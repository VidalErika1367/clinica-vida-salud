"""CU16-CU20: ejecutar solo en servidor local y base vacía terminada en _test.

Ejecuta primero la regresión CU01-CU15 y después estas pruebas. Las credenciales
son aleatorias y las citas históricas se crean exclusivamente como fixtures.
Usa las mismas variables e instrucciones de tests/http_smoke.py.
"""
import html
import json
import os
from pathlib import Path
import re
import runpy
import secrets
import subprocess
import urllib.parse
from concurrent.futures import ThreadPoolExecutor

ROOT = Path(__file__).resolve().parents[1]
context = runpy.run_path(str(ROOT / 'tests/http_smoke.py'))
sql, check, Browser = (context[key] for key in ('sql', 'check', 'Browser'))
results = context['results']
baseline_count = len(results)
admin, admin_id = context['admin'], context['admin_id']
roles = context['roles']
prefix = 'bloque2_' + secrets.token_hex(4)
password = 'Aa1-' + secrets.token_urlsafe(22)  # cumple la política de contraseñas


def create_account(role, suffix):
    name = prefix + '_' + suffix
    data = {'csrf': admin.token('/usuarios/nuevo'), 'nombre_usuario': name,
            'correo': name+'@example.invalid', 'rol_id': str(roles[role]), 'habilitado': '1',
            'password': password, 'password_confirmation': password}
    if role == 'paciente':
        data.update(ci=name, nombres=suffix, apellidos='Prueba bloque dos', telefono='00000000')
    check(admin.request('/usuarios/nuevo', data)[0] == 303, 'Fixture: cuenta de '+suffix)
    uid = int(sql('SELECT id FROM usuario WHERE nombre_usuario=?', [name])[0]['id'])
    browser = Browser()
    check(browser.login(name, password)[0][0] == 303, 'Acceso de '+suffix)
    return uid, browser


doc_user, doctor = create_account('medico', 'medico_uno')
doc2_user, doctor2 = create_account('medico', 'medico_dos')
unlinked_id, unlinked = create_account('medico', 'sin_vinculo')
receipt_id, receipt = create_account('recepcionista', 'recepcion')
patient_user, patient = create_account('paciente', 'paciente_uno')
other_user, other = create_account('paciente', 'paciente_dos')
patient_id = int(sql('SELECT id FROM paciente WHERE usuario_id=?', [patient_user])[0]['id'])
other_id = int(sql('SELECT id FROM paciente WHERE usuario_id=?', [other_user])[0]['id'])
specialty = int(sql('INSERT INTO especialidad(nombre) VALUES(?) RETURNING id', [prefix])[0]['id'])
doc_ids = []
for uid, name in [(doc_user, 'Médico Uno'), (doc2_user, 'Médico Dos')]:
    did = int(sql('INSERT INTO medico(usuario_id,registro_profesional,nombres,apellidos) VALUES(?,?,?,?) RETURNING id',
                  [uid, prefix+str(uid), name, prefix])[0]['id'])
    sql('INSERT INTO medico_especialidad(medico_id,especialidad_id) VALUES(?,?)', [did, specialty])
    doc_ids.append(did)
doc_id, doc2_id = doc_ids
today = sql('SELECT current_date::text AS fecha')[0]['fecha']

# La función existe únicamente en esta base desechable. No es un comando de uso real.
sql("""CREATE FUNCTION prueba_cu16_crear_cita(p_paciente bigint,p_medico bigint,p_especialidad bigint,
    p_dia integer,p_bloque integer,p_actor bigint,p_origen text DEFAULT 'paciente') RETURNS bigint
    LANGUAGE plpgsql AS $$ DECLARE v_id bigint; v_inicio timestamptz; BEGIN
    v_inicio:=current_date+p_dia+time '08:00'+make_interval(mins=>30*p_bloque);
    PERFORM set_config('session_replication_role','replica',true);
    INSERT INTO cita(paciente_id,medico_id,especialidad_id,inicio,fin,creado_por,creado_en,
        origen_cambio,justificada,motivo_cambio)
    VALUES(p_paciente,p_medico,p_especialidad,v_inicio,v_inicio+interval '30 minutes',p_actor,
        v_inicio-interval '3 days',p_origen,p_origen='clinica',
        CASE WHEN p_origen='clinica' THEN 'Causa previamente validada por recepción' ELSE NULL END)
    RETURNING id INTO v_id;
    PERFORM set_config('session_replication_role','origin',true);
    INSERT INTO historial_cita(cita_id,actor_id,accion,datos_despues)
        SELECT id,p_actor,'crear',to_jsonb(cita) FROM cita WHERE id=v_id;
    RETURN v_id; END $$""")


def fixture(days=-1, slot=0, pid=patient_id, did=doc_id, origin='paciente'):
    return int(sql('SELECT prueba_cu16_crear_cita(?,?,?,?,?,?,?) AS id',
                   [pid, did, specialty, days, slot, admin_id, origin])[0]['id'])


def payload(browser, cid, **extra):
    status, body, _ = browser.request('/citas/'+str(cid))
    check(status == 200, 'Consulta autorizada de cita '+str(cid))
    version = html.unescape(re.search(r'name="version" value="([^"]+)"', body).group(1)) if 'name="version"' in body else None
    # Las citas vencidas pueden no tener otra operación con versión en su detalle.
    if version is None:
        page = '/atender' if browser in (doctor, doctor2) else '/inasistencia'
        form = browser.request('/citas/'+str(cid)+page)[1]
        version = html.unescape(re.search(r'name="version" value="([^"]+)"', form).group(1))
    return {'csrf': browser.token('/panel'), 'version': version, **extra}


def counts(cid):
    return sql("""SELECT c.estado,(SELECT count(*) FROM historial_cita WHERE cita_id=c.id) AS historial,
        (SELECT count(*) FROM aviso WHERE cita_id=c.id) AS avisos,
        (SELECT count(*) FROM incumplimiento WHERE cita_id=c.id) AS incidencias,
        (SELECT count(*) FROM multa mu JOIN incumplimiento i ON i.id=mu.incumplimiento_id WHERE i.cita_id=c.id) AS multas
        FROM cita c WHERE c.id=?""", [cid])[0]


def geturl(path, **filters):
    return path+'?'+urllib.parse.urlencode(filters)


past = fixture()
future = fixture(3)
foreign = fixture(pid=other_id, did=doc2_id)
clinic = fixture(slot=1, origin='clinica')
check(doctor.request('/agenda')[0] == 200 and doctor.request('/citas')[0] == 200,
      'CU18: médico accede a su agenda y listado')
check(doctor.request('/citas/'+str(foreign))[0] == 403,
      'Médico no consulta una cita ajena cambiando el identificador')
check(doctor.request(geturl('/agenda', medico_id=doc2_id))[0] == 403,
      'CU18: médico no selecciona otro profesional')
code = 'CM-'+str(foreign).zfill(6)
check(code not in doctor.request(geturl('/citas', estado='todos'))[1],
      'El listado del médico no filtra solo en la interfaz: excluye citas ajenas')
check(unlinked.request('/agenda')[0] == 200 and 'todavía no está vinculada' in unlinked.request('/agenda')[1]
      and code not in unlinked.request('/citas?estado=todos')[1],
      'Cuenta médica sin vínculo ve un estado vacío y ninguna agenda global')

for browser, role in [(patient, 'Paciente'), (doctor, 'Médico')]:
    check(browser.request('/reportes')[0] == 403, role+' no genera reportes administrativos')
    check(browser.request('/citas/'+str(past)+'/inasistencia')[0] == 403,
          role+' no abre el registro de inasistencia')
check(patient.request('/agenda')[0] == 403 and doctor.request('/historial')[0] == 403,
      'CU18-CU19 mantienen separados los alcances de médico y paciente')
check(patient.request('/citas/'+str(past)+'/atender')[0] == 403,
      'Paciente no registra su propia atención')
check(doctor.request('/citas/'+str(foreign)+'/atender', {'csrf': doctor.token('/panel'), 'version':'falsa',
      'atencion_realizada':'1'})[0] == 403, 'CU16: servidor rechaza atención de otro médico')

data = payload(doctor, future, atencion_realizada='1')
before = counts(future)
check(doctor.request('/citas/'+str(future)+'/atender', data)[0] == 422 and counts(future) == before,
      'CU16: no permite atención futura ni genera cambios parciales')
data = payload(doctor, past)
before = counts(past)
check(doctor.request('/citas/'+str(past)+'/atender', data)[0] == 422 and counts(past) == before,
      'CU16: exige confirmación explícita de que se realizó la consulta')
check(doctor.request('/citas/'+str(past)+'/atender', dict(data, csrf='incorrecto', atencion_realizada='1'))[0] == 419,
      'CU16: formulario protegido contra CSRF')
data = payload(doctor, past, atencion_realizada='1')
check(doctor.request('/citas/'+str(past)+'/atender', data)[0] == 303,
      'CU16: médico asignado registra atención realizada')
after = counts(past)
check(after['estado'] == 'atendida' and int(after['historial']) == int(before['historial'])+1
      and int(after['avisos']) == int(before['avisos'])+1 and int(after['incidencias']) == 0,
      'CU16: atención, historial, responsable y aviso se guardan juntos sin sanción')
check(int(sql('SELECT actor_id FROM historial_cita WHERE cita_id=? ORDER BY id DESC LIMIT 1', [past])[0]['actor_id']) == doc_user,
      'CU16: historial atribuye la atención al médico autenticado')
check(sql("SELECT max(fin) AS referencia FROM cita WHERE paciente_id=? AND estado='atendida'", [patient_id])[0]['referencia'] is not None,
      'La atención participa en la referencia existente de inactividad del paciente')
check(doctor.request('/citas/'+str(past)+'/atender', data)[0] == 422 and counts(past) == after,
      'CU16: doble envío no duplica atención, historial ni avisos')
for action in ['cancelar', 'reprogramar', 'inasistencia']:
    check(receipt.request('/citas/'+str(past)+'/'+action,
          {'csrf': receipt.token('/panel'), 'version':data['version'], 'motivo_cambio':'Intento de prueba',
           'medico_disponible':'1', 'paciente_ausente':'1'})[0] == 422,
          'Cita Atendida impide el cambio posterior: '+action)
data = payload(doctor, clinic, atencion_realizada='1', origen_cambio='paciente', justificada='0')
check(doctor.request('/citas/'+str(clinic)+'/atender', data)[0] == 303,
      'CU16: médico cierra cita con una causa clínica previamente validada')
row = sql('SELECT origen_cambio,justificada,motivo_cambio FROM cita WHERE id=?', [clinic])[0]
check(row['origen_cambio'] == 'clinica' and row['justificada']
      and row['motivo_cambio'] == 'Causa previamente validada por recepción',
      'CU16: conserva la justificación anterior e ignora su manipulación en el navegador')

fault = fixture(slot=2)
data = payload(doctor, fault, atencion_realizada='1')
before = counts(fault)
sql("ALTER TABLE aviso ADD CONSTRAINT prueba_cu16_fallo CHECK(tipo<>'cambio_cita') NOT VALID")
try:
    status = doctor.request('/citas/'+str(fault)+'/atender', data)[0]
finally:
    sql('ALTER TABLE aviso DROP CONSTRAINT prueba_cu16_fallo')
check(status == 500 and counts(fault) == before,
      'CU16: fallo al guardar aviso revierte estado e historial completos')

no_show = fixture(-2, 0)
no_show2 = fixture(-2, 1)
justified = fixture(-2, 2)
absent = fixture(-2, 3)
data = payload(receipt, no_show, motivo_cambio='Ausencia verificada')
before = counts(no_show)
check(receipt.request('/citas/'+str(no_show)+'/inasistencia', data)[0] == 422 and counts(no_show) == before,
      'CU17: exige verificar paciente ausente y médico disponible')
data.update(paciente_ausente='1', medico_disponible='1')
check(receipt.request('/citas/'+str(no_show)+'/inasistencia', data)[0] == 303,
      'CU17: recepción registra inasistencia con el turno finalizado')
check(counts(no_show)['estado'] == 'inasistencia' and int(counts(no_show)['incidencias']) == 1
      and int(counts(no_show)['multas']) == 0,
      'CU17: primera incidencia válida genera advertencia sin multa')
after = counts(no_show)
check(receipt.request('/citas/'+str(no_show)+'/inasistencia', data)[0] == 422 and counts(no_show) == after,
      'CU17: repetir el envío no duplica advertencias ni avisos')
data2 = payload(receipt, no_show2, motivo_cambio='Segunda ausencia verificada', paciente_ausente='1', medico_disponible='1')
check(receipt.request('/citas/'+str(no_show2)+'/inasistencia', data2)[0] == 303 and int(counts(no_show2)['multas']) == 1,
      'CU17: segunda incidencia genera una multa pendiente')
check(not sql('SELECT fn_paciente_sin_deuda(?) AS libre', [patient_id])[0]['libre'],
      'La multa de inasistencia activa la restricción de nuevas reservas ordinarias')
data = payload(receipt, justified, motivo_cambio='Causa justificada verificada', paciente_ausente='1', medico_disponible='1', justificada='1')
check(receipt.request('/citas/'+str(justified)+'/inasistencia', data)[0] == 303
      and int(counts(justified)['incidencias']) == 0,
      'CU17: causa justificada autorizada queda trazada sin penalización')
row = sql('SELECT inicio,fin FROM cita WHERE id=?', [absent])[0]
sql('INSERT INTO ausencia_medico(medico_id,inicio,fin,motivo,registrado_por) VALUES(?,?,?,?,?)',
    [doc_id, row['inicio'], row['fin'], 'Ausencia registrada de prueba', receipt_id])
data = payload(receipt, absent, motivo_cambio='No corresponde sancionar', paciente_ausente='1', medico_disponible='1')
before = counts(absent)
check(receipt.request('/citas/'+str(absent)+'/inasistencia', data)[0] == 422 and counts(absent) == before,
      'CU17: ausencia del médico impide atribuir una inasistencia al paciente')
data = payload(receipt, future, motivo_cambio='Intento temprano', paciente_ausente='1', medico_disponible='1')
check(receipt.request('/citas/'+str(future)+'/inasistencia', data)[0] == 422,
      'CU17: turno sin finalizar no puede registrarse como inasistencia')

# Permiso retirado entre abrir y enviar, además de rechazo de formularios antiguos.
stale = fixture(-3, 0)
data = payload(doctor, stale, atencion_realizada='1')
sql("SELECT set_config('app.actor_id',?,false)", [str(admin_id)])
# Modificación de un campo no terminal con autor válido dentro del mismo bloque SQL.
sql("""DO $$ BEGIN PERFORM set_config('app.actor_id','"""+str(admin_id)+"""',true);
    UPDATE cita SET motivo='Cambió la cita después de abrir el formulario' WHERE id="""+str(stale)+"""; END $$""")
check(doctor.request('/citas/'+str(stale)+'/atender', data)[0] == 422 and counts(stale)['estado'] == 'programada',
      'CU16: formulario antiguo no sobrescribe una cita modificada')
data = payload(doctor, stale, atencion_realizada='1')
sql("DELETE FROM rol_permiso WHERE rol_id=? AND permiso_id=(SELECT id FROM permiso WHERE codigo='CU16')", [roles['medico']])
try:
    check(doctor.request('/citas/'+str(stale)+'/atender', data)[0] == 403,
          'CU16: revocar permiso invalida un formulario ya abierto')
finally:
    sql("INSERT INTO rol_permiso SELECT ?,id FROM permiso WHERE codigo='CU16'", [roles['medico']])

day = sql('SELECT inicio::date::text AS dia FROM cita WHERE id=?', [absent])[0]['dia']
before = counts(absent)
status, body, _ = receipt.request(geturl('/agenda', fecha=day, medico_id=doc_id))
check(status == 200 and 'Ausencia registrada de prueba' in body and counts(absent) == before,
      'CU18: agenda muestra ausencias solapadas sin modificar citas')
check(receipt.request(geturl('/agenda', fecha='2026-02-30'))[0] == 422
      and receipt.request('/agenda?fecha[]=2026-01-01')[0] == 422,
      'CU18: fechas inexistentes y filtros con estructuras inválidas se rechazan')
empty = receipt.request(geturl('/agenda', fecha='2001-01-01', medico_id=doc_id))
check(empty[0] == 200 and 'No hay citas con los filtros' in empty[1],
      'CU18: agenda vacía informa cero citas sin inventar turnos')

sql('UPDATE paciente SET activo=false WHERE id=?', [patient_id])
history_url = geturl('/historial', desde='2000-01-01', hasta='2099-12-31')
status, body, _ = patient.request(history_url)
check(status == 200 and 'Paciente inactivo' in body and 'CM-'+str(no_show).zfill(6) in body,
      'CU19: paciente inactivo conserva historial e inasistencias')
check('CM-'+str(foreign).zfill(6) not in body and patient.request(geturl('/historial', paciente_id=other_id))[0] == 403,
      'CU19: paciente no consulta ni filtra citas de otra ficha')
check(receipt.request(geturl('/historial', paciente_id=patient_id, desde='2000-01-01', hasta='2099-12-31'))[0] == 200,
      'CU19: recepción consulta el historial de un paciente seleccionado')
check(patient.request(geturl('/historial', desde='2026-12-01', hasta='2026-01-01'))[0] == 422,
      'CU19: rango invertido se rechaza')

# Más de una página, tanto en citas como en el registro de cambios de una reserva.
for index in range(55):
    fixture(-5-index//16, index%16)
status, body, _ = patient.request(history_url)
check(status == 200 and 'Siguiente' in body and len(re.findall('Ver cita y cambios', body)) == 50,
      'CU19: historial extenso se pagina y no se trunca silenciosamente')
check(patient.request(history_url+'&pagina=2')[0] == 200
      and 'Ver cita y cambios' in patient.request(history_url+'&pagina=2')[1],
      'CU19: las citas anteriores siguen disponibles en la página siguiente')
sql("""INSERT INTO historial_cita(cita_id,actor_id,accion,datos_despues)
    SELECT c.id,?,'fixture histórico',to_jsonb(c) FROM cita c CROSS JOIN generate_series(1,25) WHERE c.id=?""", [admin_id, past])
check('Cambios anteriores' in patient.request('/citas/'+str(past))[1]
      and patient.request('/citas/'+str(past)+'?historial_pagina=2')[0] == 200,
      'CU19: permite recorrer también más de 20 cambios de la misma cita')

report_url = geturl('/reportes', desde='2000-01-01', hasta='2099-12-31', medico_id=doc_id, especialidad_id=specialty)
status, body, _ = receipt.request(report_url)
expected = sql('SELECT count(*) AS n FROM cita WHERE medico_id=?', [doc_id])[0]['n']
check(status == 200 and int(re.search(r'data-total="total">(\d+)', body).group(1)) == int(expected),
      'CU20: reporte cuenta todas las citas filtradas, no solo la primera página')
expected_attended = sql("SELECT count(*) AS n FROM cita WHERE medico_id=? AND estado='atendida'", [doc_id])[0]['n']
check(int(re.search(r'data-total="atendidas">(\d+)', body).group(1)) == int(expected_attended),
      'CU20: total de atenciones coincide con la base y no se multiplica por cambios históricos')
empty = receipt.request(geturl('/reportes', desde='2001-01-01', hasta='2001-01-01', medico_id=doc_id))
check(empty[0] == 200 and 'data-total="total">0<' in empty[1], 'CU20: período vacío muestra total cero')
check(receipt.request(geturl('/reportes', desde='2026-12-01', hasta='2026-01-01'))[0] == 422
      and receipt.request('/reportes?tipo[]=pagos')[0] == 422,
      'CU20: rechaza períodos invertidos y filtros malformados')

fine = int(sql('SELECT mu.id FROM multa mu JOIN incumplimiento i ON i.id=mu.incumplimiento_id WHERE i.cita_id=?', [no_show2])[0]['id'])
# El pago es un fixture del reporte: CU23 aún no se ofrece como operación web.
sql('CALL sp_registrar_pago(?,?,?,?)', [fine, receipt_id, '20.00', prefix+'-pago'])
payments_url = geturl('/reportes', tipo='pagos', desde=today, hasta=today, medico_id=doc_id)
status, body, _ = receipt.request(payments_url)
check(status == 200 and 'data-total="pagos">1<' in body and 'data-total="monto">20,00<' in body,
      'CU20: pagos usan fecha de pago e importe exacto, aunque la cita sea de otro día')
sql("""INSERT INTO historial_cita(cita_id,actor_id,accion,datos_despues)
    SELECT c.id,?,'fixture histórico',to_jsonb(c) FROM cita c CROSS JOIN generate_series(1,12) WHERE c.id=?""", [admin_id, no_show2])
check('data-total="monto">20,00<' in receipt.request(payments_url)[1],
      'CU20: múltiples cambios de una cita no duplican el importe cobrado')
for permission, path in [('CU18','/agenda'), ('CU19','/historial'), ('CU20','/reportes')]:
    sql('DELETE FROM rol_permiso WHERE rol_id=? AND permiso_id=(SELECT id FROM permiso WHERE codigo=?)', [roles['recepcionista'], permission])
    try:
        check(receipt.request(path)[0] == 403, 'Revocar '+permission+' se aplica a la siguiente consulta')
    finally:
        sql('INSERT INTO rol_permiso SELECT ?,id FROM permiso WHERE codigo=?', [roles['recepcionista'], permission])

race = fixture(-10, 0)
data = payload(doctor, race, atencion_realizada='1')
code = ('require getcwd()."/bootstrap.php";'
        '$x=json_decode(stream_get_contents(STDIN),true);'
        'App\\Database::run("SELECT pg_sleep(0.15)");'
        'try { App\\AppointmentService::attend($x["id"],$x["data"],$x["actor"]);echo "guardada"; }'
        'catch (App\\ValidationException $e) { echo "rechazada"; }')
before = counts(race)
def concurrent_attempt(_):
    return subprocess.run([os.environ['PHP_BIN'], '-r', code], cwd=ROOT,
        input=json.dumps({'id':race, 'data':data, 'actor':doc_user}), capture_output=True, text=True, check=True).stdout
with ThreadPoolExecutor(max_workers=2) as pool:
    outcomes = list(pool.map(concurrent_attempt, range(2)))
after = counts(race)
check(sorted(outcomes) == ['guardada','rechazada'] and int(after['historial']) == int(before['historial'])+1
      and int(after['avisos']) == int(before['avisos'])+1,
      'CU16: dos conexiones PostgreSQL concurrentes producen una atención y un solo evento')

sql('DROP FUNCTION prueba_cu16_crear_cita(bigint,bigint,bigint,integer,integer,bigint,text)')
(ROOT/'tests/resultados_validacion_cu16_20.json').write_text(
    json.dumps({'regresion':baseline_count, 'nuevas':len(results)-baseline_count, 'passed':len(results), 'results':results},
               ensure_ascii=False, indent=2))
print(f'{len(results)-baseline_count} comprobaciones nuevas; {len(results)} comprobaciones HTTP correctas en total.')
