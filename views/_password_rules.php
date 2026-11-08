<?php /* Requisitos de contraseña con comprobación en vivo (CU01 y CU04). La regla real la aplica el servidor. */ ?>
<div class="password-rules" data-password-rules<?= !empty($passwordOptional) ? ' data-optional' : '' ?>>
    <p class="password-rules-title" id="<?= e($rulesId ?? 'password-rules-title') ?>">La contraseña debe tener:</p>
    <ul class="password-rules-list" aria-live="polite" aria-labelledby="<?= e($rulesId ?? 'password-rules-title') ?>">
        <li data-rule="length"><span class="rule-mark" aria-hidden="true">○</span> Al menos 8 caracteres<span class="sr-only" data-rule-state></span></li>
        <li data-rule="upper"><span class="rule-mark" aria-hidden="true">○</span> Una letra mayúscula<span class="sr-only" data-rule-state></span></li>
        <li data-rule="lower"><span class="rule-mark" aria-hidden="true">○</span> Una letra minúscula<span class="sr-only" data-rule-state></span></li>
        <li data-rule="number"><span class="rule-mark" aria-hidden="true">○</span> Un número<span class="sr-only" data-rule-state></span></li>
        <li data-rule="special"><span class="rule-mark" aria-hidden="true">○</span> Un carácter especial (por ejemplo . _ - ! @ # $ % &amp; * ?)<span class="sr-only" data-rule-state></span></li>
    </ul>
    <p class="password-rules-limit" data-rule-limit aria-live="polite" hidden>La contraseña no puede superar 72 bytes (las letras con tilde y la ñ ocupan 2 bytes).</p>
    <p class="password-match" data-password-match aria-live="polite"></p>
</div>
