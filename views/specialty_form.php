<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1><?= $item ? 'Editar especialidad' : 'Nueva especialidad' ?></h1><p>Define su nombre, descripción y estado.</p></div><a class="button quiet" href="/especialidades">Volver al listado</a></div>
<section class="card form-card">
<?php foreach (['general','version'] as $key): ?><?php if (isset($errors[$key])): ?><div class="alert error" role="alert"><?= e($errors[$key]) ?></div><?php endif; ?><?php endforeach; ?>
<form class="form-stack" method="post" action="<?= $item ? '/especialidades/' . (int) $item['id'] . '/editar' : '/especialidades/nueva' ?>">
    <?= csrf_field() ?><?php if ($item): ?><input type="hidden" name="version" value="<?= e($version) ?>"><?php endif; ?>
    <label>Nombre *<input name="nombre" value="<?= old($old,'nombre') ?>" maxlength="100" required><?= field_error($errors,'nombre') ?></label>
    <label>Descripción<textarea name="descripcion" rows="4" maxlength="1000"><?= old($old,'descripcion') ?></textarea><?= field_error($errors,'descripcion') ?></label>
    <label class="check-label"><input type="checkbox" name="activo" value="1" <?= ($old['activo'] ?? false) ? 'checked' : '' ?>> Especialidad activa</label>
    <p class="field-help">Los nombres equivalentes por mayúsculas o espacios se consideran duplicados. Una especialidad inactiva conserva sus médicos y citas; las citas futuras afectadas se muestran en su detalle.</p>
    <div class="form-actions"><button class="button primary" type="submit">Guardar especialidad</button><a class="button quiet" href="/especialidades">Cancelar</a></div>
</form></section>
