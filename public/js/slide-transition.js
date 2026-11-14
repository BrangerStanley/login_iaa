document.addEventListener('DOMContentLoaded', function () {

    /* === Animación de salida para "Crear cuenta" (login → validación) === */
    var linkCrear = document.getElementById('linkCrearCuenta');
    if (linkCrear) {
        linkCrear.addEventListener('click', function (e) {
            e.preventDefault();
            document.body.classList.add('slide-to-left');
            setTimeout(function () {
                window.location.href = 'validacion.php?slide=left';
            }, 500);
        });
    }

    /* === Animación de salida para "Iniciar Sesión" (validación → login) === */
    var linkLogin = document.getElementById('linkIniciarSesion');
    if (linkLogin) {
        linkLogin.addEventListener('click', function (e) {
            e.preventDefault();
            document.body.classList.add('slide-to-right');
            setTimeout(function () {
                window.location.href = 'login.php?slide=right';
            }, 500);
        });
    }

});
