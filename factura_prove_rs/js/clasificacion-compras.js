(function (global) {
    'use strict';

    var temporizadorTotales = null;

    function numero(valor) {
        valor = String(valor == null ? '' : valor).replace(',', '.');
        valor = parseFloat(valor);
        return isFinite(valor) ? valor : 0;
    }

    function campo(id, valor) {
        var elemento = document.getElementById(id);
        if (elemento) {
            elemento.value = numero(valor).toFixed(2);
        }
    }

    function filas(tipo) {
        var cuerpo = document.getElementById('iva_multiple_' + tipo + '_body');
        return cuerpo ? Array.prototype.slice.call(cuerpo.querySelectorAll('tr')) : [];
    }

    function selectorClasificacion(fila, tipo) {
        var celda = fila.querySelector('.iva-multiple-clasificacion-cell');
        if (!celda) {
            celda = document.createElement('td');
            celda.className = 'iva-multiple-clasificacion-cell';
            celda.innerHTML = '<select class="form-control input-sm iva-multiple-clasificacion" title="Clasificación de la compra">' +
                '<option value="bienes">Bienes</option><option value="servicios">Servicios</option></select>';
            fila.insertBefore(celda, fila.firstChild);
            celda.firstChild.onchange = function () {
                var destino = document.getElementById('iva_multiple_' + this.value + '_body');
                if (destino) {
                    destino.appendChild(fila);
                    calcular();
                }
            };
        }
        celda.firstChild.value = tipo;
    }

    function resumen(id, base, iva) {
        var cuerpo = document.getElementById(id);
        if (cuerpo) {
            cuerpo.innerHTML = '<tr><td>Base</td><td class="text-right">' + base.toFixed(2) +
                '</td></tr><tr><td>IVA</td><td class="text-right">' + iva.toFixed(2) +
                '</td></tr><tr><th>Total</th><th class="text-right">' + (base + iva).toFixed(2) + '</th></tr>';
        }
    }

    function retencion(baseId, valorId, base, porcentajeId) {
        campo(baseId, base);
        var porcentaje = document.getElementById(porcentajeId);
        campo(valorId, base * numero(porcentaje ? porcentaje.value : 0) / 100);
    }

    function valorCampo(id) {
        var elemento = document.getElementById(id);
        return elemento ? elemento.value : '';
    }

    function copiarConfiguracionRetencion(origen, destino, sufijo) {
        var codigoOrigen = 'codigo_ret_' + sufijo + '_' + origen;
        var porcentajeOrigen = 'porc_ret_' + sufijo + '_' + origen;
        var codigoDestino = 'codigo_ret_' + sufijo + '_' + destino;
        var porcentajeDestino = 'porc_ret_' + sufijo + '_' + destino;
        var destinoCodigo = document.getElementById(codigoDestino);
        var destinoPorcentaje = document.getElementById(porcentajeDestino);

        if (destinoCodigo && !valorCampo(codigoDestino) && valorCampo(codigoOrigen)) {
            destinoCodigo.value = valorCampo(codigoOrigen);
        }
        if (destinoPorcentaje && numero(valorCampo(porcentajeDestino)) === 0 && numero(valorCampo(porcentajeOrigen)) !== 0) {
            destinoPorcentaje.value = valorCampo(porcentajeOrigen);
        }
    }

    function sincronizarConfiguracionRetencion(bienes, servicios) {
        if (bienes.base === 0 && servicios.base > 0) {
            copiarConfiguracionRetencion('b', 's', 'fue');
        } else if (servicios.base === 0 && bienes.base > 0) {
            copiarConfiguracionRetencion('s', 'b', 'fue');
        }
        if (bienes.iva === 0 && servicios.iva > 0) {
            copiarConfiguracionRetencion('b', 's', 'iva');
        } else if (servicios.iva === 0 && bienes.iva > 0) {
            copiarConfiguracionRetencion('s', 'b', 'iva');
        }
    }

    function sincronizarTotalesServidor() {
        global.clearTimeout(temporizadorTotales);
        temporizadorTotales = global.setTimeout(function () {
            var formulario = document.getElementById('form1');
            if (formulario && typeof global.xajax_totales === 'function' && global.xajax && typeof global.xajax.getFormValues === 'function') {
                global.xajax_totales(global.xajax.getFormValues('form1'));
            }
        }, 250);
    }

    function calcular() {
        var datos = [], acumulados = {
            bienes: {base: 0, baseGravada: 0, baseCero: 0, iva: 0},
            servicios: {base: 0, baseGravada: 0, baseCero: 0, iva: 0}
        };

        ['bienes', 'servicios'].forEach(function (tipo) {
            filas(tipo).forEach(function (fila) {
                selectorClasificacion(fila, tipo);
                var porcentaje = numero(fila.querySelector('.iva-multiple-porcentaje').value);
                var base = numero(fila.querySelector('.iva-multiple-base').value);
                var ivaInput = fila.querySelector('.iva-multiple-iva');
                var iva = numero(ivaInput.value);
                var total = base + iva;
                fila.querySelector('.iva-multiple-total').value = total.toFixed(2);
                acumulados[tipo].base += base;
                acumulados[tipo].iva += iva;
                acumulados[tipo][porcentaje > 0 ? 'baseGravada' : 'baseCero'] += base;
                if (base !== 0 || iva !== 0) {
                    datos.push({tipo: tipo, porcentaje_iva: porcentaje, base_imponible: base, valor_iva: iva, total: total});
                }
            });
        });

        var b = acumulados.bienes, s = acumulados.servicios;
        sincronizarConfiguracionRetencion(b, s);
        campo('valor_grab12b', b.baseGravada); campo('valor_grab0b', b.baseCero); campo('ivab', b.iva);
        campo('valor_grab12s', s.baseGravada); campo('valor_grab0s', s.baseCero); campo('ivas', s.iva);
        campo('valor_grab12t', b.baseGravada + s.baseGravada);
        campo('valor_grab0t', b.baseCero + s.baseCero); campo('ivat', b.iva + s.iva);
        var otros = numero((document.getElementById('valor_exentoIva') || {}).value) + numero((document.getElementById('valor_noObjIva') || {}).value);
        campo('totals', b.base + s.base + b.iva + s.iva + otros);
        campo('val_gasto1', b.base + s.base);
        retencion('base_fue_b', 'val_fue_b', b.base, 'porc_ret_fue_b');
        retencion('base_fue_s', 'val_fue_s', s.base, 'porc_ret_fue_s');
        retencion('base_iva_b', 'val_iva_b', b.iva, 'porc_ret_iva_b');
        retencion('base_iva_s', 'val_iva_s', s.iva, 'porc_ret_iva_s');
        resumen('iva_multiple_bienes_resumen', b.base, b.iva);
        resumen('iva_multiple_servicios_resumen', s.base, s.iva);
        resumen('iva_multiple_total_resumen', b.base + s.base, b.iva + s.iva);
        var json = document.getElementById('iva_multiple_json');
        if (json) { json.value = JSON.stringify(datos); }
        sincronizarTotalesServidor();
    }

    global.agregarFilaIvaMultiple = function (tipo, porcentaje, base, iva) {
        var cuerpo = document.getElementById('iva_multiple_' + tipo + '_body');
        if (!cuerpo) { return; }
        var fila = document.createElement('tr');
        fila.innerHTML = '<td><input class="form-control input-sm iva-multiple-porcentaje" type="number" min="0" value="' + numero(porcentaje) + '"></td>' +
            '<td><input class="form-control input-sm iva-multiple-base" value="' + numero(base).toFixed(2) + '"></td>' +
            '<td><input class="form-control input-sm iva-multiple-iva" value="' + numero(iva).toFixed(2) + '"></td>' +
            '<td><input class="form-control input-sm iva-multiple-total" readonly></td>' +
            '<td><button type="button" class="btn btn-danger btn-xs">x</button></td>';
        fila.querySelector('button').onclick = function () { fila.parentNode.removeChild(fila); calcular(); };
        Array.prototype.forEach.call(fila.querySelectorAll('input'), function (input) { input.oninput = calcular; });
        cuerpo.appendChild(fila); selectorClasificacion(fila, tipo); calcular();
    };

    global.cargarIvaMultipleDesdeSri = function (detalles) {
        ['bienes', 'servicios'].forEach(function (tipo) {
            var cuerpo = document.getElementById('iva_multiple_' + tipo + '_body');
            if (cuerpo) { cuerpo.innerHTML = ''; }
        });
        (detalles || []).forEach(function (d) {
            global.agregarFilaIvaMultiple(d.tipo === 'servicios' ? 'servicios' : 'bienes', d.porcentaje_iva, d.base_imponible, d.valor_iva);
        });
        calcular();
    };

    global.calcularIvaMultiple = calcular;
    global.inicializarClasificacionCompras = function () {
        ['bienes', 'servicios'].forEach(function (tipo) {
            filas(tipo).forEach(function (fila) {
                selectorClasificacion(fila, tipo);
                Array.prototype.forEach.call(fila.querySelectorAll('input,select'), function (control) { control.oninput = calcular; });
            });
            var encabezado = document.querySelector('#iva_multiple_' + tipo + '_body');
            if (encabezado && encabezado.parentNode.querySelector('thead tr') && !encabezado.parentNode.querySelector('th.clasificacion')) {
                encabezado.parentNode.querySelector('thead tr').insertAdjacentHTML('afterbegin', '<th class="clasificacion">Clasificación</th>');
            }
        });
        if (global.ivaMultipleSriPendiente) {
            var pendientes = global.ivaMultipleSriPendiente;
            global.ivaMultipleSriPendiente = null;
            global.cargarIvaMultipleDesdeSri(pendientes);
        } else {
            calcular();
        }
    };
}(window));
