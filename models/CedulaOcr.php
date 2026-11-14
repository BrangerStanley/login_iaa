<?php
// models/CedulaOcr.php
// Lectura de los datos de una cédula a partir del texto que devuelve
// Tesseract.
// -----------------------------------------------------------------------------
// Tesseract corre en el servidor y el texto que devuelve se interpreta aqui,
// en el flujo de validacion/verificacion de la cedula (validacionController,
// verificar_cedula_beneficiario).
//
// No modifica la base de datos: esta clase solo lee texto.
class CedulaOcr
{
    /** Palabras que delatan un documento de identidad venezolano. */
    private static $PALABRAS_CEDULA = ['CEDULA', 'IDENTIDAD', 'REPUBLICA', 'BOLIVARIANA', 'VENEZUELA', 'VENEZOLANO'];

    /**
     * Valores que viven en la cédula pero NUNCA son parte de un nombre.
     * Sin esta lista el lector sin etiquetas daba como nombre "VENEZOLANO" o
     * "SOLTERA": son lineas de una sola palabra que pasaban el filtro.
     */
    private static $PALABRAS_NO_NOMBRE = [
        'VENEZOLANO', 'VENEZOLANA', 'EXTRANJERO', 'EXTRANJERA', 'EXTRANJERIA',
        'SOLTERO', 'SOLTERA', 'CASADO', 'CASADA', 'VIUDO', 'VIUDA',
        'DIVORCIADO', 'DIVORCIADA', 'CONCUBINO', 'CONCUBINA', 'CONYUGE',
        'SEXO', 'NACIONALIDAD', 'ESTADO', 'CIVIL', 'EXPEDICION', 'VENCIMIENTO',
        'ALTURA', 'PESO', 'SIGNOS', 'SANGRE', 'PROFESION', 'NACIMIENTO',
        'MASCULINO', 'FEMENINO', 'DOCUMENTO', 'ELABORADO', 'IDENTIDAD',
        'REPUBLICA', 'BOLIVARIANA', 'VENEZUELA', 'GERENTE', 'DIRECTOR',
    ];

    /** ¿Esta palabra es un valor de cédula que no puede ser un nombre? */
    public static function esPalabraNoNombre($palabra)
    {
        return in_array(self::normalizarTexto(trim($palabra)), self::$PALABRAS_NO_NOMBRE, true);
    }

    /**
     * Palabras sueltas que jamas son parte del nombre de una persona
     * (normalizadas, sin acentos).
     */
    private static $PALABRAS_NOMBRE_PROHIBIDAS = [
        'MM', 'M', 'F', 'VENEZOLANO', 'VENEZOLANA', 'SOLTERA', 'SOLTERO',
        'CASADA', 'CASADO', 'DIRECTOR', 'DIRECTORA', 'GERENTE', 'PRESIDENTE',
        'MUESTRA', 'VIGENTE', 'ANTIGUA', 'FORMATO', 'DISENO', 'ELEMENTOS',
        'SEGURIDAD', 'IDENTIDAD', 'CEDULA', 'REPUBLICA', 'BOLIVARIANA',
        'VENEZUELA', 'NACIONALIDAD', 'ESTADO', 'CIVIL', 'SEXO', 'EXPEDICION',
        'VENCIMIENTO', 'ALTURA', 'PESO', 'SIGNOS', 'SANGRE', 'PROFESION',
        'NACIMIENTO', 'EXTRANJERO', 'EXTRANJERA', 'MASCULINO', 'FEMENINO',
        'REPUBL', 'NACIM', 'FOMAC', 'OCUL', 'FISCAL', 'NOTARIA',
    ];

    /**
     * Fragmentos que delatan un valor de la cédula (o de la pantalla de
     * internet) colado en el nombre: se busca dentro de la palabra.
     */
    private static $FRAGMENTOS_NO_NOMBRE = [
        'REPUBL', 'VENEZUEL', 'VENEZOLAN', 'IDENTIDAD', 'CEDULA', 'NACIM',
        'FOMAC', 'EXPEDIC', 'VENCIM', 'ALTURA', 'PROFES', 'SOLTER', 'CASAD',
        'VIUD', 'DIVORC', 'CONCUB', 'NACIONAL', 'SIGNOS', 'SANGRE', 'DIREC',
        'GERENT', 'PRESIDENT', 'MUESTR', 'VIGENT', 'ANTIGU', 'FORMAT',
        'ELEMENTOS', 'SEGURIDAD', 'FIRMA', 'HUELLA', 'BOLETIN', 'REGISTRO',
    ];

    /**
     * ¿Este token puede ser parte del nombre de una persona?
     * Descarta los valores de cédula y las palabras sueltas prohibidas.
     */
    public static function esTokenNombreValido($token): bool
    {
        $token = trim((string)$token);
        if ($token === '') return false;
        if (!preg_match("/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ'\.\-]+$/u", $token)) return false;

        $normalizado = self::normalizarTexto($token);
        if (in_array($normalizado, self::$PALABRAS_NOMBRE_PROHIBIDAS, true)) return false;
        if (in_array($normalizado, self::$PALABRAS_NO_NOMBRE, true)) return false;

        foreach (self::$FRAGMENTOS_NO_NOMBRE as $fragmento) {
            if (mb_strpos($normalizado, $fragmento) !== false) return false;
        }

        return mb_strlen($normalizado) >= 2;
    }

    /**
     * Filtra una lista de tokens dejando solo los que forman parte de un
     * nombre.
     *
     * En la cédula los nombres van en MAYÚSCULAS; el nombre del funcionario
     * que firma (director) va en minúsculas y queda pegado a la linea de los
     * apellidos. Si en la linea hay al menos una palabra en mayusculas, se
     * conservan solo las palabras en mayusculas (y los conectores), de modo
     * que "LOPEZ NAVARRO > Gustavo Vizcain" se queda con "LOPEZ NAVARRO".
     */
    private static function filtrarTokensNombre(array $tokens): array
    {
        $validos = [];
        $hayMayusculas = false;
        foreach ($tokens as $token) {
            if (!self::esTokenNombreValido($token)) continue;
            if (preg_match('/^[A-ZÁÉÍÓÚÜÑ\'\.\-]+$/u', $token)) $hayMayusculas = true;
            $validos[] = $token;
        }

        if (!$hayMayusculas) return $validos;

        $salida = [];
        foreach ($validos as $token) {
            $normalizado = self::normalizarTexto($token);
            if (in_array($normalizado, self::conectores(), true)) { $salida[] = $token; continue; }
            if (preg_match('/^[A-ZÁÉÍÓÚÜÑ\'\.\-]+$/u', $token)) $salida[] = $token;
        }

        return $salida;
    }

    /**
     * Todas las etiquetas impresas en la cédula.
     */
    public static function etiquetasDocumento(): array
    {
        return [
            'NOMBRES', 'NOMBRE', 'APELLIDOS', 'APELLIDO', 'FECHA', 'NACIMIENTO',
            'SEXO', 'NACIONALIDAD', 'ESTADO', 'CIVIL', 'EXPEDICION', 'CEDULA',
            'VENCIMIENTO', 'PROFESION', 'ALTURA', 'PESO', 'SIGNOS', 'SANGRE',
        ];
    }

    /**
     * ¿Esta "palabra" es en realidad una etiqueta deformada por el OCR?
     * Es el caso habitual: el glifo inicial se lee mal y queda "VOMENES" por
     * "NOMBRES" o "aee.vioos" por "APELLIDOS". Se compara el token tal cual y
     * tambien sin su primera letra, que es justo donde falla el reconocimiento.
     */
    public static function esEtiquetaDeformada($palabra)
    {
        $token = self::normalizarTexto(trim((string)$palabra));
        if ($token === '') return false;

        foreach (self::etiquetasDocumento() as $etiqueta) {
            $esEtiquetaDeNombre = in_array($etiqueta, ['NOMBRES', 'NOMBRE', 'APELLIDOS', 'APELLIDO'], true);
            $tolerancia = ($esEtiquetaDeNombre || mb_strlen($etiqueta) >= 6) ? 2 : 1;
            if (self::distanciaLev($token, $etiqueta) <= $tolerancia) return true;

            // Sin la primera letra, que es justo donde falla el reconocimiento
            // del glifo inicial: "VOMENES" por "NOMBRES" (difieren en 2).
            if ($esEtiquetaDeNombre && mb_strlen($token) >= 5 && mb_strlen($etiqueta) >= 5) {
                if (self::distanciaLev(mb_substr($token, 1), mb_substr($etiqueta, 1)) <= 2) return true;
            }
        }
        return false;
    }

    /**
     * Limpia una linea antes de tratarla como nombre: descarta los pedazos de
     * etiqueta deformada y los fragmentos con puntuacion (el OCR rompe
     * "APELLIDOS" en "aee.vioos"). Devuelve '' si la linea no queda con forma
     * de nombre.
     */
    public static function limpiarLineaNombre($linea): string
    {
        $linea = preg_replace('/\s+/u', ' ', (string)$linea);
        $quedan = [];

        foreach (preg_split('/\s+/u', trim($linea), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            // Fragmento con puntuacion pegada: no es parte de un nombre.
            if (preg_match('/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ\' \-]/u', $token)) continue;
            // Eco de una etiqueta ("vomenes", "apellidos").
            if (self::esEtiquetaDeformada($token)) continue;
            $quedan[] = $token;
        }

        // Etiqueta deformada pegada al valor ("nousses VANESSA KRISTINA"):
        // la cédula imprime los nombres en mayúsculas, así que una palabra
        // en minúsculas al principio de la linea es la etiqueta, no el nombre.
        while (count($quedan) > 1 && self::esEtiquetaRota($quedan[0], array_slice($quedan, 1))) {
            array_shift($quedan);
        }

        $limpia = trim(implode(' ', $quedan));
        return $limpia === '' ? '' : $limpia;
    }

    // -----------------------------------------------------------------------
    // Utilidades de texto
    // -----------------------------------------------------------------------

    /** Mayusculas sin acentos: el OCR suele inventarlos al leerlas. */
    public static function normalizarTexto($texto)
    {
        $t = mb_strtoupper((string)$texto, 'UTF-8');

        // Sin acentos: se prueban las dos vias segun la version de PHP.
        if (class_exists('Normalizer')) {
            $t = (string)\Normalizer::normalize($t, \Normalizer::FORM_D);
            $t = preg_replace('/\x{0300}-\x{036f}/u', '', $t);
        } else {
            $t = strtr($t, [
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            ]);
        }
        return $t;
    }

    /** Distancia de Levenshtein (tolerancia de lectura con errores). */
    public static function distanciaLev($a, $b)
    {
        $m = mb_strlen($a);
        $n = mb_strlen($b);
        if ($m === 0) return $n;
        if ($n === 0) return $m;

        $prev = range(0, $n);
        for ($i = 1; $i <= $m; $i++) {
            $actual = [$i];
            for ($j = 1; $j <= $n; $j++) {
                $costo = mb_substr($a, $i - 1, 1) === mb_substr($b, $j - 1, 1) ? 0 : 1;
                $actual[$j] = min(
                    $prev[$j] + 1,
                    $actual[$j - 1] + 1,
                    $prev[$j - 1] + $costo
                );
            }
            $prev = $actual;
        }
        return $prev[$n];
    }

    // -----------------------------------------------------------------------
    // Deteccion del documento
    // -----------------------------------------------------------------------

    /** ¿El texto parece una cedula venezolana? */
    public static function esCedulaVenezolana($texto)
    {
        $t = self::normalizarTexto($texto);
        if ($t === '') return false;

        if (mb_strpos($t, 'CEDULA DE IDENTIDAD') !== false
            || mb_strpos($t, 'CE OULA') !== false
            || mb_strpos($t, 'IDENTIDAD') !== false) {
            return true;
        }

        $senales = 0;
        $palabras = preg_split('/[^A-Z0-9]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (self::$PALABRAS_CEDULA as $clave) {
            $distMax = max(1, (int)ceil(mb_strlen($clave) / 5));
            foreach ($palabras as $palabra) {
                if (abs(mb_strlen($palabra) - mb_strlen($clave)) > $distMax + 1) continue;
                if (self::distanciaLev($palabra, $clave) <= $distMax) {
                    $senales++;
                    break;
                }
            }
        }
        if (preg_match('/[VEY][\s\x{2013}\x{2014}\-\.]{0,3}\d{2,4}\s?[.,]\s?\d{3}/u', $t)) {
            $senales++;
        }

        return $senales >= 2;
    }

    /** El numero leido aparece casi siempre junto al prefijo V- o E-. */
    public static function extraerNumeroCedula($texto)
    {
        $t = self::normalizarTexto($texto);
        if ($t === '') return '';

        $candidatos = [];

        // 0) Numero con los puntos de miles, tal como se imprime en la
        //    cedula (V 22.909.081 / 6.123.724). Es la forma mas fiable: el
        //    motor lee los puntos mucho mejor que el prefijo, que deformo
        //    ("Vo", "Ye", "N"). Se prioriza el numero que tenga un prefijo
        //    V/E delante (dentro de los caracteres previos).
        if (preg_match_all('/(?<![\d.])(\d{1,3})\.\s?(\d{3})\.\s?(\d{3})(?![\d.])/u', $t, $conPuntos, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($conPuntos as $coincidencia) {
                $digitos = $coincidencia[1][0] . $coincidencia[2][0] . $coincidencia[3][0];
                if (!self::cedulaFormatoAceptable($digitos)) continue;

                $posicion = $coincidencia[0][1];
                $previo = mb_substr($t, max(0, $posicion - 12), min(12, $posicion));
                $conPrefijo = (preg_match('/[VEY]$/u', $previo) || preg_match('/[VEY][\s\-\.]{1,3}$/u', $previo)) ? 1 : 0;

                $candidatos[] = ['valor' => $digitos, 'puntos' => 1, 'prefijo' => $conPrefijo];
            }
        }
        if ($candidatos) {
            usort($candidatos, function ($a, $b) {
                return [$b['prefijo'], $b['puntos']] <=> [$a['prefijo'], $a['puntos']];
            });
            return $candidatos[0]['valor'];
        }

        // 1) Numero pegado al prefijo (V12345678, E-12345678, V-16.789.542).
        //    El prefijo debe empezar palabra: si no, "DE IDENTIDAD" contaria.
        $rePrefijo = '/(?<![A-Z0-9])[VEY][\s\x{2013}\x{2014}\-\.]{0,3}(\d[\d\.\s]{5,14})/u';
        if (preg_match_all($rePrefijo, $t, $coincidencias, PREG_SET_ORDER)) {
            foreach ($coincidencias as $coincidencia) {
                $contexto = preg_replace('/\D/', '', $coincidencia[1]);
                for ($largo = 8; $largo >= 7; $largo--) {
                    if (mb_strlen($contexto) >= $largo) {
                        $candidatos[] = mb_substr($contexto, 0, $largo);
                    }
                }
            }
        }

        $aceptado = self::primerCedulaValida($candidatos);
        if ($aceptado !== '') {
            return $aceptado;
        }

        // 2) Sin prefijo legible: cualquier secuencia de 7 u 8 digitos.
        //    Antes se descartan las fechas (30/11/2018 -> 30112018), que de
        //    otro modo se confunden con un numero de cedula.
        $sinFechas = preg_replace('/\b\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}\b/u', ' ', $t);
        $candidatos = [];
        $soloDigitos = preg_replace('/\D/', '', $sinFechas);
        for ($largo = 8; $largo >= 7; $largo--) {
            for ($k = 0; $k + $largo <= mb_strlen($soloDigitos); $k++) {
                $candidatos[] = mb_substr($soloDigitos, $k, $largo);
            }
        }

        return self::primerCedulaValida($candidatos);
    }

    /** Primer candidato con 7 u 8 digitos que parezca una cedula. */
    private static function primerCedulaValida($candidatos)
    {
        foreach ($candidatos as $candidato) {
            $candidato = ltrim($candidato, '0');
            $largo = mb_strlen($candidato);
            if ($largo >= 7 && $largo <= 8 && (int)$candidato > 1000000) {
                return $candidato;
            }
        }
        return '';
    }

    /** ¿El numero buscado aparece aunque el OCR haya metido un caracter de mas? */
    public static function contieneCedula($texto, $cedula)
    {
        $t = preg_replace('/[.\s\x{2013}\x{2014}\-]/u', '', self::normalizarTexto($texto));
        $objetivo = ltrim(preg_replace('/\D/', '', (string)$cedula), '0');
        $largo = mb_strlen($objetivo);
        if ($largo < 6) return false;

        for ($len = $largo - 1; $len <= $largo + 2; $len++) {
            for ($k = 0; $k + $len <= mb_strlen($t); $k++) {
                if (self::alineaCedula(mb_substr($t, $k, $len), $objetivo)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function alineaCedula($ventana, $objetivo)
    {
        $i = 0;
        $j = 0;
        $err = 0;
        $lv = mb_strlen($ventana);
        $lo = mb_strlen($objetivo);
        while ($i < $lv && $j < $lo && $err <= 1) {
            if ($ventana[$i] === $objetivo[$j]) {
                $i++;
                $j++;
            } elseif (isset($ventana[$i + 1]) && $ventana[$i + 1] === $objetivo[$j]) {
                $i++;
                $err++;
            } elseif ($ventana[$i] === $objetivo[$j + 1]) {
                $j++;
                $err++;
            } else {
                $i++;
                $j++;
                $err++;
            }
        }
        $err += abs(($lv - $i) - ($lo - $j));
        return $err <= 1;
    }

    // -----------------------------------------------------------------------
    // Extraccion de campos
    // -----------------------------------------------------------------------

    /**
     * El OCR confunde letras con digitos (N0MBRES, J0SE). Cuando una palabra
     * mezcla ambos, se asume que todos los digitos son letras mal leidas.
     */
    private static function repararLetras($valor)
    {
        return preg_replace_callback(
            '/(?=[\p{L}\p{N}]*[A-Za-zÁÉÍÓÚÜÑáéíóúüñ])(?=[\p{L}\p{N}]*[0-9])[\p{L}\p{N}]+/u',
            function ($m) {
                return strtr($m[0], ['0' => 'O', '1' => 'I', '5' => 'S', '8' => 'B', '2' => 'Z', '6' => 'G']);
            },
            (string)$valor
        );
    }

    /**
     * Valor que sigue a una etiqueta, continuando en las lineas siguientes
     * hasta encontrar una de las etiquetas de cierre.
     */
    private static function valorTrasEtiqueta($texto, $etiquetas, $fin)
    {
        $lineas = preg_split('/\r\n|\r|\n/', (string)$texto);
        $indice = -1;
        $posEnLinea = 0;
        $etiquetaEncontrada = '';

        // La etiqueta mas larga primero: "NOMBRES" debe ganarle a "NOMBRE".
        $buscadas = $etiquetas;
        usort($buscadas, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        foreach ($buscadas as $etiqueta) {
            foreach ($lineas as $i => $linea) {
                $pos = self::buscarEtiqueta($linea, $etiqueta);
                if ($pos !== null) {
                    $indice = $i;
                    $posEnLinea = $pos;
                    $etiquetaEncontrada = $etiqueta;
                    break 2;
                }
            }
        }
        if ($indice === -1) return '';

        $linea = $lineas[$indice];
        $resto = mb_substr($linea, $posEnLinea + mb_strlen($etiquetaEncontrada));
        $resto = preg_replace('/^[\s:.\-]+/u', '', $resto);
        $valor = trim((string)$resto);

        $total = count($lineas);
        for ($j = $indice + 1; $j < $total; $j++) {
            // Con 4 palabras ya se trata como un nombre completo: seguir
            // agregaria ruido de otras etiquetas.
            if (count(preg_split('/\s+/u', trim($valor), -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 4) {
                break;
            }
            $siguiente = trim($lineas[$j]);
            if ($siguiente === '') {
                continue;
            }
            $siguienteMayus = mb_strtoupper($siguiente, 'UTF-8');
            foreach ($fin as $etiquetaFin) {
                // buscarEtiqueta y no strpos: la linea de corte tambien puede
                // venir con errores ("N0MBRES J0SE...").
                // break 2 (y no continue) corta la lectura: a partir de ahi
                // empieza otro dato de la cédula.
                if (self::buscarEtiqueta($siguienteMayus, $etiquetaFin) !== null) {
                    break 2;
                }
            }
            $valor = $valor === '' ? $siguiente : $valor . ' ' . $siguiente;
        }

        $valor = self::repararLetras($valor);
        $valor = preg_replace('/\s+/u', ' ', $valor);
        // El OCR arrastra ruido: se queda solo con letras, espacios y separadores.
        $valor = preg_replace('/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ\' .\-\/]/u', ' ', $valor);

        return self::limpiarValorNombre(trim(preg_replace('/\s+/u', ' ', $valor)), $etiquetaEncontrada);
    }

    /**
     * Limpia el valor leido tras una etiqueta: Tesseract suele repetir la
     * propia etiqueta deformada ("vomenes VANESSA KRISTINA") y, cuando el
     * documento va en columnas, la linea siguiente puede ser el OTRO nombre
     * ("APELLIDOS"). Sin esta limpieza el nombre salia mezclado con el apellido
     * y hasta con la nacionalidad.
     */
    private static function limpiarValorNombre($valor, string $etiqueta)
    {
        $otraEtiqueta = in_array(mb_strtoupper($etiqueta, 'UTF-8'), ['NOMBRES', 'NOMBRE'], true)
            ? ['APELLIDOS', 'APELLIDO']
            : ['NOMBRES', 'NOMBRE'];

        $palabras = preg_split('/\s+/u', trim($valor), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $salida = [];

        foreach ($palabras as $indice => $palabra) {
            $normalizada = self::normalizarTexto($palabra);

            // Ruido que nunca es un nombre (VENEZOLANO, SOLTERA, ...).
            if (in_array($normalizada, self::$PALABRAS_NO_NOMBRE, true)) break;

            // Eco deformado de la etiqueta que se esta leyendo ("vomenes").
            if ($indice === 0 && self::esEtiquetaDeformada($palabra)) continue;

            // Empieza la otra mitad del nombre: el valor termina ahi.
            $esOtraEtiqueta = false;
            foreach ($otraEtiqueta as $candidata) {
                if (self::distanciaLev($normalizada, $candidata) <= 2) { $esOtraEtiqueta = true; break; }
            }
            if ($esOtraEtiqueta) break;

            $salida[] = $palabra;
        }

        // Se descartan los valores de cédula que se colaron (VENEZOLANO,
        // REPUBLICA...) y la firma del funcionario en minúsculas.
        $salida = self::filtrarTokensNombre($salida);

        return trim(implode(' ', $salida));
    }

    /**
     * Ubica una etiqueta dentro de una linea tolerando los errores tipicos
     * del OCR ("N0MBRES" en vez de "NOMBRES"). Devuelve la posicion o null.
     */
    private static function buscarEtiqueta($linea, $etiqueta)
    {
        $pos = mb_strpos(mb_strtoupper($linea, 'UTF-8'), $etiqueta);
        if ($pos !== false) {
            return $pos;
        }

        // Segunda pasada: palabra por palabra con distancia de edicion.
        $tolerancia = mb_strlen($etiqueta) >= 5 ? 1 : 0;
        if ($tolerancia === 0) {
            return null;
        }

        $desplazamiento = 0;
        foreach (preg_split('/\s+/u', $linea, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            if (self::distanciaLev(mb_strtoupper($palabra, 'UTF-8'), $etiqueta) <= $tolerancia) {
                return $desplazamiento;
            }
            $desplazamiento += mb_strlen($palabra) + 1;
        }
        return null;
    }

    /** Fecha de nacimiento en ISO (Y-m-d). Acepta dd/mm/aaaa y dd-mm-aaaa. */
    public static function parsearFechaNacimiento($texto)
    {
        $t = (string)$texto;
        // El separador puede ser barra, guion o punto, y el OCR a veces lo
        // Borra del todo: "01 12 2005".
        $sep = '[\/\-\.\s]{1,2}';

        // Todas las fechas del documento con anio coherente. En una cédula la
        // fecha de nacimiento es SIEMPRE la mas antigua: las demas son
        // expedicion y vencimiento (esta ultima, ademas, esta en el futuro).
        $candidatas = [];
        if (preg_match_all('/\b(\d{1,2})' . $sep . '(\d{1,2})' . $sep . '(\d{4})\b/u', $t, $todas, PREG_SET_ORDER)) {
            foreach ($todas as $encontro) {
                $iso = self::fechaIso((int)$encontro[3], (int)$encontro[2], (int)$encontro[1]);
                // fechaIso ya descarta anios futuros o inexistentes.
                if ($iso !== '') $candidatas[] = $iso;
            }
        }

        // 1) Con etiqueta explicita: NACIMIENTO 12/03/1990
        $porEtiqueta = '';
        if (preg_match('/NAC\w*[^\d]{0,30}(\d{1,2})' . $sep . '(\d{1,2})' . $sep . '(\d{4})/iu', $t, $m)) {
            $porEtiqueta = self::fechaIso((int)$m[3], (int)$m[2], (int)$m[1]);
        }

        // La etiqueta manda solo si no apunta a una fecha posterior a otra del
        // propio documento: con el OCR desalineado, "NACIMIENTO" caia sobre la
        // fecha de expedicion y devolvia la fecha equivocada. En una cédula la
        // fecha de nacimiento es siempre la mas antigua.
        if ($porEtiqueta !== '') $candidatas[] = $porEtiqueta;
        if (!$candidatas) return '';

        $candidatas = array_unique($candidatas);
        sort($candidatas);
        return $candidatas[0];
    }

    private static function fechaIso($anio, $mes, $dia)
    {
        if ($mes < 1 || $mes > 12 || $dia < 1 || $dia > 31 || $anio < 1900 || $anio > (int)date('Y')) {
            return '';
        }
        if (!checkdate($mes, $dia, $anio)) {
            return '';
        }
        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    /** Etiquetas que cortan el valor: en ellas empieza otro dato de la cédula. */
    private static function etiquetasDeCierre()
    {
        return [
            'NOMBRES', 'NOMBRE', 'APELLIDOS', 'APELLIDO', 'FECHA', 'NACIMIENTO',
            'SEXO', 'NACIONALIDAD', 'ESTADO', 'EXPEDICION', 'CEDULA', 'PROFESION',
            'ALTURA', 'PESO', 'SIGNOS', 'SANGRE',
        ];
    }

    public static function parsearApellidos($texto)
    {
        return self::valorTrasEtiqueta($texto, ['APELLIDOS', 'APELLIDO'], self::etiquetasDeCierre());
    }

    public static function parsearNombres($texto)
    {
        return self::valorTrasEtiqueta($texto, ['NOMBRES', 'NOMBRE'], self::etiquetasDeCierre());
    }

    /**
     * Cédula electrónica (formato nuevo): el nombre va en dos líneas limpias
     * SIN las etiquetas "APELLIDOS" / "NOMBRES". Cuando el lector por etiqueta
     * no encuentra nada, se toman las dos primeras líneas con forma de nombre.
     *
     * @return array{0:string,1:string} [apellidos, nombres]
     */
    public static function nombresSinEtiqueta($texto)
    {
        $lineas = preg_split('/\r\n|\r|\n/', (string)$texto);
        $candidatos = [];
        $anteriorEraNombre = false;

        foreach ($lineas as $linea) {
            $limpia = self::limpiarLineaNombre(trim(preg_replace('/\s+/u', ' ', (string)$linea)));
            $esNombre = $limpia !== '' && self::esLineaDeNombre($limpia);

            if ($esNombre) {
                $candidatos[] = [
                    'texto' => self::repararLetras($limpia),
                    // Venia justo despues de otra linea de nombre (ignorando
                    // las lineas vacias que el OCR intercalaba).
                    'seguida' => $anteriorEraNombre,
                ];
                // Con tres candidatas ya se puede decidir: la pareja del
                // titular es la primera adyacente. Antes se paraba en dos y
                // "... = Gustavo Virca" + "LOPEZ NAVARRO" ganaba.
                if (count($candidatos) >= 3) break;
            } elseif (trim((string)$linea) !== '') {
                $anteriorEraNombre = false;
            }
            $anteriorEraNombre = $esNombre;
        }

        // Se toma la primera pareja contigua; si no hay ninguna, las dos
        // primeras lineas con forma de nombre.
        $eleccion = [null, null];
        $total = count($candidatos);
        for ($i = 0; $i + 1 < $total; $i++) {
            if ($candidatos[$i + 1]['seguida']) {
                $eleccion = [$candidatos[$i]['texto'], $candidatos[$i + 1]['texto']];
                break;
            }
        }
        if ($eleccion[0] === null && $total >= 2) {
            $eleccion = [$candidatos[0]['texto'], $candidatos[1]['texto']];
        } elseif ($eleccion[0] === null && $total === 1) {
            $eleccion = [$candidatos[0]['texto'], ''];
        }

        return [$eleccion[0], $eleccion[1]];
    }

    /** ¿Esta línea puede ser un nombre? Solo letras, de 1 a 4 palabras. */
    private static function esLineaDeNombre($linea)
    {
        // Cualquier digito descarta la linea: alli viven fechas y numeros.
        if (preg_match('/\d/u', $linea)) return false;

        // Los nombres de la cédula se imprimen en MAYÚSCULAS. Una linea en
        // minúsculas es la firma del funcionario ("Gustavo Vizcain"), no el
        // titular del documento.
        $tieneMayusculas = false;
        foreach (preg_split('/\s+/u', $linea, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            if (in_array(self::normalizarTexto($palabra), self::conectores(), true)) continue;
            if (!preg_match('/^[A-ZÁÉÍÓÚÜÑ\'\.\-]+$/u', $palabra)) return false;
            $tieneMayusculas = true;
        }
        if (!$tieneMayusculas) return false;

        // Encabezados, etiquetas y valores de cédula que el OCR no reconoce
        // como tales. Sin esto, "VENEZOLANO" o "SOLTERA" pasaban por nombres.
        foreach (self::$PALABRAS_NO_NOMBRE as $palabra) {
            if (mb_strpos(self::normalizarTexto($linea), $palabra) !== false) return false;
        }

        // El sexo aparece como palabra o como letra suelta: no es un nombre.
        if (preg_match('/^(F|M|MASCULINO|FEMENINO|VARON|MUJER)$/u', trim(self::normalizarTexto($linea)))) return false;

        // Solo letras y separadores de nombre.
        if (!preg_match("/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ' .\\-]+( [A-Za-zÁÉÍÓÚÜÑáéíóúüñ' .\\-]+){0,3}$/u", $linea)) return false;

        $palabras = preg_split('/\s+/u', $linea);
        if (count($palabras) < 1 || count($palabras) > 4) return false;

        // Cada palabra debe poder ser parte de un nombre.
        foreach ($palabras as $palabra) {
            if (in_array(self::normalizarTexto($palabra), self::conectores(), true)) continue;
            if (!self::esTokenNombreValido($palabra)) return false;
        }

        // "DE", "DEL" y "LA" son conectores: no cuentan como nombre corto.
        $conectores = ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y', 'MC', 'MC.'];
        foreach ($palabras as $palabra) {
            if (mb_strlen($palabra) < 3 && !in_array(mb_strtoupper($palabra, 'UTF-8'), $conectores, true)) {
                return false;
            }
        }
        return true;
    }

    /** Devuelve 'Masculino', 'Femenino' o '' (los valores del <select>). */
    public static function parsearSexo($texto)
    {
        $t = self::normalizarTexto($texto);
        $pos = mb_strpos($t, 'SEXO');
        if ($pos !== false) {
            $trozo = mb_substr($t, $pos, 40);
            if (preg_match('/FEMENINO|MUJER|\bF\b/u', $trozo)) return 'Femenino';
            if (preg_match('/MASCULINO|VARON|\bM\b/u', $trozo)) return 'Masculino';
        }
        if (mb_strpos($t, 'FEMENINO') !== false) return 'Femenino';
        if (mb_strpos($t, 'MASCULINO') !== false) return 'Masculino';

        // Formato nuevo: el sexo va como letra suelta en su propia linea. Al
        // alinear mal las columnas el OCR la pega a la etiqueta de al lado
        // ("F NACIMIENTO", "NACIMIENTO F"): se buscan ambos sentidos.
        foreach (preg_split('/\r\n|\r|\n/', (string)$texto) as $linea) {
            $linea = trim($linea);
            if (preg_match('/^[FMfm]$/u', $linea)) {
                return mb_strtoupper($linea) === 'M' ? 'Masculino' : 'Femenino';
            }
        }
        $normalizado = self::normalizarTexto($texto);
        if (preg_match('/\b([FM])\s+NAC\w+/u', $normalizado, $m)) {
            return $m[1] === 'M' ? 'Masculino' : 'Femenino';
        }
        if (preg_match('/\bNAC\w+\s+([FM])\b/u', $normalizado, $m)) {
            return $m[1] === 'M' ? 'Masculino' : 'Femenino';
        }

        return '';
    }

    /**
     * ¿El texto leido puede ser el nombre de una persona?
     * Exige al menos dos palabras y que ninguna sea un valor de cédula
     * (nacionalidad, estado civil, sexo).
     */
    public static function nombrePlausible($nombre): bool
    {
        $palabras = preg_split('/\s+/u', trim((string)$nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($palabras) < 2) return false;
        foreach ($palabras as $palabra) {
            if (self::esPalabraNoNombre($palabra)) return false;
        }
        return true;
    }

    public static function parsearNacionalidad($texto)
    {
        $t = self::normalizarTexto($texto);
        if (mb_strpos($t, 'EXTRANJER') !== false) return 'Extranjero';
        if (preg_match('/E[\s\x{2013}\x{2014}\-]{0,3}\d{6,9}/u', $t)) return 'Extranjero';
        return 'Venezolano';
    }

    // -----------------------------------------------------------------------
    // Lectura por diseño (sin etiqueta)
    // -----------------------------------------------------------------------

    /** Conectores que pueden aparecer en un nombre venezolano. */
    private static function conectores(): array
    {
        return ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y', 'MC', 'MC.', 'DELA', 'DELOS'];
    }

    /**
     * ¿Este token es una etiqueta rota por el OCR? En una cédula los nombres
     * se imprimen en MAYÚSCULAS y las etiquetas ("APELLIDOS", "NOMBRES") en
     * un cuerpo mas pequeño que el motor suele leer deformado y en minuscula
     * ("sresoos", "nousses", "mues"). Un token en minuscula al principio de
     * una linea que despues tiene mayusculas es, casi con certeza, la
     * etiqueta: se descarta y la linea queda con el valor real.
     */
    private static function esEtiquetaRota(string $token, array $restoLinea): bool
    {
        if ($token === '' || mb_strlen($token) < 3) return false;
        if (!preg_match('/[a-z]/u', $token)) return false;          // todo en mayusculas: no es etiqueta
        if (in_array(self::normalizarTexto($token), self::conectores(), true)) return false;

        // Debe quedar al menos un token en mayusculas que sea nombre.
        foreach ($restoLinea as $otro) {
            if (preg_match('/^[A-ZÁÉÍÓÚÜÑ\'\.\-]{2,}$/u', $otro)) return true;
        }
        return false;
    }

    /**
     * Primera rena de tokens que forma un nombre: se avanza mientras los
     * tokens sean palabras en mayusculas (o conectores). Se detiene en
     * cuanto aparece algo que no es un nombre (la firma del director, el
     * cargo, un simbolo).
     */
    private static function renaNombre(array $tokens): string
    {
        $salida = [];
        foreach ($tokens as $token) {
            $normalizado = self::normalizarTexto($token);
            if (in_array($normalizado, self::conectores(), true)) {
                $salida[] = $token;
                continue;
            }
            if (!self::esTokenNombreValido($token)) break;
            $salida[] = $token;
            if (count($salida) >= 5) break;
        }

        // Si la linea trae palabras en mayusculas, el resto es ruido (la
        // firma del funcionario que autoriza).
        $salida = self::filtrarTokensNombre($salida);

        while ($salida && in_array(self::normalizarTexto(end($salida)), self::conectores(), true)) {
            array_pop($salida);
        }

        return trim(implode(' ', $salida));
    }

    /**
     * Lee apellidos y nombres por la posicion de las lineas, sin depender de
     * que la etiqueta se haya leido bien.
     *
     * En la cédula el bloque izquierdo trae, en este orden, la linea de
     * APELLIDOS y la de NOMBRES. Cuando la etiqueta se deformo, se reconoce
     * el valor por su forma (mayusculas, sin digitos) y por el orden.
     *
     * @return array{0:string,1:string} [apellidos, nombres]
     */
    public static function nombresPorDiseno(string $texto): array
    {
        $lineas = preg_split('/\r\n|\r|\n/', (string)$texto);
        $candidatos = [];

        foreach ($lineas as $linea) {
            $linea = trim(preg_replace('/\s+/u', ' ', (string)$linea));
            if ($linea === '') continue;
            // Con digitos la linea es de fechas, del numero de cedula o del
            // cargo del funcionario: no aporta el nombre del titular.
            if (preg_match('/\d/u', $linea)) continue;

            $tokens = preg_split('/\s+/u', $linea, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            // Se descarta la etiqueta rota del principio.
            while (count($tokens) > 1 && self::esEtiquetaRota($tokens[0], array_slice($tokens, 1))) {
                array_shift($tokens);
            }

            $valor = self::renaNombre($tokens);
            if ($valor === '') continue;
            if (count(preg_split('/\s+/u', $valor, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 2) continue;

            $candidatos[] = $valor;
            if (count($candidatos) >= 2) break;
        }

        return [
            $candidatos[0] ?? '',
            $candidatos[1] ?? '',
        ];
    }

    // -----------------------------------------------------------------------
    // Punto de entrada
    // -----------------------------------------------------------------------

    /**
     * Analiza el texto crudo del OCR y devuelve los campos del formulario.
     * Los valores llegan como cadenas vacias cuando no se pudieron leer:
     * ningun campo es obligatorio en esta capa.
     *
     * @return array{cedula:string,apellidos:string,nombres:string,nombre_completo:string,
     *               fecha_nacimiento:string,sexo:string,nacionalidad:string,es_cedula:bool}
     */
    public static function analizar($texto)
    {
        $apellidos = self::parsearApellidos($texto);
        $nombres = self::parsearNombres($texto);

        // Cédula electrónica: los nombres no traen etiqueta. Se recurren a las
        // dos primeras líneas con forma de nombre (primero apellidos, luego
        // nombres, que es el orden en que se imprimen en el documento).
        if ($apellidos === '' || $nombres === '') {
            list($apeLimpios, $nomLimpios) = self::nombresSinEtiqueta($texto);
            if ($apellidos === '') $apellidos = $apeLimpios;
            if ($nombres === '') $nombres = $nomLimpios;
        }

        // Etiqueta deformada ("sresoos LOPEZ NAVARRO"): se recurre a la
        // posicion de las lineas para recuperar apellidos y nombres.
        if ($apellidos === '' || $nombres === '') {
            list($apeDiseno, $nomDiseno) = self::nombresPorDiseno($texto);
            if ($apellidos === '') $apellidos = $apeDiseno;
            if ($nombres === '') $nombres = $nomDiseno;
        }

        $cedula = self::extraerNumeroCedula($texto);

        // Ultimo filtro antes de llenar el formulario: un nombre de una sola
        // palabra que sea nacionalidad o estado civil ("VENEZOLANO",
        // "SOLTERA") no es un nombre, asi que no se ofrece al usuario.
        if ($nombres !== '' && $nombres === $apellidos) {
            // El documento solo traia una linea de nombre: no se repite.
            $apellidos = '';
        }

        $nombreCompleto = trim($nombres . ' ' . $apellidos);
        $nombreCompleto = preg_replace('/\s+/u', ' ', $nombreCompleto);
        $nombreCompleto = self::colapsarPalabrasRepetidas($nombreCompleto);
        if (!self::nombrePlausible($nombreCompleto)) {
            $nombreCompleto = '';
        }

        return [
            'cedula'            => $cedula,
            'apellidos'         => $apellidos,
            'nombres'           => $nombres,
            'nombre_completo'   => $nombreCompleto,
            'fecha_nacimiento'  => self::parsearFechaNacimiento($texto),
            'sexo'              => self::parsearSexo($texto),
            'nacionalidad'      => self::parsearNacionalidad($texto),
            'es_cedula'         => self::esCedulaVenezolana($texto),
            'es_galeria'        => self::esCapturaDeGaleria($texto),
        ];
    }

    /**
     * Analiza varias lecturas de la MISMA imagen y devuelve un solo conjunto
     * de campos combinando lo mejor de cada una.
     *
     * Cada pasada de Tesseract ve la imagen de forma distinta: una lee el
     * numero y otra los nombres. Se puntua cada lectura y se toma el mejor
     * valor de cada campo. El numero de cedula se decide por votacion (el
     * valor que aparece en mas lecturas), con un filtro de formato, para no
     * devolver un numero inventado por una sola pasada.
     *
     * @param string[] $textos
     * @return array|null null si ninguna lectura devolvio texto
     */
    public static function analizarMultiples(array $textos): ?array
    {
        $textos = array_values(array_filter(array_map('trim', $textos), function ($t) {
            return $t !== '';
        }));
        if (!$textos) return null;

        $resultados = [];
        foreach ($textos as $texto) {
            $resultados[] = ['texto' => $texto, 'datos' => self::analizar($texto)];
        }

        // Puntaje de cada lectura:cuanto vale como lectura.
        foreach ($resultados as $indice => $resultado) {
            $d = $resultado['datos'];
            $puntaje = 0;
            if ($d['cedula'] !== '' && self::cedulaFormatoAceptable($d['cedula'])) $puntaje += 100;
            if ($d['nombre_completo'] !== '') $puntaje += 60;
            if ($d['apellidos'] !== '' && $d['nombres'] !== '') $puntaje += 30;
            if ($d['fecha_nacimiento'] !== '') $puntaje += 20;
            if ($d['sexo'] !== '') $puntaje += 15;
            if ($d['es_cedula']) $puntaje += 25;
            $resultados[$indice]['puntaje'] = $puntaje;
        }

        usort($resultados, function ($a, $b) {
            return $b['puntaje'] <=> $a['puntaje'];
        });

        $mejor = $resultados[0]['datos'];

        // Votacion del numero de cedula: solo se aceptan los que cumplen el
        // formato y se elige el mas repetido (desempate: el de la mejor
        // lectura).
        $votos = [];
        foreach ($resultados as $resultado) {
            $cedula = $resultado['datos']['cedula'];
            if ($cedula === '' || !self::cedulaFormatoAceptable($cedula)) continue;
            $votos[$cedula] = ($votos[$cedula] ?? 0) + 1;
        }
        if ($votos) {
            arsort($votos);
            $mejor['cedula'] = array_key_first($votos);
        } else {
            $mejor['cedula'] = '';
        }

        // Los nombres se eligen por calidad, no por orden de lectura: una
        // pasada puede traer "VANESSA KRISTINA" y otra "Gustavo Vircam" (la
        // firma del funcionario). Se gana la lectura mas limpia.
        foreach (['nombres', 'apellidos'] as $campo) {
            $mejorCalidad = -PHP_INT_MAX;
            $elegido = '';
            foreach ($resultados as $resultado) {
                $valor = (string)($resultado['datos'][$campo] ?? '');
                if ($valor === '') continue;
                $calidad = self::calidadNombre($valor);
                if ($calidad > $mejorCalidad) {
                    $mejorCalidad = $calidad;
                    $elegido = $valor;
                }
            }
            if ($elegido !== '' && $mejorCalidad > 0) {
                $mejor[$campo] = $elegido;
            }
        }

        // El resto de campos: se rellenan con la primera lectura que los traiga.
        foreach (['fecha_nacimiento', 'sexo', 'nacionalidad', 'es_cedula'] as $campo) {
            if (!empty($mejor[$campo])) continue;
            foreach ($resultados as $resultado) {
                if (!empty($resultado['datos'][$campo])) {
                    $mejor[$campo] = $resultado['datos'][$campo];
                    break;
                }
            }
        }

        // La captura de galeria se decide por mayoría: basta con que una
        // lectura vea varias cedulas para no rellenar el formulario.
        $mejor['es_galeria'] = false;
        foreach ($resultados as $resultado) {
            if (!empty($resultado['datos']['es_galeria'])) {
                $mejor['es_galeria'] = true;
                break;
            }
        }

        // El nombre completo se recalcula con los apellidos y nombres finales.
        // Si los dos campos traen el mismo texto (una sola linea de nombre
        // en el documento), no se repite.
        if (trim((string)($mejor['nombres'] ?? '')) !== ''
            && trim((string)($mejor['nombres'] ?? '')) === trim((string)($mejor['apellidos'] ?? ''))) {
            $mejor['apellidos'] = '';
        }

        $nombreCompleto = trim(($mejor['nombres'] ?? '') . ' ' . ($mejor['apellidos'] ?? ''));
        $nombreCompleto = preg_replace('/\s+/u', ' ', $nombreCompleto);
        $nombreCompleto = self::colapsarPalabrasRepetidas($nombreCompleto);
        $mejor['nombre_completo'] = self::nombrePlausible($nombreCompleto) ? $nombreCompleto : '';

        // El texto que se devuelve es el de la mejor lectura (el que el
        // operador puede revisar en el log de depuracion).
        $mejor['texto'] = $resultados[0]['texto'];
        $mejor['lecturas'] = count($textos);

        return $mejor;
    }

    /**
     * Calidad de un nombre leido: cuanto mayor, mas probable es que sea el
     * nombre del titular y no un fragmento de la cedula o la firma del
     * funcionario.
     *
     * En la cédula los nombres van en mayúsculas, asi que una palabra en
     * minusculas resta puntos (es el nombre del director o un valor suelto).
     */
    public static function calidadNombre($nombre): int
    {
        $nombre = trim((string)$nombre);
        if ($nombre === '') return -100;

        $palabras = preg_split('/\s+/u', $nombre, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$palabras) return -100;

        $puntaje = 0;
        foreach ($palabras as $palabra) {
            $normalizado = self::normalizarTexto($palabra);
            if (in_array($normalizado, self::conectores(), true)) { $puntaje += 1; continue; }
            if (!self::esTokenNombreValido($palabra)) { $puntaje -= 6; continue; }
            if (preg_match('/^[A-ZÁÉÍÓÚÜÑ\'\.\-]+$/u', $palabra)) $puntaje += 3;
            else $puntaje -= 2;
        }

        // Un nombre completo tiene entre 2 y 4 palabras.
        $cantidad = count($palabras);
        if ($cantidad < 2) $puntaje -= 3;
        if ($cantidad > 4) $puntaje -= 4;

        return $puntaje;
    }

    /**
     * Colapsa palabras repetidas seguidas ("DE DE", "MER VIN MER VIN") que
     * deja el OCR cuando la misma linea se lee dos veces.
     */
    public static function colapsarPalabrasRepetidas(string $texto): string
    {
        $palabras = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $salida = [];
        $anterior = null;
        foreach ($palabras as $palabra) {
            $clave = self::normalizarTexto($palabra);
            if ($clave === $anterior) continue;
            $salida[] = $palabra;
            $anterior = $clave;
        }
        return implode(' ', $salida);
    }

    /** Numero de cedula con el formato que acepta el sistema (7 u 8 digitos). */
    public static function cedulaFormatoAceptable($cedula): bool
    {
        $digitos = preg_replace('/\D/', '', (string)$cedula);
        $largo = strlen($digitos);
        return $largo >= 7 && $largo <= 8 && (int)$digitos > 1000000;
    }

    /**
     * Cuantos numeros de cedula distintos se detectan en el texto.
     * Sirve para detectar capturas de galerias (varias cedulas en pantalla).
     */
    public static function contarNumerosCedula($texto): int
    {
        $t = self::normalizarTexto($texto);
        $encontrados = [];

        if (preg_match_all('/(?<![\d.])(\d{1,3})\.\s?(\d{3})\.\s?(\d{3})(?![\d.])/u', $t, $m)) {
            foreach ($m[0] as $numero) {
                $encontrados[preg_replace('/\D/', '', $numero)] = true;
            }
        }
        if (preg_match_all('/(?<![A-Z0-9])[VEY][\s\x{2013}\x{2014}\-\.]{0,3}(\d[\d\.\s]{5,14})/u', $t, $m2)) {
            foreach ($m2[1] as $numero) {
                $digitos = preg_replace('/\D/', '', $numero);
                if (self::cedulaFormatoAceptable($digitos)) {
                    $encontrados[substr($digitos, 0, 8)] = true;
                }
            }
        }

        return count($encontrados);
    }

    /**
     * ¿La imagen es una captura de pantalla de una galeria de cedulas (por
     * ejemplo, el resultado de una busqueda en internet) y no una cedula
     * suelta?
     *
     * En ese caso el OCR devuelve datos de varias personas mezclados: rellenar
     * el formulario con ellos seria dar de alta a alguien con la identidad de
     * otro. El sistema debe pedir una foto de un solo documento.
     */
    public static function esCapturaDeGaleria($texto): bool
    {
        $t = self::normalizarTexto($texto);
        if ($t === '') return false;

        $marcas = [
            'GOOGLE', 'BING', 'BUSQUEDA', 'IMAGENES', 'MAS RESULTADOS',
            'COPIAR', 'GUARDAR', 'DESCARGAR', 'ELEMENTOS DE SEGURIDAD',
            'DISEÑO', 'DISENO', 'FORMATO', 'MUESTRA', 'ANTIGUA', 'VIGENTE',
        ];
        foreach ($marcas as $marca) {
            if (mb_strpos($t, $marca) !== false) return true;
        }

        // Varias cedulas distintas en el mismo texto: es una galeria.
        if (self::contarNumerosCedula($texto) >= 2) return true;

        return false;
    }
}
