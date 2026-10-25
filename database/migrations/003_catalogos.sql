-- Catálogos y reglas iniciales. No crea usuarios ni contraseñas.
INSERT INTO rol(codigo,nombre) VALUES
 ('administrador','Administrador'),('recepcionista','Recepcionista'),('medico','Médico'),('paciente','Paciente');
INSERT INTO permiso(codigo) SELECT 'CU'||lpad(n::text,2,'0') FROM generate_series(1,30) n;
INSERT INTO rol_permiso(rol_id,permiso_id) SELECT r.id,p.id FROM rol r CROSS JOIN permiso p WHERE r.codigo='administrador';
INSERT INTO rol_permiso(rol_id,permiso_id) SELECT r.id,p.id FROM rol r CROSS JOIN permiso p
WHERE r.codigo='recepcionista' AND p.codigo IN('CU02','CU03','CU06','CU11','CU12','CU13','CU14','CU15','CU16','CU17','CU18','CU19','CU20','CU22','CU23','CU24','CU25','CU26','CU28','CU29');
INSERT INTO rol_permiso(rol_id,permiso_id) SELECT r.id,p.id FROM rol r CROSS JOIN permiso p
WHERE r.codigo='medico' AND p.codigo IN('CU02','CU03','CU16','CU18');
INSERT INTO rol_permiso(rol_id,permiso_id) SELECT r.id,p.id FROM rol r CROSS JOIN permiso p
WHERE r.codigo='paciente' AND p.codigo IN('CU01','CU02','CU03','CU07','CU11','CU12','CU13','CU14','CU15','CU19','CU21','CU24','CU27','CU28','CU29','CU30');
INSERT INTO parametro_sistema(id,plazo_cancelacion_horas,multa_bs) VALUES(1,24,20);
