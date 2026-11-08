<?php /* Historial de citas por paciente (CU19). */ ?>
<div class="page-heading"><div><span class="eyebrow">REGISTROS CONSERVADOS</span><h1>Historial de citas</h1><p>Consulta las citas del paciente y abre el detalle para revisar sus cambios y motivos.</p></div></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<section class="card"><form class="form-stack" method="get" action="/historial">
<?php if ($user['rol']!=='paciente'): ?>
<div class="search-form"><label class="grow">Buscar paciente por CI o nombre<input name="q" maxlength="100" value="<?= e($query) ?>"></label><button class="button secondary align-end" type="submit">Buscar paciente</button></div>
<label>Paciente<select name="paciente_id"><option value="">Selecciona un paciente</option>
<?php if ($patient && !in_array((int) $patient['id'],array_map('intval',array_column($patients,'id')),true)): ?><option value="<?= (int) $patient['id'] ?>" selected><?= e($patient['ci'].' · '.$patient['nombres'].' '.$patient['apellidos']) ?></option><?php endif; ?>
<?php foreach ($patients as $candidate): ?><option value="<?= (int) $candidate['id'] ?>" <?= (string) ($old['paciente_id'] ?? $filters['paciente_id'] ?? '')===(string) $candidate['id'] ? 'selected' : '' ?>><?= e($candidate['ci'].' · '.$candidate['nombres'].' '.$candidate['apellidos']) ?><?= $candidate['activo'] ? '' : ' · Inactivo' ?></option><?php endforeach; ?></select></label>
<?php endif; ?>
<div class="report-filters"><label>Desde<input type="date" name="desde" value="<?= e($old['desde'] ?? $filters['desde']) ?>" required></label><label>Hasta<input type="date" name="hasta" value="<?= e($old['hasta'] ?? $filters['hasta']) ?>" required></label>
<label>Estado<select name="estado"><?php foreach ($states as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($old['estado'] ?? $filters['estado'])===$value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button class="button primary align-end" type="submit">Consultar historial</button></div>
</form></section>
<?php if ($patient): ?><section class="card"><h2><?= e($patient['nombres'].' '.$patient['apellidos']) ?></h2><p>CI <?= e($patient['ci']) ?> · <?= $patient['activo'] ? 'Paciente activo' : 'Paciente inactivo: su historial se conserva' ?></p>
<?php require BASE_PATH . '/views/_appointment_listing.php'; ?></section>
<?php elseif (!$errors): ?><section class="card"><p>Selecciona un paciente para consultar su historial.</p></section><?php endif; ?>
