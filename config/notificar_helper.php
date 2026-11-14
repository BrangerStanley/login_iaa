<?php
// config/notificar_helper.php
// Capa unificada de notificación: inserta notificación interna y envía correo electrónico.
// Uso: notificar($conexion, $idUsuario, $titulo, $mensaje, $tipo = 'general');

require_once __DIR__ . '/helpers_email.php';
require_once __DIR__ . '/../models/NotificacionModel.php';

function notificar($conexion, $id_usuario, $titulo, $mensaje, $tipo = 'general')
{
    if (!$conexion || (int)$id_usuario <= 0) {
        return false;
    }

    $id_usuario = (int)$id_usuario;
    $titulo = trim((string)$titulo);
    $mensaje = trim((string)$mensaje);
    if ($tipo === '') {
        $tipo = 'general';
    }
    if ($titulo === '' || $mensaje === '') {
        return false;
    }
    if (mb_strlen($titulo) > 200) {
        $titulo = mb_substr($titulo, 0, 200);
    }
    if (mb_strlen($mensaje) > 4000) {
        $mensaje = mb_substr($mensaje, 0, 4000);
    }

    // 1. Notificación interna (tabla notificacion -> badge/panel)
    $model = new NotificacionModel($conexion);
    $model->crear($id_usuario, $titulo, $mensaje, $tipo);

    // 2. Correo electrónico a la dirección del usuario (persona.correo)
    $stmt = $conexion->prepare(
        "SELECT p.nombre, p.correo
         FROM usuario u
         INNER JOIN persona p ON u.id_persona = p.id_persona
         WHERE u.id_usuario = ?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $id_usuario);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($fila && !empty($fila['correo']) && filter_var($fila['correo'], FILTER_VALIDATE_EMAIL)) {
            $nombre = htmlspecialchars($fila['nombre'] ?? '', ENT_QUOTES, 'UTF-8');
            $cuerpo = "Hola " . ($nombre !== '' ? $nombre : 'usuario') . ",<br><br>"
                    . nl2br(htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'))
                    . "<br><br>" . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8')
                    . "<br><br>Equipo IAA";
            enviarCorreoSeguro($fila['correo'], '[IAA] ' . $titulo, $cuerpo);
        }
    }

    return true;
}
