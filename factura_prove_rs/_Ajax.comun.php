<?php
/* ARCHIVO COMUN PARA LA EJECUCION DEL SERVIDOR AJAX DEL MODULO */

/***************************************************/
/* NO MODIFICAR */
include_once('../../Include/config.inc.php');
include_once(path(DIR_INCLUDE) . 'conexiones/db_conexion.php');
include_once(path(DIR_INCLUDE) . 'comun.lib.php');
include_once(path(DIR_INCLUDE) . 'Clases/Formulario/Formulario.class.php');
require_once(path(DIR_INCLUDE) . 'Clases/xajax/xajax_core/xajax.inc.php');
require_once(path(DIR_INCLUDE) . 'Clases/GeneraDetalleAsientoContable.class.php');
require_once(path(DIR_INCLUDE) . 'Clases/Logs.class.php');

/***************************************************/
/* INSTANCIA DEL SERVIDOR AJAX DEL MODULO*/
$xajax = new xajax('_Ajax.server.php');
$xajax->setCharEncoding('ISO-8859-1');
/***************************************************/
//	FUNCIONES PUBLICAS DEL SERVIDOR AJAX DEL MODULO 
//	Aqui registrar todas las funciones publicas del servidor ajax
//	Ejemplo,
//	$xajax->registerFunction("Nombre de la Funcion");
/***************************************************/
//	Fuciones de lista de pedido
$xajax->registerFunction("genera_formulario_pedido");
$xajax->registerFunction("cargarDatosClpv");
$xajax->registerFunction("cargarSustento");
$xajax->registerFunction("cargarTipoComprobante");
$xajax->registerFunction("ordenCompra");
$xajax->registerFunction("archivosAdjuntos");
$xajax->registerFunction("guarda_pedido");
$xajax->registerFunction("totales");
$xajax->registerFunction("num_digito");
$xajax->registerFunction("cargar_lista_correo");
$xajax->registerFunction("reporte");


//FUNCIONES ENVIO
$xajax->registerFunction("firmar");
$xajax->registerFunction("validaAutoriza");
$xajax->registerFunction("autorizaComprobante");
$xajax->registerFunction("actualizar_grid");
$xajax->registerFunction("update_comprobante");



// F U N C I O N E S     P A R A     E L     
// S E C U E N C I A L     D E L      P E D I D O
$xajax->registerFunction("secuencial_pedido");
$xajax->registerFunction("cero_mas");


//TIPO FACTURA
$xajax->registerFunction("tipo_factura");
$xajax->registerFunction("validar_factura");

$xajax->registerFunction("centroCostos3");
$xajax->registerFunction("centroCostos4");

$xajax->registerFunction("genera_formulario_clave");
$xajax->registerFunction("clave_acceso");

$xajax->registerFunction("cargar_reemb");

$xajax->registerFunction("genera_formulario_reembolso");
$xajax->registerFunction("totales_reemb");

$xajax->registerFunction("agrega_modifica_grid");
$xajax->registerFunction("mostrar_grid");
$xajax->registerFunction("elimina_detalle");
$xajax->registerFunction("cargar_fact_reemb");
$xajax->registerFunction("genera_formulario_correo");
$xajax->registerFunction("guardar_correo");

$xajax->registerFunction("cargar_electronica");


// DISTRIBUCION
$xajax->registerFunction("genera_formulario_distribucion");
$xajax->registerFunction("totales_dist");
$xajax->registerFunction("agrega_modifica_grid_dist");
$xajax->registerFunction("mostrar_grid_dist");
$xajax->registerFunction("elimina_detalle_dist");
$xajax->registerFunction("totales_dist");

$xajax->registerFunction("genera_comprobante");

$xajax->registerFunction("cod_ret_b");
$xajax->registerFunction("cod_ret_iva");

$xajax->registerFunction("cuenta_fisc");
$xajax->registerFunction("cuenta_gasto");
$xajax->registerFunction("cargarPlanillas");
$xajax->registerFunction("cargarPlanillaClpv");
$xajax->registerFunction("cargarActividades");
$xajax->registerFunction("agrega_modifica_gridAdj");
$xajax->registerFunction("elimina_detalleAdj");
$xajax->registerFunction("genera_documento");
$xajax->registerFunction("calculaFechaVence");
$xajax->registerFunction("selectCuentaContable");
$xajax->registerFunction("selectTret");

$xajax->registerFunction("forma_pago_form");
$xajax->registerFunction("cargar_fp");

$xajax->registerFunction("agrega_modifica_gridFp");
$xajax->registerFunction("mostrar_gridFp");
$xajax->registerFunction("elimina_detalleFp");

$xajax->registerFunction("genera_pdf_doc");
$xajax->registerFunction("validar_cedula_len");
$xajax->registerFunction("tipo_factura_reemb");
$xajax->registerFunction("clave_acceso_reemb");
$xajax->registerFunction("liqCompras");

$xajax->registerFunction("distribucion_ccosn");
$xajax->registerFunction("distribucion_ccosnV");

$xajax->registerFunction("agrega_modifica_grid_ret");
$xajax->registerFunction("elimina_detalle_ret");
$xajax->registerFunction("mostrar_grid_ret");


$xajax->registerFunction("cargar_coti");

$xajax->registerFunction("agrega_modifica_grid_dia");
$xajax->registerFunction("mostrar_grid_dia");
$xajax->registerFunction("elimina_detalle_dia");

$xajax->registerFunction("agrega_modifica_grid_dir_ori");
$xajax->registerFunction("mostrar_grid_dir");
$xajax->registerFunction("elimina_detalle_dir");

$xajax->registerFunction("form_modificar_valor");
$xajax->registerFunction("modificar_valor");

$xajax->registerFunction("cargar_lista_tran");
$xajax->registerFunction("cargar_lista_subcliente");

$xajax->registerFunction("agrega_modifica_grid_dir_ori_add");

$xajax->registerFunction("form_distri");
$xajax->registerFunction("procesar_distri");
$xajax->registerFunction("total_diario");

$xajax->registerFunction("totales_inciva");
$xajax->registerFunction("cargarDatosOP");

$xajax->registerFunction("cargar_stotal");


$xajax->registerFunction("cargarCcos");


$xajax->registerFunction("orden_compra_reporte");
$xajax->registerFunction("orden_compra_reporte_det");

$xajax->registerFunction("asignar_valor_fact");


$xajax->registerFunction("listaBeneficiarios");
$xajax->registerFunction("agrega_modifica_gridAdj_1");
$xajax->registerFunction("elimina_detalleAdj_1");

$xajax->registerFunction("asignar_cuenta");

//
$xajax->registerFunction("agrega_modifica_grid_dia_cheque");

$xajax->registerFunction("reporte_facturas");
$xajax->registerFunction("cargarValoresPlanillaClpv");




/***************************************************/
