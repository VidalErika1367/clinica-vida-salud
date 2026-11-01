# Validación CU01–CU30

- Sintaxis: 122 archivos PHP revisados sin errores.
- Pruebas unitarias: 76 comprobaciones correctas.
- HTTP y servicios: 608 comprobaciones correctas (336 CU01–CU15, 82 CU16–CU20, 97 CU21–CU25 y 93 CU26–CU30).
- JavaScript: 5 comprobaciones del contador con reloj y transporte simulados.
- Total: 689 comprobaciones automatizadas.
- Resultados: tests/resultados_validacion_cu26_30.json, además de los reportes de regresión anteriores.
- Actualización 005 a 007 y 006 a 007: preservan cuentas, contraseñas, pacientes y solicitudes previas y se aplican una sola vez. El recorrido 006 a 007 también conserva una ausencia, un vínculo de Telegram y un aviso previos.
- Las seis migraciones anteriores conservan sus bytes.

Entorno: PHP 8.4.24, PDO_PGSQL, mbstring y PostgreSQL nativo 17.11, por TCP y sobre bases locales desechables *_test. El requisito de la aplicación se mantiene en PHP >=8.2 y PostgreSQL >=16. El paquete nuevo todavía requiere aplicación e inspección manual en Windows; no se ha publicado en Render.

## Regresión CU01-CU20

Se conserva el recorrido de acceso, permisos, pacientes, perfil, médicos, especialidades, horarios, disponibilidad, reservas, atención, inasistencia, agenda, historial y reportes. Incluye propiedad, versiones, CSRF, revocación de permisos y reversión transaccional. Las pruebas nativas de atención con dos procesos independientes producen un solo cambio.

## CU21 Solicitar cita urgente

Paciente inactivo y con deudas puede enviar motivo y contacto sin crear cita. Se verifican contacto inválido, ficha ajena, CSRF, identidad derivada de la sesión, doble envío, reutilización del formulario con otros datos, propietario del listado y del detalle, cola de recepción y reversión de solicitud e historial si falla el aviso.

## CU22 Gestionar solicitud urgente

Seguimiento sin asignación ni cierre automático, formulario antiguo, aceptación del paciente, excepción urgente autorizada para restricciones ordinarias, responsable, tipo Urgente y estado Programada, vinculación y conservación de actividad/deudas. Turno ocupado conserva la solicitud pendiente. El doble envío no crea otra cita. Una caída del guardado del aviso revierte cita, solicitud y gestión.

## CU23 Registrar pago de multa

Acceso administrativo, importe exacto como decimal, coma decimal, rechazo de importes menores/mayores/cero/negativos/con precisión extra, recepción presencial verificada, CSRF, comprobante único, responsable y fecha real conservada. Repetición y comprobante duplicado no crean abonos. Un fallo del aviso revierte pago, multa y auditoría. Pagar una deuda conserva la restante y la inactividad. Dos procesos con conexiones independientes intentan pagar la misma multa y producen un solo abono.

## CU24 Consultar advertencias y multas

Paciente inactivo consulta su saldo, paciente ajeno rechazado, ficha sin deuda con saldo cero, consulta equivalente desde recepción, importes y comprobantes pagados conservados. Se verifica la retirada del permiso en la siguiente petición.

## CU25 Reactivar paciente

Identidad y contacto no verificados impiden reactivar. Un cambio de contacto invalida el formulario anterior. La reactivación válida registra fecha y conserva cuenta, contraseña, rol, habilitación, citas, pagos y deudas. Repetir el envío no renueva fecha ni duplica auditoría. Con ficha activa y todas las multas pagadas se verifica una reserva ordinaria real por HTTP. La búsqueda y formulario CU25 funcionan aun sin permiso CU06.

## Alcance de los fixtures y de la revisión manual

Las citas antiguas y algunas incidencias iniciales se preparan como fixtures únicamente en la base de pruebas. Las operaciones de atención, inasistencia, solicitudes, asignaciones, pagos y reactivación se ejercitan mediante HTTP o servicios transaccionales con conexiones PostgreSQL independientes donde se indica. No se ejecutaron estas pruebas sobre la base de la estudiante ni sobre Render. Los avisos se comprueban en su cola; no se afirma envío de Telegram.

La estudiante confirmó en Windows el bloque anterior: creación de CM-000001 para Luis Prueba, Rosa Ejemplo y Medicina General el 02/11/2026 09:00; consulta en agenda e historial; reporte con total 1 y Programadas 1. Atención e inasistencia no se ejercitaron manualmente sobre esa cita futura.

La estudiante confirmó en Windows SU-000001 de Ana Ejemplo, asignación de CM-000002 para el 02/11/2026 09:30 con excepción documentada por ficha inactiva, consulta de saldo cero y reactivación con fecha y motivo visibles. CU23 no se probó manualmente con una multa real pendiente; sí mediante fixtures aislados. Los recorridos visuales CU26–CU30 y la conexión con un bot real quedan pendientes de configuración y prueba local. La publicación se realizará al finalizar el trabajo completo.


## CU26 Registrar ausencia del médico

Permisos, autenticación y CSRF; intervalo y médico válidos; detección de solapamientos con límite final exclusivo; ausencia idempotente y rechazo de superposición. Registrar la ausencia no cambia estados de citas ni crea incidencias. La gestión exige aceptación, registra cancelación o sustitución de la misma especialidad con origen Clínica y conserva pendientes ante un fallo. Se verifican reversión del aviso y del historial, repetición de gestión, disponibilidad al guardar y conservación de la ficha inactiva al resolver una reserva anterior.

## CU27 Vincular cuenta con Telegram

Autorización explícita, enlace aleatorio sin CI ni contraseña, hash almacenado, caducidad y revocación del código anterior. Se rechazan grupos, remitente diferente del chat, código vencido, actualización repetida, reutilización y chat vinculado a otra ficha. Desactivar en la web o mediante /stop revoca el vínculo y códigos pendientes. El webhook rechaza solicitudes sin secreto. No se usó un token real ni se enviaron mensajes a terceros.

## CU28 Consultar avisos del sistema

Propiedad, filtro ajeno rechazado, listado administrativo, fallo de entrega sin fecha de envío y sin cambios en la cita. Reintento exige aceptación administrativa y versión; el paciente no puede forzarlo. Un transporte simulado confirma el mensaje y registra fecha e intentos. El payload externo excluye el texto médico y se comprueba que dos conexiones independientes solo procesan una fila una vez.

## CU29 Consultar contadores de la cita

Endpoint con propiedad y permiso, bloque de 30 minutos, fases exactas antes/durante/después del turno y ninguna transición de estado. Cita cancelada oculta el panel. Cinco verificaciones JavaScript ejercitan espera, inicio automático, reprogramación, finalización y cancelación con reloj simulado, sin depender del reloj del equipo.

## CU30 Contactar con recepción por Telegram

Contacto sin configurar explícito, contacto oficial t.me, apertura externa y ningún cambio de citas. Se rechazan javascript, dominios parecidos, enlaces inseguros y parámetros arbitrarios. Se verifica la revocación inmediata de los cinco permisos del bloque en sesiones abiertas.

## Mantenimiento e integración

Se verifica inactividad de un año y ejecución repetida sin duplicar eventos; recordatorio único por cita y ventana; omisión al cancelar. El proceso CLI permite ejecución continua o un ciclo. Las llamadas getUpdates y sendMessage se implementan contra la API oficial, pero su conexión real requiere configuración y no forma parte de estas 689 comprobaciones. El intento de iniciar Chromium para revisión visual no estuvo disponible por una restricción de sockets del entorno; no se afirma revisión visual automatizada. Las pantallas usan el diseño existente y se revisarán en Windows.

Verificaciones adicionales: el instalador con un administrador existente conserva las cuentas y orienta a iniciar PHP; un ciclo CLI sin Telegram completa mantenimiento; el webhook HTTP con secreto ficticio acepta una actualización y su repetición sin usar la API externa.
