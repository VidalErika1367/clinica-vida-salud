<div class="page-heading"><div><span class="eyebrow">PACIENTES</span><h1><?= $editing ? 'Editar paciente' : 'Nuevo paciente' ?></h1><p><?= $editing ? 'Actualiza sus datos personales y de contacto.' : 'Registra al paciente para su atención en la clínica.' ?></p></div><a class="button quiet" href="/pacientes">Volver al listado</a></div>
<section class="card form-card">
    <?php foreach (['general','version'] as $key): ?><?php if (isset($errors[$key])): ?><div class="alert error" role="alert"><?= e($errors[$key]) ?></div><?php endif; ?><?php endforeach; ?>
    <?php if ($patient): ?><p class="patient-state"><?= e(App\PatientService::code((int) $patient['id'])) ?> · <span class="badge <?= $patient['activo'] ? 'enabled' : 'disabled' ?>"><?= $patient['activo'] ? 'Activo' : 'Inactivo' ?></span></p><?php endif; ?>
    <form class="form-stack" method="post" action="<?= $editing ? '/pacientes/' . (int) $editing . '/editar' : '/pacientes/nuevo' ?>">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="version" value="<?= e($version) ?>"><?php endif; ?>
        <div class="form-grid">
            <label>CI *<input name="ci" value="<?= old($old,'ci') ?>" maxlength="30" required><?= field_error($errors,'ci') ?></label>
            <label>Teléfono *<input type="tel" name="telefono" value="<?= old($old,'telefono') ?>" maxlength="30" required><?= field_error($errors,'telefono') ?></label>
            <label>Nombres *<input name="nombres" value="<?= old($old,'nombres') ?>" maxlength="100" autocomplete="given-name" required><?= field_error($errors,'nombres') ?></label>
            <label>Apellidos *<input name="apellidos" value="<?= old($old,'apellidos') ?>" maxlength="100" autocomplete="family-name" required><?= field_error($errors,'apellidos') ?></label>
            <label>Fecha de nacimiento<input type="date" name="fecha_nacimiento" value="<?= old($old,'fecha_nacimiento') ?>" max="<?= e(date('Y-m-d')) ?>"><?= field_error($errors,'fecha_nacimiento') ?></label>
            <label>Sexo<input name="sexo" value="<?= old($old,'sexo') ?>" maxlength="30"><?= field_error($errors,'sexo') ?></label>
            <label>Correo de contacto<input type="email" name="correo" value="<?= old($old,'correo') ?>" maxlength="160" autocomplete="email"><?= field_error($errors,'correo') ?></label>
            <label>Dirección<input name="direccion" value="<?= old($old,'direccion') ?>" maxlength="250" autocomplete="street-address"><?= field_error($errors,'direccion') ?></label>
        </div>
        <p class="field-help">Los campos marcados con * son obligatorios. El correo de contacto puede ser diferente del correo usado para iniciar sesión.</p>
        <?php if (!$editing): ?><p class="field-help">El paciente se registra activo. Este formulario crea su ficha; las cuentas de acceso se administran por separado.</p><?php endif; ?>
        <?php if ($editing && !$patient['activo']): ?><p class="field-help">Actualizar estos datos conserva el estado inactivo del paciente.</p><?php endif; ?>
        <div class="form-actions"><button class="button primary" type="submit">Guardar paciente</button><a class="button quiet" href="<?= $editing ? '/pacientes/' . (int) $editing : '/pacientes' ?>">Cancelar</a></div>
    </form>
</section>
