"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const classification = require("../factura_prove_rs/js/clasificacion-compras");

test("detecta la factura de referencia como servicio", () => {
    const xml = fs.readFileSync(path.join(__dirname, "..", "2108202601096859902000120029990292108530349455514.xml"), "utf8");
    const descriptions = [...xml.matchAll(/<descripcion>([^<]+)<\/descripcion>/g)]
        .map((match) => ({ descripcion: match[1] }));

    const state = classification.fromInvoice({
        // Base imponible con código de porcentaje 0 de la factura de referencia.
        totalSinImpuestos: 221.93,
        iva: 0,
        detalles: descriptions
    }, "bienes");

    assert.equal(state.clasificacionAutomatica, true);
    assert.equal(state.servicios.base, 221.93);
    assert.equal(state.bienes.base, 0);
    assert.equal(state.total, 221.93);
});

test("mantiene la clasificación disponible cuando no existe indicio de servicio", () => {
    const state = classification.fromInvoice({
        base: 100,
        iva: 15,
        detalles: [{ descripcion: "Repuestos" }]
    }, "bienes");

    assert.deepEqual(state.bienes, { base: 100, iva: 15 });
    assert.deepEqual(state.servicios, { base: 0, iva: 0 });
});

test("reclasifica base e IVA sin duplicar el total y sincroniza las secciones", () => {
    const state = classification.fromInvoice({ base: 221.93, iva: 33.29, detalles: [] }, "bienes");
    const originalTotal = state.total;

    classification.reclassify(state, "bienes", "servicios", 221.93, 33.29);

    assert.deepEqual(state.bienes, { base: 0, iva: 0 });
    assert.deepEqual(state.servicios, { base: 221.93, iva: 33.29 });
    assert.equal(state.total, originalTotal);
    assert.equal(state.datosRetencionProveedor.fuenteBienes, 0);
    assert.equal(state.datosRetencionProveedor.fuenteServicio, 221.93);
    assert.equal(state.datosRetencionProveedor.ivaServicio, 33.29);
    assert.equal(state.directorio.servicios, 221.93);
    assert.equal(state.directorio.total, originalTotal);
});

test("permite reclasificación parcial en sentido contrario", () => {
    const state = classification.fromInvoice({ base: 80, iva: 12, detalles: [{ descripcion: "Servicio técnico" }] });
    classification.reclassify(state, "servicios", "bienes", 30, 4.5);

    assert.deepEqual(state.bienes, { base: 30, iva: 4.5 });
    assert.deepEqual(state.servicios, { base: 50, iva: 7.5 });
    assert.equal(state.total, 92);
});

test("rechaza montos que provocarían valores negativos", () => {
    const state = classification.fromInvoice({ base: 10, iva: 0, detalles: [] }, "bienes");
    assert.throws(
        () => classification.reclassify(state, "bienes", "servicios", 10.01, 0),
        /mayor al disponible/
    );
});
