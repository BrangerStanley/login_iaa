<?php
// config/acceso_pagina.php
// Registro automático en `bitacora` de cada página que un usuario:
//   • entra normalmente       → categoria 'acceso_pagina'
//   • intenta entrar          → categoria 'acceso_denegado'
//     (sin sesión, sin permisos, 401/403 de endpoints, etc.)
// Se dispara al finalizar la petición (register_shutdown_function) para cubrir
// también las páginas que redirigen antes de renderizarse y los endpoints JSON
// rechazados. No altera el esquema: usa las columnas existentes de `bitacora`.

require_once __DIR__ . '/bitacora_helper.php';
require_once __DIR__ . '/Database.php';

/** Archivos que no representan una visita del usuario (sub-recursos o flujos de auth). */
const ACCESO_ARCHIVOS_OMITIDOS = [
    'captcha.php',                    // imagen del captcha
    'keep_alive.php',                 // sondeo AJAX de sesión
    'logoutController.php',           // cierre de sesión (no es un acceso)
    'funcionarioLoginController.php', // intentos de login → tabla intentos_login
];

/** Motivo de denegación fijado por los guards de config/seguridad.php. */
function accesoMotivoGuard(): string
{
    return trim((string)($GLOBALS['acceso_motivo_denegacion'] ?? ''));
}

/**
 * Conexión a BD para registrar accesos.
 * Reutiliza $GLOBALS['conexion'] si ya existe; si no, conecta por su cuenta sin
 * interrumpir la página (a diferencia de Conexion.php, que muere con JSON 500).
 */
function accesoConexion()
{
    if (isset($GLOBALS['conexion']) && $GLOBALS['conexion'] instanceof IaaConnection) {
        return $GLOBALS['conexion'];
    }
    static $propia = null;
    static $intentada = false;
    if ($intentada) {
        return $propia;
    }
    $intentada = true;

    // Misma configuración que config/Conexion.php, pero sin abortar la página
    // si falla: el registro de accesos es auxiliar y no debe tumbar la respuesta.
    require_once __DIR__ . '/env.php';

    $dbHost = iaa_env_valor('IAA_DB_HOST', '127.0.0.1');
    $dbPort = (int)iaa_env_valor('IAA_DB_PORT', '5432');
    $dbUser = iaa_env_valor('IAA_DB_USER', 'iaa_app_user');
    $dbPass = iaa_env_valor('IAA_DB_PASS', '');
    $dbName = iaa_env_valor('IAA_DB_NAME', 'fpaiaa');
    if ($dbPort <= 0) {
        $dbPort = 5432;
    }

    try {
        $propia = new IaaConnection([
            'host'    => $dbHost,
            'port'    => $dbPort,
            'user'    => $dbUser,
            'pass'    => $dbPass,
            'db'      => $dbName,
            'sslmode' => (PHP_OS_FAMILY === 'Windows') ? 'prefer' : 'verify-full',
        ]);
    } catch (Throwable $e) {
        error_log('acceso_pagina.php: no se pudo conectar para registrar el acceso: ' . $e->getMessage());
        $propia = null;
    }
    return $propia;
}

/**
 * Inserta un acceso (concedido o denegado) en la bitácora.
 *
 * @param string $ruta   Ruta relativa de la página (ej. views/dashboard.php)
 * @param string $estado 'accedido' | 'denegado'
 * @param string $motivo 'sin_sesion', 'sin_permiso', 'csrf_invalido', 'redireccion'…
 * @param string $metodo Método HTTP (GET/POST)
 */
function registrarAccesoPagina(string $ruta, string $estado, string $motivo = '', string $metodo = '')
{
    $estado = ($estado === 'denegado') ? 'denegado' : 'accedido';
    $categoria = ($estado === 'denegado') ? 'acceso_denegado' : 'acceso_pagina';
    $accion = ($estado === 'denegado') ? 'intento_acceso' : 'acceso_pagina';

    $metodo = strtoupper(trim($metodo !== '' ? $metodo : (string)($_SERVER['REQUEST_METHOD'] ?? '')));
    $motivo = trim($motivo);
    $detalle = ($ruta !== '' ? $ruta : '-')
        . ' · ' . ($metodo !== '' ? $metodo : '-')
        . ' · ' . ($estado === 'denegado'
            ? 'denegado: ' . ($motivo !== '' ? $motivo : 'sin_permiso')
            : 'concedido' . ($motivo !== '' ? ' (' . $motivo . ')' : ''));
    if (mb_strlen($detalle) > 1000) {
        $detalle = mb_substr($detalle, 0, 1000);
    }

    $conexion = accesoConexion();
    if (!$conexion instanceof IaaConnection) {
        return false;
    }

    $id_usuario = 0;
    $id_logeo = 0;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $id_usuario = (int)($_SESSION['id_user'] ?? $_SESSION['id_usuario'] ?? 0);
        if ($id_usuario <= 0) {
            $ci = (string)($_SESSION['ci_user'] ?? '');
            if ($ci !== '') {
                try {
                    $stmtCi = $conexion->prepare("SELECT id_usuario FROM usuario WHERE ci_usuario = ? LIMIT 1");
                    if ($stmtCi) {
                        $stmtCi->bind_param('s', $ci);
                        $stmtCi->execute();
                        $rowCi = $stmtCi->get_result()->fetch_assoc();
                        $stmtCi->close();
                        $id_usuario = (int)($rowCi['id_usuario'] ?? 0);
                    }
                } catch (Throwable $e) {
                    $id_usuario = 0;
                }
            }
        }
        $id_logeo = bitacoraIdSesionActual($conexion);
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (mb_strlen($agent) > 300) {
        $agent = mb_substr($agent, 0, 300);
    }

    // id_usuario / id_logeo van en NULL cuando no existen (FK de `usuario`).
    if ($id_usuario > 0 && $id_logeo > 0) {
        $sql = "INSERT INTO bitacora (id_usuario, id_logeo, accion, categoria, detalle, fecha, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)";
        $tipos = 'iisssss';
    } elseif ($id_usuario > 0) {
        $sql = "INSERT INTO bitacora (id_usuario, id_logeo, accion, categoria, detalle, fecha, ip, user_agent)
                VALUES (?, NULL, ?, ?, ?, NOW(), ?, ?)";
        $tipos = 'isssss';
    } elseif ($id_logeo > 0) {
        $sql = "INSERT INTO bitacora (id_usuario, id_logeo, accion, categoria, detalle, fecha, ip, user_agent)
                VALUES (NULL, ?, ?, ?, ?, NOW(), ?, ?)";
        $tipos = 'isssss';
    } else {
        $sql = "INSERT INTO bitacora (id_usuario, id_logeo, accion, categoria, detalle, fecha, ip, user_agent)
                VALUES (NULL, NULL, ?, ?, ?, NOW(), ?, ?)";
        $tipos = 'sssss';
    }

    try {
        $stmt = $conexion->prepare($sql);
        if (!$stmt) {
            return false;
        }
        if ($id_usuario > 0 && $id_logeo > 0) {
            $stmt->bind_param($tipos, $id_usuario, $id_logeo, $accion, $categoria, $detalle, $ip, $agent);
        } elseif ($id_usuario > 0) {
            $stmt->bind_param($tipos, $id_usuario, $accion, $categoria, $detalle, $ip, $agent);
        } elseif ($id_logeo > 0) {
            $stmt->bind_param($tipos, $id_logeo, $accion, $categoria, $detalle, $ip, $agent);
        } else {
            $stmt->bind_param($tipos, $accion, $categoria, $detalle, $ip, $agent);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        // Nunca debe tumbar (ni saturar de salida) la petición principal.
        error_log('registrarAccesoPagina: ' . $e->getMessage());
        return false;
    }
}

/** Cabecera Location enviada en la petición actual (null si no hay). */
function accesoLocationHeader(): ?string
{
    if (!function_exists('headers_list')) {
        return null;
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') === 0) {
            return trim(substr($h, strlen('Location:')));
        }
    }
    return null;
}

/**
 * Ruta que el navegador solicitó realmente cuando la respuesta viene del
 * documento de error (views/404.php vía ErrorDocument 404/403).
 * Devuelve '' si no hay URL solicitada (acceso directo al documento de error).
 */
function accesoRutaSolicitada(): string
{
    $url = (string)($_SERVER['REDIRECT_URL'] ?? '');
    if ($url === '') {
        return '';
    }
    $partes = explode('?', $url, 2);
    $url = $partes[0];
    $base = rtrim((string)BASE_URL, '/');
    if ($base !== '' && strpos($url, $base . '/') === 0) {
        $url = substr($url, strlen($base));
    }
    $url = ltrim(str_replace('\\', '/', $url), '/');
    if (function_exists('mb_strlen') && mb_strlen($url, 'UTF-8') > 500) {
        $url = mb_substr($url, 0, 500, 'UTF-8');
    }
    return ($url !== '') ? $url : '/';
}

/** Ruta del script relativa a la raíz del proyecto (views/dashboard.php). */
function accesoRutaRelativa(string $script): string
{
    $raiz = str_replace('\\', '/', dirname(__DIR__));
    $script = str_replace('\\', '/', $script);
    if (strpos($script, $raiz . '/') === 0) {
        return substr($script, strlen($raiz) + 1);
    }
    return basename($script);
}

/**
 * Clasifica la petición finalizada y registra el acceso en la bitácora.
 *  - Página (views/ o public/) con 200            → accedido
 *  - Página redirigida a login / menu_principal   → denegado (sin sesión / sin permiso)
 *  - Endpoint con 401 / 403 / Location vía guard  → denegado
 *  - Endpoint exitoso (AJAX)                      → no se registra (evita ruido)
 */
function registrarAccesoPaginaAutomatico(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script === '') {
        return;
    }
    if (in_array(basename($script), ACCESO_ARCHIVOS_OMITIDOS, true)) {
        return;
    }
    $scriptNorm = str_replace('\\', '/', $script);
    if (strpos($scriptNorm, '/views/partials/') !== false) {
        return; // los parciales no son páginas visitables
    }
    $esPagina = (bool)preg_match('#/(views|public)/#', $scriptNorm);
    $esEndpoint = (bool)strpos($scriptNorm, '/controllers/');
    if (!$esPagina && !$esEndpoint) {
        return;
    }

    $codigo = http_response_code();
    $codigo = (is_int($codigo) && $codigo > 0) ? $codigo : 200;
    $location = accesoLocationHeader();
    $motivoGuard = accesoMotivoGuard();

    // Documento de error servido vía ErrorDocument: la ruta real es la que
    // pidió el navegador (REDIRECT_URL) y el código original el de Apache
    // (404 inexistente, 403 denegado → responde 404 pero se audita como 403).
    $rutaSolicitada = '';
    if (preg_match('#/views/404\.php$#', $scriptNorm)) {
        $rutaSolicitada = accesoRutaSolicitada();
        $codigoOriginal = (int)($_SERVER['REDIRECT_STATUS'] ?? 0);
        if ($codigoOriginal >= 400) {
            $codigo = $codigoOriginal;
        }
    }

    if ($rutaSolicitada !== '') {
        $estado = 'denegado';
        $motivo = 'http_' . $codigo;
    } elseif ($location !== null && $location !== '') {
        if ($esEndpoint) {
            // Solo los guards de seguridad.php justifican un endpoint redirigido.
            if ($motivoGuard === '') {
                return;
            }
            $estado = 'denegado';
            $motivo = $motivoGuard;
        } elseif (stripos($location, 'login.php') !== false) {
            $estado = 'denegado';
            $motivo = $motivoGuard !== '' ? $motivoGuard : 'sin_sesion';
        } elseif (stripos($location, 'menu_principal.php') !== false) {
            $estado = 'denegado';
            $motivo = $motivoGuard !== '' ? $motivoGuard : 'sin_permiso';
        } else {
            // Redirección interna tras pasar los guards (stubs / compatibilidad).
            $estado = 'accedido';
            $motivo = 'redireccion';
        }
    } elseif ($codigo === 401) {
        $estado = 'denegado';
        $motivo = $motivoGuard !== '' ? $motivoGuard : 'sin_sesion';
    } elseif ($codigo === 403 || $codigo === 419) {
        $estado = 'denegado';
        $motivo = $motivoGuard !== '' ? $motivoGuard : 'sin_permiso';
    } elseif ($codigo >= 400) {
        if (!$esPagina) {
            return; // error de un endpoint ≠ intento de acceso a una página
        }
        $estado = 'denegado';
        // 'sin_permiso' fijado por denegarAccesoVista() manda sobre http_404:
        // así la bitácora distingue un rol insuficiente de una ruta inexistente.
        $motivo = ($motivoGuard !== '') ? $motivoGuard : 'http_' . $codigo;
    } elseif ($esPagina) {
        $estado = 'accedido';
        $motivo = '';
    } else {
        return; // endpoints exitosos (AJAX): no se registran
    }

    registrarAccesoPagina(
        ($rutaSolicitada !== '') ? $rutaSolicitada : accesoRutaRelativa($script),
        $estado,
        $motivo,
        (string)($_SERVER['REQUEST_METHOD'] ?? '')
    );
}

/** Registra (una sola vez) el hook de fin de petición. */
function accesoRegistrarShutdown(): void
{
    static $registrado = false;
    if ($registrado) {
        return;
    }
    $registrado = true;
    // La suite de pruebas no debe escribir en la base de datos: las pruebas
    // tienen que ser idempotentes y no dejar rastro.
    if (defined('IAA_SIN_REGISTRO_ACCESO')) {
        return;
    }
    register_shutdown_function('registrarAccesoPaginaAutomatico');
}
