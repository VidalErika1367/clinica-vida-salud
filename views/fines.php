<?php /* Advertencias y multas del paciente (CU24) y pantalla de pagos (CU23). */ ?>
<div class="page-heading"><div><span class="eyebrow">SITUACIÓN ADMINISTRATIVA</span><h1><?= e($title) ?></h1><p>Consulta las incidencias y sus importes, estados y pagos.</p></div></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<section class="card"><form class="form-stack" method="get" action="<?= e($paginationPath) ?>">
<?php if ($user['rol']!=='paciente'): ?>
<div class="search-form"><label class="grow">Buscar paciente por CI o nombre<input name="q" value="<?= e($query) ?>" maxlength="100"></label><button class="button secondary align-end" type="submit">Buscar paciente</button></div>
<label>Paciente<select name="paciente_id"><option value="">Selecciona un paciente</option>
<?php if ($patient && !in_array((int) $patient['id'],array_map('intval',array_column($patients,'id')),true)): ?><option value="<?= (int) $patient['id'] ?>" selected><?= e($patient['ci'].' · '.$patient['nombres'].' '.$patient['apellidos']) ?></option><?php endif; ?>
<?php foreach ($patients as $candidate): ?><option value="<?= (int) $candidate['id'] ?>" <?= (int) ($patient['id'] ?? 0)===(int) $candidate['id'] ? 'selected' : '' ?>><?= e($candidate['ci'].' · '.$candidate['nombres'].' '.$candidate['apellidos']) ?><?= $candidate['activo'] ? '' : ' · Inactivo' ?></option><?php endforeach; ?></select></label>
<?php endif; ?>
<label>Mostrar<select name="estado"><?php foreach ($states as $value=>$label): ?><option value="<?= e($value) ?>" <?= $state===$value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><div class="form-actions"><button class="button primary" type="submit">Consultar</button></div></form></section>
<?php if ($patient && $summary): ?>
<section class="card"><h2><?= e($patient['nombres'].' '.$patient['apellidos']) ?></h2><p>CI <?= e($patient['ci']) ?> · <?= $patient['activo'] ? 'Paciente activo' : 'Paciente inactivo' ?></p>
<div class="stats-grid"><article class="stat"><span>Advertencias</span><strong><?= (int) $summary['advertencias'] ?></strong></article><article class="stat"><span>Multas pendientes</span><strong><?= (int) $summary['pendientes'] ?></strong></article><article class="stat"><span>Saldo pendiente · Bs</span><strong data-saldo="<?= e($summary['saldo']) ?>"><?= e(number_format((float) $summary['saldo'],2,',','.')) ?></strong></article></div>
<p class="field-help">Las advertencias no tienen importe. Las multas se regularizan presencialmente en recepción. Pagar una multa conserva las demás deudas pendientes y el estado de la ficha.</p>
<div class="table-scroll"><table><thead><tr><th scope="col">Cita de origen</th><th scope="col">Incidencia</th><th scope="col">Fecha de registro</th><th scope="col">Importe · Bs</th><th scope="col">Estado</th><th scope="col">Pago</th></tr></thead><tbody>
<?php foreach ($listing['items'] as $item): ?><tr><td><?= e(App\AppointmentService::code((int) $item['cita_id'])) ?><br><?= e(clinic_datetime($item['inicio'])) ?></td><td><?= $item['tipo']==='inasistencia' ? 'Inasistencia' : 'Cancelación tardía' ?></td><td><?= e(clinic_datetime($item['creado_en'])) ?></td><td><?= $item['multa_id'] ? e(number_format((float) $item['monto'],2,',','.')) : '0,00' ?></td><td><?= !$item['multa_id'] ? 'Advertencia' : ($item['estado']==='pendiente' ? 'Pendiente' : 'Pagada') ?></td><td>
<?php if ($item['pagado_en']): ?><?= e(clinic_datetime($item['pagado_en'])) ?><br>Comprobante: <?= e($item['comprobante']) ?>
<?php elseif ($item['multa_id'] && $item['estado']==='pendiente' && App\AppointmentService::allowed($user,'CU23')): ?><a class="table-action" href="/multas/<?= (int) $item['multa_id'] ?>/pagar">Registrar pago</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$listing['items']): ?><tr><td colspan="6" class="empty-state">No hay incidencias con estos filtros.</td></tr><?php endif; ?></tbody></table></div><?php require BASE_PATH . '/views/_appointment_pagination.php'; ?></section>
<?php elseif (!$errors): ?><section class="card"><p>Selecciona un paciente para consultar sus registros.</p></section><?php endif; ?>
