<?php /* Parcial de búsqueda de catálogos: médicos (CU08) y especialidades (CU09). */ ?>
<form class="patient-search" method="get" action="<?= e($searchAction) ?>">
    <label><?= e($searchLabel) ?><input name="q" value="<?= e($query) ?>" maxlength="100"></label>
    <?php if (isset($searchSpecialties)): ?><label>Especialidad<select name="especialidad"><option value="">Todas</option><?php foreach ($searchSpecialties as $specialty): ?><option value="<?= (int) $specialty['id'] ?>" <?= $searchSpecialtyId === (int) $specialty['id'] ? 'selected' : '' ?>><?= e($specialty['nombre']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label>Estado<select name="estado"><?php foreach (['todos'=>'Todos','activos'=>'Activos','inactivos'=>'Inactivos'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $state === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <button class="button secondary" type="submit">Buscar</button>
</form>
