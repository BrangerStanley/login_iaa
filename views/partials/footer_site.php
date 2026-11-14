<?php
// views/partials/footer_site.php
// Footer unificado para todas las vistas del sistema.
if (!empty($GLOBALS['footerSiteRenderizado'])) {
    return;
}
$GLOBALS['footerSiteRenderizado'] = true;
?>
<footer class="iaa-footer">
    <div class="iaa-footer__inner">
        <div class="iaa-footer__brand">IAA<span class="iaa-footer__dot">.</span></div>
        <div class="iaa-footer__info">
            <p class="iaa-footer__text">Instituto Autónomo de Ayuda a los Funcionarios del Estado Yaracuy</p>
            <p class="iaa-footer__copy">© <?php echo date('Y'); ?> IAA. Todos los derechos reservados.</p>
        </div>

        <div class="iaa-footer__team">
            <?php include __DIR__ . '/equipo_trigger.php'; ?>
        </div>
    </div>
</footer>
<?php include __DIR__ . '/selector_rol_cabecera.php'; ?>