<?php
// controllers/funcionarioLoginController.php
require_once __DIR__ . '/../config/Conexion.php';
require_once __DIR__ . '/../config/seguridad.php';
require_once __DIR__ . '/../config/cedula_helper.php';
require_once __DIR__ . '/../models/LoginModel.php';

if ($conexion instanceof IaaConnection) {
    $conexion->set_charset('utf8mb4');
}

configurarSesionSegura();

$esAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

function redirigir($mensaje, $esAjax) {
    if ($esAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $mensaje]);
        exit();
    }
    header("Location: " . BASE_URL . "/public/logeo/login.php?error=" . urlencode($mensaje));
    exit();
}

function loginExitoso($redirect, $esAjax) {
    if ($esAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'redirect' => $redirect]);
        exit();
    }
    header("Location: " . $redirect);
    exit();
}

function registrarRazonIntento($conexion, $ci, $razon, $exitoso = false) {
    $exitosoBool = $exitoso ? 'true' : 'false';
    $stmt = $conexion->prepare(
        "UPDATE intentos_login SET razon = ?, exitoso = ?, ultimo_intento = NOW() WHERE ci_user = ?"
    );
    if ($stmt) {
        $stmt->bind_param('sss', $razon, $exitosoBool, $ci);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Efectos de una autenticación completa: cierra sesiones
 * previas, registra el nuevo `logeo`, inicializa la sesión PHP, resuelve el
 * rol y redirige. También envía la alerta de inicio de sesión si el equipo es
 * distinto al anterior.
 */
function completarLogin($conexion, $loginModel, array $datos_usuario, $esAjax) {
    $usuario_id   = (int)$datos_usuario['id_usuario'];
    $cedula_logeo = (string)$datos_usuario['ci_usuario'];
    $nombre_logeo = $datos_usuario['nombre_completo'] ?? $cedula_logeo;

    // Reinicia el contador de intentos y el bloqueo progresivo.
    $stmtLim = $conexion->prepare("UPDATE intentos_login SET intentos = 0, bloqueo_hasta = NULL, bloqueos_consecutivos = 0 WHERE ci_user = ?");
    if ($stmtLim) {
        $stmtLim->bind_param("s", $cedula_logeo);
        $stmtLim->execute();
        $stmtLim->close();
    }
    registrarRazonIntento($conexion, $cedula_logeo, 'Inicio de sesión exitoso', 1);

    // Datos del acceso anterior (antes de cerrarlo) para decidir la alerta.
    $dir_ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $agent_u = $_SERVER['HTTP_USER_AGENT'] ?? '-';
    $accesoAnterior = null;
    $stmtPrev = $conexion->prepare(
        "SELECT dir_ip, agent_u FROM logeo WHERE ci_user = ? ORDER BY id_logeo DESC LIMIT 1"
    );
    if ($stmtPrev) {
        $stmtPrev->bind_param('s', $cedula_logeo);
        $stmtPrev->execute();
        $accesoAnterior = $stmtPrev->get_result()->fetch_assoc();
        $stmtPrev->close();
    }

    session_regenerate_id(true);

    // El ID de sesión ya se acaba de regenerar. Se marca la rotación para que
    // la primera petición posterior (latido, navegación…) no vuelva a rotarlo:
    // rotar dos veces seguidas invalida la cookie que el navegador aún está
    // estrenando y provoca un cierre de sesión inmediato.
    $_SESSION['_ultima_rotacion'] = time();
    $_SESSION['LAST_ACTIVITY'] = time();

    // Cierra cualquier sesión previa (una sola sesión activa por usuario).
    $stmtCierre = $conexion->prepare("UPDATE logeo SET estado = 'inactivo', f_sal = NOW() WHERE ci_user = ? AND estado = 'activo'");
    if ($stmtCierre) {
        $stmtCierre->bind_param('s', $cedula_logeo);
        $stmtCierre->execute();
        $stmtCierre->close();
    }
    cerrarSesionesPhpDeUsuario($cedula_logeo);

    $token_sesion = bin2hex(random_bytes(16));
    $id_logeo_nuevo = $loginModel->registrarLogeo(
        $usuario_id, $cedula_logeo, $nombre_logeo,
        $dir_ip, $agent_u, $token_sesion, 'activo'
    );

    $_SESSION['id_user']      = $usuario_id;
    $_SESSION['ci_user']      = $cedula_logeo;
    $_SESSION['nombre_user']  = $nombre_logeo;
    $_SESSION['token_sesion'] = $token_sesion;
    // Hora de entrada de ESTA sesión (la usa views/menu_principal.php).
    $_SESSION['_inicio_sesion'] = time();
    if ($id_logeo_nuevo > 0) {
        $_SESSION['id_logeo'] = $id_logeo_nuevo;
    }
    unset($_SESSION['vista_activa']);

    // --- NUEVO: Dejar el correo del usuario disponible en la sesión ---
    // LoginModel ya lo trae de persona.correo en $datos_usuario: se guarda en
    // la sesión para que la descarga de documentación lo use sin pedirlo.
    if (!empty($datos_usuario['correo'])) {
        $_SESSION['correo'] = $datos_usuario['correo'];
    }
    // -----------------------------------------------------------

    require_once __DIR__ . '/../models/PermisoModel.php';
    $permisoModel = new PermisoModel();
    $rol_num = $permisoModel->rolIdPorNombre($datos_usuario['rol'] ?? '');
    if ($rol_num <= 0) {
        $rol_num = 1;
    }
    $_SESSION['per_id'] = $rol_num;
    $_SESSION['id_rol'] = $rol_num;

    // --- Roles múltiples de la cuenta ----------------------------------
    // Una cuenta puede tener más de un rol (p. ej. MEDICO + PARTICULAR:
    // el doctor atiende su agenda profesional y además agenda sus propias
    // citas como paciente). Se guardan en sesión y, si hay más de uno,
    // el sistema lleva al selector de rol: el usuario elige con qué
    // "sombrero" entra (rol activo). El rol principal ya quedó arriba.
    require_once __DIR__ . '/../models/UsuarioRolModel.php';
    $usuarioRolModel = new UsuarioRolModel($conexion);
    $usuarioRolModel->sincronizarPrincipal($usuario_id);
    $rolesCuenta = $usuarioRolModel->rolesDe($usuario_id);
    $_SESSION['roles_cuenta'] = $rolesCuenta;

    // ROL ACTIVO POR DEFECTO.
    // Con una cuenta que tiene varios roles (p. ej. MÉDICO + PARTICULAR) el
    // usuario entra por defecto como USUARIO (particular/funcionario): ve
    // menu_principal con la barra lateral normal y desde el modal puede
    // cambiarse a Médico para su agenda profesional. Solo si no tiene ninguno
    // de esos roles se queda con el rol principal de la cuenta.
    $esRolUsuario = in_array($rol_num, [1, 7], true);
    if (!$esRolUsuario) {
        $idsCuenta = array_map(fn($r) => (int)$r['id'], $rolesCuenta);
        if (in_array(7, $idsCuenta, true)) {
            $rol_num = 7;      // particular
        } elseif (in_array(1, $idsCuenta, true)) {
            $rol_num = 1;      // funcionario
        }
        $_SESSION['per_id'] = $rol_num;
        $_SESSION['id_rol'] = $rol_num;
    }

    cargarPermisosSesion($conexion);

    // Alerta por correo si el acceso viene de un equipo/red distinto al anterior.
    $esEquipoNuevo = true;
    if ($accesoAnterior) {
        $esEquipoNuevo = ((string)($accesoAnterior['dir_ip'] ?? '') !== $dir_ip)
            || ((string)($accesoAnterior['agent_u'] ?? '') !== $agent_u);
    }
    if ($esEquipoNuevo) {
        require_once __DIR__ . '/../config/notificar_helper.php';
        notificar(
            $conexion,
            $usuario_id,
            'Nuevo inicio de sesión',
            "Se inició sesión en tu cuenta desde un equipo o red nuevos.\n"
            . "IP: {$dir_ip}\n"
            . "Fecha: " . date('d/m/Y H:i') . "\n\n"
            . "Si no reconoces este acceso, cambia tu contraseña de inmediato.",
            'seguridad'
        );
    }

    // El destino es siempre menu_principal.php: si la cuenta tiene varios
    // roles, es allí donde se muestra el modal de selección ("¿con qué rol
    // entrar hoy?"). Antes el médico iba directo a servicios.php y no veía
    // nunca la opción de cambiarse.
    // Bitácora: inicio de sesión exitoso vinculado a la sesión nueva.
    require_once __DIR__ . '/../config/bitacora_helper.php';
    registrarBitacora($conexion, 'iniciar_sesion', 'Inicio de sesión: ' . $nombre_logeo . ' (IP: ' . $dir_ip . ')', 'seguridad');

    $destino = BASE_URL . "/views/menu_principal.php";

    loginExitoso($destino, $esAjax);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    redirigir("Petición no válida, intente de nuevo.", $esAjax);
}

/* =========================================================================
 * ETAPA 1: captcha + credenciales.
 * ========================================================================= */

// Limite por IP del endpoint de login (M-02).
// El bloqueo progresivo de la tabla intentos_login protege a una cuenta
// concreta, pero no limitaba el volumen de intentos que una misma IP puede
// generar contra muchas cuentas distintas (fuerza bruta distribuida).
// Umbral holgado: una persona normal no supera 20 intentos por minuto, y el
// trafico legitimo es mucho menor porque ademas exige captcha y CSRF.
exigirRateLimitPorIp('login', 20, 60);

if (!verificarCaptcha(is_string($_POST['captcha'] ?? null) ? $_POST['captcha'] : null)) {
    redirigir("Código de seguridad incorrecto. Intente de nuevo.", $esAjax);
}

if (isset($_POST['cedula']) && isset($_POST['password'])) {
    // Solo formato: la validación real es contra la BD. Los dígitos normalizan
    // la búsqueda ("V-12345678" = "12345678").
    $cedula   = cedula_digitos(trim($_POST['cedula']));
    $password = $_POST['password'];

    if (!cedula_formato_valido($cedula)) {
        redirigir("El usuario o la clave son incorrectas", $esAjax);
    }

    // Limite por IP + cedula: complementa el tope global de arriba. Usa la
    // cedula ya normalizada para que "V-12345678" y "12345678" cuenten juntas,
    // y como es una clave por cuenta una IP compartida (todo un departamento
    // detras del mismo NAT) no bloquea a los demas usuarios.
    exigirRateLimitPorIp('login_cuenta', 30, 300, $cedula);

    $loginModel = new LoginModel($conexion);

    define('MAX_INTENTOS_LOGIN', 5);
    define('MINUTOS_BLOQUEO', 10);

    // La tabla intentos_login (incluida la columna bloqueos_consecutivos) forma
    // parte del esquema gestionado por database/iaa_schema_pg.sql. El usuario de
    // la aplicación no tiene privilegios DDL, así que no se crea ni se altera en
    // tiempo de ejecución: hacerlo aquí fallaría y bloquearía el login.

    $stmtInt = $conexion->prepare("SELECT intentos, bloqueo_hasta, bloqueos_consecutivos FROM intentos_login WHERE ci_user = ? LIMIT 1");
    if ($stmtInt) {
        $stmtInt->bind_param("s", $cedula);
        $stmtInt->execute();
        $intento = $stmtInt->get_result()->fetch_assoc();
        $stmtInt->close();
    } else {
        $intento = null;
    }

    // Verificar si la cuenta está bloqueada.
    if ($intento && !empty($intento['bloqueo_hasta'])) {
        $finBloqueo = strtotime($intento['bloqueo_hasta']);
        if ($finBloqueo > time()) {
            $restante = max(1, ceil(($finBloqueo - time()) / 60));
            registrarRazonIntento($conexion, $cedula, 'Cuenta temporalmente bloqueada por intentos fallidos. Restan ' . $restante . ' min.');
            redirigir("Cuenta temporalmente bloqueada por intentos fallidos. Intente de nuevo en " . $restante . " minuto(s).", $esAjax);
        }
    }

    $datos_usuario = $loginModel->verificarCredenciales($cedula);

    $dummyHash = '$2y$10$ay4Hab0EmrsKd6NnY5EiqOp7UEeG0IYfAScT22.mtUiAblofH537q';
    $passValida = password_verify($password, $datos_usuario['contrasena'] ?? $dummyHash);

    if ($datos_usuario && $passValida) {
        $estado_cuenta = $datos_usuario['estado'] ?? 'activo';
        if ($estado_cuenta === 'pendiente') {
            registrarRazonIntento($conexion, $cedula, 'Cuenta pendiente de aprobación por el administrador.');
            redirigir("Su cuenta está pendiente de aprobación. Aguarde a que el administrador la revise.", $esAjax);
        }
        if ($estado_cuenta === 'rechazado') {
            registrarRazonIntento($conexion, $cedula, 'Cuenta rechazada por el administrador.');
            redirigir("Su cuenta fue rechazada. Contacte con soporte del sistema.", $esAjax);
        }
        if ($estado_cuenta !== 'activo') {
            registrarRazonIntento($conexion, $cedula, 'Cuenta no activa (estado: ' . $estado_cuenta . ').');
            redirigir("Su cuenta no está activa. Contacte con soporte del sistema.", $esAjax);
        }
    }

    if ($datos_usuario && $passValida) {
        completarLogin($conexion, $loginModel, $datos_usuario, $esAjax);
    } else {
        $razon = $datos_usuario ? 'Contraseña incorrecta' : 'Cédula no registrada en el sistema';
        $nuevos = $intento ? ((int)$intento['intentos'] + 1) : 1;
        $bloqueo = null;
        $bloqueos = $intento ? (int)($intento['bloqueos_consecutivos'] ?? 0) : 0;
        $bloqueadoAhora = false;

        if ($nuevos >= MAX_INTENTOS_LOGIN) {
            // Bloqueo progresivo: 10 min, luego 30, luego 120.
            $bloqueos++;
            $minutos = 10;
            if ($bloqueos === 2) { $minutos = 30; }
            elseif ($bloqueos >= 3) { $minutos = 120; }
            $bloqueo = date('Y-m-d H:i:s', time() + $minutos * 60);
            $nuevos = 0;
            $bloqueadoAhora = true;
            $GLOBALS['_login_minutos_bloqueo'] = $minutos;
        }

        if ($intento) {
            $stmtFail = $conexion->prepare("UPDATE intentos_login SET intentos = ?, bloqueo_hasta = ?, bloqueos_consecutivos = ?, razon = ?, exitoso = false, ultimo_intento = NOW() WHERE ci_user = ?");
            $stmtFail->bind_param("isiss", $nuevos, $bloqueo, $bloqueos, $razon, $cedula);
            $stmtFail->execute();
            $stmtFail->close();
        } else {
            $stmtFail = $conexion->prepare("INSERT INTO intentos_login (ci_user, intentos, bloqueo_hasta, bloqueos_consecutivos, razon, exitoso, ultimo_intento) VALUES (?, ?, ?, ?, ?, false, NOW())");
            $stmtFail->bind_param("sisis", $cedula, $nuevos, $bloqueo, $bloqueos, $razon);
            $stmtFail->execute();
            $stmtFail->close();
        }

        if ($bloqueadoAhora) {
            // Bitácora: cuenta bloqueada por intentos fallidos.
            require_once __DIR__ . '/../config/bitacora_helper.php';
            bitacoraIntentoFallido($conexion, 'iniciar_sesion', 'cuenta_bloqueada', [
                'cedula' => $cedula,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconocida',
                'minutos_bloqueo' => $GLOBALS['_login_minutos_bloqueo'] ?? MINUTOS_BLOQUEO,
            ]);
            // Alerta por correo al usuario si la cuenta existe.
            if ($datos_usuario && !empty($datos_usuario['id_usuario'])) {
                require_once __DIR__ . '/../config/notificar_helper.php';
                notificar(
                    $conexion,
                    (int)$datos_usuario['id_usuario'],
                    'Cuenta bloqueada temporalmente',
                    "Tu cuenta se bloqueó temporalmente tras varios intentos fallidos de inicio de sesión.\n"
                    . "IP del intento: " . ($_SERVER['REMOTE_ADDR'] ?? 'desconocida') . "\n"
                    . "Fecha: " . date('d/m/Y H:i') . "\n\n"
                    . "Podrás intentar de nuevo en unos minutos. Si no fuiste tú, cambia tu contraseña al recuperar el acceso.",
                    'seguridad'
                );
            }
            $minutos = (int)($GLOBALS['_login_minutos_bloqueo'] ?? MINUTOS_BLOQUEO);
            redirigir("Demasiados intentos fallidos. Cuenta bloqueada temporalmente por " . $minutos . " minutos.", $esAjax);
        }

        redirigir("El usuario o la clave son incorrectas", $esAjax);
    }
} else {
    redirigir("Faltan datos del formulario.", $esAjax);
}
