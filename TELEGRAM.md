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
