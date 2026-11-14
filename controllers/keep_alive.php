<?php
require_once __DIR__ . '/../config/session_timeout.php';
require_once __DIR__ . '/../config/seguridad.php';
require_once __DIR__ . '/../config/Conexion.php';
header('Content-Type: application/json');

$ci    = $_SESSION['ci_user'] ?? '';
$token = $_SESSION['token_sesion'] ?? '';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if ($token !== '') {
    @setcookie('iaa_id', base64_encode($token), ['expires' => time() + 604800, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
} elseif (!empty($_COOKIE['iaa_id'])) {
    $token = base64_decode($_COOKIE['iaa_id']);
}

if ($ci === '' && $token === '') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

asegurarColumnaCerradaPorAdmin($conexion);
if ($token !== '') {
    $stmt = $conexion->prepare("SELECT cerrada_por_admin FROM logeo WHERE token_sesion = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($fila && !empty($fila['cerrada_por_admin'])) {
            session_unset();
            session_destroy();
            @setcookie('iaa_id', '', ['expires' => time() - 3600, 'path' => '/']);
            echo json_encode(['success' => false, 'expired' => true, 'message' => 'Tu sesión fue cerrada por el administrador']);
            exit;
        }
    }
}

if ($ci === '') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!enforceSessionTimeout(false)) {
    echo json_encode(['success' => false, 'expired' => true, 'message' => 'Sesión expirada']);
    exit;
}

if (!verificarSesionUnica($conexion)) {
    session_unset();
    session_destroy();
    echo json_encode(['success' => false, 'expired' => true, 'message' => 'Tu sesión fue reemplazada por otra con la misma cuenta']);
    exit;
}

$_SESSION['LAST_ACTIVITY'] = time();
sesionAutoCierre($conexion);

// NO se rota aquí el ID de sesión ni el token de `logeo`.
//
// rotarTokenSesion() hace session_regenerate_id(true), que BORRA el archivo de
// la sesión anterior. Este endpoint se invoca de forma periódica y en paralelo
// con la navegación (el latido se dispara con el primer movimiento del ratón o
// con el sondeo de 15 s). Si la rotación cae en medio de otra petición, esta
// llega con el ID viejo, se queda sin sesión y responde "No autorizado": el JS
// lo interpreta como sesión expirada y expulsa al usuario recién logueado.
//
// La rotación de ID ya se hace una vez al iniciar sesión (completarLogin) y la
// rotación periódica la sigue cubriendo configurarSesionSegura() en peticiones
// de página, donde no compite con el sondeo.

$no_leidas = 0;
try {
    $stmtNl = $conexion->prepare("SELECT COUNT(*) FROM notificacion WHERE id_usuario = ? AND leida = false");
    if ($stmtNl) {
        $idUser = isset($_SESSION['id_user']) ? intval($_SESSION['id_user']) : 0;
        $stmtNl->bind_param('i', $idUser);
        $stmtNl->execute();
        $stmtNl->bind_result($no_leidas);
        $stmtNl->fetch();
        $stmtNl->close();
        $no_leidas = (int)$no_leidas;
    }
} catch (Throwable $e) {
    $no_leidas = 0;
}

echo json_encode([
    'success'   => true,
    'remaining' => SESSION_TIMEOUT_SECONDS,
    'no_leidas' => $no_leidas
]);
