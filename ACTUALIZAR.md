# Actualizar los ajustes finales en la laptop

Paquete acumulativo CU01–CU30 y ajustes del 04/11/2026: agrega reporte de pacientes registrados, ubicación de la clínica y fecha de registro en el historial. Conserva cuentas, configuración y la misma base.

## 1. Descargar y extraer

Descarga Vida_Salud_Ajustes_Finales.zip y extráelo en Descargas, en una carpeta llamada Vida_Salud_Ajustes_Finales. Dentro queda clinica-vida-salud.

Antes de copiar, ejecuta git status --short en el proyecto original. Si muestra cambios posteriores al último commit, consérvalos y revísalos antes de reemplazar código. El actualizador respalda archivos, pero no fusiona cambios propios.

## 2. Detener PHP y copiar la actualización

Pulsa Ctrl+C en las terminales que estén ejecutando PHP y procesar_tareas. Desde PowerShell:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\Downloads\Vida_Salud_Ajustes_Finales\clinica-vida-salud\scripts\actualizar_codigo.ps1" -ProjectPath "$env:USERPROFILE\Downloads\Clinica_Vida_Salud_Base_Inicial\clinica-vida-salud"
```

Se respalda el código anterior, se comprueban las migraciones y se conservan .env, .git, storage y los registros de la base. Una copia fallida revierte los archivos que alcanzó a reemplazar. También se actualiza deploy para la publicación posterior.

## 3. Comprobar instalación e iniciar PHP

Desde el proyecto original, con PostgreSQL iniciado:

```powershell
cd "$env:USERPROFILE\Downloads\Clinica_Vida_Salud_Base_Inicial\clinica-vida-salud"
php bin/instalar.php
```

Si ya instalaste CU01–CU30, esta versión agrega una sola migración, 008_restablecer.sql (la columna usuario.debe_cambiar_password del restablecimiento de contraseña); 001–007 conservan sus bytes y 008 se aplica sola al iniciar el servidor o con php bin/instalar.php. Usa las mismas cuentas; no necesitas crear otro administrador.

```powershell
php -S 127.0.0.1:8000 -t public router.php
```

Deja la terminal abierta y entra en http://127.0.0.1:8000/ingresar.

### Revisar los tres ajustes

- Reportes: con recepción, elige Pacientes registrados, período de alta y estado. Una ficha sin citas debe aparecer y sumar en el total. Las altas se filtran por la fecha de registro de la ficha.
- Historial de citas: consulta un paciente y revisa Fecha de registro junto a Fecha y hora del turno.
- Ubicación de la clínica: entra con paciente. Configura CLINICA_DIRECCION, CLINICA_CIUDAD y CLINICA_MAPA_URL según los datos disponibles; usa los nombres y ejemplos de .env.example. La estudiante ya configuró y comprobó su enlace al mapa; no se inventó una dirección textual. El actualizador conserva tu .env y no agrega estos valores por sí solo.

## 4. Revisar el contador (CU29)

Con recepción o el paciente abre el detalle de una cita Programada o Confirmada. Debe aparecer Tiempo de la cita y la cuenta regresiva al horario. El bloque de 30 minutos comienza automáticamente al llegar el inicio. Se oculta al cancelar. Su finalización no registra atención ni inasistencia; esas operaciones siguen requiriendo verificación real.

## 5. Revisar ausencias (CU26)

Con recepción abre Ausencias médicas y Registrar ausencia. Selecciona médico, intervalo y motivo de prueba. Revisa las citas pendientes del intervalo; conservarán su estado. Abre Gestionar cambio, acuerda la alternativa con el paciente y registra aceptación y motivo. Puedes cancelar con origen Clínica o buscar un turno libre del mismo médico o de otro de la misma especialidad. Un fallo conserva el pendiente. El historial de la cita y de la ausencia registra la gestión, sin sancionar al paciente por esa causa.

## 6. Revisar avisos (CU28)

Abre Avisos del sistema. El paciente solo ve los propios; recepción ve el ámbito de la clínica. Comprueba asunto, fecha, estado y acceso al detalle de la cita. Sin Telegram vinculado permanecen disponibles en la web. Un error de entrega no cancela ni confirma citas. Solo recepción o administración pueden autorizar un reintento después de revisar el envío.

## 7. Configurar Telegram (CU27) y WhatsApp (CU30)

Sigue TELEGRAM.md para crear el bot, agregar sus variables al .env local. Para el contacto de recepción (CU30) define CLINICA_RECEPCION_WHATSAPP con hasta 3 contactos "Etiqueta:numero" (código de país, solo dígitos). Después de verificar el bot inicia `php bin/procesar_tareas.php` en otra terminal del proyecto. Ese proceso recibe las vinculaciones, envía los avisos y ejecuta los recordatorios y la inactividad diaria. Debe permanecer activo durante la prueba.

Como paciente abre Avisos por Telegram, autoriza, genera el enlace y pulsa Iniciar en el bot. Vuelve a comprobar la vinculación. Prueba la desactivación. Contactar con recepción debe mostrar un botón por contacto que abre WhatsApp con un saludo prellenado, sin enviar mensajes automáticamente.

La estudiante ya confirmó la vinculación y entrega de avisos por Telegram en local. La desactivación manual, el contacto desde otra cuenta, los recorridos pendientes y la configuración del servidor todavía requieren revisión. No inicies dos procesos de polling contra el mismo bot.

## Pruebas de desarrollo

Solo sobre un servidor local y una base VACÍA y desechable terminada en _test, usando las variables documentadas en tests/http_smoke.py:

```powershell
php tests/unit.php
python tests/menu_movil_smoke.py
node tests/counter_browser.mjs
node tests/report_filters_browser.mjs
node tests/mobile_menu_browser.mjs
```

La prueba HTTP agrega los bloques anteriores y usa un token ficticio de prueba en el entorno del servidor y del proceso de pruebas: TELEGRAM_BOT_TOKEN=123456:PRUEBA_SIN_BOT_REAL_123456789 y TELEGRAM_BOT_USERNAME=ClinicaPruebaBot. Nunca usa un bot real. Node solo es necesario para esa prueba de JavaScript, no para ejecutar la aplicación. No ejecutes la batería sobre la base de tu proyecto ni sobre Render.

## Incorporar el cierre de validación local

Después de instalar los ajustes y el menú móvil, Vida_Salud_Cierre_Local.zip incorpora los tests nuevos y el registro de resultados. Su instalador verifica los archivos modificados de la aplicación y actualiza únicamente los documentos de seguimiento del repositorio, los tests y el script del menú, con respaldo y reversión. No incluye el documento académico; su actualización se realizará al final.

Extrae en Descargas/Vida_Salud_Cierre_Local y, desde PowerShell, ejecuta:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\Downloads\Vida_Salud_Cierre_Local\clinica-vida-salud\scripts\incorporar_validacion_local.ps1" -ProjectPath "$env:USERPROFILE\Downloads\Clinica_Vida_Salud_Base_Inicial\clinica-vida-salud"
~~~

El cierre registra 755 comprobaciones de aplicación, la revisión representativa de cuatro roles y pantallas móviles, la restauración local y el mantenimiento Windows sin servidor web. Los preparadores y comprobar_tareas.php están confinados a la copia local clinica_vida_salud_manual_test y no se ejecutan al desplegar. VALIDACION.md identifica los resultados y los pendientes del servidor.
