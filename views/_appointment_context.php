<?php /* Parcial de citas: origen del cambio y contexto del paciente (CU12-CU15, CU16-CU17). */if ($user['rol']!=='paciente'): ?>
<label>Origen del cambio<select name="origen_cambio"><option value="paciente" <?= ($old['origen_cambio'] ?? 'paciente')==='paciente' ? 'selected' : '' ?>>Paciente</option><option value="clinica" <?= ($old['origen_cambio'] ?? '')==='clinica' ? 'selected' : '' ?>>Clínica</option></select></label>
<label class="check-label"><input type="checkbox" name="justificada" value="1" <?= ($old['justificada'] ?? '')==='1' ? 'checked' : '' ?>> Causa justificada verificada por recepción</label>
<?php endif; ?>
