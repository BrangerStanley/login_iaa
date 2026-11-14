<?php
// config/evidencia_helper.php
// Evidencia fotografica de cedulas: cifrado en reposo, acceso controlado y
// purga por antiguedad.
//
// Que resuelve:
//   1. Las fotos de cedula son datos personales de altisima sensibilidad
//      (documento de identidad + rostro). En el disco no deben quedar en
//      claro: se cifran con AES-256-GCM (cifrado autenticado) usando una
//      clave que vive fuera del repositorio.
//   2. El OCR necesita la imagen en claro, pero solo durante el instante de
//      la lectura: se descifra en un archivo temporal con permisos 0600 y se
//      borra al terminar.
//   3. Los datos no se guardan para siempre: hay una politica de retencion
//      configurable (IAA_EVIDENCIA_RETENCION_DIAS, 180 dias por defecto).
//
// La clave (IAA_EVIDENCIA_CLAVE) NO esta en el codigo: si no se define en el
// entorno, se genera una y se guarda en un archivo fuera del webroot, con
// permisos 0600 y excluido de Git.
require_once __DIR__ . '/directorios.php';

if (!function_exists('evidencia_clave')) {

    /**
     * Cabecera de los archivos cifrados (permite distinguirlos de los planos).
     */
    if (!defined('EVIDENCIA_MAGIC')) {
        define('EVIDENCIA_MAGIC', 'IAAC1');
    }

    /** Archivo con la clave cuando no se define en el entorno. */
    function iaa_archivo_clave_evidencia(): string
    {
        return iaa_dir_raiz() . DIRECTORY_SEPARATOR . '.clave_evidencia';
    }

    /**
     * Clave de 32 bytes para AES-256.
     *
     * Prioridad: variable de entorno IAA_EVIDENCIA_CLAVE (recomendada en
     * produccion, permite rotarla sin tocar el disco) y, si no existe, un
     * archivo generado una sola vez.
     */
    function evidencia_clave(): ?string
    {
        if (!function_exists('openssl_encrypt')) {
            error_log('evidencia: la extension openssl no esta disponible: no se cifra la evidencia');
            return null;
        }

        $valor = trim((string)iaa_env_valor('IAA_EVIDENCIA_CLAVE', ''));
        // La cache se guarda JUNTO con el origen que la produjo. Si la cache
        // fuera incondicional, una variable de entorno definida despues (rotacion
        // de clave, o un putenv() en las pruebas) seguiria devolviendo la clave
        // anterior durante todo el proceso.
        static $origen = null;
        static $clave = null;
        $actual = 'env:' . $valor;
        if ($clave !== null && $origen === $actual) {
            return $clave;
        }
        $origen = $actual;
        $clave = null;

        if ($valor !== '') {
            $binaria = @hex2bin($valor);
            if ($binaria === false || strlen($binaria) < 32) {
                // Tambien se acepta una frase de texto: se deriva con SHA-256.
                $binaria = hash('sha256', $valor, true);
            }
            $clave = substr($binaria, 0, 32);
            return $clave;
        }

        // Sin clave en el entorno: se usa (o se genera) el archivo de clave.
        $ruta = iaa_archivo_clave_evidencia();
        if (is_file($ruta)) {
            $contenido = trim((string)@file_get_contents($ruta));
            $binaria = @hex2bin($contenido);
            if ($binaria !== false && strlen($binaria) === 32) {
                $clave = $binaria;
                return $clave;
            }
            error_log('evidencia: el archivo de clave no es valido: no se cifra la evidencia');
            return null;
        }

        $nueva = random_bytes(32);
        if (@file_put_contents($ruta, bin2hex($nueva), LOCK_EX) === false) {
            error_log('evidencia: no se pudo crear el archivo de clave');
            return null;
        }
        @chmod($ruta, 0600);
        $clave = $nueva;
        return $clave;
    }

    /** ¿El archivo esta cifrado con el formato de este sistema? */
    function evidencia_es_cifrada(string $ruta): bool
    {
        $manejador = @fopen($ruta, 'rb');
        if ($manejador === false) return false;
        $cabecera = (string)fread($manejador, strlen(EVIDENCIA_MAGIC));
        fclose($manejador);
        return $cabecera === EVIDENCIA_MAGIC;
    }

    /**
     * Cifra un archivo en el sitio (deja el cifrado y borra el original).
     * Devuelve true si el archivo queda cifrado.
     */
    function evidencia_cifrar(string $ruta): bool
    {
        if (!is_file($ruta)) return false;
        if (evidencia_es_cifrada($ruta)) return true;

        $clave = evidencia_clave();
        if ($clave === null) return false;

        $contenido = @file_get_contents($ruta);
        if ($contenido === false) return false;

        $iv = random_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $tag = '';
        $cifrado = openssl_encrypt($contenido, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag, 'evidencia', 16);
        if ($cifrado === false) return false;

        $salida = EVIDENCIA_MAGIC . $iv . $tag . $cifrado;
        $tmp = $ruta . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $salida, LOCK_EX) === false) return false;
        @chmod($tmp, 0600);

        if (!@rename($tmp, $ruta)) {
            @unlink($tmp);
            return false;
        }
        @chmod($ruta, 0600);

        return true;
    }

    /**
     * Descifra un archivo en un archivo temporal legible (0600) y devuelve su
     * ruta. Si el archivo ya estaba en claro (evidencia antigua), devuelve la
     * ruta original. El llamante debe borrar el temporal.
     */
    function evidencia_preparar_para_ocr(string $ruta): ?string
    {
        if (!is_file($ruta)) return null;

        if (!evidencia_es_cifrada($ruta)) {
            return $ruta;
        }

        $clave = evidencia_clave();
        if ($clave === null) return null;

        $contenido = @file_get_contents($ruta);
        if ($contenido === false) return null;

        $longitudCabecera = strlen(EVIDENCIA_MAGIC);
        $iv = substr($contenido, $longitudCabecera, 12);
        $tag = substr($contenido, $longitudCabecera + 12, 16);
        $cifrado = substr($contenido, $longitudCabecera + 28);

        $plano = openssl_decrypt($cifrado, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag, 'evidencia');
        if ($plano === false) {
            error_log('evidencia: no se pudo descifrar ' . basename($ruta));
            return null;
        }

        $temporal = sys_get_temp_dir() . '/iaa_ev_' . bin2hex(random_bytes(8)) . '.jpg';
        if (@file_put_contents($temporal, $plano, LOCK_EX) === false) return null;
        @chmod($temporal, 0600);

        return $temporal;
    }

    /**
     * Guarda una evidencia: la cifra y deja solo la version cifrada.
     * Devuelve el nombre del archivo (sin ruta) o '' si no se pudo guardar.
     */
    function evidencia_guardar(string $origenTemporal, string $nombre): string
    {
        $directorio = iaa_dir_evidencias();
        if (!iaa_preparar_dir_sensible($directorio)) return '';

        // El nombre no lleva datos personales (la cedula, el nombre): solo
        // fecha y aleatoriedad. El vinculo con la persona vive en la base de
        // datos, no en el nombre del archivo.
        $seguro = preg_replace('/[^A-Za-z0-9_\-]/', '', $nombre);
        if ($seguro === '') $seguro = 'evidencia';

        $destino = $directorio . DIRECTORY_SEPARATOR . $seguro . '.enc';
        if (!@copy($origenTemporal, $destino)) {
            error_log('evidencia: no se pudo copiar el archivo de evidencia');
            return '';
        }
        @chmod($destino, 0600);

        if (!evidencia_cifrar($destino)) {
            // Sin cifrado posible no se guarda: dejar la foto en claro es
            // peor que no tener evidencia.
            error_log('evidencia: no se pudo cifrar la evidencia: se descarta');
            @unlink($destino);
            return '';
        }

        return $seguro . '.enc';
    }

    /**
     * Borra las evidencias mas antiguas que el numero de dias indicado.
     * Devuelve cuantas se borraron.
     */
    function evidencia_purgar_antiguas(?int $dias = null): int
    {
        if ($dias === null) {
            $dias = (int)iaa_env_valor('IAA_EVIDENCIA_RETENCION_DIAS', '180');
        }
        if ($dias <= 0) return 0;

        $directorio = iaa_dir_evidencias();
        if (!is_dir($directorio)) return 0;

        $limite = time() - ($dias * 86400);
        $borradas = 0;

        foreach (glob($directorio . DIRECTORY_SEPARATOR . '*.enc') ?: [] as $archivo) {
            $fecha = @filemtime($archivo);
            if ($fecha !== false && $fecha < $limite) {
                if (@unlink($archivo)) $borradas++;
            }
        }

        return $borradas;
    }

    /** Dias de retencion configurados. */
    function evidencia_dias_retencion(): int
    {
        $dias = (int)iaa_env_valor('IAA_EVIDENCIA_RETENCION_DIAS', '180');
        return $dias > 0 ? $dias : 180;
    }
}
