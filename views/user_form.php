<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1><?= $editing ? 'Editar usuario' : 'Nuevo usuario' ?></h1><p>Define su cuenta y el rol que le corresponde.</p></div><a href="/usuarios" class="button quiet">Volver</a></div>
<section class="card form-card">
    <?php if (isset($errors['general'])): ?><div class="alert error" role="alert"><?= e($errors['general']) ?></div><?php endif; ?>
    <form method="post" action="<?= $editing ? '/usuarios/' . (int) $editing . '/editar' : '/usuarios/nuevo' ?>" class="form-stack">
        <?= csrf_field() ?>
        <div class="form-grid"><label>Nombre de usuario<input name="nombre_usuario" autocomplete="off" minlength="3" maxlength="60" value="<?= old($old,'nombre_usuario') ?>" required><?= field_error($errors,'nombre_usuario') ?></label><label>Correo electrónico<input type="email" name="correo" maxlength="160" value="<?= old($old,'correo') ?>" required><?= field_error($errors,'correo') ?></label>
        <label>Rol<select name="rol_id" id="rol" required><option value="">Selecciona un rol</option><?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>" data-role="<?= e($role['codigo']) ?>" <?= (int) ($old['rol_id'] ?? 0) === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['nombre']) ?></option><?php endforeach; ?></select><?= field_error($errors,'rol_id') ?></label>
        <label class="check-label"><input type="checkbox" name="habilitado" value="1" <?= ($old['habilitado'] ?? false) ? 'checked' : '' ?>> Acceso habilitado</label></div>
        <div class="patient-section" id="patient-fields"><h2>Datos del paciente</h2><p class="field-help">Completa estos campos cuando el rol seleccionado sea Paciente.</p><?php require BASE_PATH . '/views/_patient_fields.php'; ?></div>
        <div class="form-grid"><label><?= $editing ? 'Nueva contraseña (opcional)' : 'Contraseña' ?><input type="password" name="password" autocomplete="new-password" minlength="12" <?= $editing ? '' : 'required' ?>><?= field_error($errors,'password') ?></label><label>Repite la contraseña<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" <?= $editing ? '' : 'required' ?>><?= field_error($errors,'password_confirmation') ?></label></div>
        <p class="field-help"><?= $editing ? 'Deja ambas contraseñas vacías para conservar la actual. ' : '' ?>Usa al menos 12 caracteres. Deshabilitar una cuenta conserva sus datos e historial.</p>
        <div class="form-actions"><button type="submit" class="button primary">Guardar usuario</button><a href="/usuarios" class="button quiet">Cancelar</a></div>
    </form>
</section>
