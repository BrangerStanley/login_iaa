<?php
require_once __DIR__ . '/../../config/seguridad.php';
configurarSesionSegura();
$slideDir = isset($_GET['slide']) && is_scalar($_GET['slide']) ? (string) $_GET['slide'] : '';
$bodyClass = ($slideDir === 'left') ? 'slide-from-left' : (($slideDir === 'right') ? 'slide-from-right' : '');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/inicio.css?v=10">
    <link rel="stylesheet" href="../css/barlow.css">
    <link rel="stylesheet" href="../css/boxicons.min.css">
    <link rel="icon" type="image/png" href="../../img/Logo.png">
    <title>IAA · Registro</title>
    <style nonce="<?php echo csp_nonce(); ?>">
        .login-form { justify-content: center !important; padding: 30px !important; background: rgba(255, 255, 255, 0.95) !important; border-radius: 12px !important; }
        .form-group { width: 100% !important; max-width: 350px !important; margin-bottom: 20px !important; }
        .form-group label { margin-left: 0 !important; font-size: 0.9rem !important; color: var(--text) !important; margin-bottom: 5px !important; }
        .form-group input { border: 1px solid #ddd !important; border-radius: 8px !important; padding: 12px !important; }
        .form-group:focus-within label { color: var(--buttons-selected) !important; }
        .form-group:focus-within input { border: 1px solid var(--buttons-selected) !important; }
        .login-form .btn { width: 100% !important; max-width: 350px !important; border-radius: 8px !important; padding: 12px !important; background-color: var(--buttons-selected) !important; }
        .login-form .btn:hover { background-color: var(--buttons-selected-hover) !important; }
        small { font-size: 0.75rem !important; color: #777 !important; }
        .aviso-modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.55); z-index:2000; align-items:center; justify-content:center; }
        .aviso-modal-overlay.mostrado { display:flex; }
        .aviso-modal { background:#fff; border-radius:12px; padding:28px 34px; max-width:420px; width:90%; box-shadow:0 10px 30px rgba(0,0,0,0.25); text-align:center; }
        .aviso-modal-body { font-size:16px; line-height:1.5; color:#333; margin-bottom:20px; word-break:break-word; }
        .aviso-modal-btn { border:none; color:#fff; padding:10px 34px; border-radius:8px; font-size:15px; cursor:pointer; transition:opacity .2s; }
        .aviso-modal-btn:hover { opacity:.85; }
    </style>
</head>

<body class="<?php echo esc($bodyClass); ?>">
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
                    <a href="login.php?slide=left" class="btn btn-brand ms-lg-3">Iniciar Sesión</a>
                </li>
            </ul>
        </div>
    </nav>
</header>

<div class="slide-wrapper">
    <main class="main-content">
        <div class="container-form">
            <form action="<?php echo BASE_URL; ?>/controllers/validacionController.php" method="POST" class="login-form login-form-left" autocomplete="off" id="formValidacion">
                <?php if (isset($_GET['error']) && is_scalar($_GET['error'])): ?>
                    <input type="hidden" id="aviso-mensaje" value="<?php echo htmlspecialchars((string) $_GET['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" data-tipo="error">
                <?php endif; ?>

                <input type="hidden" name="csrftoken" value="<?php echo csrf_token(); ?>">

                <div class="form-group">
                    <label for="cedula">Cédula:</label>
                    <input type="number" name="cedula" id="cedula" min="1000000" max="99999999" required>
                </div>

                <div class="form-group">
                    <label for="foto_cedula">Foto de la cédula (opcional):</label>
                    <input type="file" name="foto_cedula" id="foto_cedula" accept="image/*" aria-describedby="aviso-foto">
                    <small id="aviso-foto" style="color: #666;">
                        La validación automática por imagen está desactivada. No revisamos ni usamos el archivo y la aplicación no lo guarda.
                        Puedes continuar sin adjuntarlo.
                    </small>
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

                <button type="submit" name="form-btn" id="btn-validar" class="btn">Continuar al registro</button>
                <br><br>
                <p>¿Ya tienes una cuenta? <a href="login.php?slide=left" id="linkIniciarSesion">Iniciar Sesión</a></p>
            </form>

            <div class="welcome-messages">
                <div class="welcome-message-text">
                    <h2>Registro</h2>
                    <p>Ingresa tu cédula y continúa con el registro</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Procesando -->
    <div class="procesando-overlay" id="procesandoOverlay">
        <div class="procesando-modal" id="procesandoModal">
            <div id="procesandoSpinner">
                <div class="procesando-spinner"></div>
                <h3>Procesando...</h3>
                <p style="margin-top:6px;color:#666;font-size:.9rem;">Preparando el registro</p>
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
                <h3>¡Listo!</h3>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../views/partials/footer_site.php'; ?>

<div class="aviso-modal-overlay" id="avisoModalOverlay" role="dialog" aria-modal="true">
    <div class="aviso-modal">
        <div class="aviso-modal-body" id="avisoModalBody"></div>
        <button type="button" class="aviso-modal-btn" id="avisoModalBtn">Aceptar</button>
    </div>
</div>

<script nonce="<?php echo csp_nonce(); ?>">
    (function () {
        var img = document.getElementById('captcha-img');
        if (img) {
            img.addEventListener('click', function () {
                img.src = img.src.split('?')[0] + '?t=' + new Date().getTime();
                var campo = document.getElementById('captcha');
                if (campo) campo.value = '';
            });
        }
        var oculto = document.getElementById('aviso-mensaje');
        if (oculto) {
            var overlay = document.getElementById('avisoModalOverlay');
            var body = document.getElementById('avisoModalBody');
            var btn = document.getElementById('avisoModalBtn');
            function cerrar() { overlay.classList.remove('mostrado'); }
            overlay.addEventListener('click', function (e) { if (e.target === overlay) cerrar(); });
            btn.addEventListener('click', cerrar);
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
            btn.style.background = (oculto.dataset.tipo === 'msg') ? '#198754' : '#dc3545';
            body.textContent = oculto.value;
            overlay.classList.add('mostrado');
        }
    })();
</script>
<script nonce="<?php echo csp_nonce(); ?>">var BASE_URL = '<?php echo BASE_URL; ?>';</script>
<script src="../js/validacion_cedula.js?v=6"></script>
<script src="../js/slide-transition.js"></script>
<?php include __DIR__ . '/../../views/partials/equipo_modal.php'; ?>
</body>
</html>
