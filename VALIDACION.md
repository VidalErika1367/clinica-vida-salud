# Validación CU01–CU30

- Sintaxis: 127 archivos PHP revisados sin errores; incluye la utilidad CLI de comprobación de tareas añadida después de la regresión de la aplicación.
- Pruebas unitarias: 87 comprobaciones correctas.
- HTTP y servicios: 668 comprobaciones correctas (344 CU01–CU15, 82 CU16–CU20, 97 CU21–CU25, 93 CU26–CU30, 41 ajustes de sincronización y 11 de navegación móvil).
- JavaScript: 19 comprobaciones: 5 del contador, 4 de filtros de reportes y 10 del menú móvil.
- Total: 774 comprobaciones automatizadas.
- Resultados: tests/resultados_validacion_menu_movil.json, además de los reportes de regresión anteriores.
- Actualización 005 a 007 y 006 a 007: preservan cuentas, contraseñas, pacientes y solicitudes previas y se aplican una sola vez. El recorrido 006 a 007 también conserva una ausencia, un vínculo de Telegram y un aviso previos.
- Las siete migraciones 001–007 conservan sus bytes en estos ajustes; no se modifica el esquema.

Entorno automatizado: PHP 8.4.24, PDO_PGSQL, mbstring y PostgreSQL nativo 17.11, por TCP y sobre bases locales desechables *_test. El requisito de la aplicación se mantiene en PHP >=8.2 y PostgreSQL >=16. Los ajustes y el menú móvil se instalaron en Windows con PHP 8.2.12 y PostgreSQL 16; las comprobaciones manuales constan abajo. Su instalación en Render no se ha verificado.

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

La estudiante confirmó en Windows la creación de CM-000001 para Luis Prueba, Rosa Ejemplo y Medicina General el 02/11/2026 09:00; consulta en agenda e historial; reporte con total 1 y Programadas 1. El 04/11/2026 registró su atención realizada. Las pruebas manuales posteriores de inasistencia y multas se detallan al final.

La estudiante confirmó en Windows SU-000001 de Ana Ejemplo, asignación de CM-000002 para el 02/11/2026 09:30 con excepción documentada por ficha inactiva, saldo cero y reactivación con fecha y motivo visibles. Después confirmó ausencia del médico y reprogramación de CM-000002 a las 10:00, con origen Clínica y sin sanción, dejando cero citas pendientes en ese intervalo. El pago manual de una multa pendiente se comprobó el 04/11/2026 únicamente en la copia de pruebas. El 01/11/2026 confirmó Telegram vinculado y seis avisos recibidos, con estado Enviado en la web. El contacto propio abre Mensajes guardados; falta probarlo desde otra cuenta. La publicación y configuración final del servidor requieren revisión.


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

Se verifica inactividad de un año y ejecución repetida sin duplicar eventos; recordatorio único por cita y ventana; omisión al cancelar. El proceso CLI permite ejecución continua o un ciclo. Las llamadas getUpdates y sendMessage se implementan contra la API oficial, pero su conexión real requiere configuración y no forma parte de estas 755 comprobaciones. El intento de iniciar Chromium para revisión visual no estuvo disponible por una restricción de sockets del entorno; no se afirma revisión visual automatizada. La revisión manual posterior de Windows y del celular se describe abajo.

Verificaciones adicionales: el instalador con un administrador existente conserva las cuentas y orienta a iniciar PHP; un ciclo CLI sin Telegram completa mantenimiento; el webhook HTTP con secreto ficticio acepta una actualización y su repetición sin usar la API externa.

## Ajustes de sincronización del 04/11/2026

41 comprobaciones nuevas: reporte por fecha de alta, inclusión de fichas sin cuenta ni citas, independencia de la fecha de reserva, estados actuales, límites exactos de medianoche de Bolivia, totales y paginación de 56 registros, listas vacías, filtros inválidos, escape, autenticación y revocación CU20. El historial muestra cita.creado_en sin exponer fichas ajenas. La ubicación exige sesión, conserva acceso de inactivos, escapa la configuración y admite únicamente enlaces HTTPS de proveedores de mapas validados; cuando falta dirección informa cómo consultarla.

Cuatro comprobaciones JavaScript adicionales recorren el formulario desde citas a pacientes, luego pagos y de vuelta a citas, verificando campos visibles, controles habilitados y texto de ayuda. La comprobación visual con Chromium no pudo iniciarse por una restricción de sockets del entorno. Esa tentativa no se considera aprobada; las comprobaciones reales posteriores en Windows y Honor X5c se documentan abajo y no equivalen a cobertura de todos los teléfonos.

La estudiante configuró el mapa proporcionado por la clínica y confirmó su apertura; se conserva Santa Cruz de la Sierra, Bolivia como ciudad, sin inventar una dirección textual. El respaldo y la restauración local se comprobaron en una base separada. Continúan pendientes la revisión de Render, el respaldo y comparación de la base remota, Telegram remoto y la ejecución continua de tareas en el alojamiento.

## Menú desplegable y revisión del celular

La estudiante accedió a la copia local desde su Honor X5c y aportó capturas del historial con filtros en una columna y tabla con desplazamiento interno. Solicitó agrupar las opciones de cabecera. Se implementó Menú/Cerrar, plegado inicial, Escape con foco, cierre al elegir o tocar fuera y adaptación entre móvil y escritorio. Se conservaron permisos y navegación lateral. Pasaron 10 comprobaciones JavaScript y 11 HTTP, además de la regresión. El parche se instaló correctamente en Windows, con respaldo de los tres archivos en storage/respaldo-menu-20261104-214952-357. La estudiante confirmó que el menú aparece y que las pruebas de apertura, cierre y navegación se ven bien en su celular. No aportó nuevas capturas del menú; la confirmación de esta última parte es verbal. El navegador remoto no pudo acceder a la vista previa local y esa tentativa no se considera prueba visual aprobada.

Tras la prueba, la estudiante ejecutó Remove-NetFirewallRule -Name "VidaSalud-Celular-8001" en PowerShell como administrador; el comando terminó sin errores visibles. Se registra la retirada del permiso temporal. MENU_MOVIL.md conserva las instrucciones de instalación y limpieza para futuras pruebas.

## Evidencia manual adicional del 04/11/2026

En la base original de Windows se comprobaron tres altas, incluida Marta sin citas; fecha de registro de CM-000002 en el listado; mapa configurado y abierto; CM-000001 Atendida a las 20:05. CM-000002 quedó Inasistencia justificada a las 20:09 por una casilla marcada durante la simulación; no acredita una advertencia.

Se respaldó y restauró en clinica_vida_salud_manual_test. El preparador verificó coincidencia de 3 pacientes, 2 citas, cuentas, permisos e historiales. Solo en esa copia: CM-000003 generó primera advertencia a las 20:31; CM-000004 multa de Bs 20 a las 20:33; la cuenta paciente confirmó reserva deshabilitada; el pago ficticio PRUEBA-MANUAL-001 se registró a las 20:39 con saldo cero y advertencia conservada. Luego se guardó CM-000005 a las 20:42, acreditando desbloqueo, y se canceló a las 20:45, ocultando el contador y conservando el historial.

CM-000006 mostró Tiempo restante del turno 00:10:12 y Programada; CM-000007 mostró Turno finalizado y Programada. Son fixtures del reloj de la copia. Los preparadores históricos y del contador tienen 12 y 10 comprobaciones aisladas adicionales, documentadas aparte de las 755 de la aplicación.

Render, sincronización con la base remota, Telegram del servidor y continuidad de las tareas en el alojamiento siguen sujetos a comprobación.

## Recorrido representativo local de los cuatro roles

En la copia local 8001, la estudiante confirmó el acceso de admin y su panel Administrador; cuatro cuentas habilitadas y sus roles correctos. Consultó Roles y permisos con los 30 casos de uso. Dos capturas de CU16 a CU30 muestran las casillas activas y los campos No corresponde de acuerdo con config/permisos.php; no se modificó la matriz.

Con recepción confirmó /pacientes accesible y /usuarios rechazado mediante URL directa. Con médico consultó la agenda de Rosa Ejemplo, CM-000006 y CM-000007, y confirmó /pacientes rechazado. Con paciente consultó el historial propio de Ana y confirmó el rechazo de /citas/1, de Luis. Estos últimos rechazos se confirmaron verbalmente. Es un recorrido representativo, no una repetición manual de los treinta casos de uso ni una validación remota.

## Revisión responsive adicional

La estudiante confirmó verbalmente en la emulación de Chrome a 360 × 800: agenda del médico con filtros y desplazamiento interno de tabla; disponibilidad con filtros usando paciente; formulario de reserva sin guardar otra cita; campos y botón de Mi perfil; filtros, botón y tarjetas de Reportes usando administrador. Reportes también se revisó a anchos de 320 y 768 píxeles. No se aportaron capturas nuevas de esta emulación; no se afirma inspección visual propia ni cobertura exhaustiva de todos los dispositivos. El historial y el menú también se comprobaron en el Honor X5c como se indica arriba.

## Comprobación CLI local sin servidor web

Con PHP web detenido y las pestañas de la clínica cerradas, la estudiante ejecutó comprobar_tareas.php en Windows. La utilidad solo escribe en clinica_vida_salud_manual_test y deshabilita Telegram en el proceso y sus hijos. Sus ocho comprobaciones resultaron correctas: estado inicial, inactivación anual con un evento, conservación de actividad reciente y reservas futuras, generación de recordatorios sin visitas web, una fila por ventana 1h y 24h sin intentos de envío, ausencia de duplicados al repetir el CLI y validación de citas habilitada. Invoca dos procesos reales independientes de bin/procesar_tareas.php --una-vez; no sustituye la prueba de ejecución continua del alojamiento ni acredita entrega de Telegram.

La utilidad tiene además 15 comprobaciones de seguridad y comportamiento en el entorno aislado, registradas en tests/resultados_comprobacion_tareas.json: rechazo de host remoto y carpeta incorrecta, reversión transaccional de fixtures y DDL ante fallo, aislamiento de la base original, token heredado deshabilitado y repetición segura con nuevos fixtures. Estas comprobaciones, las ocho internas por ejecución y las 12 y 10 de los preparadores anteriores se informan aparte de las 755 de la aplicación.

El documento académico se actualizará al final por decisión de la estudiante, mediante apartados completos para copiar y pegar. Este registro distingue los resultados locales de los pendientes remotos.
