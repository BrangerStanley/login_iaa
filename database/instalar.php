<?php
declare(strict_types=1);

/**
 * ============================================================================
 * database/instalar.php — instalador de Login Seguro
 * ============================================================================
 *
 *   php database/instalar.php --admin-pass='<clave de postgres>'
 *
 * Qué hace, en este orden:
 *   1. Crea el rol de aplicación (si no existe) con su contraseña.
 *   2. Crea la base de datos (si no existe) con ese rol como propietario.
 *   3. Aplica database/schema.sql sobre la base recién creada.
 *   4. Crea (o actualiza) la cuenta demo con una contraseña aleatoria.
 *
 * Es idempotente: se puede ejecutar tantas veces como haga falta.
 * Solo puede ejecutarse por CLI (nunca por HTTP).
 *
 * Opciones:
 *   --admin-user=postgres   Rol administrativo (por defecto: IAA_DB_ADMIN_USER)
 *   --admin-pass=...        Su contraseña (por defecto: IAA_DB_ADMIN_PASS o prompt)
 *   --demo-pass=...         Contraseña de la cuenta demo (por defecto: IAA_DEMO_PASS
 *                           o una aleatoria generada en el acto)
 *   --help                  Muestra esta ayuda
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

require_once __DIR__ . '/../config/env.php';

// ---------------------------------------------------------------------------
// Utilidades de consola
// ---------------------------------------------------------------------------

function salida(string $texto = ''): void
{
    fwrite(STDOUT, $texto . PHP_EOL);
}

function fallo(string $texto, int $codigo = 1): never
{
    fwrite(STDERR, 'ERROR: ' . $texto . PHP_EOL);
    exit($codigo);
}

function titulo(string $texto): void
{
    salida();
    salida('=== ' . $texto . ' ===');
}

/**
 * Identificador SQL seguro (rol / base de datos). PostgreSQL permite casi
 * cualquier cosa entre comillas dobles, pero preferimos no dar pie a sorpresas:
 * solo mayúsculas, minúsculas, dígitos y guion bajo, sin empezar por dígito.
 */
function identificadorValido(string $valor): bool
{
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $valor);
}

/**
 * Contraseña aleatoria que cumple la complejidad del sistema
 * (8+ caracteres, mayúscula, minúscula, número y especial).
 */
function generarClave(int $longitud = 16): string
{
    $especiales = ['@', '#', '$', '!', '%', '&'];
    $clave = '';

    do {
        $clave = '';
        for ($i = 0; $i < $longitud - 4; $i++) {
            $tipo = random_int(0, 2);
            $clave .= match ($tipo) {
                0 => chr(random_int(65, 90)),
                1 => chr(random_int(97, 122)),
                default => chr(random_int(48, 57)),
            };
        }
        $clave .= $especiales[array_rand($especiales)];
        $clave .= chr(random_int(65, 90));
        $clave .= chr(random_int(97, 122));
        $clave .= chr(random_int(48, 57));
        $letras = preg_split('//u', $clave, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        shuffle($letras);
        $clave = implode('', $letras);
    } while (!esClaveSegura($clave));

    return $clave;
}

function esClaveSegura(string $clave): bool
{
    return strlen($clave) >= 8
        && preg_match('/[A-Z]/', $clave) === 1
        && preg_match('/[a-z]/', $clave) === 1
        && preg_match('/[0-9]/', $clave) === 1
        && preg_match('/[^a-zA-Z0-9]/', $clave) === 1;
}

function pedirClave(string $etiqueta): string
{
    fwrite(STDOUT, $etiqueta . ': ');
    $linea = fgets(STDIN);

    if ($linea === false) {
        fallo('No se pudo leer la contraseña.');
    }

    return rtrim($linea, "\r\n");
}

// ---------------------------------------------------------------------------
// Opciones de línea de comandos
// ---------------------------------------------------------------------------

$opciones = getopt('', ['admin-user::', 'admin-pass::', 'demo-pass::', 'help']);

if (isset($opciones['help'])) {
    salida('Uso: php database/instalar.php [--admin-user=postgres] [--admin-pass=...] [--demo-pass=...]');
    exit(0);
}

$host = iaa_env_valor('IAA_DB_HOST', '127.0.0.1');
$puerto = (int) iaa_env_valor('IAA_DB_PORT', '5432');
$sslmode = iaa_env_valor('IAA_DB_SSLMODE', '');
if ($sslmode === '') {
    $sslmode = (PHP_OS_FAMILY === 'Windows') ? 'prefer' : 'verify-full';
}

$usuarioApp = iaa_env_valor('IAA_DB_USER', 'login_demo');
$claveApp = iaa_env_valor('IAA_DB_PASS', '');
$baseDatos = iaa_env_valor('IAA_DB_NAME', 'login_demo');

$usuarioAdmin = $opciones['admin-user'] ?? iaa_env_valor('IAA_DB_ADMIN_USER', 'postgres');
$claveAdmin = $opciones['admin-pass'] ?? iaa_env_valor('IAA_DB_ADMIN_PASS', '');

if ($usuarioApp === '' || $baseDatos === '') {
    fallo('IAA_DB_USER / IAA_DB_NAME no pueden estar vacíos. Revisa el archivo .env.');
}

if (!identificadorValido($usuarioApp)) {
    fallo('IAA_DB_USER "' . $usuarioApp . '" no es un nombre válido de rol PostgreSQL.');
}

if (!identificadorValido($baseDatos)) {
    fallo('IAA_DB_NAME "' . $baseDatos . '" no es un nombre válido de base de datos.');
}

if ($claveApp === '' || $claveApp === 'cambia_esta_clave') {
    fallo(
        "IAA_DB_PASS no está definido (o sigue con el valor de plantilla).\n"
        . "       Edita .env y pon una contraseña real para el rol " . $usuarioApp . "."
    );
}

if (!identificadorValido($usuarioAdmin)) {
    fallo('IAA_DB_ADMIN_USER "' . $usuarioAdmin . '" no es un nombre válido.');
}

if ($claveAdmin === '') {
    $claveAdmin = pedirClave('Contraseña del rol administrativo (' . $usuarioAdmin . ')');
}

if ($claveAdmin === '') {
    fallo('La contraseña administrativa no puede quedar vacía.');
}

salida('Login Seguro · instalador');
salida('  PostgreSQL : ' . $host . ':' . $puerto . ' (sslmode=' . $sslmode . ')');
salida('  Aplicación : rol ' . $usuarioApp . ' · base ' . $baseDatos);

// ---------------------------------------------------------------------------
// 1. Conexión administrativa
// ---------------------------------------------------------------------------

titulo('1/4  Rol de aplicación');

try {
    $admin = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres;sslmode=%s;connect_timeout=5', $host, $puerto, $sslmode),
        $usuarioAdmin,
        $claveAdmin,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fallo(
        "No se pudo conectar como " . $usuarioAdmin . " a la base 'postgres'.\n"
        . '       Motivo: ' . $e->getMessage() . "\n"
        . '       Comprueba IAA_DB_ADMIN_USER / IAA_DB_ADMIN_PASS en .env.'
    );
}

$existeRol = $admin->prepare('SELECT 1 FROM pg_roles WHERE rolname = :rol');
$existeRol->execute([':rol' => $usuarioApp]);
$rolExiste = (bool) $existeRol->fetchColumn();

if ($rolExiste) {
    $admin->exec('ALTER ROLE ' . $usuarioApp . ' WITH LOGIN PASSWORD ' . $admin->quote($claveApp));
    salida('  · El rol ya existía: contraseña actualizada para que coincida con .env.');
} else {
    $admin->exec('CREATE ROLE ' . $usuarioApp . ' WITH LOGIN PASSWORD ' . $admin->quote($claveApp));
    salida('  · Rol creado: ' . $usuarioApp);
}

// ---------------------------------------------------------------------------
// 2. Base de datos
// ---------------------------------------------------------------------------

titulo('2/4  Base de datos');

$existeBase = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = :base');
$existeBase->execute([':base' => $baseDatos]);

if ((bool) $existeBase->fetchColumn()) {
    salida('  · La base "' . $baseDatos . '" ya existe: se conserva tal cual.');
} else {
    $admin->exec(
        'CREATE DATABASE ' . $baseDatos
        . ' OWNER ' . $usuarioApp
        . " ENCODING 'UTF8' TEMPLATE template0"
    );
    salida('  · Base creada: ' . $baseDatos);
}

// ---------------------------------------------------------------------------
// 3. Esquema
// ---------------------------------------------------------------------------

titulo('3/4  Esquema (database/schema.sql)');

try {
    $app = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=5', $host, $puerto, $baseDatos, $sslmode),
        $usuarioApp,
        $claveApp,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $app->exec("SET client_encoding TO 'UTF8'");
} catch (PDOException $e) {
    fallo(
        'No se pudo conectar con la base "' . $baseDatos . '" como ' . $usuarioApp . ".\n"
        . '       Motivo: ' . $e->getMessage()
    );
}

$sql = @file_get_contents(__DIR__ . '/schema.sql');

if ($sql === false || trim($sql) === '') {
    fallo('No se pudo leer database/schema.sql.');
}

try {
    $app->exec($sql);
    salida('  · Tablas creadas (roles, permisos, personas, usuarios, logeo,');
    salida('    intentos_login, bitácora y notificaciones).');
} catch (PDOException $e) {
    fallo('Error al aplicar el esquema: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 4. Cuenta demo
// ---------------------------------------------------------------------------

titulo('4/4  Cuenta de demostración');

$cedula = preg_replace('/\D+/', '', (string) iaa_env_valor('DEMO_CEDULA', '12345678'));
$nombre = trim((string) iaa_env_valor('DEMO_NOMBRE', 'Usuario Demo'));
$correo = trim((string) iaa_env_valor('DEMO_CORREO', 'demo@example.com'));
$rolNombre = strtolower(trim((string) iaa_env_valor('DEMO_ROL', 'administrador')));
$claveDemo = $opciones['demo-pass'] ?? iaa_env_valor('DEMO_PASSWORD', '');

if (!preg_match('/^\d{7,8}$/', $cedula)) {
    fallo('DEMO_CEDULA debe tener 7 u 8 dígitos (ej. 12345678).');
}

$generada = false;
if ($claveDemo === '') {
    $claveDemo = generarClave();
    $generada = true;
} elseif (!esClaveSegura($claveDemo)) {
    fallo('DEMO_PASSWORD debe tener 8+ caracteres, mayúscula, minúscula, número y un especial.');
}

$buscaRol = $app->prepare('SELECT id FROM rol WHERE lower(nombre) = :nombre LIMIT 1');
$buscaRol->execute([':nombre' => $rolNombre]);
$idRol = (int) $buscaRol->fetchColumn();

if ($idRol <= 0) {
    fallo('DEMO_ROL "' . $rolNombre . '" no existe. Opciones: funcionario, moderador, supervisor, administrador, medico, particular.');
}

$hash = password_hash($claveDemo, PASSWORD_DEFAULT);

$buscaUsuario = $app->prepare('SELECT id_usuario FROM usuario WHERE ci_usuario = :ci LIMIT 1');
$buscaUsuario->execute([':ci' => $cedula]);
$idUsuario = (int) $buscaUsuario->fetchColumn();

if ($idUsuario > 0) {
    $stmt = $app->prepare(
        'UPDATE usuario
            SET contrasena = :hash, id_rol = :rol, estado = \'activo\', password_changed_at = NOW()
          WHERE id_usuario = :id'
    );
    $stmt->execute([':hash' => $hash, ':rol' => $idRol, ':id' => $idUsuario]);

    $stmt = $app->prepare(
        'UPDATE persona
            SET nombre = :nombre, correo = :correo
          WHERE id_persona = (SELECT id_persona FROM usuario WHERE id_usuario = :id)'
    );
    $stmt->execute([':nombre' => $nombre, ':correo' => $correo, ':id' => $idUsuario]);

    salida('  · La cuenta ' . $cedula . ' ya existía: contraseña actualizada.');
} else {
    $stmt = $app->prepare(
        'INSERT INTO persona (nombre, correo, ci_usuario) VALUES (:nombre, :correo, :ci)'
    );
    $stmt->execute([':nombre' => $nombre, ':correo' => $correo, ':ci' => $cedula]);
    $idPersona = (int) $app->lastInsertId();

    $stmt = $app->prepare(
        'INSERT INTO usuario (id_persona, ci_usuario, contrasena, id_rol, estado, password_changed_at)
         VALUES (:persona, :ci, :hash, :rol, \'activo\', NOW())'
    );
    $stmt->execute([':persona' => $idPersona, ':ci' => $cedula, ':hash' => $hash, ':rol' => $idRol]);
    $idUsuario = (int) $app->lastInsertId();

    salida('  · Cuenta creada: ' . $cedula);
}

// Sincroniza la pivote de roles múltiples (la usa el login al entrar).
$stmt = $app->prepare(
    'INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (:usuario, :rol)
     ON CONFLICT DO NOTHING'
);
$stmt->execute([':usuario' => $idUsuario, ':rol' => $idRol]);

$app->prepare('UPDATE usuario SET ultimo_acceso = NULL WHERE id_usuario = :id')
    ->execute([':id' => $idUsuario]);

// ---------------------------------------------------------------------------
// Resumen
// ---------------------------------------------------------------------------

titulo('Instalación completada');

salida('  Credenciales de acceso:');
salida('    Usuario : ' . $cedula);
salida('    Clave   : ' . $claveDemo);
salida('    Rol     : ' . $rolNombre);

if ($generada) {
    salida();
    salida('  ★ GUARDA ESTA CLAVE: solo se muestra esta vez.');
}

salida();
salida('  Siguiente paso:');
salida('    php -S localhost:8000 router.php');
salida('    y abre http://localhost:8000/public/logeo/login.php');
salida();
