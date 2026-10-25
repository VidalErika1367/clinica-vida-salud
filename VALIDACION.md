# Validación del recorrido de reservas CU11-CU15

- Sintaxis: 79 archivos PHP revisados sin errores.
- Validaciones y hash: 76 comprobaciones correctas.
- Flujos HTTP: 336 comprobaciones correctas. Total: 412. Detalle: tests/resultados_validacion.json.
- Compilación del script Python de pruebas correcta.
- Instalación de las cuatro migraciones y repetición sin duplicados.
- Los cuatro archivos SQL conservan exactamente los mismos bytes que el paquete CU10.

Entorno: PHP 8.3.6 y PDO_PGSQL, conectados por TCP a PostgreSQL embebido con PGlite y pglite-socket. El adaptador serializa consultas, limpia consultas preparadas al abrir una conexión y admite margen para el cierre TCP de solicitudes sucesivas. No equivale a conexiones PostgreSQL nativas independientes. La aplicación mantiene consultas preparadas y las extensiones PHP existentes.

Se repitieron las pruebas CU01-CU10: cuentas, sesiones, permisos, último administrador, pacientes, perfil, médicos, especialidades, horarios, estados, conservación, formularios antiguos, validación, CSRF, propiedad y reversión transaccional.

CU11 verifica turnos únicos de 30 minutos, tramos contiguos, fin exclusivo, especialidad y médico activos y relacionados, fechas válidas y futuras, consulta del día actual, ocupación compartida entre especialidades y ausencias con superposición parcial. El paciente inactivo conserva consulta. Médico y visitantes no acceden a este flujo.

CU12 verifica reserva propia y por recepción, paciente activo sin deuda, asignación de autor y estado Programada, duración fija, código, historial y aviso atómicos. Se rechazan duplicados, identificadores ajenos, tipo urgente forzado, estado y duración manipulados, instantes inválidos, horas sin atención, ausencia, paciente inactivo y deuda. Una solicitud abierta antes de que otro usuario ocupe el turno se rechaza al guardar. El aviso queda pendiente en la base, sin afirmar envío externo.

CU13 verifica confirmación, conservación del turno y nuevos eventos, rechazo de repetición y peticiones GET, citas ajenas, terminales y vencidas. Se comprueba la confirmación del paciente después de una reprogramación originada por la clínica: la ratificación actual se registra como del paciente y la causa clínica anterior permanece en el historial.

CU14 verifica conservación de identidad, autor y fecha originales, nueva validación del turno, retorno a Programada, liberación y ocupación correctas, datos anteriores en el historial y rechazo de formularios antiguos. Se conserva el turno anterior si el destino está ocupado. No se cambia especialidad. El paciente no cambia por sí mismo de médico ni declara causas clínicas; recepción requiere aceptación para ofrecer otro profesional de la especialidad y registra ese evento.

CU15 verifica cancelación anticipada y liberación sin sanción, preservación del registro y rechazo de cambios sobre citas terminales o vencidas. Las cancelaciones tardías válidas se ejercitan por HTTP: primera advertencia sin multa, segunda incidencia con multa pendiente, bloqueo de nuevas reservas ordinarias conservando perfil, consulta y confirmación de una reserva previa. Las causas clínicas, justificadas, avisos previos verificados y ausencia médica registrada evitan sanción. Una reserva hecha dentro del plazo se deriva a revisión antes de sancionar.

Para esos escenarios se preparan reservas antiguas ficticias mediante SQL. Una cita vencida se inserta en una transacción de preparación con el validador de altas temporalmente deshabilitado y restituido, sin modificar migraciones. Esto permite comprobar el rechazo del flujo ordinario; no acredita los módulos de atención o inasistencia. Los cambios y las reservas ordinarias de los casos nuevos sí se ejercitan mediante HTTP.

Un fallo provocado al insertar el aviso en la base revierte cita e historial sin cambios parciales y devuelve un 500 controlado. Esa prueba comprueba la atomicidad de la base; no simula una caída de la API de Telegram. El envío externo queda pendiente y deberá procesarse después de confirmar la transacción.

Se comprueban propiedad en listado, consulta y cada cambio, CSRF, códigos inexistentes, ausencia de eliminación física, y revocación y restitución de CU11-CU15 sobre sesiones y formularios abiertos.

La estudiante ya revisó en Windows, PHP 8.2.12 y PostgreSQL 16.15, CU01-CU10: cuatro cuentas y roles; permisos; pacientes; perfil; Medicina General y Pediatría; Rosa Ejemplo con dos especialidades y cuenta medico; y horarios del lunes 08:00-12:00 y 12:00-14:00, rechazo de 11:00-13:00, aceptación contigua y desactivación/reactivación del segundo tramo. Ambos quedaron activos.

Ana Ejemplo figura inactiva y conserva acceso y edición de perfil. CU25 Reactivar paciente está pendiente; la guía indica crear una cuenta ficticia activa para probar reservas, sin modificar ese estado directamente en la base.

CU11-CU15 aún requieren revisión en Windows y navegador de la estudiante. No se realizó inspección visual automatizada porque no está instalado el navegador de pruebas. Se mantuvo el diseño adaptable, formularios accesibles, tablas desplazables y selección de turnos; la apariencia en escritorio y móvil sigue pendiente de revisión.

No se afirma prueba concurrente con PostgreSQL nativo. Se probaron solicitudes sucesivas y formularios desactualizados. La aplicación usa transacciones, bloqueo de cuentas, paciente, cita y las claves del médico compartidas con las reglas SQL; la exclusión de intervalos se conserva en PostgreSQL. La comprobación concurrente nativa queda pendiente.

No se considera completo CU16-CU30: atención, inasistencia, agenda, historial especializado, reportes, urgencias, pago y consulta de multas, reactivación, inactividad automática, Telegram y contadores se completarán en sus módulos. El listado operativo y el registro de cambios sirven de apoyo a CU12-CU15.
