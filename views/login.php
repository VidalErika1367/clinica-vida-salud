<div class="auth-layout">
    <section class="auth-story">
        <a class="brand" href="/ingresar"><img src="/assets/marca.svg" alt="" width="44" height="44"><span>Vida Salud<small>CLÍNICA</small></span></a>
        <div class="story-copy"><span class="pill">Tu espacio de confianza</span><h1>Tu salud,<br>bien organizada.</h1><p>Un solo acceso para pacientes y el equipo de la clínica.</p><div class="story-detail"><span class="cross-symbol">+</span><div><strong>Nos importa cada persona.</strong><span>Atención cercana, información en un mismo lugar.</span></div></div></div>
        <p class="story-footer">Santa Cruz de la Sierra · Bolivia</p>
    </section>
    <section class="auth-form-panel">
        <div class="form-intro"><span class="eyebrow">BIENVENIDO/A</span><h2>Inicia sesión</h2><p>Ingresa con tu usuario o correo registrado.</p></div>
        <?php if (isset($errors['general'])): ?><div class="alert error" role="alert"><?= e($errors['general']) ?></div><?php endif; ?>
        <form action="/sesion" method="post" class="form-stack">
            <?= csrf_field() ?>
            <label>Usuario o correo<input name="identidad" autocomplete="username" maxlength="160" value="<?= old($old, 'identidad') ?>" required autofocus></label>
            <label>Contraseña<span class="password-input"><input id="password" type="password" name="password" autocomplete="current-password" maxlength="72" required><button type="button" class="toggle-password" data-password-target="password" aria-label="Mostrar contraseña">Ver</button></span></label>
            <button class="button primary full" type="submit">Ingresar <span aria-hidden="true">→</span></button>
        </form>
        <p class="form-bottom">¿Es tu primera vez? <a href="/registro">Crea tu cuenta de paciente</a></p>
        <div class="help-note">Si ya tienes un registro en la clínica y no puedes ingresar, consulta en recepción.</div>
    </section>
</div>
