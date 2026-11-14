<?php
declare(strict_types=1);

/**
 * scripts/lint.php — comprueba la sintaxis de todos los .php del proyecto.
 *
 *   php scripts/lint.php
 *
 * Sale con código 1 si algún archivo no pasa, para poder usarlo en CI.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

$raiz = dirname(__DIR__);
$excluidos = ['vendor', '.git', 'node_modules', 'logs', 'backups', 'uploads', 'cedulas'];
$errores = [];
$total = 0;

$iterador = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterador as $archivo) {
    if (!$archivo->isFile() || strtolower($archivo->getExtension()) !== 'php') {
        continue;
    }

    $ruta = $archivo->getPathname();
    $relativa = str_replace('\\', '/', substr($ruta, strlen($raiz) + 1));

    $omite = false;
    foreach ($excluidos as $carpeta) {
        if (str_starts_with($relativa, $carpeta . '/')) {
            $omite = true;
            break;
        }
    }
    if ($omite) {
        continue;
    }

    $total++;
    $salida = [];
    $codigo = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($ruta) . ' 2>&1', $salida, $codigo);

    if ($codigo !== 0) {
        $errores[] = $relativa . ' → ' . implode(' ', $salida);
    }
}

echo 'Archivos revisados: ' . $total . PHP_EOL;

if ($errores === []) {
    echo "Sintaxis correcta en todos los archivos.\n";
    exit(0);
}

echo 'ERRORES (' . count($errores) . '):' . PHP_EOL;
foreach ($errores as $error) {
    echo '  - ' . $error . PHP_EOL;
}
exit(1);
