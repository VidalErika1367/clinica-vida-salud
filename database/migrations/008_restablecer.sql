-- Restablecimiento de contraseña con apoyo de recepción (amplía CU02, CU04 y CU06).
-- La cuenta con contraseña temporal solo puede cambiarla hasta que lo haga.
ALTER TABLE usuario ADD COLUMN debe_cambiar_password boolean NOT NULL DEFAULT false;
