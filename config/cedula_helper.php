<?php
// config/cedula_helper.php
// Validacion NATIVA de cedula venezolana (sin OCR ni Tesseract).
//
// Reglas:
//   La cedula de identidad venezolana NO trae digito verificador. El patron
//   3,2,7,6,5,4,3,2 con residuo mod 10 es el algoritmo de Colombia: aplicado
//   a las cedulas reales de la base de datos rechazaba el 100% de ellas
//   (y ninguna combinacion de pesos mod 10 explica los datos), por lo que
//   se elimino. La validacion se limita a:
//
//   1. Formato: prefijo opcional V/E + 7 u 8 digitos (V-12345678, 12345678).
//   2. La validacion real es la base de datos: en altas/ediciones se
//      comprueba ademas que la cedula exista (persona) o que no este ya
//      registrada (duplicados); en consultas (login, busquedas,
//      recuperacion) basta el formato.
//
// Alcance por punto de entrada:
//   - Altas y ediciones de identidad: cedula_formato_valido($v) + existencia
//     en la BD / duplicados en cada controlador.
//   - Consultas (login, busquedas, recuperacion): cedula_formato_valido($v).
//
// No requiere ninguna extension mas alla de PHP base.

if (!function_exists('cedula_normalizar')) {

    /**
     * Normaliza una cedula: mayusculas, sin guiones, espacios ni puntos.
     * "v-12.345.678" -> "V12345678". Si no hay prefijo, solo quedan digitos.
     */
    function cedula_normalizar($valor)
    {
        $texto = strtoupper(trim((string)$valor));
        $texto = preg_replace('/[^VE0-9]/', '', $texto);

        // Un prefijo solo vale si esta al principio y le siguen digitos.
        if (strlen($texto) > 1 && ($texto[0] === 'V' || $texto[0] === 'E')) {
            return $texto[0] . preg_replace('/\D/', '', substr($texto, 1));
        }
        return preg_replace('/\D/', '', $texto);
    }

    /** Solo los digitos de la cedula ("V12345678" -> "12345678"). */
    function cedula_digitos($valor)
    {
        $normal = cedula_normalizar($valor);
        if ($normal !== '' && ($normal[0] === 'V' || $normal[0] === 'E')) {
            return substr($normal, 1);
        }
        return $normal;
    }

    /** Formato correcto: V/E opcional + 7 u 8 digitos. */
    function cedula_formato_valido($valor)
    {
        return (bool)preg_match('/^[VE]?\d{7,8}$/', cedula_normalizar($valor));
    }
}
