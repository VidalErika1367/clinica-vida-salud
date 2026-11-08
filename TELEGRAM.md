# Telegram y tareas automáticas · CU26–CU30

Este paquete contiene el código de los 30 casos de uso. La entrega se prueba primero en local. La conexión real con Telegram requiere configurar un bot y un contacto de recepción; las pruebas automáticas usan un transporte simulado, sin mensajes reales.

## Configurar el bot en el equipo local

1. En Telegram abre el contacto oficial [@BotFather](https://t.me/BotFather), usa `/newbot` y crea el bot de avisos de tu clínica. Conserva su token en privado.
2. Agrega estas variables al archivo `.env` del proyecto que ya utilizas. Conserva el resto de su configuración:

```dotenv
APP_URL=http://127.0.0.1:8000
TELEGRAM_BOT_TOKEN=TOKEN_PRIVADO_DEL_BOT
TELEGRAM_BOT_USERNAME=NombreExactoDelBot
TELEGRAM_RECEPCION_USUARIO=UsuarioDeRecepcion
TELEGRAM_MODO=polling
TELEGRAM_WEBHOOK_SECRET=
```

`TELEGRAM_BOT_USERNAME` es el usuario del bot, sin `@`. El contacto de recepción es una cuenta de atención de la clínica, independiente del bot; usa su usuario público sin `@`. No inventes un contacto ni uses el bot de avisos como si respondiera consultas humanas.

3. Ejecuta `php bin/configurar_telegram.php`. Verifica que el token corresponda al nombre del bot y configura la recepción de mensajes. Este comando sí contacta a Telegram.
4. Mantén el servidor web local abierto. En **otra terminal**, dentro del mismo proyecto, ejecuta:

```powershell
php bin/procesar_tareas.php
```

Ese proceso recibe la vinculación del bot, envía avisos pendientes, crea recordatorios y revisa la inactividad diaria. Detenerlo con Ctrl+C detiene las tareas, sin borrar los datos. `php bin/procesar_tareas.php --una-vez` ejecuta un ciclo y termina; sirve para un programador de tareas que se ejecute periódicamente. En modo polling solo debe existir un proceso activo para el bot.

5. Inicia sesión como paciente, entra en **Avisos por Telegram**, autoriza, genera el enlace, ábrelo en tu propia cuenta de Telegram y pulsa **Iniciar**. Vuelve a la web y pulsa **Comprobar vinculación**. El enlace caduca en 10 minutos; un nuevo enlace invalida el anterior. La web almacena únicamente su hash.
6. Revisa la confirmación en Telegram y los avisos en la web. Puedes desactivar el envío desde la web o enviar `/stop` al bot. Los avisos web se conservan.

No subas `.env`, tokens o respaldos de la base al repositorio. `.env.example` documenta las variables sin incluir claves. Si HTTPS falla en XAMPP, revisa el certificado CA configurado en PHP; conserva la verificación TLS.

## Servidor y webhook opcional

Al publicar el proyecto completo, configura las mismas variables en el servidor y usa el dominio HTTPS real en `APP_URL`. `deploy/start.sh` inicia el proceso de tareas junto con Apache. Se ejecuta mientras el servicio permanezca en ejecución; si la infraestructura suspende o detiene el servicio, las tareas también se detienen. Para ejecución continua se necesita un proceso activo o un programador externo.

El modo predeterminado **polling** funciona localmente sin exponer localhost. Para usar webhook, configura `TELEGRAM_MODO=webhook`, `APP_URL` HTTPS y `TELEGRAM_WEBHOOK_SECRET` aleatorio de 32 a 256 caracteres seguros. Ejecuta `php bin/configurar_telegram.php` en el entorno del servidor. El endpoint `/telegram/webhook` acepta únicamente el secreto exacto enviado en `X-Telegram-Bot-Api-Secret-Token`. No usa formularios de sesión. No configures polling y webhook simultáneamente.

El proceso de tareas sigue siendo necesario con webhook para enviar avisos, generar recordatorios y revisar inactividad. Una actualización duplicada no vuelve a vincular ni consume otra vez el código. El offset de polling avanza después de guardar la actualización. No se asocia una cuenta por su teléfono.

## Comprobación en Render

**Cómo funciona hoy.** No hay cron ni programador en el repositorio. `deploy/start.sh` ejecuta `bin/preparar_servidor.php` (migraciones y primer administrador), lanza `bin/procesar_tareas.php` en segundo plano dentro de un bucle y deja Apache en primer plano. Si el proceso de tareas termina por un error, el bucle escribe `tareas reiniciadas` en los registros y lo vuelve a lanzar a los 5 segundos. Si Apache termina, el contenedor se detiene y el proceso de tareas se cierra con él. El proceso de tareas es un bucle que repite cada 10 segundos: mantenimiento (inactividad diaria y recordatorios), sondeo de Telegram en modo polling y envío de avisos pendientes.

**Qué pasa con el plan gratuito.** Render suspende el servicio después de 15 minutos sin tráfico entrante (véase DESPLEGAR_RENDER.md). La suspensión detiene el contenedor y, con él, el proceso de tareas y su bucle de reinicio: el reinicio automático solo cubre fallos del proceso, no la suspensión. Al despertar, `start.sh` vuelve a ejecutarse y las tareas reinician. Mientras el servicio está dormido:

- En modo polling, Telegram no envía tráfico HTTP al servicio, así que nada lo despierta. Los mensajes al bot quedan sin procesar hasta que alguien abra la web.
- Los avisos pendientes se envían al siguiente ciclo, después de despertar.
- Un recordatorio solo se genera si la cita sigue sin comenzar mientras el proceso está activo. Si el servicio permanece dormido durante toda la ventana de 24 h o de 1 h de una cita, ese recordatorio no se generará.

**Cómo evitar la suspensión.** Un monitor externo que visite la web cada 5 minutos mantiene el tráfico entrante y evita que el plan gratuito suspenda el servicio. Por ejemplo, UptimeRobot con un monitor HTTP(s) cada 5 minutos a `https://<tu-servicio>.onrender.com/ingresar`. Esto no está configurado ni verificado en este repositorio, y el plan gratuito de Render tiene otros límites además de la suspensión (véase DESPLEGAR_RENDER.md).

**Cómo verificarlo.**

1. Abre la web para despertar el servicio. En Render, en **Logs**, busca las líneas `[OK]` de `preparar_servidor`: aparecen en cada arranque en frío y confirman que `start.sh` se ejecutó.
2. En Render → **Logs**, busca el texto `tareas reiniciadas`: cada aparición indica que el proceso de tareas terminó y `start.sh` lo volvió a lanzar. Si aparece repetida cada pocos segundos, el proceso falla al arrancar; busca justo antes el mensaje de error. También puede aparecer `No se completó el ciclo de tareas` cuando falla un ciclo. Los logs no muestran actividad normal del bucle: que no haya reinicios ni errores no prueba por sí solo que el proceso funcione.
3. Ejecuta las consultas siguientes en la base de Render. Son de solo lectura. `render.yaml` declara `ipAllowList: []`, así que la base no admite conexiones externas: usa la consola que ofrezca el panel de la base o el shell del servicio si tu plan lo incluye.
4. Interpreta el resultado:
   - `integracion_estado.inactividad_dia` con la fecha de hoy indica que el mantenimiento se ejecutó hoy. Si la fecha es antigua, el proceso no corrió. Si no hay fila, nunca corrió en esa base.
   - Las citas de las próximas 24 horas deben tener aviso de 24 h y, al acercarse, de 1 h. Sin avisos en citas cercanas, el proceso no corrió dentro de esa ventana.
   - La consulta 2b debe devolver cero filas.
   - Un aviso `enviado` debe tener `enviado_en`. Un aviso `error` tiene intentos y `ultimo_error`.

```sql
SET TIME ZONE 'America/La_Paz';
BEGIN READ ONLY;

-- 1. Recordatorios de 24 h y 1 h por cita (próximas 48 h y últimas 2 h).
SELECT c.id AS cita_id, c.inicio AS inicio_local, c.estado AS estado_cita,
       MAX(CASE WHEN split_part(a.clave_evento, ':', 5) = '24h' THEN a.estado END) AS aviso_24h,
       MAX(CASE WHEN split_part(a.clave_evento, ':', 5) = '1h'  THEN a.estado END) AS aviso_1h,
       COUNT(a.id) AS avisos_total
FROM cita c
LEFT JOIN aviso a ON a.cita_id = c.id AND a.tipo = 'recordatorio'
WHERE c.inicio >= now() - interval '2 hours' AND c.inicio <= now() + interval '48 hours'
GROUP BY c.id, c.inicio, c.estado
ORDER BY c.inicio
LIMIT 200;

-- 2a. Ventanas con más de un aviso (puede ser legítimo si la cita se reprogramó).
SELECT a.cita_id, split_part(a.clave_evento, ':', 5) AS ventana, COUNT(*) AS avisos,
       string_agg(a.estado, ',' ORDER BY a.id) AS estados
FROM aviso a
WHERE a.tipo = 'recordatorio'
GROUP BY a.cita_id, split_part(a.clave_evento, ':', 5)
HAVING COUNT(*) > 1
ORDER BY a.cita_id;

-- 2b. Duplicados activos (pendiente, enviado o error). Debe devolver 0 filas.
SELECT a.cita_id, split_part(a.clave_evento, ':', 5) AS ventana, COUNT(*) AS avisos_activos
FROM aviso a
WHERE a.tipo = 'recordatorio' AND a.estado IN ('pendiente', 'enviado', 'error')
GROUP BY a.cita_id, split_part(a.clave_evento, ':', 5)
HAVING COUNT(*) > 1;

-- 3a. Resumen por estado: cantidad, intentos y fechas.
SELECT a.estado, COUNT(*) AS avisos, SUM(a.intentos) AS intentos_total,
       MAX(a.intentos) AS intentos_maximos,
       COUNT(*) FILTER (WHERE a.estado = 'enviado' AND a.enviado_en IS NULL) AS enviados_sin_fecha,
       COUNT(*) FILTER (WHERE a.estado <> 'enviado' AND a.enviado_en IS NOT NULL) AS no_enviados_con_fecha,
       MAX(a.creado_en) AS ultimo_aviso_creado,
       MAX(a.ultimo_intento_en) AS ultimo_intento,
       MAX(a.enviado_en) AS ultimo_envio_confirmado
FROM aviso a
GROUP BY a.estado
ORDER BY a.estado;

-- 3b. Últimos 30 avisos (sin el texto del mensaje).
SELECT a.id, a.tipo, a.estado, a.intentos, a.cita_id,
       a.creado_en, a.ultimo_intento_en, a.enviado_en, left(a.ultimo_error, 80) AS error
FROM aviso a
ORDER BY a.id DESC
LIMIT 30;

-- 3c. Pendientes sin vinculación de Telegram activa (no pueden enviarse).
SELECT COUNT(*) AS pendientes_sin_vinculo_activo
FROM aviso a
WHERE a.estado = 'pendiente'
  AND NOT EXISTS (SELECT 1 FROM vinculo_telegram v WHERE v.paciente_id = a.paciente_id AND v.activo);

-- 4a. Última ejecución registrada. inactividad_dia guarda la fecha AAAAMMDD del último mantenimiento diario.
SELECT e.clave, e.valor,
       CASE WHEN e.clave = 'inactividad_dia' THEN to_date(e.valor::text, 'YYYYMMDD') END AS fecha_inactividad
FROM integracion_estado e
ORDER BY e.clave;

-- 4b. Señales indirectas: no existe tabla de latidos.
SELECT (SELECT MAX(creado_en) FROM aviso) AS ultimo_aviso_generado,
       (SELECT MAX(ultimo_intento_en) FROM aviso) AS ultimo_intento_envio,
       (SELECT MAX(creado_en) FROM telegram_actualizacion) AS ultima_actualizacion_telegram,
       now() AS consulta_en;

ROLLBACK;
```

Si necesitas una ejecución continua con seguridad, la opción es un programador externo que ejecute `php bin/procesar_tareas.php --una-vez`. Eso requiere un servicio o plan que ejecute tareas programadas en Render; no está configurado en el repositorio ni se ha verificado.

## Avisos, errores y reintentos

Cada cambio guarda su aviso en la misma transacción que la operación. Sin un chat autorizado, el aviso permanece pendiente y está disponible en la web. El proceso solo envía con una vinculación activa y una cuenta habilitada de paciente.

Telegram recibe asunto, fecha, código de cita cuando corresponde y acceso a la web. No recibe CI, contraseñas, motivo de consulta ni información médica. La consulta de los detalles siempre exige autenticación y propiedad en la web.

**Enviado** significa que Telegram respondió correctamente con un mensaje; no confirma lectura ni asistencia. Un fallo o tiempo de espera deja **Error**. No se reintenta automáticamente un error ambiguo: recepción debe revisar y autorizar la repetición, que podría duplicar un mensaje ya recibido. Se conservan intentos y fechas, con un límite de 10 intentos por aviso. Los bloqueos de la cola evitan que dos procesos normales envíen simultáneamente la misma fila; una caída entre la aceptación externa y la confirmación de la transacción todavía puede exigir revisión.

Los recordatorios corresponden al horario vigente, con ventanas de 24 horas y una hora. Se genera uno por cita, horario, médico y ventana; si la cita cambia o termina, se omite su recordatorio pendiente. No se cambia el estado de la cita por una notificación.

## Ausencias y contadores

Recepción registra médico, intervalo y motivo administrativo. Se bloquean reservas, se listan las citas Programadas o Confirmadas que coincidan y se coordina cada cambio. La sustitución mantiene la especialidad y requiere aceptación del paciente. La cancelación o reprogramación guarda origen **Clínica**, responsable, historia y aviso. Si falla, la cita queda pendiente. Esta gestión de una reserva afectada no reactiva fichas ni borra deudas.

El contador aparece en el detalle para Paciente y Recepcionista autorizados, también Administración. Usa inicio, fin y hora del servidor en Bolivia; consulta de nuevo el horario cada 30 segundos y al volver a la pestaña. Antes del inicio muestra espera; luego el bloque de 30 minutos; al terminar muestra **Turno finalizado**. Cancelar lo oculta. No registra atención ni inasistencia. Sin conexión reciente pide actualizar el horario.

## Documentación oficial consultada

- [Enlaces personales para iniciar el bot](https://core.telegram.org/bots/features#deep-linking).
- [Recepción de actualizaciones por polling](https://core.telegram.org/bots/api#getupdates).
- [Webhook y secreto de verificación](https://core.telegram.org/bots/api#setwebhook).
- [Respuesta de envío de mensajes](https://core.telegram.org/bots/api#sendmessage).
