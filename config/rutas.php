<?php
// config/rutas.php
// Definiciones globales de rutas del proyecto (IAA - Versión Ligera).
//
// En el sistema original BASE_URL está fijado a mano ('/iaa_iuty'). Aquí se
// calcula a partir de la petición en curso para que el repositorio funcione
// igual en:
//   - el servidor embebido de PHP   → http://localhost:8000
//   - XAMPP sirviendo htdocs        → http://localhost/para%20github/login_iaa
//   - cualquier subcarpeta de otro proyecto
//
// Fórmula: se descubre el directorio del sistema de archivos que corresponde
// a la raíz web (SCRIPT_FILENAME - SCRIPT_NAME) y se resta de ROOT_PATH.

if (!defined('BASE_URL')) {
    $base = '';

    $archivo = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $rutaWeb = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($archivo !== '' && $rutaWeb !== '' && str_ends_with($archivo, $rutaWeb)) {
        $raizWeb = substr($archivo, 0, strlen($archivo) - strlen($rutaWeb));
        $raizWeb = rtrim(str_replace('\\', '/', $raizWeb), '/');
        $raizProyecto = str_replace('\\', '/', dirname(__DIR__));

        if ($raizWeb !== '' && str_starts_with($raizProyecto, $raizWeb)) {
            $base = substr($raizProyecto, strlen($raizWeb));
        }
    }

    define('BASE_URL', $base);
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

// La capa de compatibilidad (config/Database.php) reproduce la semántica de
// mysqli: devuelve false en error en vez de lanzar excepciones. Ya no se usa
// la extensión mysqli, así que no hace falta configurar su modo de reporte.
