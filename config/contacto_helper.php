<?php
/**
 * ============================================================================
 * config/contacto_helper.php — Unicidad de los datos de contacto por cuenta
 * ============================================================================
 *
 * Regla de negocio: un correo o un teléfono no puede quedar vinculado a más de
 * una cuenta de usuario. La fuente de verdad del contacto es `persona`
 * (`correo` y `telefono`), por lo que la comprobación cruza `usuario` con
 * `persona`.
 *
 * Se compara:
 *   - correo: sin espacios y en minúsculas.
 *   - teléfono: solo dígitos (ignora guiones, espacios y paréntesis).
 *
 * La función permite excluir al propio usuario/persona para que una
 * actualización de perfil no choque contra sus propios datos.
 */

if (!function_exists('contacto_normalizar_correo')) {
    function contacto_normalizar_correo($correo): string
    {
        return mb_strtolower(trim((string)$correo), 'UTF-8');
    }
}

if (!function_exists('contacto_normalizar_telefono')) {
    function contacto_normalizar_telefono($telefono): string
    {
        return preg_replace('/\D/', '', (string)$telefono) ?? '';
    }
}

/**
 * Busca si el correo o el teléfono indicados ya pertenecen a otra cuenta.
 *
 * @param IaaConnection|mysqli $conexion
 * @param string $correo
 * @param string $telefono
 * @param int    $excluirIdUsuario  Cuenta que se está editando (0 = ninguna).
 * @param int    $excluirIdPersona  Persona que se está editando (0 = ninguna).
 *
 * @return array{motivo:string,id_usuario:int,id_persona:int}|null
 *         null si el contacto está libre; si hay conflicto, el motivo puede
 *         ser 'correo' o 'telefono'.
 */
function contacto_cuenta_vinculada($conexion, $correo, $telefono, int $excluirIdUsuario = 0, int $excluirIdPersona = 0): ?array
{
    $correoNorm = contacto_normalizar_correo($correo);
    $telefonoNorm = contacto_normalizar_telefono($telefono);

    if ($correoNorm === '' && $telefonoNorm === '') {
        return null;
    }

    $sql = "SELECT u.id_usuario, u.id_persona,
                   CASE
                       WHEN ? <> '' AND lower(trim(COALESCE(p.correo, ''))) = ? THEN 'correo'
                       ELSE 'telefono'
                   END AS motivo
              FROM usuario u
              INNER JOIN persona p ON p.id_persona = u.id_persona
             WHERE u.id_usuario <> ?
               AND u.id_persona <> ?
               AND (
                    (? <> '' AND lower(trim(COALESCE(p.correo, ''))) = ?)
                 OR (? <> '' AND regexp_replace(COALESCE(p.telefono, ''), '[^0-9]', '', 'g') = ?)
               )
             LIMIT 1";

    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        // Ante un fallo de preparación no se bloquea el flujo por sí solo:
        // cada controlador decide cómo tratarlo. Se registra para auditoría.
        error_log('contacto_cuenta_vinculada: no se pudo preparar la consulta');
        return null;
    }

    $stmt->bind_param(
        'ssiissss',
        $correoNorm, $correoNorm,
        $excluirIdUsuario, $excluirIdPersona,
        $correoNorm, $correoNorm,
        $telefonoNorm, $telefonoNorm
    );
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$fila) {
        return null;
    }

    return [
        'motivo'      => (string)($fila['motivo'] ?? 'contacto'),
        'id_usuario'  => (int)($fila['id_usuario'] ?? 0),
        'id_persona'  => (int)($fila['id_persona'] ?? 0),
    ];
}

if (!function_exists('contacto_mensaje_duplicado')) {
    function contacto_mensaje_duplicado(array $conflicto): string
    {
        return ($conflicto['motivo'] ?? '') === 'correo'
            ? 'El correo electrónico ya está vinculado a otra cuenta.'
            : 'El número de teléfono ya está vinculado a otra cuenta.';
    }
}
