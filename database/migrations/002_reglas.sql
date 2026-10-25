-- Autoría y protección de reglas. La aplicación comprueba además sesión y permiso.
CREATE FUNCTION fn_es_personal(p_usuario bigint, p_roles text[])
RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM usuario u JOIN rol r ON r.id=u.rol_id
 WHERE u.id=p_usuario AND u.habilitado AND r.codigo=ANY(p_roles));
$$;

CREATE FUNCTION fn_paciente_sin_deuda(p_paciente bigint)
RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT NOT EXISTS(SELECT 1 FROM multa m
 JOIN incumplimiento i ON i.id=m.incumplimiento_id
 JOIN cita c ON c.id=i.cita_id
 WHERE c.paciente_id=p_paciente AND m.estado='pendiente');
$$;

CREATE FUNCTION fn_validar_cita() RETURNS trigger LANGUAGE plpgsql AS $$
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
 IF (NEW.origen_cambio='clinica' OR NEW.justificada) AND NOT fn_es_personal(v_actor,ARRAY['administrador','recepcionista']) THEN
  RAISE EXCEPTION 'Solo el personal valida causas justificadas o de la clínica';
 END IF;
 IF v_cambio AND NEW.estado IN ('programada','confirmada') THEN
  PERFORM 1 FROM paciente WHERE id=NEW.paciente_id FOR UPDATE;
  IF NOT EXISTS(SELECT 1 FROM paciente WHERE id=NEW.paciente_id AND activo) THEN
   RAISE EXCEPTION 'Paciente inactivo para nuevas reservas';
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
CREATE TRIGGER tr_validar_cita BEFORE INSERT OR UPDATE ON cita
FOR EACH ROW EXECUTE FUNCTION fn_validar_cita();

CREATE FUNCTION fn_historial_cita() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_actor bigint; v_evento bigint;
BEGIN
 IF TG_OP='UPDATE' AND NEW IS NOT DISTINCT FROM OLD THEN RETURN NEW; END IF;
 v_actor:=NULLIF(current_setting('app.actor_id',true),'')::bigint;
 INSERT INTO historial_cita(cita_id,actor_id,accion,datos_antes,datos_despues)
 VALUES(NEW.id,v_actor,CASE WHEN TG_OP='INSERT' THEN 'crear' ELSE 'modificar' END,
 CASE WHEN TG_OP='INSERT' THEN NULL ELSE to_jsonb(OLD) END,to_jsonb(NEW)) RETURNING id INTO v_evento;
 INSERT INTO aviso(paciente_id,cita_id,clave_evento,tipo,texto)
 VALUES(NEW.paciente_id,NEW.id,'cita:'||NEW.id||':evento:'||v_evento,'cambio_cita',
 'Su cita CM-'||lpad(NEW.id::text,6,'0')||' tiene estado '||NEW.estado||'. Consulte el detalle en la web.');
 RETURN NEW;
END;
$$;
CREATE TRIGGER tr_historial_cita AFTER INSERT OR UPDATE ON cita
FOR EACH ROW EXECUTE FUNCTION fn_historial_cita();

CREATE FUNCTION fn_sancionar_cita() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_plazo integer; v_monto numeric(10,2); v_id bigint; v_total integer;
BEGIN
 IF NEW.estado=OLD.estado OR NEW.justificada OR NEW.origen_cambio='clinica' THEN RETURN NEW; END IF;
 SELECT plazo_cancelacion_horas,multa_bs INTO STRICT v_plazo,v_monto FROM parametro_sistema WHERE id=1;
 IF NEW.estado='cancelada' AND NEW.inicio-COALESCE(NEW.aviso_cancelacion_en,now())>=make_interval(hours=>v_plazo) THEN
  RETURN NEW;
 END IF;
 IF NEW.estado NOT IN ('cancelada','inasistencia') THEN RETURN NEW; END IF;
 IF EXISTS(SELECT 1 FROM ausencia_medico a WHERE a.medico_id=NEW.medico_id
  AND tstzrange(a.inicio,a.fin,'[)') && tstzrange(NEW.inicio,NEW.fin,'[)')) THEN RETURN NEW; END IF;
 -- Las reservas hechas dentro del plazo se revisan antes de una sanción.
 IF NEW.inicio-NEW.creado_en<make_interval(hours=>v_plazo) AND NOT NEW.justificada THEN
  INSERT INTO evento_administrativo(paciente_id,actor_id,accion,motivo)
  VALUES(NEW.paciente_id,NULLIF(current_setting('app.actor_id',true),'')::bigint,
         'revisar_incidencia','Reserva realizada con menos anticipación que el plazo de cancelación');
  RETURN NEW;
 END IF;
 PERFORM 1 FROM paciente WHERE id=NEW.paciente_id FOR UPDATE;
 INSERT INTO incumplimiento(cita_id,tipo) VALUES(NEW.id,
 CASE WHEN NEW.estado='inasistencia' THEN 'inasistencia' ELSE 'cancelacion_tardia' END)
 ON CONFLICT(cita_id) DO NOTHING RETURNING id INTO v_id;
 IF v_id IS NULL THEN RETURN NEW; END IF;
 SELECT count(*) INTO v_total FROM incumplimiento i JOIN cita c ON c.id=i.cita_id WHERE c.paciente_id=NEW.paciente_id;
 IF v_total>=2 THEN
  INSERT INTO multa(incumplimiento_id,monto) VALUES(v_id,v_monto);
  INSERT INTO aviso(paciente_id,cita_id,clave_evento,tipo,texto)
  VALUES(NEW.paciente_id,NEW.id,'incidencia:'||v_id,'multa','Tiene una multa pendiente. Regularice en recepción antes de una nueva reserva ordinaria.');
 ELSE
  INSERT INTO aviso(paciente_id,cita_id,clave_evento,tipo,texto)
  VALUES(NEW.paciente_id,NEW.id,'incidencia:'||v_id,'advertencia','Primera advertencia por incumplimiento de una cita.');
 END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER tr_sancionar_cita AFTER UPDATE OF estado ON cita
FOR EACH ROW EXECUTE FUNCTION fn_sancionar_cita();

CREATE FUNCTION fn_validar_pago() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_multa multa;
BEGIN
 IF NOT fn_es_personal(NEW.registrado_por,ARRAY['administrador','recepcionista']) THEN
  RAISE EXCEPTION 'El pago lo registra recepción'; END IF;
 SELECT * INTO STRICT v_multa FROM multa WHERE id=NEW.multa_id FOR UPDATE;
 IF v_multa.estado<>'pendiente' OR NEW.monto<>v_multa.monto THEN
  RAISE EXCEPTION 'Multa no pendiente o monto distinto del importe íntegro'; END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER tr_validar_pago BEFORE INSERT ON pago_multa
FOR EACH ROW EXECUTE FUNCTION fn_validar_pago();

CREATE FUNCTION fn_aplicar_pago() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE multa SET estado='pagada' WHERE id=NEW.multa_id;
 RETURN NEW;
END;
$$;
CREATE TRIGGER tr_aplicar_pago AFTER INSERT ON pago_multa
FOR EACH ROW EXECUTE FUNCTION fn_aplicar_pago();

CREATE PROCEDURE sp_programar_cita(p_paciente bigint,p_medico bigint,p_especialidad bigint,
 p_inicio timestamptz,p_actor bigint,p_motivo varchar DEFAULT NULL)
LANGUAGE plpgsql AS $$
BEGIN
 PERFORM set_config('app.actor_id',p_actor::text,true);
 INSERT INTO cita(paciente_id,medico_id,especialidad_id,inicio,fin,motivo,creado_por)
 VALUES(p_paciente,p_medico,p_especialidad,p_inicio,p_inicio+interval '30 minutes',p_motivo,p_actor);
END;
$$;

CREATE PROCEDURE sp_cancelar_cita(p_cita bigint,p_actor bigint,p_motivo varchar,
 p_origen varchar DEFAULT 'paciente',p_justificada boolean DEFAULT false,
 p_aviso timestamptz DEFAULT NULL)
LANGUAGE plpgsql AS $$
DECLARE v_cita cita;
BEGIN
 PERFORM set_config('app.actor_id',p_actor::text,true);
 SELECT * INTO STRICT v_cita FROM cita WHERE id=p_cita FOR UPDATE;
 IF v_cita.estado NOT IN ('programada','confirmada') THEN RAISE EXCEPTION 'Cita no cancelable'; END IF;
 IF p_aviso>now() THEN RAISE EXCEPTION 'La hora del aviso no puede ser futura'; END IF;
 IF p_aviso IS NOT NULL AND NOT fn_es_personal(p_actor,ARRAY['administrador','recepcionista']) THEN
  RAISE EXCEPTION 'Solo recepción verifica un aviso previo'; END IF;
 UPDATE cita SET estado='cancelada',motivo_cambio=p_motivo,origen_cambio=p_origen,
 justificada=p_justificada,aviso_cancelacion_en=COALESCE(p_aviso,now()) WHERE id=p_cita;
END;
$$;

CREATE PROCEDURE sp_registrar_pago(p_multa bigint,p_actor bigint,p_monto numeric,p_comprobante varchar)
LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO pago_multa(multa_id,registrado_por,monto,comprobante)
 VALUES(p_multa,p_actor,p_monto,p_comprobante);
END;
$$;

CREATE PROCEDURE sp_desactivar_inactivos()
LANGUAGE plpgsql AS $$
BEGIN
 WITH candidatos AS (
  SELECT p.id FROM paciente p
  WHERE p.activo AND greatest(p.creado_en,COALESCE(p.reactivado_en,p.creado_en),
    COALESCE((SELECT max(c.fin) FROM cita c WHERE c.paciente_id=p.id AND c.estado='atendida'),p.creado_en))
    < now()-interval '1 year'
  AND NOT EXISTS(SELECT 1 FROM cita c WHERE c.paciente_id=p.id
    AND c.estado IN ('programada','confirmada') AND c.fin>=now())
 ), cambiados AS (
  UPDATE paciente SET activo=false WHERE id IN(SELECT id FROM candidatos) RETURNING id
 ) INSERT INTO evento_administrativo(paciente_id,accion,motivo)
 SELECT id,'inactividad','Un año sin atención ni reactivación y sin citas vigentes futuras' FROM cambiados;
END;
$$;

CREATE PROCEDURE sp_reactivar_paciente(p_paciente bigint,p_actor bigint,p_motivo varchar)
LANGUAGE plpgsql AS $$
BEGIN
 IF NOT fn_es_personal(p_actor,ARRAY['administrador','recepcionista']) THEN
  RAISE EXCEPTION 'Reactivación no autorizada'; END IF;
 UPDATE paciente SET activo=true,reactivado_en=now() WHERE id=p_paciente;
 IF NOT FOUND THEN RAISE EXCEPTION 'Paciente inexistente'; END IF;
 INSERT INTO evento_administrativo(paciente_id,actor_id,accion,motivo)
 VALUES(p_paciente,p_actor,'reactivar',p_motivo);
END;
$$;

CREATE FUNCTION fn_proteger_historial() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Registro histórico protegido; utilice estados y desactivación';
END;
$$;
CREATE TRIGGER tr_no_borrar_cita BEFORE DELETE ON cita
FOR EACH ROW EXECUTE FUNCTION fn_proteger_historial();
CREATE TRIGGER tr_no_alterar_historial BEFORE UPDATE OR DELETE ON historial_cita
FOR EACH ROW EXECUTE FUNCTION fn_proteger_historial();
CREATE TRIGGER tr_no_alterar_pago BEFORE UPDATE OR DELETE ON pago_multa
FOR EACH ROW EXECUTE FUNCTION fn_proteger_historial();
