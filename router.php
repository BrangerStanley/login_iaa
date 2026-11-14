<?php
/**
 * router.php — servidor embebido de PHP (sustituye a .htaccess en local).
 *
 *   php -S localhost:8000 router.php
 *
 * Por defecto `php -S` sirve TODO lo que haya en la carpeta, incluido .env y
 * database/. Este router reproduce las mismas barreras que el .htaccess de
 * Apache para que el comando de arriba sea seguro:
 *
 *   · Bloquea /.env, /config/, /models/, /database/, /scripts/, /storage/ y
 *     cualquier archivo oculto.
 *   · Sirve los ficheros reales devolviendo `false` (el servidor embebido los
 *     atiende con $_SERVER idéntico al de Apache: SCRIPT_NAME / SCRIPT_FILENAME
 *     correctos, que es lo que usa config/rutas.php para calcular BASE_URL).
 *   · Redirige la raíz a /public/index.php.
 */

$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($uri === '' || $uri === false) {
    $uri = '/';
}

// ---------------------------------------------------------------------------
// 1. Rutas que nunca deben servirse por HTTP
// ---------------------------------------------------------------------------
$bloqueadas = [
    '/config/' => true,
    '/models/' => true,
    '/database/' => true,
    '/scripts/' => true,
    '/storage/' => true,
];

$primerSegmento = explode('/', trim($uri, '/'))[0] ?? '';

if (isset($bloqueadas[$uri]) || isset($bloqueadas[$uri . '/']) || isset($bloqueadas['/' . $primerSegmento . '/'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    exit;
}

// Archivos ocultos (.env, .gitignore, .htaccess, .git/...).
if ($primerSegmento !== '' && $primerSegmento[0] === '.') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    exit;
}

// ---------------------------------------------------------------------------
// 2. Raíz → portal público
// ---------------------------------------------------------------------------
if ($uri === '/') {
    header('Location: /public/index.php');
    exit;
}

// ---------------------------------------------------------------------------
// 3. Ficheros reales: que los sirva el propio servidor embebido
// ---------------------------------------------------------------------------
$destino = __DIR__ . $uri;

if (is_file($destino)) {
    // Nunca servir binarios "textuales" con extensión peligrosa.
    if (preg_match('/\.(env|sql|md|bak|orig|log|ini|neon|lock|zip)$/i', $uri) === 1) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden';
        exit;
    }

    return false;
}

// ---------------------------------------------------------------------------
// 4. Todo lo demás → 404 (sin revelar si el recurso existía)
// ---------------------------------------------------------------------------
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 Not Found';
