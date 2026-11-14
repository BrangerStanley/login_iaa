<?php
// config/permisos_helper.php
// Carga en sesión los permisos del rol actual (tabla rol_permiso).
//
//   $_SESSION['permisos']     => nombres de permiso (array de strings)
//   $_SESSION['permisos_ids'] => ids de permiso (array de ints)
//   $_SESSION['permisos'] === null => sin mapa de permisos disponible
//                                     (migración no aplicada) => NO se restringe.
//   $_SESSION['permisos'] === []   => rol configurado SIN permisos => se bloquea todo.
//
// Se carga como máximo una vez por request; los cambios hechos en la matriz
// de roles se reflejan en el request siguiente (o con $forzar = true).

// Conexión disponible para cargar permisos, incluso en scripts que todavía
// no habían incluido config/Conexion.php (p. ej. guardas al inicio de una vista).
function conexionPermisos()
{
    global $conexion;
    if ($conexion instanceof IaaConnection) {
        return $conexion;
    }
    require_once __DIR__ . '/Conexion.php';
    return ($conexion instanceof IaaConnection) ? $conexion : null;
}

function cargarPermisosSesion($conexion = null, $forzar = false)
{
    static $yaCargo = false;

    if ($yaCargo && !$forzar) {
        return;
    }
    $yaCargo = true;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if ($conexion === null) {
        $conexion = $GLOBALS['conexion'] ?? null;
    }
    if (!($conexion instanceof IaaConnection) || !function_exists('rolActual')) {
        return;
    }

    $resultado = $conexion->query(
        "SELECT rp.id_rol, p.id, p.nombre
           FROM rol_permiso rp
           INNER JOIN permiso p ON p.id = rp.id_permiso"
    );

    if (!$resultado || $resultado->num_rows === 0) {
        // Tabla inexistente o sin sembrar: no se restringe nada.
        $_SESSION['permisos'] = null;
        $_SESSION['permisos_ids'] = null;
        return;
    }

    $mapa = [];
    while ($fila = $resultado->fetch_assoc()) {
        $mapa[(int)$fila['id_rol']][] = [
            'id'     => (int)$fila['id'],
            'nombre' => (string)$fila['nombre'],
        ];
    }

    // Rol ausente del mapa => [] (bloqueado). Rol presente => sus permisos.
    $permisosRol = $mapa[rolActual()] ?? [];

    $_SESSION['permisos']     = array_column($permisosRol, 'nombre');
    $_SESSION['permisos_ids'] = array_column($permisosRol, 'id');
}
?>
