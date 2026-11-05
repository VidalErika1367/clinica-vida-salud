# Menú del celular

En pantallas de hasta 760 píxeles las opciones comienzan plegadas. Menú las despliega y cambia a Cerrar. El escritorio conserva la navegación lateral. Se mantienen las opciones autorizadas de cada rol, teclado, Escape con devolución de foco, cierre al elegir una opción o tocar fuera y adaptación al cambiar el ancho. Sin JavaScript, los enlaces siguen disponibles.

## Instalar el parche

Extrae Vida_Salud_Menu_Movil.zip en Descargas, en la carpeta Vida_Salud_Menu_Movil. Detén con Ctrl+C las dos terminales de PHP que comparten el proyecto. En la terminal PowerShell normal de VS Code ejecuta:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\Downloads\Vida_Salud_Menu_Movil\clinica-vida-salud\scripts\actualizar_menu_movil.ps1" -ProjectPath "$env:USERPROFILE\Downloads\Clinica_Vida_Salud_Base_Inicial\clinica-vida-salud"
~~~

El actualizador comprueba y respalda views/layout.php, public/assets/app.css y public/assets/app.js. Reconoce LF y CRLF. Se detiene ante cambios propios y restaura los archivos previos si falla una copia. Conserva .env y las bases de datos. No contiene migraciones.

Para continuar la revisión del teléfono, inicia otra vez la copia:

~~~powershell
$env:DB_NAME="clinica_vida_salud_manual_test"
$env:APP_URL="http://192.168.0.10:8001"
$env:TELEGRAM_BOT_TOKEN="PRUEBA_MANUAL_SIN_BOT"
php -S 192.168.0.10:8001 -t public router.php
~~~

La dirección corresponde al Wi-Fi de esta sesión. Recarga la página del teléfono. Debe mostrar Menú junto a la marca; al tocarlo aparecen los enlaces y Cerrar. Comprueba Historial de citas y Mi perfil. Las direcciones de los recursos incluyen una versión calculada desde su contenido para recibir los archivos actualizados.

Al terminar la revisión del celular, retira la excepción temporal en la ventana PowerShell de administrador:

~~~powershell
Remove-NetFirewallRule -Name "VidaSalud-Celular-8001"
~~~

Las pruebas automatizadas incluyen 10 interacciones JavaScript del menú y 11 verificaciones HTTP de controles, permisos de los cuatro roles y versiones de recursos. El parche se instaló en Windows y la estudiante confirmó apertura, cierre y navegación en Honor X5c. La regla temporal de conexión del teléfono se retiró tras la prueba. La revisión posterior de Chrome y sus límites constan en VALIDACION.md.
