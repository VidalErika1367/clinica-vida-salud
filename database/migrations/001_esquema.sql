-- CLINICA VIDA SALUD. Ejecutar en una base PostgreSQL vacía.
-- Diseñado para PostgreSQL 16 o superior. Zona de la clínica: America/La_Paz.
-- No contiene tokens de Telegram ni credenciales reales.
CREATE EXTENSION IF NOT EXISTS btree_gist;
SET TIME ZONE 'America/La_Paz';
CREATE TABLE rol (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  codigo varchar(24) NOT NULL UNIQUE,
  nombre varchar(60) NOT NULL
);

CREATE TABLE permiso (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  codigo varchar(80) NOT NULL UNIQUE
);

CREATE TABLE rol_permiso (
  rol_id bigint NOT NULL REFERENCES rol(id),
  permiso_id bigint NOT NULL REFERENCES permiso(id),
  PRIMARY KEY (rol_id,permiso_id)
);

CREATE TABLE usuario (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  rol_id bigint NOT NULL REFERENCES rol(id),
  nombre_usuario varchar(60) NOT NULL UNIQUE,
  correo varchar(160) NOT NULL,
  password_hash varchar(255) NOT NULL,
  habilitado boolean NOT NULL DEFAULT true,
  creado_en timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE paciente (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_id bigint UNIQUE REFERENCES usuario(id),
  ci varchar(30) NOT NULL UNIQUE,
  nombres varchar(100) NOT NULL,
  apellidos varchar(100) NOT NULL,
  fecha_nacimiento date,
  sexo varchar(30),
  telefono varchar(30) NOT NULL,
  correo varchar(160),
  direccion varchar(250),
  activo boolean NOT NULL DEFAULT true,
  creado_en timestamptz NOT NULL DEFAULT now(),
  reactivado_en timestamptz
);

CREATE TABLE perfil_salud (
  paciente_id bigint NOT NULL PRIMARY KEY REFERENCES paciente(id),
  tipo_sangre varchar(8),
  alergias text,
  enfermedades_cronicas text,
  medicamentos text,
  antecedentes text
);

CREATE TABLE contacto_emergencia (
  paciente_id bigint NOT NULL PRIMARY KEY REFERENCES paciente(id),
  nombre varchar(160) NOT NULL,
  parentesco varchar(60) NOT NULL,
  telefono varchar(30) NOT NULL
);

CREATE TABLE medico (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_id bigint UNIQUE REFERENCES usuario(id),
  registro_profesional varchar(50) NOT NULL UNIQUE,
  nombres varchar(100) NOT NULL,
  apellidos varchar(100) NOT NULL,
  telefono varchar(30),
  correo varchar(160),
  activo boolean NOT NULL DEFAULT true
);

CREATE TABLE especialidad (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nombre varchar(100) NOT NULL,
  descripcion text,
  activo boolean NOT NULL DEFAULT true
);

CREATE TABLE medico_especialidad (
  medico_id bigint NOT NULL REFERENCES medico(id),
  especialidad_id bigint NOT NULL REFERENCES especialidad(id),
  activo boolean NOT NULL DEFAULT true,
  PRIMARY KEY(medico_id,especialidad_id)
);

CREATE TABLE horario (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  medico_id bigint NOT NULL REFERENCES medico(id),
  dia_semana smallint NOT NULL CHECK(dia_semana BETWEEN 1 AND 7),
  hora_inicio time NOT NULL,
  hora_fin time NOT NULL,
  activo boolean NOT NULL DEFAULT true,
  CHECK(hora_fin > hora_inicio),
  CHECK(extract(second FROM hora_inicio)=0 AND extract(second FROM hora_fin)=0),
  CHECK(extract(minute FROM hora_inicio)::integer % 30=0 AND extract(minute FROM hora_fin)::integer % 30=0),
  EXCLUDE USING gist (medico_id WITH =, dia_semana WITH =, int4range((extract(epoch FROM hora_inicio)/60)::integer,(extract(epoch FROM hora_fin)/60)::integer,'[)') WITH &&) WHERE (activo)
);

CREATE TABLE ausencia_medico (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  medico_id bigint NOT NULL REFERENCES medico(id),
  inicio timestamptz NOT NULL,
  fin timestamptz NOT NULL,
  motivo varchar(250) NOT NULL,
  registrado_por bigint NOT NULL REFERENCES usuario(id),
  CHECK(fin > inicio)
);

CREATE TABLE parametro_sistema (
  id smallint NOT NULL PRIMARY KEY CHECK(id=1),
  plazo_cancelacion_horas integer NOT NULL DEFAULT 24 CHECK(plazo_cancelacion_horas >= 0),
  multa_bs numeric(10,2) NOT NULL DEFAULT 20 CHECK(multa_bs > 0),
  contacto_telegram varchar(250)
);

CREATE TABLE cita (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  paciente_id bigint NOT NULL REFERENCES paciente(id),
  medico_id bigint NOT NULL,
  especialidad_id bigint NOT NULL,
  inicio timestamptz NOT NULL,
  fin timestamptz NOT NULL,
  estado varchar(16) NOT NULL DEFAULT 'programada' CHECK(estado IN ('programada','confirmada','atendida','cancelada','inasistencia')),
  tipo varchar(12) NOT NULL DEFAULT 'ordinaria' CHECK(tipo IN ('ordinaria','urgente')),
  motivo varchar(250),
  creado_por bigint NOT NULL REFERENCES usuario(id),
  creado_en timestamptz NOT NULL DEFAULT now(),
  origen_cambio varchar(12) NOT NULL DEFAULT 'paciente' CHECK(origen_cambio IN ('paciente','clinica')),
  motivo_cambio varchar(250),
  aviso_cancelacion_en timestamptz,
  justificada boolean NOT NULL DEFAULT false,
  FOREIGN KEY(medico_id,especialidad_id) REFERENCES medico_especialidad(medico_id,especialidad_id),
  CHECK(fin=inicio+interval '30 minutes'),
  CHECK(extract(second FROM inicio)=0 AND extract(minute FROM inicio)::integer % 30=0),
  EXCLUDE USING gist (medico_id WITH =, tstzrange(inicio,fin,'[)') WITH &&) WHERE (estado IN ('programada','confirmada','atendida'))
);

CREATE TABLE historial_cita (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  cita_id bigint NOT NULL REFERENCES cita(id),
  actor_id bigint NOT NULL REFERENCES usuario(id),
  accion varchar(60) NOT NULL,
  datos_antes jsonb,
  datos_despues jsonb NOT NULL,
  creado_en timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE incumplimiento (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  cita_id bigint NOT NULL UNIQUE REFERENCES cita(id),
  tipo varchar(24) NOT NULL CHECK(tipo IN ('cancelacion_tardia','inasistencia')),
  creado_en timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE multa (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  incumplimiento_id bigint NOT NULL UNIQUE REFERENCES incumplimiento(id),
  monto numeric(10,2) NOT NULL CHECK(monto > 0),
  estado varchar(12) NOT NULL DEFAULT 'pendiente' CHECK(estado IN ('pendiente','pagada')),
  creado_en timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE pago_multa (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  multa_id bigint NOT NULL UNIQUE REFERENCES multa(id),
  registrado_por bigint NOT NULL REFERENCES usuario(id),
  monto numeric(10,2) NOT NULL CHECK(monto > 0),
  pagado_en timestamptz NOT NULL DEFAULT now(),
  comprobante varchar(80) NOT NULL UNIQUE
);

CREATE TABLE solicitud_urgente (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  paciente_id bigint NOT NULL REFERENCES paciente(id),
  especialidad_id bigint REFERENCES especialidad(id),
  motivo varchar(500) NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'pendiente' CHECK(estado IN ('pendiente','gestionada','cerrada')),
  cita_id bigint UNIQUE REFERENCES cita(id),
  gestionado_por bigint REFERENCES usuario(id),
  resolucion varchar(500),
  creado_en timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE vinculo_telegram (
  paciente_id bigint NOT NULL PRIMARY KEY REFERENCES paciente(id),
  chat_id bigint NOT NULL UNIQUE,
  consentido_en timestamptz NOT NULL DEFAULT now(),
  activo boolean NOT NULL DEFAULT true
);

CREATE TABLE token_vinculacion (
  token_hash varchar(64) NOT NULL PRIMARY KEY,
  paciente_id bigint NOT NULL REFERENCES paciente(id),
  caduca_en timestamptz NOT NULL,
  usado_en timestamptz
);

CREATE TABLE aviso (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  paciente_id bigint NOT NULL REFERENCES paciente(id),
  cita_id bigint REFERENCES cita(id),
  clave_evento varchar(150) NOT NULL UNIQUE,
  tipo varchar(50) NOT NULL,
  texto varchar(700) NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'pendiente' CHECK(estado IN ('pendiente','enviado','error','omitido')),
  intentos integer NOT NULL DEFAULT 0 CHECK(intentos>=0),
  ultimo_error varchar(300),
  creado_en timestamptz NOT NULL DEFAULT now(),
  enviado_en timestamptz
);

CREATE TABLE evento_administrativo (
  id bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  paciente_id bigint REFERENCES paciente(id),
  actor_id bigint REFERENCES usuario(id),
  accion varchar(60) NOT NULL,
  motivo varchar(250) NOT NULL,
  creado_en timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_usuario_correo ON usuario(lower(btrim(correo)));
CREATE UNIQUE INDEX uq_especialidad_nombre ON especialidad(lower(regexp_replace(btrim(nombre),'\s+',' ','g')));
CREATE INDEX ix_cita_paciente_fecha ON cita(paciente_id,inicio);
CREATE INDEX ix_cita_fecha_estado ON cita(inicio,estado);
CREATE INDEX ix_aviso_pendiente ON aviso(estado,creado_en);
CREATE VIEW vista_cita AS
SELECT c.*, 'CM-'||lpad(c.id::text,6,'0') AS codigo,
       p.nombres||' '||p.apellidos AS paciente,
       m.nombres||' '||m.apellidos AS medico, e.nombre AS especialidad
FROM cita c JOIN paciente p ON p.id=c.paciente_id
JOIN medico m ON m.id=c.medico_id
JOIN especialidad e ON e.id=c.especialidad_id;
