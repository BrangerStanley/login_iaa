<?php
require_once __DIR__ . '/../config/Conexion.php';

class PermisoModel {
    private $db;

    public function __construct() {
        global $conexion;
        $this->db = $conexion;
    }

    // Consulta los permisos granulares del sistema (tabla permiso).
    // Ojo: no son los roles del ENUM usuario.rol; ver obtenerRolesSistema().
    public function obtenerRoles() {
        $sql = "SELECT id, nombre, descripcion FROM permiso ORDER BY id ASC";
        $resultado = $this->db->query($sql);
        if (!$resultado) {
            return [];
        }
        return $resultado->fetch_all(MYSQLI_ASSOC);
    }

    // Identificador numérico por posición del ENUM usuario.rol.
    // Coincide con el mapa histórico usado por el login (funcionario=1,
    // moderador=3, supervisor=4, administrador=5, medico=6, particular=7).
    // Los roles nuevos se agregan SIEMPRE al final del ENUM para no
    // alterar la posición (y por tanto el id) de los existentes.
    // Al renombrar un rol se conserva su posición, por lo que el id de
    // autorización no cambia. El id 2 queda reservado.
    public const ROL_IDS_POR_POSICION = [1, 3, 4, 5, 6, 7];

    // Roles reales del sistema: filas de la tabla `rol`
    // (diferentes de los permisos granulares de la tabla permiso).
    // En PostgreSQL el rol es relacional: usuario.id_rol -> rol.id.
    public function obtenerRolesSistema() {
        $roles = [];
        $resultado = $this->db->query("SELECT id, nombre FROM rol ORDER BY id ASC");
        if ($resultado) {
            while ($fila = $resultado->fetch_assoc()) {
                $roles[] = [
                    'id'     => (int)$fila['id'],
                    'nombre' => (string)$fila['nombre']
                ];
            }
        }
        return $roles;
    }

    // Id numérico del rol por su nombre (o 0 si no existe).
    public function rolIdPorNombre($nombre): int {
        $nombre = trim((string)$nombre);
        if ($nombre === '') {
            return 0;
        }
        $stmt = $this->db->prepare("SELECT id FROM rol WHERE lower(nombre) = lower(?) LIMIT 1");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("s", $nombre);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $fila ? (int)$fila['id'] : 0;
    }

    // Nombre del rol para un id (o null si no existe).
    public function nombreRolPorId(int $idRol): ?string {
        if ($idRol <= 0) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT nombre FROM rol WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $idRol);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $fila ? (string)$fila['nombre'] : null;
    }

    // ========================================================
    // MAPA ROL -> PERMISOS (tabla rol_permiso)
    // ========================================================

    // Permisos por defecto de cada rol, por NOMBRE de permiso (fuente única:
    // database/permisos_canonicos.sql). Se resuelven a ids en runtime con
    // permisosPorDefectoIds(), así no dependen del orden de inserción de la
    // tabla permiso ni de los ids posicionales.
    public const PERMISOS_POR_ROL_DEFECTO_NOMBRES = [
        // funcionario
        1 => ['ver_perfil', 'editar_perfil', 'gestionar_beneficiarios', 'solicitar_servicio', 'ver_citas'],
        // moderador
        3 => ['ver_perfil', 'editar_perfil', 'gestionar_beneficiarios', 'subir_documentos', 'solicitar_servicio',
              'ver_citas', 'agendar_cita', 'aprobar_solicitudes', 'enviar_notificaciones', 'ver_bitacora',
              'ver_morbilidad'],
        // supervisor (control financiero: sin solicitudes, citas ni herramientas)
        4 => ['ver_perfil', 'editar_perfil', 'gestionar_beneficiarios', 'subir_documentos', 'solicitar_servicio',
              'gestionar_pagos', 'ver_morbilidad'],
        // administrador (todos)
        5 => ['ver_perfil', 'editar_perfil', 'gestionar_beneficiarios', 'subir_documentos', 'solicitar_servicio',
              'ver_citas', 'agendar_cita', 'aprobar_solicitudes', 'gestionar_pagos', 'administrar_usuarios',
              'ver_bitacora', 'configurar_servicios', 'configurar_institucional', 'configurar_territorial',
              'enviar_notificaciones', 'mantenimiento_bd', 'gestionar_patologias', 'ver_morbilidad'],
        // medico (ver/editar perfil, citas, agendar, solicitudes, bitacora)
        6 => ['ver_perfil', 'editar_perfil', 'ver_citas', 'agendar_cita', 'aprobar_solicitudes', 'ver_bitacora'],
        // particular (igual que funcionario)
        7 => ['ver_perfil', 'editar_perfil', 'gestionar_beneficiarios', 'solicitar_servicio', 'ver_citas'],
    ];

    // Nombre de permiso => id instalado en la tabla permiso.
    public function idsPorNombre(): array {
        $mapa = [];
        $resultado = $this->db->query("SELECT id, nombre FROM permiso");
        if ($resultado) {
            while ($fila = $resultado->fetch_assoc()) {
                $mapa[(string)$fila['nombre']] = (int)$fila['id'];
            }
        }
        return $mapa;
    }

    // Id instalado de un permiso por nombre (0 si no existe en la BD).
    public function idPorNombre(string $nombre): int {
        $mapa = $this->idsPorNombre();
        return $mapa[$nombre] ?? 0;
    }

    // Permisos por defecto resueltos a ids: id_rol => [id_permiso, ...].
    // Los permisos del catálogo que no estén instalados simplemente se omiten.
    public function permisosPorDefectoIds(): array {
        $ids = $this->idsPorNombre();
        $mapa = [];
        foreach (self::PERMISOS_POR_ROL_DEFECTO_NOMBRES as $idRol => $nombres) {
            foreach ($nombres as $nombre) {
                if (isset($ids[$nombre])) {
                    $mapa[$idRol][] = $ids[$nombre];
                }
            }
        }
        return $mapa;
    }

    // Mapa completo: id_rol => [id_permiso, ...].
    public function mapaPermisosIds(): array {
        $mapa = [];
        $resultado = $this->db->query("SELECT id_rol, id_permiso FROM rol_permiso");
        if (!$resultado) {
            return $mapa;
        }
        while ($fila = $resultado->fetch_assoc()) {
            $mapa[(int)$fila['id_rol']][] = (int)$fila['id_permiso'];
        }
        return $mapa;
    }

    // Permisos de un solo rol: [id_permiso, ...].
    public function permisosDeRol(int $idRol): array {
        $stmt = $this->db->prepare("SELECT id_permiso FROM rol_permiso WHERE id_rol = ?");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $idRol);
        $stmt->execute();
        $ids = [];
        $resultado = $stmt->get_result();
        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['id_permiso'];
        }
        $stmt->close();
        return $ids;
    }

    // Ids de permisos validos existentes en la tabla permiso.
    public function idsPermisosValidos(): array {
        $resultado = $this->db->query("SELECT id FROM permiso");
        $ids = [];
        if ($resultado) {
            while ($fila = $resultado->fetch_assoc()) {
                $ids[] = (int)$fila['id'];
            }
        }
        return $ids;
    }

    // Guarda los permisos de un rol (borra e inserta, en transaccion).
    public function guardarPermisosRol(int $idRol, array $idsPermisos): bool {
        if ($idRol <= 0) {
            return false;
        }
        $validos = $this->idsPermisosValidos();
        $aGuardar = array_values(array_unique(array_map('intval', $idsPermisos)));
        $aGuardar = array_values(array_intersect($aGuardar, $validos));

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM rol_permiso WHERE id_rol = ?");
            if (!$stmt) {
                throw new Exception('No se pudo preparar la limpieza de permisos del rol.');
            }
            $stmt->bind_param("i", $idRol);
            $stmt->execute();
            $stmt->close();

            if ($aGuardar) {
                $stmt = $this->db->prepare("INSERT INTO rol_permiso (id_rol, id_permiso) VALUES (?, ?)");
                if (!$stmt) {
                    throw new Exception('No se pudo preparar el registro de permisos del rol.');
                }
                foreach ($aGuardar as $idPermiso) {
                    $stmt->bind_param("ii", $idRol, $idPermiso);
                    $stmt->execute();
                }
                $stmt->close();
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('PermisoModel::guardarPermisosRol: ' . $e->getMessage());
            return false;
        }
    }

    // Copia los permisos del rol a un usuario (usuario_permiso).
    public function asignarPermisosPorRol(int $idUsuario, int $idRol): bool {
        if ($idUsuario <= 0) {
            return false;
        }
        $ids = $this->permisosDeRol($idRol);

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM usuario_permiso WHERE id_usuario = ?");
            if (!$stmt) {
                throw new Exception('No se pudo limpiar los permisos del usuario.');
            }
            $stmt->bind_param("i", $idUsuario);
            $stmt->execute();
            $stmt->close();

            if ($ids) {
                $stmt = $this->db->prepare("INSERT INTO usuario_permiso (id_usuario, id_permiso) VALUES (?, ?)");
                if (!$stmt) {
                    throw new Exception('No se pudo registrar los permisos del usuario.');
                }
                foreach ($ids as $idPermiso) {
                    $stmt->bind_param("ii", $idUsuario, $idPermiso);
                    $stmt->execute();
                }
                $stmt->close();
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('PermisoModel::asignarPermisosPorRol: ' . $e->getMessage());
            return false;
        }
    }

    // Re-aplica la semilla de permisos por defecto a todos los roles.
    public function restablecerPermisosPorDefecto(): bool {
        return $this->restablecerPermisosDeRoles(array_keys(self::PERMISOS_POR_ROL_DEFECTO_NOMBRES));
    }

    // Re-aplica los permisos por defecto SOLO a los roles indicados
    // (la UI permite restablecer un rol concreto sin tocar los demás).
    public function restablecerPermisosDeRoles(array $idsRoles): bool {
        $porDefecto = $this->permisosPorDefectoIds();
        $this->db->begin_transaction();
        try {
            foreach ($idsRoles as $idRol) {
                $idRol = (int)$idRol;
                if ($idRol <= 0) {
                    continue;
                }
                $stmt = $this->db->prepare("DELETE FROM rol_permiso WHERE id_rol = ?");
                if (!$stmt) {
                    throw new Exception('No se pudieron limpiar los permisos del rol.');
                }
                $stmt->bind_param("i", $idRol);
                $stmt->execute();
                $stmt->close();

                if (!empty($porDefecto[$idRol])) {
                    $stmt = $this->db->prepare("INSERT INTO rol_permiso (id_rol, id_permiso) VALUES (?, ?)");
                    if (!$stmt) {
                        throw new Exception('No se pudieron insertar los permisos por defecto.');
                    }
                    foreach ($porDefecto[$idRol] as $idPermiso) {
                        $stmt->bind_param("ii", $idRol, $idPermiso);
                        $stmt->execute();
                    }
                    $stmt->close();
                }
            }
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('PermisoModel::restablecerPermisosDeRoles: ' . $e->getMessage());
            return false;
        }
    }

    // Renombra un rol real. En PostgreSQL el rol es una fila de la tabla `rol`,
    // así que renombrar es un UPDATE: se conserva el id y, por tanto, la
    // autorización de todos los usuarios que lo tienen asignado.
    public function renombrarRol(int $id_rol, string $nuevoNombre): bool {
        $nuevoNombre = trim($nuevoNombre);
        if ($nuevoNombre === '' || $id_rol <= 0) {
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9 _áéíóúüñÁÉÍÓÚÜÑ-]+$/', $nuevoNombre)) {
            return false;
        }

        // Evitar duplicados (el nombre no distingue mayúsculas/minúsculas).
        $stmt = $this->db->prepare("SELECT id FROM rol WHERE lower(nombre) = lower(?) AND id <> ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("si", $nuevoNombre, $id_rol);
        $stmt->execute();
        $duplicado = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($duplicado) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE rol SET nombre = ? WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("si", $nuevoNombre, $id_rol);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    // Nombres de los roles instalados, en orden de id.
    private function opcionesEnumRol(): array {
        $roles = [];
        $resultado = $this->db->query("SELECT nombre FROM rol ORDER BY id ASC");
        if ($resultado) {
            while ($fila = $resultado->fetch_assoc()) {
                $roles[] = (string)$fila['nombre'];
            }
        }
        return $roles;
    }

    // Asigna un rol a un usuario (insert en usuario_permiso)
    public function asignarRolUsuario($id_usuario, $id_permiso) {
        // Verificar si ya tiene asignado este rol
        $sql = "SELECT id_usuario FROM usuario_permiso WHERE id_usuario = ? AND id_permiso = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $id_usuario, $id_permiso);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            // Ya existe, no hay nada que actualizar (PK compuesta)
            $stmt->close();
            return true;
        } else {
            // Insertar nuevo
            $stmt->close();
            $sql = "INSERT INTO usuario_permiso (id_usuario, id_permiso) VALUES (?, ?)";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("ii", $id_usuario, $id_permiso);
            $ejecutado = $stmt->execute();
            $stmt->close();
            return $ejecutado;
        }
    }

    // Actualiza el rol de un usuario
    public function actualizarRolUsuario($id_usuario, $nuevo_id_permiso) {
        // Primero removemos roles antiguos
        $sql = "DELETE FROM usuario_permiso WHERE id_usuario = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $stmt->close();
        
        // Asignamos el nuevo rol
        return $this->asignarRolUsuario($id_usuario, $nuevo_id_permiso);
    }
}
?>