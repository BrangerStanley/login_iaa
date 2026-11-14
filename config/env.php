<?php
// config/env.php
// Carga las variables de entorno del proyecto sin dependencias externas.
//
// Orden de prioridad (el primero que aporte un valor gana):
//   1) Variable ya definida en el proceso (Apache SetEnv, PHP-FPM env[...],
//      variables del sistema). Nunca se sobreescribe.
//   2) Archivo `.env` en la raíz del proyecto, o `config/.env`.
//
// Existe porque config/Conexion.php, config/acceso_pagina.php,
// config/mail_config.php, config/seguridad.php y models/PreguntaSeguridadModel.php
// leen credenciales con getenv('IAA_DB_*') / getenv('IAA_SMTP_*') y, si no hay
// nada definido, caen a valores por defecto que solo valen en Windows/XAMPP
// (root sin contraseña). Este archivo los inyecta con putenv() para que
// getenv() los vea sin modificar ningún otro fichero.
//
// Es idempotente y seguro de incluir varias veces.

if (!function_exists('iaa_claves_entorno')) {
    /**
     * Claves que el sistema sabe leer. Si alguna nueva se añade en el futuro,
     * basta con agregarla aquí.
     */
    function iaa_claves_entorno(): array
    {
        return [
            // Base de datos (config/Conexion.php, config/acceso_pagina.php)
            'IAA_DB_HOST',
            'IAA_DB_PORT',
            'IAA_DB_USER',
            'IAA_DB_PASS',
            'IAA_DB_NAME',
            'IAA_DB_SSLMODE',
            // Rol administrativo para respaldos (pg_dump), no para la app.
            'IAA_DB_ADMIN_USER',
            'IAA_DB_ADMIN_PASS',
            // SMTP (config/mail_config.php)
            'IAA_SMTP_HOST',
            'IAA_SMTP_PORT',
            'IAA_SMTP_USER',
            'IAA_SMTP_PASS',
            'IAA_SMTP_FROM',
            'IAA_SMTP_ENC',
            // Seguridad
            'IAA_CSRF_HMAC_SECRET',
            'IAA_PREGUNTA_SALT',
            // Carpetas de datos sensibles (config/directorios.php). Deben poder
            // apuntar fuera del webroot: evidencia de cedulas, registros,
            // respaldos y documentos clinicos subidos por los usuarios.
            'IAA_DIR_EVIDENCIAS',
            'IAA_DIR_LOGS',
            'IAA_DIR_BACKUPS',
            'IAA_DIR_DOCUMENTOS',
            // Clave de las cuentas de prueba que crean los scripts de tools/.
            // Nunca debe aparecer escrita en el codigo (H-13).
            'IAA_CLAVE_PRUEBA',
            // Clave de cifrado de los respaldos (database/respaldo_pg.php).
            // Si no esta, el script usa .clave_respaldo (que no se versiona).
            'IAA_BACKUP_CLAVE',
            // Forzar HTTPS en produccion (redireccion 301 + HSTS).
            'IAA_FORZAR_HTTPS',
            // Binario opcional del OCR (Tesseract)
            'TESSERACT_PATH',
        ];
    }

    /**
     * Rutas candidatas del archivo de entorno.
     * La raíz del proyecto es dirname(__DIR__) porque este archivo vive en config/.
     */
    function iaa_rutas_env(): array
    {
        $raiz = dirname(__DIR__);
        return [
            $raiz . DIRECTORY_SEPARATOR . '.env',
            __DIR__ . DIRECTORY_SEPARATOR . '.env',
        ];
    }

    /**
     * Parser mínimo y tolerante de un archivo tipo .env.
     * Soporta: KEY=value, export KEY=value, comillas simples o dobles,
     * líneas vacías y comentarios con # (incluido # dentro de un valor ya
     * abierto con comillas).
     *
     * @return array<string,string>
     */
    function iaa_parsear_env(string $ruta): array
    {
        $salida = [];
        if (!is_file($ruta) || !is_readable($ruta)) {
            return $salida;
        }
        $texto = (string)@file_get_contents($ruta);
        if ($texto === '') {
            return $salida;
        }
        // Normaliza finales de línea (los .env pueden venir con CRLF desde Windows).
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        foreach (explode("\n", $texto) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#') {
                continue;
            }
            if (strncmp($linea, 'export ', 7) === 0) {
                $linea = trim(substr($linea, 7));
            }
            $igual = strpos($linea, '=');
            if ($igual === false) {
                continue;
            }
            $clave = trim(substr($linea, 0, $igual));
            $valor = trim(substr($linea, $igual + 1));
            // Solo claves alfanuméricas con guion bajo bajo: evita basura.
            if ($clave === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $clave)) {
                continue;
            }
            // Quita un comentario final no contenido entre comillas.
            if ($valor !== '' && $valor[0] !== '"' && $valor[0] !== "'") {
                $c = strpos($valor, ' #');
                if ($c !== false) {
                    $valor = rtrim(substr($valor, 0, $c));
                }
            }
            $long = strlen($valor);
            if ($long >= 2) {
                $primero = $valor[0];
                $ultimo  = $valor[$long - 1];
                if (($primero === '"' && $ultimo === '"') || ($primero === "'" && $ultimo === "'")) {
                    $valor = substr($valor, 1, -1);
                }
            }
            $salida[$clave] = $valor;
        }
        return $salida;
    }

    /**
     * Inyecta en el entorno del proceso las claves que aún no estén definidas.
     * Nunca pisa un valor ya presente (respeta SetEnv / variables del SO).
     */
    /**
     * Lee el archivo .env y devuelve el mapa clave=>valor.
     *
     * El resultado se cachea en un static: el archivo se lee una sola vez por
     * petición, pero si la primera lectura falla (archivo bloqueado por otro
     * proceso, Antivirus, etc.) se reintenta en la siguiente llamada en lugar
     * de dejar al proceso sin credenciales.
     */
    function iaa_leer_env_cache(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        foreach (iaa_rutas_env() as $ruta) {
            if (is_file($ruta) && is_readable($ruta)) {
                $cache = iaa_parsear_env($ruta);
                if ($cache !== []) {
                    break;
                }
            }
        }
        return $cache;
    }

    function iaa_cargar_entorno(): void
    {
        $leidas = iaa_leer_env_cache();

        $soportaPutenv = function_exists('putenv');
        foreach ($leidas as $clave => $valor) {
            if (!in_array($clave, iaa_claves_entorno(), true)) {
                continue; // Ignora claves ajenas al sistema.
            }
            // Solo se respeta una variable ya definida si TIENE VALOR. Una
            // variable presente pero vacía se considera no definida: de otro
            // modo un putenv() con valor vacío quedaría "pegado" para todo el
            // proceso y la aplicación moriría sin credenciales.
            $actual = getenv($clave);
            if ($actual !== false && trim((string)$actual) !== '') {
                continue; // Ya definida fuera del .env: no se sobreescribe.
            }
            if ($valor === '') {
                continue; // El .env no aporta un valor utilizable.
            }
            if ($soportaPutenv) {
                @putenv($clave . '=' . $valor);
            }
            $_ENV[$clave] = $valor;
            $_SERVER[$clave] = $valor;
        }
    }

    /**
     * Resuelve el valor de una variable de entorno con la siguiente
     * precedencia:
     *   1) Valor definido en el proceso (Apache SetEnv, PHP-FPM, SO).
     *   2) Valor del archivo .env (respaldo en disco).
     *   3) Valor por defecto indicado por el llamante.
     *
     * A diferencia de getenv() a secas, nunca devuelve '' por un fallo
     * transitorio de lectura: si el proceso no expone la variable pero el
     * .env sí la tiene, se devuelve la del .env.
     */
    function iaa_env_valor(string $clave, string $defecto = ''): string
    {
        $actual = getenv($clave);
        if ($actual !== false && trim((string)$actual) !== '') {
            return (string)$actual;
        }
        $leidas = iaa_leer_env_cache();
        if (isset($leidas[$clave]) && trim((string)$leidas[$clave]) !== '') {
            return (string)$leidas[$clave];
        }
        return $defecto;
    }

    /**
     * Clave para las cuentas de prueba que generan los scripts de tools/.
     *
     * H-13: nadie debe poder leer en el repositorio la contraseña de una cuenta
     * que luego quede activa en produccion. Asi que NO se fija una clave
     * conocida en el codigo: se lee de IAA_CLAVE_PRUEBA y, si no esta definida,
     * se genera una aleatoria en cada ejecucion (el script la muestra por
     * pantalla para quien la acaba de crear).
     *
     * @return array{0:string,1:bool} [clave en texto plano, true si fue generada]
     */
    function iaa_clave_prueba(): array
    {
        $definida = trim((string)iaa_env_valor('IAA_CLAVE_PRUEBA', ''));
        if ($definida !== '') {
            return [$definida, false];
        }
        return [bin2hex(random_bytes(6)), true];
    }

    iaa_cargar_entorno();
}
