# Correspondencia con el documento de Clínica Vida Salud

Referencia principal: 2DO PARCIAL_SALUD.docx, proporcionado el 24 de octubre de 2026. Se conservan sus cuatro roles, 30 casos de uso, 26 reglas de negocio y 23 tablas de dominio. La corrección confirmada por la estudiante establece que la recepcionista gestiona directamente las solicitudes urgentes.

| Caso | Implementación actual | Componentes principales |
|---|---|---|
| CU01 Registrar cuenta de paciente | Registro, validación, CI y correo únicos, rol Paciente obligatorio, operación atómica. Se respeta su habilitación en la matriz. | Controller, UserService, UserRepository, PatientRepository, RoleRepository, vista register |
| CU02 Iniciar sesión | Usuario o correo, hash, sesión renovada, cuenta habilitada y panel por rol. | Controller, Auth, Session, UserRepository, RoleRepository, vista login |
| CU03 Cerrar sesión | Solicitud POST protegida, invalidación de sesión y cookie, retorno al acceso. | Controller, Session, formulario de salida |
| CU04 Gestionar usuarios | Crear, buscar, editar, asignar rol y deshabilitar sin borrar historial; protección del último administrador. | Controller, UserService, repositorios de usuarios, roles, pacientes y auditoría |
| CU05 Asignar roles y permisos | Consulta y edición de matriz, validación de rol y permiso, guardado transaccional, protección de administración, auditoría y rechazo de formularios desactualizados. Asignación del rol en CU04. | Controller, RoleService, RoleRepository, AuditRepository, vista roles, config/permisos.php |
| CU06 Gestionar pacientes | Buscar, filtrar, registrar, consultar y editar datos administrativos; desactivar con motivo. CI único, cuenta vinculada conservada, auditoría y rechazo de ediciones antiguas. No reactiva al editar. | PatientController, PatientService, PatientRepository, AuditRepository y vistas patients, patient_form y patient_detail |
| CU07 Actualizar perfil del paciente | Perfil propio, contacto, emergencia y salud básica; identidad protegida, propiedad derivada de la sesión, guardado atómico, auditoría sin valores médicos y rechazo de formularios antiguos. | ProfileController, ProfileService, ProfileRepository, AuditRepository, Validation y vista profile |
| CU08 Gestionar médicos | Catálogo administrativo, registro profesional único, estado, contacto, varias especialidades, asociaciones históricas, vinculación opcional de cuenta y citas futuras para revisión. | DoctorController, DoctorService, DoctorRepository, SpecialtyRepository y vistas doctor_* y doctors |
| CU09 Gestionar especialidades | Nombre normalizado único, descripción, estado, búsqueda, edición antigua rechazada y conservación de relaciones y citas. | SpecialtyController, SpecialtyService, SpecialtyRepository y vistas specialty_* y specialties |
| CU10 Gestionar horarios de atención | Tramos semanales, médico activo, medias horas, filtros, estados, rechazo de superposiciones, edición antigua y cambios que dejen reservas sin cobertura. Muestra citas afectadas y revierte el cambio. | ScheduleController, ScheduleService, ScheduleRepository, ScheduleConflictException y vistas schedules y schedule_form |
| CU11 Consultar disponibilidad | Turnos por especialidad, médico y fecha; excluye pasado, ocupación entre especialidades y ausencias. La elección conduce a reserva sin ocupar el turno previamente. | AvailabilityController, AvailabilityService, AvailabilityRepository y vista availability |
| CU12 Programar cita | Paciente propio o verificado por recepción, actividad y deuda, nueva validación del turno, código, Programada, historial y aviso atómicos. | AppointmentController, AppointmentService, AppointmentRepository, sp_programar_cita y vista appointment_book |
| CU13 Confirmar asistencia | Programada futura a Confirmada con propiedad, permisos y versión, conservando ocupación y trazabilidad. | AppointmentService::confirm y vista appointment_detail |
| CU14 Reprogramar cita | Conserva identidad y especialidad, valida nuevo turno, vuelve a Programada, mantiene datos anteriores y registra aceptación para cambios de médico por recepción. | AppointmentService::reschedule, AvailabilityService y vista appointment_reschedule |
| CU15 Cancelar cita | Motivo, origen y aviso verificados, liberación del turno, conservación y política de incidencias sin duplicación. | AppointmentService::cancel, sp_cancelar_cita, tr_sancionar_cita y vista appointment_cancel |
| CU16 Registrar atención realizada | Verificación manual, cita no futura, propietario médico, permisos, Atendida e historial atómicos; conserva causas clínicas anteriores. | AppointmentService::attend, AppointmentController::finish, vista appointment_finish, migración 005 |
| CU17 Registrar inasistencia | Turno finalizado, verificación, motivo y origen, ausencia médica comprobada, incidencia única y política de advertencia/multa/revisión. | AppointmentService::noShow, tr_sancionar_cita, vista appointment_finish |
| CU18 Consultar agenda médica | Citas y ausencias por fecha y médico; médico limitado a su propio profesional. | AppointmentReadController, AppointmentReadService::agenda, AppointmentReadRepository, vista appointment_agenda |
| CU19 Consultar historial de citas | Paciente propio o seleccionado por recepción, conservación de inactivos y terminales, filtros y paginación de citas y cambios. | AppointmentReadService::history, AppointmentReadRepository, vista appointment_historial |
| CU20 Generar reportes administrativos | Actividad y totales completos; pagos por separado, filtros, cero resultados e instantánea consistente. | AppointmentReadService::report, AppointmentReadRepository, vista appointment_reportes |
| CU21 Solicitar cita urgente | Motivo y contacto, solicitud pendiente propia incluso con restricción, doble envío controlado y aviso, sin crear turno. | UrgentController, UrgentService::create, UrgentRepository, vistas urgent_form e urgent_index |
| CU22 Gestionar solicitud urgente | Seguimiento, cierre o asignación aceptada; turno revalidado, excepción urgente verificada y guardado atómico de solicitud/cita/gestión/avisos. | UrgentService::manage, AppointmentRepository::createUrgent, vista urgent_detail, historial_solicitud_urgente |
| CU23 Registrar pago de multa | Importe íntegro decimal, comprobante único, fecha, recepción verificada, bloqueo, auditoría y rechazo de doble pago. | FineController, FineService::pay, FineRepository, sp_registrar_pago, vista fine_payment |
| CU24 Consultar advertencias y multas | Propiedad del paciente o selección administrativa, saldo, filtros, advertencias sin importe y pagos trazados. | FineService::overview, FineRepository, vista fines |
| CU25 Reactivar paciente | Ficha inactiva, verificación, motivo, versión y fecha; conserva cuenta, citas, incidencias y deudas. | PatientService::reactivate, PatientController, sp_reactivar_paciente, vistas patient_reactivate y patient_reactivations |
| CU26 a CU30 | Ausencias médicas, Telegram, avisos, contadores y contacto de recepción. | Implementados; componentes y verificaciones en la tabla CU26–CU30 de este documento |

## Arquitectura

Las vistas presentan los formularios; los controladores coordinan solicitudes de acceso, pacientes, perfil, médicos, especialidades y horarios; Auth y los servicios de cada módulo aplican reglas y validaciones; los repositorios de src/Repositories contienen las consultas PDO. Database proporciona conexión, parámetros y transacciones. Esta división concreta las responsabilidades descritas en los apartados 2.3 y 6.1.2.

Los nombres «Vista CU01», «Control Acceso» y «Servicio Acceso» del análisis se concretan en esos componentes, en lugar de crear una tabla por pantalla. Las entidades persistentes conservan las tablas del diseño. migracion es adicional y técnica; registra qué scripts se instalaron. El índice adicional sobre el nombre de usuario impide duplicados por mayúsculas.

## Criterios de permisos

La matriz conserva las operaciones posibles por rol según el catálogo y la población inicial del documento. El administrador puede retirar o restituir las que correspondan; no puede convertir a un paciente en administrador agregándole CU04. El control de acceso comprueba tanto rol como permiso. CU07 comprueba además el propietario vinculado a la sesión; un permiso administrativo no permite suplantar al paciente. Los módulos mantienen los filtros de propietario o médico asignado.

Se mantienen como operaciones necesarias CU02 y CU03 para todos los roles, y CU04 y CU05 para Administración. Como existe un único rol Administrador compartido por sus cuentas, retirarle CU05 quitaría el acceso a todas; por eso se rechaza. La conservación del último administrador habilitado también continúa en CU04.

Los cambios se guardan en una transacción y cada permiso modificado genera un evento con responsable. La versión del formulario permite rechazar una edición antigua en vez de sobrescribir cambios más recientes. Los permisos se vuelven a consultar en la siguiente petición.

## Puntos pendientes de unificar en el Word

- El texto de CU22 y la tabla del modelo de negocio aún mencionan «personal responsable»; deben indicar gestión directa de recepción, como el diagrama revisado.
- El alcance excluye «cobros» de forma general. Debe precisar que sí se registra el pago presencial de multas, sin incorporar facturación médica general ni pagos por internet.
- Modelo de negocio es el apartado 3; algunos títulos y diagramas todavía usan 1.1 y 1.2.
- Los valores de 24 horas para cancelar y Bs 20 por multa siguen siendo propuestas iniciales configurables.

## Límite de la comprobación

Las pruebas del paquete verifican CU01-CU25. Ausencias médicas, Telegram, avisos completos, contadores, contacto con recepción y ejecución periódica de inactividad continúan pendientes. Una tabla instalada o un permiso marcado no acredita un caso completo.

CU06 se implementó según su ficha en el apartado 4.3, con actores Administrador y Recepcionista, búsqueda por CI/nombre/apellido y conservación de citas y multas. Los campos corresponden al alcance 1.4.3 y a la tabla paciente. La estudiante confirmó registro, búsqueda, edición y desactivación de un paciente ficticio, y consulta del registro inactivo desde Recepción en su instalación Windows.

CU07 sigue su ficha del apartado 4.3 y el prototipo Figura 14: Mi perfil, contacto, emergencia y salud básica. El alcance 1.4.3 añade tipo de sangre, enfermedades crónicas y medicamentos a los campos de ejemplo del prototipo. Se utilizan las tablas paciente, perfil_salud y contacto_emergencia existentes; no se agregan migraciones. La identidad permanece protegida y se deriva el propietario desde usuario_id de la sesión. La desactivación o deuda no impide actualizar el perfil. Cada guardado conserva citas históricas, estado y cuenta. Las pruebas incluyen rechazo de perfiles ajenos, edición antigua, campos inválidos, revocación de CU07 y reversión de las tres tablas ante un fallo de auditoría.

La estudiante confirmó CU07 en su instalación Windows: cambios de dirección, emergencia y antecedentes conservados tras recargar, aun con la ficha de Ana Ejemplo inactiva. Reactivar pacientes corresponde a CU25, incorporado en este bloque.

CU08 y CU09 siguen sus fichas del apartado 4.3, los prototipos Figuras 15 y 16 y el alcance 1.4.3. Mantienen el actor Administrador, las tablas medico, especialidad y medico_especialidad y RN02, RN04 y RN11. La cuenta médica opcional utiliza usuario_id del modelo existente; su creación permanece en CU04. El código visual ME- identifica al profesional sin agregar una columna. Una asociación retirada cambia de estado y conserva referencias de citas.

La desactivación muestra las citas futuras para revisión en el detalle correspondiente; no modifica sus estados ni horarios. La lista se deriva de los registros y los estados vigentes, sin añadir entidades al modelo. Los cambios del catálogo usan las mismas claves de bloqueo del médico que la validación SQL de reservas. Las reservas, atenciones, pagos y la cola de avisos se han comprobado con conexiones PostgreSQL nativas donde indica VALIDACION.md.

La estudiante aplicó CU08-CU09 en Windows y confirmó el alta de Medicina General y Pediatría, rechazo de MEDICINA   GENERAL como duplicado, alta de Rosa Ejemplo con registro DEMO-MED-001 y dos especialidades, búsqueda, edición del teléfono, desactivación y activación conservando las asociaciones y el panel Hola, Rosa con su cuenta medico.

CU10 sigue su ficha del apartado 4.3 y el prototipo Figura 17. Conserva el actor Administrador, la precondición de médico activo, la tabla horario y RN05, RN08 y RN13. La disponibilidad semanal pertenece al médico y respeta medias horas. La exclusión SQL evita superposiciones. La aplicación comprueba la cobertura de citas antes y después del cambio, revierte si una reserva vigente deja de estar cubierta y muestra las afectadas. No cambia el estado ni el intervalo de las citas. Se mantiene la zona horaria de la clínica y la conservación del pasado.

La estudiante aplicó CU10 en Windows y confirmó los tramos del lunes de Rosa Ejemplo, 08:00-12:00 y 12:00-14:00, rechazo del tramo superpuesto 11:00-13:00, aceptación de tramos contiguos y desactivación y reactivación del segundo tramo. Ambos quedaron activos.

CU11-CU15 siguen sus fichas del apartado 4.3 y los prototipos Figuras 18-22. Se utiliza el catálogo de permisos existente, incluyendo la gestión administrativa permitida en su matriz. El paciente siempre queda filtrado por la cuenta de sesión; recepción y administración operan sobre fichas verificadas. El Médico no utiliza estos flujos de reservas.

La confirmación registra la ratificación con origen Paciente y conserva en el historial la causa de una reprogramación anterior de la clínica. Esto permite ratificar sin atribuir al paciente permiso para justificar causas clínicas. La reprogramación conserva especialidad; el cambio de médico requiere rol de recepción o administración y aceptación registrada.

El documento fija el plazo para cancelación y menciona comprobar el plazo aplicable al reprogramar sin definir una sanción independiente. La implementación informa y registra revisión de reprogramaciones dentro del plazo, conservando el historial, sin inventar una multa adicional. Las sanciones se generan solo en las situaciones definidas por RN15-RN17 y los tratamientos propuestos existentes.

El alta y los cambios de citas generan avisos pendientes en la misma transacción. El envío real, la vinculación y el contacto por Telegram quedan en CU27-CU30. No se considera probado el envío externo. La agenda CU18 y el historial CU19 se completan en este bloque. La consulta de advertencias y multas CU24 se completa en el bloque CU21-CU25.

Las pruebas de citas vencidas e incidencias usan datos históricos ficticios preparados mediante SQL. Las reservas ordinarias y los cambios de CU12-CU15 sí se ejercitan por HTTP; las citas antiguas de preparación no acreditan los módulos de atención e inasistencia. CU11-CU15 todavía requieren revisión en la instalación Windows de la estudiante.


CU16-CU20 siguen las fichas del apartado 4.3 del documento. La referencia de inactividad se deriva de max(cita.fin) para atenciones realizadas, como ya establece sp_desactivar_inactivos; no se agrega una segunda fecha manual ni se programa todavía esa tarea. La migración 005 conserva los controles y datos anteriores y permite únicamente mantener una causa clínica anterior al registrar una atención.

Los cambios de CU16-CU17 se ejercitan por HTTP con datos ficticios históricos. Se verifican rechazo de atenciones futuras y de inasistencias antes de terminar el turno, propiedad del médico, permisos, CSRF, versiones y reversión ante fallo del aviso. CU18-CU20 se verifican con pacientes inactivos, citas ajenas, listas vacías, más de una página y pagos asociados a citas con varios cambios. La revisión de las pantallas en Windows y la aplicación al servicio Render corresponden al siguiente paso de la estudiante.


CU21-CU25 siguen las fichas del apartado 4.3. La solicitud no constituye una reserva; la asignación exige turno libre, aceptación y responsable. La recepción registra la coordinación, sin un nuevo rol de personal médico. Para restricciones ordinarias se registra una excepción urgente autorizada; no se paga ni reactiva implícitamente. El historial técnico de solicitudes conserva cada gestión, evitando sobrescribir seguimientos anteriores.

CU23 registra la fecha actual del pago presencial, monto íntegro y comprobante único. La aplicación no procesa cobros bancarios. Los pagos se integran con el reporte CU20 existente. CU24 calcula saldo y estados desde incidencias, multas y pagos, preservando el alcance del paciente. CU25 usa el procedimiento de reactivación existente, con autorización, bloqueo, versión y verificación antes de invocarlo.

La revisión manual local del bloque anterior confirmó la reserva CM-000001 para Luis Prueba con Rosa Ejemplo el 02/11/2026 a las 09:00, su presencia en agenda e historial y un reporte con una cita Programada. La prueba manual de atención e inasistencia queda pendiente por el horario; sus escenarios se ejercitaron con fixtures históricos en las pruebas automatizadas. Los nuevos CU21-CU25 requieren aplicar el paquete local y recorrer sus pantallas con la estudiante. La publicación se hará al completar todos los casos, según su indicación.


## CU26–CU30

Fuente: 2DO PARCIAL_SALUD.docx, páginas físicas 42–44, catálogo de CU25–CU30. La cuenta administrativa conserva las operaciones autorizadas; CU27 y CU30 son personales del Paciente.

| Caso | Implementación | Regla verificada |
|---|---|---|
| CU26 Registrar ausencia del médico | AbsenceController, AbsenceService, AbsenceRepository; /ausencias; gestion_ausencia; migración 007 | Bloqueo por intervalo, citas afectadas, aceptación, sustitución en la misma especialidad, origen Clínica sin sanción y pendientes conservados ante fallos |
| CU27 Vincular cuenta con Telegram | TelegramService, TelegramClient, CommunicationController; /telegram; webhook o polling | Consentimiento, enlace aleatorio de un solo uso, caducidad, hash, chat privado, rechazo de reutilización y revocación |
| CU28 Consultar avisos del sistema | NoticeService, CommunicationController, TaskService; /avisos | Propiedad o ámbito de recepción, asunto/fecha/estado, cita autorizada, error sin alterar agenda y reintento explícito |
| CU29 Consultar contadores de la cita | CounterService; /citas/{id}/contador; _appointment_counter; app.js | Reloj del servidor, espera/bloque/finalizado, cancelación oculta, actualización de horarios y ningún cambio automático de estado |
| CU30 Contactar con recepción por Telegram | TelegramService::contact; /contacto-recepcion | Contacto oficial validado, apertura del chat externo y operaciones registradas después en la web |

TaskService y bin/procesar_tareas.php ejecutan recordatorios e inactividad periódica usando las reglas existentes. Los envíos externos se verifican con transporte simulado en pruebas; la conexión real y los recorridos de Windows quedan sujetos a configuración y revisión local. Las migraciones 001–006 no cambian.
