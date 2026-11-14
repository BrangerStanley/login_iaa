<?php
// models/UsuarioRolModel.php
//
// ROLES MÚLTIPLES POR CUENTA.
//
// Antes, un usuario tenía un solo rol (usuario.id_rol). Desde la migración
// database/migracion_roles_multiples.sql una cuenta puede tener varios
// roles simultáneos (p. ej. MEDICO + PARTICULAR: el mismo doctor atiende
// su agenda profesional y además agenda sus propias citas como paciente).
//
// Convenciones que se mantienen:
//   - usuario.id_rol sigue siendo el ROL PRINCIPAL (por defecto al entrar).
//   - $_SESSION['per_id'] / $_SESSION['id_rol'] siguen siendo el ROL ACTIVO
//     (con qué "sombrero" está navegando el usuario en este momento).
//     Todos los requireRol()/exigirProteccion() ya leen de ahí, así que al
//     cambiar el rol activo cambian las vistas a las que accede.
//
require_once __DIR__ . '/../config/Conexion.php';

class UsuarioRolModel {
    private $db;

    public function __construct($conexion = null) {
        $this->db = $conexion ?? ($GLOBALS['conexion'] ?? null);
    }

    public function obtenerUsuariosConRol(): array
    {
        $resultado = $this->db->query(
            "SELECT u.id_usuario AS id_user,
                    u.ci_usuario AS ci_user,
                    r.nombre AS rol,
                    u.id_rol AS rol_id,
                    u.estado,
                    p.nombre AS nombre_user,
                    CASE WHEN u.estado = 'activo' THEN 1 ELSE 0 END AS es_activo
             FROM usuario u
             INNER JOIN persona p ON p.id_persona = u.id_persona
             INNER JOIN rol r ON r.id = u.id_rol
             ORDER BY u.id_usuario DESC"
        );

        if ($resultado === false) {
            throw new RuntimeException('Error al consultar el listado de usuarios: ' . $this->db->error);
        }

        return $resultado->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Todos los roles de una cuenta: [{id, nombre}].
     * Si la pivote no tiene filas (cuenta vieja aún sin migrar/sembrar),
     * devuelve el rol principal de usuario.id_rol para no dejar al usuario
     * sin accesos.
     */
    public function rolesDe($id_usuario) {
        $id_usuario = intval($id_usuario);
        $roles = [];

        if ($id_usuario > 0 && $this->db) {
            $stmt = $this->db->prepare(
                "SELECT r.id, r.nombre
                 FROM usuario_rol ur
                 INNER JOIN rol r ON r.id = ur.id_rol
                 WHERE ur.id_usuario = ?
                 ORDER BY r.id ASC"
            );
            if ($stmt) {
                $stmt->bind_param("i", $id_usuario);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) {
                    $roles[] = ['id' => (int)$fila['id'], 'nombre' => (string)$fila['nombre']];
                }
                $stmt->close();
            }
        }

        if (empty($roles) && $this->db) {
            $stmt = $this->db->prepare(
                "SELECT r.id, r.nombre
                 FROM usuario u
                 INNER JOIN rol r ON r.id = u.id_rol
                 WHERE u.id_usuario = ? LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param("i", $id_usuario);
                $stmt->execute();
                $fila = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($fila) {
                    $roles[] = ['id' => (int)$fila['id'], 'nombre' => (string)$fila['nombre']];
                }
            }
        }
        return $roles;
    }

    /** ¿La cuenta tiene este rol? */
    public function tieneRol($id_usuario, $id_rol) {
        foreach ($this->rolesDe($id_usuario) as $rol) {
            if ((int)$rol['id'] === (int)$id_rol) {
                return true;
            }
        }
        return false;
    }

    /** Id del rol principal (usuario.id_rol). */
    public function rolPrincipal($id_usuario) {
        $id = intval($id_usuario);
        $stmt = $this->db->prepare("SELECT id_rol FROM usuario WHERE id_usuario = ? LIMIT 1");
        if (!$stmt) return 0;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($fila['id_rol'] ?? 0);
    }

    /**
     * Asigna un rol adicional a la cuenta (idempotente: no duplica).
     * No toca usuario.id_rol: eso es el rol principal.
     */
    public function asignar($id_usuario, $id_rol) {
        $id_usuario = intval($id_usuario);
        $id_rol = intval($id_rol);
        if ($id_usuario <= 0 || $id_rol <= 0) return false;
        if ($this->tieneRol($id_usuario, $id_rol)) return true;

        // Ya se verificó existencia arriba (tieneRol), así que un INSERT
        // directo es seguro y evita extensiones MySQL no soportadas por la
        // capa PG (INSERT IGNORE).
        $stmt = $this->db->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $id_usuario, $id_rol);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Sincroniza la pivote con el rol de una cuenta: garantiza que el rol
     * principal esté en usuario_rol. Se usa en creación/actualización de
     * usuarios para que la pivote nunca quede desalineada.
     */
    public function sincronizarPrincipal($id_usuario) {
        $principal = $this->rolPrincipal($id_usuario);
        if ($principal > 0) {
            $this->asignar($id_usuario, $principal);
        }
    }

    /**
     * Quita un rol de la pivote (sin tocar el rol principal).
     * Devuelve false si se intenta quitar el rol principal: ese solo cambia
     * actualizando usuario.id_rol.
     */
    public function quitar($id_usuario, $id_rol) {
        $id_usuario = intval($id_usuario);
        $id_rol = intval($id_rol);
        if ($id_usuario <= 0 || $id_rol <= 0 || $this->rolPrincipal($id_usuario) === $id_rol) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM usuario_rol WHERE id_usuario = ? AND id_rol = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $id_usuario, $id_rol);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
