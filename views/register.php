<?php $registration = true; ?>
<section class="register-card">
    <a class="brand" href="/ingresar"><img src="/assets/marca.svg" alt="" width="40" height="40"><span>Vida Salud<small>CLÍNICA</small></span></a>
    <div class="form-intro"><span class="eyebrow">EMPECEMOS</span><h1>Crea tu cuenta de paciente</h1><p>Completa tus datos para contar con un acceso personal.</p></div>
    <?php if (isset($errors['general'])): ?><div class="alert error" role="alert"><?= e($errors['general']) ?></div><?php endif; ?>
    <form action="/registro" method="post" class="form-stack">
        <?= csrf_field() ?>
        <?php require BASE_PATH . '/views/_patient_fields.php'; ?>
        <div class="form-grid">
            <label>Nombre de usuario<input name="nombre_usuario" autocomplete="username" minlength="3" maxlength="60" pattern="[a-zA-Z0-9_.-]{3,60}" value="<?= old($old,'nombre_usuario') ?>" required><?= field_error($errors,'nombre_usuario') ?></label>
            <label>Correo electrónico<input type="email" name="correo" autocomplete="email" maxlength="160" value="<?= old($old,'correo') ?>" required><?= field_error($errors,'correo') ?></label>
            <label>Contraseña<input type="password" name="password" autocomplete="new-password" minlength="12" required aria-describedby="password-help"><?= field_error($errors,'password') ?></label>
            <label>Repite la contraseña<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required><?= field_error($errors,'password_confirmation') ?></label>
        </div>
        <p class="field-help" id="password-help">Usa una frase de al menos 12 caracteres. No compartas tu contraseña.</p>
        <button class="button primary full" type="submit">Crear mi cuenta</button>
    </form>
    <p class="form-bottom">¿Ya tienes cuenta? <a href="/ingresar">Inicia sesión</a></p>
</section>
