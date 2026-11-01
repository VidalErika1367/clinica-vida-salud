<div class="page-heading"><div><span class="eyebrow">CITAS MÉDICAS</span><h1><?= e($title) ?></h1><p><?= e(App\AppointmentService::code((int) $appointment['id'])) ?> · <?= e($appointment['paciente']) ?></p></div><a class="button quiet" href="/citas/<?= (int) $appointment['id'] ?>">Volver a la cita</a></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<section class="card form-card">
<dl class="account-details patient-details">
<div><dt>Paciente</dt><dd><?= e($appointment['paciente']) ?> · CI <?= e($appointment['ci']) ?></dd></div>
<div><dt>Médico</dt><dd><?= e($appointment['medico']) ?></dd></div>
<div><dt>Especialidad</dt><dd><?= e($appointment['especialidad']) ?></dd></div>
<div><dt>Turno</dt><dd><?= e(clinic_datetime($appointment['inicio'])) ?> a <?= e(clinic_input($appointment['fin'],'H:i')) ?></dd></div>
<div><dt>Estado actual</dt><dd><?= e(ucfirst($appointment['estado'])) ?></dd></div></dl>
<?php if (!$canFinish): ?><div class="alert warning">La cita debe estar Programada o Confirmada y su turno debe haber <?= $noShow ? 'finalizado' : 'comenzado' ?> para registrar esta operación.</div><?php endif; ?>
<?php if ($noShow && $doctorAbsent): ?><div class="alert warning">Hay una ausencia del médico registrada en ese turno. La cita requiere revisión de recepción; no corresponde registrar una inasistencia del paciente.</div><?php endif; ?>
<form class="form-stack appointment-actions" method="post" action="/citas/<?= (int) $appointment['id'] ?>/<?= $noShow ? 'inasistencia' : 'atender' ?>">
<?= csrf_field() ?><input type="hidden" name="version" value="<?= e($version) ?>">
<?php if ($noShow): ?>
<p class="help-note">La primera incidencia válida genera una advertencia; desde la segunda, una multa de Bs <?= e(number_format((float) $policy['multa_bs'],2,',','.')) ?>. Las causas justificadas y de la clínica no penalizan al paciente. Las reservas hechas con poca anticipación se derivan a revisión.</p>
<label>Motivo verificado *<input name="motivo_cambio" maxlength="250" value="<?= old($old,'motivo_cambio') ?>" required></label>
<label class="catalog-check"><input type="checkbox" name="paciente_ausente" value="1" <?= ($old['paciente_ausente'] ?? '')==='1' ? 'checked' : '' ?> required> Verifiqué que el paciente no asistió y revisé los avisos de cancelación.</label>
<label class="catalog-check"><input type="checkbox" name="medico_disponible" value="1" <?= ($old['medico_disponible'] ?? '')==='1' ? 'checked' : '' ?> required> Verifiqué que el médico estaba disponible para atender.</label>
<?php require BASE_PATH . '/views/_appointment_context.php'; ?>
<?php else: ?>
<p>Registra esta operación únicamente después de verificar que la consulta se realizó. El paso del tiempo no marca automáticamente una cita como Atendida.</p>
<label class="catalog-check"><input type="checkbox" name="atencion_realizada" value="1" <?= ($old['atencion_realizada'] ?? '')==='1' ? 'checked' : '' ?> required> Verifiqué al paciente y confirmo que la consulta se realizó.</label>
<?php endif; ?>
<div class="form-actions"><button class="button <?= $noShow ? 'danger' : 'primary' ?>" type="submit" <?= !$canFinish || ($noShow && $doctorAbsent) ? 'disabled' : '' ?>><?= $noShow ? 'Confirmar inasistencia' : 'Marcar como Atendida' ?></button><a class="button quiet" href="/citas/<?= (int) $appointment['id'] ?>">Volver sin guardar</a></div>
</form></section>
