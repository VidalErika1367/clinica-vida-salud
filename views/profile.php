<div class="page-heading"><div><span class="eyebrow">MI ESPACIO</span><h1>Mi perfil</h1><p>Mantén actualizada tu información de contacto y tus datos médicos básicos.</p></div><span class="badge <?= $profile['activo'] ? 'enabled' : 'disabled' ?>"><?= $profile['activo'] ? 'Activo' : 'Inactivo' ?></span></div>
<?php if (!$profile['activo']): ?><div class="alert warning">Tu ficha está inactiva para nuevas reservas. Puedes actualizar tu perfil y solicitar la reactivación en recepción.</div><?php endif; ?>
<section class="card form-card">
    <h2><?= e($profile['nombres'] . ' ' . $profile['apellidos']) ?></h2>
    <dl class="account-details patient-details">
        <div><dt>Código</dt><dd><?= e(App\PatientService::code((int) $profile['id'])) ?></dd></div>
        <div><dt>CI</dt><dd><?= e($profile['ci']) ?></dd></div>
        <div><dt>Nacimiento</dt><dd><?= e($profile['fecha_nacimiento'] ?: 'No informado') ?></dd></div>
        <div><dt>Sexo</dt><dd><?= e($profile['sexo'] ?: 'No informado') ?></dd></div>
    </dl>
    <p class="field-help patient-note">Para corregir tus datos de identidad, comunícate con recepción.</p>
</section>
<?php foreach (['general','version'] as $key): ?><?php if (isset($errors[$key])): ?><div class="alert error" role="alert"><?= e($errors[$key]) ?></div><?php endif; ?><?php endforeach; ?>
<form class="form-stack profile-form" method="post" action="/mi-perfil">
    <?= csrf_field() ?><input type="hidden" name="version" value="<?= e($version) ?>">
    <fieldset class="card form-card"><legend>Datos de contacto</legend><div class="form-grid">
        <label>Teléfono *<input type="tel" name="telefono" value="<?= old($old,'telefono') ?>" maxlength="30" autocomplete="tel" required><?= field_error($errors,'telefono') ?></label>
        <label>Correo de contacto<input type="email" name="correo" value="<?= old($old,'correo') ?>" maxlength="160" autocomplete="email"><?= field_error($errors,'correo') ?></label>
        <label class="span-columns">Dirección<input name="direccion" value="<?= old($old,'direccion') ?>" maxlength="250" autocomplete="street-address"><?= field_error($errors,'direccion') ?></label>
    </div><p class="field-help patient-note">El correo de contacto puede ser diferente del correo con el que inicias sesión.</p></fieldset>
    <fieldset class="card form-card"><legend>Contacto de emergencia</legend><p class="field-help">Completa los tres campos o déjalos todos vacíos.</p><div class="form-grid">
        <label>Nombre completo<input name="emergencia_nombre" value="<?= old($old,'emergencia_nombre') ?>" maxlength="160" autocomplete="off"><?= field_error($errors,'emergencia_nombre') ?></label>
        <label>Parentesco o relación<input name="emergencia_parentesco" value="<?= old($old,'emergencia_parentesco') ?>" maxlength="60"><?= field_error($errors,'emergencia_parentesco') ?></label>
        <label>Teléfono de emergencia<input type="tel" name="emergencia_telefono" value="<?= old($old,'emergencia_telefono') ?>" maxlength="30" autocomplete="off"><?= field_error($errors,'emergencia_telefono') ?></label>
    </div></fieldset>
    <fieldset class="card form-card"><legend>Información médica básica</legend><p class="field-help">Registra la información que conoces. Los campos pueden quedar vacíos.</p><div class="form-grid">
        <label>Tipo de sangre<select name="tipo_sangre"><option value="">No informado</option><?php foreach (App\Validation::BLOOD_TYPES as $blood): ?><option value="<?= e($blood) ?>" <?= ($old['tipo_sangre'] ?? '') === $blood ? 'selected' : '' ?>><?= e($blood) ?></option><?php endforeach; ?></select><?= field_error($errors,'tipo_sangre') ?></label>
        <?php foreach (['alergias'=>'Alergias','enfermedades_cronicas'=>'Enfermedades crónicas','medicamentos'=>'Medicamentos','antecedentes'=>'Antecedentes relevantes'] as $key=>$label): ?>
        <label <?= $key === 'antecedentes' ? 'class="span-columns"' : '' ?>><?= e($label) ?><textarea name="<?= e($key) ?>" rows="3" maxlength="2000"><?= old($old,$key) ?></textarea><?= field_error($errors,$key) ?></label>
        <?php endforeach; ?>
    </div></fieldset>
    <div class="form-actions"><button class="button primary" type="submit">Guardar cambios</button><a class="button quiet" href="/panel">Volver al panel</a></div>
</form>
