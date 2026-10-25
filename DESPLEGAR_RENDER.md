# Publicar Clínica Vida Salud en Render

Esta configuración publica la aplicación PHP con Apache y crea una base PostgreSQL 16 separada de la instalación de tu laptop. El código de CU01–CU15 se conserva. Docker se ejecuta en Render; no necesitas instalarlo en Windows.

## 1. Agregar los archivos al proyecto que ya subiste a GitHub

Extrae `Vida_Salud_Render.zip` dentro de Descargas, en una carpeta llamada `Vida_Salud_Render`. Debe contener `clinica-vida-salud/scripts/preparar_render.ps1`.

En la terminal de VS Code del proyecto original ejecuta:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\Downloads\Vida_Salud_Render\clinica-vida-salud\scripts\preparar_render.ps1" -ProjectPath "$env:USERPROFILE\Downloads\Clinica_Vida_Salud_Base_Inicial\clinica-vida-salud"
git status --short
```

El script agrega los archivos de despliegue y respalda los que ya existan. Conserva `.env`, `.git` y la base de datos local. El `.env` continúa excluido de Git. No reemplaces toda tu carpeta original por este paquete: contiene solo los archivos adicionales.

Después de revisar la lista:

```powershell
git add Dockerfile .dockerignore .gitattributes render.yaml DESPLEGAR_RENDER.md deploy bin/preparar_servidor.php scripts/preparar_render.ps1
git commit -m "Preparar publicacion de PHP y PostgreSQL en Render"
git push
```

## 2. Crear los recursos gratuitos

1. Abre https://dashboard.render.com y entra con tu cuenta de GitHub `VidalErika1367`.
2. En **New → Blueprint**, conecta el repositorio `VidalErika1367/clinica-vida-salud` y su rama `main`.
3. Render leerá el archivo `render.yaml`. Deben aparecer un servicio web y una base de datos, ambos con plan **Free** y en la misma región.
4. Completa estos tres campos en Render, sin escribirlos dentro del código:

| Campo | Qué debes colocar |
| --- | --- |
| `CLINICA_ADMIN_USER` | El nombre de usuario que quieres para el administrador en internet. |
| `CLINICA_ADMIN_EMAIL` | El correo del administrador. |
| `CLINICA_ADMIN_PASSWORD` | Una contraseña de 12 a 72 bytes. |

5. Aplica el Blueprint. Si el resumen muestra un plan de pago, corrige el plan antes de crear los recursos. Esta configuración no requiere un plan de pago.
6. Espera a que la base esté disponible y el servicio termine su primer despliegue. El primer arranque crea las tablas y el administrador. Si falla la conexión o la instalación, Apache no arranca: revisa los mensajes del despliegue antes de reintentarlo.
7. Cuando el servicio aparezca **Live**, abre la URL HTTPS exacta que muestra Render. No se puede conocer esa URL antes de crear el servicio.

Las variables `DB_*` se completan automáticamente desde la base del mismo Blueprint. La conexión usa TLS. La base solo acepta conexiones desde la red privada de Render. No copies tu `.env` local al repositorio.

## 3. Probar el sistema publicado

1. Abre `/ingresar` e inicia sesión con el administrador configurado en Render.
2. Desde **Usuarios**, crea las cuentas de recepción, médico y paciente que se usarán para la demostración.
3. Registra especialidades, un médico y sus horarios, y comprueba el recorrido de disponibilidad y citas.
4. Cierra sesión y verifica el ingreso con cada rol. Comprueba que los cambios se conserven después de recargar la página.
5. Comparte con el ingeniero la URL del sistema y, por separado, las credenciales de prueba que decidas entregarle.

La base del servidor comienza vacía, con los catálogos del sistema y el administrador inicial. Las cuentas, médicos, pacientes y citas de tu laptop no se copian automáticamente. Migrar esos registros sería un paso adicional con exportación e importación de PostgreSQL.

## Qué ocurre al actualizar

Cada nuevo `git push` a `main` puede iniciar un despliegue. El arranque verifica las migraciones y conserva las ya aplicadas. Si existe un administrador habilitado, no cambia su usuario, correo ni contraseña. Una vez creado el administrador inicial, sus tres variables pueden retirarse de **Environment**; agregar nuevas cuentas o cambiar sus contraseñas se hace dentro de **Usuarios**.

Las sesiones y el control temporal de intentos se guardan en el contenedor. Un reinicio puede cerrar las sesiones y reiniciar ese control temporal; los usuarios y las citas se conservan en PostgreSQL.

## Límites del plan gratuito

- La web se suspende después de 15 minutos sin tráfico y puede tardar aproximadamente un minuto en despertar.
- La base PostgreSQL gratuita vence 30 días después de su creación. Guarda un respaldo antes de esa fecha o cambia de plan si necesitas conservarla en Render.
- La configuración gratuita es para la demostración académica con datos ficticios. La puesta en producción de una clínica real necesita una revisión adicional.

## Referencias

- PHP y Docker: https://hub.docker.com/_/php
- Docker en Render: https://render.com/docs/docker
- Blueprints: https://render.com/docs/blueprint-spec
- Plan gratuito: https://render.com/docs/free
- Extensiones PostgreSQL: https://render.com/docs/postgresql-extensions
