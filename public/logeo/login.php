<?php
require_once __DIR__ . '/../../config/seguridad.php';
configurarSesionSegura();
$slideDir = isset($_GET['slide']) ? $_GET['slide'] : '';
$bodyClass = ($slideDir === 'left') ? 'slide-from-left' : (($slideDir === 'right') ? 'slide-from-right' : '');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-url" content="<?php echo BASE_URL; ?>">
    <link rel="stylesheet" href="../css/inicio.css?v=10">
    <link rel="stylesheet" href="../css/barlow.css">
    <link rel="stylesheet" href="../css/boxicons.min.css">
    <link rel="icon" type="image/png" href="../../img/Logo.png">
    <title>IAA</title>
    <style nonce="<?php echo csp_nonce(); ?>">
        /* Estilos minimalistas manteniendo colores de marca */
        .login-form {
            justify-content: center !important;
            padding: 30px !important;
            background: rgba(255, 255, 255, 0.95) !important;
            border-radius: 12px !important;
        }
        .form-group {
            width: 100% !important;
            max-width: 350px !important;
            margin-bottom: 20px !important;
        }
        .form-group label {
            margin-left: 0 !important;
            font-size: 0.9rem !important;
            color: var(--text) !important;
            margin-bottom: 5px !important;
        }
        .form-group input {
            border: 1px solid #ddd !important;
            border-radius: 8px !important;
            padding: 12px !important;
        }
        .form-group:focus-within label {
            color: var(--buttons-selected) !important;
        }
        .form-group:focus-within input {
            border: 1px solid var(--buttons-selected) !important;
        }
        .login-form .btn {
            width: 100% !important;
            max-width: 350px !important;
            border-radius: 8px !important;
            padding: 12px !important;
            background-color: var(--buttons-selected) !important;
        }
        .login-form .btn:hover {
            background-color: var(--buttons-selected-hover) !important;
        }
        small {
            font-size: 0.75rem !important;
            color: #777 !important;
        }
    </style>
</head>

<body class="<?php echo $bodyClass; ?>">
<header class="header">
    <nav class="navbar navbar-expand-lg navbar-light bg-white">
        <div class="container">
            <div class="logo-area">
                <img src="../../img/Logo ex.png" alt="Logo IAA">
                <div class="logo-area-text">
                    <h1>IAA</h1>
                    <p>Instituto Autónomo De Ayuda a los Funcionarios</p>
                </div>
            </div>
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a href="../index.php" class="btn btn-brand ms-lg-3">Menú Principal</a>
                </li>
            </ul>
        </div>
    </nav>
</header>

<div class="slide-wrapper">
    <main class="main-content">
        <div class="container-form">
            <div class="welcome-message">
                <div class="welcome-message-text">
                    <h2>Bienvenido</h2>
                    <p>Al Instituto Autónomo De Ayuda A Los Funcionarios</p>
                </div>
            </div>

            <?php
            $aviso_mensaje = null;
            $aviso_tipo = 'error';
            if (isset($_GET['registro']) && $_GET['registro'] === 'ok') {
                $aviso_mensaje = 'Tu cuenta fue creada correctamente. Ya puedes iniciar sesión.';
                $aviso_tipo = 'msg';
            } elseif (isset($_GET['error']) && is_scalar($_GET['error'])) {
                $aviso_mensaje = (string) $_GET['error'];
                $aviso_tipo = 'error';
            } elseif (isset($_GET['msg']) && is_scalar($_GET['msg'])) {
                $aviso_mensaje = (string) $_GET['msg'];
                $aviso_tipo = 'msg';
            }
            ?>
            <?php if ($aviso_mensaje !== null): ?>
                <input type="hidden" id="aviso-mensaje" value="<?php echo htmlspecialchars($aviso_mensaje, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                       data-tipo="<?php echo $aviso_tipo; ?>">
            <?php endif; ?>
<form action="<?php echo BASE_URL; ?>/controllers/funcionarioLoginController.php" method="POST" class="login-form" autocomplete="off" id="loginForm">
    <h2>Iniciar Sesión</h2>
    <input type="hidden" name="csrftoken" value="<?php echo csrf_token(); ?>">
    <input type="hidden" id="login-base-url" value="<?php echo BASE_URL; ?>">
    
    <div class="form-group">
        <label for="cedula">Usuario (Cédula):</label>
        <input type="number" name="cedula" id="cedula" required autocomplete="off">
    </div>
    
    <div class="form-group">
        <label for="clave">Contraseña:</label>
        <input type="password" name="password" id="password" required autocomplete="new-password">
    </div>

    <div class="form-group">
        <label for="captcha">Código de seguridad:</label>
        <div style="display: flex; gap: 10px; align-items: center;">
            <input type="text" name="captcha" id="captcha" placeholder="Escribe el código" maxlength="5"
                   required style="flex: 1; text-transform: uppercase;" autocomplete="off">
            <img src="<?php echo captcha_img_url(); ?>" id="captcha-img" alt="Captcha"
                 style="height: 46px; border-radius: 8px; border: 1px solid var(--text); cursor: pointer;"
                 title="Haz clic para recargar">
        </div>
        <small style="color:#666; margin-left:10px;">Haz clic en la imagen para cambiarla</small>
    </div>

    <button type="submit" name="form-btn" class="btn">Acceder</button>
                    <br><br>
                <p>¿Eres nuevo? <a href="validacion.php?slide=left" id="linkCrearCuenta">Crear cuenta aquí</a></p>
            </form>

        </div>
    </main>

    <!-- Modal Procesando -->
    <div class="procesando-overlay" id="procesandoOverlay">
        <div class="procesando-modal" id="procesandoModal">
            <div id="procesandoSpinner">
                <div class="procesando-spinner"></div>
                <h3>Procesando...</h3>
            </div>
            <div id="procesandoError" style="display: none;">
                <div class="procesando-error-icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <line class="line1" x1="7" y1="7" x2="17" y2="17"/>
                        <line class="line2" x1="17" y1="7" x2="7" y2="17"/>
                    </svg>
                </div>
                <h3 id="procesandoErrorMsg">Error</h3>
            </div>
            <div id="procesandoSuccess" style="display: none;">
                <div class="procesando-success-icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <h3>¡Completado!</h3>
            </div>
        </div>
    </div>

    <script src="../js/login.js?v=4"></script>

    <div class="aviso-modal-overlay" id="avisoModalOverlay" role="dialog" aria-modal="true">
        <div class="aviso-modal">
            <div class="aviso-modal-body" id="avisoModalBody"></div>
            <button type="button" class="aviso-modal-btn" id="avisoModalBtn">Aceptar</button>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../../views/partials/footer_site.php'; ?>

<style nonce="<?php echo csp_nonce(); ?>">
    .aviso-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 2000; align-items: center; justify-content: center; }
    .aviso-modal-overlay.mostrado { display: flex; }
    .aviso-modal { background: #fff; border-radius: 12px; padding: 28px 34px; max-width: 420px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.25); text-align: center; }
    .aviso-modal-body { font-size: 16px; line-height: 1.5; color: #333; margin-bottom: 20px; word-break: break-word; }
    .aviso-modal-btn { background: #dc3545; color: #fff; border: none; padding: 10px 34px; border-radius: 8px; font-size: 15px; cursor: pointer; transition: opacity .2s; }
    .aviso-modal-btn:hover { opacity: .85; }
</style>

<script nonce="<?php echo csp_nonce(); ?>">
    (function () {
        var oculto = document.getElementById('aviso-mensaje');
        if (!oculto) return;
        var overlay = document.getElementById('avisoModalOverlay');
        var body = document.getElementById('avisoModalBody');
        overlay.addEventListener('click', function (e) { if (e.target === overlay) cerrar(); });
        document.getElementById('avisoModalBtn').addEventListener('click', cerrar);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
        function cerrar() { overlay.classList.remove('mostrado'); }
        var btn = document.getElementById('avisoModalBtn');
        btn.style.background = (oculto.dataset.tipo === 'msg') ? '#198754' : '#dc3545';
        body.textContent = oculto.value;
        overlay.classList.add('mostrado');
    })();
</script>

<script src="../js/slide-transition.js"></script>
<?php include __DIR__ . '/../../views/partials/equipo_modal.php'; ?>
</body>
</html>
