"""Restablecer contraseña con apoyo de recepción (amplía CU02, CU04 y CU06) y regresión CU01-CU30.

Solo servidor local y base VACÍA desechable *_test: hereda los controles de http_smoke.py. El servidor de pruebas
debe iniciarse con CLINICA_RECEPCION_WHATSAPP (tests/ejecutar_todo.ps1 usa los números ficticios 59170000001 y 59170000002).
"""
import hashlib, html, json, re, runpy, secrets, urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
top = runpy.run_path(str(ROOT / 'tests/menu_movil_smoke.py'))
check, Browser, results = (top[k] for k in ('check', 'Browser', 'results'))
base = top['ctx']
sql, php, admin = (base[k] for k in ('sql', 'php', 'admin'))
baseline = len(results)
prefix = 'rst_' + secrets.token_hex(4)
SESSION = 'vida_salud'


def strong():
    """Contraseña aleatoria que cumple la política: mayúscula, minúscula, número y carácter especial."""
    return 'Aa1-' + secrets.token_urlsafe(18)


def create(role, suffix, **extra):
    """Crea una cuenta con el administrador y devuelve (id, nombre, contraseña, navegador con sesión)."""
    name = prefix + '_' + suffix
    password = strong()
    data = {'csrf': admin.token('/usuarios/nuevo'), 'nombre_usuario': name, 'correo': name + '@example.invalid',
            'rol_id': str(roles[role]), 'habilitado': '1', 'password': password, 'password_confirmation': password}
    if role == 'paciente':
        data.update(ci=name + '-ci', nombres=suffix, apellidos='Restablecer', telefono='00000000')
    data.update(extra)
    check(admin.request('/usuarios/nuevo', data)[0] == 303, 'Fixture: cuenta ' + suffix)
    uid = int(sql('SELECT id FROM usuario WHERE nombre_usuario=?', [name])[0]['id'])
    browser = Browser()
    check(browser.login(name, password)[0][0] == 303, 'Fixture: acceso de ' + suffix)
    return uid, name, password, browser


def state(uid):
    row = sql('SELECT password_hash,debe_cambiar_password FROM usuario WHERE id=?', [uid])[0]
    return row['password_hash'], bool(row['debe_cambiar_password'])


def cookie(browser):
    return next((c.value for c in browser.cookies if c.name == SESSION), '')


def location(response):
    return response[2].get('Location', '')


roles = {x['codigo']: int(x['id']) for x in sql('SELECT id,codigo FROM rol')}
rec_uid, rec_name, rec_pw, recep = create('recepcionista', 'recepcion')
rec2_uid, rec2_name, rec2_pw, recep2 = create('recepcionista', 'recepcion_dos')
med_uid, med_name, med_pw, medico = create('medico', 'medico')
adm2_uid, adm2_name, adm2_pw, admin2 = create('administrador', 'admin_dos')
pac_uid, pac_name, pac_pw, paciente = create('paciente', 'paciente')
pac2_uid, pac2_name, pac2_pw, _ = create('paciente', 'paciente_dos')
pac_id = int(sql('SELECT id FROM paciente WHERE usuario_id=?', [pac_uid])[0]['id'])

# --- Esquema y migraciones
column = sql("SELECT is_nullable,column_default FROM information_schema.columns WHERE table_name='usuario' AND column_name='debe_cambiar_password'")
check(len(column) == 1 and column[0]['is_nullable'] == 'NO' and column[0]['column_default'] == 'false', 'La columna debe_cambiar_password es obligatoria y vale false por omisión')
saved = {x['nombre']: x['hash'] for x in sql('SELECT nombre,hash FROM migracion')}
files = sorted((ROOT / 'database/migrations').glob('*.sql'))
check(len(files) == 8 and files[-1].name == '008_restablecer.sql' and all(saved.get(f.name) == hashlib.sha256(f.read_bytes()).hexdigest() for f in files),
      'Las migraciones 001 a 007 conservan sus bytes y 008 quedó registrada')

# --- Enlace y página pública
login_page = Browser().request('/ingresar')[1]
check('href="/olvide-password"' in login_page and '¿Olvidaste tu contraseña?' in login_page, 'El inicio de sesión ofrece el enlace "¿Olvidaste tu contraseña?"')
forgot = Browser().request('/olvide-password')
message = 'Hola, olvidé mi contraseña de la Clínica Vida Salud. Mi CI es: '
encoded = urllib.parse.quote(message, safe='')
check(forgot[0] == 200 and 'Recepción restablece tu contraseña después de verificar tu identidad' in forgot[1], 'La página pública explica que recepción restablece la contraseña')
check(all(f'href="https://wa.me/{n}?text={encoded}"' in forgot[1] for n in ('59170000001', '59170000002'))
      and forgot[1].count('target="_blank" rel="noopener noreferrer"') >= 2 and encoded.endswith('%3A%20'),
      'La página pública muestra un botón de WhatsApp por contacto con el mensaje prellenado codificado')
empty_code = ('$contacts=App\\ReceptionContact::whatsapp(App\\ReceptionContact::FORGOT_MESSAGE);ob_start();'
              'require getcwd()."/views/forgot_password.php";echo json_encode(ob_get_clean());')
no_contacts = php(empty_code, extra={'CLINICA_RECEPCION_WHATSAPP': 'sin-numero'})
check('wa.me' not in no_contacts and 'Acércate a recepción' in no_contacts, 'Sin contactos configurados la página pide acercarse a recepción')
check(paciente.request('/olvide-password')[0] == 303, 'Con la sesión abierta la página pública redirige al panel')

# --- Recepción restablece la contraseña de un paciente
ficha = recep.request(f'/pacientes/{pac_id}')
check(ficha[0] == 200 and 'Restablecer contraseña' in ficha[1] and f'/pacientes/{pac_id}/restablecer' in ficha[1] and 'name="verificado"' in ficha[1]
      and 'data-password-rules' in ficha[1], 'La ficha del paciente muestra el formulario con la casilla de verificación y la lista de requisitos')
hash_before, flag_before = state(pac_uid)
temp = strong()


def reset_patient(browser, **overrides):
    data = {'csrf': browser.token(f'/pacientes/{pac_id}'), 'password': temp, 'password_confirmation': temp, 'verificado': '1'}
    data.update(overrides)
    data = {k: v for k, v in data.items() if v is not None}
    return browser.request(f'/pacientes/{pac_id}/restablecer', data)


no_check = reset_patient(recep, verificado=None)
check(no_check[0] == 422 and 'Confirma que verificaste la identidad' in no_check[1] and state(pac_uid) == (hash_before, False),
      'Recepción no restablece sin marcar la verificación de identidad')
weak = reset_patient(recep, password='sinrequisitos', password_confirmation='sinrequisitos')
check(weak[0] == 422 and 'le falta' in weak[1] and state(pac_uid) == (hash_before, False), 'La contraseña temporal débil se rechaza')
mismatch = reset_patient(recep, password_confirmation=temp + 'x')
check(mismatch[0] == 422 and state(pac_uid) == (hash_before, False), 'La temporal debe coincidir con su confirmación')
check(paciente.request('/panel')[0] == 200, 'Las sesiones del paciente siguen válidas mientras no se restablece')
done = reset_patient(recep)
hash_after, flag_after = state(pac_uid)
check(done[0] == 303 and location(done).endswith(f'/pacientes/{pac_id}') and flag_after and hash_after != hash_before,
      'Recepción restablece la contraseña de un paciente con la verificación marcada')
check(hash_after.startswith('$2y$') and temp not in hash_after, 'La temporal se guarda con password_hash')
check(paciente.request('/panel')[0] == 303, 'El restablecimiento invalida las sesiones abiertas de la cuenta')
check(Browser().login(pac_name, pac_pw)[0][0] == 422, 'La contraseña anterior deja de funcionar')
audit = sql("SELECT actor_id,paciente_id,motivo FROM evento_administrativo WHERE accion='restablecer_password' AND paciente_id=?", [pac_id])
check(len(audit) == 1 and int(audit[0]['actor_id']) == rec_uid, 'La auditoría registra al responsable del restablecimiento')
check(temp not in audit[0]['motivo'] and hash_after not in audit[0]['motivo']
      and not sql('SELECT 1 FROM evento_administrativo WHERE motivo LIKE ? OR motivo LIKE ?', ['%' + temp + '%', '%' + hash_after + '%']),
      'La auditoría no guarda la contraseña ni su hash')

# --- Cambio obligatorio de la contraseña temporal
forced = Browser()
check(forced.login(pac_name, temp)[0][0] == 303, 'Con la contraseña temporal se puede iniciar sesión')
blocked = [p for p in ('/panel', '/citas', '/mi-perfil', '/contacto-recepcion', '/ubicacion', '/historial', '/olvide-password', '/registro')
           if not (forced.request(p)[0] == 303 and location(forced.request(p)).endswith('/cambiar-password'))]
check(not blocked, 'Mientras la contraseña sea temporal, las demás rutas redirigen a Cambiar contraseña')
page = forced.request('/cambiar-password')
check(page[0] == 200 and 'data-password-rules' in page[1] and 'id="main-navigation"' not in page[1] and 'action="/salir"' in page[1],
      'Cambiar contraseña muestra el formulario con la lista en vivo, sin menú y con cerrar sesión')
token = forced.token('/cambiar-password')
check(forced.request('/mi-perfil', {'csrf': token})[0] == 303 and location(forced.request('/mi-perfil', {'csrf': token})).endswith('/cambiar-password'),
      'Las acciones POST de otras rutas también quedan bloqueadas')
same = forced.request('/cambiar-password', {'csrf': token, 'password': temp, 'password_confirmation': temp})
check(same[0] == 422 and 'igual a la temporal' in same[1] and state(pac_uid)[1], 'La contraseña nueva no puede ser igual a la temporal')
weak_new = forced.request('/cambiar-password', {'csrf': token, 'password': 'sinrequisitos', 'password_confirmation': 'sinrequisitos'})
check(weak_new[0] == 422 and 'le falta' in weak_new[1] and state(pac_uid)[1], 'La contraseña nueva debe cumplir la política')
diff = forced.request('/cambiar-password', {'csrf': token, 'password': strong(), 'password_confirmation': strong()})
check(diff[0] == 422 and state(pac_uid)[1], 'La contraseña nueva debe coincidir con su confirmación')
new_pw = strong()
session_before = cookie(forced)
changed = forced.request('/cambiar-password', {'csrf': token, 'password': new_pw, 'password_confirmation': new_pw})
check(changed[0] == 303 and location(changed).endswith('/panel') and state(pac_uid)[1] is False, 'Al guardar se quita la obligación de cambiar la contraseña')
check(cookie(forced) != session_before and forced.request('/panel')[0] == 200, 'El cambio regenera la sesión y habilita el resto del sistema')
check(Browser().login(pac_name, temp)[0][0] == 422 and Browser().login(pac_name, new_pw)[0][0] == 303, 'La temporal deja de servir y la nueva permite iniciar sesión')
check(forced.request('/cambiar-password')[0] == 303 and location(forced.request('/cambiar-password')).endswith('/panel'),
      'Sin contraseña temporal pendiente, Cambiar contraseña redirige al panel')
check(Browser().request('/cambiar-password')[0] == 303 and location(Browser().request('/cambiar-password')).endswith('/ingresar'), 'Cambiar contraseña exige iniciar sesión')
change_audit = sql("SELECT motivo FROM evento_administrativo WHERE accion='cambiar_password_temporal' AND actor_id=?", [pac_uid])
check(len(change_audit) == 1 and new_pw not in change_audit[0]['motivo'] and temp not in change_audit[0]['motivo'], 'El cambio queda auditado sin guardar contraseñas')

# --- Cerrar sesión sigue disponible con una contraseña temporal
temp2 = strong()
check(admin.request(f'/usuarios/{pac_uid}/restablecer', {'csrf': admin.token(f'/usuarios/{pac_uid}/editar'), 'password': temp2, 'password_confirmation': temp2})[0] == 303,
      'El administrador vuelve a restablecer la contraseña del paciente')
leaving = Browser()
leaving.login(pac_name, temp2)
logout = leaving.request('/salir', {'csrf': leaving.token('/cambiar-password')})
check(logout[0] == 303 and leaving.request('/cambiar-password')[0] == 303 and location(leaving.request('/cambiar-password')).endswith('/ingresar'),
      'Con contraseña temporal sí se puede cerrar sesión')

# --- Recepción nunca restablece cuentas de personal
for label, uid, name in (('médico', med_uid, med_name), ('recepcionista', rec2_uid, rec2_name), ('administrador', adm2_uid, adm2_name)):
    before = state(uid)
    denied = recep.request(f'/usuarios/{uid}/restablecer', {'csrf': recep.token('/panel'), 'password': strong(), 'password_confirmation': 'x', 'verificado': '1'})
    check(denied[0] == 403 and state(uid) == before, 'Recepción recibe 403 al restablecer una cuenta de ' + label)
# Ficha de paciente enlazada por error a una cuenta de personal: recepción tampoco puede restablecerla.
linked = int(sql("INSERT INTO paciente(usuario_id,ci,nombres,apellidos,telefono) VALUES(?,?,?,?,?) RETURNING id",
                 [med_uid, prefix + '-enlace', 'Enlace', 'Prueba', '00000000'])[0]['id'])
before = state(med_uid)
temp_linked = strong()
denied = recep.request(f'/pacientes/{linked}/restablecer', {'csrf': recep.token(f'/pacientes/{linked}'), 'password': temp_linked,
                                                         'password_confirmation': temp_linked, 'verificado': '1'})
check(denied[0] == 403 and state(med_uid) == before, 'Recepción recibe 403 si la ficha está enlazada a una cuenta de personal')
for browser, label in ((medico, 'médico'), (recep, 'recepción')):
    check(browser.request(f'/usuarios/{pac2_uid}/restablecer', {'csrf': browser.token('/panel'), 'password': strong(), 'password_confirmation': 'x'})[0] == 403
          and state(pac2_uid)[1] is False, 'Un ' + label + ' no restablece contraseñas por la ruta de administración')
sql('DELETE FROM paciente WHERE id=?', [linked])

# --- El administrador restablece cualquier cuenta
for label, uid, name in (('médico', med_uid, med_name), ('recepcionista', rec2_uid, rec2_name), ('administrador', adm2_uid, adm2_name), ('paciente', pac2_uid, pac2_name)):
    edit_page = admin.request(f'/usuarios/{uid}/editar')
    check(edit_page[0] == 200 and f'/usuarios/{uid}/restablecer' in edit_page[1] and 'data-password-rules' in edit_page[1],
          'Usuarios → Editar ofrece restablecer la contraseña de ' + label)
    weak_admin = admin.request(f'/usuarios/{uid}/restablecer', {'csrf': admin.token(f'/usuarios/{uid}/editar'), 'password': 'debil', 'password_confirmation': 'debil'})
    check(weak_admin[0] == 422 and 'le falta' in weak_admin[1] and state(uid)[1] is False, 'El administrador no puede guardar una temporal débil (' + label + ')')
    temp_admin = strong()
    ok = admin.request(f'/usuarios/{uid}/restablecer', {'csrf': admin.token(f'/usuarios/{uid}/editar'), 'password': temp_admin, 'password_confirmation': temp_admin})
    check(ok[0] == 303 and state(uid)[1] is True, 'El administrador restablece la cuenta de ' + label)
    probe = Browser()
    check(probe.login(name, temp_admin)[0][0] == 303 and location(probe.request('/panel')).endswith('/cambiar-password'),
          'La cuenta de ' + label + ' debe cambiar la contraseña temporal al ingresar')
check(medico.request('/panel')[0] == 303 and recep2.request('/panel')[0] == 303 and admin2.request('/panel')[0] == 303,
      'El restablecimiento invalidó las sesiones anteriores de médico, recepción y administrador')
actors = {int(x['actor_id']) for x in sql("SELECT actor_id FROM evento_administrativo WHERE accion='restablecer_password'")}
check({rec_uid} <= actors and len(sql("SELECT 1 FROM evento_administrativo WHERE accion='restablecer_password' AND motivo LIKE '%contraseña de la cuenta%'")) >= 5,
      'La auditoría conserva todos los restablecimientos con su responsable')

(ROOT / 'tests/resultados_validacion_restablecer.json').write_text(json.dumps(
    {'regresion': baseline, 'nuevas': len(results) - baseline, 'passed': len(results), 'results': results}, ensure_ascii=False, indent=2), encoding='utf-8')
print(f'{len(results)-baseline} nuevas comprobaciones; {len(results)} comprobaciones HTTP y servicios correctas en total.')
