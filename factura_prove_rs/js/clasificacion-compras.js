(function (root, factory) {
    var api = factory();
    if (typeof module === "object" && module.exports) module.exports = api;
    else root.ClasificacionCompras = api;
}(typeof self !== "undefined" ? self : this, function () {
    "use strict";

    var SERVICE_PATTERN = /(?:^|[^a-z0-9])(?:serv(?:icio|icios)?|serv\.|honorarios?|asesor[ií]a|consultor[ií]a|mantenimiento|arrendamiento|transporte|telecomunicaciones?|energ[ií]a|alumbrado)(?:$|[^a-z0-9])/i;
    var CENTS = 100;

    function money(value) {
        var number = Number(value || 0);
        if (!isFinite(number)) throw new TypeError("El valor debe ser numérico");
        return Math.round((number + Number.EPSILON) * CENTS) / CENTS;
    }

    function isService(details) {
        return (details || []).some(function (detail) {
            return SERVICE_PATTERN.test(String(detail.descripcion || detail.description || ""));
        });
    }

    function emptyBucket() {
        return { base: 0, iva: 0 };
    }

    function calculate(state) {
        state.bienes.base = money(state.bienes.base);
        state.bienes.iva = money(state.bienes.iva);
        state.servicios.base = money(state.servicios.base);
        state.servicios.iva = money(state.servicios.iva);

        var base = money(state.bienes.base + state.servicios.base);
        var iva = money(state.bienes.iva + state.servicios.iva);
        state.total = money(base + iva);
        state.datosRetencionProveedor = {
            fuenteBienes: state.bienes.base,
            fuenteServicio: state.servicios.base,
            ivaBienes: state.bienes.iva,
            ivaServicio: state.servicios.iva,
            total: state.total
        };
        state.directorio = {
            bienes: state.bienes.base,
            servicios: state.servicios.base,
            ivaBienes: state.bienes.iva,
            ivaServicio: state.servicios.iva,
            total: state.total
        };
        return state;
    }

    function fromInvoice(invoice, currentClassification) {
        var classification = isService(invoice.detalles) ? "servicios" : (currentClassification || "bienes");
        if (classification !== "bienes" && classification !== "servicios") {
            throw new TypeError("La clasificación inicial debe ser bienes o servicios");
        }
        var state = { bienes: emptyBucket(), servicios: emptyBucket() };
        state[classification] = {
            base: money(invoice.base != null ? invoice.base : invoice.totalSinImpuestos),
            iva: money(invoice.iva)
        };
        state.clasificacionAutomatica = isService(invoice.detalles);
        return calculate(state);
    }

    function reclassify(state, from, to, base, iva) {
        if ((from !== "bienes" && from !== "servicios") ||
                (to !== "bienes" && to !== "servicios") || from === to) {
            throw new TypeError("La reclasificación debe indicar un origen y destino distintos");
        }
        base = money(base);
        iva = money(iva);
        if (base < 0 || iva < 0 || base > state[from].base || iva > state[from].iva) {
            throw new RangeError("No se puede trasladar un valor mayor al disponible");
        }
        state[from].base = money(state[from].base - base);
        state[from].iva = money(state[from].iva - iva);
        state[to].base = money(state[to].base + base);
        state[to].iva = money(state[to].iva + iva);
        return calculate(state);
    }

    function setField(container, name, value) {
        var field = container.querySelector('[data-clasificacion="' + name + '"]');
        if (!field) return;
        var formatted = money(value).toFixed(2);
        if ("value" in field) field.value = formatted;
        else field.textContent = formatted;
    }

    function render(container, state) {
        var values = {
            "bienes-base": state.bienes.base, "bienes-iva": state.bienes.iva,
            "servicios-base": state.servicios.base, "servicios-iva": state.servicios.iva,
            "total": state.total,
            "retencion-fuente-bienes": state.datosRetencionProveedor.fuenteBienes,
            "retencion-fuente-servicio": state.datosRetencionProveedor.fuenteServicio,
            "retencion-iva-bienes": state.datosRetencionProveedor.ivaBienes,
            "retencion-iva-servicio": state.datosRetencionProveedor.ivaServicio,
            "retencion-total": state.datosRetencionProveedor.total,
            "directorio-bienes": state.directorio.bienes,
            "directorio-servicios": state.directorio.servicios,
            "directorio-iva-bienes": state.directorio.ivaBienes,
            "directorio-iva-servicio": state.directorio.ivaServicio,
            "directorio-total": state.directorio.total
        };
        Object.keys(values).forEach(function (name) { setField(container, name, values[name]); });
        container.dispatchEvent(new CustomEvent("clasificacion:actualizada", { detail: state }));
        return state;
    }

    function bind(container, state) {
        container.addEventListener("click", function (event) {
            var button = event.target.closest("[data-reclasificar]");
            if (!button || !container.contains(button)) return;
            var from = button.getAttribute("data-reclasificar");
            var to = from === "bienes" ? "servicios" : "bienes";
            var baseField = container.querySelector('[data-traslado-base="' + from + '"]');
            var ivaField = container.querySelector('[data-traslado-iva="' + from + '"]');
            render(container, reclassify(state, from, to,
                baseField ? baseField.value : state[from].base,
                ivaField ? ivaField.value : state[from].iva));
        });
        return render(container, state);
    }

    return {
        SERVICE_PATTERN: SERVICE_PATTERN,
        isService: isService,
        fromInvoice: fromInvoice,
        reclassify: reclassify,
        calculate: calculate,
        render: render,
        bind: bind
    };
}));
