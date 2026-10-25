CREATE UNIQUE INDEX uq_usuario_nombre_normalizado ON usuario(lower(nombre_usuario));
