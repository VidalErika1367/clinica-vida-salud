<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1><?= $item ? 'Editar médico' : 'Nuevo médico' ?></h1><p>Registra su identidad profesional y las especialidades que atiende.</p></div><a class="button quiet" href="/medicos">Volver al listado</a></div>
<section class="card form-card">
<?php foreach (['general','version'] as $key): ?><?php if (isset($errors[$key])): ?><div class="alert error" role="alert"><?= e($errors[$key]) ?></div><?php endif; ?><?php endforeach; ?>
<form class="form-stack" method="post" action="<?= $item ? '/medicos/' . (int) $item['id'] . '/editar' : '/medicos/nuevo' ?>">
<?= csrf_field() ?><?php if ($item): ?><input type="hidden" name="version" value="<?= e($version) ?>"><?php endif; ?>
<div class="form-grid">
<label>Registro profesional *<input name="registro_profesional" value="<?= old($old,'registro_profesional') ?>" maxlength="50" required><?= field_error($errors,'registro_profesional') ?></label>
<label>Nombres *<input name="nombres" value="<?= old($old,'nombres') ?>" maxlength="100" required><?= field_error($errors,'nombres') ?></label>
<label>Apellidos *<input name="apellidos" value="<?= old($old,'apellidos') ?>" maxlength="100" required><?= field_error($errors,'apellidos') ?></label>
<label>Teléfono<input type="tel" name="telefono" value="<?= old($old,'telefono') ?>" maxlength="30"><?= field_error($errors,'telefono') ?></label>
<label>Correo de contacto<input type="email" name="correo" value="<?= old($old,'correo') ?>" maxlength="160"><?= field_error($errors,'correo') ?></label>
<label>Cuenta de acceso (opcional)<select name="usuario_id"><option value="">Sin cuenta vinculada</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) ($old['usuario_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>><?= e($account['nombre_usuario'] . (!$account['habilitado'] ? ' · Deshabilitada' : '') . ($account['codigo'] !== 'medico' ? ' · Vinculación actual' : '')) ?></option><?php endforeach; ?></select><?= field_error($errors,'usuario_id') ?></label>
<label class="check-label"><input type="checkbox" name="activo" value="1" <?= ($old['activo'] ?? false) ? 'checked' : '' ?>> Médico activo</label>
</div>
<p class="field-help">Puedes vincular una cuenta de rol Médico creada en Usuarios. El correo de contacto y el estado del profesional son independientes de su acceso.</p>
<fieldset class="specialty-choices"><legend>Especialidades activas</legend>
<?php if (!$specialties): ?><p class="field-help">Primero registra o activa una especialidad desde Especialidades.</p><?php endif; ?>
<?php foreach ($specialties as $specialty): ?><label class="catalog-check"><input type="checkbox" name="especialidades[]" value="<?= (int) $specialty['id'] ?>" <?= in_array((string) $specialty['id'],$old['especialidades'] ?? [],true) ? 'checked' : '' ?>> <?= e($specialty['nombre']) ?></label><?php endforeach; ?>
<?= field_error($errors,'especialidades') ?></fieldset>
<?php if ($item): ?><?php $retained=array_filter($item['especialidades'],static fn($e)=>$e['asignada'] && !$e['activo']); ?><?php if ($retained): ?><p class="field-help">Especialidades inactivas que conservan su asociación: <?= e(implode(', ',array_column($retained,'nombre'))) ?>. Su disponibilidad se recupera al activar la especialidad.</p><?php endif; ?><?php endif; ?>
<p class="field-help">El alta y los médicos activos requieren al menos una especialidad activa. Las asociaciones retiradas se conservan para su historial. Después de cambiar el estado o las asociaciones, revisa las citas futuras de su ficha.</p>
<div class="form-actions"><button class="button primary" type="submit">Guardar médico</button><a class="button quiet" href="/medicos">Cancelar</a></div>
</form></section>
