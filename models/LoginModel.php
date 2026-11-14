<?php
// models/LoginModel.php
// Adapta el login al esquema normalizado: usuario + persona + permiso (vía usuario_permiso).
class LoginModel {
    private $conexion;

    public function __construct($conexion) {
        $this->conexion = $conexion;
    }

    /**
     * Retorna los datos del usuario junto con el id_permiso más alto (rol)
     * o null si la cédula no existe.
     */
    public function verificarCredenciales(string $ci_user): ?array
    {
        $sql = "SELECT u.id_usuario, u.ci_usuario, u.contrasena, r.nombre AS rol, u.estado,
                       p.nombre AS nombre_completo,
                       p.correo,
                       COALESCE(up.id_permiso, 0) AS id_permiso
                FROM usuario u
                INNER JOIN persona p ON u.id_persona = p.id_persona
                INNER JOIN rol r ON r.id = u.id_rol
                LEFT JOIN usuario_permiso up ON u.id_usuario = up.id_usuario
                WHERE u.ci_usuario = ?
                ORDER BY up.id_permiso DESC
                LIMIT 1";

        $stmt = $this->conexion->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param("s", $ci_user);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $fila = $resultado->fetch_assoc();
        $stmt->close();
        return $fila;
    }

    /**
     * Registra el evento de ingreso en la tabla logeo.
     * Devuelve el id_logeo insertado (>0) o 0 en caso de fallo.
     */
    public function registrarLogeo(
        int $id_user,
        string $ci_user,
        string $nombre_user,
        string $dir_ip,
        string $agent_u,
        string $token_sesion,
        string $estado
    ): int {
        $sql = "INSERT INTO logeo (id_user, ci_user, nombre_user, f_ing, dir_ip, agent_u, token_sesion, estado)
                VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)";
        $stmt = $this->conexion->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("issssss", $id_user, $ci_user, $nombre_user, $dir_ip, $agent_u, $token_sesion, $estado);
        $result = $stmt->execute();
        $insertId = (int)$this->conexion->insert_id;
        $stmt->close();
        return ($result && $insertId > 0) ? $insertId : 0;
    }

    /**
     * Marca la salida en la tabla logeo.
     */
    public function registrarSalida(string $token_sesion): bool
    {
        $estado_nuevo = 'inactivo';
        $sql = "UPDATE logeo SET f_sal = NOW(), estado = ? WHERE token_sesion = ?";
        $stmt = $this->conexion->prepare($sql);
        $stmt->bind_param("ss", $estado_nuevo, $token_sesion);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}
