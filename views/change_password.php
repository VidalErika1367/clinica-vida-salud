<?php /* Cambio obligatorio de la contraseña temporal (amplía CU02): única página disponible hasta cambiarla. */ ?>
<section class="register-card">
    <a class="brand" href="/ingresar"><img src="/assets/marca.svg" alt="" width="40" height="40"><span>Vida Salud<small>CLÍNICA</small></span></a>
    <div class="form-intro"><span class="eyebrow">CONTRASEÑA TEMPORAL</span><h1>Cambia tu contraseña</h1><p>La cuenta <strong><?= e($account) ?></strong> usa una contraseña temporal. Elige una nueva distinta para continuar.</p></div>
    <?php if (isset($errors['general'])): ?><div class="alert error" role="alert"><?= e($errors['general']) ?></div><?php endif; ?>
    <form action="/cambiar-password" method="post" class="form-stack">
        <?= csrf_field() ?>
        <div class="form-grid">
            <label>Contraseña nueva<input type="password" name="password" autocomplete="new-password" minlength="8" required autofocus><?= field_error($errors,'password') ?></label>
            <label>Repite la contraseña nueva<input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required><?= field_error($errors,'password_confirmation') ?></label>
        </div>
        <?php $passwordOptional = false; require BASE_PATH . '/views/_password_rules.php'; ?>
        <button class="button primary full" type="submit">Guardar contraseña nueva</button>
    </form>
    <form action="/salir" method="post" class="form-bottom"><?= csrf_field() ?><button class="button quiet" type="submit">Cerrar sesión</button></form>
</section>
