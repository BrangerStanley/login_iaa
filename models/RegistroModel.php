<?php
require_once __DIR__ . '/../config/cedula_helper.php';

// models/RegistroModel.php — registro directo ACTIVO de cuentas particulares.
class RegistroModel
{
    private $conexion;

    // Permisos mínimos de una cuenta particular.
    public const PERMISOS_PARTICULAR = [1, 2, 3, 5, 6];

    public function __construct($conexion)
    {
        $this->conexion = $conexion;
    }

    private function sexoPersona($valor): ?string
    {
        $v = mb_strtoupper(trim((string) $valor), 'UTF-8');
        if (in_array($v, ['M', 'MASCULINO', 'HOMBRE', 'VARON'], true)) return 'M';
        if (in_array($v, ['F', 'FEMENINO', 'MUJER'], true)) return 'F';
        return null;
    }

    private function jsonOcr(array $datos): string
    {
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) return '';
        return mb_substr($json, 0, 30000, 'UTF-8');
    }

    private function normalizarFecha($fecha): ?string
    {
        $fecha = (string) $fecha;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha)
            || !checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
            return null;
        }
        return $fecha;
    }

    public function crearPersona(string $ci, array $datos): int
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre === '') return 0;

        $sexo = $this->sexoPersona($datos['sexo'] ?? '');
        $telefono = trim((string) ($datos['telefono'] ?? ''));
        $correo = trim((string) ($datos['correo'] ?? ''));
        $nacionalidad = trim((string) ($datos['nacionalidad'] ?? 'Venezolano'));
        $fecha = $this->normalizarFecha($datos['fecha_nacimiento'] ?? '');
        $apellidos = mb_substr(trim((string) ($datos['apellidos'] ?? '')), 0, 150, 'UTF-8');
        $nombres = mb_substr(trim((string) ($datos['nombres'] ?? '')), 0, 150, 'UTF-8');
        $ocrTexto = mb_substr((string) ($datos['ocr_texto'] ?? ''), 0, 20000, 'UTF-8');
        $ocrDatos = (isset($datos['ocr_datos']) && is_array($datos['ocr_datos'])) ? $this->jsonOcr($datos['ocr_datos']) : '';
        $ocrLeidoAt = ($ocrTexto !== '' || $ocrDatos !== '') ? date('Y-m-d H:i:sP') : null;

        $stmt = $this->conexion->prepare(
            "INSERT INTO persona (nombre, ci_usuario, telefono, correo, fecha_nacimiento, sexo, nacionalidad,
                                  apellidos, nombres, ocr_texto, ocr_datos, ocr_leido_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return 0;

        $stmt->bind_param(
            'ssssssssssss',
            $nombre, $ci, $telefono, $correo, $fecha, $sexo, $nacionalidad,
            $apellidos, $nombres, $ocrTexto, $ocrDatos, $ocrLeidoAt
        );
        if (!$stmt->execute()) {
            error_log('RegistroModel::crearPersona: ' . $stmt->error);
            $stmt->close();
            return 0;
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function actualizarPersona(int $idPersona, array $datos): bool
    {
        if ($idPersona <= 0) return false;
        $telefono = trim((string) ($datos['telefono'] ?? ''));
        $correo = trim((string) ($datos['correo'] ?? ''));
        $sexo = $this->sexoPersona($datos['sexo'] ?? '');
        $nacionalidad = trim((string) ($datos['nacionalidad'] ?? ''));
        $fecha = $this->normalizarFecha($datos['fecha_nacimiento'] ?? '');
        $apellidos = mb_substr(trim((string) ($datos['apellidos'] ?? '')), 0, 150, 'UTF-8');
        $nombres = mb_substr(trim((string) ($datos['nombres'] ?? '')), 0, 150, 'UTF-8');
        $ocrTexto = mb_substr((string) ($datos['ocr_texto'] ?? ''), 0, 20000, 'UTF-8');
        $ocrDatos = (isset($datos['ocr_datos']) && is_array($datos['ocr_datos'])) ? $this->jsonOcr($datos['ocr_datos']) : '';

        $stmt = $this->conexion->prepare(
            "UPDATE persona
                SET telefono         = COALESCE(NULLIF(?, ''), telefono),
                    correo           = COALESCE(NULLIF(?, ''), correo),
                    sexo             = COALESCE(NULLIF(?, ''), sexo),
                    fecha_nacimiento = COALESCE(?, fecha_nacimiento),
                    nacionalidad     = COALESCE(NULLIF(?, ''), nacionalidad),
                    apellidos        = COALESCE(NULLIF(TRIM(apellidos), ''), NULLIF(?, '')),
                    nombres          = COALESCE(NULLIF(TRIM(nombres), ''), NULLIF(?, '')),
                    ocr_texto        = COALESCE(NULLIF(?, ''), ocr_texto),
                    ocr_datos        = COALESCE(NULLIF(?, ''), ocr_datos),
                    ocr_leido_at     = CASE WHEN NULLIF(?, '') IS NOT NULL THEN NOW() ELSE ocr_leido_at END
              WHERE id_persona = ?"
        );
        if (!$stmt) return false;
        $stmt->bind_param(
            'ssssssssssi',
            $telefono, $correo, $sexo, $fecha, $nacionalidad,
            $apellidos, $nombres, $ocrTexto, $ocrDatos, $ocrDatos, $idPersona
        );
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }

    /**
     * Crea cuenta particular ACTIVA (registro directo) con evidencia de cédula.
     * Firma compatible con el original: ($ci, $hash, $id_persona, $datosPersona, $evidenciaCedula)
     */
    public function registrarUsuario(
        string $ci,
        string $passwordHash,
        int $id_persona = 0,
        array $datosPersona = [],
        string $evidenciaCedula = ''
    ): bool {
        $this->conexion->begin_transaction();
        try {
            $digitos = cedula_digitos($ci);

            if ($id_persona <= 0) {
                $stmt = $this->conexion->prepare(
                    "SELECT id_persona FROM persona
                      WHERE regexp_replace(upper(ci_usuario), '[^0-9]', '', 'g') = ?
                      LIMIT 1"
                );
                $stmt->bind_param('s', $digitos);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $id_persona = (int) $row['id_persona'];
                }
            }

            if ($id_persona <= 0) {
                $id_persona = $this->crearPersona($ci, $datosPersona);
                if ($id_persona <= 0) { $this->conexion->rollback(); return false; }
            } else {
                $this->actualizarPersona($id_persona, $datosPersona);
            }

            $evidenciaCedula = mb_substr(trim($evidenciaCedula), 0, 255, 'UTF-8');

            $stmtU = $this->conexion->prepare(
                "INSERT INTO usuario (id_persona, ci_usuario, contrasena, id_rol, estado, evidencia_cedula, password_changed_at)
                 VALUES (?, ?, ?, 7, 'activo', ?, NOW())"
            );
            if (!$stmtU) { $this->conexion->rollback(); return false; }
            $stmtU->bind_param('isss', $id_persona, $ci, $passwordHash, $evidenciaCedula);
            if (!$stmtU->execute()) { $this->conexion->rollback(); return false; }
            $idUsuario = (int) $stmtU->insert_id;
            $stmtU->close();

            $stmtR = $this->conexion->prepare(
                "INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, 7) ON CONFLICT DO NOTHING"
            );
            $stmtR->bind_param('i', $idUsuario);
            $stmtR->execute();
            $stmtR->close();

            $stmtP = $this->conexion->prepare(
                "INSERT INTO usuario_permiso (id_usuario, id_permiso) VALUES (?, ?) ON CONFLICT DO NOTHING"
            );
            foreach (self::PERMISOS_PARTICULAR as $pid) {
                $stmtP->bind_param('ii', $idUsuario, $pid);
                $stmtP->execute();
            }
            $stmtP->close();

            $this->conexion->commit();
            return true;
        } catch (Throwable $e) {
            $this->conexion->rollback();
            error_log('RegistroModel::registrarUsuario: ' . $e->getMessage());
            return false;
        }
    }

    public function vincularDocumentosPrevios(int $idUsuario, string $ci): int
    {
        return 0; // no aplica en este demo
    }
}
