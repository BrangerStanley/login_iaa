/* Inicio del registro: cédula + captcha. La foto opcional no se envía ni se procesa. */
(function () {
    'use strict';

    function soloDigitos(v) { return String(v || '').replace(/\D/g, ''); }

    function mostrarAviso(msg, tipo) {
        var overlay = document.getElementById('avisoModalOverlay');
        var body = document.getElementById('avisoModalBody');
        var btn = document.getElementById('avisoModalBtn');
        if (!overlay || !body || !btn) { alert(msg); return; }
        body.textContent = msg;
        btn.style.background = (tipo === 'msg') ? '#198754' : '#dc3545';
        overlay.classList.add('mostrado');
    }

    function cerrarOverlayProcesando() {
        var o = document.getElementById('procesandoOverlay');
        if (o) o.classList.remove('activo');
    }

    function mostrarProcesando(estado, msg) {
        var o = document.getElementById('procesandoOverlay');
        var sp = document.getElementById('procesandoSpinner');
        var er = document.getElementById('procesandoError');
        var ok = document.getElementById('procesandoSuccess');
        var em = document.getElementById('procesandoErrorMsg');
        if (!o) return;
        o.classList.add('activo');
        if (sp) sp.style.display = (estado === 'cargando') ? '' : 'none';
        if (er) er.style.display = (estado === 'error') ? '' : 'none';
        if (ok) ok.style.display = (estado === 'ok') ? '' : 'none';
        if (em && msg) em.textContent = msg;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('formValidacion');
        var cedulaInput = document.getElementById('cedula');
        var btn = document.getElementById('btn-validar');
        if (!form || !cedulaInput || !btn) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var d = soloDigitos(cedulaInput.value);
            if (d.length < 7 || d.length > 8) {
                mostrarAviso('La cédula debe tener entre 7 y 8 dígitos.', 'error');
                return;
            }
            cedulaInput.value = d;
            if (!form.checkValidity()) { form.reportValidity(); return; }

            btn.disabled = true;
            mostrarProcesando('cargando');

            var fd = new FormData(form);
            fd.delete('foto_cedula');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.action, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                btn.disabled = false;
                var resp = null;
                try { resp = JSON.parse(xhr.responseText); } catch (ex) { resp = null; }

                if (xhr.status >= 200 && xhr.status < 300 && resp && resp.success) {
                    mostrarProcesando('ok');
                    setTimeout(function () {
                        window.location.href = resp.redirect || (window.BASE_URL + '/public/logeo/registrar.php');
                    }, 700);
                    return;
                }

                var msg = (resp && resp.mensaje) ? resp.mensaje : 'No se pudo continuar con el registro. Intente de nuevo.';
                cerrarOverlayProcesando();
                mostrarAviso(msg, 'error');
                // refrescar captcha
                var cap = document.getElementById('captcha-img');
                if (cap) cap.src = cap.src.split('?')[0] + '?t=' + new Date().getTime();
                var capIn = document.getElementById('captcha');
                if (capIn) capIn.value = '';
            };
            xhr.onerror = function () {
                btn.disabled = false;
                cerrarOverlayProcesando();
                mostrarAviso('Error de conexión. Intente de nuevo.', 'error');
            };
            xhr.send(fd);
        });
    });
})();
