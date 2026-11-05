<div class="page-heading"><div><span class="eyebrow">CLÍNICA VIDA SALUD</span><h1>Ubicación de la clínica</h1><p>Consulta dónde se encuentra la clínica antes de tu visita.</p></div></div>
<section class="card form-card">
<h2>Cómo llegar</h2>
<?php if ($location['address']!=='' || $location['map']): ?>
<dl class="account-details patient-details">
<?php if ($location['address']!==''): ?><div><dt>Dirección</dt><dd><?= e($location['address']) ?></dd></div><?php endif; ?>
<?php if ($location['city']!==''): ?><div><dt>Ciudad</dt><dd><?= e($location['city']) ?></dd></div><?php endif; ?>
</dl>
<?php if ($location['map']): ?><div class="form-actions"><a class="button primary" href="<?= e($location['map']) ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Abrir ubicación en el mapa</a></div><?php endif; ?>
<?php else: ?>
<p>La dirección todavía no está publicada. Solicítala en recepción antes de tu visita.</p>
<?php endif; ?>
<?php if (App\AppointmentService::allowed($user,'CU30')): ?><p><a href="/contacto-recepcion">Contactar con recepción</a></p><?php endif; ?>
</section>
