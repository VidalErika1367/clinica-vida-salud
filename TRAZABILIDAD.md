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
| CU16 a CU30 | Pendientes de sus módulos web completos. Las tablas y reglas SQL constituyen su base; la cola y el listado operativo de citas ya sirven de apoyo a CU12-CU15. | Próximos módulos |

## Arquitectura

Las vistas presentan los formularios; los controladores coordinan solicitudes de acceso, pacientes, perfil, médicos, especialidades y horarios; Auth y los servicios de cada módulo aplican reglas y validaciones; los repositorios de src/Repositories contienen las consultas PDO. Database proporciona conexión, parámetros y transacciones. Esta división concreta las responsabilidades descritas en los apartados 2.3 y 6.1.2.

Los nombres «Vista CU01», «Control Acceso» y «Servicio Acceso» del análisis se concretan en esos componentes, en lugar de crear una tabla por pantalla. Las entidades persistentes conservan las tablas del diseño. migracion es adicional y técnica; registra qué scripts se instalaron. El índice adicional sobre el nombre de usuario impide duplicados por mayúsculas.

## Criterios de permisos

La matriz conserva las operaciones posibles por rol según el catálogo y la población inicial del documento. El administrador puede retirar o restituir las que correspondan; no puede convertir a un paciente en administrador agregándole CU04. El control de acceso comprueba tanto rol como permiso. CU07 comprueba además el propietario vinculado a la sesión; un permiso administrativo no permite suplantar al paciente. Los siguientes módulos deberán mantener los filtros de propietario o médico asignado.

Se mantienen como operaciones necesarias CU02 y CU03 para todos los roles, y CU04 y CU05 para Administración. Como existe un único rol Administrador compartido por sus cuentas, retirarle CU05 quitaría el acceso a todas; por eso se rechaza. La conservación del último administrador habilitado también continúa en CU04.

Los cambios se guardan en una transacción y cada permiso modificado genera un evento con responsable. La versión del formulario permite rechazar una edición antigua en vez de sobrescribir cambios más recientes. Los permisos se vuelven a consultar en la siguiente petición.

## Puntos pendientes de unificar en el Word

- El texto de CU22 y la tabla del modelo de negocio aún mencionan «personal responsable»; deben indicar gestión directa de recepción, como el diagrama revisado.
- El alcance excluye «cobros» de forma general. Debe precisar que sí se registra el pago presencial de multas, sin incorporar facturación médica general ni pagos por internet.
- Modelo de negocio es el apartado 3; algunos títulos y diagramas todavía usan 1.1 y 1.2.
- Los valores de 24 horas para cancelar y Bs 20 por multa siguen siendo propuestas iniciales configurables.

## Límite de la comprobación

Las pruebas del paquete verifican los flujos implementados CU01-CU15. Atención, inasistencia, agenda médica, historial especializado, urgencias, reportes, pago y consulta de multas, reactivación, inactividad automática, contadores y Telegram se comprobarán en sus módulos. Una tabla instalada o un permiso marcado no acredita un caso completo.

CU06 se implementó según su ficha en el apartado 4.3, con actores Administrador y Recepcionista, búsqueda por CI/nombre/apellido y conservación de citas y multas. Los campos corresponden al alcance 1.4.3 y a la tabla paciente. La estudiante confirmó registro, búsqueda, edición y desactivación de un paciente ficticio, y consulta del registro inactivo desde Recepción en su instalación Windows.

CU07 sigue su ficha del apartado 4.3 y el prototipo Figura 14: Mi perfil, contacto, emergencia y salud básica. El alcance 1.4.3 añade tipo de sangre, enfermedades crónicas y medicamentos a los campos de ejemplo del prototipo. Se utilizan las tablas paciente, perfil_salud y contacto_emergencia existentes; no se agregan migraciones. La identidad permanece protegida y se deriva el propietario desde usuario_id de la sesión. La desactivación o deuda no impide actualizar el perfil. Cada guardado conserva citas históricas, estado y cuenta. Las pruebas incluyen rechazo de perfiles ajenos, edición antigua, campos inválidos, revocación de CU07 y reversión de las tres tablas ante un fallo de auditoría.

La estudiante confirmó CU07 en su instalación Windows: cambios de dirección, emergencia y antecedentes conservados tras recargar, aun con la ficha de Ana Ejemplo inactiva. Reactivar pacientes corresponde a CU25 y sigue pendiente.

CU08 y CU09 siguen sus fichas del apartado 4.3, los prototipos Figuras 15 y 16 y el alcance 1.4.3. Mantienen el actor Administrador, las tablas medico, especialidad y medico_especialidad y RN02, RN04 y RN11. La cuenta médica opcional utiliza usuario_id del modelo existente; su creación permanece en CU04. El código visual ME- identifica al profesional sin agregar una columna. Una asociación retirada cambia de estado y conserva referencias de citas.

La desactivación muestra las citas futuras para revisión en el detalle correspondiente; no modifica sus estados ni horarios. La lista se deriva de los registros y los estados vigentes, sin añadir entidades al modelo. Los cambios del catálogo usan las mismas claves de bloqueo del médico que la validación SQL de reservas. El control concurrente con conexiones PostgreSQL nativas sigue pendiente de comprobación.

La estudiante aplicó CU08-CU09 en Windows y confirmó el alta de Medicina General y Pediatría, rechazo de MEDICINA   GENERAL como duplicado, alta de Rosa Ejemplo con registro DEMO-MED-001 y dos especialidades, búsqueda, edición del teléfono, desactivación y activación conservando las asociaciones y el panel Hola, Rosa con su cuenta medico.

CU10 sigue su ficha del apartado 4.3 y el prototipo Figura 17. Conserva el actor Administrador, la precondición de médico activo, la tabla horario y RN05, RN08 y RN13. La disponibilidad semanal pertenece al médico y respeta medias horas. La exclusión SQL evita superposiciones. La aplicación comprueba la cobertura de citas antes y después del cambio, revierte si una reserva vigente deja de estar cubierta y muestra las afectadas. No cambia el estado ni el intervalo de las citas. Se mantiene la zona horaria de la clínica y la conservación del pasado.

La estudiante aplicó CU10 en Windows y confirmó los tramos del lunes de Rosa Ejemplo, 08:00-12:00 y 12:00-14:00, rechazo del tramo superpuesto 11:00-13:00, aceptación de tramos contiguos y desactivación y reactivación del segundo tramo. Ambos quedaron activos.

CU11-CU15 siguen sus fichas del apartado 4.3 y los prototipos Figuras 18-22. Se utiliza el catálogo de permisos existente, incluyendo la gestión administrativa permitida en su matriz. El paciente siempre queda filtrado por la cuenta de sesión; recepción y administración operan sobre fichas verificadas. El Médico no utiliza estos flujos de reservas.

La confirmación registra la ratificación con origen Paciente y conserva en el historial la causa de una reprogramación anterior de la clínica. Esto permite ratificar sin atribuir al paciente permiso para justificar causas clínicas. La reprogramación conserva especialidad; el cambio de médico requiere rol de recepción o administración y aceptación registrada.

El documento fija el plazo para cancelación y menciona comprobar el plazo aplicable al reprogramar sin definir una sanción independiente. La implementación informa y registra revisión de reprogramaciones dentro del plazo, conservando el historial, sin inventar una multa adicional. Las sanciones se generan solo en las situaciones definidas por RN15-RN17 y los tratamientos propuestos existentes.

El alta y los cambios de citas generan avisos pendientes en la misma transacción. El envío real, la vinculación y el contacto por Telegram quedan en CU27-CU30. No se considera probado el envío externo. La consulta operativa y los cambios de una cita apoyan CU12-CU15; no se declaran completos CU18-CU19 ni CU24.

Las pruebas de citas vencidas e incidencias usan datos históricos ficticios preparados mediante SQL. Las reservas ordinarias y los cambios de CU12-CU15 sí se ejercitan por HTTP; las citas antiguas de preparación no acreditan los módulos de atención e inasistencia. CU11-CU15 todavía requieren revisión en la instalación Windows de la estudiante.
