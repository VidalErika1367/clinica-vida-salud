# Actualizar CU26–CU30 en la laptop

Paquete acumulativo CU01–CU30: agrega ausencias médicas, Telegram, avisos, contadores y contacto de recepción. Conserva cuentas, configuración y la misma base. Primero se completa la revisión local; después se publicará el trabajo completo.

## 1. Descargar y extraer

Descarga Vida_Salud_CU26_CU30.zip y extráelo en Descargas en una carpeta llamada Vida_Salud_CU26_CU30. Dentro queda clinica-vida-salud.

## 2. Detener PHP y copiar la actualización

En la terminal de Visual Studio Code donde está corriendo PHP pulsa Ctrl+C. Ejecuta:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\Downloads\Vida_Salud_CU26_CU30\clinica-vida-salud\scripts\actualizar_codigo.ps1" -ProjectPath "$env:USERPROFILE\Downloads\Clinica_Vida_Salud_Base_Inicial\clinica-vida-salud"
```

Se respalda el código anterior, se comprueban las migraciones y se conservan .env, .git, storage y los registros de la base. La copia fallida revierte los archivos que alcanzó a reemplazar. También se actualiza deploy para cuando se publique el proyecto completo.

## 3. Aplicar la migración e iniciar PHP

Desde el proyecto original, con PostgreSQL iniciado:

```powershell
cd "$env:USERPROFILE\Downloads\Clinica_Vida_Salud_Base_Inicial\clinica-vida-salud"
php bin/instalar.php
```

Si ya instalaste CU25, solo debe aplicar 007_comunicaciones.sql. Las seis migraciones anteriores conservan sus bytes. Usa las mismas cuentas; no necesitas crear otro administrador.

```powershell
php -S 127.0.0.1:8000 -t public router.php
```

Deja la terminal abierta y entra en http://127.0.0.1:8000/ingresar. Si PHP no está en PATH, usa el ejecutable de XAMPP que ya funciona en tu equipo.

## 4. Revisar el contador (CU29)

Con recepción o el paciente abre el detalle de una cita Programada o Confirmada. Debe aparecer Tiempo de la cita y la cuenta regresiva al horario. El bloque de 30 minutos comienza automáticamente al llegar el inicio. Se oculta al cancelar. Su finalización no registra atención ni inasistencia; esas operaciones siguen requiriendo verificación real.

## 5. Revisar ausencias (CU26)

Con recepción abre Ausencias médicas y Registrar ausencia. Selecciona médico, intervalo y motivo de prueba. Revisa las citas pendientes del intervalo; conservarán su estado. Abre Gestionar cambio, acuerda la alternativa con el paciente y registra aceptación y motivo. Puedes cancelar con origen Clínica o buscar un turno libre del mismo médico o de otro de la misma especialidad. Un fallo conserva el pendiente. El historial de la cita y de la ausencia registra la gestión, sin sancionar al paciente por esa causa.

## 6. Revisar avisos (CU28)

Abre Avisos del sistema. El paciente solo ve los propios; recepción ve el ámbito de la clínica. Comprueba asunto, fecha, estado y acceso al detalle de la cita. Sin Telegram vinculado permanecen disponibles en la web. Un error de entrega no cancela ni confirma citas. Solo recepción o administración pueden autorizar un reintento después de revisar el envío.

## 7. Configurar Telegram (CU27 y CU30)

Sigue TELEGRAM.md para crear el bot, agregar sus variables al .env local y configurar el contacto oficial de recepción. Después de verificar el bot inicia `php bin/procesar_tareas.php` en otra terminal del proyecto. Ese proceso recibe las vinculaciones, envía los avisos y ejecuta los recordatorios y la inactividad diaria. Debe permanecer activo durante la prueba.

Como paciente abre Avisos por Telegram, autoriza, genera el enlace y pulsa Iniciar en el bot. Vuelve a comprobar la vinculación. Prueba la desactivación. Contactar con recepción debe mostrar el contacto oficial y abrir su chat, sin enviar mensajes automáticamente.

La conexión real del bot y los recorridos visuales de este bloque se completan en el equipo de la estudiante; las pruebas automatizadas no envían mensajes reales. La publicación del código y la revisión de los datos del servidor se realizarán después de terminar estas pruebas.

## Pruebas de desarrollo

Solo sobre un servidor local y una base VACÍA y desechable terminada en _test, usando las variables documentadas en tests/http_smoke.py:

```powershell
php tests/unit.php
python tests/cu26_30_smoke.py
node tests/counter_browser.mjs
```

La prueba HTTP agrega los bloques anteriores y usa un token ficticio de prueba en el entorno del servidor y del proceso de pruebas: TELEGRAM_BOT_TOKEN=123456:PRUEBA_SIN_BOT_REAL_123456789 y TELEGRAM_BOT_USERNAME=ClinicaPruebaBot. Nunca usa un bot real. Node solo es necesario para esa prueba de JavaScript, no para ejecutar la aplicación. No ejecutes la batería sobre la base de tu proyecto ni sobre Render.
