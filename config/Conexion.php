<?php
declare(strict_types=1);

/**
 * ============================================================================
 * config/Conexion.php — Conexión a PostgreSQL para todo el sistema IAA
 * ============================================================================
 *
 * Entrega en la variable global `$conexion` un objeto IaaConnection, que expone
 * la misma API que mysqli (query, prepare, bind_param, get_result, insert_id,
 * num_rows, …) pero ejecuta PostgreSQL mediante PDO nativo. Ver config/Database.php
 * para el detalle de la capa de compatibilidad.
 *
 * SEGURIDAD
 * ---------
 *  - Las credenciales provienen SOLO de variables de entorno (config/env.php).
 *    Nunca se escriben en el código.
 *  - Se rechaza arrancar con un rol administrativo (postgres/root). La
 *    aplicación usa `iaa_app_user`, que no puede alterar el esquema.
 *  - El mensaje de error mostrado al cliente es genérico: no revela host,
 *    usuario, base ni el error real del motor. El detalle va al log del
 *    servidor.
 *
 * ENTORNOS
 * --------
 *  - Windows / XAMPP (desarrollo): sslmode=prefer (el servidor local no
 *    ofrece TLS; exigirlo impediría arrancar).
 *  - Linux (producción): sslmode=verify-full por omisión. Debe existir el
 *    certificado de la CA en el sistema; si no, la conexión falla a propósito
 *    en lugar de degradar a texto plano.
 * ============================================================================
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/Database.php';

$dbHost = iaa_env_valor('IAA_DB_HOST', '127.0.0.1');
$dbPort = (int)iaa_env_valor('IAA_DB_PORT', '5432');
$dbUser = iaa_env_valor('IAA_DB_USER', 'iaa_app_user');
$dbPass = iaa_env_valor('IAA_DB_PASS', '');
$dbName = iaa_env_valor('IAA_DB_NAME', 'fpaiaa');

if ($dbPort <= 0) {
    $dbPort = 5432;
}

// ---------------------------------------------------------------------------
// Control 1: la aplicación no debe operar con un rol administrativo.
// ---------------------------------------------------------------------------
$rolesProhibidos = ['postgres', 'root', 'admin', 'administrator', 'iaa_admin'];
$permitirAdmin  = (getenv('IAA_ALLOW_ADMIN_DB') === '1');

if (!$permitirAdmin && in_array(strtolower($dbUser), $rolesProhibidos, true)) {
    error_log(
        'Conexion.php: RECHAZADA la conexión con el rol administrativo "' . $dbUser . '". '
        . 'La aplicación debe usar iaa_app_user (mínimo privilegio). '
        . 'Si es una operación administrativa deliberada, defina IAA_ALLOW_ADMIN_DB=1.'
    );
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode([
        'success' => false,
        'message' => 'Configuración de base de datos no válida.',
    ]));
}

// ---------------------------------------------------------------------------
// Control 2: la contraseña es obligatoria. Un rol sin contraseña en PostgreSQL
// solo puede autenticarse por socket local con trust, lo que no debe ocurrir.
//
// Si el proceso no expone IAA_DB_PASS, se reintenta una última vez con el
// respaldo en disco (.env): un fallo transitorio de lectura no debe dejar al
// sistema sin credenciales.
// ---------------------------------------------------------------------------
if ($dbPass === '') {
    $dbPass = iaa_env_valor('IAA_DB_PASS', '');
}
if ($dbPass === '') {
    error_log('Conexion.php: IAA_DB_PASS está vacío. Se exige contraseña para la aplicación.');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode([
        'success' => false,
        'message' => 'Error de configuración del servidor.',
    ]));
}

// ---------------------------------------------------------------------------
// Control 3: TLS según el entorno.
// ---------------------------------------------------------------------------
$sslmode = iaa_env_valor('IAA_DB_SSLMODE', '');
if ($sslmode === '') {
    $sslmode = (PHP_OS_FAMILY === 'Windows') ? 'prefer' : 'verify-full';
}

try {
    $conexion = new IaaConnection([
        'host'    => $dbHost,
        'port'    => $dbPort,
        'db'      => $dbName,
        'user'    => $dbUser,
        'pass'    => $dbPass,
        'sslmode' => $sslmode,
    ]);
} catch (Throwable $e) {
    // El detalle va al log del servidor; al cliente solo un mensaje genérico.
    error_log('Conexion.php: fallo de conexión a PostgreSQL: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode([
        'success' => false,
        'message' => 'Error de conexión con el servidor.',
    ]));
}
