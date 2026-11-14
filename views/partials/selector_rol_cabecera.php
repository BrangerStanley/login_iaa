<?php
$paginaSelectorRol = basename($_SERVER['PHP_SELF'] ?? '');
if (in_array($paginaSelectorRol, ['menu_principal.php', 'selector_rol.php'], true)
    || empty($_SESSION['id_user'])) {
    return;
}

require_once __DIR__ . '/../../models/UsuarioRolModel.php';
try {
    $selectorRoles = (new UsuarioRolModel($conexion ?? null))->rolesDe((int)$_SESSION['id_user']);
} catch (Throwable $e) {
    error_log('selector_rol_cabecera: no se pudieron cargar los roles: ' . $e->getMessage());
    $selectorRoles = [];
}

if (count($selectorRoles) > 1):
    $rolActivoCabecera = (int)($_SESSION['id_rol'] ?? $_SESSION['per_id'] ?? 0);
?>
<form id="selectorRolCabecera" class="d-flex align-items-center gap-2 ms-auto mb-0"
      action="../controllers/cambiarRolActivoController.php" method="POST" hidden>
    <input type="hidden" name="csrftoken" value="<?php echo esc(csrf_token()); ?>">
    <label class="visually-hidden" for="selectorRolCabeceraSelect">Cambiar de rol</label>
    <select id="selectorRolCabeceraSelect" name="id_rol" class="form-select form-select-sm"
            aria-label="Cambiar de rol" onchange="this.form.submit()">
        <?php foreach ($selectorRoles as $rolCabecera): ?>
        <option value="<?php echo (int)$rolCabecera['id']; ?>" <?php echo (int)$rolCabecera['id'] === $rolActivoCabecera ? 'selected' : ''; ?>>
            <?php echo esc(ucfirst((string)$rolCabecera['nombre'])); ?>
        </option>
        <?php endforeach; ?>
    </select>
</form>
<script nonce="<?php echo csp_nonce(); ?>">
    (function () {
        var form = document.getElementById('selectorRolCabecera');
        var contenedor = document.querySelector('header.header .navbar .container-fluid, header.header .navbar .container');
        if (form && contenedor) {
            form.hidden = false;
            contenedor.appendChild(form);
        }
    })();
</script>
<?php endif; ?>
