<?php
// controllers/registroController.php — registro directo de cuenta particular activa.
require_once __DIR__ . '/../config/seguridad.php';
configurarSesionSegura();
require_once __DIR__ . '/../config/Conexion.php';
require_once __DIR__ . '/../config/cedula_helper.php';
require_once __DIR__ . '/../config/contacto_helper.php';
require_once __DIR__ . '/../config/bitacora_helper.php';
require_once __DIR__ . '/../models/RegistroModel.php';
require_once __DIR__ . '/../models/LogeoModel.php';

function fallo(string $motivo, string $msg, array $ctx = []): never
{
    global $conexion;
    bitacoraIntentoFallido($conexion, 'registro_cuenta', $motivo, $ctx);
    echo '<script>alert(' . json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '); window.location = ' . json_encode(BASE_URL . '/public/logeo/registrar.php') . ';</script>';
    exit;
}

if (!isset($_SESSION['ci_user'])) {
    header('Location: ' . BASE_URL . '/public/logeo/validacion.php');
    exit;
}

$datosSesion = is_array($_SESSION['datos_cedula'] ?? null) ? $_SESSION['datos_cedula'] : [];
$validadoAt = (int) ($datosSesion['validado_at'] ?? 0);
if ($validadoAt <= 0 || (time() - $validadoAt) > 1800) {
    unset($_SESSION['ci_user'], $_SESSION['datos_cedula'], $_SESSION['persona_nueva']);
    fallo('validacion_expirada', 'La validación expiró. Vuelve a validar tu cédula.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    fallo('csrf_invalido', 'Petición no válida, intenta de nuevo.');
}

exigirRateLimitPorIp('registro', 10, 60);

if (($_POST['acepto_privacidad'] ?? '') !== '1') {
    fallo('privacidad_no_aceptada', 'Debes aceptar la Política de Privacidad para crear tu cuenta.');
}

if (!verificarCaptcha(is_string($_POST['captcha'] ?? null) ? $_POST['captcha'] : null)) {
    fallo('captcha_invalido', 'Código de seguridad incorrecto, intenta de nuevo.');
}

$campo = function (string $k, bool $trim = true): string {
    $v = $_POST[$k] ?? '';
    if (!is_scalar($v)) return '';
    $s = (string) $v;
    return $trim ? trim($s) : $s;
};

$ci = cedula_digitos($campo('cedula'));
if ($ci === '' || $ci !== cedula_digitos((string) $_SESSION['ci_user'])) {
    fallo('cedula_no_coincide', 'La cédula no coincide con la validación.', ['ci_usuario' => $ci]);
}
if (!cedula_formato_valido($ci)) {
    fallo('cedula_formato_invalido', 'Formato de cédula inválido.', ['ci_usuario' => $ci]);
}

$nombre = preg_replace('/\s+/u', ' ', $campo('nombre')) ?? '';
if ($nombre === '' && !empty($datosSesion['nombre_completo'])) {
    $nombre = (string) $datosSesion['nombre_completo'];
}
if (mb_strlen($nombre, 'UTF-8') < 3 || mb_strlen($nombre, 'UTF-8') > 150
    || preg_match('/[\x00-\x1F\x7F]/u', $nombre)
    || !preg_match("/^[\\p{L}\\p{M} .'-]+$/u", $nombre)) {
    fallo('nombre_invalido', 'El nombre es inválido (3-150 caracteres, solo letras).', ['ci_usuario' => $ci]);
}

$password = $campo('pass', false);
if (strlen($password) > 72) {
    fallo('password_demasiado_larga', 'La contraseña no debe superar los 72 caracteres.', ['ci_usuario' => $ci]);
}
$errorPass = validarComplejidadPassword($password);
if ($errorPass !== null) {
    fallo('password_insegura', $errorPass, ['ci_usuario' => $ci]);
}
if (!hash_equals($password, $campo('pass_confirm', false))) {
    fallo('password_no_coincide', 'Las contraseñas no coinciden.', ['ci_usuario' => $ci]);
}

$telefono = preg_replace('/[\s\-\.()]+/u', '', $campo('telefono')) ?? '';
if (!preg_match('/^\d{7,15}$/', $telefono)) {
    fallo('telefono_invalido', 'Teléfono inválido (7 a 15 dígitos).', ['ci_usuario' => $ci]);
}

$correo = $campo('correo');
if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 150 || preg_match('/[\x00-\x1F\x7F]/', $correo)) {
    fallo('correo_invalido', 'Correo electrónico inválido.', ['ci_usuario' => $ci]);
}

$sexo = $campo('sexo');
if ($sexo === '' && isset($datosSesion['sexo'])) $sexo = (string) $datosSesion['sexo'];
if (!in_array($sexo, ['Masculino', 'Femenino', 'M', 'F'], true)) $sexo = '';

$fecha_nac = $campo('fecha_nacimiento');
if ($fecha_nac === '' && !empty($datosSesion['fecha_nacimiento'])) $fecha_nac = (string) $datosSesion['fecha_nacimiento'];

$nacionalidad = ($campo('nacionalidad') === 'Extranjero' || ($datosSesion['nacionalidad'] ?? '') === 'Extranjero') ? 'Extranjero' : 'Venezolano';

// Ya tiene cuenta?
$stmt = $conexion->prepare(
    "SELECT id_usuario FROM usuario WHERE regexp_replace(upper(ci_usuario), '[^0-9]', '', 'g') = ? LIMIT 1"
);
$stmt->bind_param('s', $ci);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    fallo('cuenta_ya_existe', 'Esta cédula ya tiene una cuenta. Inicia sesión.', ['ci_usuario' => $ci]);
}
$stmt->close();

// Contacto duplicado (excluye persona propia si existe)
$idPersonaExistente = 0;
$stmt = $conexion->prepare("SELECT id_persona FROM persona WHERE regexp_replace(upper(ci_usuario), '[^0-9]', '', 'g') = ? LIMIT 1");
$stmt->bind_param('s', $ci);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($r) $idPersonaExistente = (int) $r['id_persona'];

$conflicto = contacto_cuenta_vinculada($conexion, $correo, $telefono, 0, $idPersonaExistente);
if ($conflicto !== null) {
    fallo('contacto_vinculado_otra_cuenta', contacto_mensaje_duplicado($conflicto) . ' Usa otro correo y teléfono.', ['ci_usuario' => $ci]);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$modelo = new RegistroModel($conexion);
$ok = $modelo->registrarUsuario($ci, $hash, $idPersonaExistente, [
    'nombre'           => $nombre,
    'telefono'         => $telefono,
    'correo'           => $correo,
    'sexo'             => $sexo,
    'fecha_nacimiento' => $fecha_nac,
    'nacionalidad'     => $nacionalidad,
    'apellidos'        => (string) ($datosSesion['apellidos'] ?? ''),
    'nombres'          => (string) ($datosSesion['nombres'] ?? ''),
], '');

if (!$ok) {
    fallo('error_registro_modelo', 'No se pudo crear la cuenta, inténtalo de nuevo.', ['ci_usuario' => $ci]);
}

$logeo = new LogeoModel();
$logeo->registrarEvento(0, $ci, $nombre, 'registro_cuenta', 'Cuenta particular creada (activa): ' . $ci);
registrarBitacora($conexion, 'registro_cuenta', 'Cuenta creada: CI ' . $ci, 'dato_guardado');

unset($_SESSION['ci_user'], $_SESSION['datos_cedula'], $_SESSION['persona_nueva'], $_SESSION['tipo_registro'], $_SESSION['nombre_precargado'], $_SESSION['registros_previos']);

header('Location: ' . BASE_URL . '/public/logeo/login.php?registro=ok');
exit;
