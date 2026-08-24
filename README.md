# FacturaGasto-08-24

## Clasificación de compras

El módulo `factura_prove_rs/js/clasificacion-compras.js` centraliza la clasificación
de BIENES/SERVICIOS. Al importar, `fromInvoice` conserva la clasificación actual,
salvo que una descripción contenga un indicio inequívoco de servicio. Para mover una
base (y su IVA) manualmente se utiliza `reclassify`; la operación recalcula en un solo
paso el total, Datos Retención Proveedor y Directorio, sin alterar el total original.

La interfaz puede enlazarse con `ClasificacionCompras.bind(contenedor, estado)`. Los
campos que deban actualizarse llevan `data-clasificacion`, por ejemplo
`bienes-base`, `servicios-base`, `retencion-fuente-bienes`,
`retencion-fuente-servicio`, `directorio-bienes`, `directorio-servicios` y `total`.
Un botón con `data-reclasificar="bienes"` mueve de bienes a servicios (o con valor
`servicios`, en sentido contrario); los importes opcionales se toman de los campos
`data-traslado-base` y `data-traslado-iva` del mismo origen. Después de cada cambio se
emite el evento `clasificacion:actualizada` con el estado consistente en `detail`.

Las pruebas se ejecutan con:

```sh
node --test tests/clasificacion-compras.test.js
```
