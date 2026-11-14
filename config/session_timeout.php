<?php
require_once __DIR__ . '/seguridad.php';
if (session_status() === PHP_SESSION_NONE) {
    iniciarSesionSegura();
}

if (!defined('SESSION_TIMEOUT_SECONDS')) {
    // 3 minutos ponderados de inactividad antes de cerrar la sesión.
    define('SESSION_TIMEOUT_SECONDS', 180);
}

if (!defined('SESSION_WARNING_SECONDS')) {
    define('SESSION_WARNING_SECONDS', 30);
}

// Podador de sesiones huérfanas (pestañas cerradas sin cerrar sesión):
// coincide con SESSION_TIMEOUT_SECONDS para que, si el navegador se cierra
// sin aviso, el servidor la marque 'inactivo' apenas el latido deje de llegar.
if (!defined('STALE_SESSION_SECONDS')) {
    define('STALE_SESSION_SECONDS', 180);
}

function asegurarColumnaLastActivity($conexion) {
    if (!$conexion) return false;
    static $verificado = [];
    if (isset($verificado[spl_object_id($conexion)])) {
        return $verificado[spl_object_id($conexion)];
    }
    try {
        $res = $conexion->query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'logeo'
                AND column_name = 'last_activity' LIMIT 1"
        );
        if ($res === false) return false;
        if ($res->num_rows > 0) {
            $verificado[spl_object_id($conexion)] = true;
            return true;
        }
        error_log("session_timeout: falta la columna logeo.last_activity. Ejecute la migración (especialidad/nota_clinica/laste_activity).");
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function asegurarColumnaCerradaPorAdmin($conexion) {
    if (!$conexion) return false;
    static $verificado = [];
    if (isset($verificado[spl_object_id($conexion)])) {
        return $verificado[spl_object_id($conexion)];
    }
    try {
        $res = $conexion->query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'logeo'
                AND column_name = 'cerrada_por_admin' LIMIT 1"
        );
        if ($res !== false && $res->num_rows > 0) {
            $verificado[spl_object_id($conexion)] = true;
            return true;
        }
        error_log("session_timeout: falta la columna logeo.cerrada_por_admin. Ejecute la migración.");
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function sesionAutoCierre($conexion) {
    if (!$conexion) return;
    $columnaLista = asegurarColumnaLastActivity($conexion);

    if ($columnaLista && isset($_SESSION['token_sesion'])) {
        $stmt = $conexion->prepare("UPDATE logeo SET last_activity = NOW() WHERE token_sesion = ? AND estado = 'activo'");
        if ($stmt) {
            $stmt->bind_param('s', $_SESSION['token_sesion']);
            $stmt->execute();
            $stmt->close();
        }
    }

    $hacerLimpieza = true;
    if (isset($_SESSION['_iaa_ultima_limpieza'])) {
        $hacerLimpieza = (time() - intval($_SESSION['_iaa_ultima_limpieza'])) >= 60;
    }

    if ($hacerLimpieza && $columnaLista) {
        $stale = (int) STALE_SESSION_SECONDS;
        $sql = "UPDATE logeo
                   SET estado = 'inactivo', f_sal = COALESCE(last_activity, f_ing)
                 WHERE estado = 'activo'
                   AND COALESCE(last_activity, f_ing) < (NOW() - INTERVAL $stale SECOND)";
        if ($conexion->query($sql) === false) {
            error_log('session_timeout: no se pudieron cerrar sesiones inactivas: ' . $conexion->error);
        }
        if (isset($_SESSION['token_sesion'])) {
            $_SESSION['_iaa_ultima_limpieza'] = time();
        }
    }
}

function getSessionTimeoutRemaining() {
    if (!isset($_SESSION['LAST_ACTIVITY'])) {
        $_SESSION['LAST_ACTIVITY'] = time();
        return SESSION_TIMEOUT_SECONDS;
    }
    $elapsed = time() - intval($_SESSION['LAST_ACTIVITY']);
    $remaining = SESSION_TIMEOUT_SECONDS - $elapsed;
    return $remaining > 0 ? $remaining : 0;
}

function enforceSessionTimeout($redirect = true, $redirectUrl = '../public/logeo/login.php?msg=Sesion+expirada+por+inactividad') {
    $remaining = getSessionTimeoutRemaining();
    if ($remaining <= 0) {
        $conexion = isset($GLOBALS['conexion']) ? $GLOBALS['conexion'] : null;
        if ($conexion && isset($_SESSION['token_sesion'])) {
            $token = $_SESSION['token_sesion'];
            $stmt = $conexion->prepare("UPDATE logeo SET estado = 'inactivo', f_sal = NOW() WHERE token_sesion = ? AND estado = 'activo'");
            if ($stmt) {
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $stmt->close();
            }
        }
        session_unset();
        session_destroy();
        if ($redirect) {
            if (!headers_sent()) {
                header("Location: $redirectUrl");
            }
            exit;
        }
        return false;
    }
    $_SESSION['LAST_ACTIVITY'] = time();
    return true;
}
