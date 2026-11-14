<?php
// views/menu_principal.php
// Pantalla posterior al login (versión reducida del menú del sistema IAA).
// Usa los mismos guards de seguridad que el resto del sistema:
// sesión endurecida, token CSRF y control de acceso por sesión.
require_once __DIR__ . '/../config/seguridad.php';
configurarSesionSegura();

if (!isset($_SESSION['ci_user'])) {
    header('Location: ' . BASE_URL . '/public/logeo/login.php');
    exit;
}

require_once __DIR__ . '/../config/Conexion.php';

$csrf = csrf_token();
$nombre = (string) ($_SESSION['nombre_user'] ?? '');
$cedula = (string) ($_SESSION['ci_user'] ?? '');
$rolActivo = (int) ($_SESSION['id_rol'] ?? $_SESSION['per_id'] ?? 0);
$rolesCuenta = $_SESSION['roles_cuenta'] ?? [];
$inicioSesion = (int) ($_SESSION['_inicio_sesion'] ?? time());

// Últimos accesos de esta cuenta en la bitácora de sesiones.
$accesos = [];
if ($conexion instanceof IaaConnection) {
    $stmt = $conexion->prepare(
        "SELECT f_ing, f_sal, dir_ip, agent_u, estado
           FROM logeo
          WHERE ci_user = ?
          ORDER BY id_logeo DESC
          LIMIT 5"
    );
    if ($stmt) {
        $stmt->bind_param('s', $cedula);
        $stmt->execute();
        $accesos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$nombreRol = '';
if ($conexion instanceof IaaConnection) {
    $stmt = $conexion->prepare('SELECT nombre FROM rol WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $rolActivo);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $nombreRol = (string) ($fila['nombre'] ?? '');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo esc($csrf); ?>">
    <meta name="base-url" content="<?php echo esc(BASE_URL); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/inicio.css?v=1">
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/img/Logo.png">
    <title>Menú principal · IAA</title>
    <style nonce="<?php echo csp_nonce(); ?>">
        .iaa-panel { width: min(980px, 92%); margin: 32px auto 48px; }
        .iaa-bienvenida { background: #fff; border: 1px solid #e5e9f0; border-radius: 14px;
            padding: 26px 28px; box-shadow: 0 10px 30px rgba(15, 23, 42, .07); margin-bottom: 22px; }
        .iaa-bienvenida h2 { margin: 0 0 6px; font-size: 1.5rem; color: #1b2a41; }
        .iaa-bienvenida p { margin: 0; color: #5b6472; }
        .iaa-chips { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .iaa-chip { background: #eef4ff; color: #1b3a63; border: 1px solid #d6e4ff;
            border-radius: 999px; padding: 6px 14px; font-size: .82rem; font-weight: 600; }
        .iaa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 22px; }
        .iaa-tarjeta { background: #fff; border: 1px solid #e5e9f0; border-radius: 14px;
            padding: 20px 22px; box-shadow: 0 10px 30px rgba(15, 23, 42, .07); }
        .iaa-tarjeta h3 { margin: 0 0 14px; font-size: 1rem; color: #1b2a41;
            text-transform: uppercase; letter-spacing: .05em; }
        .iaa-dato { display: flex; justify-content: space-between; gap: 12px;
            padding: 9px 0; border-bottom: 1px dashed #e8eef6; font-size: .88rem; }
        .iaa-dato:last-child { border-bottom: 0; }
        .iaa-dato span { color: #6b7280; }
        .iaa-dato strong { color: #1f2937; font-weight: 600; text-align: right; }
        .iaa-accesos { width: 100%; border-collapse: collapse; font-size: .82rem; }
        .iaa-accesos th, .iaa-accesos td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #eef2f7; }
        .iaa-accesos th { color: #6b7280; text-transform: uppercase; font-size: .68rem; letter-spacing: .06em; }
        .iaa-salir { display: inline-flex; align-items: center; gap: 8px; margin-top: 18px;
            background: #1b2a41; color: #fff; border: 0; border-radius: 10px;
            padding: 10px 20px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .iaa-salir:hover { background: #26385a; text-decoration: none; color: #fff; }
        .iaa-aviso { background: #f0f9ff; border: 1px solid #bae6fd; color: #075985;
            border-radius: 10px; padding: 10px 14px; font-size: .82rem; margin-bottom: 18px; }
    </style>
</head>
<body>
    <header class="header">
        <nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top">
            <div class="container">
                <div class="logo-area">
                    <img src="<?php echo BASE_URL; ?>/img/Logo ex.png" alt="Logo IAA">
                    <div class="logo-area-text">
                        <h1>IAA</h1>
                        <p>Instituto Autónomo De Ayuda a los Funcionarios</p>
                    </div>
                </div>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>/public/logeo/login.php" class="btn btn-brand ms-lg-3">Ir al login</a>
                    </li>
                </ul>
            </div>
        </nav>
    </header>

    <main class="iaa-panel">
        <div class="iaa-aviso">
            Repositorio de demostración: este es el punto de llegada real del login del sistema IAA,
            reducido al panel de bienvenida. El resto de módulos (afiliados, citas, expedientes…)
            no forma parte de este repositorio.
        </div>

        <section class="iaa-bienvenida">
            <h2>Bienvenido, <?php echo esc($nombre !== '' ? $nombre : $cedula); ?></h2>
            <p>Has iniciado sesión correctamente. Tu sesión se cerrará automáticamente por inactividad.</p>
            <div class="iaa-chips">
                <span class="iaa-chip">Cédula: <?php echo esc($cedula); ?></span>
                <span class="iaa-chip">Rol: <?php echo esc($nombreRol !== '' ? $nombreRol : '—'); ?></span>
                <span class="iaa-chip">Sesión: <?php echo esc(date('d/m/Y H:i', $inicioSesion)); ?></span>
                <span class="iaa-chip">Roles de la cuenta: <?php echo esc(count($rolesCuenta)); ?></span>
            </div>
        </section>

        <div class="iaa-grid">
            <section class="iaa-tarjeta">
                <h3>Últimos accesos</h3>
                <?php if ($accesos === []): ?>
                    <p style="color:#6b7280; font-size:.85rem; margin:0;">Sin accesos registrados todavía.</p>
                <?php else: ?>
                    <table class="iaa-accesos">
                        <thead>
                            <tr>
                                <th>Entrada</th>
                                <th>IP</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accesos as $acceso): ?>
                            <tr>
                                <td><?php echo esc(date('d/m/Y H:i', strtotime((string) $acceso['f_ing']))); ?></td>
                                <td><?php echo esc($acceso['dir_ip'] ?? '—'); ?></td>
                                <td><?php echo esc($acceso['estado'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="iaa-tarjeta">
                <h3>Seguridad de la sesión</h3>
                <div class="iaa-dato"><span>Cookies</span><strong>HttpOnly · SameSite=Lax</strong></div>
                <div class="iaa-dato"><span>CSRF</span><strong>HMAC firmado</strong></div>
                <div class="iaa-dato"><span>Captcha</span><strong>5 caracteres (GD)</strong></div>
                <div class="iaa-dato"><span>Bloqueo</span><strong>10 · 30 · 120 min</strong></div>
                <div class="iaa-dato"><span>Inactividad</span><strong>Cierre a los 3 min</strong></div>
                <div class="iaa-dato"><span>Bitácora</span><strong>Entrada y salida</strong></div>

                <form action="<?php echo BASE_URL; ?>/controllers/logoutController.php" method="get">
                    <button type="submit" class="iaa-salir">Cerrar sesión</button>
                </form>
            </section>
        </div>
    </main>

    <?php include __DIR__ . '/partials/footer_site.php'; ?>
    <?php include __DIR__ . '/partials/equipo_modal.php'; ?>

    <!-- Control de inactividad: aviso a los 30 s, cierre a los 3 min y latido
         cada 15 s (mismo mecanismo del sistema IAA, ver config/session_timeout.php). -->
    <script src="<?php echo BASE_URL; ?>/views/js/session_timeout.js?v=1"></script>
</body>
</html>
