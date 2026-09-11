# Operación en producción

Este documento reúne los controles mínimos para operar Botica POS con continuidad y trazabilidad. No contiene contraseñas, tokens ni cadenas de conexión.

## Salud de la aplicación

| URL | Qué valida | Uso recomendado |
| --- | --- | --- |
| `/up` | Que Laravel puede atender una solicitud. | Healthcheck del servicio web. |
| `/api/health` | Que Laravel y la conexión a MySQL responden. | Monitor externo o verificación posterior a un deploy. |

El endpoint `/api/health` devuelve `200` con `status: ok` cuando la base de datos está disponible. Si MySQL no responde, devuelve `503` y un estado genérico; nunca expone el error interno, host, usuario o contraseña.

Después de cada despliegue de Railway:

1. Abre `https://TU-DOMINIO/up`.
2. Abre `https://TU-DOMINIO/api/health`.
3. Confirma que ambas respuestas sean correctas antes de probar el POS.

## Respaldos de MySQL en Railway

La base de datos y su volumen son parte de la operación crítica. En Railway, configura los respaldos desde el servicio **MySQL**, no desde el servicio `botica-backend`.

Configuración recomendada:

1. Abre el proyecto de Railway y selecciona **MySQL**.
2. Entra en el volumen `mysql-volume` y abre **Backups**.
3. Crea un respaldo manual inicial con una etiqueta como `baseline-produccion-AAAA-MM-DD`.
4. Activa los calendarios **diario**, **semanal** y **mensual**.
5. Revisa el costo y la retención que muestra Railway antes de guardar. La retención predeterminada documentada por Railway es de 6 días para diarios, 27 para semanales y 89 para mensuales.
6. Una vez al mes, confirma que hay al menos un respaldo reciente y registrado en el historial operativo.

Railway administra los respaldos por volumen. Eliminar el volumen elimina también sus respaldos; nunca elimines `mysql-volume` para resolver un incidente.

## Restauración controlada

Una restauración reemplaza el contenido del volumen de destino. Por eso no se debe ejecutar como primer intento de solución.

1. Declara el incidente y conserva los logs de Railway y la hora de detección.
2. Crea un respaldo manual adicional del estado actual, incluso si parece defectuoso.
3. Identifica el respaldo exacto por fecha y motivo.
4. Desde **MySQL > Backups**, selecciona **Restore** y revisa el cambio que Railway prepara.
5. Ejecuta el despliegue de restauración solamente con autorización de un administrador.
6. Valida `/up`, `/api/health`, inicio de sesión, apertura de caja, inventario y una consulta de venta.
7. Registra en el historial operativo: responsable, hora, respaldo usado, motivo y resultado.

Los respaldos de Railway solo se restauran dentro del mismo proyecto y entorno. Para ejercicios de recuperación sin riesgo, utiliza una copia/exportación separada de datos no productivos; no restaures producción solo para probar.

## Variables y errores

Antes de publicar, verifica en Railway:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY` presente
- Las variables `DB_*` referencian el servicio MySQL de Railway
- El frontend solo apunta al dominio HTTPS público del backend

Usa los **Deploy Logs** y **Runtime Logs** de Railway para investigar excepciones. Para una alerta proactiva de errores, la siguiente mejora recomendada es conectar una herramienta de monitoreo de aplicaciones (por ejemplo, Sentry) usando un DSN almacenado como variable secreta; no guardes ese valor en Git.

## Checklist de liberación

Realiza estas comprobaciones en producción antes de dar por bueno un cambio:

- [ ] El build de Railway finalizó correctamente.
- [ ] `/up` y `/api/health` responden correctamente.
- [ ] Se puede iniciar sesión con una cuenta autorizada.
- [ ] El cajero abre caja, registra una venta y consulta un cliente por documento.
- [ ] Un administrador puede anular una venta con motivo y el inventario se restituye.
- [ ] Un administrador puede registrar una corrección de cierre y aparece en el historial.
- [ ] La recepción de una compra aumenta el inventario y deja movimientos trazables.
- [ ] Se visualizan reportes, márgenes y actividad sin exponer datos sensibles.
- [ ] Existe un respaldo reciente de MySQL confirmado.
