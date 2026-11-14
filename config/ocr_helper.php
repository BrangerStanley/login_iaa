<?php
// config/ocr_helper.php
// Pipeline de OCR de cedulasVenezuela con Tesseract, reutilizable.
// Lo usan:
//   - controllers/validacionController.php (lee la foto al validar la cedula
//     y prellena los datos del formulario de creacion de cuenta)
require_once __DIR__ . '/../models/CedulaOcr.php';

/**
 * Ruta del binario de Tesseract, o null si no esta instalado.
 * Se puede forzar con la variable de entorno TESSERACT_PATH.
 */
function tesseract_binario(): ?string
{
    static $cacheado = false;
    static $binario = null;

    if ($cacheado) return $binario;
    $cacheado = true;

    $candidatos = [
        getenv('TESSERACT_PATH') ?: '',
        'C:\Program Files\Tesseract-OCR\tesseract.exe',
        'C:\Program Files (x86)\Tesseract-OCR\tesseract.exe',
        '/usr/bin/tesseract',
        '/usr/local/bin/tesseract',
    ];

    foreach ($candidatos as $ruta) {
        if ($ruta !== '' && @is_file($ruta)) {
            $binario = $ruta;
            break;
        }
    }
    return $binario;
}

/**
 * Idiomas de Tesseract disponibles en la maquina.
 * Se cachea en disco porque `tesseract --list-langs` es lento: se consulta
 * una vez al dia, no en cada lectura.
 */
function tesseract_idiomas(string $binario): string
{
    static $cache = [];

    if (isset($cache[$binario])) {
        return $cache[$binario];
    }

    $archivoCache = sys_get_temp_dir() . '/iaa_tesseract_langs.cache';
    if (is_file($archivoCache) && (time() - filemtime($archivoCache)) < 86400) {
        $guardado = trim((string)file_get_contents($archivoCache));
        if ($guardado !== '') {
            return $cache[$binario] = $guardado;
        }
    }

    $lineas = [];
    @exec(escapeshellarg($binario) . ' --list-langs 2>&1', $lineas);

    $idiomas = [];
    foreach (array_slice($lineas, 1) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || mb_strpos($linea, 'List of') === 0) continue;
        $idiomas[] = $linea;
    }

    $eleccion = 'eng';
    if (in_array('spa', $idiomas, true)) {
        $eleccion = in_array('eng', $idiomas, true) ? 'spa+eng' : 'spa';
    } elseif (in_array('spa_old', $idiomas, true)) {
        $eleccion = in_array('eng', $idiomas, true) ? 'spa_old+eng' : 'spa_old';
    }

    @file_put_contents($archivoCache, $eleccion);
    return $cache[$binario] = $eleccion;
}

/**
 * Prepara la imagen para Tesseract y devuelve la ruta de un PNG temporal
 * (la version en color, que es la que mejor lee los digitos de la cedula).
 * Devuelve null si no se pudo preparar.
 */
function ocr_preparar_imagen(string $rutaImagen): ?string
{
    $variantes = ocr_preparar_variantes($rutaImagen);
    $color = $variantes['color'] ?? null;
    $gris = $variantes['gris'] ?? null;

    if ($color !== null) {
        if ($gris !== null) @unlink($gris);
        return $color;
    }

    return $gris;
}

/**
 * Prepara DOS versiones de la imagen para Tesseract: una en color y otra en
 * escala de grises con contraste. Segun el documento y la luz, Tesseract lee
 * mejor una u otra (en las miniaturas de cedula el color conserva los
 * digitos que el contraste aggressive destruye).
 *
 * Devuelve un array asociativo ['color' => ruta, 'gris' => ruta] con las
 * rutas de los PNG temporales (null si no se pudo preparar cada una).
 */
function ocr_preparar_variantes(string $rutaImagen): array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
        return ['color' => null, 'gris' => null];
    }

    $crudo = @file_get_contents($rutaImagen);
    if ($crudo === false || $crudo === '') return ['color' => null, 'gris' => null];

    $origen = @imagecreatefromstring($crudo);
    if ($origen === false) return ['color' => null, 'gris' => null];

    $anchoOrig = imagesx($origen);
    $altoOrig = imagesy($origen);
    $mayorOrig = max($anchoOrig, $altoOrig);

    // Presupuesto de pixeles: una imagen enorme no se procesa (consumo de
    // memoria y de CPU desproporcionado en el servidor).
    if ($anchoOrig * $altoOrig > ocr_maximo_pixeles()) {
        imagedestroy($origen);
        return ['color' => null, 'gris' => null];
    }

    // Se busca un lado mayor de 1600 px (suficiente para el texto de una
    // cedula) sin pasar de 2400 px (a partir de ahi no gana nada y se
    // multiplica el tiempo de Tesseract).
    if ($mayorOrig > 2400) {
        $escala = 2400 / $mayorOrig;
    } elseif ($mayorOrig < 1600) {
        $escala = min(4.0, 1600 / max(1, $mayorOrig));
    } else {
        $escala = 1.0;
    }

    $ancho = max(1, (int)round($anchoOrig * $escala));
    $alto = max(1, (int)round($altoOrig * $escala));

    $color = imagecreatetruecolor($ancho, $alto);
    imagecopyresampled($color, $origen, 0, 0, 0, 0, $ancho, $alto, $anchoOrig, $altoOrig);
    imagedestroy($origen);

    $gris = imagecreatetruecolor($ancho, $alto);
    imagecopyresampled($gris, $color, 0, 0, 0, 0, $ancho, $alto, $ancho, $alto);
    if (function_exists('imagefilter')) {
        @imagefilter($gris, IMG_FILTER_GRAYSCALE);
        @imagefilter($gris, IMG_FILTER_CONTRAST, -12);
    }

    $salida = [];
    foreach (['color' => $color, 'gris' => $gris] as $clave => $imagen) {
        $tmpFile = ocr_dir_temporal() . '/ocr_' . $clave . '_' . bin2hex(random_bytes(8)) . '.png';
        $salida[$clave] = @imagepng($imagen, $tmpFile) ? $tmpFile : null;
        imagedestroy($imagen);
    }

    return $salida;
}

/**
 * Ejecuta Tesseract sobre un archivo ya preparado con un modo de
 * segmentacion concreto y devuelve el texto leido ('' si falla).
 *
 * El proceso se lanza SIN shell (array de argumentos) y con tiempo maximo:
 * una imagen patologica (por ejemplo 8000x8000 px) hacia que Tesseract se
 * pase minutos consumiendo CPU, y eso es un vector de denegacion de
 * servicio muy facil de disparar desde el formulario de subida.
 */
function ocr_ejecutar_tesseract(string $binario, string $imagen, int $psm = 3, int $timeout = 25): string
{
    $texto = '';
    try {
        $base = ocr_dir_temporal() . '/ocr_output_' . bin2hex(random_bytes(4));

        $codigo = ocr_ejecutar_proceso([
            $binario,
            $imagen,
            $base,
            '-l', tesseract_idiomas($binario),
            '--psm', (string)(int)$psm,
        ], $timeout);

        if ($codigo === 0 && is_file($base . '.txt')) {
            $texto = trim((string)file_get_contents($base . '.txt'));
        }
        @unlink($base . '.txt');
    } catch (Throwable $e) {
        error_log('ocr_ejecutar_tesseract: ' . $e->getMessage());
    }

    return $texto;
}

/**
 * Ejecuta un proceso sin shell y con tiempo maximo. Devuelve el codigo de
 * salida (-1 si no se pudo ejecutar o se detuvo por tiempo).
 */
function ocr_ejecutar_proceso(array $comando, int $timeout = 25): int
{
    $descriptores = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $opciones = ['bypass_shell' => true];
    $proceso = @proc_open($comando, $descriptores, $pipes, null, null, $opciones);
    if (!is_resource($proceso)) return -1;

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $fin = microtime(true) + max(1, $timeout);
    $codigo = -1;

    while (true) {
        $estado = proc_get_status($proceso);
        $salida = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);

        if (!$estado['running']) {
            $codigo = (int)$estado['exitcode'];
            break;
        }
        if (microtime(true) > $fin) {
            // Se corta el proceso: sin esto, una imagen dificil puede dejar
            // el servidor ocupado durante minutos.
            @proc_terminate($proceso, 9);
            error_log('OCR: proceso detenido por exceeded de tiempo (' . $timeout . 's)');
            break;
        }
        usleep(50000);
    }

    @fclose($pipes[1]);
    @fclose($pipes[2]);
    @proc_close($proceso);

    return $codigo;
}

/**
 * Carpeta temporal exclusiva del OCR, fuera de la carpeta publica y con
 * permisos restringidos. Contiene imagenes de cedula (datos personales), asi
 * que no puede quedar en un directorio compartido por todos los usuarios del
 * servidor.
 */
function ocr_dir_temporal(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $base = sys_get_temp_dir() . '/iaa_ocr';
    if (!is_dir($base)) {
        @mkdir($base, 0700, true);
    }
    if (!is_dir($base) || !is_writable($base)) {
        $base = sys_get_temp_dir();
    }

    $dir = rtrim($base, '/\\');
    return $dir;
}

/**
 * Presupuesto de pixeles: por encima de este valor la imagen no se procesa
 * (evita que una imagen de 8000x8000 px se convierta en varios cientos de
 * MB de memoria en el servidor).
 */
function ocr_maximo_pixeles(): int
{
    return 25 * 1000 * 1000;
}

/** Tiempo maximo (segundos) de una pasada de Tesseract. */
function ocr_tiempo_maximo_pasada(): int
{
    return 25;
}

/** Tiempo maximo (segundos) de toda la operacion de OCR. */
function ocr_tiempo_maximo_total(): int
{
    return 60;
}

/**
 * Genera varios textos candidatos leyendo la imagen con distintas
 * combinaciones de preprocesado y de modo de segmentacion.
 *
 * Por que varias pasadas: una cedula es un documento de dos columnas con
 * texto de cuerpo muy pequeno. El modo por defecto (psm 3) se pierde cuando
 * la imagen es una miniatura, mientras que psm 6 (bloque uniforme) o psm 11
 * (texto disperso) si la leen; y en documentos limpios el modo por defecto
 * es el mejor. Con una sola pasada se perdia mas de la mitad de las
 * imagenes de prueba.
 *
 * El orden de las pasadas es el de mayor probabilidad de acierto y el
 * proceso se detiene en cuanto una pasada entrega numero de cedula y un
 * nombre: no se multiplica el tiempo de espera del usuario sin necesidad.
 */
function ocr_textos_candidatos(string $rutaImagen): array
{
    $binario = tesseract_binario();
    if ($binario === null) return [];

    $variantes = ocr_preparar_variantes($rutaImagen);
    $temporales = array_filter($variantes);

    $textos = [];
    try {
        $pasadas = [
            ['color', 3],
            ['color', 6],
            ['color', 11],
            ['gris',  3],
            ['gris',  6],
            ['gris',  11],
        ];

        // Tiempo total maximo de la operacion: el usuario no puede esperar
        // indefinidamente y el servidor tampoco puede quedar ocupado.
        $limite = microtime(true) + (float)ocr_tiempo_maximo_total();

        foreach ($pasadas as $pasada) {
            if (microtime(true) > $limite) break;

            $imagen = $variantes[$pasada[0]] ?? null;
            if ($imagen === null) continue;

            $texto = ocr_ejecutar_tesseract($binario, $imagen, $pasada[1], ocr_tiempo_maximo_pasada());
            if ($texto === '') continue;

            $textos[] = ['texto' => $texto, 'variante' => $pasada[0], 'psm' => $pasada[1]];

            // Si esta pasada ya lee el numero y el nombre, no hace falta
            // seguir probando: el resto solo aportaria ruido.
            if (CedulaOcr::extraerNumeroCedula($texto) !== ''
                && (CedulaOcr::parsearApellidos($texto) !== '' || CedulaOcr::parsearNombres($texto) !== '')) {
                break;
            }
        }
    } finally {
        foreach ($temporales as $temporal) {
            @unlink($temporal);
        }
    }

    return $textos;
}

/**
 * Lee una imagen de cedula con Tesseract y devuelve el texto crudo ('' si no
 * se pudo leer o no hay Tesseract instalado). Si hay varias lecturas, se
 * devuelve la mejor: la que tiene numero de cedula y mas texto util.
 */
function ocr_texto_imagen(string $rutaImagen): string
{
    $candidatos = ocr_textos_candidatos($rutaImagen);
    if (!$candidatos) return '';

    $mejor = '';
    $mejorPuntaje = -1;
    foreach ($candidatos as $candidato) {
        $texto = $candidato['texto'];
        $puntaje = mb_strlen($texto);
        if (CedulaOcr::extraerNumeroCedula($texto) !== '') $puntaje += 1000;
        if (CedulaOcr::esCedulaVenezolana($texto)) $puntaje += 500;
        if ($puntaje > $mejorPuntaje) {
            $mejorPuntaje = $puntaje;
            $mejor = $texto;
        }
    }

    return $mejor;
}

/**
 * Atajo: imagen -> campos de la cedula ya interpretados.
 * Devuelve null si no se pudo leer nada.
 */
function ocr_campos_cedula(string $rutaImagen): ?array
{
    $candidatos = ocr_textos_candidatos($rutaImagen);
    if (!$candidatos) return null;

    $textos = array_column($candidatos, 'texto');
    $datos = CedulaOcr::analizarMultiples($textos);
    if ($datos === null) return null;

    $datos['texto'] = $datos['texto'] ?? '';
    $datos['lecturas'] = count($textos);
    return $datos;
}