<?php
// config/helpers_email.php

function enviarCorreoSeguro($destinatario, $asunto, $mensaje) {
    $config = include __DIR__ . '/mail_config.php';
    $modo = $config['mode'] ?? 'simulador';

    if ($modo === 'smtp') {
        $smtp = $config['smtp'];
        $host = $smtp['host'];
        $port = 465;
        $user = $smtp['username'];
        $pass = $smtp['password'];
        $from = $smtp['from_email'];
        $name = $smtp['from_name'];

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
            ]
        ]);
        $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        
        if (!$socket) {
            registrarLogCorreo($destinatario, $asunto, "[FALLO CONEXIÓN SSL SMTP: $errstr]");
            return false;
        }

        try {
            fgets($socket, 515);

            fputs($socket, "EHLO " . gethostname() . "\r\n");
            while($line = fgets($socket, 515)) {
                if (substr($line, 3, 1) == " ") break;
            }

            fputs($socket, "AUTH LOGIN\r\n");
            fgets($socket, 515);

            fputs($socket, base64_encode($user) . "\r\n");
            fgets($socket, 515);

            fputs($socket, base64_encode($pass) . "\r\n");
            $passResp = fgets($socket, 515);

            if (substr($passResp, 0, 3) !== '235') {
                throw new Exception("Autenticación rechazada por el servidor: " . trim($passResp));
            }

            fputs($socket, "MAIL FROM: <{$from}>\r\n");
            if (substr(fgets($socket, 515), 0, 3) !== '250') throw new Exception("MAIL FROM rechazado");

            fputs($socket, "RCPT TO: <{$destinatario}>\r\n");
            if (substr(fgets($socket, 515), 0, 3) !== '250') throw new Exception("RCPT TO rechazado");

            fputs($socket, "DATA\r\n");
            if (substr(fgets($socket, 515), 0, 3) !== '354') throw new Exception("DATA rechazado");

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: {$name} <{$from}>\r\n";
            $headers .= "To: <{$destinatario}>\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=\r\n";
            $headers .= "\r\n";

            fputs($socket, $headers . $mensaje . "\r\n.\r\n");
            if (substr(fgets($socket, 515), 0, 3) !== '250') throw new Exception("Envío de contenido rechazado");

            fputs($socket, "QUIT\r\n");
            fclose($socket);

            registrarLogCorreo($destinatario, $asunto, "[ENVIADO REAL VIA SSL 465]");
            return true;

        } catch (Exception $e) {
            @fclose($socket);
            registrarLogCorreo($destinatario, $asunto, "[ERROR SMTP SSL: " . $e->getMessage() . "]");
            return false;
        }

    } else {
        return registrarLogCorreo($destinatario, $asunto, "");
    }
}

/**
 * Envía un correo con un archivo adjunto (multipart/mixed).
 * Reutiliza la lógica SMTP de enviarCorreoSeguro, pero construye un mensaje
 * MIME con el adjunto codificado en base64.
 *
 * @param string $destinatario correo destino
 * @param string $asunto
 * @param string $mensajeHtml cuerpo en HTML
 * @param string $rutaAdjunto ruta física del archivo a adjuntar
 * @param string $nombreAdjunto nombre con el que llegará el adjunto
 * @return bool
 */
function enviarCorreoConAdjunto($destinatario, $asunto, $mensajeHtml, $rutaAdjunto, $nombreAdjunto = 'documentos.zip')
{
    $config = include __DIR__ . '/mail_config.php';
    $modo = $config['mode'] ?? 'simulador';

    if ($modo !== 'smtp') {
        registrarLogCorreo($destinatario, $asunto, "[ADJUNTO: $nombreAdjunto]");
        return true;
    }

    $smtp = $config['smtp'];
    $host = $smtp['host'];
    $port = 465;
    $user = $smtp['username'];
    $pass = $smtp['password'];
    $from = $smtp['from_email'];
    $name = $smtp['from_name'];

    if (!is_file($rutaAdjunto)) {
        registrarLogCorreo($destinatario, $asunto, "[FALLO ADJUNTO: no existe $rutaAdjunto]");
        return false;
    }
    $contenidoAdjunto = @file_get_contents($rutaAdjunto);
    if ($contenidoAdjunto === false) {
        registrarLogCorreo($destinatario, $asunto, "[FALLO ADJUNTO: no se pudo leer $rutaAdjunto]");
        return false;
    }

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ]
    ]);
    $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        registrarLogCorreo($destinatario, $asunto, "[FALLO CONEXIÓN SSL SMTP: $errstr]");
        return false;
    }

    try {
        $limite = '----=_IAA_' . md5(uniqid('', true));
        $cuerpoBase64 = chunk_split(base64_encode($contenidoAdjunto), 76, "\r\n");

        $mensaje = "--{$limite}\r\n";
        $mensaje .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mensaje .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $mensaje .= $mensajeHtml . "\r\n\r\n";
        $mensaje .= "--{$limite}\r\n";
        $mensaje .= "Content-Type: application/zip; name=\"{$nombreAdjunto}\"\r\n";
        $mensaje .= "Content-Disposition: attachment; filename=\"{$nombreAdjunto}\"\r\n";
        $mensaje .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $mensaje .= $cuerpoBase64 . "\r\n";
        $mensaje .= "--{$limite}--\r\n";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$limite}\"\r\n";
        $headers .= "From: {$name} <{$from}>\r\n";
        $headers .= "To: <{$destinatario}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=\r\n";

        fgets($socket, 515);
        fputs($socket, "EHLO " . gethostname() . "\r\n");
        while ($line = fgets($socket, 515)) {
            if (substr($line, 3, 1) == " ") break;
        }

        fputs($socket, "AUTH LOGIN\r\n");
        fgets($socket, 515);
        fputs($socket, base64_encode($user) . "\r\n");
        fgets($socket, 515);
        fputs($socket, base64_encode($pass) . "\r\n");
        $passResp = fgets($socket, 515);
        if (substr($passResp, 0, 3) !== '235') {
            throw new Exception("Autenticación rechazada por el servidor: " . trim($passResp));
        }

        fputs($socket, "MAIL FROM: <{$from}>\r\n");
        if (substr(fgets($socket, 515), 0, 3) !== '250') throw new Exception("MAIL FROM rechazado");

        fputs($socket, "RCPT TO: <{$destinatario}>\r\n");
        if (substr(fgets($socket, 515), 0, 3) !== '250') throw new Exception("RCPT TO rechazado");

        fputs($socket, "DATA\r\n");
        if (substr(fgets($socket, 515), 0, 3) !== '354') throw new Exception("DATA rechazado");

        fputs($socket, $headers . "\r\n" . $mensaje . "\r\n.\r\n");
        if (substr(fgets($socket, 515), 0, 3) !== '250') throw new Exception("Envío de contenido rechazado");

        fputs($socket, "QUIT\r\n");
        fclose($socket);

        registrarLogCorreo($destinatario, $asunto, "[ENVIADO REAL VIA SSL 465 CON ADJUNTO: $nombreAdjunto]");
        return true;

    } catch (Exception $e) {
        @fclose($socket);
        registrarLogCorreo($destinatario, $asunto, "[ERROR SMTP SSL ADJUNTO: " . $e->getMessage() . "]");
        return false;
    }
}

function registrarLogCorreo($destinatario, $asunto, $mensaje) {
    // La carpeta de registros es configurable (IAA_DIR_LOGS): en produccion
    // conviene que este FUERA del webroot, porque el log de correo contiene
    // datos de los usuarios.
    require_once __DIR__ . '/directorios.php';
    $logDir = iaa_dir_logs();
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    
    $logFile = $logDir . '/correos_enviados.log';
    $fecha = date('Y-m-d H:i:s');
    $separador = str_repeat("=", 80);
    
    $contenidoLog = "{$separador}\n";
    $contenidoLog .= "FECHA: {$fecha}\n";
    $contenidoLog .= "PARA:  {$destinatario}\n";
    $contenidoLog .= "ASUNTO: {$asunto}\n";
    $contenidoLog .= "ESTADO: {$mensaje}\n";
    $contenidoLog .= "{$separador}\n\n";
    
    @file_put_contents($logFile, $contenidoLog, FILE_APPEND | LOCK_EX);
    @chmod($logFile, 0600);
    
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (isset($_SESSION) && !isset($_SESSION['emails_simulados'])) {
        $_SESSION['emails_simulados'] = [];
    }
    
    if (isset($_SESSION)) {
        array_unshift($_SESSION['emails_simulados'], [
            'fecha' => $fecha,
            'destinatario' => $destinatario,
            'asunto' => $asunto,
            'mensaje' => '[CONTENIDO OMITIDO POR SEGURIDAD]'
        ]);
        
        if (count($_SESSION['emails_simulados']) > 50) {
            array_pop($_SESSION['emails_simulados']);
        }
    }
    
    return true;
}
