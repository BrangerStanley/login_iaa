<?php
// views/partials/equipo_modal.php
// Carga los estilos, el markup y el script del carrusel del equipo.
// Se incluye una sola vez por página, justo antes de </body>.
if (!empty($GLOBALS['equipoModalRenderizado'])) {
    return;
}
$GLOBALS['equipoModalRenderizado'] = true;

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../../config/rutas.php';
}
$equipoBase = rtrim(BASE_URL, '/');
?>
<?php if (empty($equipoCssYaCargado)): ?>
<link rel="stylesheet" href="<?php echo $equipoBase; ?>/public/css/equipo.css?v=11">
<?php endif; ?>

<!-- MODAL EQUIPO DE DESARROLLO -->
<div class="equipo-modal" id="equipoModal" role="dialog" aria-modal="true" aria-hidden="true"
    aria-labelledby="equipoModalTitle" data-img-base="<?php echo $equipoBase; ?>/img/Equipo/">
    <div class="equipo-modal__panel">
        <button type="button" class="equipo-modal__close" aria-label="Cerrar">&times;</button>

        <p class="equipo-modal__eyebrow">Equipo de desarrollo</p>
        <h2 class="equipo-modal__title" id="equipoModalTitle">Página web diseñada por</h2>
        <p class="equipo-modal__subtitle">Instituto Autónomo de Ayuda a los Funcionarios</p>

        <div class="equipo-carousel" tabindex="-1" aria-roledescription="carrusel"
            aria-label="Integrantes del equipo de desarrollo">
            <div class="equipo-carousel__stage"></div>
            <button type="button" class="equipo-carousel__nav equipo-carousel__nav--prev"
                aria-label="Integrante anterior">&lsaquo;</button>
            <button type="button" class="equipo-carousel__nav equipo-carousel__nav--next"
                aria-label="Siguiente integrante">&rsaquo;</button>
        </div>

        <div class="equipo-info" aria-live="polite">
            <h3 class="equipo-info__name"></h3>
            <span class="equipo-info__role"></span>
            <p class="equipo-info__desc"></p>
            <div class="equipo-info__actions"></div>
        </div>

        <div class="equipo-carousel__footer">
            <div class="equipo-dots" role="tablist" aria-label="Seleccionar integrante"></div>
            <span class="equipo-counter"></span>
        </div>
    </div>
</div>

<script src="<?php echo $equipoBase; ?>/public/js/equipo.js?v=11"></script>
