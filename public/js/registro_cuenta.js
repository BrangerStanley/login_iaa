(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form');
        var pass = document.getElementById('pass');
        var passConfirm = document.getElementById('pass_confirm');
        var togglePass = document.getElementById('toggle-pass');
        var togglePassConfirm = document.getElementById('toggle-pass-confirm');
        var iconPass = document.getElementById('icon-pass');
        var iconPassConfirm = document.getElementById('icon-pass-confirm');
        var passReqs = document.getElementById('pass-reqs');
        var telefono = document.getElementById('telefono');
        var nombre = document.getElementById('nombre');
        var correo = document.getElementById('correo');

        function limpiarError() {
            if (passConfirm) passConfirm.setCustomValidity('');
            if (pass) pass.setCustomValidity('');
        }

        function toggleVisibility(btn, icon, input) {
            if (!btn || !input) return;
            btn.addEventListener('click', function () {
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'bx bxs-show';
                    btn.setAttribute('aria-label', 'Ocultar contraseña');
                } else {
                    input.type = 'password';
                    icon.className = 'bx bx-hide';
                    btn.setAttribute('aria-label', 'Mostrar contraseña');
                }
            });
        }

        function requisitosPassword(val) {
            return [
                { label: 'Mínimo 8 caracteres', ok: val.length >= 8 },
                { label: 'Máximo 72 caracteres', ok: val.length <= 72 },
                { label: 'Al menos una mayúscula', ok: /[A-Z]/.test(val) },
                { label: 'Al menos una minúscula', ok: /[a-z]/.test(val) },
                { label: 'Al menos un número', ok: /[0-9]/.test(val) },
                { label: 'Al menos un carácter especial', ok: /[^A-Za-z0-9]/.test(val) }
            ];
        }

        function validatePassword() {
            if (!pass || !passReqs) return true;
            var val = pass.value;
            var checks = requisitosPassword(val);
            var html = '';
            var todoOk = val.length > 0;
            for (var i = 0; i < checks.length; i++) {
                var c = checks[i];
                var cls = val.length === 0 ? 'fail' : (c.ok ? 'ok' : 'fail');
                var icon = val.length === 0 ? '' : (c.ok ? '✓' : '✗');
                if (!c.ok) todoOk = false;
                html += '<span class="req ' + cls + '">' + icon + ' ' + c.label + '</span>';
            }
            passReqs.innerHTML = html;
            return todoOk;
        }

        if (pass) {
            pass.addEventListener('input', function () {
                limpiarError();
                validatePassword();
            });
        }
        if (passConfirm) passConfirm.addEventListener('input', limpiarError);

        if (togglePass && pass && iconPass) {
            toggleVisibility(togglePass, iconPass, pass);
        }
        if (togglePassConfirm && passConfirm && iconPassConfirm) {
            toggleVisibility(togglePassConfirm, iconPassConfirm, passConfirm);
        }

        // El teléfono se envía solo con dígitos; en pantalla se toleran
        // espacios, guiones, puntos y paréntesis.
        function normalizarTelefono() {
            if (!telefono) return;
            telefono.value = telefono.value.replace(/[^\d\s.\-()]/g, '');
        }
        if (telefono) {
            telefono.addEventListener('input', normalizarTelefono);
        }

        // --- Política de privacidad -------------------------------------
        var ppAcepto = document.getElementById('pp-acepto');
        var ppLink = document.getElementById('pp-ver-politica');
        var ppModal = document.getElementById('modalPoliticaPrivacidad');
        var ppDialog = ppModal ? ppModal.querySelector('.privacy-dialog') : null;
        var ppLastFocus = null;

        function cerrarPolitica() {
            if (!ppModal) return;
            ppModal.hidden = true;
            ppModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('privacy-modal-open');
            if (ppLastFocus) ppLastFocus.focus();
        }

        function abrirPolitica() {
            if (!ppModal) return;
            ppLastFocus = document.activeElement;
            ppModal.hidden = false;
            ppModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('privacy-modal-open');
            var closeButton = ppModal.querySelector('.privacy-close');
            if (closeButton) closeButton.focus();
            else if (ppDialog) ppDialog.focus();
        }

        if (ppLink) {
            ppLink.addEventListener('click', function (e) {
                e.preventDefault();
                abrirPolitica();
            });
        }

        if (ppModal) {
            var closeButtons = ppModal.querySelectorAll('[data-privacy-close]');
            for (var i = 0; i < closeButtons.length; i++) {
                closeButtons[i].addEventListener('click', cerrarPolitica);
            }
            ppModal.addEventListener('click', function (e) {
                if (e.target === ppModal) cerrarPolitica();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !ppModal.hidden) cerrarPolitica();
            });
        }

        function validarPrivacidad() {
            if (!ppAcepto) return true;
            var wrap = ppAcepto.closest('.privacy-consent');
            if (!wrap) return true;
            if (ppAcepto.checked) {
                wrap.classList.remove('is-invalid');
                return true;
            }
            wrap.classList.add('is-invalid');
            ppAcepto.focus();
            return false;
        }

        if (ppAcepto) {
            ppAcepto.addEventListener('change', validarPrivacidad);
        }

        function recortarCampos() {
            if (nombre) nombre.value = nombre.value.replace(/\s+/g, ' ').trim();
            if (correo) correo.value = correo.value.trim();
            if (telefono) normalizarTelefono();
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                recortarCampos();
                limpiarError();

                // 1. Contraseña: longitud, complejidad y coincidencia.
                if (pass && !validatePassword()) {
                    e.preventDefault();
                    pass.setCustomValidity('La contraseña no cumple todos los requisitos.');
                    pass.reportValidity();
                    return;
                }
                if (pass && passConfirm && pass.value !== passConfirm.value) {
                    e.preventDefault();
                    passConfirm.setCustomValidity('Las contraseñas no coinciden.');
                    passConfirm.reportValidity();
                    return;
                }

                // 2. Resto de reglas nativas (correo, fecha, teléfono, captcha).
                if (!form.checkValidity()) {
                    e.preventDefault();
                    form.reportValidity();
                    return;
                }

                // 3. Política de privacidad.
                if (!validarPrivacidad()) {
                    e.preventDefault();
                    abrirPolitica();
                    return;
                }

                // 4. Evitar dobles envíos.
                var boton = form.querySelector('button[type="submit"]');
                if (boton) {
                    boton.disabled = true;
                    boton.textContent = 'Creando cuenta...';
                }
                form.submit();
            });
        }
    });
})();
