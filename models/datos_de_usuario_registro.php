<?php
// models/datos_de_usuario_registro.php — datos precargados para el formulario de registro.
include_once __DIR__ . '/../config/Conexion.php';

if (isset($_SESSION['ci_user'])) {
    $ci_buscar = (string) $_SESSION['ci_user'];
    $sql = "SELECT p.nombre AS nombre_user, p.sexo, p.fecha_nacimiento
            FROM persona p
            WHERE regexp_replace(upper(p.ci_usuario), '[^0-9]', '', 'g') = ?
            LIMIT 1";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("s", $ci_buscar);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado && $resultado->num_rows > 0) {
        $datos_usuario = $resultado->fetch_assoc();
        $datos_usuario['ci_user'] = $ci_buscar;
        $es_campo_guardado = true;
    } else {
        $nombrePrecargado = isset($_SESSION['nombre_precargado']) ? (string) $_SESSION['nombre_precargado'] : '';
        // Fallback a los datos del paso inicial del registro.
        if ($nombrePrecargado === '' && isset($_SESSION['datos_cedula']['nombre_completo'])) {
            $nombrePrecargado = (string) $_SESSION['datos_cedula']['nombre_completo'];
        }
        $datos_usuario = ['nombre_user' => $nombrePrecargado, 'ci_user' => $ci_buscar];
        $es_campo_guardado = false;
    }
    $stmt->close();
} else {
    $datos_usuario = ['nombre_user' => '', 'ci_user' => ''];
    $es_campo_guardado = false;
}
