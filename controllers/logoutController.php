<?php
// controllers/logoutController.php
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

if (isset($_COOKIE[session_name()])) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (isset($_COOKIE['iaa_csrf'])) {
    setcookie('iaa_csrf', '', ['expires' => time() - 3600, 'path' => '/']);
}

header("Location: " . BASE_URL . "/public/logeo/login.php");
exit;
