<?php
// config/seguridad.php
// Seguridad completa: sesión endurecida + tokens CSRF + captcha + rate-limit.
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/rutas.php';
require_once __DIR__ . '/acceso_pagina.php';
require_once __DIR__ . '/permisos_helper.php';
require_once __DIR__ . '/horario_laboral.php';

// Toda petición que pase por aquí deja constancia en la bitácora de accesos
// (páginas concedidas e intentos denegados, incluso sin sesión o sin permisos).
accesoRegistrarShutdown();

// ---------------------------------------------------------------------------
// HTTPS obligatorio en producción (HSTS).
//
// Se activa con IAA_FORZAR_HTTPS=1 en .env. En desarrollo (http://localhost)
// se deja apagado: sin TLS no se podría ni entrar.
//
// La redirección es permanente (301) para que el navegador no la repita, y
// el HSTS lo añade enviarCabecerasSeguridad() cuando la peticion ya es HTTPS.
// ---------------------------------------------------------------------------
if (iaa_env_valor('IAA_FORZAR_HTTPS', '') === '1') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$https && !headers_sent()) {
        // El Host de la cabecera no se acepta a ciegas: se filtra para que no
        // se pueda usar como redireccion abierta.
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: https://' . $host . $uri, true, 301);
            exit;
        }
    }
}

// Política única de errores (M-03 / M-04).
// Los errores NUNCA se muestran al cliente (una traza de PHP revela rutas,
// versión y a veces valores de variables), pero se registran completos en el
// log del servidor. Antes cada controlador fijaba esto por su cuenta, con
// valores contradictorios, y controllers/actualizar_usuario.php los activaba
// con display_errors=1 para depurar un 500, filtrando trazas en la respuesta.
// Al centralizarlo aquí todo el código que carga config/seguridad.php queda
// cubierto. En CLI se respeta el php.ini: los scripts de diagnóstico sí
// quieren ver el error por consola.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

function iniciarSesionSegura()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    if (intentarAbrirSesion(5)) {
        return true;
    }
    $alternativa = sys_get_temp_dir();
    if (is_writable($alternativa) && $alternativa !== session_save_path()) {
        session_save_path(str_replace('\\', '/', $alternativa));
        if (intentarAbrirSesion(5)) {
            return true;
        }
    }
    return false;
}

function intentarAbrirSesion($intentos)
{
    for ($i = 1; $i <= $intentos; $i++) {
        if (@session_start()) {
            return true;
        }
        if ($i < $intentos) {
            usleep(100000);
        }
    }
    return false;
}

function configurarSesionSegura()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    // Solo configurar parámetros de cookie si la sesión no está activa todavía.
    // Si ya lo está (por ejemplo, después del login), evitamos cambiar los
    // parámetros de cookie para no provocar warnings de PHP ni romper la sesión.
    $recienCreada = false;
    if (session_status() === PHP_SESSION_NONE) {
        // Endurecimiento de sesión a nivel de aplicación. Se fija aquí y no solo
        // en php.ini para que la aplicación sea segura también en servidores cuyo
        // php.ini no esté endurecido (y para que el endurecimiento viaje con el
        // código). Debe ejecutarse SIEMPRE antes de iniciarSesionSegura().
        // El bloque equivalente para producción está en HARDENING_PHPINI.md.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        enviarCabecerasSeguridad();
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        iniciarSesionSegura();
        $recienCreada = (session_status() === PHP_SESSION_ACTIVE);
    }
    if ($recienCreada) {
        // Sesión acabada de abrir: PHP ya ha generado un ID aleatorio nuevo, no
        // hay todavía ninguna identidad que proteger contra fijación y ningún
        // dato que valga la pena conservar. Rotar en este punto solo emitiría un
        // segundo Set-Cookie con un ID distinto en la misma respuesta.
        return;
    }
    // Regeneramos el ID de sesión solo si aún no se enviaron cabeceras a el
    // navegador. Si ya salieron cabeceras, no intentamos regenerar para evitar
    // el warning de "Session ID cannot be regenerated after headers have already
    // been sent".
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        rotarIdSesion();
    }
}

function rotarIdSesion()
{
    if (session_status() === PHP_SESSION_NONE) {
        return;
    }
    $ultima = $_SESSION['_ultima_rotacion'] ?? 0;
    if (time() - (int)$ultima < 1800) {
        return;
    }
    session_regenerate_id(true);
    $_SESSION['_ultima_rotacion'] = time();
}

function rotarTokenSesion($conexion = null)
{
    if (session_status() === PHP_SESSION_NONE) {
        return;
    }
    $ultima = $_SESSION['_ultima_rotacion'] ?? 0;
    if (time() - (int)$ultima < 1800) {
        return;
    }
    session_regenerate_id(true);
    $_SESSION['_ultima_rotacion'] = time();
    if ($conexion && isset($_SESSION['token_sesion'])) {
        $viejo = $_SESSION['token_sesion'];
        $nuevo = bin2hex(random_bytes(16));
        $stmt = $conexion->prepare(
            "UPDATE logeo SET token_sesion = ? WHERE token_sesion = ? AND estado = 'activo'"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $nuevo, $viejo);
            $stmt->execute();
            $stmt->close();
            $_SESSION['token_sesion'] = $nuevo;
        }
    }
}

if (!defined('CSRF_HMAC_SECRET')) {
    $secret_env = iaa_env_valor('IAA_CSRF_HMAC_SECRET', '');
    if (trim((string)$secret_env) !== '') {
        define('CSRF_HMAC_SECRET', trim((string)$secret_env));
    } else {
        // Secret por archivo fuera del alcance web (no hardcodeado).
        //
        // La lectura-escritura se hace bajo bloqueo exclusivo y con escritura
        // atómica (archivo temporal + rename). Antes, dos peticiones
        // simultáneas podían generar secretos distintos: una sobreescribía el
        // archivo mientras la otra conservaba en memoria el suyo, y los tokens
        // firmados por una no valían en la otra ("Petición no válida").
        $secretFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iaa_iuty_csrf_secret';
        $stored = '';
        if (is_file($secretFile)) {
            $stored = trim((string)@file_get_contents($secretFile));
        }
        if (strlen($stored) < 32) {
            $lockFile = $secretFile . '.lock';
            $fh = @fopen($lockFile, 'c');
            if ($fh) {
                @flock($fh, LOCK_EX);
                // Reintentar la lectura dentro del lock: otra petición pudo
                // haber creado el archivo mientras esperábamos.
                if (is_file($secretFile)) {
                    $releido = trim((string)@file_get_contents($secretFile));
                    if (strlen($releido) >= 32) {
                        $stored = $releido;
                    }
                }
                if (strlen($stored) < 32) {
                    $nuevo = bin2hex(random_bytes(32));
                    $tmp = $secretFile . '.' . getmypid() . '.' . uniqid('', true) . '.tmp';
                    if (@file_put_contents($tmp, $nuevo) !== false && @rename($tmp, $secretFile)) {
                        $stored = $nuevo;
                    } else {
                        @file_put_contents($secretFile, $nuevo, LOCK_EX);
                        $stored = $nuevo;
                    }
                }
                @flock($fh, LOCK_UN);
                @fclose($fh);
                @unlink($lockFile);
            } else {
                // Sin bloqueo disponible: se usa un valor en memoria. En este
                // caso el token CSRF se valida contra la cookie firmada, por
                // lo que la sesión sigue siendo coherente.
                if (strlen($stored) < 32) {
                    $stored = bin2hex(random_bytes(32));
                }
            }
        }
        define('CSRF_HMAC_SECRET', $stored);
    }
}

function csrf_firmarToken($token)
{
    return $token . '.' . hash_hmac('sha256', $token, CSRF_HMAC_SECRET);
}

function csrf_leerCookie()
{
    $c = $_COOKIE['iaa_csrf'] ?? '';
    if (!is_string($c)) $c = '';
    if ($c === '' || strpos($c, '.') === false) {
        return null;
    }
    [$token, $firma] = explode('.', $c, 2);
    if (!hash_equals(hash_hmac('sha256', $token, CSRF_HMAC_SECRET), $firma)) {
        return null;
    }
    return $token;
}

function csrf_token()
{
    $token_cookie = csrf_leerCookie();
    if (empty($_SESSION['_csrf_token'])) {
        if ($token_cookie !== null) {
            $_SESSION['_csrf_token'] = $token_cookie;
            $_SESSION['_csrf_created'] = time();
        } else {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['_csrf_created'] = time();
        }
    }
    if (time() - intval($_SESSION['_csrf_created']) > 1800) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['_csrf_created'] = time();
    }
    if ($token_cookie !== $_SESSION['_csrf_token']) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('iaa_csrf', csrf_firmarToken($_SESSION['_csrf_token']), [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return $_SESSION['_csrf_token'];
}

function csrf_verify()
{
    $token_recibido = $_POST['csrftoken'] ?? '';
    if (!is_string($token_recibido)) $token_recibido = '';
    if (empty($token_recibido)) {
        $token_recibido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token_recibido)) $token_recibido = '';
    }
    if (empty($token_recibido)) {
        return false;
    }
    $token_sesion = $_SESSION['_csrf_token'] ?? '';
    if (!empty($token_sesion) && hash_equals($token_sesion, $token_recibido)) {
        return true;
    }
    $token_cookie = csrf_leerCookie();
    if ($token_cookie !== null) {
        return hash_equals($token_cookie, $token_recibido);
    }
    return false;
}

function exigirProteccion($roles = null)
{
    if (session_status() === PHP_SESSION_NONE) {
        configurarSesionSegura();
    }
    if (!isset($_SESSION['ci_user'])) {
        $GLOBALS['acceso_motivo_denegacion'] = 'sin_sesion';
        header("Location: " . BASE_URL . "/public/logeo/login.php");
        exit;
    }
    if ($roles !== null) {
        $rol = $_SESSION['id_rol'] ?? $_SESSION['per_id'] ?? 0;
        if (!in_array((int)$rol, (array)$roles, true)) {
            $GLOBALS['acceso_motivo_denegacion'] = 'sin_permiso';
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'No tiene permisos para realizar esta acción']);
            exit;
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
        $GLOBALS['acceso_motivo_denegacion'] = 'csrf_invalido';
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido. Recargue la página e intente de nuevo.']);
        exit;
    }
}

function requireSesion()
{
    if (session_status() === PHP_SESSION_NONE) {
        configurarSesionSegura();
    }
    if (!isset($_SESSION['ci_user'])) {
        $GLOBALS['acceso_motivo_denegacion'] = 'sin_sesion';
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }
}

function requireRol($roles = [5])
{
    requireSesion();
    $rol = $_SESSION['id_rol'] ?? $_SESSION['per_id'] ?? 0;
    if (!in_array((int)$rol, (array)$roles, true)) {
        $GLOBALS['acceso_motivo_denegacion'] = 'sin_permiso';
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para realizar esta acción']);
        exit;
    }
}

/**
 * Denegación de acceso a una vista HTML sin revelar que la página existe:
 * responde la misma página 404 que un recurso inexistente (y no una
 * redirección que delataría la ruta). Deja el motivo 'sin_permiso' para que
 * config/acceso_pagina.php lo registre en la bitácora.
 */
function denegarAccesoVista()
{
    $GLOBALS['acceso_motivo_denegacion'] = 'sin_permiso';
    http_response_code(404);
    include ROOT_PATH . '/views/404.php';
    exit;
}

// ¿Tiene el rol actual el permiso dado (por nombre 'ver_bitacora' o por id 11)?
// Fail-open cuando no hay mapa de permisos cargado o la tabla rol_permiso
// está vacía: así el control de acceso sigue siendo el histórico por rol.
function tienePermiso($permiso)
{
    if (session_status() === PHP_SESSION_NONE) {
        configurarSesionSegura();
    }

    if (function_exists('cargarPermisosSesion')) {
        // Carga (como máximo una vez por request) el mapa rol -> permisos.
        cargarPermisosSesion(function_exists('conexionPermisos') ? conexionPermisos() : null);
    }

    if (!array_key_exists('permisos', $_SESSION) || $_SESSION['permisos'] === null) {
        return true;
    }

    if (is_int($permiso) || ctype_digit((string)$permiso)) {
        return in_array((int)$permiso, $_SESSION['permisos_ids'] ?? [], true);
    }

    return in_array((string)$permiso, $_SESSION['permisos'] ?? [], true);
}

// Para endpoints/controllers: responde 403 en JSON (mismo estilo que requireRol).
function requirePermiso($permiso)
{
    requireSesion();
    if (!tienePermiso($permiso)) {
        $GLOBALS['acceso_motivo_denegacion'] = 'sin_permiso';
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para realizar esta acción']);
        exit;
    }
}

// Para vistas HTML: redirige en lugar de devolver JSON.
function exigirPermisoPagina($permiso, $destino = 'menu_principal.php')
{
    $rol = (int)($_SESSION['id_rol'] ?? $_SESSION['per_id'] ?? 0);
    if (!horarioVistaRolDisponible($rol)) {
        $GLOBALS['acceso_motivo_denegacion'] = 'fuera_horario';
        header('Location: ' . BASE_URL . '/views/menu_principal.php?error=' . urlencode(mensajeHorarioLaboral()));
        exit;
    }
    if (!tienePermiso($permiso)) {
        $GLOBALS['acceso_motivo_denegacion'] = 'sin_permiso';
        header('Location: ' . $destino);
        exit;
    }
}

function exigirHorarioVistaRol($rol = null)
{
    $rol = $rol === null
        ? (int)($_SESSION['id_rol'] ?? $_SESSION['per_id'] ?? 0)
        : (int)$rol;
    if (!horarioVistaRolDisponible($rol)) {
        $GLOBALS['acceso_motivo_denegacion'] = 'fuera_horario';
        header('Location: ' . BASE_URL . '/views/menu_principal.php?error=' . urlencode(mensajeHorarioLaboral()));
        exit;
    }
}

function rolActual()
{
    return (int)($_SESSION['id_rol'] ?? $_SESSION['per_id'] ?? 0);
}

function esGestor()
{
    return in_array(rolActual(), [3, 4, 5], true);
}

function esSuperior()
{
    return in_array(rolActual(), [4, 5], true);
}

function esc($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function verificarSesionUnica($conexion)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }
    $ci    = (string)($_SESSION['ci_user'] ?? '');
    $token = (string)($_SESSION['token_sesion'] ?? '');
    if ($ci === '' || $token === '' || !$conexion) {
        return true;
    }
    $stmt = $conexion->prepare(
        "SELECT token_sesion FROM logeo
         WHERE ci_user = ? AND estado = 'activo'
         ORDER BY f_ing DESC, id_logeo DESC LIMIT 1"
    );
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('s', $ci);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    return hash_equals((string)$row['token_sesion'], $token);
}

function cerrarSesionesPhpDeUsuario($ci)
{
    if ($ci === '') {
        return;
    }
    $dirSesiones = session_save_path();
    if (empty($dirSesiones) || !is_dir($dirSesiones)) {
        $fallback = 'C:\\xampp\\tmp';
        if (is_dir($fallback)) $dirSesiones = $fallback;
        else return;
    }
    $idActual = session_id();
    $archivos = @scandir($dirSesiones);
    if (!$archivos) {
        return;
    }
    foreach ($archivos as $f) {
        if (strpos($f, 'sess_') !== 0) continue;
        if ($idActual !== '' && $f === 'sess_' . $idActual) continue;
        $ruta = rtrim($dirSesiones, '\\/') . DIRECTORY_SEPARATOR . $f;
        $contenido = @file_get_contents($ruta);
        if ($contenido === false) continue;
        if (strpos($contenido, 'ci_user|') !== false
            && (strpos($contenido, '"' . $ci . '"') !== false || strpos($contenido, ':' . $ci . ';') !== false)) {
            @unlink($ruta);
        }
    }
}

/**
 * Cierra todas las sesiones activas del usuario excepto la actual.
 *
 * Marca inactivas las filas de `logeo` del usuario y elimina sus archivos
 * de sesión PHP (cerrarSesionesPhpDeUsuario omite la sesión en curso).
 * Se usa al cambiar la contraseña y en el botón "Cerrar otras sesiones".
 *
 * @param mixed  $conexion    Conexión mysqli (o null: solo archivos PHP).
 * @param string $ci          Cédula del usuario.
 * @param string $tokenActual token_sesion que debe conservarse ('' = cerrar todas).
 * @return int    Filas de `logeo` cerradas.
 */
function cerrarOtrasSesiones($conexion, string $ci, string $tokenActual = ''): int
{
    $ci = trim($ci);
    if ($ci === '') {
        return 0;
    }
    $cerradas = 0;
    if ($conexion instanceof IaaConnection) {
        if ($tokenActual !== '') {
            $stmt = $conexion->prepare(
                "UPDATE logeo
                    SET estado = 'inactivo', f_sal = NOW()
                  WHERE ci_user = ? AND estado = 'activo' AND token_sesion <> ?"
            );
            if ($stmt) {
                $stmt->bind_param('ss', $ci, $tokenActual);
                $stmt->execute();
                $cerradas = (int)$stmt->affected_rows;
                $stmt->close();
            }
        } else {
            $stmt = $conexion->prepare(
                "UPDATE logeo
                    SET estado = 'inactivo', f_sal = NOW()
                  WHERE ci_user = ? AND estado = 'activo'"
            );
            if ($stmt) {
                $stmt->bind_param('s', $ci);
                $stmt->execute();
                $cerradas = (int)$stmt->affected_rows;
                $stmt->close();
            }
        }
    }
    cerrarSesionesPhpDeUsuario($ci);
    return max(0, $cerradas);
}

/**
 * Elimina el archivo de sesión PHP asociado a un token_sesion concreto.
 *
 * Se usa al revocar una sesión concreta desde el centro de seguridad: la fila
 * de `logeo` se marca inactiva y, además, se destruye el archivo de sesión
 * para que el navegador de ese dispositivo pierda el acceso de inmediato.
 *
 * @param string $token token_sesion a invalidar.
 * @return bool  true si se eliminó al menos un archivo.
 */
function cerrarSesionPhpPorToken(string $token): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }
    $dirSesiones = session_save_path();
    if (empty($dirSesiones) || !is_dir($dirSesiones)) {
        $fallback = 'C:\\xampp\\tmp';
        if (is_dir($fallback)) {
            $dirSesiones = $fallback;
        } else {
            return false;
        }
    }
    $idActual = session_id();
    $archivos = @scandir($dirSesiones);
    if (!$archivos) {
        return false;
    }
    $eliminado = false;
    foreach ($archivos as $f) {
        if (strpos($f, 'sess_') !== 0) continue;
        if ($idActual !== '' && $f === 'sess_' . $idActual) continue;
        $ruta = rtrim($dirSesiones, '\\/') . DIRECTORY_SEPARATOR . $f;
        $contenido = @file_get_contents($ruta);
        if ($contenido === false) continue;
        if (strpos($contenido, 'token_sesion|') !== false && strpos($contenido, $token) !== false) {
            @unlink($ruta);
            $eliminado = true;
        }
    }
    return $eliminado;
}

function captcha_img_url(): string
{
    if (empty($GLOBALS['_CAPTCHA_TOKEN'])) {
        $GLOBALS['_CAPTCHA_TOKEN'] = bin2hex(random_bytes(8));
    }
    return BASE_URL . '/public/logeo/captcha.php?c=' . $GLOBALS['_CAPTCHA_TOKEN'];
}

function verificarCaptcha(?string $input): bool
{
    $hash = $_SESSION['_captcha_hash'] ?? null;
    unset($_SESSION['_captcha_hash']);
    if ($hash === null || $input === null) {
        return false;
    }
    $input = trim($input);
    if ($input === '' || strlen($input) > 32) {
        return false;
    }
    return hash_equals($hash, hash('sha256', strtoupper($input)));
}

function validarComplejidadPassword(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'La contraseña debe tener al menos 8 caracteres.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'La contraseña debe incluir al menos una letra mayúscula.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'La contraseña debe incluir al menos una letra minúscula.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'La contraseña debe incluir al menos un número.';
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        return 'La contraseña debe incluir al menos un carácter especial (ej. @, #, $, !, %).';
    }
    return null;
}

function csp_nonce(): string
{
    if (empty($GLOBALS['_CSP_NONCE'])) {
        $GLOBALS['_CSP_NONCE'] = base64_encode(random_bytes(18));
    }
    return $GLOBALS['_CSP_NONCE'];
}

function enviarCabecerasSeguridad()
{
    if (headers_sent()) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    $nonce = csp_nonce();
    $csp = "default-src 'self'; " .
           "script-src-elem 'self' 'nonce-$nonce'; " .
           "script-src-attr 'unsafe-inline'; " .
           "style-src-elem 'self' 'nonce-$nonce'; " .
           "style-src-attr 'unsafe-inline'; " .
           "img-src 'self' data: blob:; " .
           "font-src 'self' data:; " .
           "connect-src 'self' blob:; " .
           "worker-src 'self' blob:; " .
           "child-src 'self' blob:; " .
           "frame-src 'self' https://www.google.com; " .
           "frame-ancestors 'none'; " .
           "form-action 'self'; " .
           "base-uri 'self'; " .
           "object-src 'none';";
    header("Content-Security-Policy: $csp");
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

/**
 * Rate limit por IP y, opcionalmente, por recurso (p. ej. la cédula).
 *
 * - Sin $recurso: límite clásico por IP.
 * - Con $recurso: el límite aplica por IP + recurso, de modo que una misma
 *   cuenta no puede atacarse desde ninguna IP sin tope (fuerza bruta
 *   distribuida) y una IP compartida no bloquea a todo un departamento.
 *
 * @param string $accion          Identificador de la operación ('perfil_actualizar').
 * @param int    $maxIntentos     Peticiones permitidas dentro de la ventana.
 * @param int    $segundosVentana Tamaño de la ventana en segundos.
 * @param string $recurso         Clave adicional (ci, id_usuario…). '' = solo IP.
 */
function exigirRateLimitPorIp(string $accion, int $maxIntentos = 10, int $segundosVentana = 60, string $recurso = ''): void
{
    global $conexion;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $recurso = trim($recurso);
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iaa_iuty_rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    // Formato anterior: md5(accion_ip). Con recurso: md5(accion_recurso_ip).
    $clave = $accion . '|' . ($recurso !== '' ? $recurso . '|' : '') . $ip;
    $archivo = $dir . DIRECTORY_SEPARATOR . md5($clave) . '.json';
    $ahora = time();
    $intentos = [];
    if (is_file($archivo)) {
        $contenido = @file_get_contents($archivo);
        $datos = $contenido ? json_decode($contenido, true) : [];
        if (is_array($datos)) {
            $intentos = array_filter($datos, function($t) use ($ahora, $segundosVentana) {
                return ($ahora - $t) < $segundosVentana;
            });
        }
    }
    if (count($intentos) >= $maxIntentos) {
        // Queda constancia del bloqueo: es la senal mas clara de automatizacion.
        // Solo se registra la primera peticion bloqueada de la ventana para que un
        // flood no llene la tabla bitacora.
        if (count($intentos) === $maxIntentos && isset($conexion) && $conexion instanceof IaaConnection) {
            require_once __DIR__ . '/bitacora_helper.php';
            $contextoBloqueo = [
                'ip' => $ip,
                'accion' => $accion,
                'max_intentos' => $maxIntentos,
                'ventana_seg' => $segundosVentana,
            ];
            if ($recurso !== '') {
                $contextoBloqueo['recurso'] = $recurso;
            }
            bitacoraIntentoFallido($conexion, 'rate_limit_' . $accion, 'ip_bloqueada_por_volumen', $contextoBloqueo);
        }
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Demasiadas solicitudes desde su IP. Intente de nuevo en unos minutos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $intentos[] = $ahora;
    @file_put_contents($archivo, json_encode(array_values($intentos)));
}

function verificarClaveAdmin($conexion, $clave)
{
    $ci = $_SESSION['ci_user'] ?? '';
    $id = (int)($_SESSION['id_user'] ?? 0);
    if ($clave === '' || ($ci === '' && $id <= 0)) {
        return false;
    }
    $bloqueoHasta = $_SESSION['_clave_admin_bloqueo'] ?? 0;
    if ($bloqueoHasta > time()) {
        return false;
    }
    $fallos = $_SESSION['_clave_admin_fallos'] ?? 0;
    if ($ci !== '') {
        $stmt = $conexion->prepare("SELECT contrasena FROM usuario WHERE ci_usuario = ? LIMIT 1");
        $stmt->bind_param('s', $ci);
    } else {
        $stmt = $conexion->prepare("SELECT contrasena FROM usuario WHERE id_usuario = ? LIMIT 1");
        $stmt->bind_param('i', $id);
    }
    if (!$stmt) {
        return false;
    }
    $stmt->execute();
    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$usuario) {
        $_SESSION['_clave_admin_fallos'] = $fallos + 1;
        if ($fallos + 1 >= 5) {
            $_SESSION['_clave_admin_bloqueo'] = time() + 60;
        }
        return false;
    }
    $valido = password_verify($clave, $usuario['contrasena']);
    if (!$valido) {
        $_SESSION['_clave_admin_fallos'] = $fallos + 1;
        if ($fallos + 1 >= 5) {
            $_SESSION['_clave_admin_bloqueo'] = time() + 60;
            unset($_SESSION['_clave_admin_fallos']);
        }
    } else {
        $_SESSION['_clave_admin_fallos'] = 0;
        unset($_SESSION['_clave_admin_bloqueo']);
    }
    return $valido;
}

if (!defined('EXTENSIONES_SEGURAS')) {
    define('EXTENSIONES_SEGURAS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv']);
}

function validarArchivo(array $archivo, array $extensionesPermitidas = EXTENSIONES_SEGURAS, int $maxBytes = 5 * 1024 * 1024): array
{
    $resultado = ['ok' => false, 'error' => '', 'extension' => '', 'mime' => ''];
    if (!isset($archivo['tmp_name'], $archivo['name'])) {
        $resultado['error'] = 'Archivo no recibido.';
        return $resultado;
    }
    if ((int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $resultado['error'] = 'Error al subir el archivo (código ' . ($archivo['error'] ?? '') . ').';
        return $resultado;
    }
    if ((int)($archivo['size'] ?? 0) > $maxBytes) {
        $resultado['error'] = 'El archivo supera el tamaño máximo permitido.';
        return $resultado;
    }
    $nombreOriginal = basename($archivo['name']);
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $extensionesPermitidas, true)) {
        $resultado['error'] = 'Formato no permitido. Solo: ' . implode(', ', $extensionesPermitidas) . '.';
        return $resultado;
    }
    if (preg_match('/\.(?:php|phtml|php\d*|pht|phar|pl|py|cgi|asp|aspx|jsp|sh|exe|bat|cmd|com|htaccess|ini|txt)$/i', $nombreOriginal)) {
        $resultado['error'] = 'Tipo de archivo bloqueado.';
        return $resultado;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);
    $mimePermitidos = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv',
    ];
    if (!in_array($mime, $mimePermitidos, true)) {
        $resultado['error'] = 'El contenido del archivo no corresponde a un formato válido.';
        return $resultado;
    }
    $resultado['ok'] = true;
    $resultado['extension'] = $ext;
    $resultado['mime'] = $mime;
    return $resultado;
}
?>
