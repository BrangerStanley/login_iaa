<?php
class NotificacionModel {
    private $conexion;

    public function __construct($conexion) {
        $this->conexion = $conexion;
    }

    public function obtenerPorUsuario($id_usuario) {
        $sql = "SELECT * FROM notificacion 
                WHERE id_usuario = ? 
                ORDER BY fecha_creacion DESC";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Notificaciones del ÁMBITO MÉDICO (Centro de Notificaciones Médicas):
     * tipos 'medico' y avisos de citas ('cita%'). Sin los avisos generales
     * de cuenta/seguridad, que viven en el centro de notificaciones normal.
     */
    public function obtenerMedicasDeUsuario($id_usuario) {
        $sql = "SELECT * FROM notificacion
                WHERE id_usuario = ?
                  AND (tipo = 'medico' OR tipo LIKE 'cita%')
                ORDER BY fecha_creacion DESC";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function contarNoLeidasMedicas($id_usuario) {
        $sql = "SELECT COUNT(*) as total FROM notificacion
                WHERE id_usuario = ? AND leida = false
                  AND (tipo = 'medico' OR tipo LIKE 'cita%')";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int)($result['total'] ?? 0);
    }

    public function contarNoLeidas($id_usuario) {
        $sql = "SELECT COUNT(*) as total FROM notificacion WHERE id_usuario = ? AND leida = false";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['total'] ?? 0;
    }

    public function marcarComoLeidas($id_usuario) {
        $sql = "UPDATE notificacion SET leida = true WHERE id_usuario = ? AND leida = false";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("i", $id_usuario);
        return $stmt->execute();
    }

    public function marcarUnaComoLeida($id_notificacion, $id_usuario) {
        $sql = "UPDATE notificacion SET leida = true WHERE id_notificacion = ? AND id_usuario = ?";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("ii", $id_notificacion, $id_usuario);
        return $stmt->execute();
    }

    public function crear($id_usuario, $titulo, $mensaje, $tipo) {
        $sql = "INSERT INTO notificacion (id_usuario, titulo, mensaje, tipo) VALUES (?, ?, ?, ?)";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("isss", $id_usuario, $titulo, $mensaje, $tipo);
        if ($stmt->execute()) {
            return $this->conexion->insert_id;
        }
        return null;
    }

    public function eliminar($id_notificacion, $id_usuario) {
        $sql = "DELETE FROM notificacion WHERE id_notificacion = ? AND id_usuario = ?";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("ii", $id_notificacion, $id_usuario);
        return $stmt->execute();
    }

    public function eliminarTodas($id_usuario) {
        $sql = "DELETE FROM notificacion WHERE id_usuario = ?";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("i", $id_usuario);
        return $stmt->execute();
    }

    public function obtenerPorId($id_notificacion, $id_usuario) {
        $sql = "SELECT * FROM notificacion WHERE id_notificacion = ? AND id_usuario = ?";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("ii", $id_notificacion, $id_usuario);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Registra una notificación de forma estática (compatibilidad con llamadas
     * NotificacionModel::registrar($conexion, $ci_o_id, ...)).
     * Acepta id_usuario numérico o ci_usuario; resuelve el id si es cédula.
     */
    public static function registrar($conexion, $id_usuario_o_ci, $titulo, $mensaje, $tipo = 'general') {
        if (!$conexion) {
            return null;
        }
        $destino = trim((string)$id_usuario_o_ci);
        if ($destino === '') {
            return null;
        }
        $id_usuario = 0;
        if (ctype_digit($destino)) {
            $candidato = (int)$destino;
            $stmt = $conexion->prepare(
                "SELECT id_usuario FROM usuario WHERE id_usuario = ? LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param("i", $candidato);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $id_usuario = (int)($row['id_usuario'] ?? 0);
            }
        }
        if ($id_usuario <= 0) {
            $stmt = $conexion->prepare(
                "SELECT id_usuario FROM usuario WHERE ci_usuario = ? LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("s", $destino);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $id_usuario = (int)($row['id_usuario'] ?? 0);
        }
        if ($id_usuario <= 0) {
            return null;
        }
        try {
            $stmt = $conexion->prepare(
                "INSERT INTO notificacion (id_usuario, titulo, mensaje, tipo) VALUES (?, ?, ?, ?)"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("isss", $id_usuario, $titulo, $mensaje, $tipo);
            if ($stmt->execute()) {
                $id = $conexion->insert_id;
                $stmt->close();
                return $id;
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            error_log("NotificacionModel::registrar fallo: " . $e->getMessage());
            return null;
        }
        return null;
    }
}
?>