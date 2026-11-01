<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1>Reportes administrativos</h1><p>Consulta la actividad de citas o los pagos de multas del período seleccionado.</p></div></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<section class="card"><form class="form-stack" method="get" action="/reportes"><div class="report-filters">
<label>Reporte<select name="tipo"><option value="citas" <?= $type==='citas' ? 'selected' : '' ?>>Actividad de citas</option><option value="pagos" <?= $type==='pagos' ? 'selected' : '' ?>>Pagos de multas</option></select></label>
<label>Desde<input type="date" name="desde" value="<?= e($old['desde'] ?? $filters['desde']) ?>" required></label><label>Hasta<input type="date" name="hasta" value="<?= e($old['hasta'] ?? $filters['hasta']) ?>" required></label>
<label>Médico<select name="medico_id"><option value="">Todos los médicos</option><?php foreach ($doctors as $doctor): ?><option value="<?= (int) $doctor['id'] ?>" <?= (string) ($old['medico_id'] ?? '')===(string) $doctor['id'] ? 'selected' : '' ?>><?= e($doctor['nombre']) ?></option><?php endforeach; ?></select></label>
<label>Especialidad<select name="especialidad_id"><option value="">Todas las especialidades</option><?php foreach ($specialties as $specialty): ?><option value="<?= (int) $specialty['id'] ?>" <?= (string) ($old['especialidad_id'] ?? '')===(string) $specialty['id'] ? 'selected' : '' ?>><?= e($specialty['nombre']) ?></option><?php endforeach; ?></select></label>
<label>Estado de citas<select name="estado"><?php foreach ($states as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($type==='pagos' ? 'todos' : ($old['estado'] ?? $filters['estado']))===$value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
</div><p class="field-help">La actividad se filtra por la fecha de la cita. Los pagos se filtran por la fecha del pago y no por el estado de la cita.</p><div class="form-actions"><button class="button primary" type="submit">Generar reporte</button></div></form></section>
<?php if (!$errors): ?>
<p class="report-caption">Período: <?= e($filters['desde']) ?> a <?= e($filters['hasta']) ?> · Consulta: <?= e(clinic_datetime($generatedAt)) ?> · Hora de Bolivia</p>
<?php if ($type==='citas'): ?>
<div class="stats-grid report-stats">
<?php foreach (['total'=>'Total de citas','pacientes'=>'Pacientes con citas','programadas'=>'Programadas','confirmadas'=>'Confirmadas','atendidas'=>'Atendidas','canceladas'=>'Canceladas','inasistencias'=>'Inasistencias'] as $key=>$label): ?><article class="stat"><span><?= e($label) ?></span><strong data-total="<?= e($key) ?>"><?= (int) $summary[$key] ?></strong></article><?php endforeach; ?>
</div><section class="card"><h2><?= $filters['desde']===$filters['hasta'] ? 'Agenda diaria' : 'Citas del período' ?></h2><?php require BASE_PATH . '/views/_appointment_listing.php'; ?></section>
<?php else: ?>
<div class="stats-grid"><article class="stat"><span>Pagos registrados</span><strong data-total="pagos"><?= (int) $listing['total'] ?></strong></article><article class="stat"><span>Total cobrado · Bs</span><strong class="money-total" data-total="monto"><?= e(number_format((float) $listing['monto'],2,',','.')) ?></strong></article></div>
<section class="card"><h2>Pagos de multas</h2><div class="table-scroll"><table><thead><tr><th scope="col">Fecha del pago</th><th scope="col">Comprobante</th><th scope="col">Paciente</th><th scope="col">Cita</th><th scope="col">Monto · Bs</th></tr></thead><tbody>
<?php foreach ($listing['items'] as $payment): ?><tr><td><?= e(clinic_datetime($payment['pagado_en'])) ?></td><td><?= e($payment['comprobante']) ?></td><td><?= e($payment['paciente']) ?></td><td><?= e(App\AppointmentService::code((int) $payment['cita_id'])) ?></td><td><?= e(number_format((float) $payment['monto'],2,',','.')) ?></td></tr><?php endforeach; ?>
<?php if (!$listing['items']): ?><tr><td colspan="5" class="empty-state">No hay pagos registrados con esos filtros.</td></tr><?php endif; ?>
</tbody></table></div><?php require BASE_PATH . '/views/_appointment_pagination.php'; ?></section>
<?php endif; ?>
<?php endif; ?>
