<?php
// views/partials/politica_privacidad.php
// Modal con la Política de Privacidad y Protección de Datos del IAA.
// Uso:
//   include __DIR__ . '/../../views/partials/politica_privacidad.php';
// Params opcionales: $privacidad_modal_id (id del modal), $privacidad_texto_pie
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../../config/seguridad.php';
}

$privacidad_modal_id = $privacidad_modal_id ?? 'modalPoliticaPrivacidad';
$privacidad_texto_pie = $privacidad_texto_pie ?? 'Al aceptar, declaras haber leído y comprendido la Política de Privacidad y Protección de Datos del Instituto Autónomo de Ayuda a los Funcionarios.';
?>
<div class="privacy-modal" id="<?php echo htmlspecialchars($privacidad_modal_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
     role="dialog" aria-modal="true"
     aria-labelledby="<?php echo htmlspecialchars($privacidad_modal_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>Titulo"
     aria-hidden="true" hidden>
    <section class="privacy-dialog" tabindex="-1">
            <header class="privacy-header">
                <h2 id="<?php echo htmlspecialchars($privacidad_modal_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>Titulo">
                    <i class='bx bxs-shield-alt-2'></i> Política de Privacidad y Protección de Datos
                </h2>
                <button type="button" class="privacy-close" data-privacy-close aria-label="Cerrar">×</button>
            </header>

            <div class="privacy-content">
                <div class="pp-cuerpo">
                    <p>
                        El Instituto Autónomo de Ayuda a los Funcionarios,
                        en cumplimiento de la Constitución de la República Bolivariana de Venezuela y de la
                        Ley Orgánica de Protección de Datos Personales, protege la información personal que
                        usted nos proporciona.
                    </p>

                    <h3>1. Datos que recopilamos</h3>
                    <p>Para crear su cuenta solicitamos:</p>
                    <ul>
                        <li>Cédula de identidad y fecha de nacimiento, proporcionadas durante el registro.</li>
                        <li>Nombre completo.</li>
                        <li>Correo electrónico.</li>
                        <li>Número de teléfono.</li>
                        <li>Contraseña de acceso (almacenada cifrada mediante <em>hash</em>, nunca en texto plano).</li>
                    </ul>

                    <h3>2. Finalidad del tratamiento</h3>
                    <p>Sus datos se utilizan exclusivamente para:</p>
                    <ul>
                        <li>Crear y administrar su cuenta de usuario en el sistema IAA.</li>
                        <li>Gestionar la afiliación de usted y de sus beneficiarios.</li>
                        <li>Tratar solicitudes de servicios, citas y reembolso médico.</li>
                        <li>Notificarle sobre el estado de su cuenta y de sus trámites.</li>
                        <li>Cumplir obligaciones legales, de auditoría y de seguridad de la información.</li>
                    </ul>

                    <h3>3. Base jurídica</h3>
                    <p>
                        El tratamiento se sustenta en su consentimiento inequívoco al marcar la casilla de
                        aceptación y aceptar esta política, y en el cumplimiento de los fines públicos
                        asignados al Instituto.
                    </p>

                    <h3>4. Conservación y seguridad</h3>
                    <p>
                        Sus datos se conservarán mientras su cuenta esté activa y por los plazos legales
                        aplicables. El IAA aplica medidas de seguridad informáticas y físicas para
                        protegerlos frente a accesos no autorizados, alteración, pérdida o destrucción.
                    </p>

                    <h3>5. Compartir con terceros</h3>
                    <p>
                        No compartimos ni cedemos sus datos personales con terceros sin su autorización
                        previa, salvo aquellos casos exigidos por ley, por orden de autoridad competente o
                        cuando sea necesario para prestar el servicio requerido dentro del sistema institucional.
                    </p>

                    <h3>6. Derechos ARCO</h3>
                    <p>
                        Usted tiene derecho a <strong>Acceder</strong>, <strong>Rectificar</strong>,
                        <strong>Cancelar</strong> y <strong>Oponerse</strong> al tratamiento de sus datos,
                        así como a revocar el consentimiento otorgado. Para ejercerlos, comuníquese con la
                        oficina de protección de datos del IAA indicando su cédula y el derecho que
                        desea ejercer.
                    </p>

                    <h3>7. Menores de edad</h3>
                    <p>
                        El registro está habilitado para funcionarios mayores de edad. El tratamiento de
                        datos de menores corresponde exclusivamente a su representante legal.
                    </p>

                    <h3>8. Cambios en esta política</h3>
                    <p>
                        Cualquier modificación sustancial será publicada en esta misma plataforma y le será
                        notificada al momento de su próximo acceso al sistema.
                    </p>
                </div>
            </div>

            <footer class="privacy-footer">
                <small><?php echo htmlspecialchars($privacidad_texto_pie, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></small>
                <button type="button" class="privacy-accept" data-privacy-close>
                    Entendido
                </button>
            </footer>
    </section>
</div>