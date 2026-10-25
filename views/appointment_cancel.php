<div class="page-heading"><div><span class="eyebrow">CITAS MÉDICAS</span><h1>Cancelar cita</h1><p><?= e(App\AppointmentService::code((int) $appointment['id'])) ?> · <?= e($appointment['paciente']) ?></p></div><a class="button quiet" href="/citas/<?= (int) $appointment['id'] ?>">Volver a la cita</a></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<section class="card form-card"><p><strong><?= e(clinic_datetime($appointment['inicio'])) ?></strong> · <?= e($appointment['medico']) ?> · <?= e($appointment['especialidad']) ?></p>
<p>La cancelación conservará el registro y liberará el turno.</p>
<div class="help-note">El plazo configurado de cancelación es de <?= (int) $policy['plazo_cancelacion_horas'] ?> horas. La primera incidencia válida genera advertencia; desde la segunda, una multa de Bs <?= e(number_format((float) $policy['multa_bs'],2,',','.')) ?>. Las causas de la clínica y las justificadas por recepción no penalizan al paciente.</div>
<form class="form-stack" method="post" action="/citas/<?= (int) $appointment['id'] ?>/cancelar">
<?= csrf_field() ?><input type="hidden" name="version" value="<?= e($version) ?>">
<label>Motivo de cancelación *<input name="motivo_cambio" value="<?= old($old,'motivo_cambio') ?>" maxlength="250" required></label>
<?php require BASE_PATH . '/views/_appointment_context.php'; ?>
<?php if ($user['rol']!=='paciente'): ?><label>Fecha y hora de aviso verificado (opcional)<input type="datetime-local" name="aviso_cancelacion_en" value="<?= old($old,'aviso_cancelacion_en') ?>" max="<?= e(clinic_input($clock['ahora'])) ?>"></label><p class="field-help">Déjalo vacío para registrar el momento actual. Completa un aviso anterior únicamente si fue verificado.</p><?php endif; ?>
<div class="form-actions"><button class="button danger" type="submit" <?= !in_array($appointment['estado'],['programada','confirmada'],true) || !$appointment['vigente'] ? 'disabled' : '' ?>>Confirmar cancelación</button><a class="button quiet" href="/citas/<?= (int) $appointment['id'] ?>">Conservar cita</a></div>
</form></section>
