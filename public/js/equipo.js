/**
 * Carrusel del equipo de desarrollo.
 *
 * Se abre desde el enlace "Página web diseñada por" del footer.
 * Orden fijo: líder, segundo al mando y luego el resto del equipo.
 *
 * Los contactos de Yisnaiker González están pendientes: mientras no se
 * definan, el carrusel muestra un aviso en lugar de los botones.
 * La foto de Luis Díaz está pendiente: se espera img/Equipo/LuisDiaz.jpeg;
 * hasta que exista se muestra la imagen genérica sin_foto.svg.
 */
(function () {
  'use strict';

  var EQUIPO = [
    {
      nombre: 'Branger Stanley',
      cargo: 'Líder de Proyecto y Enlace Institucional',
      foto: 'BrangerStanley.jpeg',
      email: 'cuentadebranger@gmail.com',
      github: 'BrangerStanley',
      instagram: 'br_stanley'
    }
  ];

  var ICONO_CORREO =
    "<svg viewBox='0 0 24 24' width='1.15em' height='1.15em' fill='none' stroke='currentColor' stroke-width='2' " +
    "stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>" +
    "<rect x='2' y='4' width='20' height='16' rx='2'></rect><path d='m2 7 10 6 10-6'></path></svg>";

  var ICONO_GITHUB =
    "<svg viewBox='0 0 24 24' width='1.15em' height='1.15em' fill='none' stroke='currentColor' stroke-width='2' " +
    "stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>" +
    "<path d='M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4'></path>" +
    "<path d='M9 18c-4.51 2-5-2-7-2'></path></svg>";

  var ICONO_INSTAGRAM =
    "<svg viewBox='0 0 24 24' width='1.15em' height='1.15em' fill='none' stroke='currentColor' stroke-width='2' " +
    "stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>" +
    "<rect x='3' y='3' width='18' height='18' rx='5'></rect><circle cx='12' cy='12' r='4'></circle>" +
    "<circle cx='17.5' cy='6.5' r='1' fill='currentColor' stroke='none'></circle></svg>";

  // Reserva espacio para el footer fijo (no debe tapar el final del contenido).
  (function reservarEspacioFooter() {
    var footer = document.querySelector('.iaa-footer');
    if (!footer) {
      return;
    }

    function ajustar() {
      document.body.style.paddingBottom =
        window.getComputedStyle(footer).position === 'fixed' ? footer.offsetHeight + 'px' : '0px';
    }

    ajustar();
    window.addEventListener('resize', ajustar);
    if (window.ResizeObserver) {
      new ResizeObserver(ajustar).observe(footer);
    }
  })();

  var modal = document.getElementById('equipoModal');
  if (!modal) {
    return;
  }

  var triggers = document.querySelectorAll('[data-equipo-open]');
  var stage = modal.querySelector('.equipo-carousel__stage');
  var nameEl = modal.querySelector('.equipo-info__name');
  var roleEl = modal.querySelector('.equipo-info__role');
  var descEl = modal.querySelector('.equipo-info__desc');
  var infoEl = modal.querySelector('.equipo-info');
  var actionsEl = modal.querySelector('.equipo-info__actions');
  var dotsEl = modal.querySelector('.equipo-dots');
  var counterEl = modal.querySelector('.equipo-counter');
  var closeBtn = modal.querySelector('.equipo-modal__close');
  var prevBtn = modal.querySelector('.equipo-carousel__nav--prev');
  var nextBtn = modal.querySelector('.equipo-carousel__nav--next');
  var carousel = modal.querySelector('.equipo-carousel');

  var slides = [];
  var dots = [];
  var active = 0;
  var lastFocused = null;
  var imgBase = modal.getAttribute('data-img-base') || '../img/Equipo/';

  function pad(n) {
    return (n < 10 ? '0' : '') + n;
  }

  function buildSlides() {
    EQUIPO.forEach(function (miembro, i) {
      var figure = document.createElement('figure');
      figure.className = 'equipo-slide';
      figure.setAttribute('data-index', String(i));

      var img = document.createElement('img');
      img.alt = 'Fotografía de ' + miembro.nombre;
      // Carga inmediata: así el fallback de "foto pendiente" también funciona
      // cuando el modal aún está oculto (las imágenes lazy podrían no intentar cargar).
      img.loading = 'eager';
      img.draggable = false;
      img.addEventListener('error', function () {
        if (img.getAttribute('src') !== imgBase + 'sin_foto.svg') {
          img.src = imgBase + 'sin_foto.svg';
          img.alt = 'Fotografía pendiente de ' + miembro.nombre;
        }
      });
      img.src = imgBase + (miembro.foto || 'sin_foto.svg');

      figure.appendChild(img);
      figure.addEventListener('click', function () {
        if (i !== active) {
          goTo(i);
        }
      });

      stage.appendChild(figure);
      slides.push(figure);

      var dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'equipo-dot';
      dot.setAttribute('aria-label', 'Ver a ' + miembro.nombre);
      dot.addEventListener('click', function () {
        goTo(i);
      });
      dotsEl.appendChild(dot);
      dots.push(dot);
    });
  }

  function layout() {
    var total = EQUIPO.length;

    slides.forEach(function (slide, i) {
      // Distancia circular: el carrusel nunca deja huecos.
      var diff = i - active;
      if (diff > total / 2) {
        diff -= total;
      } else if (diff < -total / 2) {
        diff += total;
      }

      var distance = Math.abs(diff);
      var capped = Math.max(-2, Math.min(2, diff));

      slide.setAttribute('data-distance', String(distance));
      slide.style.setProperty('--offset', diff);
      slide.style.setProperty('--scale', distance === 0 ? '1' : (distance === 1 ? '0.82' : '0.68'));
      slide.style.setProperty('--rotate', (diff < 0 ? 14 : diff > 0 ? -14 : 0) + 'deg');
      slide.style.setProperty('--opacity', distance === 0 ? '1' : (distance === 1 ? '0.38' : '0.14'));
      slide.style.setProperty('--blur', distance === 0 ? '0' : (distance === 1 ? '1px' : '2px'));
      slide.style.zIndex = String(10 - distance);
      slide.style.pointerEvents = Math.abs(capped) <= 1 ? 'auto' : 'none';
      slide.setAttribute('aria-hidden', distance === 0 ? 'false' : 'true');
    });

    dots.forEach(function (dot, i) {
      dot.classList.toggle('is-active', i === active);
      dot.setAttribute('aria-current', i === active ? 'true' : 'false');
    });

    counterEl.textContent = pad(active + 1) + ' / ' + pad(EQUIPO.length);
  }

  function renderInfo() {
    var miembro = EQUIPO[active];

    nameEl.textContent = miembro.nombre;
    roleEl.textContent = miembro.cargo;
    descEl.textContent = miembro.descripcion || '';
    descEl.hidden = !miembro.descripcion;

    // Eliminar lista de destacados previa si existe
    var existente = modal.querySelector('.equipo-destacados');
    if (existente) {
      existente.remove();
    }

    // Si tiene destacados exclusivos (líder)
    if (miembro.destacadosLider && miembro.destacadosLider.length > 0) {
      var ul = document.createElement('ul');
      ul.className = 'equipo-destacados';
      miembro.destacadosLider.forEach(function (item) {
        var li = document.createElement('li');
        li.textContent = item;
        ul.appendChild(li);
      });
      descEl.parentNode.insertBefore(ul, actionsEl);
    }

    actionsEl.innerHTML = '';

    if (miembro.email) {
      var mail = document.createElement('a');
      mail.className = 'equipo-btn equipo-btn--mail';
      mail.href = 'mailto:' + miembro.email;
      mail.innerHTML = ICONO_CORREO;
      mail.setAttribute('aria-label', 'Enviar correo a ' + miembro.nombre);
      actionsEl.appendChild(mail);
    }

    if (miembro.github) {
      var gh = document.createElement('a');
      gh.className = 'equipo-btn equipo-btn--gh';
      gh.href = 'https://github.com/' + miembro.github;
      gh.target = '_blank';
      gh.rel = 'noopener noreferrer';
      gh.innerHTML = ICONO_GITHUB;
      gh.setAttribute('aria-label', 'Ver GitHub de ' + miembro.nombre);
      actionsEl.appendChild(gh);
    }

    if (miembro.instagram) {
      var ig = document.createElement('a');
      ig.className = 'equipo-btn equipo-btn--ig';
      ig.href = 'https://instagram.com/' + miembro.instagram;
      ig.target = '_blank';
      ig.rel = 'noopener noreferrer';
      ig.innerHTML = ICONO_INSTAGRAM;
      ig.setAttribute('aria-label', 'Ver Instagram de ' + miembro.nombre);
      actionsEl.appendChild(ig);
    }

    if (!miembro.email && !miembro.github && !miembro.instagram) {
      var pendiente = document.createElement('span');
      pendiente.className = 'equipo-btn equipo-btn--pendiente';
      pendiente.textContent = 'Datos de contacto pendientes';
      actionsEl.appendChild(pendiente);
    }

    infoEl.classList.remove('is-changing');
    // Reinicia la animación de la ficha en cada cambio.
    void infoEl.offsetWidth;
    infoEl.classList.add('is-changing');
  }

  function goTo(index) {
    var total = EQUIPO.length;
    active = ((index % total) + total) % total;
    layout();
    renderInfo();
  }

  function openModal() {
    lastFocused = document.activeElement;
    goTo(0);
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('equipo-modal-open');
    closeBtn.focus();
  }

  function closeModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('equipo-modal-open');
    if (lastFocused && typeof lastFocused.focus === 'function') {
      lastFocused.focus();
    }
  }

  function onKeydown(event) {
    if (!modal.classList.contains('is-open')) {
      return;
    }

    if (event.key === 'Escape') {
      closeModal();
    } else if (event.key === 'ArrowLeft') {
      event.preventDefault();
      goTo(active - 1);
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      goTo(active + 1);
    } else if (event.key === 'Tab') {
      // Mantiene el foco dentro de la modal.
      var focusables = modal.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );
      if (!focusables.length) {
        return;
      }
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  }

  // Deslizamiento táctil.
  var touchStartX = null;
  carousel.addEventListener('touchstart', function (event) {
    touchStartX = event.changedTouches[0].clientX;
  }, { passive: true });

  carousel.addEventListener('touchend', function (event) {
    if (touchStartX === null) {
      return;
    }
    var delta = event.changedTouches[0].clientX - touchStartX;
    if (Math.abs(delta) > 40) {
      goTo(active + (delta < 0 ? 1 : -1));
    }
    touchStartX = null;
  }, { passive: true });

  buildSlides();
  layout();

  triggers.forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      openModal();
    });
  });

  closeBtn.addEventListener('click', closeModal);
  prevBtn.addEventListener('click', function () { goTo(active - 1); });
  nextBtn.addEventListener('click', function () { goTo(active + 1); });

  modal.addEventListener('click', function (event) {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', onKeydown);

  // Precarga las fotos laterales para que el carrusel no parpadee.
  EQUIPO.forEach(function (miembro) {
    var preload = new Image();
    preload.src = imgBase + miembro.foto;
  });
})();
