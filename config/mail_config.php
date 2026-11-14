<?php
// config/mail_config.php
// Credenciales SMTP por variables de entorno (no hardcodeadas en el repo).
require_once __DIR__ . '/env.php';

$smtpUser = getenv('IAA_SMTP_USER');
$smtpPass = getenv('IAA_SMTP_PASS');
$fromUser = getenv('IAA_SMTP_FROM');

// Solo se intenta enviar de verdad si hay credenciales SMTP configuradas.
// Sin ellas el sistema entra en modo simulador: registra el correo en
// logs/correos.log y continúa (el login no debe esperar 15 s por un SMTP
// que no está configurado).
$haySmtp = ($smtpUser !== false && $smtpUser !== '') && ($smtpPass !== false && $smtpPass !== '');

return [
    'mode' => $haySmtp ? 'smtp' : 'simulador',
    'smtp' => [
        'host'       => getenv('IAA_SMTP_HOST') ?: 'smtp.gmail.com',
        'port'       => (int)(getenv('IAA_SMTP_PORT') ?: 465),
        'username'   => ($smtpUser !== false && $smtpUser !== '') ? $smtpUser : '',
        'password'   => ($smtpPass !== false) ? (string)$smtpPass : '',
        'encryption' => getenv('IAA_SMTP_ENC') ?: 'ssl',
        'from_email' => ($fromUser !== false && $fromUser !== '') ? $fromUser : (($smtpUser !== false && $smtpUser !== '') ? $smtpUser : ''),
        'from_name'  => 'IAA - Sistema de Notificaciones',
    ]
];
