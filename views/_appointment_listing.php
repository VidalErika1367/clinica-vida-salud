<div class="table-scroll"><table><thead><tr><th scope="col">Código</th><th scope="col">Fecha y hora</th><th scope="col">Paciente</th><th scope="col">Médico</th><th scope="col">Especialidad</th><th scope="col">Estado</th><th scope="col">Detalle</th></tr></thead><tbody>
<?php foreach ($listing['items'] as $item): ?><tr>
<td><?= e(App\AppointmentService::code((int) $item['id'])) ?></td><td><?= e(clinic_datetime($item['inicio'])) ?></td><td><?= e($item['paciente']) ?></td><td><?= e($item['medico']) ?></td><td><?= e($item['especialidad']) ?></td><td><span class="pill"><?= e(ucfirst($item['estado'])) ?></span></td>
<td><?php if (App\AppointmentService::canView($user)): ?><a class="table-action" href="/citas/<?= (int) $item['id'] ?>">Ver cita y cambios</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$listing['items']): ?><tr><td colspan="7" class="empty-state">No hay citas con los filtros seleccionados.</td></tr><?php endif; ?>
</tbody></table></div>
<?php require BASE_PATH . '/views/_appointment_pagination.php'; ?>
