<?php
// views/partials/equipo_trigger.php
// Enlace del footer que abre la modal del equipo de desarrollo.
// Uso dentro de un <footer>:  include __DIR__ . '/partials/equipo_trigger.php';
// Uso como barra suelta:      $equipoTriggerBar = true; include ...
$equipoTriggerBar = $equipoTriggerBar ?? false;
$GLOBALS['equipoTriggerRenderizado'] = true;
?>
<?php if ($equipoTriggerBar): ?>
<div class="equipo-footer-bar">
<?php endif; ?>
    <button type="button" class="equipo-trigger" data-equipo-open>
        <svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="9" cy="8" r="3"></circle>
            <path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"></path>
            <path d="M16 5.5a3 3 0 0 1 0 5.8"></path>
            <path d="M17.5 14.5c2.1.7 3.5 2.7 3.5 5.5"></path>
        </svg>
        Página web diseñada por
    </button>
<?php if ($equipoTriggerBar): ?>
</div>
<?php endif; ?>
