<div class="listing-pagination"><p class="field-help"><?= (int) $listing['total'] ?> resultados · Página <?= (int) $listing['page'] ?> de <?= (int) $listing['pages'] ?></p><nav class="form-actions" aria-label="Páginas de resultados">
<?php if ($listing['page']>1): ?><a class="button quiet" href="<?= e($paginationPath.'?'.http_build_query(array_replace($paginationQuery,['pagina'=>(string) ($listing['page']-1)]))) ?>">Anterior</a><?php endif; ?>
<?php if ($listing['page']<$listing['pages']): ?><a class="button quiet" href="<?= e($paginationPath.'?'.http_build_query(array_replace($paginationQuery,['pagina'=>(string) ($listing['page']+1)]))) ?>">Siguiente</a><?php endif; ?>
</nav></div>
