<?php
// controllers/cerrar_sesion_navegador.php
//
// Recibe el 'sendBeacon' de views/js/session_timeout.js cuando el usuario cierra
// la pestaña/ventana del navegador sin haber cerrado la sesión. Marca la sesión
// como 'inactivo' (con f_sal registrada, como un cierre normal) y destruye la
// sesión PHP. Responde 204: sendBeacon no lee la respuesta.
//
// El propio view/js filtra recargas y navegaciones internas; aquí solo se
// confía en el token de sesión propio de $_SESSION (un atacante no podría
// cerrar la sesión de otra persona sin su cookie).
require_once __DIR__ . '/../config/seguridad.php';
configurarSesionSegura();

include __DIR__ . '/../config/Conexion.php';
include __DIR__ . '/../models/LoginModel.php';

if (isset($_SESSION['token_sesion'])) {
    $token_actual = $_SESSION['token_sesion'];
    $loginModel = new LoginModel($conexion);
    $loginModel->registrarSalida($token_actual);
}

session_unset();
session_destroy();

header('HTTP/1.1 204 No Content');
exit;
