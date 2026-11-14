<?php
declare(strict_types=1);

/**
 * scripts/crear_usuario.php — crea una cuenta adicional en el sistema.
 *
 *   php scripts/crear_usuario.php --cedula=19876543 --nombre="Ana Pérez" \
 *        --correo=ana@example.com --rol=funcionario --clave=Segura#123
 *
 * Sin opciones, pregunta los datos por consola. La clave se genera si no se
 * indica (y se imprime una sola vez).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

require_once __DIR__ . '/../config/env.php';

function salida(string $t = ''): void
{
    fwrite(STDOUT, $t . PHP_EOL);
}

function fallo(string $t, int $c = 1): never
{
    fwrite(STDERR, 'ERROR: ' . $t . PHP_EOL);
    exit($c);
}

function pedir(string $etiqueta, string $porDefecto = ''): string
{
    $sufijo = $porDefecto !== '' ? ' [' . $porDefecto . ']' : '';
    fwrite(STDOUT, $etiqueta . $sufijo . ': ');
    $linea = fgets(STDIN);
    if ($linea === false) {
        fallo('Entrada no disponible.');
    }
    $valor = trim($linea);

    return $valor !== '' ? $valor : $porDefecto;
}

function esClaveSegura(string $clave): bool
{
    return strlen($clave) >= 8
        && preg_match('/[A-Z]/', $clave) === 1
        && preg_match('/[a-z]/', $clave) === 1
        && preg_match('/[0-9]/', $clave) === 1
        && preg_match('/[^a-zA-Z0-9]/', $clave) === 1;
}

function generarClave(): string
{
    $especiales = ['@', '#', '$', '!', '%', '&'];
    do {
        $partes = [];
        for ($i = 0; $i < 12; $i++) {
            $partes[] = match (random_int(0, 2)) {
                0 => chr(random_int(65, 90)),
                1 => chr(random_int(97, 122)),
                default => chr(random_int(48, 57)),
            };
        }
        $partes[] = $especiales[array_rand($especiales)];
        $clave = implode('', $partes);
    } while (!esClaveSegura($clave));

    return $clave;
}

$opciones = getopt('', ['cedula:', 'nombre:', 'correo:', 'rol:', 'clave:', 'help']);

if (isset($opciones['help'])) {
    salida('Uso: php scripts/crear_usuario.php [--cedula=...] [--nombre=...] [--correo=...] [--rol=funcionario] [--clave=...]');
    exit(0);
}

$host = iaa_env_valor('IAA_DB_HOST', '127.0.0.1');
$puerto = iaa_env_valor('IAA_DB_PORT', '5432');
$usuario = iaa_env_valor('IAA_DB_USER', 'login_demo');
$clave = iaa_env_valor('IAA_DB_PASS', '');
$baseDatos = iaa_env_valor('IAA_DB_NAME', 'login_demo');
$sslmode = iaa_env_valor('IAA_DB_SSLMODE', 'prefer');

if ($clave === '') {
    fallo('IAA_DB_PASS no está definido. Revisa el archivo .env.');
}

try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=5', $host, $puerto, $baseDatos, $sslmode),
        $usuario,
        $clave,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fallo('No se pudo conectar con PostgreSQL: ' . $e->getMessage());
}

$cedula = preg_replace('/\D+/', '', (string) ($opciones['cedula'] ?? pedir('Cédula (solo dígitos)', '19876543')));
$nombre = trim((string) ($opciones['nombre'] ?? pedir('Nombre completo', '')));
$correo = trim((string) ($opciones['correo'] ?? pedir('Correo electrónico', '')));
$rolNombre = strtolower(trim((string) ($opciones['rol'] ?? pedir('Rol (funcionario|moderador|supervisor|administrador|medico|particular)', 'funcionario'))));
$claveUsuario = (string) ($opciones['clave'] ?? '');

if (!preg_match('/^\d{7,8}$/', $cedula)) {
    fallo('La cédula debe tener 7 u 8 dígitos.');
}
if ($nombre === '') {
    fallo('El nombre es obligatorio.');
}
if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
    fallo('El correo no es válido.');
}
if ($claveUsuario === '') {
    $claveUsuario = generarClave();
    salida('Clave generada: ' . $claveUsuario);
} elseif (!esClaveSegura($claveUsuario)) {
    fallo('La clave debe tener 8+ caracteres, mayúscula, minúscula, número y un especial.');
}

$buscaRol = $pdo->prepare('SELECT id FROM rol WHERE lower(nombre) = :nombre LIMIT 1');
$buscaRol->execute([':nombre' => $rolNombre]);
$idRol = (int) $buscaRol->fetchColumn();
if ($idRol <= 0) {
    fallo('Rol "' . $rolNombre . '" no existe.');
}

$busca = $pdo->prepare('SELECT id_usuario FROM usuario WHERE ci_usuario = :ci LIMIT 1');
$busca->execute([':ci' => $cedula]);
$idUsuario = (int) $busca->fetchColumn();

$hash = password_hash($claveUsuario, PASSWORD_DEFAULT);

if ($idUsuario > 0) {
    fallo('Ya existe una cuenta con la cédula ' . $cedula . '. Usa el instalador para resetear su clave.');
}

$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare('INSERT INTO persona (nombre, correo, ci_usuario) VALUES (:nombre, :correo, :ci)');
    $stmt->execute([':nombre' => $nombre, ':correo' => $correo, ':ci' => $cedula]);
    $idPersona = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        "INSERT INTO usuario (id_persona, ci_usuario, contrasena, id_rol, estado, password_changed_at)
         VALUES (:persona, :ci, :hash, :rol, 'activo', NOW())"
    );
    $stmt->execute([':persona' => $idPersona, ':ci' => $cedula, ':hash' => $hash, ':rol' => $idRol]);
    $idUsuario = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (:u, :r) ON CONFLICT DO NOTHING');
    $stmt->execute([':u' => $idUsuario, ':r' => $idRol]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fallo('No se pudo crear la cuenta: ' . $e->getMessage());
}

salida();
salida('Cuenta creada correctamente:');
salida('  Cédula : ' . $cedula);
salida('  Nombre : ' . $nombre);
salida('  Rol    : ' . $rolNombre);
salida('  Clave  : ' . $claveUsuario);
