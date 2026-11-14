<?php
// models/LogeoModel.php
require_once __DIR__ . '/../config/Conexion.php';

class LogeoModel {
    private $db;

    public function __construct() {
        global $conexion;
        $this->db = $conexion;
    }

    public function obtenerHistorialLogs($limit = 100) {
        $limit = (int)$limit;
        $sql = "SELECT id_logeo, id_user, ci_user, nombre_user, f_ing, f_sal, dir_ip, agent_u, estado, tipo_evento, detalle
                FROM logeo ORDER BY id_logeo DESC LIMIT $limit";
        $result = $this->db->query($sql);
        if (!$result) throw new Exception("Error en la consulta MySQLi: " . $this->db->error);
        $logs = [];
        while ($row = $result->fetch_assoc()) $logs[] = $row;
        return $logs;
    }

    public function obtenerLogs($tipo = 'conexiones', $dia = '', $mes = '', $limit = 100) {
        $limit = (int) $limit;
        $condiciones = []; $params = ''; $valores = [];
        if ($tipo === 'registros') $condiciones[] = "tipo_evento IN ('registro_cuenta', 'intento_validacion')";
        else $condiciones[] = "(tipo_evento IS NULL OR tipo_evento NOT IN ('registro_cuenta', 'intento_validacion'))";
        if (!empty($dia) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
            $condiciones[] = "f_ing::date = ?"; $params .= 's'; $valores[] = $dia;
        } elseif (!empty($mes) && preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $condiciones[] = "to_char(f_ing, 'YYYY-MM') = ?"; $params .= 's'; $valores[] = $mes;
        }
        $sql = "SELECT id_logeo, id_user, ci_user, nombre_user, f_ing, f_sal, dir_ip, agent_u, estado, tipo_evento, detalle FROM logeo";
        if ($condiciones) $sql .= " WHERE " . implode(' AND ', $condiciones);
        $sql .= " ORDER BY id_logeo DESC LIMIT $limit";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        if ($valores) $stmt->bind_param($params, ...$valores);
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        while ($row = $result->fetch_assoc()) $logs[] = $row;
        return $logs;
    }

    public function registrarEvento($id_user, $ci_user, $nombre_user, $tipo_evento, $detalle) {
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 200);
        $token = bin2hex(random_bytes(16));
        $sql = "INSERT INTO logeo (id_user, ci_user, nombre_user, f_ing, f_sal, dir_ip, agent_u, token_sesion, estado, tipo_evento, detalle)
                VALUES (?, ?, ?, NOW(), NULL, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return false;
        $estado = $tipo_evento;
        $stmt->bind_param("issssssss", $id_user, $ci_user, $nombre_user, $ip, $agent, $token, $estado, $tipo_evento, $detalle);
        return $stmt->execute();
    }
}
