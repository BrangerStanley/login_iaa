<?php
require_once __DIR__ . '/../../config/seguridad.php';
configurarSesionSegura();

if (!isset($_SESSION['ci_user'])) {
    header("Location: validacion.php");
    exit;
}

include dirname(__DIR__, 2) . '/models/datos_de_usuario_registro.php';

$datos_cedula = is_array($_SESSION['datos_cedula'] ?? null) ? $_SESSION['datos_cedula'] : [];
$fecha_nac_session = (string) ($datos_cedula['fecha_nacimiento'] ?? '');
$sexo_session = (string) ($datos_cedula['sexo'] ?? '');
$nacionalidad_session = (string) ($datos_cedula['nacionalidad'] ?? 'Venezolano');

$nombre_afiliado = (string) ($datos_usuario['nombre_user'] ?? '');
$ci_afiliado = (string) ($datos_usuario['ci_user'] ?? ($_SESSION['ci_user'] ?? ''));

$personaNueva = !empty($_SESSION['persona_nueva']) || empty($nombre_afiliado);
$readonlyNombre = $personaNueva ? '' : ' readonly';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/inicio.css?v=10">
    <link rel="stylesheet" href="../css/barlow.css">
    <link rel="stylesheet" href="../css/boxicons.min.css">
    <link rel="stylesheet" href="../css/registro.css?v=1">
    <link rel="icon" type="image/png" href="../../img/Logo.png">
    <title>IAA · Crear cuenta</title>
</head>
<body class="register-page slide-from-right">

<header class="register-header">
    <a class="register-brand" href="../index.php" aria-label="IAA, ir al inicio">
        <img src="../../img/Logo.png" alt="">
        <span>
            <strong>IAA</strong>
            <small>Instituto Autónomo de Ayuda a los Funcionarios</small>
        </span>
    </a>
    <a class="register-back" href="validacion.php?slide=right">Volver</a>
</header>

<div class="slide-wrapper">
    <main class="register-main">
        <section class="register-card" aria-labelledby="register-title">
            <header class="register-card-header">
                <img src="../../img/Logo.png" alt="" class="register-card-logo">
                <div>
                    <p class="register-eyebrow">CUENTA PARTICULAR</p>
                    <h1 id="register-title">Crea tu cuenta</h1>
                    <p class="register-subtitle">Completa tus datos para activar el acceso.</p>
                </div>
            </header>

            <?php if (isset($_GET['error']) && is_scalar($_GET['error'])): ?>
                <div class="register-alert" role="alert"><?php echo esc((string) $_GET['error']); ?></div>
            <?php endif; ?>

            <form action="<?php echo BASE_URL; ?>/controllers/registroController.php" method="POST" autocomplete="off" class="register-form">
                <input type="hidden" name="csrftoken" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="nacionalidad" value="<?php echo esc($nacionalidad_session); ?>">

                <div class="register-grid">
                    <div class="register-field">
                        <label for="cedula">Cédula</label>
                        <input class="register-input is-readonly" type="text" name="cedula" id="cedula"
                               value="<?php echo esc($ci_afiliado); ?>" readonly autocomplete="off">
                    </div>

                    <div class="register-field">
                        <label for="nombre">Nombre completo</label>
                        <input class="register-input<?php echo $personaNueva ? '' : ' is-readonly'; ?>" type="text" name="nombre" id="nombre"
                               value="<?php echo esc($nombre_afiliado); ?>"<?php echo $readonlyNombre; ?>
                               maxlength="150" autocomplete="name"
                               <?php echo $personaNueva ? 'required minlength="3"' : ''; ?>>
                    </div>

                    <?php if ($personaNueva): ?>
                        <div class="register-field">
                            <label for="fecha_nacimiento">Fecha de nacimiento</label>
                            <input class="register-input" type="date" name="fecha_nacimiento" id="fecha_nacimiento"
                                   value="<?php echo esc($fecha_nac_session); ?>"
                                   min="1900-01-01" max="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="register-field">
                            <label for="sexo">Sexo</label>
                            <select class="register-input" name="sexo" id="sexo" required>
                                <option value="">Seleccione...</option>
                                <option value="Masculino" <?php echo ($sexo_session === 'Masculino') ? 'selected' : ''; ?>>Masculino</option>
                                <option value="Femenino" <?php echo ($sexo_session === 'Femenino') ? 'selected' : ''; ?>>Femenino</option>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="fecha_nacimiento" value="<?php echo esc($fecha_nac_session); ?>">
                        <input type="hidden" name="sexo" value="<?php echo esc($sexo_session); ?>">
                    <?php endif; ?>

                    <div class="register-field">
                        <label for="correo">Correo electrónico</label>
                        <input class="register-input" type="email" name="correo" id="correo"
                               placeholder="tu@correo.com" maxlength="150" autocomplete="email" required>
                    </div>

                    <div class="register-field">
                        <label for="telefono">Teléfono</label>
                        <input class="register-input" type="tel" name="telefono" id="telefono"
                               placeholder="0412 1234567" minlength="7" maxlength="20"
                               inputmode="numeric" autocomplete="tel"
                               pattern="[0-9\s.\-()]{7,20}" required>
                    </div>

                    <div class="register-field">
                        <label for="pass">Contraseña</label>
                        <div class="register-password">
                            <input class="register-input" type="password" name="pass" id="pass"
                                   placeholder="Mínimo 8 caracteres" minlength="8" maxlength="72"
                                   autocomplete="new-password" required>
                            <button class="password-toggle" type="button" id="toggle-pass" aria-label="Mostrar contraseña">
                                <i class="bx bx-hide" id="icon-pass" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="pass-requirements" id="pass-reqs" aria-live="polite"></div>
                    </div>

                    <div class="register-field">
                        <label for="pass_confirm">Confirmar contraseña</label>
                        <div class="register-password">
                            <input class="register-input" type="password" name="pass_confirm" id="pass_confirm"
                                   placeholder="Repite tu contraseña" minlength="8" maxlength="72"
                                   autocomplete="new-password" required>
                            <button class="password-toggle" type="button" id="toggle-pass-confirm" aria-label="Mostrar confirmación de contraseña">
                                <i class="bx bx-hide" id="icon-pass-confirm" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="register-captcha">
                    <label for="captcha">Código de seguridad</label>
                    <div class="captcha-row">
                        <input class="register-input" type="text" name="captcha" id="captcha"
                               placeholder="Escribe el código" maxlength="5" required
                               style="text-transform: uppercase;">
                        <img src="<?php echo captcha_img_url(); ?>" id="captcha-img" alt="Captcha"
                             title="Haz clic para cambiar el código">
                    </div>
                    <small>Haz clic en la imagen para generar otro código.</small>
                </div>

                <div class="privacy-consent" id="pp-check-wrap">
                    <input type="checkbox" value="1" id="pp-acepto" name="acepto_privacidad">
                    <label for="pp-acepto">
                        He leído y acepto la
                        <a href="#modalPoliticaPrivacidad" id="pp-ver-politica">Política de Privacidad</a>
                    </label>
                    <div class="privacy-feedback" id="pp-feedback">
                        Debe aceptar la Política de Privacidad para continuar.
                    </div>
                </div>

                <button type="submit" name="form-btn" class="register-submit">Crear cuenta</button>
            </form>

            <p class="register-login">
                ¿Ya tienes una cuenta?
                <a href="login.php?slide=right">Inicia sesión</a>
            </p>
        </section>
    </main>
</div>

<?php include __DIR__ . '/../../views/partials/footer_site.php'; ?>
<?php include dirname(__DIR__, 2) . '/views/partials/politica_privacidad.php'; ?>

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
    })();
</script>
<script src="../js/registro_cuenta.js?v=2"></script>
<script src="../js/slide-transition.js"></script>
<?php include __DIR__ . '/../../views/partials/equipo_modal.php'; ?>
</body>
</html>
