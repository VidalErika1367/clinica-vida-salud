<section class="card"><h2>Citas futuras relacionadas</h2>
<?php $review=count(array_filter($upcoming,static fn($c)=>$c['revisar'])); ?>
<?php if ($review): ?><div class="alert warning"><?= (int) $review ?> cita(s) del listado necesitan revisión por cambios en el médico o la especialidad. Coordina su gestión con recepción. Se conservan los turnos y su historial.</div><?php endif; ?>
<div class="table-scroll"><table><thead><tr><th scope="col">Cita</th><th scope="col">Fecha y hora</th><th scope="col">Paciente</th><th scope="col">Médico</th><th scope="col">Especialidad</th><th scope="col">Estado</th><th scope="col">Revisión</th></tr></thead><tbody>
<?php foreach ($upcoming as $appointment): ?><tr>
<td><?= e('CM-' . str_pad((string) $appointment['id'],6,'0',STR_PAD_LEFT)) ?></td>
<td><?= e((new DateTimeImmutable($appointment['inicio']))->setTimezone(new DateTimeZone('America/La_Paz'))->format('d/m/Y H:i')) ?></td>
<td><?= e($appointment['paciente']) ?></td><td><?= e($appointment['medico']) ?></td><td><?= e($appointment['especialidad']) ?></td><td><?= e(ucfirst($appointment['estado'])) ?></td><td><?= $appointment['revisar'] ? 'Pendiente de revisión' : 'Sin cambios en médico y especialidad' ?></td>
</tr><?php endforeach; ?>
<?php if (!$upcoming): ?><tr><td colspan="7" class="empty-state">No hay citas futuras relacionadas.</td></tr><?php endif; ?>
</tbody></table></div><p class="field-help">Hasta 200 citas programadas o confirmadas, ordenadas por fecha. Los cambios del catálogo conservan las reservas existentes.</p></section>
