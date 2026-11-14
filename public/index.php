<?php
// public/index.php
// Punto de entrada: envía al login (o al menú si ya hay sesión abierta).
require_once __DIR__ . '/../config/seguridad.php';
configurarSesionSegura();

$destino = isset($_SESSION['ci_user'])
    ? BASE_URL . '/views/menu_principal.php'
    : BASE_URL . '/public/logeo/login.php';

header('Location: ' . $destino);
exit;
