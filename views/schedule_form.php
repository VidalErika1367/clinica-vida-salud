<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1><?= $item ? 'Editar horario' : 'Nuevo horario' ?></h1><p>Define un tramo semanal para un médico.</p></div><a class="button quiet" href="/horarios">Volver al listado</a></div>
<section class="card form-card">
<?php foreach (['general','version'] as $key): ?><?php if (isset($errors[$key])): ?><div class="alert error" role="alert"><?= e($errors[$key]) ?></div><?php endif; ?><?php endforeach; ?>
<form class="form-stack" method="post" action="<?= $item ? '/horarios/' . (int) $item['id'] . '/editar' : '/horarios/nuevo' ?>">
<?= csrf_field() ?><?php if ($item): ?><input type="hidden" name="version" value="<?= e($version) ?>"><?php endif; ?>
<div class="form-grid">
<label>Médico *<select name="medico_id" required><option value="">Selecciona un médico activo</option><?php foreach ($doctors as $doctor): ?><option value="<?= (int) $doctor['id'] ?>" <?= (int) ($old['medico_id'] ?? 0)===(int) $doctor['id'] ? 'selected' : '' ?>><?= e($doctor['nombres'] . ' ' . $doctor['apellidos'] . (!$doctor['activo'] ? ' · Inactivo' : '')) ?></option><?php endforeach; ?></select><?= field_error($errors,'medico_id') ?></label>
<label>Día *<select name="dia_semana" required><?php foreach (App\ScheduleService::DAYS as $number=>$label): ?><option value="<?= (int) $number ?>" <?= (string) ($old['dia_semana'] ?? '')===(string) $number ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><?= field_error($errors,'dia_semana') ?></label>
<label>Hora inicial *<input type="time" name="hora_inicio" value="<?= old($old,'hora_inicio') ?>" step="1800" required><?= field_error($errors,'hora_inicio') ?></label>
<label>Hora final *<input type="time" name="hora_fin" value="<?= old($old,'hora_fin') ?>" step="1800" required><?= field_error($errors,'hora_fin') ?></label>
<label class="check-label"><input type="checkbox" name="activo" value="1" <?= ($old['activo'] ?? false) ? 'checked' : '' ?>> Horario activo</label>
</div>
<p class="field-help">Usa horas terminadas en :00 o :30. La hora final debe ser posterior a la inicial, dentro del mismo día. Un médico no puede tener tramos activos superpuestos.</p>
<p class="field-help">Si un cambio deja citas vigentes fuera de horario, primero deberás gestionar esas citas. El cambio se rechazará y se conservará el horario anterior.</p>
<div class="form-actions"><button class="button primary" type="submit">Guardar horario</button><a class="button quiet" href="/horarios">Cancelar</a></div>
</form></section>
<?php if ($conflicts): ?><section class="card"><h2>Citas que necesitan gestión</h2><p><?= count($conflicts) ?> cita(s) quedarían fuera del horario. Se muestran hasta 200.</p>
<div class="table-scroll"><table><thead><tr><th scope="col">Cita</th><th scope="col">Fecha y hora</th><th scope="col">Paciente</th><th scope="col">Médico</th><th scope="col">Especialidad</th><th scope="col">Estado</th></tr></thead><tbody>
<?php foreach (array_slice($conflicts,0,200) as $appointment): ?><tr><td><?= e('CM-' . str_pad((string) $appointment['id'],6,'0',STR_PAD_LEFT)) ?></td><td><?= e((new DateTimeImmutable($appointment['inicio']))->setTimezone(new DateTimeZone('America/La_Paz'))->format('d/m/Y H:i')) ?></td><td><?= e($appointment['paciente']) ?></td><td><?= e($appointment['medico']) ?></td><td><?= e($appointment['especialidad']) ?></td><td><?= e(ucfirst($appointment['estado'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section><?php endif; ?>
