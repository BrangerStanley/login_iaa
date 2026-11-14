<?php
require_once __DIR__ . '/../config/cedula_helper.php';

// models/ValidacionModel.php — comprobaciones de cédula y datos de persona para el registro.
class ValidacionModel
{
    private $conexion;

    public function __construct($conexion)
    {
        $this->conexion = $conexion;
    }

    public function existeUsuario(string $cedula): bool
    {
        $sql = "SELECT id_usuario FROM usuario
                WHERE regexp_replace(upper(ci_usuario), '[^0-9]', '', 'g') = ?
                LIMIT 1";
        $stmt = $this->conexion->prepare($sql);
        $digitos = cedula_digitos($cedula);
        $stmt->bind_param("s", $digitos);
        $stmt->execute();
        $existe = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $existe;
    }

    /**
     * Datos básicos de la persona (si existe) para precargar el registro.
     */
    public function getPersonaDatos(string $cedula): ?array
    {
        $sql = "SELECT p.nombre, p.id_persona, p.fecha_nacimiento, p.sexo,
                       p.apellidos, p.nombres, p.nacionalidad, p.telefono, p.correo
                  FROM persona p
                 WHERE regexp_replace(upper(p.ci_usuario), '[^0-9]', '', 'g') = ?
                 LIMIT 1";
        $stmt = $this->conexion->prepare($sql);
        $digitos = cedula_digitos($cedula);
        $stmt->bind_param("s", $digitos);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    /**
     * Clasificación simplificada para autoregistro directo (sin tablas institucionales).
     */
    public function clasificarPersona(string $cedula): array
    {
        if ($this->existeUsuario($cedula)) {
            return [
                'tipo'       => 'con_cuenta',
                'etiqueta'   => 'Con cuenta',
                'afiliado'   => false,
                'con_cuenta' => true,
            ];
        }
        $persona = $this->getPersonaDatos($cedula);
        if ($persona) {
            return [
                'tipo'       => 'particular',
                'etiqueta'   => 'Particular (no afiliado)',
                'afiliado'   => false,
                'con_cuenta' => false,
            ];
        }
        return [
            'tipo'       => 'sin_registro',
            'etiqueta'   => 'No registrado (no afiliado)',
            'afiliado'   => false,
            'con_cuenta' => false,
        ];
    }

    /**
     * Guarda la lectura OCR en persona (evidencia de auditoría). No pisa datos oficiales existentes.
     */
    public function guardarLecturaOcr(int $idPersona, array $campos, string $textoCrudo = ''): bool
    {
        if ($idPersona <= 0) return false;

        $apellidos = trim((string) ($campos['apellidos'] ?? ''));
        $nombres   = trim((string) ($campos['nombres'] ?? ''));
        $apellidos = mb_substr(preg_replace('/\s+/u', ' ', $apellidos) ?? '', 0, 150, 'UTF-8');
        $nombres   = mb_substr(preg_replace('/\s+/u', ' ', $nombres) ?? '', 0, 150, 'UTF-8');

        if ($textoCrudo === '') {
            $textoCrudo = (string) ($campos['texto'] ?? '');
        }
        $textoCrudo = mb_substr($textoCrudo, 0, 20000, 'UTF-8');

        $json = json_encode($campos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) $json = '{}';
        $json = mb_substr($json, 0, 30000, 'UTF-8');

        $sql = "UPDATE persona
                   SET apellidos    = COALESCE(NULLIF(TRIM(apellidos), ''), NULLIF(?, '')),
                       nombres      = COALESCE(NULLIF(TRIM(nombres), ''), NULLIF(?, '')),
                       ocr_texto    = ?,
                       ocr_datos    = ?,
                       ocr_leido_at = NOW()
                 WHERE id_persona = ?";
        $stmt = $this->conexion->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('ssssi', $apellidos, $nombres, $textoCrudo, $json, $idPersona);
        $ok = $stmt->execute();
        if (!$ok) {
            error_log('ValidacionModel::guardarLecturaOcr: ' . ($stmt->error ?? 'error desconocido'));
        }
        $stmt->close();
        return (bool) $ok;
    }

    public function getRegistrosPrevios(int $idPersona, string $ciActual): array
    {
        // No hay módulo de beneficiarios en este demo.
        return [];
    }
}
