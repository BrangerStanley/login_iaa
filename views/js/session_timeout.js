(function () {
    // Debe coincidir con SESSION_TIMEOUT_SECONDS de config/session_timeout.php.
    const TIMEOUT_SECONDS = 180; // Tiempo total antes del logout (3 minutos por inactividad)
    const WARNING_SECONDS = 30; // Tiempo límite para mostrar la advertencia
    const KEEP_ALIVE_URL = '../controllers/keep_alive.php';
    const LOGOUT_URL = '../controllers/logoutController.php';
    const PING_INTERVAL = 60000; // Cada 60 s de actividad se refresca el servidor
    const RETRY_DELAY = 1200; // Espera antes de reintentar un latido fallido

    let startTime = Date.now();
    let warningShown = false;
    let intervalId = null;
    let modalElement = null;
    let countdownElement = null;
    let lastPingTime = 0;

    function createWarningModal() {
        const existing = document.getElementById('sessionTimeoutModal');
        if (existing) {
            modalElement = existing;
            countdownElement = existing.querySelector('#sessionTimeoutCountdown');
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.id = 'sessionTimeoutModal';
        wrapper.style.position = 'fixed';
        wrapper.style.top = '0';
        wrapper.style.left = '0';
        wrapper.style.width = '100%';
        wrapper.style.height = '100%';
        wrapper.style.backgroundColor = 'rgba(0, 0, 0, 0.55)';
        wrapper.style.display = 'none';
        wrapper.style.alignItems = 'center';
        wrapper.style.justifyContent = 'center';
        wrapper.style.zIndex = '99999';
        wrapper.innerHTML = `
            <div style="max-width: 420px; width: 90%; background: #ffffff; border-radius: 12px; padding: 24px; box-shadow: 0 14px 40px rgba(0,0,0,0.18); text-align: center;">
                <h2 style="margin: 0 0 12px; font-size: 1.25rem; color: #1b2a41;">Atención</h2>
                <p style="margin: 0 0 18px; font-size: 0.95rem; color: #4b5563; line-height: 1.5;">
                    La sesión cerrará en <strong><span id="sessionTimeoutCountdown">60</span> segundos</strong> si no se confirma.
                </p>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; justify-content: center;">
                    <button id="sessionTimeoutContinue" style="border: none; background: #0d6efd; color: #ffffff; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 600;">Seguir conectado</button>
                    <button id="sessionTimeoutLogout" style="border: 1px solid #d1d5db; background: #ffffff; color: #111827; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 600;">Cerrar sesión ahora</button>
                </div>
            </div>
        `;

        document.body.appendChild(wrapper);
        modalElement = wrapper;
        countdownElement = wrapper.querySelector('#sessionTimeoutCountdown');

        wrapper.querySelector('#sessionTimeoutContinue').addEventListener('click', keepSessionAlive);
        wrapper.querySelector('#sessionTimeoutLogout').addEventListener('click', () => {
            window.location.href = LOGOUT_URL;
        });
    }

    function showWarning(remaining) {
        if (!modalElement) {
            createWarningModal();
        }
        if (countdownElement) {
            countdownElement.textContent = String(remaining);
        }
        modalElement.style.display = 'flex';
        warningShown = true;
    }

    function hideWarning() {
        if (modalElement) {
            modalElement.style.display = 'none';
        }
    }

    function updateCountdown(remaining) {
        if (countdownElement) {
            countdownElement.textContent = String(remaining > 0 ? remaining : 0);
        }
    }

    function sessionExpired(message) {
        const text = message || 'La sesión ha expirado. Serás redirigido al login.';
        if (modalElement) {
            modalElement.querySelector('p').textContent = text;
        }
        hideWarning();
        // Cerrar la sesión en el servidor (marca 'inactivo' y destruye la sesión PHP)
        // para que el siguiente login no quede bloqueado por una sesión 'activa' fantasma.
        if (navigator.sendBeacon) {
            navigator.sendBeacon(LOGOUT_URL);
        } else {
            fetch(LOGOUT_URL, { method: 'GET', credentials: 'same-origin', keepalive: true }).catch(function () {});
        }
        window.location.href = '../public/logeo/login.php?msg=' + encodeURIComponent(message || 'Sesion expirada por inactividad');
    }

    // Envía la petición de "seguir vivo" al servidor.
    // onSuccess(data): el servidor confirmó que la sesión sigue activa.
    // onExpired(message): el servidor informó que la sesión ya expiró.
    function callKeepAlive(onSuccess, onExpired) {
        fetch(KEEP_ALIVE_URL, {
            method: 'POST',
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data && data.success === true) {
                onSuccess(data);
            } else {
                onExpired((data && data.message) ? data.message : 'La sesión ha expirado.');
            }
        })
        .catch(() => {
            // Error transitorio de red: NO cerramos la sesión por esto.
            onSuccess(null);
        });
    }

    // Un solo latido fallido no debe cerrar la sesión: puede ser una petición
    // paralela que llegó justo mientras se renovaba la sesión, o un fallo
    // transitorio de red. Se reintenta una vez y solo se da por expirada si el
    // servidor vuelve a negar la sesión.
    function callKeepAliveConReintento(onSuccess, onExpired) {
        callKeepAlive(onSuccess, function (message) {
            window.setTimeout(function () {
                callKeepAlive(onSuccess, onExpired);
            }, RETRY_DELAY);
        });
    }

    // Sincroniza el punto rojo del menú (si existe en esta vista) con el
    // contador que viaja dentro del propio latido: no hace falta un poller
    // adicional de notificaciones.
    function actualizarBadgeDesdeLatido(data) {
        const badge = document.getElementById('badge_notificaciones_sidebar');
        if (!badge) return;
        const noLeidas = parseInt(data.no_leidas || 0);
        badge.style.display = (noLeidas > 0) ? 'inline-block' : 'none';
    }

    // Pausa el polling mientras la pestaña está en segundo plano y lo retoma
    // (con validación inmediata) al volver: reduce drásticamente las peticiones
    // cuando hay varias pestañas abiertas.
    let intervaloLatido = null;
    function detenerPolling() {
        if (intervaloLatido) { clearInterval(intervaloLatido); intervaloLatido = null; }
    }
    function iniciarPolling() {
        if (intervaloLatido || document.hidden) return;
        intervaloLatido = window.setInterval(function () {
            callKeepAliveConReintento(function (data) { actualizarBadgeDesdeLatido(data || {}); }, sessionExpired);
        }, 15000);
    }

    function keepSessionAlive() {
        callKeepAliveConReintento(function (data) {
            lastPingTime = Date.now();
            startTime = Date.now();
            warningShown = false;
            hideWarning();
            actualizarBadgeDesdeLatido(data || {});
        }, sessionExpired);
    }

    // Refresca el servidor cuando hay actividad del usuario, para evitar que
    // la sesión expire en el servidor mientras el usuario sigue trabajando.
    function pingServerOnActivity() {
        if (Date.now() - lastPingTime < PING_INTERVAL) return;
        callKeepAliveConReintento(function (data) {
            lastPingTime = Date.now();
            actualizarBadgeDesdeLatido(data || {});
        }, sessionExpired);
    }

    function tick() {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        const remaining = TIMEOUT_SECONDS - elapsed;

        if (remaining <= 0) {
            clearInterval(intervalId);
            sessionExpired();
            return;
        }

        if (remaining <= WARNING_SECONDS) {
            if (!warningShown) {
                showWarning(remaining);
            } else {
                updateCountdown(remaining);
            }
        }
    }

    function resetTimer() {
        // CORRECCIÓN: Si el aviso ya está mostrado, bloqueamos el reseteo por movimiento del ratón/teclado.
        // El usuario debe pulsar "Seguir conectado" para enviar la petición al servidor y ocultar el aviso.
        if (warningShown) {
            return;
        }

        startTime = Date.now();
        // Mantiene viva la sesión en el servidor durante la actividad del usuario.
        pingServerOnActivity();
    }

    // ---------------------------------------------------------------------------
    // Detección de cierre del navegador sin cerrar la sesión.
    //
    // El problema: 'beforeunload'/'pagehide' también se disparan en recargas y
    // en navegaciones internas, por lo que no se puede enviar el logout a ciegas
    // (cada F5 cerraría la sesión). Se distinguen con dos señales combinadas:
    //   1) Se marca 'sessionStorage' cuando el usuario pulsa un enlace interno o
    //      envía un formulario (eso sí es una navegación que mantiene la sesión).
    //   2) En Chrome/Edge, al cerrar realmente la pestaña el 'pagehide' llega con
    //      visibilityState 'hidden'; en una recarga de una pestaña visible llega
    //      con 'visible'.
    // Si el cierre es legítimo, se avisa al servidor con sendBeacon y la sesión
    // queda marcada 'inactivo' (f_sal registrada) sin esperar al podador.
    // ---------------------------------------------------------------------------
    const NAV_FLAG_KEY = 'iaa_navegacion_interna';
    const NAV_GRACE_MS = 15000;

    function marcarNavegacionInterna() {
        try { sessionStorage.setItem(NAV_FLAG_KEY, String(Date.now())); } catch (e) { /* sin storage: sin señales */ }
    }

    function navegacionInternaReciente() {
        try {
            const marca = parseInt(sessionStorage.getItem(NAV_FLAG_KEY) || '0', 10);
            return marca > 0 && (Date.now() - marca) < NAV_GRACE_MS;
        } catch (e) {
            return false;
        }
    }

    document.addEventListener('click', function (e) {
        const ancla = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        const href = ancla ? (ancla.getAttribute('href') || '') : '';
        if (!href || href.startsWith('#') || href.toLowerCase().startsWith('javascript:')) return;
        marcarNavegacionInterna();
    }, true);

    document.addEventListener('submit', marcarNavegacionInterna, true);

    function alDescargarPagina(evento) {
        // Descartes de bfcache (botón atrás/adelante): la página revivió, no se cierra.
        if (evento && evento.persisted) return;
        // Navegación interna reciente (enlace/formulario): mantiene la sesión.
        if (navegacionInternaReciente()) return;
        // Tabla visible recargándose o cambiando de página: sesión sigue viva.
        if (!document.hidden) return;
        if (navigator.sendBeacon) {
            navigator.sendBeacon(KEEP_ALIVE_URL.replace('keep_alive.php', 'cerrar_sesion_navegador.php'));
        }
    }

    window.addEventListener('pagehide', alDescargarPagina);

    document.addEventListener('DOMContentLoaded', function () {
        createWarningModal();
        intervalId = window.setInterval(tick, 1000);
        ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll'].forEach(eventName => {
            window.addEventListener(eventName, resetTimer, true);
        });

        // Latido periódico (pausado si la pestaña está oculta) que además
        // valida sesión remota/suspensión y sincroniza el badge de notificaciones.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                detenerPolling();
            } else {
                iniciarPolling();
                // Validación inmediata al volver a la pestaña
                callKeepAliveConReintento(function (data) { actualizarBadgeDesdeLatido(data || {}); }, sessionExpired);
            }
        });
        iniciarPolling();
    });
})();