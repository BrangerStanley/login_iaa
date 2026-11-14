<?php
// config/bitacora_helper.php
// Registro centralizado de acciones en la tabla bitacora.
// Vincula cada acción a la sesión (logeo) en la que ocurrió y a una categoría.
// Uso: registrarBitacora($conexion, 'crear_usuario', 'Detalle…', 'dato_guardado');

/** Categorías admitidas (mantener sincronizado con la UI de Bitácoras). */
const BITACORA_CATEGORIAS = [
    'archivo_enviado',
    'archivo_eliminado',
    'dato_guardado',
    'dato_actualizado',
    'dato_eliminado',
    'exportacion',
    'respaldo',
    'seguridad',
    'notificacion',
    'catalogo',
    'acceso_pagina',
    'acceso_denegado',
    'intento_fallido',
];

/** Claves de contexto que nunca deben persistirse en la bitácora. */
const BITACORA_CAMPOS_SENSIBLES = [
    'pass',
    'pass_confirm',
    'password',
    'password_admin',
    'clave',
    'clave_admin',
    'contrasena',
    'correo',
    'email',
    'telefono',
    'foto',
    'captcha',
];

/**
 * Resuelve y cachea el id_logeo de la sesión actual.
 * Se guarda en $_SESSION['id_logeo'] al hacer login; si no existe
 * (sesiones antiguas o rotación de token), busca por token_sesion.
 */
function bitacoraIdSesionActual($conexion): int
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = (int)($_SESSION['id_logeo'] ?? 0);
    if ($cache > 0) {
        return $cache;
    }
    $token = (string)($_SESSION['token_sesion'] ?? '');
    if ($token === '' || !$conexion) {
        $cache = 0;
        return 0;
    }
    $stmt = $conexion->prepare(
        "SELECT id_logeo FROM logeo WHERE token_sesion = ? ORDER BY id_logeo DESC LIMIT 1"
    );
    if (!$stmt) {
        $cache = 0;
        return 0;
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cache = (int)($row['id_logeo'] ?? 0);
    if ($cache > 0) {
        $_SESSION['id_logeo'] = $cache;
    }
    return $cache;
}

function registrarBitacora($conexion, $accion, $detalle = '', $categoria = 'dato_actualizado')
{
    if (!$conexion) {
        return false;
    }
    $id_usuario = (int)($_SESSION['id_user'] ?? $_SESSION['id_usuario'] ?? 0);
    if ($id_usuario <= 0) {
        $ci = (string)($_SESSION['ci_user'] ?? '');
        if ($ci !== '' && $conexion) {
            $stmtCi = $conexion->prepare("SELECT id_usuario FROM usuario WHERE ci_usuario = ? LIMIT 1");
            if ($stmtCi) {
                $stmtCi->bind_param('s', $ci);
                $stmtCi->execute();
                $rowCi = $stmtCi->get_result()->fetch_assoc();
                $stmtCi->close();
                $id_usuario = (int)($rowCi['id_usuario'] ?? 0);
            }
        }
    }
    $accion = trim((string)$accion);
    $detalle = trim((string)$detalle);
    if ($accion === '') {
        return false;
    }
    if (mb_strlen($detalle) > 1000) {
        $detalle = mb_substr($detalle, 0, 1000);
    }
    $categoria = trim((string)$categoria);
    if ($categoria === '' || !in_array($categoria, BITACORA_CATEGORIAS, true)) {
        $categoria = 'dato_actualizado';
    }
    $id_logeo = bitacoraIdSesionActual($conexion);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (mb_strlen($agent) > 300) {
        $agent = mb_substr($agent, 0, 300);
    }

    if ($id_usuario > 0 && $id_logeo > 0) {
        $stmt = $conexion->prepare(
            "INSERT INTO bitacora (id_usuario, id_logeo, accion, categoria, detalle, fecha, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iisssss', $id_usuario, $id_logeo, $accion, $categoria, $detalle, $ip, $agent);
    } else {
        // Sin sesión (intentos anónimos) ambos FKs van en NULL: enviar 0 violaría
        // la clave foránea contra usuario(id_usuario) y el INSERT se perdería.
        $stmt = $conexion->prepare(
            "INSERT INTO bitacora (id_usuario, id_logeo, accion, categoria, detalle, fecha, ip, user_agent)
             VALUES (?, NULL, ?, ?, ?, NOW(), ?, ?)"
        );
        if (!$stmt) {
            return false;
        }
        if ($id_usuario > 0) {
            $stmt->bind_param('isssss', $id_usuario, $accion, $categoria, $detalle, $ip, $agent);
        } else {
            $id_usuario_null = null;
            $stmt->bind_param('isssss', $id_usuario_null, $accion, $categoria, $detalle, $ip, $agent);
        }
    }

    try {
        $ok = $stmt->execute();
        if (!$ok) {
            error_log('[bitacora] INSERT sin exito: ' . $stmt->error);
        }
    } catch (Throwable $e) {
        // FK u otros fallos no deben tumbar la operacion principal.
        error_log('[bitacora] INSERT fallo: ' . $e->getMessage());
        $ok = false;
    }
    $stmt->close();
    return $ok;
}

/**
 * Registra un intento fallido (validacion, autorizacion, error de persistencia).
 * Filtra el contexto para que jamas se guarden contrasenas ni datos sensibles.
 *
 * @param string $accion  Identificador de la operacion, p.ej. 'crear_usuario'.
 * @param string $motivo  Codigo corto del fallo, p.ej. 'cuenta_duplicada'.
 * @param array  $contexto Pares clave => valor, se ignoran los sensibles.
 */
function bitacoraIntentoFallido($conexion, $accion, $motivo, array $contexto = [])
{
    if (!$conexion) {
        return false;
    }
    $partes = ['Intento fallido: ' . trim((string)$motivo)];
    foreach ($contexto as $clave => $valor) {
        $clave = mb_strtolower(trim((string)$clave));
        if ($clave === '' || in_array($clave, BITACORA_CAMPOS_SENSIBLES, true)) {
            continue;
        }
        if (is_array($valor) || is_object($valor)) {
            continue;
        }
        $valor = trim((string)$valor);
        if ($valor === '') {
            continue;
        }
        $partes[] = $clave . ': ' . $valor;
    }
    return registrarBitacora($conexion, $accion, implode(' | ', $partes), 'intento_fallido');
}
