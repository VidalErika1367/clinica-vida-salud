<?php /* Página pública "¿Olvidaste tu contraseña?" (amplía CU02): recepción restablece la contraseña tras verificar la identidad. */ ?>
<section class="register-card">
    <a class="brand" href="/ingresar"><img src="/assets/marca.svg" alt="" width="40" height="40"><span>Vida Salud<small>CLÍNICA</small></span></a>
    <div class="form-intro"><span class="eyebrow">AYUDA CON TU ACCESO</span><h1>¿Olvidaste tu contraseña?</h1><p>Recepción restablece tu contraseña después de verificar tu identidad.</p></div>
    <?php if ($contacts): ?>
    <p>Escribe a recepción por WhatsApp e indica tu CI. Te daremos una contraseña temporal y el sistema te pedirá cambiarla cuando ingreses.</p>
    <div class="form-actions"><?php foreach ($contacts as $contact): ?><a class="button primary" href="<?= e($contact['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($contact['label']) ?></a><?php endforeach; ?></div>
    <p class="field-help">El chat se abre en WhatsApp con un mensaje ya escrito. Completa tu CI y envíalo tú.</p>
    <?php else: ?>
    <div class="alert warning" role="status">Todavía no hay un número de WhatsApp configurado. Acércate a recepción de la clínica con tu cédula de identidad para restablecer tu contraseña.</div>
    <?php endif; ?>
    <p class="form-bottom"><a href="/ingresar">Volver a iniciar sesión</a></p>
</section>
