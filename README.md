# Clínica Vida Salud · Gestión de citas médicas

Proyecto en PHP, PostgreSQL, HTML, CSS y JavaScript. Mantiene los cuatro roles de la documentación: Administrador, Recepcionista, Médico y Paciente. No requiere Composer ni Node para funcionar.

## Qué ya funciona

- Registro público de paciente y cuenta en una sola transacción. El formulario nunca permite asignarse un rol administrativo.
- Inicio y cierre de sesión, caducidad por inactividad, cambio del identificador de sesión y protección de formularios. Cambiar la contraseña exige volver a ingresar en las sesiones anteriores de esa cuenta.
- Panel personal para los cuatro roles. Un paciente inactivo o con deuda puede iniciar sesión; esas condiciones se mantienen separadas de un usuario deshabilitado.
- Administración: crear, consultar, editar y deshabilitar usuarios; asignar uno de los cuatro roles. No se elimina el historial. Se protege al último administrador habilitado.
- Consulta y edición de la matriz de roles y permisos (CU05), guardado transaccional, auditoría y rechazo de ediciones antiguas. Conserva los accesos básicos de administración y las operaciones propias de cada rol. La habilitación del registro público CU01 también se aplica al formulario y a su envío.
- Gestión administrativa de pacientes (CU06): buscar por CI, nombres o apellidos; filtrar activos e inactivos; registrar, consultar, editar y desactivar con motivo. Conserva las citas y multas, y distingue la ficha de la cuenta de acceso.
- Mi perfil (CU07): el paciente consulta su propia identidad y actualiza teléfono, correo de contacto, dirección, emergencia e información médica básica. Comprueba propiedad en el servidor y guarda las tres tablas de forma atómica. Mantiene protegida la identidad y rechaza formularios antiguos.
- Médicos (CU08): registro profesional único, búsqueda, contacto, estado, asignación de varias especialidades y vinculación opcional con una cuenta de Médico. Conserva las asociaciones retiradas y las citas existentes; muestra las citas futuras que requieren revisión.
- Especialidades (CU09): catálogo con búsqueda, descripción, estado y nombres únicos por comparación sin mayúsculas ni espacios sobrantes. Desactivar conserva médicos y citas; el detalle muestra los turnos afectados.
- Horarios (CU10): tramos semanales por médico activo, alineados a medias horas; búsqueda por médico, día y estado; alta, edición y cambio de estado. Rechaza superposiciones y cambios que dejen citas vigentes fuera de horario, mostrando las reservas afectadas.
- Disponibilidad (CU11): especialidad, médico y fecha, bloques de 30 minutos, exclusión de horas pasadas, reservas de cualquier especialidad del médico y ausencias con solapamiento parcial.
- Reservas ordinarias (CU12-CU15): programar, confirmar, reprogramar y cancelar con propiedad del paciente, permisos, motivo, versión del formulario y trazabilidad. Al guardar se revalida el turno. Se encolan avisos en la base; el envío externo de Telegram queda pendiente.
- Instalación de las 23 tablas del modelo y sus reglas SQL mediante migraciones. `migracion` es una tabla técnica adicional para registrar qué scripts se aplicaron.

Si ya instalaste el paquete anterior, sigue ACTUALIZAR.md para actualizar el código conservando tu configuración y tus cuentas. TRAZABILIDAD.md relaciona el código con el Word aportado.

Esta etapa implementa CU01-CU15: acceso, usuarios, permisos, pacientes, perfil, médicos, especialidades, horarios, disponibilidad y gestión de reservas ordinarias. Atención, inasistencia, agenda médica, historial especializado, urgencias, consulta de multas, pago, reportes, reactivación, inactividad automática, contadores y Telegram todavía requieren sus módulos completos. La solicitud urgente será gestionada directamente por recepción; no se agrega un rol «Personal médico».

## 1. Revisa tus programas en Windows

Abre una terminal de PowerShell y ejecuta:

```powershell
php -v
psql --version
```

Si un comando no se reconoce, puede faltar el programa o puede estar instalado sin estar agregado a PATH. Para XAMPP prueba también:

```powershell
C:\xampp\php\php.exe -v
```

PostgreSQL suele encontrarse en `C:\Program Files\PostgreSQL\VERSION\bin`. La aplicación necesita PostgreSQL; la base MariaDB que puede acompañar a XAMPP no sustituye esta conexión.

Puedes consultar sin modificar el equipo el archivo `scripts/revisar_equipo.ps1`, o copiar sus comandos a la terminal. No ejecutes una instalación adicional antes de revisar lo que ya tienes.

Requisitos: PHP 8.2 o superior; extensiones `pdo_pgsql` y `mbstring`; PostgreSQL 16 o superior; navegador. En PHP `php --ini` indica qué archivo `php.ini` estás usando. Habilita `extension=pdo_pgsql` y `extension=mbstring` si faltan y reinicia el servidor de desarrollo. `php -m` permite comprobarlas. Si usas el ejecutable de XAMPP, reemplaza `php` por su ruta completa en los comandos siguientes.

Fuentes oficiales de instalación: https://www.php.net/downloads.php y https://www.postgresql.org/download/windows/.

## 2. Abre el proyecto

Descomprime el ZIP. En VS Code selecciona **Archivo → Abrir carpeta** y abre `clinica-vida-salud`. Abre **Terminal → Nueva terminal**. Los comandos deben ejecutarse desde esa carpeta, donde está `bootstrap.php`.

## 3. Crea una base vacía

En pgAdmin, con tu servidor PostgreSQL conectado: clic derecho en **Databases → Create → Database**. Nombre: `clinica_vida_salud`. No necesitas ejecutar los scripts a mano: el instalador los aplicará en orden.

Si prefieres usar la terminal:

```powershell
psql -U postgres -c "CREATE DATABASE clinica_vida_salud;"
```

La contraseña que solicita PostgreSQL es la que elegiste al instalarlo. Si ya creaste tablas del proyecto en otra base, conserva esa base y usa una vacía para este primer paquete. El instalador no borra ni reemplaza datos existentes.

## 4. Configura la conexión

```powershell
Copy-Item .env.example .env
```

Abre `.env` y completa `DB_USER` y `DB_PASSWORD` con tus datos locales. Deja el host `127.0.0.1`, puerto `5432` y nombre `clinica_vida_salud` si usaste los anteriores. No compartas `.env` ni lo subas a GitHub.

```powershell
php bin/diagnostico.php
```

Antes de instalar, el diagnóstico puede indicar que faltan las tablas. Eso se resuelve con el paso siguiente. Si falla la conexión, primero revisa PostgreSQL y `.env`.

## 5. Instala las tablas y crea el administrador

```powershell
php bin/instalar.php
php bin/crear_admin.php
```

El segundo comando solicita un usuario, correo y contraseña elegidos por ti. La contraseña debe tener al menos 12 caracteres y un máximo de 72 bytes. No hay cuentas ni contraseñas predeterminadas. Para crear las demás cuentas, ingresa en la web como administrador y abre **Usuarios**. Al seleccionar el rol Paciente, también se solicitan CI, nombres, apellidos y teléfono.

Ejecutar de nuevo `instalar.php` comprueba las migraciones ya aplicadas. Si hay una migración aplicada que fue modificada, el instalador se detiene para evitar cambios silenciosos.

## 6. Abre la aplicación

```powershell
php -S 127.0.0.1:8000 -t public router.php
```

Abre http://127.0.0.1:8000. Mantén la terminal abierta. Para detener el servidor pulsa **Ctrl+C**. El servidor integrado se usa para desarrollo local; la publicación posterior se hará con un servidor web y HTTPS.

### Recorrido para comprobarla

1. Ingresa con el administrador que creaste.
2. Abre Usuarios y crea una cuenta de recepción y otra de médico.
3. Cierra sesión. Usa «Crea tu cuenta de paciente» para registrar un paciente.
4. Ingresa con el paciente: verá su panel y no podrá abrir `/usuarios`.
5. Ingresa como administrador y edita una cuenta. Deshabilitarla impedirá su siguiente acceso y revocará el acceso de una sesión abierta en su próxima petición.

## Qué significa cada carpeta

| Carpeta / archivo | Función |
|---|---|
| `public/` | Archivos que recibe el navegador: entrada PHP, CSS, JS y marca. Es la raíz pública. |
| `src/` | Controlador, servicios, validaciones, sesiones y conexión. src/Repositories contiene las consultas PDO. |
| `views/` | Pantallas HTML con sus datos. |
| `database/migrations/` | Tablas, restricciones, procedimientos y catálogos iniciales. |
| `bin/` | Comandos para diagnóstico e instalación desde terminal. |
| `config/casos.php` | Nombres del catálogo de casos de uso de la documentación. |
| `storage/` | Contadores de intentos fallidos de acceso. Debe poder escribir el proceso PHP. |
| `tests/` | Comprobaciones de validación y del flujo HTTP. |
| `.env.example` | Ejemplo sin credenciales reales. Lo copias a `.env`. |

## Relación con los casos de uso

- CU01: registro de paciente.
- CU02 y CU03: inicio y cierre de sesión.
- CU04: creación, consulta, edición y deshabilitación de cuentas.
- CU05: consulta y edición de la matriz con validación, auditoría y protección de administración; asignación del rol al usuario desde CU04.
- CU06: gestión de las fichas de pacientes por administrador y recepcionista, con permisos, CI único, auditoría y conservación de registros relacionados. La reactivación corresponde a CU25 y sigue pendiente.
- CU07: actualización del perfil propio del paciente, contacto de emergencia e información médica básica, conservando su identidad, su cuenta y los registros relacionados.
- CU08: crear, consultar, buscar, editar, activar y desactivar médicos; asignar varias especialidades y conservar las relaciones históricas.
- CU09: catálogo de especialidades, nombres normalizados únicos, búsqueda, edición y cambio de estado sin eliminación física.
- CU10: administración de horarios semanales por médico, alineación de 30 minutos, exclusión de tramos y protección de citas vigentes antes de modificar la disponibilidad.
- CU11: consulta de turnos futuros disponibles y selección hacia Programar cita.
- CU12: reserva propia del paciente o verificada por recepción, estado Programada, historial y aviso en una transacción.
- CU13: ratificación de asistencia, manteniendo el turno ocupado.
- CU14: nuevo turno validado, conservación de código y datos anteriores, estado Programada y aceptación registrada para cambiar de médico por recepción.
- CU15: cancelación, liberación del turno, origen y aviso verificados, advertencia o multa según la política SQL existente.

## Decisiones que se conservan

Los 24 h de anticipación para cancelar y Bs 20 por multa se cargan como valores iniciales propuestos y configurables, según el documento. No se ejecuta el envío de Telegram desde esta base inicial ni se guardan tokens. El contador de cita se implementará desde el horario guardado, sin que el médico tenga que iniciarlo.

## Comprobaciones para desarrollo

```powershell
php tests/unit.php
```

`tests/http_smoke.py` requiere Python 3 y una aplicación ejecutándose contra una **base de pruebas vacía cuyo nombre termine en `_test`**. Crea cuentas ficticias y verifica acceso, registro y administración. No lo ejecutes sobre una base con información real. Consulta las instrucciones al inicio del archivo.

## Fuentes técnicas usadas

- Contraseñas: https://www.php.net/manual/en/function.password-hash.php
- Consultas parametrizadas: https://www.php.net/manual/en/pdo.prepared-statements.php
- Sesiones: https://www.php.net/manual/en/session.security.ini.php
- Servidor local: https://www.php.net/manual/en/features.commandline.webserver.php

Al publicar, configura la raíz del servidor en `public/`, usa HTTPS y `SESSION_SECURE=true`, protege `.env` fuera de esa raíz y emplea un usuario de base con los permisos necesarios. La configuración del alojamiento y la prueba completa de los módulos pertenecen a los avances siguientes.

## Gestión de pacientes

Entra como administrador o recepcionista y abre Pacientes. El listado incluye los pacientes registrados desde las cuentas anteriores; no se necesita importarlos. Un paciente creado desde este módulo no requiere una cuenta web para disponer de ficha administrativa. La creación y asignación de cuentas permanece en Usuarios.

La ficha contiene CI, nombres, apellidos, nacimiento, sexo, teléfono, correo de contacto y dirección. El correo de contacto puede ser diferente del de acceso; editarlo no cambia la contraseña ni el rol de la cuenta. Las fichas se identifican visualmente mediante PA- seguido de su identificador numérico.

Desde Ver ficha se puede desactivar al paciente indicando un motivo. Esto no deshabilita al usuario, no elimina multas y no cancela citas futuras. Un paciente inactivo puede consultar su cuenta. Editar sus datos no equivale a reactivarlo. La reactivación administrativa y la desactivación automática anual siguen en sus avances correspondientes.

## Mi perfil

Entra con una cuenta de paciente y abre Mi perfil. El código, CI, nombres, apellidos, nacimiento y sexo son de consulta; las correcciones de identidad se realizan en recepción. Puedes editar teléfono, correo de contacto y dirección. Este correo no cambia el que utilizas para iniciar sesión.

El contacto de emergencia es opcional: completa nombre, parentesco o relación y teléfono, o deja los tres vacíos. Solo hay un contacto por paciente. Para retirarlo, vacía los tres campos y guarda.

La información médica básica comprende tipo de sangre, alergias, enfermedades crónicas, medicamentos y antecedentes relevantes. No es obligatorio completar lo que desconozcas. Los campos de texto admiten hasta 2000 caracteres y varias líneas. Puedes seleccionar No informado para el tipo de sangre.

Mi perfil pertenece a la cuenta autenticada y requiere CU07 habilitado. Los identificadores enviados por el navegador no permiten cambiar de propietario. Administración y recepción mantienen su módulo de datos administrativos CU06; esta pantalla personal se utiliza como paciente. La auditoría guarda los nombres de los campos modificados, sin copiar información médica ni contactos.

Un paciente inactivo o con multa pendiente puede actualizar su perfil. Esto no lo reactiva, no paga multas ni modifica citas. Si el perfil o su ficha cambiaron desde que abriste el formulario, se muestran los datos actuales para que los revises antes de volver a guardar.

## Médicos y especialidades

Entra como administrador. Primero abre Especialidades y registra nombre, descripción opcional y estado. Por ejemplo, Medicina General y MEDICINA   GENERAL son equivalentes. Los nombres de registros inactivos también se conservan únicos. Puedes editar su estado para volver a activarlos.

Después abre Médicos y completa registro profesional, nombres y apellidos. El teléfono y correo son opcionales. Selecciona una o varias especialidades activas; el alta y un médico activo necesitan al menos una. Un médico previamente registrado puede quedar inactivo sin especialidades disponibles, conservando sus asociaciones históricas.

La cuenta de acceso es opcional. El selector ofrece cuentas habilitadas de rol Médico que todavía no estén vinculadas a otro profesional y conserva la vinculación actual. Las cuentas se crean en Usuarios. Vincular una no cambia su contraseña, correo de acceso ni rol; el estado del profesional es independiente de la habilitación de su cuenta.

Las asociaciones retiradas se marcan como históricas en medico_especialidad. No se borran. Una especialidad temporalmente inactiva conserva su asociación al editar el contacto del médico; vuelve a estar disponible al activarla. Al desactivar al médico, a la especialidad o a una asociación, las citas programadas o confirmadas permanecen registradas y el detalle muestra cuáles necesitan revisión con recepción. Este listado no sustituye la agenda completa ni ejecuta cancelaciones o reprogramaciones: esos casos se implementarán en sus módulos.

CU08 y CU09 requieren sus permisos y el rol Administrador. Retirar un permiso bloquea la siguiente petición, incluidos formularios abiertos. Las ediciones antiguas y los datos inválidos se rechazan. Se conservan los cuatro archivos de migración existentes.

## Horarios de atención

Entra como administrador y abre Horarios de atención. Primero debe existir un médico activo. Cada registro define un día de la semana, hora inicial, hora final y estado. Los tramos se repiten semanalmente y no se dividen por especialidad: pertenecen al médico.

Las horas terminan en :00 o :30 y el final debe ser posterior al inicio dentro del mismo día. Puedes crear varios tramos contiguos o separados. El mismo médico no puede tener tramos activos superpuestos para un día; un horario inactivo puede conservarse, pero activarlo vuelve a comprobar los conflictos.

Los cambios se aplican en una transacción y comprueban las citas programadas o confirmadas que aún no finalizaron. Si una reserva que estaba cubierta queda fuera de los horarios activos, el cambio se revierte y se muestran su código, fecha, paciente, médico y especialidad. Se considera el intervalo completo y la zona America/La_Paz. El pasado, las citas y sus estados permanecen sin modificación.

Se puede cambiar el médico de un tramo solo si se conserva la cobertura de las citas del médico anterior y no se crean conflictos en el destino. Un formulario antiguo se rechaza si cambió el horario o el estado del médico. No se eliminan registros por la interfaz. CU10 requiere Administrador y el permiso correspondiente.

Los turnos libres por fecha se consultan en Disponibilidad. El horario semanal es la base para esos turnos.

## Recorrido de citas

En Consultar disponibilidad selecciona especialidad, médico y una fecha futura. Un cambio de especialidad recarga sus médicos; el botón Buscar turnos permite consultar la fecha. La lista no reserva todavía un horario. Elegir turno abre Programar cita.

El paciente reserva únicamente para su propia ficha. Recepción o administración pueden buscar un paciente activo por CI o nombre. La ficha debe estar activa y sin multas pendientes para una nueva reserva ordinaria. El médico y su especialidad deben estar activos y asociados. El servidor vuelve a comprobar disponibilidad antes de guardar y fija duración de 30 minutos, autor y estado Programada.

Mis citas o Citas permiten localizar las reservas para operar. El paciente ve solo las propias; recepción y administración requieren permisos. Este listado operativo y el registro de cambios por cita no sustituyen todavía los módulos completos de agenda e historial CU18-CU19. La cuenta de Médico utilizará su agenda en su propio módulo.

Confirmar asistencia transforma Programada en Confirmada si el turno aún no comenzó. Reprogramar valida otro turno y devuelve la cita a Programada. Se mantienen código, paciente, autor y fecha original; el historial conserva médico y horario anteriores. El paciente mantiene el mismo médico y especialidad; recepción puede cambiar de médico dentro de la especialidad registrando la aceptación del paciente. Los motivos y el origen quedan trazados.

El plazo configurado corresponde a cancelaciones. En una reprogramación solicitada dentro de ese plazo se muestra una advertencia informativa y se registra revisión administrativa, conservando el turno anterior en el historial. No se incorpora una multa nueva por reprogramar: RN15 define la incidencia para cancelación tardía o inasistencia. Esta distinción debe mantenerse al revisar las políticas del proyecto.

Cancelar conserva la cita y libera la hora. Las cancelaciones tardías válidas usan las reglas SQL: primera incidencia, advertencia; desde la segunda, multa pendiente que bloquea nuevas reservas ordinarias. No bloquea el inicio de sesión, la consulta ni la confirmación de una reserva ya existente. Solo recepción o administración validan causas de la clínica, justificaciones y avisos previos.

Las reservas creadas con menos anticipación que el plazo se registran para revisión antes de sancionar, según el tratamiento propuesto del documento. Ausencias médicas registradas y causas de la clínica no generan sanción al paciente. Los importes y plazos proceden de los parámetros existentes; no se añaden tarifas externas.

Los cambios de estado y horario generan historial y avisos pendientes en PostgreSQL. La cola de avisos se conserva separada del envío externo. La vinculación y el procesamiento de Telegram siguen pendientes de CU27-CU30; este paquete no envía mensajes reales. El pago presencial de multas corresponde a CU23 y todavía no tiene su pantalla.

Si Ana Ejemplo está inactiva, conservará perfil y consulta, pero no podrá hacer una nueva reserva. CU25 sigue pendiente. Para probar reservas utiliza una nueva cuenta ficticia de paciente activo, sin modificar manualmente el estado de Ana en la base.
