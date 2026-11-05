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
- Reservas ordinarias (CU12-CU15): programar, confirmar, reprogramar y cancelar con propiedad del paciente, permisos, motivo, versión del formulario y trazabilidad. Al guardar se revalida el turno. Se encolan avisos en la base y el proceso de tareas los envía a los chats autorizados.
- Atención e inasistencia (CU16-CU17): confirmación manual, turno iniciado o finalizado según la operación, médico limitado a sus propias citas, estado terminal protegido y registro transaccional de cambios, advertencias y multas.
- Agenda médica (CU18): fecha, profesional y estado, citas asignadas y ausencias registradas; cada médico consulta únicamente su agenda.
- Historial de citas (CU19): acceso propio del paciente o autorizado de recepción, fecha de registro junto al turno, filtros, pacientes inactivos y paginación de citas y cambios.
- Reportes administrativos (CU20): actividad y totales por período, médico, especialidad y estado; altas por fecha de registro de pacientes, incluso sin cuenta ni citas, con totales de activos e inactivos; pagos separados por fecha de pago, sin duplicar importes por los cambios históricos.
- Ubicación de la clínica: dirección, ciudad y mapa configurables; los pacientes inactivos conservan el acceso y se muestra una alternativa de recepción cuando la dirección no está publicada.
- Solicitudes urgentes (CU21-CU22): contacto, solicitud pendiente separada de la cita, seguimiento, cierre o asignación con aceptación, disponibilidad revalidada, excepciones autorizadas y trazabilidad.
- Multas y pagos (CU23-CU24): consulta propia o administrativa, importes decimales exactos, comprobante único, recepción presencial verificada, saldo calculado desde las deudas y conservación de advertencias.
- Reactivación (CU25): búsqueda de inactivos, verificación de identidad y contacto, motivo, versión, fecha y auditoría, conservando las deudas y la cuenta.
- Instalación de las 23 tablas del modelo y sus reglas SQL mediante migraciones. `migracion` registra los scripts aplicados. `historial_solicitud_urgente` conserva la auditoría técnica de las gestiones urgentes.

Si ya instalaste el paquete anterior, sigue ACTUALIZAR.md para actualizar el código conservando tu configuración y tus cuentas. TRAZABILIDAD.md relaciona el código con el Word aportado.

Esta etapa implementa CU01-CU30. CU26–CU30 incluyen ausencias, vinculación de Telegram, avisos con reintento autorizado, contadores y contacto de recepción. TELEGRAM.md explica la configuración del bot y el proceso de recordatorios e inactividad. La estudiante confirmó la vinculación y entrega real de avisos en local; falta comprobar la versión final en Render. La solicitud urgente es gestionada directamente por recepción; no se agrega un rol «Personal médico».

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
- CU06: gestión de las fichas de pacientes por administrador y recepcionista, con permisos, CI único, auditoría y conservación de registros relacionados. La reactivación se realiza desde CU25, con verificación y motivo.
- CU07: actualización del perfil propio del paciente, contacto de emergencia e información médica básica, conservando su identidad, su cuenta y los registros relacionados.
- CU08: crear, consultar, buscar, editar, activar y desactivar médicos; asignar varias especialidades y conservar las relaciones históricas.
- CU09: catálogo de especialidades, nombres normalizados únicos, búsqueda, edición y cambio de estado sin eliminación física.
- CU10: administración de horarios semanales por médico, alineación de 30 minutos, exclusión de tramos y protección de citas vigentes antes de modificar la disponibilidad.
- CU11: consulta de turnos futuros disponibles y selección hacia Programar cita.
- CU12: reserva propia del paciente o verificada por recepción, estado Programada, historial y aviso en una transacción.
- CU13: ratificación de asistencia, manteniendo el turno ocupado.
- CU14: nuevo turno validado, conservación de código y datos anteriores, estado Programada y aceptación registrada para cambiar de médico por recepción.
- CU15: cancelación, liberación del turno, origen y aviso verificados, advertencia o multa según la política SQL existente.
- CU16: registro manual de consulta realizada por médico asignado, recepción o administración; Atendida e historial, sin marcar el futuro ni duplicar eventos.
- CU17: inasistencia verificada por recepción o administración tras finalizar el turno; advertencia, multa o revisión conforme a las reglas existentes.
- CU18: agenda por fecha, médico y estado, con ausencias y acceso limitado al profesional.
- CU19: historial del paciente conservado, incluso inactivo, con filtros y paginación.
- CU20: reportes de actividad y pagos separados con filtros, totales completos y consultas consistentes.
- CU21: solicitud personal urgente, incluso con restricción ordinaria, sin crear cita automáticamente.
- CU22: seguimiento, cierre y asignación por recepción con aceptación del paciente y disponibilidad verificada.
- CU23: registro de pago presencial íntegro, comprobante único, fecha, auditoría y actualización del saldo.
- CU24: advertencias y multas propias o seleccionadas por recepción, con importes y pagos.
- CU25: reactivación verificada, nueva fecha de actividad y conservación de las deudas y registros.

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

Desde Ver ficha se puede desactivar al paciente indicando un motivo. Esto no deshabilita al usuario, no elimina multas y no cancela citas futuras. Un paciente inactivo puede consultar su cuenta. Editar sus datos no equivale a reactivarlo. La reactivación administrativa se gestiona en CU25. TaskService realiza la revisión diaria de inactividad; su ejecución CLI local se comprobó sin servidor web. La continuidad en el alojamiento sigue pendiente.

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

Mis citas o Citas permiten localizar las reservas para operar. El paciente ve solo las propias; recepción y administración requieren permisos. La agenda CU18 y el historial CU19 disponen de sus propios filtros y paginación. La cuenta de Médico accede a su agenda CU18 y al detalle de sus citas; CU16 permite registrar atención realizada.

Confirmar asistencia transforma Programada en Confirmada si el turno aún no comenzó. Reprogramar valida otro turno y devuelve la cita a Programada. Se mantienen código, paciente, autor y fecha original; el historial conserva médico y horario anteriores. El paciente mantiene el mismo médico y especialidad; recepción puede cambiar de médico dentro de la especialidad registrando la aceptación del paciente. Los motivos y el origen quedan trazados.

El plazo configurado corresponde a cancelaciones. En una reprogramación solicitada dentro de ese plazo se muestra una advertencia informativa y se registra revisión administrativa, conservando el turno anterior en el historial. No se incorpora una multa nueva por reprogramar: RN15 define la incidencia para cancelación tardía o inasistencia. Esta distinción debe mantenerse al revisar las políticas del proyecto.

Cancelar conserva la cita y libera la hora. Las cancelaciones tardías válidas usan las reglas SQL: primera incidencia, advertencia; desde la segunda, multa pendiente que bloquea nuevas reservas ordinarias. No bloquea el inicio de sesión, la consulta ni la confirmación de una reserva ya existente. Solo recepción o administración validan causas de la clínica, justificaciones y avisos previos.

Las reservas creadas con menos anticipación que el plazo se registran para revisión antes de sancionar, según el tratamiento propuesto del documento. Ausencias médicas registradas y causas de la clínica no generan sanción al paciente. Los importes y plazos proceden de los parámetros existentes; no se añaden tarifas externas.

Los cambios de estado y horario generan historial y avisos pendientes en PostgreSQL. La cola de avisos se conserva separada del envío externo. CU27-CU30 incorporan la vinculación y el procesamiento de Telegram, con recepción real comprobada en local y configuración remota pendiente. Las pruebas automatizadas usan transporte simulado. El pago presencial de multas se registra desde CU23 y se consulta en CU24.

Si Ana Ejemplo está inactiva, conserva perfil, consultas y solicitudes urgentes, pero requiere reactivación CU25 y no tener deudas para nuevas reservas ordinarias. Recepción o administración pueden verificar y reactivar su ficha desde la interfaz.


## Atención, agenda, historial y reportes

En Agenda médica se elige una fecha y un estado. Recepción o administración pueden consultar un profesional o todos; el médico solo consulta sus propias citas y ausencias. Una cuenta sin profesional vinculado recibe una lista vacía y una indicación para administración. Una agenda vacía no significa que existan turnos libres.

CU16 requiere confirmar que la consulta se realizó. La cita debe estar Programada o Confirmada y su turno ya debe haber comenzado. El médico solo puede registrar sus citas. Recepción y administración también pueden realizar el registro si tienen el permiso. El contador no marca una atención por sí solo. Se conservan las causas clínicas previamente validadas por recepción.

CU17 requiere un turno finalizado, motivo y verificación de la ausencia del paciente y de la disponibilidad del médico. Si existe una ausencia médica en el intervalo, se rechaza la imputación al paciente. Las justificaciones verificadas no generan sanción. Se usa la política SQL existente: primera incidencia válida con advertencia, desde la segunda con multa; las reservas realizadas con poca anticipación se derivan a revisión. Los cambios se guardan con historial y avisos en una transacción y un segundo envío no duplica la incidencia.

Historial de citas conserva las citas terminales y las de pacientes inactivos. El paciente solo consulta su ficha. Recepción selecciona un paciente por CI o nombre. Los resultados se muestran en páginas de 50 y el registro de cambios de una cita en páginas de 20.

Los reportes CU20 requieren Administrador o Recepcionista y permiso. Actividad de citas usa la fecha del turno y permite filtrar médico, especialidad y estado. Los totales abarcan todos los resultados, aunque el listado esté paginado. Pagos de multas usa la fecha del pago y suma cada pago una sola vez. Una consulta del reporte usa una instantánea consistente de PostgreSQL. El pago presencial se registra desde CU23; no hay cobro por internet.

La migración 005_atencion.sql añade una excepción delimitada al validador para que el médico asignado pueda cerrar la atención sin borrar una causa clínica ya validada. No permite crear o cambiar esa justificación. Las cuatro migraciones anteriores conservan sus bytes. Render aplica la migración nueva automáticamente al desplegar; en local, ejecuta bin/instalar.php tras copiar la actualización.


## Solicitudes urgentes, pagos y reactivación

El paciente abre Citas urgentes y envía motivo y contacto. La solicitud queda Pendiente de revisión; su envío no ocupa un turno. Se admite aunque la ficha esté inactiva o tenga multas. Un doble envío del mismo formulario devuelve la misma solicitud y evita duplicar la recepción. El paciente solo consulta sus solicitudes.

Recepción o administración con CU22 revisan su cola. Pueden registrar seguimiento y mantener pendiente, cerrar con resolución sin cita o asignar un turno libre. Para asignar se exige aceptación del paciente. Si tiene restricciones ordinarias, se requiere registrar la excepción urgente autorizada con su motivo y responsable. No se elimina la deuda ni se reactiva por ese hecho. La disponibilidad mantiene los bloques, horario, especialidad, médico, ausencias y exclusión de superposiciones. Solicitud, cita Programada, historial y avisos se guardan atómicamente.

En Advertencias y multas, el paciente consulta su propia situación. Recepción selecciona una ficha; la consulta de pagos puede tener acceso independiente CU23. Una advertencia no tiene importe. Cada multa muestra su estado, la cita de origen y, cuando existe, fecha y comprobante del pago. El saldo procede de las multas pendientes, no de un bloqueo manual.

El pago se registra después de recibirlo presencialmente en recepción. Requiere importe íntegro exacto, comprobante único y confirmación de recepción. Se guarda la fecha actual del registro. El importe se normaliza como decimal y se compara sin usar cálculos de coma flotante. La transacción bloquea la multa y evita un segundo abono, incluso con dos conexiones simultáneas. Un fallo posterior revierte pago, estado, auditoría y aviso. Pagar conserva la inactividad y cualquier otra deuda.

Reactivar pacientes permite buscar fichas inactivas, revisar datos y antecedentes, confirmar identidad y contacto y registrar el motivo. No es una nueva cuenta: conserva usuario, contraseña, habilitación, citas e incidencias. Registra reactivado_en como referencia de actividad y mantiene las multas. Un paciente ya activo no renueva su fecha mediante otro envío. CU25 tiene su búsqueda y formulario propios, independientes de CU06.

006_gestiones.sql añade contacto y clave de envío a las solicitudes y un historial protegido de sus gestiones. Conserva las cinco migraciones anteriores. Ajusta únicamente la restricción de actividad para la asignación urgente por personal autorizado con motivo; las reservas ordinarias siguen exigiendo paciente activo y sin deuda.


## Ausencias, Telegram y contadores (CU26–CU30)

Ausencias médicas permite registrar un intervalo y consultar las citas afectadas. Cada cambio requiere acuerdo con el paciente y registra origen Clínica; la ausencia no marca al paciente como inasistente. Reprogramar valida un turno libre y conserva la especialidad, incluso cuando se sustituye al médico. El historial de gestión es protegido y los fallos conservan la cita pendiente.

Avisos por Telegram utiliza autorización del paciente y un código aleatorio temporal de un solo uso, guardado como hash. El webhook exige un secreto propio; el modo polling permite pruebas locales sin túneles. Desactivar o enviar `/stop` revoca los envíos y los códigos pendientes. Avisos del sistema filtra por propietario o ámbito administrativo, muestra fecha, asunto y estado, y permite a recepción autorizar un reintento después de un error.

El contador usa la hora del servidor y el horario guardado, cambia automáticamente de espera a duración del turno y se actualiza tras reprogramaciones. Nunca modifica el estado de una cita. Contactar con recepción abre únicamente el contacto oficial configurado, sin enviar mensajes ni registrar operaciones de citas.

La migración `007_comunicaciones.sql` conserva las seis anteriores y agrega los registros técnicos de comunicaciones y gestión de ausencias. El actualizador conserva `.env`, Git y la base. También actualiza los archivos de despliegue para iniciar tareas al publicar el proyecto completo. Consulta [TELEGRAM.md](TELEGRAM.md) antes de configurar el servicio real.

## Ubicación de la clínica

La opción del menú abre `/ubicacion` para los cuatro roles. Completa en tu `.env` local y, después, en las variables del servidor:

```dotenv
CLINICA_DIRECCION=
CLINICA_CIUDAD="Santa Cruz de la Sierra, Bolivia"
CLINICA_MAPA_URL=
```

Usa la dirección acordada para el caso de la clínica y, opcionalmente, un enlace HTTPS de Google Maps u OpenStreetMap. No se incluye una dirección ficticia sin definir. Si ambos datos están vacíos se informa que todavía no está publicada y se ofrece contactar con recepción. No requiere una migración.

## Menú del celular

Las opciones se agrupan en Menú en pantallas pequeñas. Se conserva la navegación lateral del escritorio y el control de permisos. Consulta [MENU_MOVIL.md](MENU_MOVIL.md) para instalar el parche y comprobarlo en el teléfono.
