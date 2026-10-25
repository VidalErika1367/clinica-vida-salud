# Actualizar a CU11-CU15: disponibilidad y gestión de reservas

Esta versión conserva CU01-CU10 y añade consultar disponibilidad, programar, confirmar, reprogramar y cancelar citas. Se utilizan las tablas y reglas SQL existentes. Las cuatro migraciones mantienen sus mismos bytes.

## Aplicar la actualización

1. Detén el servidor PHP en su terminal con **Ctrl + C**.
2. Descarga el ZIP actualizado.
3. Extráelo en C:\Users\HP\Downloads\Vida_Salud_CU11_CU15. Dentro debe aparecer clinica-vida-salud.
4. En la terminal de Visual Studio Code ejecuta esta línea completa:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\Downloads\Vida_Salud_CU11_CU15\clinica-vida-salud\scripts\actualizar_codigo.ps1" -ProjectPath "$env:USERPROFILE\Downloads\Clinica_Vida_Salud_Base_Inicial\clinica-vida-salud"
~~~

El nombre de la carpeta nueva debe coincidir exactamente con la ruta del comando. El script respalda código, comprueba migraciones y conserva la configuración, storage, cuentas y pacientes. No ejecuta SQL ni reinstala la base. Si falla una copia, restaura los archivos anteriores.

5. Desde la carpeta del proyecto original, inicia PHP:

~~~powershell
php -S 127.0.0.1:8000 -t public router.php
~~~

6. Abre http://127.0.0.1:8000 y recarga con **Ctrl + F5**.

No ejecutes instalar.php ni crear_admin.php para actualizar. Conservas las mismas contraseñas.

## Preparar un paciente activo para las pruebas

Ana Ejemplo figura inactiva y CU25 Reactivar paciente sigue pendiente. No es necesario modificarla directamente en PostgreSQL.

Entra como administrador y crea una cuenta ficticia desde Usuarios:

- Usuario: paciente2.
- Correo: paciente2@example.com.
- Rol: Paciente.
- Acceso habilitado: marcado.
- CI: DEMO-003.
- Nombres: Carla.
- Apellidos: Ejemplo.
- Teléfono: 00000000.
- Contraseña: elige una de al menos 12 caracteres y repítela. Consérvala en tu equipo.

Se creará una ficha activa vinculada a esa cuenta. Si alguno de esos identificadores ya existe, usa uno diferente.

## Probar CU11: Consultar disponibilidad

1. Cierra sesión e ingresa como paciente2.
2. Abre Consultar disponibilidad.
3. Selecciona Medicina General, Rosa Ejemplo y un lunes futuro con más de 24 horas de anticipación.
4. Pulsa Buscar turnos.
5. Deben aparecer los bloques de 30 minutos de los tramos activos 08:00-12:00 y 12:00-14:00.

Si se consulta un día sin horario, se informa que no hay turnos. Al consultar Pediatría para el mismo médico se comparte la ocupación: reservar en una especialidad ocupa el bloque del profesional para ambas.

## Probar CU12: Programar cita

1. Elige un turno libre, por ejemplo 09:00-09:30.
2. Revisa paciente, médico, especialidad y fecha.
3. El motivo es opcional. Pulsa Guardar reserva.
4. Debe aparecer el código CM- y el estado Programada.
5. Vuelve a consultar la disponibilidad: el turno reservado ya no debe ofrecerse.

Una pantalla de disponibilidad no reserva la hora. Al guardar se vuelve a validar y una reserva competidora se rechaza sin duplicar registros. Un paciente inactivo o con multa pendiente no puede hacer nuevas reservas ordinarias.

## Probar CU13: Confirmar asistencia

1. En Mis citas abre la reserva y pulsa Confirmar asistencia.
2. Debe pasar a Confirmada.
3. El mismo horario sigue ocupado. La operación queda en Registro de cambios.

No se confirma una cita ajena, cancelada, atendida o cuyo turno ya comenzó.

## Probar CU14: Reprogramar cita

1. Abre Reprogramar cita.
2. Busca otro turno libre y selecciónalo.
3. Escribe el motivo y guarda.
4. La cita conserva su código, vuelve a Programada y muestra el turno anterior en su registro.
5. El turno anterior vuelve a estar disponible y el nuevo queda ocupado.
6. Puedes confirmar nuevamente la asistencia.

El paciente conserva médico y especialidad. Recepción o administración pueden ofrecer otro médico de la misma especialidad, con aceptación registrada del paciente. Si el nuevo turno se ocupa, se conserva íntegra la reserva anterior.

El plazo configurado se utiliza para cancelaciones. Una reprogramación dentro de ese plazo muestra una advertencia informativa y registra revisión, sin inventar una multa por reprogramar que no figura en RN15. Se conserva su trazabilidad.

## Probar CU15: Cancelar cita

1. Abre Cancelar cita.
2. Escribe un motivo.
3. Revisa la fecha: usa la cita futura de prueba con suficiente anticipación para comprobar una cancelación sin incidencia.
4. Pulsa Confirmar cancelación.
5. Debe quedar Cancelada y liberar el turno. El registro se conserva y ya no permite confirmar o reprogramar esa cita.

La primera incidencia tardía válida genera advertencia y desde la segunda se registra una multa pendiente. Recepción valida causas clínicas, justificaciones y avisos previos. Las reservas realizadas con menos anticipación que el plazo se derivan a revisión antes de sancionar, según la propuesta del documento. Una ausencia médica registrada evita penalizar al paciente.

Los avisos quedan encolados en PostgreSQL. El envío real de Telegram requiere sus próximos módulos. El pago presencial, la consulta completa de multas y la reactivación se completarán en sus casos de uso; esta versión no simula esos pagos.

## Acceso de recepción y límites de esta versión

Recepción y administración pueden consultar disponibilidad y programar para un paciente activo seleccionado por CI o nombre. Los pacientes solo consultan y gestionan sus citas propias, incluso si intentan cambiar identificadores en la dirección o el formulario. Cada operación requiere su permiso.

El Médico conserva su cuenta y accederá a la agenda en CU18; no utiliza estas pantallas de reservas. El listado operativo de citas y el registro de una reserva apoyan CU12-CU15; los módulos completos de agenda, historial, reportes, atención e inasistencia siguen pendientes.

Este paquete alcanza la meta práctica de implementar CU01-CU15 para una primera versión. No implica por sí solo un porcentaje oficial de evaluación. La documentación hasta diseño contempla los 30 casos.
