# Comprobantes electrónicos

## Estado actual

La instalación opera en `modo_emision_comprobantes=demo`. Cada venta genera una boleta demostrativa con:

- serie y correlativo independientes;
- copia estructurada de los datos utilizados para emitirla;
- huella SHA-256 para detectar cambios;
- historial inmutable de generación y anulación;
- representación imprimible marcada como **MODO PRUEBAS — SIN VALIDEZ TRIBUTARIA**.

El modo demo no se conecta a SUNAT, no utiliza credenciales reales y no registra fechas de envío o aceptación. Si se selecciona producción sin un adaptador oficial, la venta falla de forma segura y la transacción revierte caja, inventario y comprobante.

## Activación productiva pendiente

Después de la aprobación de la dueña se debe:

1. Confirmar el sistema de emisión elegido: SEE del Contribuyente o proveedor PSE/OSE.
2. Registrar a la botica como emisora electrónica y validar la serie oficial.
3. Configurar certificado digital y credenciales como secretos de Railway; nunca guardarlos en Git.
4. Implementar el adaptador oficial para UBL, firma, envío y procesamiento de CDR.
5. Incorporar reintentos, contingencia, resumen diario y notas de crédito según el sistema elegido.
6. Ejecutar pruebas homologadas antes de cambiar el modo a `produccion`.

Los estados de SUNAT deben provenir exclusivamente de una respuesta verificable del servicio oficial o del proveedor contratado. El sistema no debe marcar un comprobante como aceptado basándose solo en una respuesta local.
