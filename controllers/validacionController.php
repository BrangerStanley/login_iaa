<?php
// controllers/validacionController.php — paso inicial del registro, sin verificación documental.
require_once __DIR__ . '/../config/seguridad.php';
configurarSesionSegura();
require_once __DIR__ . '/../config/Conexion.php';
require_once __DIR__ . '/../config/cedula_helper.php';
require_once __DIR__ . '/../config/bitacora_helper.php';
require_once __DIR__ . '/../models/ValidacionModel.php';
require_once __DIR__ . '/../models/LogeoModel.php';

$esAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

define('URL_VALIDACION', BASE_URL . '/public/logeo/validacion.php');

function responder($success, $mensaje, $esAjax, $redirect = null)
{
    if ($esAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => (bool) $success,
            'mensaje' => (string) $mensaje,
            'redirect' => $redirect,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        if ($success && $redirect) {
            header('Location: ' . $redirect);
        } else {
            header('Location: ' . URL_VALIDACION . '?error=' . urlencode((string) $mensaje));
        }
    }
    exit;
}

function fallo(string $motivo, string $mensaje, bool $esAjax, array $contexto = []): never
{
    global $conexion;
    bitacoraIntentoFallido($conexion, 'registro_validacion', $motivo, $contexto);
    responder(false, $mensaje, $esAjax);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fallo('metodo_invalido', 'Petición no válida.', $esAjax);
}

if (!csrf_verify()) {
    fallo('csrf_invalido', 'Token de seguridad inválido. Recargue la página.', $esAjax);
}

exigirRateLimitPorIp('registro_validacion', 15, 60);

if (!verificarCaptcha(is_string($_POST['captcha'] ?? null) ? $_POST['captcha'] : null)) {
    fallo('captcha_invalido', 'Código de seguridad incorrecto. Intente de nuevo.', $esAjax);
}

$cedula = cedula_digitos(is_scalar($_POST['cedula'] ?? null) ? (string) $_POST['cedula'] : '');
if (!cedula_formato_valido($cedula)) {
    fallo('cedula_formato_invalido', 'Debe ingresar una cédula válida (7 u 8 dígitos).', $esAjax, ['ci_usuario' => $cedula]);
}

$modelo = new ValidacionModel($conexion);
$persona = $modelo->getPersonaDatos($cedula);
if ($modelo->existeUsuario($cedula)) {
    fallo('cuenta_ya_existe', 'Esta cédula ya tiene una cuenta. Inicia sesión con tus credenciales.', $esAjax, ['ci_usuario' => $cedula]);
}

session_regenerate_id(true);
$_SESSION['ci_user'] = $cedula;
$_SESSION['persona_nueva'] = $persona ? false : true;
$_SESSION['tipo_registro'] = 'particular';
$_SESSION['nombre_precargado'] = $persona ? (string) ($persona['nombre'] ?? '') : '';
$_SESSION['registros_previos'] = [];
$_SESSION['datos_cedula'] = [
    'fecha_nacimiento' => (string) ($persona['fecha_nacimiento'] ?? ''),
    'sexo'             => (string) ($persona['sexo'] ?? ''),
    'nacionalidad'     => (string) ($persona['nacionalidad'] ?? 'Venezolano'),
    'apellidos'        => (string) ($persona['apellidos'] ?? ''),
    'nombres'          => (string) ($persona['nombres'] ?? ''),
    'nombre_completo'  => (string) ($persona['nombre'] ?? ''),
    'validado_at'      => time(),
];

$logeo = new LogeoModel();
$logeo->registrarEvento(0, $cedula, '', 'registro_validacion', 'Inicio de registro sin verificación documental: ' . $cedula);
registrarBitacora($conexion, 'registro_validacion', 'Inicio de registro sin verificación documental: ' . $cedula, 'seguridad');

responder(true, 'Puedes continuar con el registro.', $esAjax, BASE_URL . '/public/logeo/registrar.php');
