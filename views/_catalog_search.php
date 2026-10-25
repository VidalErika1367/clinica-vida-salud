<form class="patient-search" method="get" action="<?= e($searchAction) ?>">
    <label><?= e($searchLabel) ?><input name="q" value="<?= e($query) ?>" maxlength="100"></label>
    <label>Estado<select name="estado"><?php foreach (['todos'=>'Todos','activos'=>'Activos','inactivos'=>'Inactivos'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $state === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <button class="button secondary" type="submit">Buscar</button>
</form>
