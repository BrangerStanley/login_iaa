document.addEventListener('DOMContentLoaded', function () {

    /* === Captcha refresh === */
    var captchaImg = document.getElementById('captcha-img');
    if (captchaImg) {
        captchaImg.addEventListener('click', function () {
            captchaImg.src = captchaImg.src.split('?')[0] + '?t=' + new Date().getTime();
            var campo = document.getElementById('captcha');
            if (campo) campo.value = '';
        });
    }

    /* === Modal selector de rol (si existe) === */
    var modalRol = document.getElementById('modalRolLogin');
    if (modalRol && typeof bootstrap !== 'undefined') {
        try {
            new bootstrap.Modal(modalRol).show();
        } catch (e) { }
    }

    /* === Login AJAX con animación === */
    var loginForm = document.getElementById('loginForm');
    if (!loginForm) return;

    var containerForm = document.querySelector('.container-form');
    var overlay = document.getElementById('procesandoOverlay');
    var spinnerBlock = document.getElementById('procesandoSpinner');
    var errorBlock = document.getElementById('procesandoError');
    var errorMsg = document.getElementById('procesandoErrorMsg');
    var successBlock = document.getElementById('procesandoSuccess');
    var baseUrl = document.getElementById('login-base-url');

    loginForm.addEventListener('submit', function (e) {
        e.preventDefault();

        // 1. Formulario baja
        if (containerForm) containerForm.classList.add('form-hidden');

        // 2. Fondo se vuelve nítido
        document.body.classList.add('bg-clear');

        // 3. Mostrar modal "Procesando..."
        if (spinnerBlock) spinnerBlock.style.display = '';
        if (errorBlock) errorBlock.style.display = 'none';
        if (successBlock) successBlock.style.display = 'none';
        if (overlay) overlay.classList.add('visible');

        // 4. Enviar petición AJAX
        var formData = new FormData(loginForm);
        formData.append('form-btn', '1');

        fetch(baseUrl.value + '/controllers/funcionarioLoginController.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success && data.redirect) {
                // Éxito: mostrar checkmark y redirigir
                if (spinnerBlock) spinnerBlock.style.display = 'none';
                if (successBlock) successBlock.style.display = '';
                setTimeout(function () {
                    window.location.href = data.redirect;
                }, 1000);
            } else {
                mostrarError(data.message || 'Error al iniciar sesión.');
            }
        })
        .catch(function () {
            mostrarError('Error de conexión. Intente de nuevo.');
        });
    });

    function mostrarError(msg) {
        // Ocultar spinner, mostrar X roja
        if (spinnerBlock) spinnerBlock.style.display = 'none';
        if (errorBlock) errorBlock.style.display = '';
        if (successBlock) successBlock.style.display = 'none';
        if (errorMsg) errorMsg.textContent = msg;

        setTimeout(function () {
            if (overlay) overlay.classList.remove('visible');
            document.body.classList.remove('bg-clear');
            if (containerForm) containerForm.classList.remove('form-hidden');

            // Resetear modal para la siguiente vez
            setTimeout(function () {
                if (spinnerBlock) spinnerBlock.style.display = '';
                if (errorBlock) errorBlock.style.display = 'none';
            }, 400);
        }, 2000);
    }

});
