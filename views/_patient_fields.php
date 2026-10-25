<div class="form-grid">
    <label>CI<input name="ci" maxlength="30" value="<?= old($old,'ci') ?>" <?= isset($registration) ? 'required' : '' ?>><?= field_error($errors,'ci') ?></label>
    <label>Teléfono<input type="tel" name="telefono" autocomplete="tel" maxlength="30" value="<?= old($old,'telefono') ?>" <?= isset($registration) ? 'required' : '' ?>><?= field_error($errors,'telefono') ?></label>
    <label>Nombres<input name="nombres" autocomplete="given-name" maxlength="100" value="<?= old($old,'nombres') ?>" <?= isset($registration) ? 'required' : '' ?>><?= field_error($errors,'nombres') ?></label>
    <label>Apellidos<input name="apellidos" autocomplete="family-name" maxlength="100" value="<?= old($old,'apellidos') ?>" <?= isset($registration) ? 'required' : '' ?>><?= field_error($errors,'apellidos') ?></label>
</div>
