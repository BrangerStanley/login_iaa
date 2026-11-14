<?php
// config/directorios.php
// Ubicación de las carpetas que guardan datos sensibles.
//
// Regla de seguridad: la evidencia fotográfica de cédulas, los registros y
// los respaldos NO deberían vivir dentro del webroot. Si están dentro,
// cualquiera que adivine la URL puede descargarlos (el .htaccess los
// bloquea, pero es una segunda barrera, no la primera).
//
// Por eso la ubicacion es configurable por entorno:
//
//   IAA_DIR_EVIDENCIAS=/var/lib/iaa/evidencias
//   IAA_DIR_LOGS=/var/log/iaa
//   IAA_DIR_BACKUPS=/var/backups/iaa
//
// Si no se definen, se usa la carpeta del proyecto (comportamiento actual)
// y se refuerza con un .htaccess de denegacion total.
require_once __DIR__ . '/env.php';

if (!function_exists('iaa_dir_raiz')) {

    /** Carpeta raiz del proyecto. */
    function iaa_dir_raiz(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Resuelve una carpeta sensible: si el entorno define una ruta
     * absoluta se usa esa; si no, la carpeta por defecto dentro del proyecto.
     */
    function iaa_dir_sensible(string $claveEnv, string $porDefecto): string
    {
        $ruta = trim((string)iaa_env_valor($claveEnv, ''));
        if ($ruta === '') {
            $ruta = iaa_dir_raiz() . DIRECTORY_SEPARATOR . trim($porDefecto, '/\\');
        } else {
            // En Linux una ruta relativa respecto al proyecto tambien vale.
            if (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $ruta)) {
                $ruta = iaa_dir_raiz() . DIRECTORY_SEPARATOR . ltrim($ruta, '/\\');
            }
        }

        return rtrim($ruta, '/\\');
    }

    /**
     * Prepara una carpeta sensible: la crea si no existe con permisos
     * restringidos y, si queda dentro del webroot, escribe un .htaccess que
     * deny toda el acceso HTTP.
     */
    function iaa_preparar_dir_sensible(string $ruta): bool
    {
        if (!is_dir($ruta)) {
            if (!@mkdir($ruta, 0700, true) && !is_dir($ruta)) return false;
        }
        @chmod($ruta, 0700);

        // Si esta dentro del proyecto (y por tanto del webroot), se blinda
        // con un .htaccess: sin ejecucion y sin acceso directo.
        $raiz = iaa_dir_raiz();
        if (strpos($ruta, $raiz) === 0) {
            $htaccess = $ruta . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($htaccess)) {
                @file_put_contents($htaccess, "Options -Indexes\nRequire all denied\n");
                @chmod($htaccess, 0600);
            }
        }

        return true;
    }

    /** Carpeta de la evidencia fotografica de cedulas (fuera del webroot si se puede). */
    function iaa_dir_evidencias(): string
    {
        static $ruta = null;
        if ($ruta === null) {
            $ruta = iaa_dir_sensible('IAA_DIR_EVIDENCIAS', 'uploads/cedulas');
            iaa_preparar_dir_sensible($ruta);
        }
        return $ruta;
    }

    /** Carpeta de registros del sistema. */
    function iaa_dir_logs(): string
    {
        static $ruta = null;
        if ($ruta === null) {
            $ruta = iaa_dir_sensible('IAA_DIR_LOGS', 'logs');
            iaa_preparar_dir_sensible($ruta);
        }
        return $ruta;
    }

    /** Carpeta de respaldos de base de datos. */
    function iaa_dir_backups(): string
    {
        static $ruta = null;
        if ($ruta === null) {
            $ruta = iaa_dir_sensible('IAA_DIR_BACKUPS', 'backups');
            iaa_preparar_dir_sensible($ruta);
        }
        return $ruta;
    }

    /** ¿La carpeta esta dentro del webroot? (solo informativo, para avisos) */
    function iaa_dir_dentro_del_webroot(string $ruta): bool
    {
        $raiz = iaa_dir_raiz();
        return strpos($ruta, $raiz) === 0;
    }

    /**
     * Carpeta raiz de los documentos clinicos subidos por los usuarios
     * (expedientes, actas, copias de cedulas, partidas de nacimiento).
     *
     * H-01: estos ficheros NO pueden quedar dentro del webroot. Servidos por
     * Apache, cualquiera que supusiera (o enumerara) la URL se los descargaba
     * sin autenticarse, y el nombre del archivo lleva la cedula.
     */
    function iaa_dir_documentos(): string
    {
        static $ruta = null;
        if ($ruta === null) {
            $ruta = iaa_dir_sensible('IAA_DIR_DOCUMENTOS', 'uploads');
            iaa_preparar_dir_sensible($ruta);
        }
        return $ruta;
    }

    /**
     * Resuelve la ruta fisica de un documento a partir de la ruta relativa
     * guardada en la base de datos ("uploads/afiliaciones/expediente.pdf").
     *
     * Devuelve null si la ruta no es valida, si intenta salir de la carpeta de
     * documentos (path traversal) o si el fichero no existe.
     */
    function iaa_ruta_fisica_documento(string $rutaRelativa): ?string
    {
        $relativa = str_replace('\\', '/', trim($rutaRelativa));
        if ($relativa === '' || strpos($relativa, '..') !== false) {
            return null;
        }
        // Un byte nulo hace que realpath() lance un ValueError en PHP 8: se
        // descarta antes de tocar el sistema de ficheros.
        if (strpos($relativa, "\0") !== false) {
            return null;
        }
        // Solo se admiten ficheros de una subcarpeta de uploads/
        if (!preg_match('#^uploads/([A-Za-z0-9_-]+)/(.+)$#', $relativa, $m)) {
            return null;
        }
        // El nombre no puede contener separadores de directorio
        $nombre = $m[2];
        if (strpos($nombre, '/') !== false) {
            return null;
        }

        $raiz = iaa_dir_documentos();
        $fichero = $raiz . DIRECTORY_SEPARATOR . $m[1] . DIRECTORY_SEPARATOR . $nombre;

        $realRaiz = realpath($raiz);
        $realFichero = realpath($fichero);
        if ($realRaiz === false || $realFichero === false) {
            return null;
        }
        // Confinamiento: el fichero tiene que estar dentro de la raiz
        if (strpos($realFichero, $realRaiz . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        if (!is_file($realFichero)) {
            return null;
        }
        return $realFichero;
    }

    /**
     * Resuelve la ruta fisica de cualquier documento del sistema: primero la
     * carpeta de documentos subidos y, si no esta ahi, la de la evidencia
     * fotografica (que tambien esta fuera del webroot).
     */
    function iaa_resolver_ruta_documental(string $rutaRelativa): ?string
    {
        $ruta = iaa_ruta_fisica_documento($rutaRelativa);
        if ($ruta !== null) {
            return $ruta;
        }

        $relativa = str_replace('\\', '/', trim($rutaRelativa));
        if ($relativa === '' || strpos($relativa, '..') !== false) {
            return null;
        }
        if (strpos($relativa, "\0") !== false) {
            return null;
        }
        $base = realpath(iaa_dir_evidencias());
        if ($base === false) {
            return null;
        }
        $candidato = realpath($base . DIRECTORY_SEPARATOR . basename($relativa));
        if ($candidato === false || !is_file($candidato)) {
            return null;
        }
        if (strpos($candidato, $base . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $candidato;
    }
}
