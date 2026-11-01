-- CU21-CU25. Preserva los registros y las migraciones anteriores.
ALTER TABLE solicitud_urgente ADD COLUMN contacto varchar(30);
ALTER TABLE solicitud_urgente ADD COLUMN clave_envio varchar(64);
CREATE UNIQUE INDEX uq_solicitud_envio ON solicitud_urgente(paciente_id,clave_envio);
CREATE TABLE historial_solicitud_urgente (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 solicitud_id bigint NOT NULL REFERENCES solicitud_urgente(id),
 actor_id bigint NOT NULL REFERENCES usuario(id),
 accion varchar(20) NOT NULL CHECK(accion IN ('solicitar','seguimiento','asignar','cerrar')),
 resolucion varchar(500) NOT NULL,
 creado_en timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_solicitud_estado ON solicitud_urgente(estado,id);
CREATE TRIGGER tr_proteger_gestion_urgente BEFORE UPDATE OR DELETE ON historial_solicitud_urgente
FOR EACH ROW EXECUTE FUNCTION fn_proteger_historial();
-- Una solicitud urgente permite gestionar la restricción ordinaria con responsable
-- y motivo. El servicio exige aceptación del paciente y excepción autorizada.
CREATE OR REPLACE FUNCTION fn_validar_cita() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_local timestamp; v_hora time; v_fin time;
        v_cambio boolean; v_actor bigint;
BEGIN
 v_actor:=NULLIF(current_setting('app.actor_id',true),'')::bigint;
 IF v_actor IS NULL OR NOT EXISTS(SELECT 1 FROM usuario WHERE id=v_actor AND habilitado) THEN
  RAISE EXCEPTION 'Falta un responsable habilitado de la operación';
 END IF;
 IF TG_OP='INSERT' THEN
  IF NEW.estado<>'programada' THEN RAISE EXCEPTION 'Una nueva cita inicia Programada'; END IF;
  NEW.creado_por:=v_actor;
  v_cambio:=true;
 ELSE
  IF NEW.id<>OLD.id OR NEW.paciente_id<>OLD.paciente_id OR
     NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en THEN
   RAISE EXCEPTION 'La identidad y autoría original de la cita no se modifican';
  END IF;
  v_cambio:=(NEW.inicio,NEW.fin,NEW.medico_id,NEW.especialidad_id)
         IS DISTINCT FROM (OLD.inicio,OLD.fin,OLD.medico_id,OLD.especialidad_id);
  IF OLD.estado IN ('atendida','cancelada','inasistencia') AND NEW IS DISTINCT FROM OLD THEN
   RAISE EXCEPTION 'Una cita terminal conserva su historial';
  END IF;
  IF v_cambio AND NEW.estado<>'programada' THEN
   RAISE EXCEPTION 'Una reprogramación queda Programada';
  END IF;
  IF NOT v_cambio AND NEW.estado<>OLD.estado AND NOT
     (OLD.estado='programada' AND NEW.estado IN ('confirmada','cancelada','atendida','inasistencia') OR
      OLD.estado='confirmada' AND NEW.estado IN ('cancelada','atendida','inasistencia')) THEN
   RAISE EXCEPTION 'Transición de estado no permitida';
  END IF;
  IF NEW.estado IN ('atendida','inasistencia') AND NOT fn_es_personal(v_actor,ARRAY['administrador','recepcionista','medico']) THEN
   RAISE EXCEPTION 'El paciente no registra atención ni inasistencia';
  END IF;
  IF NEW.estado='inasistencia' AND NOT fn_es_personal(v_actor,ARRAY['administrador','recepcionista']) THEN
   RAISE EXCEPTION 'Recepción verifica la inasistencia';
  END IF;
  IF NEW.estado IN ('atendida','inasistencia') AND NEW.inicio>now() THEN
   RAISE EXCEPTION 'El turno todavía no comenzó';
  END IF;
  IF NEW.estado='inasistencia' AND NEW.fin>now() THEN
   RAISE EXCEPTION 'El turno aún no finalizó';
  END IF;
 END IF;
 IF NOT fn_es_personal(v_actor,ARRAY['administrador','recepcionista']) AND NOT EXISTS(
  SELECT 1 FROM paciente WHERE id=NEW.paciente_id AND usuario_id=v_actor
 ) AND NOT (TG_OP='UPDATE' AND NEW.estado='atendida' AND EXISTS(
  SELECT 1 FROM medico WHERE id=NEW.medico_id AND usuario_id=v_actor)) THEN
  RAISE EXCEPTION 'El registro no pertenece al usuario autorizado';
 END IF;
 IF (NEW.origen_cambio='clinica' OR NEW.justificada)
    AND NOT fn_es_personal(v_actor,ARRAY['administrador','recepcionista'])
    AND NOT (TG_OP='UPDATE' AND NEW.estado='atendida'
      AND (NEW.origen_cambio,NEW.justificada,NEW.motivo_cambio,NEW.aviso_cancelacion_en)
          IS NOT DISTINCT FROM
          (OLD.origen_cambio,OLD.justificada,OLD.motivo_cambio,OLD.aviso_cancelacion_en)) THEN
  RAISE EXCEPTION 'Solo el personal valida causas justificadas o de la clínica';
 END IF;
 IF v_cambio AND NEW.estado IN ('programada','confirmada') THEN
  PERFORM 1 FROM paciente WHERE id=NEW.paciente_id FOR UPDATE;
  IF NOT EXISTS(SELECT 1 FROM paciente WHERE id=NEW.paciente_id AND activo) THEN
   IF NEW.tipo<>'urgente' OR NOT fn_es_personal(v_actor,ARRAY['administrador','recepcionista'])
      OR NULLIF(btrim(NEW.motivo),'') IS NULL THEN
    RAISE EXCEPTION 'Paciente inactivo para nuevas reservas ordinarias';
   END IF;
  END IF;
  IF NEW.tipo='ordinaria' AND NOT fn_paciente_sin_deuda(NEW.paciente_id) THEN
   RAISE EXCEPTION 'Existen multas pendientes';
  END IF;
  IF NEW.tipo='urgente' AND NOT fn_es_personal(v_actor,ARRAY['administrador','recepcionista']) THEN
   RAISE EXCEPTION 'La solicitud urgente requiere gestión de recepción';
  END IF;
  IF NEW.inicio<now() THEN RAISE EXCEPTION 'No reservar en el pasado'; END IF;
  PERFORM pg_advisory_xact_lock(NEW.medico_id);
  IF NOT EXISTS(SELECT 1 FROM medico m JOIN medico_especialidad me ON me.medico_id=m.id
    JOIN especialidad e ON e.id=me.especialidad_id
    WHERE m.id=NEW.medico_id AND me.especialidad_id=NEW.especialidad_id
    AND m.activo AND me.activo AND e.activo) THEN
   RAISE EXCEPTION 'Médico o especialidad no disponible';
  END IF;
  v_local:=NEW.inicio AT TIME ZONE 'America/La_Paz';
  v_hora:=v_local::time; v_fin:=(NEW.fin AT TIME ZONE 'America/La_Paz')::time;
  IF (NEW.fin AT TIME ZONE 'America/La_Paz')::date<>v_local::date OR NOT EXISTS(
   SELECT 1 FROM horario h WHERE h.medico_id=NEW.medico_id AND h.activo
   AND h.dia_semana=extract(isodow FROM v_local)
   AND v_hora>=h.hora_inicio AND v_fin<=h.hora_fin) THEN
   RAISE EXCEPTION 'Cita fuera del horario de atención';
  END IF;
  IF EXISTS(SELECT 1 FROM ausencia_medico a WHERE a.medico_id=NEW.medico_id
    AND tstzrange(a.inicio,a.fin,'[)') && tstzrange(NEW.inicio,NEW.fin,'[)')) THEN
   RAISE EXCEPTION 'Médico ausente en ese intervalo';
  END IF;
 END IF;
 RETURN NEW;
END;
$$;
