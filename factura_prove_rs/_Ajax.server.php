<?php

require("_Ajax.comun.php"); // No modificar esta linea
include_once './mayorizacion.inc.php';
/* :::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::
  // S E R V I D O R   A J A X //
  :::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::: */

/**
  Herramientas de apoyo
 */

function asignar_cuenta()
{

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	$oReturn = new xajaxResponse();

	$oReturn->script("anadir_dasi();");

	return $oReturn;
}
function asignar_valor_fact($val = 0, $id = '', $eti = '', $aForm = '')
{

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}


	$oReturn = new xajaxResponse();

	$check = $aForm[$eti];

	$val = round($val, 2);

	if ($check == 'N') {
		$oReturn->assign($id, 'value', $val);
	} else {
		$oReturn->assign($id, 'value', '');
	}
	return $oReturn;
}


function porcentaje_iva_desde_xml_sri($totalImpuesto)
{
	$tarifa = isset($totalImpuesto->tarifa) ? (float)$totalImpuesto->tarifa : null;
	if ($tarifa !== null && $tarifa >= 0) {
		return $tarifa;
	}
	$codigoPorcentaje = isset($totalImpuesto->codigoPorcentaje) ? (string)$totalImpuesto->codigoPorcentaje : '';
	$mapa = array(
		'0' => 0,
		'2' => 12,
		'3' => 14,
		'4' => 15,
		'5' => 5,
		'10' => 13,
	);
	return isset($mapa[$codigoPorcentaje]) ? $mapa[$codigoPorcentaje] : 0;
}

function agregar_iva_multiple_xml_sri(&$detalles, $tipo, $porcentaje, $base, $iva)
{
	$base = round((float)$base, 2);
	$iva = round((float)$iva, 2);
	$porcentaje = round((float)$porcentaje, 6);
	if ($base == 0 && $iva == 0) {
		return;
	}
	$clave = $tipo . '|' . $porcentaje;
	if (!isset($detalles[$clave])) {
		$detalles[$clave] = array(
			'tipo' => $tipo,
			'porcentaje_iva' => $porcentaje,
			'base_imponible' => 0,
			'valor_iva' => 0,
			'total' => 0,
		);
	}
	$detalles[$clave]['base_imponible'] += $base;
	$detalles[$clave]['valor_iva'] += $iva;
	$detalles[$clave]['total'] += round($base + $iva, 2);
}

function script_cargar_iva_multiple_xml_sri($detalles)
{
	$filas = array_values($detalles);
	foreach ($filas as &$fila) {
		$fila['base_imponible'] = round($fila['base_imponible'], 2);
		$fila['valor_iva'] = round($fila['valor_iva'], 2);
		$fila['total'] = round($fila['total'], 2);
	}
	return "cargarIvaMultipleDesdeSri(" . json_encode($filas) . ");";
}


function escapar_sql_iva_multiple($valor)
{
	return str_replace("'", "''", trim((string)$valor));
}

function existe_tabla_iva_multiple_factura($oIfx)
{
	static $existe = null;
	if ($existe !== null) {
		return $existe;
	}

	$sql = "select count(*) as contador from information_schema.tables where lower(table_name) = 'saefprv_iva_det'";
	$contador = consulta_string_func($sql, 'contador', $oIfx, 0);
	if ($contador > 0) {
		$existe = true;
		return $existe;
	}

	$sql = "select count(*) as contador from systables where lower(tabname) = 'saefprv_iva_det'";
	$existe = (consulta_string_func($sql, 'contador', $oIfx, 0) > 0);
	return $existe;
}

function obtener_iva_multiple_factura($aForm)
{
	$detalles = array();
	if (!empty($aForm['iva_multiple_json'])) {
		$tmp = json_decode($aForm['iva_multiple_json'], true);
		if (is_array($tmp)) {
			foreach ($tmp as $fila) {
				$tipo = isset($fila['tipo']) ? $fila['tipo'] : '';
				$porcentaje = isset($fila['porcentaje_iva']) ? (float)$fila['porcentaje_iva'] : 0;
				$base = isset($fila['base_imponible']) ? (float)$fila['base_imponible'] : 0;
				$iva = isset($fila['valor_iva']) ? (float)$fila['valor_iva'] : round(($base * $porcentaje) / 100, 2);
				if (($tipo == 'bienes' || $tipo == 'servicios') && ($base != 0 || $iva != 0)) {
					$detalles[] = array('tipo' => $tipo, 'porcentaje_iva' => $porcentaje, 'base_imponible' => $base, 'valor_iva' => $iva, 'total' => isset($fila['total']) ? (float)$fila['total'] : round($base + $iva, 2));
				}
			}
		}
	}
	if (count($detalles) == 0) {
		$ivabp = isset($aForm['ivabp']) ? (float)$aForm['ivabp'] : 0;
		$campos = array(array('bienes', $ivabp, 'valor_grab12b', 'ivab'), array('bienes', 0, 'valor_grab0b', ''), array('servicios', $ivabp, 'valor_grab12s', 'ivas'), array('servicios', 0, 'valor_grab0s', ''));
		foreach ($campos as $campo) {
			$base = isset($aForm[$campo[2]]) ? (float)$aForm[$campo[2]] : 0;
			$iva = ($campo[3] != '' && isset($aForm[$campo[3]])) ? (float)$aForm[$campo[3]] : round(($base * $campo[1]) / 100, 2);
			if ($base != 0 || $iva != 0) {
				$detalles[] = array('tipo' => $campo[0], 'porcentaje_iva' => $campo[1], 'base_imponible' => $base, 'valor_iva' => $iva, 'total' => round($base + $iva, 2));
			}
		}
	}
	return $detalles;
}

function guardar_iva_multiple_factura($oIfx, $idempresa, $idsucursal, $factura, $serie, $cod_prove, $usuario, $aForm)
{
	if (!existe_tabla_iva_multiple_factura($oIfx)) {
		return true;
	}

	$detalles = obtener_iva_multiple_factura($aForm);
	$factura_sql = escapar_sql_iva_multiple($factura);
	$serie_sql = escapar_sql_iva_multiple($serie);
	$usuario_sql = escapar_sql_iva_multiple($usuario);
	$fecha_server_sql = date('Y-m-d H:i:s');
	$sql = "delete from saefprv_iva_det where fpid_cod_empr = $idempresa and fpid_cod_sucu = $idsucursal and fpid_num_fact = '$factura_sql' and fpid_num_seri = '$serie_sql' and fpid_cod_clpv = $cod_prove";
	if (!$oIfx->QueryT($sql)) {
		return false;
	}
	foreach ($detalles as $detalle) {
		$tipo = ($detalle['tipo'] == 'servicios') ? 'S' : 'B';
		$porcentaje = number_format($detalle['porcentaje_iva'], 6, '.', '');
		$base = number_format($detalle['base_imponible'], 4, '.', '');
		$iva = number_format($detalle['valor_iva'], 4, '.', '');
		$total = number_format($detalle['total'], 4, '.', '');
		$sql = "insert into saefprv_iva_det (fpid_cod_empr, fpid_cod_sucu, fpid_num_fact, fpid_num_seri, fpid_cod_clpv, fpid_tip_reg, fpid_por_iva, fpid_base_imp, fpid_val_iva, fpid_val_total, fpid_est_reg, fpid_user_web, fpid_fech_server) values ($idempresa, $idsucursal, '$factura_sql', '$serie_sql', $cod_prove, '$tipo', $porcentaje, $base, $iva, $total, '1', '$usuario_sql', '$fecha_server_sql')";
		if (!$oIfx->QueryT($sql)) {
			return false;
		}
	}
	return true;
}

function orden_compra_reporte_det($serial, $idempresa, $idsucursal, $aForm = '')
{
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo();
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo();
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$oReturn = new xajaxResponse();

	$sHtml = ' <table id="tbordservdet" class="table table-striped table-bordered table-hover table-condensed" style=" width: 100%; margin-top: 0px;">
                    <thead><tr>
                        <th >No</th>
						<th >Codigo</th>
                        <th >Producto</th>
						<th >Detalle</th>
                        <th >Cantidad</th>                        
                        <th >Costo</th>
						<th >Total</th>
                    </tr></thead>';
	$sHtml .= '<tbody>';


	$sql = "select dmov_det1_dmov, dmov_cod_prod, dmov_cod_bode, dmov_cod_unid,
				dmov_can_dmov, dmov_cun_dmov, dmov_cto_dmov
				from saedmov where
				dmov_cod_empr = $idempresa and
				dmov_cod_sucu = $idsucursal and
				dmov_num_comp = $serial ";
	//$oReturn->alert($sql);
	$i = 1;
	$total = 0;
	if ($oIfx->Query($sql)) {
		if ($oIfx->NumFilas() > 0) {
			do {
				$codigo    = ($oIfx->f('dmov_cod_prod'));
				$detalle = utf8_decode($oIfx->f('dmov_det1_dmov'));
				$sql = "select prod_nom_prod from saeprod where prod_cod_empr = $idempresa and prod_cod_prod = '$codigo' ";
				if ($oIfxA->Query($sql)) {
					if ($oIfxA->NumFilas() > 0) {
						$nom_prod  = htmlentities($oIfxA->f('prod_nom_prod'));
					}
				}

				$cant      = $oIfx->f('dmov_can_dmov');
				$costo     = $oIfx->f('dmov_cun_dmov');
				$subt      = $oIfx->f('dmov_cto_dmov');

				$sClass = ($sClass == 'off') ? $sClass = 'on' : $sClass = 'off';
				$sHtml .= '<tr height="20" class="' . $sClass . '"
										onMouseOver="javascript:this.className=\'link\';"
										style="cursor: hand !important; cursor: pointer !important;"
										onMouseOut="javascript:this.className=\'' . $sClass . '\';"
										onClick="javascript:guia_detalle(\'' . $id_cliente . '\',\'' . $guia_cod_guia . '\');">';

				$sHtml .= '<td>' . $i . '</td>';
				$sHtml .= '<td>' . $codigo . '</td>';
				$sHtml .= '<td>' . $nom_prod . '</td>';
				$sHtml .= '<td>' . $detalle . '</td>';
				$sHtml .= '<td align="right">' . $cant . '</td>';
				$sHtml .= '<td align="right">' . $costo . '</td>';
				$sHtml .= '<td align="right">' . $subt . '</td>';
				$sHtml .= '</tr>';

				$i++;
				$total += $subt;
			} while ($oIfx->SiguienteRegistro());
		}
	}
	$sHtml .= '</tbody>';

	$sHtml .= '<tfoot><tr>';
	$sHtml .= '<td></td>';
	$sHtml .= '<td></td>';
	$sHtml .= '<td align="right"></td>';
	$sHtml .= '<td align="right"></td>';
	$sHtml .= '<td align="right"></td>';
	$sHtml .= '<td align="right" class="fecha_letra">TOTAL:</td>';
	$sHtml .= '<td align="right" class="fecha_letra">' . $total . '</td>';
	$sHtml .= '</tr></tfoot>';
	$sHtml .= '</table>';

	$modal  = '<div class="modal-dialog modal-lg">
	<div class="modal-content">
		<div class="modal-header">
			<button type="button" class="close" data-dismiss="modal">&times;</button>
			<h4 class="modal-title">DETALLE - ORDEN DE COMPRA</h4>
		</div>
		<div class="modal-body">';
	$modal .= $sHtml;
	$modal .= '          </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-danger" data-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
             </div>';

	$oReturn->assign("ModalOrdenDet", "innerHTML", $modal);
	$oReturn->script("init_table('tbordservdet')");

	return $oReturn;
}
// REPORTE ORDEN DE COMPRA
function orden_compra_reporte($aForm = '')
{

	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo();
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa  = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm['sucursal'];

	$oReturn = new xajaxResponse();

	//FORMATO PERSONALIZADO ORDEN DE COMPRA

	$sql = "select defi_for_defi from saetran, saedefi where defi_cod_empr=tran_cod_empr 
    and defi_cod_tran=tran_cod_tran
    and tran_cod_modu=10 and tran_cod_tran like '002%' and tran_cod_empr=$idempresa 
    and tran_cod_sucu=$idsucursal";

	$cod_ftrn = consulta_string($sql, 'defi_for_defi', $oIfx, '');
	$ctrl_formato = 0;
	if (!empty($cod_ftrn)) {
		$sqlf = "select ftrn_ubi_web from saeftrn where ftrn_cod_ftrn=$cod_ftrn";
		$ubi_formato = consulta_string($sqlf, 'ftrn_ubi_web', $oIfx, '');

		if (!empty($ubi_formato)) {
			$ctrl_formato++;
			include_once('../../' . $ubi_formato . '');
		}
	}

	//PARAMETRO 
	$sql = "select para_tip_oc from saepara where para_cod_empr=$idempresa";
	$tipo_oc = consulta_string($sql, 'para_tip_oc', $oIfx, 0);

	$filoc = '';
	if ($tipo_oc == 1) {
		$filoc = "and minv_tip_ord=1";
	} elseif ($tipo_oc == 2) {
		$filoc = "and minv_tip_ord is null";
	}

	$sHtml .= ' <table id="tbordserv" class="table table-striped table-bordered table-hover table-condensed" style=" width: 100%; margin-top: 0px;">
                    <thead><tr>
                        <th >No</th>
						<th >Fecha</th>    
						<th >Orden Compra</th>
                        <th >Proveedor</th>                                            
                        <th >Detalle</th>
						<th >Imprimir</th>
						<th >Total</th>
                    </tr></thead>';
	$sHtml .= '<tbody>';
	//VALIDACION POR PAIS
	$S_PAIS_API_SRI = $_SESSION['S_PAIS_API_SRI'];

	$sql = "SELECT minv_num_comp,   minv_fmov,      clpv_nom_clpv,   minv_num_sec, minv_cod_clpv,  minv_dege_minv, minv_fec_cad,minv_cer_sn,
				(COALESCE(minv_tot_minv,0) - COALESCE(minv_dge_valo,0) + COALESCE(minv_iva_valo,0) + COALESCE(minv_otr_valo,0) - COALESCE(minv_fle_minv,0) + COALESCE(minv_val_ice,0) ) total
				FROM saeminv,    saeclpv,    saedmov   WHERE 
				minv_cod_clpv = clpv_cod_clpv  and  
				minv_num_comp = dmov_num_comp and  
				minv_est_minv = '1'  and 
				minv_cod_tran in  ( select defi_cod_tran from saedefi Where 
										defi_tip_defi  = '4' and 
										defi_cod_empr  = $idempresa and 
										defi_cod_modu  = 10)  AND  
				minv_cod_empr = $idempresa  AND  
				minv_cod_sucu = $idsucursal 
				$filoc AND         
				clpv_cod_empr = $idempresa and
				dmov_can_dmov <> dmov_can_entr  
				--and (( minv_cer_sn is null) or ( minv_cer_sn = 'N' ) ) 
				ORDER BY 2 desc";

	//$oReturn->alert($sql);
	$i = 1;
	$total = 0;
	if ($oIfx->Query($sql)) {
		if ($oIfx->NumFilas() > 0) {

			do {
				$fec_oc     = $oIfx->f('minv_fmov');
				$minv_sec     = $oIfx->f('minv_num_sec');
				$clpv_cod     = $oIfx->f('minv_cod_clpv');
				$clpv_nom     = $oIfx->f('clpv_nom_clpv');
				$serial     = $oIfx->f('minv_num_comp');
				$monto         = round($oIfx->f('total'), 2);
				$estado     =  $oIfx->f('minv_cer_sn');
				$fecha_cad  =  $oIfx->f('minv_fec_cad');
				$fecha_hoy  = date('Y-m-d');
				$ctrl_ord = 0;
				if ($estado == 'N' || empty($estado)) {
					$ctrl_ord = 1;
				}

				if (!empty($fecha_cad)) {
					if ($fecha_cad >= $fecha_hoy) {
						$ctrl_ord = 1;
					} else {
						$ctrl_ord = 0;
					}
				}



				$ruta = DIR_FACTELEC . 'Include/orden_compra';
				if (!file_exists($ruta)) {
					mkdir($ruta, 0777, true);
				}
				$ruta2 = "../../Include/orden_compra/ORDEN_COMPRA_$serial.pdf";
				if (!file_exists($ruta2)) {
					if ($ctrl_formato == 0) {
						//PERU  
						if ($S_PAIS_API_SRI == '51') {
							include_once('../../Include/Formatos/comercial/orden_compra_peru.php');
							formato_orden_compra($serial);
						} else {
							reporte_orden_compra($serial);
						}
					} else {

						formato_orden_compra($serial);
					}
				}

				$orden = '<a href="' . $ruta2 . '" target="_blank"><div align="center"> <div class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-print"><span></div> </div></a>';

				if ($ctrl_ord == 1) {
					$sClass = ($sClass == 'off') ? $sClass = 'on' : $sClass = 'off';

					$sHtml .= '<tr height="20" class="' . $sClass . '"
										onMouseOver="javascript:this.className=\'link\';"
										style="cursor: hand !important; cursor: pointer !important;"
										onMouseOut="javascript:this.className=\'' . $sClass . '\';"
										onClick="javascript:guia_detalle(\'' . $id_cliente . '\',\'' . $guia_cod_guia . '\');">';

					$sHtml .= '<td align="center">' . $i . '</td>';
					$sHtml .= '<td align="center">' . $fec_oc . '</td>';
					$sHtml .= '<td align="center">' . $minv_sec . '</td>';
					$sHtml .= '<td>' . $clpv_nom . '</td>';
					$sHtml .= '<td align="center" >
								<div class="btn btn-primary btn-sm" onClick="javascript:cargar_oc_det_gen(\'' . $serial . '\', \'' . $idempresa . '\', \'' . $idsucursal . '\')" >
									<span class="glyphicon glyphicon-cog"></span>
									Detalle
								</div>
						   </td>';
					$sHtml .= '<td align="center">' . $orden . '</td>';
					$sHtml .= '<td align="right">' . $monto . '</td>';
					$sHtml .= '</tr>';
				}

				$i++;
				$total += $monto;
			} while ($oIfx->SiguienteRegistro());
			$sHtml .= '</tbody>';
			$sHtml .= '<tfoot><tr>';
			$sHtml .= '<td></td>';
			$sHtml .= '<td></td>';
			$sHtml .= '<td></td>';
			$sHtml .= '<td></td>';
			$sHtml .= '<td align="right"></td>';
			$sHtml .= '<th align="right" >TOTAL:</th>';
			$sHtml .= '<td align="right" >' . $total . '</td>';
			$sHtml .= '</tr></tfoot>';
			$sHtml .= '</table>';
		} else {
			$sHtml = '<div align="center"><span><font color="red">SIN ORDENES GENERADAS</font></span></div> ';
		}
	}

	$modal  .= '<div class="modal-dialog modal-lg">
	<div class="modal-content">
		<div class="modal-header">
			<button type="button" class="close" data-dismiss="modal">&times;</button>
			<h4 class="modal-title">LISTA - ORDENES DE COMPRA</h4>
		</div>
		<div class="modal-body">';
	$modal .= $sHtml;
	$modal .= '          </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-danger" data-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
             </div>';

	$oReturn->assign("ModalOrden", "innerHTML", $modal);
	$oReturn->script("init_table('tbordserv')");

	return $oReturn;
}

function genera_grid($aData = null, $aLabel = null, $sTitulo = 'Reporte', $iAncho = '400', $aAccion = null, $Totales = null, $aOrden = null)
{
	if (is_array($aData) && is_array($aLabel)) {
		$iLabel = count($aLabel);
		$iData = count($aData);
		$sClass = 'on';
		$sHtml = '';
		$sHtml .= '<form id="DataGrid">';
		$sHtml .= '<table class="table table-striped table-condensed table-bordered table-hover" style="width: 98%; margin-top: 20px;" align="center">';
		$sHtml .= '<tr class="info" ><td colspan="' . $iLabel . '">Su consulta genero ' . $iData . ' registros de resultado</td></tr>';
		$sHtml .= '<tr>';
		// Genera Columnas de Grid
		for ($i = 0; $i < $iLabel; $i++) {
			$sLabel = explode('|', $aLabel[$i]);
			if ($sLabel[1] == '')
				//				$sHtml .= '<th class="diagrama" align="center">'.$sLabel[0].'</th>';
				if ($i == 13) {
					$sHtml .= '<td class="fecha_letra" align="center" style="display:none">' . $sLabel[0] . '</th>';
				} else {
					$sHtml .= '<td class="fecha_letra" align="center">' . $sLabel[0] . '</th>';
				}
			else {
				if ($sLabel[1] == $aOrden[0]) {
					if ($aOrden[1] == 'ASC') {
						$sLabel[1] .= '|DESC';
						$sImg = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/ico_down.png" align="absmiddle" />';
					} else {
						$sLabel[1] .= '|ASC';
						$sImg = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/ico_up.png" align="absmiddle" />';
					}
				} else {
					$sImg = '';
					$sLabel[1] .= '|ASC';
				}

				$sHtml .= '<th onClick="xajax_' . $sLabel[2] . '(xajax.getFormValues(\'form1\'),\'' . $sLabel[1] . '\')" 
								style="cursor: hand !important; cursor: pointer !important;" >' . $sLabel[0] . ' ';
				$sHtml .= $sImg;
				$sHtml .= '</th>';
			}
		}
		$sHtml .= '</tr>';
		// Genera Filas de Grid

		for ($i = 0; $i < $iData; $i++) {
			if ($sClass == 'off')
				$sClass = 'on';
			else
				$sClass = 'off';

			$sHtml .= '<tr>';
			for ($j = 0; $j < $iLabel; $j++)
				if (is_float($aData[$i][$aLabel[$j]]))
					$sHtml .= '<td align="right">' . number_format($aData[$i][$aLabel[$j]], 2, ',', '.') . '</td>';
				else
					//				$sHtml .= '<td align="left">'.$aData[$i][$aLabel[$j]].'</td>';
					if ($j == 13) {
						$sHtml .= '<td align="left" style="display:none">' . $aData[$i][$aLabel[$j]] . '</td>';
					} else {
						$sHtml .= '<td align="left">' . $aData[$i][$aLabel[$j]] . '</td>';
					}
			$sHtml .= '</tr>';
		}

		//Totales 
		$sHtml .= '<tr class="danger">';
		if (is_array($Totales)) {
			for ($i = 0; $i < $iLabel; $i++) {
				if ($i == 0)
					$sHtml .= '<td class="fecha_letra">Totales</td>';
				else {
					if ($Totales[$i] == '')
						if ($Totales[$i] == '0.00')
							$sHtml .= '<td align="right" class="fecha_letra">' . number_format($Totales[$i], 2, ',', '.') . '</td>';
						else
							$sHtml .= '<td align="right"></td>';
					else
						$sHtml .= '<td align="right" class="fecha_letra">' . number_format($Totales[$i], 2, ',', '.') . '</td>';
				}
			}
		}

		$sHtml .= '</tr></table>';
		$sHtml .= '</form>';
	}






	return $sHtml;
}

function genera_grid_ret($aData = null, $aLabel = null, $sTitulo = 'Reporte', $iAncho = '400', $aAccion = null, $Totales = null, $aOrden = null)
{
	if (is_array($aData) && is_array($aLabel)) {
		$iLabel = count($aLabel);
		$iData = count($aData);
		$sClass = 'on';
		$sStyle = 'border:#999999 1px solid; padding:2px; width:' . $iAncho . '%';
		$sHtml = '';

		$sHtml .= '<form id="DataGrid">';
		$sHtml .= '<table align="left" border="0" class="table table-hover table-bordered table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">';
		$sHtml .= '<tr class="warning" ><td colspan="' . $iLabel . '">Su consulta genero ' . $iData . ' registros de resultado</td></tr>';
		$sHtml .= '<tr>';
		// Genera Columnas de Grid
		for ($i = 0; $i < $iLabel; $i++) {
			$sLabel = explode('|', $aLabel[$i]);
			if ($sLabel[1] == '')
				//	$sHtml .= '<th class="diagrama" align="center">'.$sLabel[0].'</th>';
				if ($i == 13) {
					$sHtml .= '<td  class="info" align="center" style="display:none">' . $sLabel[0] . '</th>';
				} else {
					$sHtml .= '<td  class="info" align="center">' . $sLabel[0] . '</th>';
				}
			else {
				if ($sLabel[1] == $aOrden[0]) {
					if ($aOrden[1] == 'ASC') {
						$sLabel[1] .= '|DESC';
						$sImg = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/ico_down.png" align="absmiddle" />';
					} else {
						$sLabel[1] .= '|ASC';
						$sImg = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/ico_up.png" align="absmiddle" />';
					}
				} else {
					$sImg = '';
					$sLabel[1] .= '|ASC';
				}

				$sHtml .= '<th onClick="xajax_' . $sLabel[2] . '(xajax.getFormValues(\'form1\'),\'' . $sLabel[1] . '\')"
								style="cursor: hand !important; cursor: pointer !important;" >' . $sLabel[0] . ' ';
				$sHtml .= $sImg;
				$sHtml .= '</th>';
			}
		}
		$sHtml .= '</tr>';
		// Genera Filas de Grid

		for ($i = 0; $i < $iData; $i++) {
			if ($sClass == 'off')
				$sClass = 'on';
			else
				$sClass = 'off';

			$sHtml .= '<tr class="' . $sClass . '"
							onMouseOver="javascript:this.className=\'link\';"
							onMouseOut="javascript:this.className=\'' . $sClass . '\';">';
			for ($j = 0; $j < $iLabel; $j++)
				if (is_float($aData[$i][$aLabel[$j]]))
					$sHtml .= '<td align="right">' . number_format($aData[$i][$aLabel[$j]], 2, ',', '.') . '</td>';
				else
					//				$sHtml .= '<td align="left">'.$aData[$i][$aLabel[$j]].'</td>';
					if ($j == 13) {
						$sHtml .= '<td align="left" style="display:none">' . $aData[$i][$aLabel[$j]] . '</td>';
					} else {
						$sHtml .= '<td align="left">' . $aData[$i][$aLabel[$j]] . '</td>';
					}
			$sHtml .= '</tr>';
		}

		//Totales
		$sHtml .= '<tr>';
		if (is_array($Totales)) {
			for ($i = 0; $i < $iLabel; $i++) {
				if ($i == 0)
					$sHtml .= '<th class="total_reporte">Totales</th>';
				else {
					if ($Totales[$i] == '')
						if ($Totales[$i] == '0.00')
							$sHtml .= '<th align="right" class="total_reporte">' . number_format($Totales[$i], 2, ',', '.') . '</th>';
						else
							$sHtml .= '<th align="right"></th>';
					else
						$sHtml .= '<th align="right" class="total_reporte">' . number_format($Totales[$i], 2, ',', '.') . '</th>';
				}
			}
		}

		$sHtml .= '</tr></table>';
		$sHtml .= '</form>';
	}
	return $sHtml;
}

function genera_grid_gen($aData = null, $aLabel = null, $sTitulo = 'Reporte', $iAncho = '400', $aAccion = null, $Totales = null, $aOrden = null)
{
	if (is_array($aData) && is_array($aLabel)) {
		$iLabel = count($aLabel);
		$iData = count($aData);
		$sClass = 'on';
		$sHtml = '';
		$sHtml .= '<form id="DataGrid">';
		$sHtml .= '<table class="table table-striped table-condensed" style="width: 98%; margin-bottom: 0px;">';
		$sHtml .= '<tr class="info" ><td colspan="' . $iLabel . '">Su consulta genero ' . $iData . ' registros de resultado</td></tr>';
		$sHtml .= '<tr>';
		// Genera Columnas de Grid
		for ($i = 0; $i < $iLabel; $i++) {
			$sLabel = explode('|', $aLabel[$i]);
			if ($sLabel[1] == '')
				//				$sHtml .= '<th class="diagrama" align="center">'.$sLabel[0].'</th>';
				if ($i == 13) {
					$sHtml .= '<td class="diagrama" align="center" style="display:none">' . $sLabel[0] . '</th>';
				} else {
					$sHtml .= '<td class="diagrama" align="center">' . $sLabel[0] . '</th>';
				}
			else {
				if ($sLabel[1] == $aOrden[0]) {
					if ($aOrden[1] == 'ASC') {
						$sLabel[1] .= '|DESC';
						$sImg = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/ico_down.png" align="absmiddle" />';
					} else {
						$sLabel[1] .= '|ASC';
						$sImg = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/ico_up.png" align="absmiddle" />';
					}
				} else {
					$sImg = '';
					$sLabel[1] .= '|ASC';
				}

				$sHtml .= '<th onClick="xajax_' . $sLabel[2] . '(xajax.getFormValues(\'form1\'),\'' . $sLabel[1] . '\')" 
								style="cursor: hand !important; cursor: pointer !important;" >' . $sLabel[0] . ' ';
				$sHtml .= $sImg;
				$sHtml .= '</th>';
			}
		}
		$sHtml .= '</tr>';
		// Genera Filas de Grid

		for ($i = 0; $i < $iData; $i++) {
			if ($sClass == 'off')
				$sClass = 'on';
			else
				$sClass = 'off';

			$sHtml .= '<tr class="warning">';
			for ($j = 0; $j < $iLabel; $j++)
				if (is_float($aData[$i][$aLabel[$j]]))
					$sHtml .= '<td align="right">' . number_format($aData[$i][$aLabel[$j]], 2, ',', '.') . '</td>';
				else
					//				$sHtml .= '<td align="left">'.$aData[$i][$aLabel[$j]].'</td>';
					if ($j == 13) {
						$sHtml .= '<td align="left" style="display:none">' . $aData[$i][$aLabel[$j]] . '</td>';
					} else {
						$sHtml .= '<td align="left">' . $aData[$i][$aLabel[$j]] . '</td>';
					}
			$sHtml .= '</tr>';
		}

		//Totales 
		$sHtml .= '<tr>';
		if (is_array($Totales)) {
			for ($i = 0; $i < $iLabel; $i++) {
				if ($i == 0)
					$sHtml .= '<th class="total_reporte">Totales</th>';
				else {
					if ($Totales[$i] == '')
						if ($Totales[$i] == '0.00')
							$sHtml .= '<th align="right" class="total_reporte">' . number_format($Totales[$i], 2, ',', '.') . '</th>';
						else
							$sHtml .= '<th align="right"></th>';
					else
						$sHtml .= '<th align="right" class="total_reporte">' . number_format($Totales[$i], 2, ',', '.') . '</th>';
				}
			}
		}

		$sHtml .= '</tr></table>';
		$sHtml .= '</form>';
	}
	return $sHtml;
}

function genera_grid_dir($aData = null, $aLabel = null, $sTitulo = 'Reporte', $iAncho = '400', $aAccion = null, $Totales = null, $aOrden = null)
{

	unset($arrayaDataGridVisible);
	unset($arrayaDataGridTipo);
	if ($sTitulo == 'DIRECTORIO') {

		$arrayaDataGridVisible[0] = 'S';
		$arrayaDataGridVisible[1] = 'S';
		$arrayaDataGridVisible[2] = 'S';
		$arrayaDataGridVisible[3] = 'S';
		$arrayaDataGridVisible[4] = 'S';
		$arrayaDataGridVisible[5] = 'S';
		$arrayaDataGridVisible[6] = 'S';
		$arrayaDataGridVisible[7] = 'S';
		$arrayaDataGridVisible[8] = 'S';
		$arrayaDataGridVisible[9] = 'S';
		$arrayaDataGridVisible[10] = 'S';
		$arrayaDataGridVisible[11] = 'S';
		$arrayaDataGridVisible[12] = 'N';
		$arrayaDataGridVisible[13] = 'S';
		$arrayaDataGridVisible[14] = 'S';


		$arrayaDataGridTipo[0] = 'N';	// ID 
		$arrayaDataGridTipo[1] = 'T';	// CLIENTE
		$arrayaDataGridTipo[2] = 'T';	// SUBCLIENTE
		$arrayaDataGridTipo[3] = 'T';	// TIPO
		$arrayaDataGridTipo[4] = 'T';	// FACTURA
		$arrayaDataGridTipo[5] = 'T';	// FEC VENCE
		$arrayaDataGridTipo[6] = 'N';	// DETALLE
		$arrayaDataGridTipo[7] = 'N';	// COTI
		$arrayaDataGridTipo[8] = 'N';	// DEB LOCAL
		$arrayaDataGridTipo[9] = 'N';	// CRE LOCAL
		$arrayaDataGridTipo[10] = 'N';  // DEB EXTR
		$arrayaDataGridTipo[11] = 'N';  // CRE EXTR
		$arrayaDataGridTipo[12] = 'I';  // MODIFICAR
		$arrayaDataGridTipo[13] = 'I';  // ELIMINAR
		$arrayaDataGridTipo[14] = 'N';
	} elseif ($sTitulo == 'DIARIO') {

		$arrayaDataGridVisible[0] = 'S';	//'Fila', 
		$arrayaDataGridVisible[1] = 'S';	//'Cuenta', 
		$arrayaDataGridVisible[2] = 'S';	//'Nombre', 
		$arrayaDataGridVisible[3] = 'S';	//'Documento', 
		$arrayaDataGridVisible[4] = 'S';	//'Cotizacion', 
		$arrayaDataGridVisible[5] = 'S';	//'Debito Moneda Local', 
		$arrayaDataGridVisible[6] = 'S';	//'Credito Moneda Local', 	
		$arrayaDataGridVisible[7] = 'S';	// DEBITO EXT
		$arrayaDataGridVisible[8] = 'S';	// CREDITO EXT
		$arrayaDataGridVisible[9] = 'S';	// 'Modificar', 
		$arrayaDataGridVisible[10] = 'S';   // 'Eliminar', 
		$arrayaDataGridVisible[11] = 'S';   // 'Beneficiario',   
		$arrayaDataGridVisible[12] = 'S';   // 'Cuenta Bancaria',     
		$arrayaDataGridVisible[13] = 'S';	// 'Cheque',    
		$arrayaDataGridVisible[14] = 'S';	// 'Fecha Venc',
		$arrayaDataGridVisible[15] = 'S';	// 'Formato Cheque', 
		$arrayaDataGridVisible[16] = 'S';	// 'Codigo Ctab', 
		$arrayaDataGridVisible[17] = 'S';	// 'Detalle',
		$arrayaDataGridVisible[18] = 'S';	// 'Centro Costo',   
		$arrayaDataGridVisible[19] = 'S';	// 'Centro Actividad'
		$arrayaDataGridVisible[20] = 'S';
		$arrayaDataGridVisible[21] = 'S';

		$arrayaDataGridTipo[0] = 'N';
		$arrayaDataGridTipo[1] = 'T';
		$arrayaDataGridTipo[2] = 'T';
		$arrayaDataGridTipo[3] = 'T';
		$arrayaDataGridTipo[4] = 'N';
		$arrayaDataGridTipo[5] = 'N';
		$arrayaDataGridTipo[6] = 'N';
		$arrayaDataGridTipo[7] = 'N';
		$arrayaDataGridTipo[8] = 'N';
		$arrayaDataGridTipo[9] = 'I';
		$arrayaDataGridTipo[10] = 'I';
		$arrayaDataGridTipo[11] = 'T';
		$arrayaDataGridTipo[12] = 'T';
		$arrayaDataGridTipo[13] = 'T';
		$arrayaDataGridTipo[14] = 'T';
		$arrayaDataGridTipo[15] = 'T';
		$arrayaDataGridTipo[16] = 'T';
		$arrayaDataGridTipo[17] = 'T';
		$arrayaDataGridTipo[18] = 'T';
		$arrayaDataGridTipo[19] = 'T';
		$arrayaDataGridTipo[20] = 'N';
		$arrayaDataGridTipo[21] = 'N';
	} elseif ($sTitulo == 'RETENCION') {

		$arrayaDataGridVisible[0] = 'S';
		$arrayaDataGridVisible[1] = 'S';
		$arrayaDataGridVisible[2] = 'S';
		$arrayaDataGridVisible[3] = 'S';
		$arrayaDataGridVisible[4] = 'S';
		$arrayaDataGridVisible[5] = 'S';
		$arrayaDataGridVisible[6] = 'S';
		$arrayaDataGridVisible[7] = 'S';
		$arrayaDataGridVisible[8] = 'S';
		$arrayaDataGridVisible[9] = 'S';
		$arrayaDataGridVisible[10] = 'S';
		$arrayaDataGridVisible[11] = 'S';
		$arrayaDataGridVisible[12] = 'S';
		$arrayaDataGridVisible[13] = 'S';
		$arrayaDataGridVisible[14] = 'S';
		$arrayaDataGridVisible[15] = 'N';
		$arrayaDataGridVisible[16] = 'S';
		$arrayaDataGridVisible[17] = 'S';

		$arrayaDataGridTipo[0] = 'N';
		$arrayaDataGridTipo[1] = 'T';
		$arrayaDataGridTipo[2] = 'T';
		$arrayaDataGridTipo[3] = 'T';
		$arrayaDataGridTipo[4] = 'T';
		$arrayaDataGridTipo[5] = 'N';
		$arrayaDataGridTipo[6] = 'N';
		$arrayaDataGridTipo[7] = 'N';
		$arrayaDataGridTipo[8] = 'T';
		$arrayaDataGridTipo[9] = 'T';
		$arrayaDataGridTipo[10] = 'N';
		$arrayaDataGridTipo[11] = 'N';
		$arrayaDataGridTipo[12] = 'N';
		$arrayaDataGridTipo[13] = 'N';
		$arrayaDataGridTipo[14] = 'N';
		$arrayaDataGridTipo[15] = 'I';
		$arrayaDataGridTipo[16] = 'I';
		$arrayaDataGridTipo[17] = 'N';
	}

	if (is_array($aData) && is_array($aLabel)) {
		$iLabel = count($aLabel);
		$iData = count($aData);
		$sHtml = '';
		$sHtml .= '<form id="DataGrid">';
		$sHtml .= '<table class="table table-striped table-condensed table-bordered" style="width: 100%; margin-top: 10px;" align="center">';
		$sHtml .= '<tr>';
		$sHtml .= '<td class="bg-primary" colspan="' . $iLabel . '">' . $sTitulo . '</td>';
		$sHtml .= '</tr>';
		$sHtml .= '<tr class="warning"><td colspan="' . $iLabel . '">Su consulta genero ' . $iData . ' registros de resultado</td></tr>';
		$sHtml .= '<tr>';

		// Genera Columnas de Grid
		for ($i = 0; $i < $iLabel; $i++) {
			$sLabel = explode('|', $aLabel[$i]);
			if ($sLabel[1] == '') {

				$aDataVisible = $arrayaDataGridVisible[$i];
				if ($aDataVisible == 'S') {
					$aDataVisible = '';
				} else {
					$aDataVisible = 'none;';
				}

				$sHtml .= '<td class="info" align="center" style="display: ' . $aDataVisible . '">' . $sLabel[0] . '</th>';
			} else {
				if ($sLabel[1] == $aOrden[0]) {
					if ($aOrden[1] == 'ASC') {
						$sLabel[1] .= '|DESC';
						$sImg = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/ico_down.png" align="absmiddle" />';
					} else {
						$sLabel[1] .= '|ASC';
						$sImg = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/ico_up.png" align="absmiddle" />';
					}
				} else {
					$sImg = '';
					$sLabel[1] .= '|ASC';
				}

				$sHtml .= '<th onClick="xajax_' . $sLabel[2] . '(xajax.getFormValues(\'form1\'),\'' . $sLabel[1] . '\')"
								style="cursor: hand !important; cursor: pointer !important;" >' . $sLabel[0] . ' ';
				$sHtml .= $sImg;
				$sHtml .= '</td>';
			}
		}
		$sHtml .= '</tr>';

		// Genera Filas de Grid
		for ($i = 0; $i < $iData; $i++) {
			$sHtml .= '<tr>';
			for ($j = 0; $j < $iLabel; $j++) {

				$campo = $aData[$i][$aLabel[$j]];

				$aDataVisible = $arrayaDataGridVisible[$j];
				if ($aDataVisible == 'S') {
					$aDataVisible = '';
				} else {
					$aDataVisible = 'none;';
				}

				$aDataTipo = $arrayaDataGridTipo[$j];
				$alignCampo = 'left';
				if ($aDataTipo == 'T') {
					$alignCampo = 'left';
				} elseif ($aDataTipo == 'N') {
					if (is_numeric($campo)) {
						$campo = number_format($campo, 2, '.', '');
					}
					$alignCampo = 'right';
				} elseif ($aDataTipo == 'I') {
					$alignCampo = 'center';
				}

				$sHtml .= '<td align="' . $alignCampo . '" style="display: ' . $aDataVisible . ';">' . $campo . '</td>';
			}
			$sHtml .= '</tr>';
		}

		//Totales
		$sHtml .= '<tr class="danger">';
		if (is_array($Totales)) {
			for ($i = 0; $i < $iLabel; $i++) {
				if ($i == 0)
					$sHtml .= '<td class="fecha_letra" align="right">TOTALES</td>';
				else {
					if ($Totales[$i] == '')
						if ($Totales[$i] == '0.00')
							$sHtml .= '<td align="right" class="fecha_letra">' . number_format($Totales[$i], 2, '.', ',') . '</td>';
						else
							$sHtml .= '<td align="right"></th>';
					else
						$sHtml .= '<td align="right" class="fecha_letra">' . number_format($Totales[$i], 2, '.', ',') . '</td>';
				}
			}
		}
		$sHtml .= '</tr>';

		//Saldos
		unset($_SESSION['ARRAY_SALDOS_TMP']);
		unset($arraySaldo);
		if ($sTitulo == 'DIARIO') {
			$sHtml .= '<tr class="danger">';
			$saldoDeb = 0;
			$saldoDeb_Ext = 0;
			$saldoCre = 0;
			$saldoCre_Ext = 0;
			if (is_array($Totales)) {
				$valDeb = 0;
				$valDeb_Ext = 0;
				$valCre = 0;
				$valCre_Ext = 0;
				for ($i = 0; $i < $iLabel; $i++) {
					if ($i == 5) {
						$valDeb += $Totales[$i];
					} elseif ($i == 6) {
						$valCre += $Totales[$i];
					} elseif ($i == 7) {
						$valDeb_Ext += $Totales[$i];
					} elseif ($i == 8) {
						$valCre_Ext += $Totales[$i];
					}
				} //fin for

				if ($valDeb > $valCre) {
					$saldoCre = $valCre - $valDeb;
					$arraySaldo[] = array('CR', $saldoCre);
				} elseif ($valDeb < $valCre) {
					$saldoDeb = round($valDeb - $valCre, 2);
					$arraySaldo[] = array('DB', $saldoDeb);
				}


				// MONDA Ext
				if ($valDeb_Ext > $valCre_Ext) {
					$saldoCre_Ext = round($valCre_Ext - $valDeb_Ext, 2);
				} elseif ($valDeb_Ext < $valCre_Ext) {
					$saldoDeb_Ext = round($valDeb_Ext - $valCre_Ext, 2);
				}

				$sHtml .= '<td class="fecha_letra" align="right">SALDO</td>';
				$sHtml .= '<td colspan="4"></td>';
				$sHtml .= '<td class="fecha_letra" align="right" >' . round($saldoDeb, 2) . '</td>';
				$sHtml .= '<td class="fecha_letra" align="right" >' . round($saldoCre, 2) . '</td>';
				$sHtml .= '<td class="fecha_letra" align="right" >' . round($saldoDeb_Ext, 2) . '</td>';
				$sHtml .= '<td class="fecha_letra" align="right" >' . round($saldoCre_Ext, 2) . '</td>';
				$sHtml .= '<td colspan="10"></td>';

				$sHtml .= '<input type="text" id="debitoLocal" name="debitoLocal" value="' . round($saldoDeb, 2) . '" style="display:none" >';
				$sHtml .= '<input type="text" id="creditoLocal" name="creditoLocal" value="' . round($saldoCre, 2) . '" style="display:none"  >';
				$sHtml .= '<input type="text" id="debitoExterior" name="debitoExterior" value="' . round($saldoDeb_Ext, 2) . '" style="display:none"  >';
				$sHtml .= '<input type="text" id="creditoExterior" name="creditoExterior" value="' . round($saldoCre_Ext, 2) . '" style="display:none"  >';
			}
			$_SESSION['ARRAY_SALDOS_TMP'] = $arraySaldo;
			$sHtml .= '</tr>';
		}
		$sHtml .= '</table>';
		$sHtml .= '</form>';
	}
	return $sHtml;
}

/* * **************************************************************** */
/* DF01 :: G E N E R A    F O R M U L A R I O    P E D I D O       */
/* * **************************************************************** */
function genera_formulario_pedido($cod = 0, $tmp = 0, $sAccion = 'nuevo', $aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();


	/*TIPO DE ORDEN DE COMRPRA*/

	$sqlgein = "SELECT count(*) as conteo
	FROM INFORMATION_SCHEMA.COLUMNS
	WHERE COLUMN_NAME = 'para_tip_oc' AND TABLE_NAME = 'saepara'";
	$ctralter = consulta_string($sqlgein, 'conteo', $oIfx, 0);
	if ($ctralter == 0) {
		$sqlalter = "ALTER TABLE saepara ADD para_tip_oc varchar(1) default '0';";
		$oIfx->QueryT($sqlalter);
	}


	///SOLICITUD DE PAGOS
	if ($cod != 0) {
		$sql = "select dpgs_cod_ccos, dpgs_pruc_dpgs,dpgs_vpag_dpgs from saedpgs where dpgs_cod_dpgs=$cod";

		$ruc = consulta_string($sql, 'dpgs_pruc_dpgs', $oCon, '');

		$pcodccos = consulta_string($sql, 'dpgs_cod_ccos', $oCon, '');
		$valpago = consulta_string($sql, 'dpgs_vpag_dpgs', $oCon, 0);

		$sqlp = "select clpv_cod_clpv from saeclpv where clpv_ruc_clpv='$ruc'";
		$pgclpv = consulta_string($sqlp, 'clpv_cod_clpv', $oCon, '');
	}



	unset($_SESSION['aDataGirdAdj']);
	unset($_SESSION['Print']);
	unset($_SESSION['DATOS_FACT_PROV']);
	unset($_SESSION['aDataGirdAdj_1']);


	$idempresa          = $_SESSION['U_EMPRESA'];
	$idsucursal         = $_SESSION['U_SUCURSAL'];
	$usuario_informix   = $_SESSION['U_USER_INFORMIX'];

	// D E T A L L E     D E S C R I P C I O N
	$aDataGrid     = $_SESSION['aDataGird'];
	$aDataGridDist = $_SESSION['aDataGirdDist'];

	$sql = "select empr_iva_empr,  * from saeempr where empr_cod_empr = $idempresa ";
	$iva = round(consulta_string_func($sql, 'empr_iva_empr', $oIfx, 0));

	$sql = "select pccp_num_digi from saepccp where pccp_cod_empr = $idempresa ";
	$pccp_num_digi = consulta_string_func($sql, 'pccp_num_digi', $oIfx, 0);
	unset($_SESSION['U_NUM_DIGI']);
	$_SESSION['U_NUM_DIGI'] = $pccp_num_digi;

	$empr_cod_pais = $_SESSION['U_PAIS_COD'];



	// IMPUESTOS POR PAIS
	$sql = "select p.impuesto, p.etiqueta, p.porcentaje, porcentaje2 from comercial.pais_etiq_imp p where
                p.pais_cod_pais = $empr_cod_pais ";
	unset($array_imp);
	unset($array_imp2);
	if ($oCon->Query($sql)) {
		if ($oCon->NumFilas() > 0) {
			do {
				$impuesto  	= $oCon->f('impuesto');
				$etiqueta 	= $oCon->f('etiqueta');
				$porcentaje = $oCon->f('porcentaje');
				$porcentaje2 = $oCon->f('porcentaje2');
				$array_imp[$impuesto] = $etiqueta;
				if ($porcentaje2 > 0) {
					$array_imp2[$impuesto] = $porcentaje2;
				}
			} while ($oCon->SiguienteRegistro());
		}
	}
	$oCon->Free();

	// VISIBLES
	$visible_ice = '';
	if (empty($array_imp['ICE'])) {
		// SEGUNDO IMPUESTOS
		$visible_ice = 'style="display: none"';
	}

	$visible_irbp = '';
	if (empty($array_imp['IRBP'])) {
		// SEGUNDO IMPUESTOS
		$visible_irbp = 'style="display: none"';
	}

	$fact_bien_sn = $_SESSION['U_PAIS_FACT_BIEN'];
	$fact_serv_sn = $_SESSION['U_PAIS_FACT_SERV'];

	$visible_bien_sn = '';
	if ($fact_bien_sn == 'N') {
		$visible_bien_sn = 'style="display: none"';
	}

	$visible_serv_sn = '';
	if ($fact_serv_sn == 'N') {
		$visible_serv_sn = 'style="display: none"';
	}

	//CODIGO PAIS
	$S_PAIS_API_SRI = $_SESSION['S_PAIS_API_SRI'];



	switch ($sAccion) {
		case 'nuevo':

			//PERU
			if ($S_PAIS_API_SRI == '51') {
				//TIPO DE RENTA PERU
				$campo_renta = '';


				// TABLA DE RENTA
				$sql_tipo_renta = "SELECT id, CONCAT(numero,' - ', descripcion) as detalle 
										from comercial.tipo_renta 
										order by 1 ";
				$lista_tip_renta = lista_boostrap($oIfx, $sql_tipo_renta, 0, 'id',  'detalle');

				// TABLA CONVENIOS DOBLE TRIBUTACION
				$sql_conv_doble_trib = "SELECT id, CONCAT(numero,' - ', descripcion) as detalle 
										from comercial.convenios_doble_tributacion 
										order by 1 ";
				$lista_conv_doble_trib = lista_boostrap($oIfx, $sql_conv_doble_trib, 0, 'id',  'detalle');

				// TABLA EXONERACION OPERACION NO DOMICILIADO
				$sql_exonera_oper_domi = "SELECT id, CONCAT(numero,' - ', descripcion) as detalle 
										from comercial.exoneracion_oper_domici 
										order by 1 ";
				$lista_exonera_oper_domi = lista_boostrap($oIfx, $sql_exonera_oper_domi, 0, 'id',  'detalle');

				$campo_renta = ' 
								<table id="no_domi10" name="no_domi10" class="table table-bordered table-hover table-striped table-condensed" style="margin-top: 30px; display: none">
									<tr>
										<td colspan="4" align="center" class="bg-primary" style="width: 60% !important;">DATOS NO DOMICILIADO</td>
									</tr>
									<tr>
										<td colspan="2">Tipo de Renta:</td>
										<td colspan="2">
											<select id="tip_renta" name="tip_renta" class="form-control input-sm" style="width:80%; text-align:left">
												<option value="">Seleccione una opcion..</option>
												' . $lista_tip_renta . '
											</select>
										</td>
									</tr>
									<tr>
										<td colspan="2">Convenios para evitar la doble tributacion:</td>
										<td colspan="2">
											<select id="conv_doble_tribu" name="conv_doble_tribu" class="form-control input-sm" style="width:80%; text-align:left">
												<option value="">Seleccione una opcion..</option>
												' . $lista_conv_doble_trib . '
											</select>
										</td>
									</tr>
									<tr>
										<td colspan="2">Exoneraciones de operaciones de no domiciliados:</td>
										<td colspan="2">
											<select id="exonera_opera_domici" name="exonera_opera_domici" class="form-control input-sm" style="width:80%; text-align:left">
												<option value="">Seleccione una opcion..</option>
												' . $lista_exonera_oper_domi . '
											</select>
										</td>
									</tr>
								</table>
								';

				$campo_renta .= ' 
								<table class="table table-bordered table-hover table-striped table-condensed" style="margin-top: 30px;">
									<tr>
										<td colspan="4" align="center" class="bg-primary" style="width: 60% !important;">CLASIFICACION DE LOS BIENES Y SERVICIOS ADQUIRIDOS</td>
									</tr>
									<tr>
										<td colspan="2">Bienes y Servicios</td>
										<td colspan="2">
											<select id="bienes_servicios_adquiridos" name="bienes_servicios_adquiridos" class="form-control input-sm" style="width:80%; text-align:left">
												<option value="">Seleccione una opcion..</option>

												<option value="1">Mercaderia, Materia Prima, Suministro, Envases y Embalajes</option>
												<option value="2">Adquisiciones de Activo FIjo</option>
												<option value="3">Otros activos no considerados en el numeral 2</option>
												<option value="4">Gastos de educacion, recreacion, salud, culturales, representacion, capacitacion de viaje, mantenimiento</option>
												<option value="5">Otros gastos no incluidos en el numeral 4</option>
												
											</select>
										</td>
									</tr>
								</table>
								';
			}


			// TIDU
			$sql = "select  tidu_cod_tidu from saetidu where
					tidu_cod_empr = $idempresa and
					tidu_cod_modu = 4 and
					tidu_tip_tidu = 'DI' and 
					tidu_cod_tidu = '021'";
			$tidu = consulta_string_func($sql, 'tidu_cod_tidu', $oIfx, '');

			// NUMERO DE RETENCION
			$anio = date("Y");
			$idprdo = date("m");
			$fecha_ejer = $anio . "-12-31";
			$sql = "select ejer_cod_ejer from saeejer where ejer_fec_finl = '$fecha_ejer' and ejer_cod_empr = $idempresa ";
			$idejer = consulta_string_func($sql, 'ejer_cod_ejer', $oIfx, 1);

			$sql = "select sucu_fac_elec from saesucu where sucu_cod_sucu = $idsucursal ";
			$sucu_fac_elec = consulta_string_func($sql, 'sucu_fac_elec', $oIfx, 'N');

			if ($sucu_fac_elec == 'S') {
				$tmp = " and retp_elec_sn = 'S'";
			} else {
				$tmp = " and retp_elec_sn = 'N'";
			}

			$sql = "select retp_sec_retp, retp_num_seri, retp_fech_cadu , retp_num_auto
							from saeretp where 
							retp_cod_empr = $idempresa and
							retp_cod_sucu = $idsucursal and
							retp_act_retp = 1 $tmp";
			//$oReturn->alert($sql);
			$num_rete     = consulta_string_func($sql, 'retp_sec_retp', $oIfx, '');
			$num_rete     = secuencial(2, '', $num_rete, 9);
			$seri_rete 	  = consulta_string_func($sql, 'retp_num_seri', $oIfx, '');
			$ret_fec_auto = consulta_string_func($sql, 'retp_fech_cadu', $oIfx, date("Y-m-d"));
			$rete_auto    = consulta_string_func($sql, 'retp_num_auto', $oIfx, '');

			//CAMPO CONTROL SOLICITU DE COMPRA
			// control
			$fu->AgregarCampoOculto('ctrlpedi', 'Control');
			$fu->cCampos["ctrlpedi"]->xValor = 0;

			//Consulta de Clientes
			$ifu->AgregarCampoTexto('proveedor', 'Suplidor|left', true, '', 254, 300);
			$ifu->AgregarComandoAlEscribir('proveedor', 'autocompletar(' . $idempresa . ', event );form1.ruc.value=form1.ruc.value.toUpperCase();');

			$ifu->AgregarCampoOculto('tipoRuc', '');

			$ifu->AgregarCampoTexto('ruc', 'Ruc|left', true, '', 170, 20);
			$ifu->AgregarComandoAlEscribir('ruc', 'autocompletar(' . $idempresa . ', event );form1.ruc.value=form1.ruc.value.toUpperCase();');

			$ifu->AgregarCampoTexto('cliente_nombre', 'Suplidor|left', true, '', 280, 200);
			$ifu->AgregarComandoAlEscribir('cliente_nombre', 'autocompletar(' . $idempresa . ', event );form1.cliente_nombre.value=form1.cliente_nombre.value.toUpperCase();');

			$ifu->AgregarCampoTexto('cliente', 'Proveedor|left', true, '', 50, 50);
			$ifu->AgregarComandoAlPonerEnfoque('cliente', 'this.blur()');
			$ifu->AgregarComandoAlCambiarValor('cliente', 'cargar_datos()');

			$ifu->AgregarCampoListaSQL('sust_trib', 'Sus. Tributario|left', "", true, 200, 300);
			$ifu->AgregarComandoAlCambiarValor('sust_trib', 'cargarTipoComprobante()');

			$ifu->AgregarCampoListaSQL('comprobante', 'Comprobante|left', "", true, 170, 150);
			$ifu->AgregarComandoAlCambiarValor('comprobante', 'cargar_reemb();');

			$ifu->AgregarCampoFecha('fecha_emision', 'Emision|left', true, date('d') . '-' . date('m') . '-' . date('Y'));
			$ifu->AgregarCampoFecha('fecha_contable', 'Contable|left', true, date('d') . '-' . date('m') . '-' . date('Y'));
			$ifu->AgregarCampoFecha('fecha_vence', 'Vence|left', true, date('d') . '-' . date('m') . '-' . date('Y'));
			$ifu->AgregarCampoFecha('fecha_retencion', 'Retencion|left', false, '');

			$ifu->AgregarCampoOculto('claveAcceso', '');

			$ifu->AgregarCampoLista('tipo_factura', 'T. Factura|left', true, 170, 100);
			$ifu->AgregarOpcionCampoLista('tipo_factura', 'ELECTRONICA', 1);
			$ifu->AgregarOpcionCampoLista('tipo_factura', 'PREIMPRESA', 2);
			$ifu->AgregarComandoAlCambiarValor('tipo_factura', 'cargar_factura()');

			$ifu->AgregarCampoNumerico('plazo', 'No Plazo|left', false, 0, 40, 9);
			$ifu->AgregarComandoAlEscribir('plazo', 'calculaFechaVence();');

			$ifu->AgregarCampoMemo('detalle', 'Detalle|left', true, '', 650, 40);
			$ifu->AgregarComandoAlEscribir('detalle', 'this.value=this.value.toUpperCase()');

			$ifu->AgregarCampoCheck('rise', 'Rise|left', false, 'N');
			$ifu->AgregarCampoCheck('doble_trib', 'Doble Tributacion|left', false, 'N');
			$ifu->AgregarCampoCheck('suje_rete', 'Pago al Exter. Sujeto a Ret.|left', false, 'N');

			$ifu->AgregarCampoListaSQL('sucursal', 'Sucursal|left', "select sucu_cod_sucu, sucu_nom_sucu from saesucu where 
												sucu_cod_empr = $idempresa and sucu_cod_sucu = $idsucursal", true, 170, 150);
			$sql = "select sucu_cod_sucu, sucu_nom_sucu from saesucu where sucu_cod_empr = $idempresa";
			$lista_sucu = lista_boostrap($oIfx, $sql, $idsucursal, 'sucu_cod_sucu',  'sucu_nom_sucu');


			$ifu->AgregarCampoLista('tipo_doc', 'Tipo|LEFT', true, 170, 100);
			$sql = "select tidu_cod_tidu, tidu_des_tidu
					from saetidu where
					tidu_cod_empr = $idempresa and
					tidu_cod_modu = 4 ";
			$lista_tipo_doc = lista_boostrap($oIfx, $sql, $tidu, 'tidu_cod_tidu',  'tidu_des_tidu');
			/*if ($oIfx->Query($sql)) {
				if ($oIfx->NumFilas() > 0) {
					do {
						$tidu_cod_tidu = $oIfx->f('tidu_cod_tidu');
						$tidu_des_tidu = $oIfx->f('tidu_des_tidu');
						$ifu->AgregarOpcionCampoLista('tipo_doc', $tidu_cod_tidu.'-'.$tidu_des_tidu, $tidu_cod_tidu);
					} while ($oIfx->SiguienteRegistro());
				}
			}
			$oIfx->Free();
			*/
			$ifu->AgregarComandoAlCambiarValor('tipo_doc', 'num_rete();');

			$ifu->AgregarCampoListaSQL('empl_soli', 'Solicita|left', "select empl_cod_empl, empl_ape_nomb from saeempl where
                                                                                            empl_cod_empr = $idempresa ", false, 170, 150);
			$ifu->AgregarCampoListaSQL('empl_auto', 'Autoriza|left', "select empl_cod_empl, empl_ape_nomb from saeempl where
                                                                                            empl_cod_empr = $idempresa ", false, 170, 150);


			$ifu->AgregarCampoListaSQL('tipo_pago', 'Tipo Pago|left', "select tpago_cod_tpago,
                                                                                        (saetpago.tpago_cod_tpago||' '||saetpago.tpago_des_tpago) as tipo_pago
                                                                                        from saetpago where
                                                                                        tpago_cod_empr = $idempresa ", false, 150, 150);
			$ifu->AgregarComandoAlCambiarValor('tipo_pago', 'check_pago();');

			$ifu->AgregarCampoListaSQL('forma_pago', 'Forma Pago|left', "SELECT saefpagop.fpagop_cod_fpagop,
																			(saefpagop.fpagop_cod_fpagop||' '||saefpagop.fpagop_des_fpagop) as fpagop
																			FROM saefpagop   where
																			fpagop_cod_empr = $idempresa ", false, 150, 150);

			$sql = "SELECT saefpagop.fpagop_cod_fpagop,
						(saefpagop.fpagop_cod_fpagop||' '||saefpagop.fpagop_des_fpagop) as fpagop
						FROM saefpagop   where
						fpagop_cod_empr = $idempresa ";
			$lista_forma_pago = lista_boostrap($oIfx, $sql, '', 'fpagop_cod_fpagop',  'fpagop');

			//regimen fiscal - pais exterior
			$ifu->AgregarCampoCheck('regimen_fiscal', 'Regimen Fiscal|left', true, 'N');
			$ifu->AgregarCampoCheck('doble_tributac', 'Doble Tributacion|left', true, 'N');
			$ifu->AgregarCampoCheck('pago_sujeto_ret', 'Pago Ext. Sujeto a Ret.|left', true, 'N');

			// DATOS FACTURA PROVEEDOR
			// BIENES
			$ifu->AgregarCampoNumerico('valor_grab12b', 'Valor ' . $iva . '%|left', true, 0, 70, 10);
			$ifu->AgregarComandoAlCambiarValor('valor_grab12b', 'totales(this)');
			$ifu->AgregarCampoNumerico('valor_grab0b', 'Valor 0%|left', true, 0, 70, 10);
			$ifu->AgregarComandoAlCambiarValor('valor_grab0b', 'totales(this)');
			$ifu->AgregarCampoNumerico('iceb', '' . $array_imp['ICE'] . '|left', true, 0, 70, 10);
			$ifu->AgregarComandoAlCambiarValor('iceb', 'totales(this)');
			$ifu->AgregarCampoNumerico('ivab', '' . $array_imp['IVA'] . '|left', true, 0, 70, 10);
			$ifu->AgregarComandoAlPonerEnfoque('ivab', 'this.blur(this)');
			$ifu->AgregarCampoNumerico('ivabp', '' . $array_imp['IVA'] . ' %|left', true, $iva, 70, 10);

			// SERVICIOS
			$ifu->AgregarCampoNumerico('valor_grab12s', 'Valor ' . $iva . '%|left', true, 0, 70, 10);
			$ifu->AgregarComandoAlCambiarValor('valor_grab12s', 'totales1(this)');
			$ifu->AgregarCampoNumerico('valor_grab0s', 'Valor 0%|left', true, 0, 70, 10);
			$ifu->AgregarComandoAlCambiarValor('valor_grab0s', 'totales1(this)');
			$ifu->AgregarCampoNumerico('ices', '' . $array_imp['ICE'] . '|left', true, 0, 70, 10);
			$ifu->AgregarComandoAlCambiarValor('ices', 'totales(this)');
			$ifu->AgregarCampoNumerico('ivas', '' . $array_imp['IVA'] . '|left', true, 0, 70, 10);
			$ifu->AgregarCampoNumerico('totals', 'Total|left', true, 0, 70, 10);

			// TOTAL
			$ifu->AgregarCampoNumerico('valor_grab12t', 'Valor ' . $iva . '%|left', true, 0, 70, 10);
			$ifu->AgregarCampoNumerico('valor_grab0t', 'Valor 0%|left', true, 0, 70, 9);
			$ifu->AgregarCampoNumerico('icet', '' . $array_imp['ICE'] . '|left', true, 0, 70, 10);
			$ifu->AgregarCampoNumerico('ivat', '' . $array_imp['IVA'] . '|left', true, 0, 70, 10);
			$ifu->AgregarCampoNumerico('valor_noObjIva', 'No Objeto Iva|left', true, 0, 70, 10);
			$ifu->AgregarCampoNumerico('valor_exentoIva', 'Exento Iva|left', true, 0, 70, 10);
			$ifu->AgregarCampoCheck('no_obj_iva', 'Monto no Objeto Iva|left', true, 'N');

			// DATOS RETENCION EMPRESA
			$ifu->AgregarCampoTexto('num_rete', 'Retencion|left', true, $num_rete, 100, 100);
			$fu->AgregarCampoSi_No('electronica', 'Electronica|left', $sucu_fac_elec);
			$fu->AgregarComandoAlCambiarValor('electronica', 'cargar_electronica();');
			$ifu->AgregarComandoAlCambiarValor('num_rete', 'num_digito(1)');


			$ifu->AgregarCampoTexto('serie_rete', 'Serie|left', true, $seri_rete, 50, 100);
			$ifu->AgregarCampoTexto('auto_rete', 'Autorizacion|left', true, $rete_auto, 200, 100);
			$ifu->AgregarCampoTexto('cad_rete', 'Caducidad|left', true, $ret_fec_auto, 70, 100);

			// DATOS RETENCION PROVEEDOR
			// BIENES
			$ifu->AgregarCampoTexto('codigo_ret_fue_b', 'Codigo|left', true, '', 40, 50);
			$ifu->AgregarComandoAlEscribir('codigo_ret_fue_b', 'cod_ret_b( 0,  event, 0 )');
			$ifu->AgregarCampoNumerico('porc_ret_fue_b', 'Porc|left', true, 0, 40, 10);
			$ifu->AgregarCampoNumerico('base_fue_b', 'Base|left', true, 0, 70, 10);
			$ifu->AgregarCampoNumerico('val_fue_b', 'Valor Retenido|left', true, 0, 70, 10);

			// SERVICIOS
			$ifu->AgregarCampoTexto('codigo_ret_fue_s', 'Codigo|left', true, '', 40, 50);
			$ifu->AgregarComandoAlEscribir('codigo_ret_fue_s', 'cod_ret_b( 1, event, 0 )');
			$ifu->AgregarCampoNumerico('porc_ret_fue_s', 'Porc|left', true, 0, 40, 10);
			$ifu->AgregarCampoNumerico('base_fue_s', 'Base|left', true, 0, 70, 10);
			$ifu->AgregarCampoNumerico('val_fue_s', 'Valor Retenido|left', true, 0, 70, 10);

			//RETENCION IVA BIENES
			$ifu->AgregarCampoNumerico('codigo_ret_iva_b', 'Ret. Fuente Bienes|left', true, '', 40, 10);
			$ifu->AgregarComandoAlEscribir('codigo_ret_iva_b', 'cod_ret_iva( 0, event, 1 )');
			$ifu->AgregarCampoNumerico('porc_ret_iva_b', 'Ret. Fuente Bienes|left', true, 0, 40, 10);
			$ifu->AgregarCampoNumerico('base_iva_b', 'Ret. Fuente Bienes|left', true, 0, 70, 10);
			$ifu->AgregarCampoNumerico('val_iva_b', 'Ret. Fuente Bienes|left', true, 0, 70, 10);

			//RETENCION IVA SERVICIOS
			$ifu->AgregarCampoNumerico('codigo_ret_iva_s', 'Ret. Fuente Bienes|left', true, '', 40, 50);
			$ifu->AgregarComandoAlEscribir('codigo_ret_iva_s', 'cod_ret_iva( 1, event, 1 )');
			$ifu->AgregarCampoNumerico('porc_ret_iva_s', 'Ret. Fuente Bienes|left', true, 0, 40, 50);
			$ifu->AgregarCampoNumerico('base_iva_s', 'Ret. Fuente Bienes|left', true, 0, 70, 20);
			$ifu->AgregarCampoNumerico('val_iva_s', 'Ret. Fuente Bienes|left', true, 0, 70, 20);

			// CUENTAS CONTABLES
			$ifu->AgregarCampoTexto('fisc_b', 'Credito Fiscal Bienes|left', false, '', 100, 100);
			$ifu->AgregarComandoAlEscribir('fisc_b', 'cuenta_fisc( 0, event )');
			//$ifu->AgregarComandoAlPonerEnfoque('fisc_b','this.blur()');

			$ifu->AgregarCampoTexto('fisc_s', 'Credito Fiscal Servicios|left', false, '', 100, 100);
			$ifu->AgregarComandoAlEscribir('fisc_s', 'cuenta_fisc( 1, event )');
			//$ifu->AgregarComandoAlPonerEnfoque('fisc_s','this.blur()');

			$ifu->AgregarCampoTexto('gasto1', 'Gasto|left', false, '', 100, 100);
			$ifu->AgregarComandoAlEscribir('gasto1', 'cuenta_gasto( 0, event )');

			$ifu->AgregarCampoNumerico('val_gasto1', 'Valor|left', true, 0, 80, 50);

			$ifu->AgregarCampoTexto('gasto2', 'Gasto2|left', false, '', 100, 100);
			$ifu->AgregarComandoAlEscribir('gasto2', 'cuenta_gasto( 1, event )');

			$ifu->AgregarCampoNumerico('val_gasto2', 'Valor|left', false, 0, 80, 50);

			$ifu->AgregarCampoListaSQL('responsable', 'Responsable|left', "select  p.pcch_cod_empl, e.empl_ape_nomb from saepcch p , saeempl e where
                                                                                            e.empl_cod_empl = p.pcch_cod_empl and
                                                                                            p.pcch_cod_empr = $idempresa and
                                                                                            e.empl_cod_empr = $idempresa order by 2 ", false, 'auto');
			$ifu->AgregarCampoTexto('secuencial', 'Secuencial|left', false, '', 100, 100);

			$op = '';
			unset($_SESSION['aDataGird']);
			$cont = count($aDataGird);
			if ($cont > 0) {
				$sHtml2 = mostrar_grid();
			} else {
				$sHtml2 = "";
			}

			$oReturn->assign("divFormularioDetalle", "innerHTML", $sHtml2);
			$oReturn->assign("divFormularioReportePrueba", "innerHTML", $sHtml2);
			$oReturn->assign("divTotal", "innerHTML", "");

			unset($_SESSION['aDataGirdDist']);
			$cont = count($aDataGirdDist);
			if ($cont > 0) {
				$sHtml2 = mostrar_grid_dist();
			} else {
				$sHtml2 = "";
			}


			// control
			$fu->AgregarCampoOculto('ctrl', 'Control');
			$fu->AgregarCampoTexto('fprv_cod_fprv', 'Fprv|left', false, '', 100, 100);
			$fu->cCampos["ctrl"]->xValor = 1;
			$ifu->cCampos["sucursal"]->xValor = $idsucursal;
			$ifu->cCampos["tipo_doc"]->xValor = $tidu;

			// COMPENSACION DEL IVA
			$fu->AgregarCampoNumerico('no_objeto_iva', 'Valor No Objeto Iva|left', false, 0, 70, 10);
			$fu->AgregarCampoNumerico('exento_iva', 'Valor Exento Iva|left', false, 0, 70, 10);
			$fu->AgregarCampoNumerico('total_sin_imp', '|left', false, 0, 70, 10);
			$fu->AgregarCampoNumerico('comp_iva', '|left', false, 0, 70, 10);
			$fu->AgregarCampoNumerico('comp_2', 'Comp. 2%|left', false, 0, 60, 10);
			$fu->AgregarComandoAlCambiarValor('comp_2', 'tot_compen();');
			$fu->AgregarCampoNumerico('comp_3', 'Comp. 3%|left', false, 0, 60, 10);
			$fu->AgregarComandoAlCambiarValor('comp_3', 'tot_compen();');
			$fu->AgregarCampoNumerico('comp_4', 'Comp. 4%|left', false, 0, 60, 10);
			$fu->AgregarComandoAlCambiarValor('comp_4', 'tot_compen();');
			$fu->AgregarCampoNumerico('total_comp', 'Total|left', false, 0, 60, 10);
			$fu->AgregarCampoNumerico('total_comp_iva', '|left', false, 0, 60, 10);

			$sql = "select   ftrn_cod_ftrn   from saeftrn where
                            ftrn_cod_modu = 4 and
                            ftrn_tip_movi = 'DI' ";
			$ftrn_cod = consulta_string_func($sql, 'ftrn_cod_ftrn', $oIfx, '8');
			$ifu->AgregarCampoListaSQL('formato', 'Formato|left', "select ftrn_cod_ftrn, ftrn_des_ftrn 
                                                                        from saeftrn where
                                                                        ftrn_cod_empr = $idempresa and
                                                                        ftrn_cod_modu = 4 ", true, 170, 150);
			$ifu->cCampos["formato"]->xValor = $ftrn_cod;

			$sql = "select ftrn_cod_ftrn, ftrn_des_ftrn 
						from saeftrn where
						ftrn_cod_empr = $idempresa and
						ftrn_cod_modu = 4 ";
			$lista_formato = lista_boostrap($oIfx, $sql, $ftrn_cod, 'ftrn_cod_ftrn',  'ftrn_des_ftrn');

			//ASIENTO CONTABLE
			$fu->AgregarCampoTexto('asto_cod', 'Asiento|left', false, '', 100, 100);
			$fu->AgregarCampoTexto('ejer_cod', 'Ejericio|left', false, '', 100, 100);
			$fu->AgregarCampoTexto('prdo_cod', 'Periodo|left', false, '', 100, 100);

			$fu->AgregarCampoListaSQL('planilla', 'Planilla|left', "", false, 170, 150);
			$fu->AgregarComandoAlCambiarValor('planilla', 'cargarValoresPlanillaClpv()');

			$fu->AgregarCampoListaSQL('proyecto', 'Proyecto|left', "select id, proyecto from proyectos where id_empresa = $idempresa and estado != 3", false, 170, 150);
			$fu->AgregarComandoAlCambiarValor('proyecto', 'cargarActividades()');


			// Verificamos si existe la tabla
			$sql_existe_tabla = "SELECT count(table_name) as contador FROM information_schema.columns 
									WHERE table_name='proyectos' 
									AND table_schema = 'public'";
			$existe_tabla = consulta_string($sql_existe_tabla, 'contador', $oIfx, 0);
			if ($existe_tabla > 0) {
				$sql = "SELECT id, proyecto from proyectos where id_empresa = $idempresa";
				$lista_proyecto = lista_boostrap($oCon, $sql, '', 'id',  'proyecto');
			}

			// Verificamos si existe la tabla
			$sql_existe_tabla = "SELECT count(table_name) as contador FROM information_schema.columns 
									WHERE table_name='proyecto_pymes' 
									AND table_schema = 'public'";
			$existe_tabla = consulta_string($sql_existe_tabla, 'contador', $oIfx, 0);
			if ($existe_tabla > 0) {
				$sql = "SELECT id, CONCAT(codigo || ' - ' || nombre) AS nombre  from proyecto_pymes where estado = 'A'";
				$lista_marco_logico = lista_boostrap($oCon, $sql, '', 'id',  'nombre');
			}




			$sql = "select id, nombre from comercial.plantilla_clpv where id_empresa = $idempresa";
			$lista_planilla = lista_boostrap($oCon, $sql, '', 'id',  'nombre');

			$fu->AgregarCampoListaSQL('actividad_proy', 'Actividad|left', "", false, 140, 150);

			$fu->AgregarCampoOculto('clpvCompania', '');

			unset($_SESSION['aDataGirdFp']);

			$etiqueta_retencion = $array_imp['RETENCION'];
			if (empty($etiqueta_retencion)) {
				$etiqueta_retencion = 'Retencion';
			}


			$ifu->AgregarCampoSi_No('ret_asumido', '' . $etiqueta_retencion . ' Asumida|left', 'N');
			$ifu->AgregarComandoAlCambiarValor('ret_asumido', 'genera_comprobante()');

			$ifu->AgregarCampoSi_No('sec_automatico', 'Secuencial Factura Auto.|left', 'N');

			$ifu->AgregarCampoTexto('factura_afectar', 'Factura a Afectar|left', false, '', 150, 200);
			$ifu->AgregarComandoAlEscribir('factura_afectar', 'facturas(' . $idempresa . ', event );');

			$ifu->AgregarCampoNumerico('total_inciva', 'Valor %|left', true, 0, 70, 10);
			$ifu->AgregarComandoAlCambiarValor('total_inciva', 'totales_inciva(this)');


			/***************************** DIRECCTORIO ****************************/
			unset($_SESSION['aDataGirdRete']);
			$oReturn->assign("divFormularioDetalleRET", "innerHTML", '');

			/******************************************************/
			// D I A R I O       C O N T A B L E
			/******************************************************/
			unset($_SESSION['aDataGirdDir']);
			$oReturn->assign("divDir", "innerHTML", "");

			unset($_SESSION['aDataGirdDiar']);
			$oReturn->assign("divDiario", "innerHTML", "");

			unset($_SESSION['aDataGirdRet']);
			$oReturn->assign("divRet", "innerHTML", "");

			// RETENCION
			$ifu->AgregarCampoTexto('cod_ret', 'Cta.|left', false, '', 100, 200);
			$ifu->AgregarComandoAlEscribir('cod_ret', 'cod_retencion(' . $idempresa . ', event );');
			$ifu->AgregarCampoNumerico('ret_porc', 'Porc.(%)|left', false, '', 50, 50);
			$ifu->AgregarCampoNumerico('ret_base', 'Base Imponible|left', false, '', 70, 200);
			$ifu->AgregarCampoNumerico('ret_val', 'Valor|left', false, '', 70, 200);
			$ifu->AgregarCampoTexto('cta_deb', 'Debito|left', false, '', 50, 200);
			$ifu->AgregarCampoTexto('cta_cre', 'Credito|left', false, '', 50, 200);
			$ifu->AgregarCampoTexto('tipo', 'tipo|left', false, '', 50, 200);

			// CUENTAS DASI
			$ifu->AgregarCampoOculto('cod_cta', '');
			$ifu->AgregarCampoTexto('nom_cta', 'Nombre Cta|left', false, '', 150, 200);
			$ifu->AgregarComandoAlEscribir('nom_cta', 'auto_dasi(' . $idempresa . ', event, 0 );form1.nom_cta.value=form1.nom_cta.value.toUpperCase();');
			$ifu->AgregarCampoNumerico('val_cta', 'Valor|left', false, '', 100, 200);

			$ifu->AgregarCampoLista('crdb', 'Tipo|left', false, 'auto');
			$ifu->AgregarOpcionCampoLista('crdb', 'CREDITO', 'CR');
			$ifu->AgregarOpcionCampoLista('crdb', 'DEBITO', 'DB');
			$ifu->AgregarCampoTexto('documento', 'Documento|left', false, '', 80, 10);
			$ifu->cCampos["crdb"]->xValor = 'DB';


			//ASIENTO CONTABLE
			$fu->AgregarCampoTexto('ejer_cod', 'Ejericio|left', false, '', 100, 100);
			$fu->AgregarCampoTexto('prdo_cod', 'Periodo|left', false, '', 100, 100);

			$sql      = "select pcon_mon_base, pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
			$mone_cod = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');
			$ifu->cCampos["moneda"]->xValor = $mone_cod;

			// MONEDA
			$ifu->AgregarCampoListaSQL('moneda', 'Moneda|left', "select mone_cod_mone, mone_des_mone  from saemone where mone_cod_empr = $idempresa ", true, 150, 150);
			$sql = "select mone_cod_mone, mone_des_mone  from saemone where mone_cod_empr = $idempresa ";
			$lista_moneda = lista_boostrap($oIfx, $sql, $mone_cod, 'mone_cod_mone',  'mone_des_mone');

			$ifu->AgregarComandoAlCambiarValor('moneda', 'cargar_coti();');

			$ifu->AgregarCampoNumerico('cotizacion_ext', 'Cotizacion Ext.|left', false, 1, 70, 9);

			// COTIZACION MONEDA EXTRANJERA
			$fech = date('Y-m-d');
			$sql      = "select pcon_mon_base, pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
			$mone_extr = consulta_string_func($sql, 'pcon_seg_mone', $oIfx, '');
			$sql = "select tcam_valc_tcam from saetcam where
						mone_cod_empr = $idempresa and
						tcam_cod_mone = $mone_extr and
						tcam_fec_tcam in (
											select max(tcam_fec_tcam)  from saetcam where
													mone_cod_empr = $idempresa and
													tcam_cod_mone = $mone_extr and tcam_fec_tcam<='$fech'
										)  ";
			//echo $sql;exit;
			$coti = consulta_string_func($sql, 'tcam_valc_tcam', $oIfx, 0);
			$ifu->cCampos["cotizacion_ext"]->xValor = $coti;

			$ifu->AgregarCampoNumerico('cotizacion', 'Cotizacion|left', false, 1, 70, 9);

			$ifu->AgregarCampoTexto('detalla_diario', 'Detalle|left', false, '', 170, 200);

			// CENTRO COSTOS 
			$ifu->AgregarCampoListaSQL('ccosn', 'Centro Costo|left', "select ccosn_cod_ccosn, ccosn_nom_ccosn || ' - ' || ccosn_cod_ccosn from saeccosn where
																			ccosn_cod_empr  = $idempresa and
																			ccosn_mov_ccosn = 1
																			order by 1 ", false, 150, 150);
			$sql = "select ccosn_cod_ccosn, ( ccosn_cod_ccosn || ' - ' || ccosn_nom_ccosn) as ccosn_nom from saeccosn where
						ccosn_cod_empr  = $idempresa and
						ccosn_mov_ccosn = 1
						order by 1 ";
			$lista_ccosn = lista_boostrap($oIfx, $sql, '', 'ccosn_cod_ccosn',  'ccosn_nom');

			// CENTRO DE ACTIVIDAD
			$ifu->AgregarCampoListaSQL('actividad', 'Centro Actividad|left', "select cact_cod_cact , cact_nom_cact ||  ' - ' || cact_cod_cact from saecact where
																					cact_cod_empr = $idempresa order by 2 ", false, 150, 150);

			$sql = "select cact_cod_cact , ( cact_nom_cact ||  ' - ' || cact_cod_cact ) as ccta from saecact where
							cact_cod_empr = $idempresa order by 2 ";
			$lista_act = lista_boostrap($oIfx, $sql, '', 'cact_cod_cact',  'ccta');


			//  /**************** DIRECTORIO *****************************/
			$ifu->AgregarCampoTexto('clpv_nom', 'Cliente - Proveedor|left', false, '', 200, 200);
			$ifu->AgregarComandoAlEscribir('clpv_nom', 'autocompletar_clpv_dir(' . $idempresa . ', event, 1 );form1.clpv_nom.value=form1.clpv_nom.value.toUpperCase();');

			$ifu->AgregarCampoOculto('clpv_cod', '');

			$ifu->AgregarCampoLista('tran', 'Tipo|left', false, 170, 150);
			$sql = "select tran_cod_tran, tran_des_tran, trans_tip_tran from saetran where
						tran_cod_empr = $idempresa and
						tran_cod_sucu = $idsucursal and
						tran_cod_modu = 4 order by 2 ";
			if ($oIfx->Query($sql)) {
				if ($oIfx->NumFilas() > 0) {
					do {
						$tran_des = $oIfx->f('tran_cod_tran') . ' || ' . $oIfx->f('tran_des_tran') . ' || ' . $oIfx->f('trans_tip_tran');
						$ifu->AgregarOpcionCampoLista('tran', $tran_des, $oIfx->f('tran_cod_tran'));
					} while ($oIfx->SiguienteRegistro());
				}
			}
			$oIfx->Free();

			$sql = "select pccp_aut_pago from saepccp where pccp_cod_empr = $idempresa ";
			$tran_cod_tran = consulta_string_func($sql, 'pccp_aut_pago', $oIfx, 0);
			$ifu->cCampos["tran"]->xValor = $tran_cod_tran;

			$sql = "select tran_cod_tran, tran_des_tran, trans_tip_tran from saetran where
						tran_cod_empr = $idempresa and
						tran_cod_sucu = $idsucursal and
						tran_cod_modu = 4 order by 2 ";
			$lista_tran = lista_boostrap($oIfx, $sql, $tran_cod_tran, 'tran_cod_tran',  'tran_des_tran');

			$ifu->AgregarCampoTexto('factura_dir', 'Factura|left', false, '', 140, 200);
			$ifu->AgregarCampoTexto('fact_valor', 'Valor|left', false, 0, 100, 100);
			$ifu->AgregarComandoAlCambiarValor('fact_valor', 'mascara(this,cpf)');
			$ifu->AgregarComandoAlEscribir('fact_valor', 'enter_dir(event);');

			$ifu->AgregarCampoTexto('det_dir', 'Detalle|left', false, '', 200, 100);

			$sHtml_dir = '';
			$sHtml_ret = '';
			$sHtml_dasi = '';

			// DIRECTORIO
			$sHtml_dir .= '<table class="table table-bordered table-hover" align="left" cellpadding="0" cellspacing="2" width="100%" border="0">';
			$sHtml_dir .= '<tr>
							<td bgcolor="#F2F2F2">' . $ifu->ObjetoHtmlLBL('clpv_nom') . '</td>
							<td bgcolor="#F2F2F2">' . $ifu->ObjetoHtmlLBL('tran') . '</td>
							<td bgcolor="#F2F2F2">' . $ifu->ObjetoHtmlLBL('det_dir') . '</td>	
							<td bgcolor="#F2F2F2">' . $ifu->ObjetoHtmlLBL('factura_dir') . '</td>						
							<td bgcolor="#F2F2F2">' . $ifu->ObjetoHtmlLBL('fact_valor') . '</td> 
							<td bgcolor="#F2F2F2" align="center"></td>
						</tr>';

			$sHtml_dir .= '<tr>
							<td>
								<input type="text" class="form-control input-sm" id="clpv_nom" name="clpv_nom" 
										onkeyup="autocompletar_clpv_dir(' . $idempresa . ', event , 1 );form1.clpv_nom.value=form1.clpv_nom.value.toUpperCase(); " 
										style="width:280px; height:25px; text-align:left" placeholder="ESCRIBA CLIENTE - PROVEEDOR Y ENTER" />
								' . $ifu->ObjetoHtml('clpv_cod') . '
							</td>
							<td>
								<select id="tran" name="tran" class="form-control input-sm" style="height:25px; text-align:left">
									<option value="0">Seleccione una opcion..</option>
									' . $lista_tran . '
								</select>
							</td>
							<td>
								<input type="text" class="form-control input-sm" id="det_dir" name="det_dir" 
								onkeyup="form1.det_dir.value=form1.det_dir.value.toUpperCase(); " 
								style="width:350px; height:25px; text-align:left" placeholder="DETALLE DIRECTORIO" />
							</td>  	
							<td>
								<input type="text" class="form-control input-sm" id="factura_dir" name="factura_dir" 
								style="width:200px; height:25px; text-align:left" placeholder="# FACTURA" />
							</td>							
							<td>
								<input type="text" class="form-control input-sm" id="fact_valor" name="fact_valor" 
								style="width:140px; height:25px; text-align:rigth" placeholder="VALOR FACTURA" />
							</td>  
							<td align="center">
								<div class="btn btn-success btn-sm" onclick="anadir_dir();">
									<span class="glyphicon glyphicon-plus"></span>
									Agregar
								</div>
							
							</td>
						</tr>';
			$sHtml_dir .= '</table>';


			// RETENCION										
			$sHtml_ret .= '<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;" align="center">';
			$sHtml_ret .= '<tr>
								<td>' . $ifu->ObjetoHtmlLBL('cod_ret') . '</td> 
								<td>' . $ifu->ObjetoHtmlLBL('ret_porc') . '</td>
								<td>' . $ifu->ObjetoHtmlLBL('ret_base') . '</td>
								<td align="center"></td>
					   </tr>';

			$sHtml_ret .= '<tr>
								<td>
									<input type="text" class="form-control input-sm" id="cod_ret" name="cod_ret" 
									onkeyup="cod_retencion(' . $idempresa . ', event ); " 
									style="width:200px; height:25px; text-align:rigth" placeholder="CODIGO RETENCION" />
								</td>   
								<td>
									<input type="text" class="form-control input-sm" id="ret_porc" name="ret_porc" 
									style="width:100px; height:25px; text-align:right" placeholder="PORCENTAJE RETENCION" />
								</td>
								<td>
									<input type="text" class="form-control input-sm" id="ret_base" name="ret_base" 
									style="width:120px; height:25px; text-align:right" placeholder="BASE IMPONIBLE" />	
								</td>
								<td style="display:none">' . $ifu->ObjetoHtml('cta_deb') . '</td>
								<td style="display:none">' . $ifu->ObjetoHtml('cta_cre') . '</td>
								<td style="display:none">' . $ifu->ObjetoHtml('tipo') . '</td>
								<td align="center">
									<div class="btn btn-success btn-sm" onclick="anadir_ret();">
										<span class="glyphicon glyphicon-plus"></span>
										Agregar
								</div>
								</td>
					   </tr>';
			$sHtml_ret .= '</table>';

			// CUENTA DASI
			$sHtml_dasi .= '<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;" align="center">';
			$sHtml_dasi .= '<tr>
								<td align="center" align="center"></td> 
								<td>' . $ifu->ObjetoHtmlLBL('nom_cta') . '</td>
								<td>' . $ifu->ObjetoHtmlLBL('documento') . '</td>
								<td>' . $ifu->ObjetoHtmlLBL('detalla_diario') . '</td>
								<td>' . $ifu->ObjetoHtmlLBL('crdb') . '</td>
								<td>' . $ifu->ObjetoHtmlLBL('ccosn') . '</td>
								<td>' . $ifu->ObjetoHtmlLBL('actividad') . '</td>
								<td>' . $ifu->ObjetoHtmlLBL('val_cta') . '</td>
								<td align="center"></td>
							</tr>';
			$sHtml_dasi .= '<tr>
								<td align="center">
									<div class="btn btn-success btn-sm" onclick="cheque();">
										<span class="glyphicon glyphicon-pencil"></span>
										Cheque
									</div>
								</td> 
								<td>									
									<input type="text" class="form-control input-sm" id="nom_cta" name="nom_cta" 
										onkeyup="auto_dasi(' . $idempresa . ', event, 0 );form1.nom_cta.value=form1.nom_cta.value.toUpperCase(); " 
										style="width:230px; height:25px; text-align:rigth" placeholder="CODIGO - CUENTA Y ENTER" />
									' . $ifu->ObjetoHtml('cod_cta') . '
								</td>
								<td>
									<input type="text" class="form-control input-sm" id="documento" name="documento" 
										style="width:150px; height:25px; text-align:rigth" placeholder="DOCUMENTO" />
								</td> 
								<td>
									<input type="text" class="form-control input-sm" id="detalla_diario" name="detalla_diario" 
										onkeyup="form1.detalla_diario.value=form1.detalla_diario.value.toUpperCase(); " 
										style="width:250px; height:25px; text-align:rigth" placeholder="DETALLE DIARIO" />	
								</td>
								<td>									
									<select id="crdb" name="crdb" class="" >
                                        <option value="0">Seleccione una opcion..</option>
                                        <option value="CR">CREDITO</option>
                                        <option value="DB" selected>DEBITO</option>
                                    </select>
								</td> 
								<td>
										<select id="ccosn" name="ccosn"  style="height:25px; text-align:left">
											<option value="0">Seleccione una opcion..</option>
											' . $lista_ccosn . '
										</select>
								</td>
								<td>
									<select id="actividad" name="actividad" class="select2" style="height:25px; text-align:left">
										<option value="0">Seleccione una opcion..</option>
										' . $lista_act . '
									</select>
								</td>
								<td>
									<input type="text" class="form-control input-sm" id="val_cta" name="val_cta" 
										style="width:140px; height:25px; text-align:rigth" placeholder="VALOR CTA" />
								</td> 
                                <td align="center">
									<div class="btn btn-primary btn-sm" onclick="anadir_dasi_distri();">
										<span class="glyphicon glyphicon-th-list"></span>
										Distribuir
									</div>
								</td>							
								<td align="center">
									<div class="btn btn-success btn-sm" onclick="anadir_dasi();">
										<span class="glyphicon glyphicon-plus"></span>
										Agregar
									</div>
								</td>
							</tr>';
			$sHtml_dasi .= '</table>';

			// IMPUESTO GASOLINA
			$sql = "SELECT p.imp_nombre, p.imp_valor FROM comercial.pais_imp_comb p ORDER BY p.id_imp_comb ";
			$lista_gaso = lista_boostrap($oCon, $sql, 1, 'imp_valor',  'imp_nombre');

			$fu->AgregarCampoListaSQL('pais_imp_comb', 'Tipo|left', "SELECT p.imp_valor, p.imp_nombre FROM comercial.pais_imp_comb p ORDER BY p.id_imp_comb ", false, 100, 150);
			$fu->AgregarComandoAlCambiarValor('pais_imp_comb', 'imp_gaso()');
			$ifu->AgregarCampoNumerico('num_galon', 'N.- Galon|left', true, 0, 50, 10);


			unset($_SESSION['aDataGirdAdj_1']);

			break;
	}
	$ifu->AgregarCampoSi_No('ambiente_sri', '|left', $op);

	$diaHoy = date("Y-m-d");


	//CODIGO PAIS

	$S_PAIS_API_SRI = $_SESSION['S_PAIS_API_SRI'];

	$sHtmlBtn .= '<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;" align="center">
					<tr>
						<td>
							<div class="btn-group">
								<div class="btn btn-primary btn-sm" onclick="genera_formulario();">
									<span class="glyphicon glyphicon-file"></span>
									Nuevo
								</div>
								<div id ="imagen1" class="btn btn-primary btn-sm" onclick="guardar_pedido();">
									<span class="glyphicon glyphicon-floppy-disk"></span>
									Guardar
								</div>
								<div class="btn btn-primary btn-sm" onclick="reporteRetencion();">
									<span class="glyphicon glyphicon-print"></span>
									' . $etiqueta_retencion . '
								</div>
								<div class="btn btn-primary btn-sm" onclick="vista_previa();">
									<span class="glyphicon glyphicon-print"></span>
									Diaro Contable
								</div>
								<div class="btn btn-primary btn-sm"onclick="javascript:orden_compra_consulta();">
										<span class="glyphicon glyphicon-tag"></span>
										Lista Ordenes Compra
									</div>	

								<div class="btn btn-primary btn-sm" onclick="ordenCompra();">
									<span class="glyphicon glyphicon-list-alt"></span>
									Cargar Orden Compra
								</div>
								<div class="btn btn-primary btn-sm" onclick="archivosAdjuntos();">
									<span class="glyphicon glyphicon-folder-open"></span>
									Adjuntos
								</div>
								<div class="btn btn-primary btn-sm" onclick="activo_fijo();">
									<span class="glyphicon glyphicon-tasks"></span>
									Activo Fijo
								</div>
								<div class="btn btn-primary btn-sm" onclick="liqCompras();">
									<span class="glyphicon glyphicon-print"></span>
									Liq. Compras
								</div>
								<div class="btn btn-primary btn-sm" onclick="crear_prove();">
									<span class="glyphicon glyphicon-user"></span>
									Proveedor
								</div>
								<div class="btn btn-primary btn-sm" onclick="listaBeneficiarios();">
									<span class="glyphicon glyphicon-folder-open"></span>
									Beneficiarios
								</div>
								<div class="btn btn-primary btn-sm" onclick="cerrar_anticipo_modulo();">
									<span class="glyphicon glyphicon-folder-open"></span>
									Cerrar Anticipo
								</div>
							</div>
						</td>
						<td>
							<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
								<tr>
									<td>Clave Acceso:</td>
									
									<td>
									<input type="text" class="form-control input-sm" id="clave_acceso_" name="clave_acceso_" 
									value="" style="width:200px; text-align:right; height:25px"/>
									</td>                                        
									<td>
										<div class="btn btn-success btn-sm" onclick="clave_acceso_sri(1);">
											<span class="glyphicon glyphicon-retweet"></span>
											Genera
										</div>
									</td>
								</tr>
							</table>
						</td>
						<td align="right">
							<div class="btn btn-danger btn-sm" onclick="cancelar_pedido();">
								<span class="glyphicon glyphicon-remove"></span>
								Cancelar
							</div>
						</td>
					</tr>
				</table>';

	$sHtml .= '<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;" align="center">
				<tr>
					<td colspan="10" align="center" class="bg-primary">FACTURA PROVEEDOR ONLINE</td>
				</tr>
				<tr class="msgFrm">
					<td colspan="10" align="center">Los campos con * son de ingreso obligatorio</td>
				</tr>';
	$sHtml .= '<tr>
					<td>' . $ifu->ObjetoHtmlLBL('sucursal') . '</td>
					<td>
							<select id="sucursal" name="sucursal" class="form-control input-sm" style="width:150px; height:25px; text-align:left">
								<option value="0">Seleccione una opcion..</option>
								' . $lista_sucu . '
							</select>
					</td>                    
                    <td colspan="2">
                        <table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
                            <tr>
                                <td>' . $ifu->ObjetoHtmlLBL('tipo_doc') . '</td>
								<td>
										<select id="tipo_doc" name="tipo_doc" class="form-control input-sm" style="width:150px; height:25px; text-align:left" onchange="num_rete();">
											<option value="0">Seleccione una opcion..</option>
											' . $lista_tipo_doc . '
										</select>
								</td>
                                <td>' . $ifu->ObjetoHtmlLBL('formato') . '</td>
								<td>
									<select id="formato" name="formato" class="form-control input-sm" style="width:150px; height:25px; text-align:left">
										<option value="0">Seleccione una opcion..</option>
										' . $lista_formato . '
									</select>
								</td>
                            </tr>
                        </table>
                    </td>	
					
			  </tr>
			  <tr style="display:none">
                        <td  >' . $fu->ObjetoHtml('ctrlpedi') . '</td>
                   </tr>';
	$sHtml .= '<tr>
					<td>
						<a href="#" onclick="apareceOculta();" title="Presione para mirar informacion relacionada al Proveedor">' . $ifu->ObjetoHtmlLBL('proveedor') . '</a>
					</td>
                    <td colspan="3">
                        <table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
                            <tr>								
								<td>
									' . $ifu->ObjetoHtml('proveedor') . '     
									<div id="div_regimen_clpv" name="div_regimen_clpv"></div>	
								</td>
								<td style="display:none;">
									<input type="text" class="form-control input-sm" id="cliente" name="cliente" style="width:50px; height:20px; text-align:right" readonly  onchange="cargar_datos();" /> 
								</td>
								<td>' . $fu->ObjetoHtml('clpvCompania') . '   </td>
								<td>
                                    <div class="btn btn-success btn-sm" onclick="autocompletarBtn();">
                                        <span class="glyphicon glyphicon-user"></span>
									</div>
								</td>
								<td>

                                    <div class="btn btn-success btn-sm" onclick="correo();">
                                        <span class="glyphicon glyphicon-envelope"></span>
                                    </div>
                                    <div id="divCompanias" style="color: blue; font-style: italic; font-size: 12px;"></div>
                                </td>
								<td><label for="regimen_fiscal">Plazo &nbsp;</label> </td>
								<td>
									<input type="text" class="form-control input-sm" id="plazo" name="plazo" style="width:40px; height:20px; text-align:right" onchange="calculaFechaVence();" />
								</td>
                                <td>' . $ifu->ObjetoHtmlLBL('forma_pago') . '</td>
								<td>
									<select id="forma_pago" name="forma_pago" class="form-control input-sm" style="width:150px; height:25px; text-align:left">
										<option value="0">Seleccione una opcion..</option>
										' . $lista_forma_pago . '
									</select>
								</td>
                                <td style="display:none;">' . $ifu->ObjetoHtmlLBL('tipo_pago') . '</td>
                                <td style="display:none;"> ' . $ifu->ObjetoHtml('tipo_pago') . '</td>
                            </tr>
                        </table>
					</td>
			  </tr>';
	$sHtml .= '<tr>
					<td colspan="4">
						<div id="verInfoClpv" style="display:none;"></div>
					</td>
				</tr>';
	$sHtml .= '<tr>
					
					<td>' . $ifu->ObjetoHtmlLBL('fecha_retencion') . '</td>
					<td><input type="date" name="fecha_retencion" id="fecha_retencion" step="1" value=""></td>
					
                    <td colspan="2">
                        <table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
                            <tr>
                                <td>' . $fu->ObjetoHtmlLBL('proyecto') . '</td>
								<td>
									<select id="proyecto" name="proyecto" class="form-control input-sm" style="width:150px; height:25px; text-align:left" onchange="cargarActividades();">
										<option value="0">Seleccione una opcion..</option>
										' . $lista_proyecto . '
									</select>
								</td>
                                <td>' . $fu->ObjetoHtmlLBL('actividad_proy') . '</td>
								<td>
									<select id="actividad_proy" name="actividad_proy" class="form-control input-sm" style="width:150px; height:25px; text-align:left">
										<option value="0">Seleccione una opcion..</option>
									</select>
								</td>
								<td>Marco Logico</td>
								<td>
									<select id="actividad_marco_logico" name="actividad_marco_logico" class="form-control input-sm" style="width:150px; height:25px; text-align:left">
										<option value="0">Seleccione una opcion..</option>
										' . $lista_marco_logico . '
									</select>
								</td>
							</tr>
						</table>
					</td>
				</tr>
				<tr>
					<td colspan="4">
						<table align="left" class="table table-striped table-condensed" style="width: 98%; margin-bottom: 0px;">
							<tr>
								<td align="right">
									<table align="rigth" class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
										<tr>		
											<td><label for="orden_op"> Orden Produccion:</label></td>			
											<td>													
													<select id="orden_op" name="orden_op" class="form-control select2" multiple="multiple" >
														<option value="">Seleccione una opcion..</option>														
													</select>
											</td>						
											<td align="right"><label for="regimen_fiscal">Regimen Fiscal &nbsp;</label>    </td>			
											<td>' . $ifu->ObjetoHtml('regimen_fiscal') . '&nbsp;             </td>
											<td><label for="doble_tributac">Doble Tributacion &nbsp;</label> </td>
											<td>' . $ifu->ObjetoHtml('doble_tributac') . '&nbsp;             </td>
											<td><label for="pago_sujeto_ret">Sujeto ' . $etiqueta_retencion . ' &nbsp;</label> </td>
											<td>' . $ifu->ObjetoHtml('pago_sujeto_ret') . '&nbsp;            </td>    
										</tr>
									</table>
								</td>                                                            
                            </tr>
                        </table>
                    </td>
			  </tr>';
	$sHtml .= '<tr> 
					<td colspan="4"></td>
			   </tr>';
	$sHtml .= '<tr style="display:none">
					<td>' . $ifu->ObjetoHtmlLBL('empl_soli') . '</td>
					<td> ' . $ifu->ObjetoHtml('empl_soli') . '</td>
					<td>' . $ifu->ObjetoHtmlLBL('empl_auto') . '</td>
					<td> ' . $ifu->ObjetoHtml('empl_auto') . '</td>
				</tr>';
	$sHtml .= '<tr> 
					<td>' . $ifu->ObjetoHtmlLBL('detalle') . '</td>
					<td colspan="3">
						<input type="text" class="form-control input-sm" id="detalle" name="detalle" style="width:650px; text-align:left; height:45px"  onkeyup="this.value=this.value.toUpperCase();"  onchange="detalle_grid_();"/>					
					</td>
					
				</tr>';

	$sHtml .= '<tr> 
					<td colspan="2" style="display:none">' . $ifu->ObjetoHtml('tipoRuc') . '' . $fu->ObjetoHtml('ctrl') . '' . $fu->ObjetoHtml('fprv_cod_fprv') . '' . $ifu->ObjetoHtml('claveAcceso') . '</td>
					<td colspan="2" style="display:none">' . $fu->ObjetoHtml('asto_cod') . '' . $fu->ObjetoHtml('ejer_cod') . '' . $fu->ObjetoHtml('prdo_cod') . '</td>
				</tr>';


	$sHtml .= '	<tr>
					' . $campo_renta . '
				</tr>
				';


	$sHtml .= $op;
	$sHtml .= '</table>';

	$sHtmlFactura .= '<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
						<tr>
							<td class="bg-primary" colspan="4">DATOS FACTURA</td>
						</tr>
						<tr>
							<td colspan="4">
								<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
									<tr> 
										<td>' . $ifu->ObjetoHtmlLBL('sust_trib') . '</td>
										<td>
											<select id="sust_trib" name="sust_trib" class="form-control input-sm" style="width:170px; height:25px; text-align:left" onchange="cargarTipoComprobante();">
												<option value="0">Seleccione una opcion..</option>
											</select>
										</td>
										<td>' . $ifu->ObjetoHtmlLBL('comprobante') . '</td>
										<td>
											<select id="comprobante" name="comprobante" class="form-control input-sm" style="width:150px; height:25px; text-align:left" onchange="cargar_reemb();">
												<option value="0">Seleccione una opcion..</option>
											</select> 
										</td>
									</tr>
									<tr>
										<td colspan="2"><span id="reemb"></span></td>
										<td colspan="2">
											<div class="btn btn-success btn-sm" onclick="forma_pago();" style="height:28px; text-align:left">
												<span class="glyphicon glyphicon-list"></span>
												FORMA PAGO
											</div>
										</td>
									</tr>
								</table<
							</td>
						</tr>
						<tr>
							<td colspan="4">
								<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
									<tr> ';
	//PERU
	if ($S_PAIS_API_SRI == '51') {
		$sHtmlFactura .= '<td>' . $ifu->ObjetoHtmlLBL('fecha_contable') . '</td>
										<td><input type="date" name="fecha_contable" id="fecha_contable" step="1" value="' . $diaHoy . '" ></td>
										<td>' . $ifu->ObjetoHtmlLBL('fecha_emision') . '</td>
										<td><input type="date" name="fecha_emision"  id="fecha_emision"  step="1" value="' . $diaHoy . '" onchange="cargar_coti();"></td>
                                        <td>' . $ifu->ObjetoHtmlLBL('fecha_vence') . '</td>
                                        <td><input type="date" name="fecha_vence" id="fecha_vence" step="1" value="' . $diaHoy . '"></td>';
	} else {
		$sHtmlFactura .= '<td>' . $ifu->ObjetoHtmlLBL('fecha_contable') . '</td>
										<td><input type="date" name="fecha_contable" id="fecha_contable" step="1" value="' . $diaHoy . '" onchange="cargar_coti();"></td>
										<td>' . $ifu->ObjetoHtmlLBL('fecha_emision') . '</td>
										<td><input type="date" name="fecha_emision"  id="fecha_emision"  step="1" value="' . $diaHoy . '"></td>
                                        <td>' . $ifu->ObjetoHtmlLBL('fecha_vence') . '</td>
                                        <td><input type="date" name="fecha_vence" id="fecha_vence" step="1" value="' . $diaHoy . '"></td>';
	}
	$sHtmlFactura .= '</tr>
								</table>
							</td>
						</tr>
						<tr>
							<td colspan="4">
								<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
									<tr> 
										<td>' . $ifu->ObjetoHtmlLBL('moneda') . '</td>
										<td>
											<select id="moneda" name="moneda" class="form-control input-sm" style="width:100px; height:25px; text-align:left" onchange="cargar_coti(); ">
												<option value="0">Seleccione una opcion..</option>
												' . $lista_moneda . '
											</select>
										</td>
										<td>' . $ifu->ObjetoHtmlLBL('cotizacion') . '</td>
										<td><input class="form-control input-sm" type="text" placeholder="Cotizacion"  style="width: 80px; height:25px; text-align:right" id="cotizacion"     name="cotizacion" value="1"</td>
										<td>' . $ifu->ObjetoHtmlLBL('cotizacion_ext') . '</td>
										<td><input class="form-control input-sm" type="text" placeholder="Cotizacion"  style="width: 80px; height:25px; text-align:right" id="cotizacion_ext" name="cotizacion_ext" value="' . $coti . '"</td>
									</tr>
								</table>
							</td>
						</tr>		
						<tr>
							<td colspan="4">
								<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
									<tr> 
										
										<td>' . $ifu->ObjetoHtmlLBL('sec_automatico') . '</td>
										<td>' . $ifu->ObjetoHtml('sec_automatico') . '</td>
										<td><div id="div_fact_afect1" name="div_fact_afect1" style="display: none">' . $ifu->ObjetoHtmlLBL('factura_afectar') . '</div></td>
										<td><div id="div_fact_afect2" name="div_fact_afect2" style="display: none">' . $ifu->ObjetoHtml('factura_afectar') . '<div></td>

									</tr>
								</table>
							</td>
						</tr>				
						<tr>
							<td colspan="4">
								<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
									<tr> 
										<td>' . $ifu->ObjetoHtmlLBL('tipo_factura') . '</td>
										<td>
											<select id="tipo_factura" name="tipo_factura" class="form-control input-sm" onchange="cargar_factura();" style="height:25px; text-align:left">
												<option value="0">Seleccione una opcion..</option>
												<option value="1">ELECTRONICA</option>
												<option value="2">PREIMPRESA</option>
											</select>
										</td>
										<td>' . $ifu->ObjetoHtmlLBL('ret_asumido') . '</td>
										<td>' . $ifu->ObjetoHtml('ret_asumido') . '</td>
									</tr>
								</table>
							</td>
						</tr>
						<tr>
							<td colspan="4">
								<table id = "divFactura" class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;"></table>
							</td>
						</tr>
					</table>';

	//retencion empresa

	$empr_cod_pais = $_SESSION['U_PAIS_COD'];
	$sql_etiqueta_pais = "SELECT p.impuesto, p.etiqueta, p.porcentaje, porcentaje2 from comercial.pais_etiq_imp p where
                p.pais_cod_pais = $empr_cod_pais 
				and p.impuesto = 'TIPO_RETENCION'";
	$etiqueta_tipo_rete = consulta_string_func($sql_etiqueta_pais, 'etiqueta', $oIfx, 'RETE');


	if ($etiqueta_tipo_rete == 'DETR') {
		$display_rete1 = 'none';
	} else {
		$display_rete1 = '';
	}

	$sHtmlRete .= '
				<div id="div_rete_ad" name="div_rete_ad" class="col-md-12" style="display: ' . $display_rete1 . '">
				<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
					<tr>
						<td class="bg-primary" colspan="10">DATOS ' . strtoupper($etiqueta_retencion) . ' EMPRESA</td>
					</tr>
					<tr>
						<td>' . $fu->ObjetoHtmlLBL('electronica') . '</td>
						<td>' . $fu->ObjetoHtml('electronica') . '</td>
						<td>' . $ifu->ObjetoHtmlLBL('serie_rete') . '</td>
						<td>
							<input class="form-control input-sm" type="text" placeholder="Serie"  
							style="width: 90px; height:25px; text-align:right" id="serie_rete"  name="serie_rete" value="' . $seri_rete . '"
						</td>						
						<td>' . $etiqueta_retencion . '</td>
						<td>
							<input class="form-control input-sm" type="text" placeholder="Retencion"  onchange="num_digito(1);"
							style="width: 150px; height:25px; text-align:right" id="num_rete"  name="num_rete" value="' . $num_rete . '"
						</td> 
						<td>' . $ifu->ObjetoHtmlLBL('cad_rete') . '</td>
						<td>
							<input class="form-control input-sm" type="text" placeholder="Caducidad"  
							style="width: 120px; height:25px; text-align:right" id="cad_rete"  name="cad_rete" value="' . $ret_fec_auto . '"
						</td>  
						<td>' . $ifu->ObjetoHtmlLBL('auto_rete') . '</td>
						<td>
							<input class="form-control input-sm" type="text" placeholder="Autorizacion"  
							style="width: 150px; height:25px; text-align:left" id="auto_rete"  name="auto_rete" value="' . $rete_auto . '"
						</td>
					</tr>
				</table>
				</div>
				';


	//valores facturas
	$sHtmlValoresFacturas .= '<input type="hidden" id="iva_multiple_json" name="iva_multiple_json" value="" />
							<table class="table table-bordered table-striped table-condensed" style="width: 100%; margin: 0px;">
								<tr>
									<td class="bg-primary" style="width: 98%;" colspan="3">
										<table style="width: 100%; margin: 0px;">
											<tr>
												<td class="bg-primary">VALORES FACTURA INCLUIDO IMPUESTO:</td>
												<td>' . $ifu->ObjetoHtml('total_inciva') . '</td>
												<td class="bg-primary"><label for="pais_imp_comb">Tipo:</label></td>
												<td>' . $fu->ObjetoHtml('pais_imp_comb') . '</td>
												<td style="display:none" class="bg-primary" id="ga1"><label for="num_galon">* N.- Galon:</label></td>
												<td style="display:none" id="ga2">' . $ifu->ObjetoHtml('num_galon') . '</td>
												<td>
													<div class="btn btn-success btn-sm" onclick="totales_inciva(this);" title="Presione para Actualiza Valores">
														<span class="glyphicon glyphicon-refresh"></span>
													</div>
												</td>
											</tr>
										</table>
									
										
										
										
										
										
									</td>
									<td class="bg-success" style="width: 5%;">
										<div class="btn btn-success btn-sm" onclick="cargarValoresPlanillaClpv();">
											<span class="glyphicon glyphicon-refresh"></span>
										</div>
									</td>
								</tr>
								<tr>
									<td valign="top" ' . $visible_bien_sn . '>
										<table class="table-bordered table-striped table-condensed" style="width: 100%; margin: 0px;">
											<tr>
												<td class="bg-info" colspan="2">BIENES</td>
											</tr>
											<tr>
												<td colspan="2">
													<table class="table table-bordered table-condensed" style="width:100%; margin:0px;">
														<thead>
															<tr class="info"><th>% IVA</th><th>Base</th><th>IVA</th><th>Total</th><th></th></tr>
														</thead>
														<tbody id="iva_multiple_bienes_body">
															<tr>
																<td><select class="form-control input-sm iva-multiple-porcentaje" onchange="calcularIvaMultiple();" style="width:80px; height:25px;"><option value="0">0%</option><option value="5">5%</option><option value="12">12%</option><option value="15" selected>15%</option></select></td>
																<td><input type="text" class="form-control input-sm iva-multiple-base" value="0" onchange="calcularIvaMultiple();" onkeyup="calcularIvaMultiple();" style="width:90px; height:25px; text-align:right"></td>
																<td><input type="text" class="form-control input-sm iva-multiple-iva" value="0" onchange="calcularIvaMultiple();" onkeyup="calcularIvaMultiple();" style="width:90px; height:25px; text-align:right"></td>
																<td><input type="text" class="form-control input-sm iva-multiple-total" value="0" readonly style="width:90px; height:25px; text-align:right"></td>
																<td><button type="button" class="btn btn-danger btn-xs" onclick="this.parentNode.parentNode.remove(); calcularIvaMultiple();">x</button></td>
															</tr>
														</tbody>
													</table>
													<button type="button" class="btn btn-primary btn-xs" onclick="agregarFilaIvaMultiple(\'bienes\', 0, 0, 0);">Agregar IVA bienes</button>
												</td>
											</tr>
											<tr class="info">
												<td colspan="2">Resumen dinámico BIENES</td>
											</tr>
											<tbody id="iva_multiple_bienes_resumen"></tbody>
											<tbody style="display:none;">
											<tr><td>' . $ifu->ObjetoHtmlLBL('valor_grab12b') . '</td><td align="right">' . $ifu->ObjetoHtml('valor_grab12b') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('valor_grab0b') . '</td><td align="right">' . $ifu->ObjetoHtml('valor_grab0b') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('iceb') . '</td><td align="right">' . $ifu->ObjetoHtml('iceb') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('ivab') . '</td><td align="right">' . $ifu->ObjetoHtml('ivab') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('ivabp') . '</td><td align="right">' . $ifu->ObjetoHtml('ivabp') . '</td></tr>
											</tbody>
											<tr>
												<td></td>
												<td></td>
											</tr>
											<tr>
												<td></td>
												<td></td>
											</tr>
											<tr>
												<td>Tasa Efectiva:</td>
												<td>
													<input class="form-check-input" type="checkbox" value="S" id="tasa_efectiva_sn" name="tasa_efectiva_sn">
												</td>
											</tr>
											<tr>
												<td>Planilla:</td>
												<td>
													<select id="planilla" name="planilla" class="form-control input-sm" style="width:150px; height:25px; text-align:left" onchange="cargarValoresPlanillaClpv();">
														<option value="0">Seleccione una opcion..</option>
														' . $lista_planilla . '
													</select>
												</td>
											</tr>
										</table>
									</td>
									<td valign="top" ' . $visible_serv_sn . '>
										<table class="table-bordered table-striped table-condensed" style="width: 100%; margin: 0px;">
											<tr>
												<td class="bg-info" colspan="2">SERVICIOS</td>
											</tr>
											<tr>
												<td colspan="2">
													<table class="table table-bordered table-condensed" style="width:100%; margin:0px;">
														<thead>
															<tr class="info"><th>% IVA</th><th>Base</th><th>IVA</th><th>Total</th><th></th></tr>
														</thead>
														<tbody id="iva_multiple_servicios_body"></tbody>
													</table>
													<button type="button" class="btn btn-primary btn-xs" onclick="agregarFilaIvaMultiple(\'servicios\', 0, 0, 0);">Agregar IVA servicios</button>
												</td>
											</tr>
											<tr class="info">
												<td colspan="2">Resumen dinámico SERVICIOS</td>
											</tr>
											<tbody id="iva_multiple_servicios_resumen"></tbody>
											<tbody style="display:none;">
											<tr><td>' . $ifu->ObjetoHtmlLBL('valor_grab12s') . '</td><td align="right">' . $ifu->ObjetoHtml('valor_grab12s') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('valor_grab0s') . '</td><td align="right">' . $ifu->ObjetoHtml('valor_grab0s') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('ices') . '</td><td align="right">' . $ifu->ObjetoHtml('ices') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('ivas') . '</td><td align="right">' . $ifu->ObjetoHtml('ivas') . '</td></tr>
											</tbody>
											
											<tr>
												<td>&nbsp;</td>
												<td>&nbsp;</td>
											</tr>
										</table>
									</td>
									<td valign="top" colspan="2">
										<table class="table-bordered table-striped table-condensed" style="width:100%; margin: 0px;">
											<tr>
												<td class="bg-info" colspan="2">TOTAL</td>
											</tr>
											<tr class="info"><td colspan="2">Resumen dinámico TOTAL</td></tr>
											<tbody id="iva_multiple_total_resumen"></tbody>
											<tbody style="display:none;">
											<tr><td>' . $ifu->ObjetoHtmlLBL('valor_grab12t') . '</td><td align="right">' . $ifu->ObjetoHtml('valor_grab12t') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('valor_grab0t') . '</td><td align="right">' . $ifu->ObjetoHtml('valor_grab0t') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('icet') . '</td><td align="right">' . $ifu->ObjetoHtml('icet') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('ivat') . '</td><td align="right">' . $ifu->ObjetoHtml('ivat') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('valor_noObjIva') . '</td><td align="right">' . $ifu->ObjetoHtml('valor_noObjIva') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('valor_exentoIva') . '</td><td align="right">' . $ifu->ObjetoHtml('valor_exentoIva') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('no_obj_iva') . '</td><td align="right">' . $ifu->ObjetoHtml('no_obj_iva') . '</td></tr>
											<tr><td>' . $ifu->ObjetoHtmlLBL('totals') . '</td><td align="right">' . $ifu->ObjetoHtml('totals') . '</td></tr>
											</tbody>
										</table>
									</td>
								</tr>
							</table>';
	$empresa = $_SESSION['U_EMPRESA'];
	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();
	$sql = "select DATE_PART('year',ejer_fec_inil) as dato from saeejer where ejer_cod_empr='$empresa' order by 1 desc";
	if ($oIfx->Query($sql)) {
		if ($oIfx->NumFilas() > 0) {
			$anio_html .= '<select id="anio_divi" name="anio_divi" class="form-control input-sm" >';
			do {
				$anio_html .= '<option value="' . $oIfx->f('dato') . '">' . $oIfx->f('dato') . '</option>';
			} while ($oIfx->SiguienteRegistro());
			$anio_html .= '</select>';
		}
	}
	$sHtmlValoresRetencion .= '<table class="table table-bordered table-striped table-condensed" style="width: 100%; margin: 0px;">
									<tr>
										<td class="bg-primary" colspan="8">DATOS ' . strtoupper($etiqueta_retencion) . ' PROVEEDOR</td>
									</tr>
									<tr class="info">
										<td colspan="8">PRESIONE ENTER SOBRE EL CODIGO DE ' . strtoupper($etiqueta_retencion) . ' PARA BUSCAR</td>
									</tr>';


	// --------------------------------------------------------------------------
	// VALIDACIONES PERU
	// --------------------------------------------------------------------------
	$empr_cod_pais = $_SESSION['U_PAIS_COD'];
	$sql_codInter_pais = "SELECT pais_codigo_inter from saepais where pais_cod_pais = $empr_cod_pais;";
	$codigo_pais = consulta_string_func($sql_codInter_pais, 'pais_codigo_inter', $oIfx, 0);

	$sql_pcon = "SELECT pcon_cue_niif from saepcon WHERE pcon_cod_empr = $idempresa;";
	$pcon_cue_niif = consulta_string_func($sql_pcon, 'pcon_cue_niif', $oIfx, 0);

	if ($codigo_pais == 51 && $pcon_cue_niif == 'S') {

		$sHtmlValoresRetencion .= '	<tr class="info">
										<td colspan="8">
											<select id="tipo_retencion_ad" name="tipo_retencion_ad" class="form-control input-sm" onchange="habilitar_retencion()">
												<option value="D">DETRACCION</option>
												<option value="R">RETENCION</option>
											</select>
										</td>
									</tr>';
	}

	// --------------------------------------------------------------------------
	// FIN VALIDACIONES PERU
	// --------------------------------------------------------------------------



	$sHtmlValoresRetencion .= '		<tr>
										<td></td>
										<td>Codigo</td>
										<td>%</td>
										<td>Base</td>
										<td>Val Retenido</td>
										<td style="display:none">Compensacion</td>
									</tr>
									<tr>
										<td>Fuente Bienes:</td>
										<td align="right">' . $ifu->ObjetoHtml('codigo_ret_fue_b') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('porc_ret_fue_b') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('base_fue_b') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('val_fue_b') . '</td>
										<td style="display:none" align="right">' . $fu->ObjetoHtmlLBL('comp_2') . '</td>
										<td style="display:none" align="right">' . $fu->ObjetoHtml('comp_2') . '</td>
									</tr>
									<tr>
										<td>Fuente Servicio:</td>
										<td align="right">' . $ifu->ObjetoHtml('codigo_ret_fue_s') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('porc_ret_fue_s') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('base_fue_s') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('val_fue_s') . '</td>
											
										<td style="display:none" align="right">' . $fu->ObjetoHtmlLBL('comp_3') . '</td>
										<td style="display:none" align="right">' . $fu->ObjetoHtml('comp_3') . '</td>
									</tr>
									<tr>
										<td>' . $array_imp['IVA'] . ' Bienes:</td>
										<td align="right">' . $ifu->ObjetoHtml('codigo_ret_iva_b') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('porc_ret_iva_b') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('base_iva_b') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('val_iva_b') . '</td>
											
										<td style="display:none" align="right">' . $fu->ObjetoHtmlLBL('comp_4') . '</td>
										<td style="display:none" align="right">' . $fu->ObjetoHtml('comp_4') . '</td>
									</tr>
									<tr>
										<td>' . $array_imp['IVA'] . ' Servicio:</td>
										<td align="right">' . $ifu->ObjetoHtml('codigo_ret_iva_s') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('porc_ret_iva_s') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('base_iva_s') . '</td>
										<td align="right">' . $ifu->ObjetoHtml('val_iva_s') . '</td>
										<td style="display:none" align="right">' . $fu->ObjetoHtmlLBL('total_comp') . '</td>
										<td style="display:none" align="right">' . $fu->ObjetoHtml('total_comp') . '</td>
									</tr>
									<tr id="tr_divi1" style="display:none">
										
										<td>Fec. Pago Dividendo</td>
										<td><input type="date" id="f_pago_divi" name="f_pago_divi" value="' . date('Y-m-d') . '"></td>
										<td>IR Aso c.</td>
										<td colspan="3"><input type="number" id="ir_pago_divi" name="ir_pago_divi"></td>
										
									</tr>
									<tr id="tr_divi2" style="display:none">
										<td>Anio en el que se generaron las utilidades</td>
										<td>' . $anio_html . '</td>
									</tr>
								</table>';

	//cuentas contables
	$sHtmlCuentas .= '<table class="table table-striped table-condensed" style="width: 100%; margin: 0px; display:none;" >
						<tr>
							<td class="bg-primary" colspan="8">CUENTAS CONTABLES</td>
						</tr>
						<tr>
							<td>' . $ifu->ObjetoHtmlLBL('fisc_b') . '</td>
							<td colspan="1">' . $ifu->ObjetoHtml('fisc_b') . '</td>
							<td><span id="fisc_b_tmp"></span></td>
							<td><span id="fisc_b_centroCostos"></span></td>
						</tr>
						<tr>
							<td>' . $ifu->ObjetoHtmlLBL('fisc_s') . '</td>
							<td colspan="1">' . $ifu->ObjetoHtml('fisc_s') . '</td>
							<td><span id="fisc_s_tmp"></span></td>
							<td><span id="fisc_s_centroCostos"></span></td>
						</tr>
						<tr>
							<td>' . $ifu->ObjetoHtmlLBL('gasto1') . '</td>
							<td>' . $ifu->ObjetoHtml('gasto1') . '</td>
							<td>' . $ifu->ObjetoHtml('val_gasto1') . '</td>
							<td>
								<div class="btn btn-success btn-sm" onclick="distribuir();">
									<span class="glyphicon glyphicon-th-list"></span>
									Distribuir
								</div>
							</td>
							<td><span id="gasto_val"></span></td>
							<td><span id="gasto_val_centroCostos"></span></td>
						</tr>
						<tr>
							<td>' . $ifu->ObjetoHtmlLBL('gasto2') . '</td>
							<td>' . $ifu->ObjetoHtml('gasto2') . '</td>
							<td>' . $ifu->ObjetoHtml('val_gasto2') . '</td>
							<td></td>
							<td><span id="gasto_val2"></span></td>
							<td><span id="gasto_val2_centroCostos"></span></td>
						</tr>
					</table>';

	$_SESSION['aDataGirdAdj_1'] = NULL;
	unset($_SESSION['aDataGirdAdj_1']);

	// Adrian47
	$_SESSION['aDataGird']  = NULL;
	unset($_SESSION['aDataGird']);


	$oReturn->assign("divBotones", "innerHTML", $sHtmlBtn);
	$oReturn->assign("divFormularioCabecera", "innerHTML", $sHtml);
	$oReturn->assign("divFormularioFactura", "innerHTML", $sHtmlFactura);
	$oReturn->assign("divFormularioRetencion", "innerHTML", $sHtmlRete);
	$oReturn->assign("divValoresFacturas", "innerHTML", $sHtmlValoresFacturas);
	$oReturn->assign("divValoresRetencion", "innerHTML", $sHtmlValoresRetencion);
	$oReturn->assign("divFormularioCuentas", "innerHTML", $sHtmlCuentas);
	$oReturn->assign("divReporte", "innerHTML", "");
	$oReturn->assign("proveedor", "placeholder", "PRESIONE ENTER O F4 PARA BUSCAR...");
	$oReturn->assign("proveedor", "focus()", "");



	$oReturn->assign("divFormDir", "innerHTML", $sHtml_dir);
	$oReturn->assign("divFormRet", "innerHTML", $sHtml_ret);
	$oReturn->assign("divFormDiario", "innerHTML", $sHtml_dasi);
	$oReturn->script("$('.select2').select2();");

	if ($cod != 0) {
		$valpago = round($valpago, 2);
		$oReturn->assign('ccosn', 'value', $pcodccos);
		$oReturn->assign('total_inciva', 'value', $valpago);
		$oReturn->script("cargarDatosClpv($pgclpv,'')");
	}





	$importacion_modulos = $_SESSION['GESTION_IMPORTACION'];
	if (!empty($importacion_modulos)) {

		$oReturn->script('eliminar_lista_planilla();');

		$sql_saeinfimp = "SELECT * from saeinfimp where infimp_cod_infimp = $importacion_modulos ";
		$infimp_cod_clpv = consulta_string($sql_saeinfimp, 'infimp_cod_clpv', $oIfx, 0);
		$infimp_tip_fact = consulta_string($sql_saeinfimp, 'infimp_tip_fact', $oIfx, 0);
		$infimp_auto_prove = consulta_string($sql_saeinfimp, 'infimp_auto_prove', $oIfx, 0);
		$infimp_seri_prove = consulta_string($sql_saeinfimp, 'infimp_seri_prove', $oIfx, 0);
		$infimp_fact_prove = consulta_string($sql_saeinfimp, 'infimp_fact_prove', $oIfx, 0);

		$infimp_val_infimp = consulta_string($sql_saeinfimp, 'infimp_val_infimp', $oIfx, 0);
		$infimp_val_impues = consulta_string($sql_saeinfimp, 'infimp_val_impues', $oIfx, 0);


		// ---------------------------------------------------------------------------
		// Cargar datos proveedor
		// ---------------------------------------------------------------------------
		$oReturn->script("cargarDatosClpv($infimp_cod_clpv,'')");
		// ---------------------------------------------------------------------------
		// Cargar datos proveedor
		// ---------------------------------------------------------------------------

		$oReturn->assign("tipo_factura", "value", $infimp_tip_fact);
		$oReturn->assign('auto_prove', 'value', $infimp_auto_prove);
		$oReturn->assign('serie', 'value', $infimp_seri_prove);
		$oReturn->assign('factura', 'value', $infimp_fact_prove);

		// Valor del los bienes 
		$oReturn->assign("valor_grab12b", "value", 0);
		$oReturn->assign("valor_grab0b", "value", 0);

		// Valor del los Servicios 
		$oReturn->assign("valor_grab12s", "value", $infimp_val_impues);
		$oReturn->assign("valor_grab0s", "value", $infimp_val_infimp);
	}


	$clave_acceso = $_SESSION['claveAccesoExterno'];
	if (!empty($clave_acceso)) {
		$oReturn->assign("clave_acceso_", "value", $clave_acceso);
		$oReturn->script("clave_acceso_sri(1)");
	}

	//$oReturn->script("automatico();");
	return $oReturn;
}


function listaBeneficiarios($aForm = '')
{
	global $DSN_Ifx, $DSN;

	session_start();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	//variables de sesion
	unset($_SESSION['ARRAY_BENEFICIARIO_FPRV']);
	$idempresa = $_SESSION['U_EMPRESA'];

	//varibales del formulario
	$cliente = $aForm['cliente'];
	$sucursal = $aForm['sucursal'];
	$totals = $aForm['totals'];

	try {

		$ifu->AgregarCampoTexto('beneficiarioProy', 'Beneficiario|left', false, '', 400, 200);
		$ifu->AgregarComandoAlEscribir('beneficiarioProy', 'autocompletarBenf(event );form1.beneficiarioProy.value=form1.beneficiarioProy.value.toUpperCase();');

		$ifu->AgregarCampoOculto('idBeneficiarioProy', 0);
		$ifu->AgregarCampoOculto('idContratoBeneficiarioProy', 0);

		$ifu->AgregarCampoNumerico('valorBenf', 'Valor|left', false, 0, 80, 9);

		$sHtml = '';
		$sHtml .= '<div class="modal-dialog modal-lg" role="document">
					<div class="modal-content">
						<div class="modal-header">
							<button type="button" class="close" data-dismiss="modal" aria-label="Close">
								<span aria-hidden="true">&times;</span>
							</button>
							<h4 class="modal-title" id="myModalLabel">GASTOS PROYECTOS</h4>
						</div>
						<div class="modal-body">';

		$sHtml .= '<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;" align="center">';
		$sHtml .= '<tr>';
		$sHtml .= '<td class="fecha_letra" colspan="5" align="right"><h3>Total: ' . number_format($totals, 2, '.', ',') . '</h3></td>';
		$sHtml .= '</tr>';
		$sHtml .= '<tr>';
		$sHtml .= '<td>' . $ifu->ObjetoHtmlLBL('beneficiarioProy') . '</td>';
		$sHtml .= '<td>' . $ifu->ObjetoHtml('beneficiarioProy') . '' . $ifu->ObjetoHtml('idBeneficiarioProy') . '' . $ifu->ObjetoHtml('idContratoBeneficiarioProy') . '</td>';
		$sHtml .= '<td>' . $ifu->ObjetoHtmlLBL('valorBenf') . '</td>';
		$sHtml .= '<td>' . $ifu->ObjetoHtml('valorBenf') . '</td>';
		$sHtml .= '<td align="center">
						<div class="btn btn-success btn-sm" onclick="agregarArchivo_1();">
							<span class="glyphicon glyphicon-plus-sign"></span>
							Agregar
						</div>
					<td>';
		$sHtml .= '</tr>';
		$sHtml .= '</table>';

		$sHtml .= '<div id="gridArchivos_1" style="margin-top: 20px;"></div>';

		$sHtml .= '</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
							<button type="button" class="btn btn-primary" data-dismiss="modal">Procesar</button>
						</div>
					</div>
				</div>';

		$oReturn->assign("miModal", "innerHTML", $sHtml);
	} catch (Exception $e) {
		$oReturn->alert($e->getMessage());
	}

	return $oReturn;
}

function agrega_modifica_gridAdj_1($nTipo = 0,  $aForm = '', $id = '', $total_fact = '')
{
	session_start();
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$oReturn = new xajaxResponse();

	$aDataGrid = $_SESSION['aDataGirdAdj_1'];

	$aLabelGrid = array('Id', 'Codigo', 'Clpv', 'Beneficiario', 'Valor', 'Eliminar');

	$beneficiarioProy = $aForm['beneficiarioProy'];
	$idBeneficiarioProy = $aForm['idBeneficiarioProy'];
	$idContratoBeneficiarioProy = $aForm['idContratoBeneficiarioProy'];
	$valorBenf  = $aForm['valorBenf'];

	//GUARDA LOS DATOS DEL DETALLE
	$cont = count($aDataGrid);

	$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
	$aDataGrid[$cont][$aLabelGrid[1]] = $idContratoBeneficiarioProy;
	$aDataGrid[$cont][$aLabelGrid[2]] = $idBeneficiarioProy;
	$aDataGrid[$cont][$aLabelGrid[3]] = $beneficiarioProy;
	$aDataGrid[$cont][$aLabelGrid[4]] = $valorBenf;
	$aDataGrid[$cont][$aLabelGrid[5]] = '<img src="' . path(DIR_IMAGENES) . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalleAdj_1(' . $cont . ');"
												alt="Eliminar"
												align="bottom" />';
	$_SESSION['aDataGirdAdj_1'] = $aDataGrid;
	$sHtml = mostrar_gridAdj_1();
	$oReturn->assign("gridArchivos_1", "innerHTML", $sHtml);
	$oReturn->assign("idBeneficiarioProy", "value", "");
	$oReturn->assign("idContratoBeneficiarioProy", "value", "");
	$oReturn->assign("beneficiarioProy", "value", "");
	$oReturn->assign("valorBenf", "value", "");

	return $oReturn;
}

function mostrar_gridAdj_1()
{
	session_start();
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa =  $_SESSION['U_EMPRESA'];
	$aDataGrid = $_SESSION['aDataGirdAdj_1'];
	$aLabelGrid = array('Id', 'Codigo', 'Clpv', 'Beneficiario', 'Valor', 'Eliminar');

	$cont = 0;
	$total     = 0;
	foreach ($aDataGrid as $aValues) {
		$aux = 0;
		foreach ($aValues as $aVal) {
			if ($aux == 0) {
				$aDatos[$cont][$aLabelGrid[$aux]] = $cont + 1;
			} elseif ($aux == 1) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 2) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 3) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 4) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
			} elseif ($aux == 5) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
														<img src="' . path(DIR_IMAGENES) . 'iconos/delete_1.png"
														title = "Presione aqui para Eliminar"
														style="cursor: hand !important; cursor: pointer !important;"
														onclick="javascript:xajax_elimina_detalleAdj_1(' . $cont . ');"
														alt="Eliminar"
														align="bottom" />
													</div>';
			} else
				$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
			$aux++;
		}
		$cont++;
	}

	return genera_grid($aDatos, $aLabelGrid, 'Beneficiarios', 98, null, $array_tot);
}


function elimina_detalleAdj_1($id = null)
{
	session_start();

	$oReturn = new xajaxResponse();

	$aLabelGrid = array('Id', 'Codigo', 'Clpv', 'Beneficiario', 'Valor', 'Eliminar');
	$aDataGrid = $_SESSION['aDataGirdAdj_1'];
	$contador = count($aDataGrid);
	if ($contador > 1) {
		unset($aDataGrid[$id]);
		$_SESSION['aDataGirdAdj_1'] = $aDataGrid;
		$sHtml = mostrar_gridAdj_1();
		$oReturn->assign("gridArchivos_1", "innerHTML", $sHtml);
	} else {
		unset($aDataGrid[0]);
		$_SESSION['aDataGirdAdj_1'] = $aDatos;
		$sHtml = "";
		$oReturn->assign("gridArchivos_1", "innerHTML", $sHtml);
	}

	return $oReturn;
}


function cargarCcos($id = '')
{
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo();
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	$oReturn->alert($id);
	$oReturn->assign("ccosn", "value", $id);


	return $oReturn;
}

function cargarDatosClpv($aForm = '', $id = 0, $tipo = "")
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo();
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oCon = new Dbo();
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	//variables de session
	$idempresa = $_SESSION['U_EMPRESA'];


	try {

		$sql = "select clpv_ruc_clpv, clpv_cod_clpv, clpv_nom_clpv,
				clv_con_clpv, clpv_etu_clpv, clpv_cod_tpago, clpv_cod_fpagop,
				clpv_pro_pago, clpv_obs_clpv, clpv_cod_mone
				from saeclpv
				where clpv_cod_empr = $idempresa and
				clpv_cod_clpv = $id";
		if ($oIfx->Query($sql)) {
			if ($oIfx->NumFilas() > 0) {
				$clpv_cod_clpv = $oIfx->f('clpv_cod_clpv');
				$clpv_ruc_clpv = $oIfx->f('clpv_ruc_clpv');
				$clpv_nom_clpv = $oIfx->f('clpv_nom_clpv');
				$clv_con_clpv = $oIfx->f('clv_con_clpv');
				$clpv_etu_clpv = $oIfx->f('clpv_etu_clpv');
				$clpv_cod_tpago = $oIfx->f('clpv_cod_tpago');
				$clpv_cod_fpagop = $oIfx->f('clpv_cod_fpagop');
				$clpv_pro_pago = $oIfx->f('clpv_pro_pago');
				$clpv_obs_clpv = $oIfx->f('clpv_obs_clpv');
				$clpv_cod_mone = $oIfx->f('clpv_cod_mone');
			}
		}
		$oIfx->Free();


		// MOSTRAR INFO TIPO REGIMEN
		// REGIMEN_BUEN_CONTRIBUYENTE:N,REGIMEN_PERCEPCION:N,DETRACCION:N,RETENCION:N
		$texto_regimen_clpv = '';
		if (!empty($clpv_obs_clpv)) {
			$array_regimen_clpv = explode(',', $clpv_obs_clpv);
			foreach ($array_regimen_clpv as $key => $array_regimen_tipo_clpv) {
				$array_regimen_data_clpv = explode(':', $array_regimen_tipo_clpv);
				$nombre_tipo = $array_regimen_data_clpv[0];
				$sn_tipo = $array_regimen_data_clpv[1];
				if ($sn_tipo == 'S') {
					$texto_regimen_clpv .= $nombre_tipo . ' - ';
				}
			}
		}
		if (!empty($texto_regimen_clpv)) {
			$texto_regimen_clpv = '<label style="color: red">' . $texto_regimen_clpv . '</label>';
			$oReturn->assign("div_regimen_clpv", "innerHTML", $texto_regimen_clpv);
		}




		// FECHA DE VENCIMIENTO
		$fecha_venc = (sumar_dias_post(date("Y-m-d"), $clpv_pro_pago)); //  Y/m/d
		list($a, $b, $c) = explode('/', $fecha_venc);
		$fecha_venc = $a . '-' . $b . '-' . $c;


		$S_PAIS_API_SRI = $_SESSION['S_PAIS_API_SRI'];
		unset($_SESSION['TIPO_IDENTIFICACION']);
		$_SESSION['TIPO_IDENTIFICACION'] = $clv_con_clpv;

		$tipoIdentificacion = '';
		if ($clv_con_clpv == '01') {
			$tipoIdentificacion = 'RUC';
		} elseif ($clv_con_clpv == '02') {
			$tipoIdentificacion = 'CED';
		} elseif ($clv_con_clpv == '03') {
			$tipoIdentificacion = 'PAS';
		}


		if ($S_PAIS_API_SRI == 593 && ($clv_con_clpv == '01' || $clv_con_clpv == '02')) {
			$oReturn->script('document.getElementById("auto_prove").type = "number"');
			$oReturn->script('document.getElementById("serie").type = "number"');
			$oReturn->script('document.getElementById("factura").type = "number"');
		}

		//query direccion
		$sqlDire = "select min(dire_dir_dire) as dire_dir_dire from saedire where dire_cod_clpv = $clpv_cod_clpv";
		$dire_dir_dire = consulta_string_func($sqlDire, 'dire_dir_dire', $oIfx, '');

		//query telefono
		$sqlTelf = "select min(tlcp_tlf_tlcp) as tlcp_tlf_tlcp from saetlcp where tlcp_cod_clpv = $clpv_cod_clpv";
		$tlcp_tlf_tlcp = consulta_string_func($sqlTelf, 'tlcp_tlf_tlcp', $oIfx, '');

		$ifu->AgregarCampoTexto('proveedor_direccion', 'Direccion|left', true, $dire_dir_dire, 350, 200);

		$ifu->AgregarCampoTexto('proveedor_telefono', 'Telefono|left', false, $tlcp_tlf_tlcp, 100, 120);

		//cargar table info clpv
		$table .= '<table class="table-striped table-bordered table-condensed" style="width: 100%; margin-bottom: 0px;" align="center">';
		$table .= '<tr>';
		$table .= '<td class="bg-info" colspan="2">INFORMACION DEL PROVEEDOR</td>';
		$table .= '</tr>';
		$table .= '<tr>';
		$table .= '<td style="font-size: 10px; font-style: italic; font-weight: bold;"><span style="color: red;">Proveedor: </span> <span style="color: blue;">' . $tipoIdentificacion . '</span>: ' . $clpv_ruc_clpv . ' - ' . $clpv_nom_clpv . '</td>';
		$table .= '<td style="font-size: 10px; font-style: italic; font-weight: bold;"><span style="color: red;">Telefono: </span>' . $ifu->ObjetoHtml('proveedor_telefono') . '</td>';
		$table .= '</tr>';
		$table .= '<tr>';
		$table .= '</tr>';
		$table .= '<tr>';
		$table .= '<td colspan="2" style="font-size: 10px; font-style: italic; font-weight: bold;"><span style="color: red;">Direccion: </span>' . $ifu->ObjetoHtml('proveedor_direccion') . '</td>';
		$table .= '</tr>';
		$table .= '</table>';

		//sql compania
		$clpv_nom = '';
		$id_owner = '';

		$sqlComp = "select id_owner from comercial.clpv_trta where id_clpv = $clpv_cod_clpv";
		$id_owner = consulta_string_func($sqlComp, 'id_owner', $oCon, '');

		if (!empty($id_owner)) {
			$sqlClpv = "select clpv_nom_clpv from saeclpv where clpv_cod_clpv = $id_owner";
			$clpv_nom = 'COMPA&Ntilde;IA: ' . consulta_string_func($sqlClpv, 'clpv_nom_clpv', $oIfx, '');
		}


		// --------------------------------------------------------------------------------------
		// VERIFICAMOS QUE EL PROVEEDOR SELECCIONADO NO SEA DEL MISMO PAIS DE LA EMPRESA (NO DOMICILIADO)
		// --------------------------------------------------------------------------------------
		$empr_cod_pais = $_SESSION['U_PAIS_COD'];
		$sql_codInter_pais = "SELECT pais_codigo_inter from saepais where pais_cod_pais = $empr_cod_pais;";
		$codigo_pais = consulta_string_func($sql_codInter_pais, 'pais_codigo_inter', $oIfx, 0);

		$sql_pcon = "SELECT pcon_cue_niif from saepcon WHERE pcon_cod_empr = $idempresa;";
		$pcon_cue_niif = consulta_string_func($sql_pcon, 'pcon_cue_niif', $oIfx, 0);

		$cod_prove = $clpv_cod_clpv;
		$sql_consulta_pais_clpv = "SELECT 
										COALESCE(pais_codigo_inter, pais_cod_inte) as pais_cod_inter
									from saeclpv
										inner join saepaisp
											on paisp_cod_paisp = clpv_cod_paisp
										inner join saepais
											on paisp_des_paisp = pais_des_pais
									where clpv_cod_clpv = $cod_prove;
									";
		$pais_cod_inter_clpv = consulta_string_func($sql_consulta_pais_clpv, 'pais_cod_inter', $oIfx, 0);

		$oReturn->script('mostrar_campos_no_domiliados(0)');

		// VERIFICAMOS QUE SEA EL PAIS PERU
		if ($codigo_pais == 51 && $pcon_cue_niif == 'S') {
			// VERIFICAMOS QUE SEA EL PROVEEDOR SEA NO DOMICILIADO
			if ($codigo_pais != $pais_cod_inter_clpv) {
				$oReturn->script('mostrar_campos_no_domiliados(1)');
			}
		}

		// --------------------------------------------------------------------------------------
		// FIN VERIFICAMOS QUE EL PROVEEDOR SELECCIONADO NO SEA DEL MISMO PAIS DE LA EMPRESA (NO DOMICILIADO)
		// --------------------------------------------------------------------------------------


		$oReturn->assign("cliente", "value", $clpv_cod_clpv);
		$oReturn->assign("tipoRuc", "value", $clv_con_clpv);
		$oReturn->assign("tipo_pago", "value", $clpv_cod_tpago);
		$oReturn->assign("forma_pago", "value", $clpv_cod_fpagop);
		$oReturn->assign("fecha_vence", "value", $fecha_venc);
		$oReturn->assign("plazo", "value", $clpv_pro_pago);
		$oReturn->assign("contribuyente", "value", $clpv_etu_clpv);
		$oReturn->assign("proveedor", "value", $clpv_ruc_clpv . ' - ' . $clpv_nom_clpv);
		$oReturn->assign("verInfoClpv", "innerHTML", $table);
		$oReturn->assign("divCompanias", "innerHTML", $clpv_nom);
		$oReturn->assign("clpvCompania", "value", $id_owner);
		$oReturn->assign("clpv_nom", "value", $clpv_nom_clpv);
		$oReturn->assign("clpv_cod", "value", $clpv_cod_clpv);

		if (!empty($clpv_cod_mone)) {
			$oReturn->assign("moneda", "value", $clpv_cod_mone);
			$oReturn->script('cargar_coti();');
		}

		$oReturn->script('cargarPlanillas();');
		$oReturn->script('check_pago();');
		$oReturn->script('cargarSustento(\'' . $tipo . '\', \'' . $clv_con_clpv . '\' );');
		$oReturn->script(('fecha_final_rs(\'' . $fecha_venc . '\' )'));
		// $oReturn->script('totales_inciva(this)');
		$oReturn->script('cargar_fecha_fact()');
	} catch (Exception $e) {
		$oReturn->alert($e->getMessage());
	}

	return $oReturn;
}

function agrega_modifica_grid_dia_cheque($nTipo = 0, $aForm = '', $idempresa = '', $idsucursal, $detalle, $mone_cod, $coti, $coti_ext)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$aDataDiar = $_SESSION['aDataGirdDiar'];

	$aLabelDiar = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad'
	);

	$oReturn = new xajaxResponse();

	// VARIABLES
	$cta_cheq   = $aForm["cta_cheq"];
	$sql = "select ctab_num_ctab, ctab_num_cheq, ctab_cod_cuen from saectab where 
                    ctab_cod_ctab = $cta_cheq and 
                    ctab_cod_empr = $idempresa and
                    ctab_cod_sucu = $idsucursal ";
	$cod_cta    = consulta_string_func($sql, 'ctab_cod_cuen', $oIfx, '');

	$sql = "select  cuen_nom_cuen  from saecuen where
                cuen_cod_empr  = $idempresa and
                cuen_cod_cuen  = '$cod_cta' ";
	$nom_cta    = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');
	$val_cta    = str_replace(",", "", $aForm['val_cheq']);
	$ben_cheq   = $aForm["ben_cheq"];
	$form_cheq  = $aForm["form_cheq"];
	$cheque     = $aForm["cheque"];
	$cta_banc   = $aForm["ctab_cheq"];
	$fec_cheq   = $aForm["fec_cheq"];
	$act_cod    = $aForm["actividad_cheq"];

	$sql        = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
	$mone_base  = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

	if ($mone_cod == $mone_base) {
		$sql      = "select pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
		$mone_extr = consulta_string_func($sql, 'pcon_seg_mone', $oIfx, '');

		$sql = "select tcam_val_tcam from saetcam where
                mone_cod_empr = $idempresa and
                tcam_cod_mone = $mone_extr and
                tcam_fec_tcam in (
                                    select max(tcam_fec_tcam)  from saetcam where
                                            mone_cod_empr = $idempresa and
                                            tcam_cod_mone = $mone_extr
                                )  ";

		$coti = $coti_ext; //consulta_string($sql, 'tcam_val_tcam', $oIfx, 0);
	}

	if ($nTipo == 0) {
		// DIARIO
		$cred = $val_cta;
		$deb  = 0;

		$contd = count($aDataDiar);
		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $cod_cta;
		$aDataDiar[$contd][$aLabelDiar[2]] = $nom_cta;
		$aDataDiar[$contd][$aLabelDiar[3]] = '';
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($cred / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($deb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra
			$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
		}

		$vacio = '-1';

		$aDataDiar[$contd][$aLabelDiar[9]] = '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
															title = "Presione aqui para Eliminar"
															style="cursor: hand !important; cursor: pointer !important;"
															onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1 );"
															alt="Eliminar"
															align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]] = $ben_cheq;
		$aDataDiar[$contd][$aLabelDiar[12]] = $cta_banc;
		$aDataDiar[$contd][$aLabelDiar[13]] = $cheque;
		$aDataDiar[$contd][$aLabelDiar[14]] = $fec_cheq;
		$aDataDiar[$contd][$aLabelDiar[15]] = $form_cheq;
		$aDataDiar[$contd][$aLabelDiar[16]] = $cta_cheq;
		$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
		$aDataDiar[$contd][$aLabelDiar[18]] = '';
		$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
	}

	// DIARIO
	$sHtml = '';
	$_SESSION['aDataGirdDiar'] = $aDataDiar;
	$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
	$oReturn->assign("divDiario", "innerHTML", $sHtml);

	$oReturn->assign("cod_cta", "value", '');
	$oReturn->assign("nom_cta", "value", '');
	$oReturn->assign("val_cta", "value", '');

	// TOTAL DIARIO
	$oReturn->script("total_diario();");
	$oReturn->script("cerrarModalch()");
	$oReturn->script("cerrar_ventana();");
	return $oReturn;
}


function calculaFechaVence($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$oReturn = new xajaxResponse();

	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $_SESSION['U_SUCURSAL'];

	$fecha_emision = $aForm['fecha_emision'];
	$plazo         = $aForm['plazo'];


	// $diaHoy = date("Y-m-d");

	// FECHA DE VENCIMIENTO
	if ($plazo > 0) {
		$fecha_venc = (sumar_dias_post($fecha_emision, $plazo)); //  Y/m/d	
		// list($a, $b, $c) = explode('/',$fecha_venc);
		// $fecha_venc = $a.'-'.$b.'-'.$c;	
		$oReturn->script(('fecha_final_rs(\'' . $fecha_venc . '\' )'));
		$oReturn->assign('fecha_vence', 'value', $fecha_venc);
	} else {
		$oReturn->assign('fecha_vence', 'value', $fecha_emision);
	}

	return $oReturn;
}



function cargarSustento($aForm = '', $tipo = "", $clv_con_clpv = 0)
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$oReturn = new xajaxResponse();

	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $_SESSION['U_SUCURSAL'];

	$cliente = $aForm['cliente'];
	$tipoRuc = $aForm['tipoRuc'];

	$strigTipoSustento = '';
	//query sustentos
	$sql = "select sustento
			from comercial.matriz_sri 
			where identificacion ='$tipoRuc' and
			estado = 'S'
			group by 1";
	if ($oCon->Query($sql)) {
		if ($oCon->NumFilas() > 0) {
			$strigTipoSustento = " where crtr_cod_crtr in( ";
			$i = 0;
			do {
				if ($i == 0) {
					$strigTipoSustento_ .= "'" . $oCon->f('sustento') . "'";
				} else {
					$strigTipoSustento_ .= ",'" . $oCon->f('sustento') . "'";
				}
				$i++;
			} while ($oCon->SiguienteRegistro());
			//$strigTipoSustento_ .= substr($strigTipoSustento_, 0, strlen($strigTipoSustento_) - 1);
			$strigTipoSustento .= $strigTipoSustento_;
			$strigTipoSustento .= " )";
		}
	}
	$oCon->Free();
	$sql = "select crtr_cod_crtr, crtr_des_crtr 
			from saecrtr
			$strigTipoSustento order by crtr_cod_crtr";
	if ($oIfx->Query($sql)) {
		$oReturn->script('eliminar_lista_sustento();');
		if ($oIfx->NumFilas() > 0) {
			$i = 1;
			do {
				$detalle = $oIfx->f('crtr_cod_crtr') . ' - ' . $oIfx->f('crtr_des_crtr');
				$oReturn->script(('anadir_elemento_sustento(' . $i++ . ',\'' . $oIfx->f('crtr_cod_crtr') . '\', \'' . $detalle . '\' )'));
			} while ($oIfx->SiguienteRegistro());
		}
	}
	$oIfx->Free();
	// echo ($clv_con_clpv);
	// exit;
	if ($clv_con_clpv == '01') {
		$oReturn->assign("sust_trib", "value", '01');
	} else {
		$oReturn->assign("sust_trib", "value", '02');
	}

	$oReturn->script('cargarTipoComprobante(\'' . $tipo . '\' );');



	return $oReturn;
}

function cargarTipoComprobante($aForm = '', $tipo = "")
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$oReturn = new xajaxResponse();

	$idempresa  = $_SESSION['U_EMPRESA'];
	$idsucursal = $_SESSION['U_SUCURSAL'];

	$cliente 	= $aForm['cliente'];
	$tipoRuc 	= $aForm['tipoRuc'];
	$sucursal 	= $aForm['sucursal'];
	$sust_trib 	= $aForm['sust_trib'];

	//$oReturn->alert($tipo);

	$strigTipoSustento = '';
	//query sustentos
	$sql = "select comprobante 
			from comercial.matriz_sri
			where identificacion = '$tipoRuc' and
			sustento = '$sust_trib' and
			estado = 'S'";
	if ($oCon->Query($sql)) {
		if ($oCon->NumFilas() > 0) {
			$strigTipoSustento = ' and trans_tip_comp in(';
			$i = 0;
			do {
				if ($i == 0) {
					$strigTipoSustento_ .= "'" . $oCon->f('comprobante') . "'";
				} else {
					$strigTipoSustento_ .= ",'" . $oCon->f('comprobante') . "'";
				}
				$i++;
			} while ($oCon->SiguienteRegistro());
			//$strigTipoSustento_ .= substr($strigTipoSustento_, 0, strlen($strigTipoSustento_) - 1);
			$strigTipoSustento .= $strigTipoSustento_;
			$strigTipoSustento .= ' )';
		}
	}
	$oCon->Free();


	$sql = "SELECT tran_cod_tran, trans_tip_comp, tran_des_tran
			FROM saetran WHERE
			(trans_tip_comp is not null ) AND
			tran_cod_empr = $idempresa AND
			tran_cod_sucu = $sucursal and
			tran_cod_modu = 4 
			$strigTipoSustento
			order by 2";


	if ($oIfx->Query($sql)) {
		$oReturn->script('eliminar_lista_comprobante();');
		if ($oIfx->NumFilas() > 0) {
			$i = 1;
			do {
				$detalle = $oIfx->f('trans_tip_comp') . ' - ' . $oIfx->f('tran_des_tran');
				$oReturn->script(('anadir_elemento_comprobante(' . $i++ . ',\'' . $oIfx->f('tran_cod_tran') . '\', \'' . $detalle . '\' )'));
			} while ($oIfx->SiguienteRegistro());
		}
	}
	$oIfx->Free();

	if ($tipo != '') {
		if ($tipo == 'ANT') {
			$tipo = 'FAC';
		}
		$oReturn->assign('comprobante', 'value', $tipo);
		$oReturn->script('cargar_factura(\'' . $tipo . '\')');
	}


	$importacion_modulos = $_SESSION['GESTION_IMPORTACION'];
	if (!empty($importacion_modulos)) {
		// Cargamos el codigo de transacion en el comprobante
		$oReturn->assign("comprobante", "value", 'FAC');
		// Cargo la factura luego de haber cargado el porveedor y el comprobante
		$oReturn->script('cargar_factura()');
	}

	return $oReturn;
}

function cargar_stotal($array, $aForm = '')
{


	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$oIfxB = new Dbo;
	$oIfxB->DSN = $DSN_Ifx;
	$oIfxB->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();
	$prod = 0;
	$serv = 0;


	$ax = explode(":", $array);



	$deta = '';
	$valor = 0;
	$control = 0;
	$codigo_oc = '';
	for ($i = 0; $i < count($ax) - 1; $i++) {


		$ay = explode(";", $ax[$i]);


		$ser = $aForm[$ay[1]];



		if ($ser == 1) {

			$control++;

			$sql = "select minv_num_comp, minv_sin_iva, minv_iva_valo, minv_cod_mone from saeminv where minv_num_comp='$ay[1]'";
			if ($oIfx->Query($sql)) {
				if ($oIfx->NumFilas() > 0) {

					$stotal = $oIfx->f('minv_sin_iva');
					$mov = $oIfx->f('minv_num_comp');
					$moneda = $oIfx->f('minv_cod_mone');
					$iva = $oIfx->f('minv_iva_valo');

					$valor += $aForm['val' . $mov];

					$sqlmov = "select dmov_cod_prod, dmov_det1_dmov, dmov_cod_pedi, dmov_cto_dmov, dmov_cod_bode
					 from saedmov ,saeprod  where dmov_cod_prod=prod_cod_prod  and dmov_num_comp='$ay[1]'";

					if ($oIfxA->Query($sqlmov)) {
						if ($oIfxA->NumFilas() > 0) {

							do {

								$codigo_oc = $ay[1];
								$cod_prod = $oIfxA->f('dmov_cod_prod');
								$bodega = $oIfxA->f('dmov_cod_bode');
								//TIPO
								$sql = "select prod_cod_tpro from saeprod where prod_cod_prod='$cod_prod'";

								$tipo_prod = consulta_string($sql, 'prod_cod_tpro', $oIfxB, '');
								//CUENTA DE INVENTARIO
								$sqlc = "select prbo_cta_inv, cuen_nom_cuen from saeprbo, saecuen where prbo_cta_inv=cuen_cod_cuen and prbo_cod_prod='$cod_prod' and prbo_cod_bode=$bodega";
								$cod_cuenta = consulta_string($sqlc, 'prbo_cta_inv', $oIfxB, '');
								$nom_cuenta = consulta_string($sqlc, 'cuen_nom_cuen', $oIfxB, '');

								$detalle = $oIfxA->f('dmov_det1_dmov');
								$pedi = $oIfxA->f('dmov_cod_pedi');


								$deta .= ' ' . $detalle;
							} while ($oIfxA->SiguienteRegistro());
						}
					}
				}
			}
		}
	}

	if ($control == 0) {
		$oReturn->alert("Seleccione una orden");
	} else {


		$oReturn->script('cerrarModalord();');

		//$oReturn->assign("cod_cta", "value", $cod_cuenta);
		//$oReturn->assign("nom_cta", "value", $nom_cuenta);

		$oReturn->assign("ctrlpedi", "value", $pedi);

		$oReturn->assign("cod_oc", "value", $codigo_oc);

		$oReturn->assign("detalle", "value", $deta);
		$oReturn->assign("moneda", "value", $moneda);
		$oReturn->script('cargar_coti()');

		if ($tipo_prod == 1) {
			$serv = $valor;
			if (round($iva, 2) > 0) {
				$oReturn->assign("valor_grab12s", "value", $serv);
			} else {
				$oReturn->assign("valor_grab0s", "value", $serv);
			}


			$oReturn->script('totales_inciva();');
		}
		if ($tipo_prod == 2) {
			$prod = $valor;
			$oReturn->assign("valor_grab12b", "value", $prod);
			$oReturn->script('totales(this);');
		}
	}


	return $oReturn;
}

function ordenCompra($aForm = '')
{
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	//variables de sesion
	$idempresa = $_SESSION['U_EMPRESA'];

	//varibales del formulario
	$cliente = $aForm['cliente'];

	$sucursal = $aForm['sucursal'];

	$aser = '';

	//FORMATO PERSONALIZADO ORDEN DE COMPRA

	$sql = "select defi_for_defi from saetran, saedefi where defi_cod_empr=tran_cod_empr 
    and defi_cod_tran=tran_cod_tran
    and tran_cod_modu=10 and tran_cod_tran like '002%' and tran_cod_empr=$idempresa 
    and tran_cod_sucu=$sucursal";

	$cod_ftrn = consulta_string($sql, 'defi_for_defi', $oIfx, '');
	$ctrl_formato = 0;
	if (!empty($cod_ftrn)) {
		$sqlf = "select ftrn_ubi_web from saeftrn where ftrn_cod_ftrn=$cod_ftrn";
		$ubi_formato = consulta_string($sqlf, 'ftrn_ubi_web', $oIfx, '');

		if (!empty($ubi_formato)) {
			$ctrl_formato++;
			include_once('../../' . $ubi_formato . '');
		}
	}

	//PARAMETRO 
	$sql = "select para_tip_oc from saepara where para_cod_empr=$idempresa";
	$tipo_oc = consulta_string($sql, 'para_tip_oc', $oIfx, 0);

	$filoc = '';
	if ($tipo_oc == 1) {
		$filoc = "and minv_tip_ord=1";
	} elseif ($tipo_oc == 2) {
		$filoc = "and minv_tip_ord is null";
	}

	//VALIDACION POR PAIS
	$S_PAIS_API_SRI = $_SESSION['S_PAIS_API_SRI'];
	try {

		$sHtml = '<div  class="modal-dialog modal-lg" role="document">
					<div class="modal-content">
						<div class="modal-header">
							<button type="button" class="close" data-dismiss="modal" aria-label="Close">
								<span aria-hidden="true">&times;</span>
							</button>
							<h4 class="modal-title" id="myModalLabel">ORDENES DE COMPRA</h4>
						</div>
						<div class="modal-body">';

		$sql = "SELECT distinct( minv_num_comp),   minv_fmov,      clpv_nom_clpv,   minv_num_sec, minv_cod_clpv,  minv_dege_minv, minv_cer_sn, minv_fec_cad,
						(COALESCE(minv_tot_minv,0)) total
						FROM saeminv,    saeclpv,    saedmov   WHERE 
						minv_cod_clpv = clpv_cod_clpv  and  
						minv_num_comp = dmov_num_comp and  
						minv_est_minv = '1'  and 
						minv_cod_tran in  ( select defi_cod_tran from saedefi Where 
												defi_tip_defi  = '4' and 
												defi_cod_empr  = $idempresa and 
												defi_cod_modu  = 10)  AND  
						minv_cod_empr = $idempresa  AND  
						minv_cod_sucu = $sucursal AND 
						clpv_cod_empr = $idempresa and
						minv_cod_clpv= $cliente and 
						dmov_can_dmov <> dmov_can_entr 
						$filoc
						-- AND (( minv_cer_sn is null) or ( minv_cer_sn = 'N' ) )
						";

		if ($oIfx->Query($sql)) {
			if ($oIfx->NumFilas() > 0) {
				$clpv_nom = $oIfx->f('clpv_nom_clpv');

				$sHtml .= '<table id="tbcargarord"class="table table-bordered table-striped table-condensed" style="width: 100%; margin-bottom: 0px;" >';
				$sHtml .= '<thead><tr>
							<td colspan="10" align="center">' . $clpv_nom . '</td>
						</tr>
						<tr>
							<th>No.</th>
							<th>Fecha</th>
							<th>Orden Compra</th>
							<th>Detalle</th>
							<th>Imprimir</th>
							<th>Total</th>
							<th>Valor a Pagar</th>
							<th>Asignar Total</th>
							<th>Seleccionar</th>
						 </tr><thead>';
				$sHtml .= '<tbody>';
				$i = 1;
				do {
					$fec_pedi = $oIfx->f('minv_fmov');
					$preimp = $oIfx->f('minv_num_sec');
					$clpv_cod = $oIfx->f('minv_cod_clpv');

					$serial = $oIfx->f('minv_num_comp');



					$total = round($oIfx->f('total'), 2);


					$estado     =  $oIfx->f('minv_cer_sn');
					$fecha_cad  =  $oIfx->f('minv_fec_cad');
					$fecha_hoy  = date('Y-m-d');
					$ctrl_ord = 0;
					if ($estado == 'N' || empty($estado)) {
						$ctrl_ord = 1;
					}

					/*if (!empty($fecha_cad)) {
						if ($fecha_cad >= $fecha_hoy) {
							$ctrl_ord = 1;
						} else {
							$ctrl_ord = 0;
						}
					} else {*/
					$sqlf = "select  sum(fprv_val_grbs) as totser, sum(fprv_val_grab) as totbie,sum (fprv_val_gr0s) as totalgr0s from saefprv where fprv_cod_pedi=$serial	";
					$valser = round(consulta_string($sqlf, 'totser', $oIfxA, 0), 2);
					$valser0 = round(consulta_string($sqlf, 'totalgr0s', $oIfxA, 0), 2);
					$valbie = intval(consulta_string($sqlf, 'totbie', $oIfxA, 0), 2);
					if ($valser > 0) {
						$total = $total - $valser;
					}
					if ($valser0 > 0) {
						$total = $total - $valser0;
					}
					if ($valbie > 0) {
						$total = $total - $valbie;
					}
					//}
					$ruta = DIR_FACTELEC . 'Include/orden_compra';
					if (!file_exists($ruta)) {
						mkdir($ruta, 0777, true);
					}

					$ruta2 = "../../Include/orden_compra/ORDEN_COMPRA_$serial.pdf";
					if (!file_exists($ruta2)) {
						if ($ctrl_formato == 0) {
							//PERU  
							if ($S_PAIS_API_SRI == '51') {
								include_once('../../Include/Formatos/comercial/orden_compra_peru.php');
								formato_orden_compra($serial);
							} else {
								reporte_orden_compra($serial);
							}
						} else {
							formato_orden_compra($serial);
						}
					}

					$orden = '<a href="' . $ruta2 . '" target="_blank"><div align="center"> <div class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-print"><span></div> </div></a>';




					$aser .= $preimp . ';' . $serial . ':';

					$array[] = array($serial, $preimp, $clpv_cod);

					$ifu->AgregarCampoCheck($serial, '', false, 1);

					//NUEVOS CAMPOS VALIDACION MÚLTIPLE

					$text = 'val' . $serial;
					$fu->AgregarCampoNumerico('val' . $serial, '', false, 0, 100, 50);
					$fu->AgregarComandoAlCambiarValor('val' . $serial, "validar_val('$text',$total)");

					$eti = 'f' . $serial;
					$fu->AgregarCampoCheck('f' . $serial, 'Check', false, 'N');
					$fu->AgregarComandoAlCambiarValor('f' . $serial, "asignar_valor($total,'$text','$eti');");

					$btn = '<i class="btn btn-primary btn-sm" onClick="javascript:cargar_oc_det_gen(\'' . $serial . '\', \'' . $idempresa . '\', \'' . $sucursal . '\')" >
					<span class="glyphicon glyphicon-list"></span>
						</i>';
					if ($ctrl_ord == 1) {
						$sHtml .= '<tr>';
						$sHtml .= '<td align="center">' . $i . '</td>';
						$sHtml .= '<td align="center">' . $fec_pedi . '</td>';
						$sHtml .= '<td align="center">' . $preimp . '</td>';
						$sHtml .= '<td align="center">' . $btn . '</td>';
						$sHtml .= '<td align="center">' . $orden . '</td>';
						$sHtml .= '<td align="right">' . number_format($total, 2, '.', ',') . '</td>';
						$sHtml .= '<td align="right">' . $fu->ObjetoHtml('val' . $serial) . '</td>';
						$sHtml .= '<td align="center">' . $fu->ObjetoHtml('f' . $serial) . '</td>';
						$sHtml .= '<td align="center">' . $ifu->ObjetoHtml($serial) . '</td>';
						$sHtml .= '</tr>';
					}



					$i++;
				} while ($oIfx->SiguienteRegistro());
				$sHtml .= '</tbody>';
				$sHtml .= '</table>';
			} else {
				$sHtml .= '<div align="center"><span><font color="red">SIN ORDENES GENERADAS</font></span></div>';
			}
		}
		$oIfx->Free();

		$sHtml .= '</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
							<button type="button" class="btn btn-primary" onclick="cargar_stotal(\'' . $aser . '\');">Procesar</button>
						</div>
					</div>
				</div>';

		$oReturn->assign("miModal", "innerHTML", $sHtml);
		$oReturn->script("init_table('tbcargarord')");
	} catch (Exception $e) {
		$oReturn->alert($e->getMessage());
	}

	return $oReturn;
}

function archivosAdjuntos($aForm = '')
{
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	//variables de sesion
	unset($_SESSION['ARRAY_ADJUNTOS_FPRV']);
	$idempresa = $_SESSION['U_EMPRESA'];

	//varibales del formulario
	$cliente = $aForm['cliente'];
	$sucursal = $aForm['sucursal'];

	try {

		$ifu->AgregarCampoTexto('titulo', 'Titulo|left', false, '', 200, 200);
		$ifu->AgregarComandoAlEscribir('titulo', 'form1.titulo.value=form1.titulo.value.toUpperCase();');

		$ifu->AgregarCampoArchivo('archivo', 'Archivo|left', false, '', 100, 100, '');

		$sHtml .= '<div class="modal-dialog modal-lg" role="document">
					<div class="modal-content">
						<div class="modal-header">
							<button type="button" class="close" data-dismiss="modal" aria-label="Close">
								<span aria-hidden="true">&times;</span>
							</button>
							<h4 class="modal-title" id="myModalLabel">SUBIR ARCHIVOS ADJUNTOS</h4>
						</div>
						<div class="modal-body">';

		$sHtml .= '<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;" align="center">';
		$sHtml .= '<tr>';
		$sHtml .= '<td>' . $ifu->ObjetoHtmlLBL('titulo') . '</td>';
		$sHtml .= '<td>' . $ifu->ObjetoHtml('titulo') . '</td>';
		$sHtml .= '<td>' . $ifu->ObjetoHtmlLBL('archivo') . '</td>';
		$sHtml .= '<td>' . $ifu->ObjetoHtml('archivo') . '</td>';
		$sHtml .= '<td align="center">
						<div class="btn btn-success btn-sm" onclick="agregarArchivo();">
							<span class="glyphicon glyphicon-plus-sign"></span>
							Agregar
						</div>
					<td>';
		$sHtml .= '</tr>';
		$sHtml .= '</table>';

		$sHtml .= '<div id="gridArchivos" style="margin-top: 20px;"></div>';

		$sHtml .= '</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
							<button type="button" class="btn btn-primary" data-dismiss="modal">Procesar</button>
						</div>
					</div>
				</div>';

		$oReturn->assign("miModal", "innerHTML", $sHtml);
	} catch (Exception $e) {
		$oReturn->alert($e->getMessage());
	}

	return $oReturn;
}

function agrega_modifica_gridAdj($nTipo = 0,  $aForm = '', $id = '', $total_fact = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$oReturn = new xajaxResponse();

	$aDataGrid = $_SESSION['aDataGirdAdj'];

	$aLabelGrid = array('Id', 'Titulo', 'Archivo', 'Eliminar');

	$archivo = substr($aForm['archivo'], 3);
	$titulo  = $aForm['titulo'];

	//GUARDA LOS DATOS DEL DETALLE
	$cont = count($aDataGrid);

	$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
	$aDataGrid[$cont][$aLabelGrid[1]] = $titulo;
	$aDataGrid[$cont][$aLabelGrid[2]] = $archivo;
	$aDataGrid[$cont][$aLabelGrid[3]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalleAdj(' . $cont . ');"
												alt="Eliminar"
												align="bottom" />';
	$_SESSION['aDataGirdAdj'] = $aDataGrid;
	$sHtml = mostrar_gridAdj();
	$oReturn->assign("gridArchivos", "innerHTML", $sHtml);

	return $oReturn;
}

function mostrar_gridAdj()
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa =  $_SESSION['U_EMPRESA'];
	$aDataGrid = $_SESSION['aDataGirdAdj'];
	$aLabelGrid = array('Id', 'Titulo', 'Archivo', 'Eliminar');

	$cont = 0;
	$total     = 0;
	foreach ($aDataGrid as $aValues) {
		$aux = 0;
		foreach ($aValues as $aVal) {
			if ($aux == 0) {
				$aDatos[$cont][$aLabelGrid[$aux]] = $cont + 1;
			} elseif ($aux == 1) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 2) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 3) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 4) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
														<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
														title = "Presione aqui para Eliminar"
														style="cursor: hand !important; cursor: pointer !important;"
														onclick="javascript:xajax_elimina_detalleAdj(' . $cont . ');"
														alt="Eliminar"
														align="bottom" />
													</div>';
			} else
				$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
			$aux++;
		}
		$cont++;
	}

	return genera_grid($aDatos, $aLabelGrid, 'Adjuntos', 98, null, $array_tot);
}

function elimina_detalleAdj($id = null)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$aLabelGrid = array('Id', 'Titulo', 'Archivo', 'Eliminar');
	$aDataGrid = $_SESSION['aDataGirdAdj'];
	$contador = count($aDataGrid);
	if ($contador > 1) {
		unset($aDataGrid[$id]);
		$_SESSION['aDataGirdAdj'] = $aDataGrid;
		$sHtml = mostrar_gridAdj();
		$oReturn->assign("gridArchivos", "innerHTML", $sHtml);
	} else {
		unset($aDataGrid[0]);
		$_SESSION['aDataGirdAdj'] = $aDatos;
		$sHtml = "";
		$oReturn->assign("gridArchivos", "innerHTML", $sHtml);
	}

	return $oReturn;
}

function totales($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	//Definiciones
	$oReturn = new xajaxResponse();


	$tasa_efectiva_sn = $aForm['tasa_efectiva_sn'];
	$idempresa = $_SESSION['U_EMPRESA'];

	// CONTRIBUYENTE ESPECIAL
	$clpv = $aForm['cliente'];
	$sql = "select clpv_etu_clpv from saeclpv where
                        clpv_clopv_clpv = 'PV' and
                        clpv_cod_clpv = '$clpv' ";
	$clpv_etu_clpv = consulta_string_func($sql, 'clpv_etu_clpv', $oIfx, 'N');

	// BIENES
	$valor_grab12b = $aForm['valor_grab12b'];
	$valor_grab0b = $aForm['valor_grab0b'];
	$iceb = $aForm['iceb'];
	$ivabp = $aForm['ivabp'];

	// -------------------------------------------------------------------------------------------------------
	// VALIDAMOS SI TIENE EL CHECK DE TASA EFECTIVA
	// -------------------------------------------------------------------------------------------------------

	if ($tasa_efectiva_sn == 'S') {

		$costo_tasa_efec2 = $valor_grab12b;                                                  // 887.55
		if ($costo_tasa_efec2 > 0) {
			$iva_tasa_efec2 = $ivabp;                                                      // 13%
			// Calculos para caluclar la tasa efectiva
			$porcentaje_impuesto2 = (100 - $iva_tasa_efec2) / 100;                        // (100 - 13)/100 => 0.87
			$costo_diferencia2 = $costo_tasa_efec2 * $porcentaje_impuesto2;                // 887.55 * 0.87 => 772.16              >Costo que baja a la tabla
			$costo_calculado2 = $costo_tasa_efec2 - $costo_diferencia2;                    // 887.55 - 772.16 => 115.38
			$iva_diferencia2 = ($costo_calculado2 * 100) / $costo_diferencia2;             // (115.38 * 100) / 772.16 => 14.9453   >Iva que baja a la tabla

			$valor_grab12b = round($costo_diferencia2, 4);
			$ivabp = round($iva_diferencia2, 6);
		}
	}
	// -------------------------------------------------------------------------------------------------------
	// FIN VALIDAMOS SI TIENE EL CHECK DE TASA EFECTIVA
	// -------------------------------------------------------------------------------------------------------
	$ivab = round((($ivabp / 100) * $valor_grab12b), 2);


	// SERVICIOS
	$valor_grab12s = $aForm['valor_grab12s'];
	$valor_grab0s = $aForm['valor_grab0s'];
	$ices = $aForm['ices'];
	$ivabp = $aForm['ivabp'];

	// -------------------------------------------------------------------------------------------------------
	// VALIDAMOS SI TIENE EL CHECK DE TASA EFECTIVA
	// -------------------------------------------------------------------------------------------------------
	if ($tasa_efectiva_sn == 'S') {
		$costo_tasa_efec = $valor_grab12s;                                                  // 887.55
		if ($costo_tasa_efec > 0) {
			$iva_tasa_efec = $ivabp;                                                      // 13%
			// Calculos para caluclar la tasa efectiva
			$porcentaje_impuesto = (100 - $iva_tasa_efec) / 100;                        // (100 - 13)/100 => 0.87
			$costo_diferencia = $costo_tasa_efec * $porcentaje_impuesto;                // 887.55 * 0.87 => 772.16              >Costo que baja a la tabla
			$costo_calculado = $costo_tasa_efec - $costo_diferencia;                    // 887.55 - 772.16 => 115.38
			$iva_diferencia = ($costo_calculado * 100) / $costo_diferencia;             // (115.38 * 100) / 772.16 => 14.9453   >Iva que baja a la tabla

			$valor_grab12s = round($costo_diferencia, 4);
			$ivabp = round($iva_diferencia, 6);
		}
	}
	// -------------------------------------------------------------------------------------------------------
	// FIN VALIDAMOS SI TIENE EL CHECK DE TASA EFECTIVA
	// -------------------------------------------------------------------------------------------------------
	$ivas = round((($ivabp / 100) * $valor_grab12s), 2);




	// TOTALES
	$valor_grab12t = $valor_grab12b + $valor_grab12s;
	$valor_grab0t = $valor_grab0b + $valor_grab0s;
	$icet = $iceb + $ices;
	$ivat = $ivab + $ivas;

	$detalles_iva_multiple = obtener_iva_multiple_factura($aForm);
	if (count($detalles_iva_multiple) > 0 && !empty($aForm['iva_multiple_json'])) {
		$valor_grab12b = 0;
		$valor_grab0b = 0;
		$ivab = 0;
		$valor_grab12s = 0;
		$valor_grab0s = 0;
		$ivas = 0;
		foreach ($detalles_iva_multiple as $detalle_iva) {
			if ($detalle_iva['tipo'] == 'bienes') {
				if ($detalle_iva['porcentaje_iva'] > 0) {
					$valor_grab12b += $detalle_iva['base_imponible'];
				} else {
					$valor_grab0b += $detalle_iva['base_imponible'];
				}
				$ivab += $detalle_iva['valor_iva'];
			} else {
				if ($detalle_iva['porcentaje_iva'] > 0) {
					$valor_grab12s += $detalle_iva['base_imponible'];
				} else {
					$valor_grab0s += $detalle_iva['base_imponible'];
				}
				$ivas += $detalle_iva['valor_iva'];
			}
		}
		$valor_grab12t = $valor_grab12b + $valor_grab12s;
		$valor_grab0t = $valor_grab0b + $valor_grab0s;
		$ivat = $ivab + $ivas;
	}

	// RETENCIONES
	$base_fue_b = $valor_grab12b + $valor_grab0b;
	$base_fue_s = $valor_grab12s + $valor_grab0s;
	$base_iva_b = $ivab;
	$base_iva_s = $ivas;

	if ($clpv_etu_clpv == '1') {
		// contribuyente especial
		//$base_iva_b = 0;
		//$base_iva_s = 0;
	}

	// VALOR RETENIDO
	$porc_ret_fue_b = $aForm['porc_ret_fue_b'];
	$porc_ret_fue_s = $aForm['porc_ret_fue_s'];
	$val_ret_fue_b = round((($base_fue_b * $porc_ret_fue_b) / 100), 2);
	$val_ret_fue_s = round((($base_fue_s * $porc_ret_fue_s) / 100), 2);

	$porc_ret_iva_b = $aForm['porc_ret_iva_b'];
	$porc_ret_iva_s = $aForm['porc_ret_iva_s'];
	$val_ret_iva_b = round((($base_iva_b * $porc_ret_iva_b) / 100), 2);
	$val_ret_iva_s = round((($base_iva_s * $porc_ret_iva_s) / 100), 2);

	$valor_exentoIva = $aForm['valor_exentoIva'];
	$valor_noObjIva = $aForm['valor_noObjIva'];


	$totals = round(($valor_grab12t + $valor_grab0t + $icet + $ivat + $valor_exentoIva + $valor_noObjIva), 2);

	$oReturn->assign("ivab", "value", $ivab);
	$oReturn->assign("ivas", "value", $ivas);
	$oReturn->assign("totals", "value", $totals);
	$oReturn->assign("valor_grab12t", "value", $valor_grab12t);
	$oReturn->assign("valor_grab0t", "value", $valor_grab0t);
	$oReturn->assign("icet", "value", $icet);
	$oReturn->assign("ivat", "value", $ivat);


	$oReturn->assign("val_gasto1", "value", ($valor_grab12t + $valor_grab0t + $icet));



	// ------------------------------------------------------------------------------------------------------------- 
	// Valores asignados a las retenciones 
	// ------------------------------------------------------------------------------------------------------------- 

	$empr_cod_pais = $_SESSION['U_PAIS_COD'];

	// DECIMALES DETRACCION
	$sql_decimales_rete = "select * from comercial.pais_etiq_imp p where p.pais_cod_pais = $empr_cod_pais and impuesto = 'DECIMALES_RETENCION' ";
	$decimales_rete = consulta_string_func($sql_decimales_rete, 'etiqueta', $oIfx, 0);
	if (empty($decimales_rete)) {
		$decimales_rete = 2;
	}

	$sql_etiqueta_pais = "SELECT p.impuesto, p.etiqueta, p.porcentaje, porcentaje2 from comercial.pais_etiq_imp p where
                p.pais_cod_pais = $empr_cod_pais 
				and p.impuesto = 'TIPO_RETENCION'";
	$etiqueta_tipo_rete = consulta_string_func($sql_etiqueta_pais, 'etiqueta', $oIfx, 'RETE');


	// --------------------------------------------------------------------------
	// VALIDACIONES PERU
	// --------------------------------------------------------------------------
	$empr_cod_pais = $_SESSION['U_PAIS_COD'];
	$sql_codInter_pais = "SELECT pais_codigo_inter from saepais where pais_cod_pais = $empr_cod_pais;";
	$codigo_pais = consulta_string_func($sql_codInter_pais, 'pais_codigo_inter', $oIfx, 0);

	$sql_pcon = "SELECT pcon_cue_niif from saepcon WHERE pcon_cod_empr = $idempresa;";
	$pcon_cue_niif = consulta_string_func($sql_pcon, 'pcon_cue_niif', $oIfx, 0);

	if ($codigo_pais == 51 && $pcon_cue_niif == 'S') {

		$tipo_retencion_ad = $aForm['tipo_retencion_ad'];
		if (empty($tipo_retencion_ad)) {
			$tipo_retencion_ad = 'R';
		}
	} else {
		$tipo_retencion_ad = 'R';
	}
	// --------------------------------------------------------------------------
	// FIN VALIDACIONES PERU
	// --------------------------------------------------------------------------



	if ($tipo_retencion_ad == 'D') {

		if ($valor_grab12b > 0 || $valor_grab0b > 0) {
			$oReturn->assign("base_fue_b", "value", $totals);
			$oReturn->assign("base_iva_b", "value", $ivat);
		} else {
			$oReturn->assign("base_fue_b", "value", 0);
			$oReturn->assign("base_iva_b", "value", 0);
		}

		if ($valor_grab12s > 0 || $valor_grab0s > 0) {
			$oReturn->assign("base_fue_s", "value", $totals);
			$oReturn->assign("base_iva_s", "value", $ivat);
		} else {
			$oReturn->assign("base_fue_s", "value", 0);
			$oReturn->assign("base_iva_s", "value", 0);
		}

		$base = $totals;
		$val_ret_fue_b = number_format(round((($base * $porc_ret_fue_b) / 100), $decimales_rete), $decimales_rete + 1, '.', '');

		$base = $totals;
		$val_ret_fue_s = number_format(round((($base * $porc_ret_fue_s) / 100), $decimales_rete), $decimales_rete + 1, '.', '');

		$base = $ivat;
		$val_ret_iva_b = number_format(round((($base * $porc_ret_iva_b) / 100), $decimales_rete), $decimales_rete + 1, '.', '');

		$base = $ivat;
		$val_ret_iva_s = number_format(round((($base * $porc_ret_iva_s) / 100), $decimales_rete), $decimales_rete + 1, '.', '');


		$oReturn->assign("val_fue_b", "value", $val_ret_fue_b);
		$oReturn->assign("val_fue_s", "value", $val_ret_fue_s);
		$oReturn->assign("val_iva_b", "value", $val_ret_iva_b);
		$oReturn->assign("val_iva_s", "value", $val_ret_iva_s);
	} else {
		$oReturn->assign("base_fue_b", "value", $base_fue_b);
		$oReturn->assign("base_fue_s", "value", $base_fue_s);
		$oReturn->assign("base_iva_b", "value", $base_iva_b);
		$oReturn->assign("base_iva_s", "value", $base_iva_s);

		$oReturn->assign("val_fue_b", "value", $val_ret_fue_b);
		$oReturn->assign("val_fue_s", "value", $val_ret_fue_s);
		$oReturn->assign("val_iva_b", "value", $val_ret_iva_b);
		$oReturn->assign("val_iva_s", "value", $val_ret_iva_s);
	}



	// ----------VERIFICAMOS SI EL PAIS ES DIFERENTE AL DE LA EMPRESA NO DOMICILIADO--------------------------------
	$empr_cod_pais = $_SESSION['U_PAIS_COD'];
	$sql_codInter_pais = "SELECT pais_codigo_inter from saepais where pais_cod_pais = $empr_cod_pais;";
	$codigo_pais = consulta_string_func($sql_codInter_pais, 'pais_codigo_inter', $oIfx, 0);
	$cod_prove = $aForm['cliente'];
	$sql_consulta_pais_clpv = "SELECT 
									COALESCE(pais_codigo_inter, pais_cod_inte) as pais_cod_inter
								from saeclpv
									inner join saepaisp
										on paisp_cod_paisp = clpv_cod_paisp
									inner join saepais
										on paisp_des_paisp = pais_des_pais
								where clpv_cod_clpv = $cod_prove;
								";
	$pais_cod_inter_clpv = consulta_string_func($sql_consulta_pais_clpv, 'pais_cod_inter', $oIfx, 0);

	if (!empty($valor_grab12b) || !empty($valor_grab0b)) {
		if ($codigo_pais != $pais_cod_inter_clpv) {
			$sql_retencion_bien = "SELECT pccp_pat_email from saepccp where pccp_cod_empr = $idempresa";
			$pccp_pat_email = consulta_string_func($sql_retencion_bien, 'pccp_pat_email', $oIfx, '');
			if (!empty($pccp_pat_email)) {
				$oReturn->assign('codigo_ret_fue_b', 'value', $pccp_pat_email);
				$oReturn->script('xajax_cod_ret_b(0, 0, xajax.getFormValues("form1"), 0);');
			}
		}
	}


	if (!empty($valor_grab12s) || !empty($valor_grab0s)) {
		if ($codigo_pais != $pais_cod_inter_clpv) {
			$sql_retencion_bien = "SELECT pccp_pat_email from saepccp where pccp_cod_empr = $idempresa";
			$pccp_pat_email = consulta_string_func($sql_retencion_bien, 'pccp_pat_email', $oIfx, '');
			if (!empty($pccp_pat_email)) {
				$oReturn->assign('codigo_ret_fue_s', 'value', $pccp_pat_email);
				$oReturn->script('xajax_cod_ret_b(1, 0, xajax.getFormValues("form1"), 0);');
			}
		}
	}


	/*
	if (!empty($valor_grab0b)) {
		if ($codigo_pais != $pais_cod_inter_clpv) {
			$sql_retencion_bien = "SELECT pccp_pat_email from saepccp where pccp_cod_empr = $idempresa";
			$pccp_pat_email = consulta_string_func($sql_retencion_bien, 'pccp_pat_email', $oIfx, '');
			if (!empty($pccp_pat_email)) {
				$oReturn->assign('codigo_ret_iva_b', 'value', $pccp_pat_email);
				$oReturn->script('xajax_cod_ret_iva(0, 0, xajax.getFormValues("form1"), 0);');
			}
		}
	}

	if (!empty($valor_grab0s)) {
		if ($codigo_pais != $pais_cod_inter_clpv) {
			$sql_retencion_bien = "SELECT pccp_pat_email from saepccp where pccp_cod_empr = $idempresa";
			$pccp_pat_email = consulta_string_func($sql_retencion_bien, 'pccp_pat_email', $oIfx, '');
			if (!empty($pccp_pat_email)) {
				$oReturn->assign('codigo_ret_iva_s', 'value', $pccp_pat_email);
				$oReturn->script('xajax_cod_ret_iva(1, 0, xajax.getFormValues("form1"), 0);');
			}
		}
	}
	*/





	// ------------------------------------------------------------------------------------------------------------- 
	// FIN Valores asignados a las retenciones 
	// ------------------------------------------------------------------------------------------------------------- 

	$oReturn->script('genera_comprobante();');

	return $oReturn;
}

function num_digito($op, $aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	//Definiciones
	$oReturn = new xajaxResponse();

	$idempresa = $_SESSION['U_EMPRESA'];

	// CONTRIBUYENTE ESPECIAL
	$factura = $aForm['factura'];
	$rete = $aForm['num_rete'];
	$prove = $aForm['cliente'];
	$serie = $aForm['serie'];

	$sql = "select  pccp_num_digi from saepccp where
                    pccp_cod_empr = $idempresa ";
	$num_digito = consulta_string_func($sql, 'pccp_num_digi', $oIfx, 9);

	if ($op == 0) {
		$len = strlen($factura);
		$ceros = cero_mas('0', abs($num_digito - $len));
		$factura = $ceros . $factura;
		// CONTROL DE NUMERO DE FACTURA
		//  EJERCICIO SAEFPRV
		list($anio_fac, $m1, $d1) = explode('-', $aForm['fecha_emision']);
		$fecha_ejer = $anio_fac . '-12-31';
		$sql = "select ejer_cod_ejer from saeejer where ejer_fec_finl = '$fecha_ejer' and ejer_cod_empr = $idempresa ";
		$idejer_fact = consulta_string_func($sql, 'ejer_cod_ejer', $oIfx, 1);
		$sql = "select count(*) as cont from saefprv where
						fprv_cod_empr = $idempresa and
                        fprv_cod_clpv = $prove and
                        fprv_num_fact = '$factura' and
                        fprv_num_seri = '$serie'
						";
		$cont = consulta_string_func($sql, 'cont', $oIfx, 0);
		if ($cont > 0) {
			$tipo_mesaje = 'info';
			$mensaje = 'Ya fue ingresado esta factura';
			$oReturn->script('alerts("' . $mensaje . '", "' . $tipo_mesaje . '");');

			$factura = '';
		}

		$oReturn->assign("factura", "value", $factura);
	} elseif ($op == 1) {
		$len = strlen($rete);
		$ceros = cero_mas('0', abs($num_digito - $len));
		$rete = $ceros . $rete;
		$oReturn->assign("num_rete", "value", $rete);
	}
	return $oReturn;
}

function pedf_num_preimp($sucursal = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$idempresa = $_SESSION['U_EMPRESA'];
	$usuario_informix = $_SESSION['U_USER_INFORMIX'];

	//      C O N S U L T A    P A R A    V E R    S I    E S    S E C U E N C I A L
	//      P O R     U S U A R I O    O    N O R M A L
	$sql_config = "select para_sec_usu from saepara where
                                para_cod_empr = $idempresa and
                                para_cod_sucu = $sucursal ";
	$para_sec_usu = consulta_string_func($sql_config, 'para_sec_usu', $oIfx, 'N');

	if ($para_sec_usu == 'S') {
		// SECUENCIAL POR USUARIO
		$sql_config_sec = "select usec_pre_pedi, usec_est_pedi, usec_isec_pedi, usec_nse_pedi from saeusec where
                                            usec_cod_empr = $idempresa and
                                            usec_cod_sucu = $sucursal and
                                            usec_cod_usua = $usuario_informix and
                                            usec_est_pedi = 'S' ";
		if ($oIfx->Query($sql_config_sec)) {
			if ($oIfx->NumFilas() > 0) {
				do {
					$secuencial = $oIfx->f('usec_isec_pedi');
					$serie = $oIfx->f('usec_nse_pedi');
					$ceros = $oIfx->f('usec_pre_pedi');
					$opcion_tmp = 1;
					//secuencial real
					$secuencial_real = secuencial_pedido(1, $serie, $secuencial, $ceros);
				} while ($oIfx->SiguienteRegistro());
			} else {
				$sql = "select para_sec_ped as para_sec_ped, para_pre_pedi from saepara where
                                                        para_cod_empr = $idempresa and
                                                        para_cod_sucu = $sucursal ";

				if ($oIfxA->Query($sql)) {
					do {
						$secuencial = $oIfxA->f('para_sec_ped');
						$ceros = $oIfxA->f('para_pre_pedi');
						$opcion_tmp = 2;
						//secuencial real
						$secuencial_real = secuencial_pedido(2, '0', $secuencial, $ceros);
					} while ($oIfxA->SiguienteRegistro());
				}
				$oIfxA->Free();
			}
		}
		$oIfx->Free();
	} elseif ($para_sec_usu == 'N') {
		// SECUENCIAL NORMAL
		//SECUENCIAL DEL PEDIDO TABLA SAEPARA
		$sql = "select para_sec_ped as para_sec_ped, para_pre_pedi from saepara where
                                para_cod_empr = $idempresa and
                                para_cod_sucu = $sucursal ";

		if ($oIfx->Query($sql)) {
			do {
				$secuencial = $oIfx->f('para_sec_ped');
				$ceros = $oIfx->f('para_pre_pedi');
				$opcion_tmp = 2;

				//secuencial real
				$secuencial_real = secuencial_pedido(2, '0', $secuencial, $ceros);
			} while ($oIfx->SiguienteRegistro());
		}
		$oIfx->Free();
	}

	return $secuencial_real;
}

/* * ************************************* */
/* DF01 :: G U A R D A      P E D I D O */
/* * ************************************* */
function guarda_pedido($codpgs, $aForm = '')
{
	//Definiciones
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$oReturn = new xajaxResponse();
	//      VARIABLES
	$idempresa      = $_SESSION['U_EMPRESA'];
	$idsucursal     = $aForm['sucursal'];
	$usuario_web    = $_SESSION['U_ID'];
	$user_web       = $_SESSION['U_ID'];
	$usuario_informix = $_SESSION['U_USER_INFORMIX'];
	$fecha_server   = date("Y-m-d ");
	$hora           = date("Y-m-d H:i:s");
	$fechaServer    = date("Y-m-d H:i:s");
	$fecha_reg_con 	=  $aForm['fecha_contable'];
	$fecha_emision_ad 	=  $aForm['fecha_emision'];
	$cotizacion_digitada 	=  $aForm['cotizacion'];
	$aDataGrid      = $_SESSION['aDataGird'];
	$aDataGridDist  = $_SESSION['aDataGirdDist'];
	$aDataGirdFp    = $_SESSION['aDataGirdFp'];

	$aDataGridDir   = $_SESSION['aDataGirdDir'];
	$aDataDiar      = $_SESSION['aDataGirdDiar'];
	$aDataGridRet   = $_SESSION['aDataGirdRet'];
	$aDataGirdAdj   = isset($_SESSION['aDataGirdAdj']) ? $_SESSION['aDataGirdAdj'] : array();

	//$oReturn->alert($aDataGridDir.'--'.$aDataDiar.'--'.$aDataGridRet);

	//CODIGO SAEPGS PAGO FACTURAS
	if (!empty($codpgs)) {
		$sqlc = "select dpgs_cod_pgs from saedpgs where dpgs_cod_dpgs=$codpgs";
		$pgs_cod_pgs = consulta_string($sqlc, 'dpgs_cod_pgs', $oCon, 0);
	} else {
		$pgs_cod_pgs = 0;
	}
	//VALIDACION FACTURAS CON ORDENE DE COMPRA SERVICIOS
	$fprv_cod_pedi = $aForm['ctrlpedi'];

	if (empty($fprv_cod_pedi)) {
		$fprv_cod_pedi = 0;
	}

	//CODIGO ORDEN DE COMPRA SERVICIOS NEUVO

	$cod_oc = $aForm['cod_oc'];

	if (empty($cod_oc)) {
		$cod_oc = 'NULL';
	}

	//CODIGO PAIS
	$S_PAIS_API_SRI = $_SESSION['S_PAIS_API_SRI'];
	$campo_fprv = '';
	$val_tip_rent = '';
	//PERU


	/*
	if ($S_PAIS_API_SRI == '51') {
		$campo_fprv = ',fprv_tip_rent';
		$tip_renta = $aForm['tip_renta'];

		if (empty($tip_renta)) $tip_renta = 'NULL';
		$val_tip_rent = ',' . $tip_renta;
	}
	*/


	if (!empty($idempresa)) {

		// TRANSACCIONALIDAD
		try {

			//registra Log de transaccion
			$Logs = new Logs(4);

			// commit
			// $oIfx->QueryT('BEGIN WORK; SET ISOLATION TO DIRTY READ;');
			$oCon->QueryT('BEGIN;');
			$oIfx->QueryT('BEGIN;');

			// transaccion de informix
			$ruc            = $aForm['ruc'];
			$cliente_nombre = $aForm['cliente_nombre'];
			$cod_prove      = $aForm['cliente'];
			$fecha_emis     = $aForm['fecha_emision'];
			$fecha_contable = $aForm['fecha_contable'];
			$fecha_retencion    = $aForm['fecha_retencion'];
			if (!empty($fecha_retencion)) {
				$fecha_retencion = $fecha_retencion . ' ' . date('H:i:s');
			}
			$fecha_retencion = $fecha_retencion != '' ? "'" . $fecha_retencion . "'" : 'NULL';
			$fecha_vence    = $aForm['fecha_vence'];
			$serie          = strtoupper($aForm['serie']);
			$factura        = strtoupper($aForm['factura']);
			$factura_inicio = $aForm['factura_inicio'];
			$factura_fin    = $aForm['factura_fin'];
			$plazo          = $aForm['plazo'];
			$sec_automatico = $aForm['sec_automatico'];

			if (empty($plazo)) {
				$plazo = 0;
			}

			$detalle        = $aForm['detalle'];
			$rise           = $aForm['rise'];
			$proyecto       = $aForm['proyecto'];
			// $actividad      = $aForm['actividad'];
			$actividad      = $aForm['actividad_marco_logico'];
			$clpvCompania 	= $aForm['clpvCompania'];







			// --------------------------------------------------------------------------------------
			// VERIFICAMOS QUE EL PROVEEDOR SELECCIONADO NO SEA DEL MISMO PAIS DE LA EMPRESA (NO DOMICILIADO)
			// --------------------------------------------------------------------------------------
			$empr_cod_pais = $_SESSION['U_PAIS_COD'];
			$sql_codInter_pais = "SELECT pais_codigo_inter from saepais where pais_cod_pais = $empr_cod_pais;";
			$codigo_pais = consulta_string_func($sql_codInter_pais, 'pais_codigo_inter', $oIfx, 0);

			$sql_pcon = "SELECT pcon_cue_niif from saepcon WHERE pcon_cod_empr = $idempresa;";
			$pcon_cue_niif = consulta_string_func($sql_pcon, 'pcon_cue_niif', $oIfx, 0);

			$sql_consulta_pais_clpv = "SELECT 
										COALESCE(pais_codigo_inter, pais_cod_inte) as pais_cod_inter
									from saeclpv
										inner join saepaisp
											on paisp_cod_paisp = clpv_cod_paisp
										inner join saepais
											on paisp_des_paisp = pais_des_pais
									where clpv_cod_clpv = $cod_prove;
									";
			$pais_cod_inter_clpv = consulta_string_func($sql_consulta_pais_clpv, 'pais_cod_inter', $oIfx, 0);

			// VERIFICAMOS QUE SEA EL PAIS PERU
			if ($codigo_pais == 51 && $pcon_cue_niif == 'S') {
				// VERIFICAMOS QUE SEA EL PROVEEDOR SEA NO DOMICILIADO
				if ($codigo_pais != $pais_cod_inter_clpv) {
					$campo_fprv = ',fprv_tip_rent, fprv_doble_tribu, fprv_exonera_opera, fprv_id_bien_serv';
					$tip_renta = $aForm['tip_renta'];
					$conv_doble_tribu = $aForm['conv_doble_tribu'];
					$exonera_opera_domici = $aForm['exonera_opera_domici'];
					$bienes_servicios_adquiridos = $aForm['bienes_servicios_adquiridos'];


					if (empty($tip_renta)) {
						$tip_renta = 'NULL';
					}
					if (empty($conv_doble_tribu)) {
						$conv_doble_tribu = 'NULL';
					}
					if (empty($exonera_opera_domici)) {
						$exonera_opera_domici = 'NULL';
					}
					if (empty($bienes_servicios_adquiridos)) {
						$bienes_servicios_adquiridos = 'NULL';
					}


					$val_tip_rent = ',' . $tip_renta . ',' . $conv_doble_tribu . ',' . $exonera_opera_domici . ',' . $bienes_servicios_adquiridos;
				}
			}

			// --------------------------------------------------------------------------------------
			// FIN VERIFICAMOS QUE EL PROVEEDOR SELECCIONADO NO SEA DEL MISMO PAIS DE LA EMPRESA (NO DOMICILIADO)
			// --------------------------------------------------------------------------------------







			$sql = "select clpv_cod_sucu, clpv_ruc_clpv, clpv_nom_clpv 
					from saeclpv 
					where clpv_cod_clpv = $cod_prove";
			if ($oIfx->Query($sql)) {
				if ($oIfx->NumFilas() > 0) {
					$clpv_cod_sucu = $oIfx->f('clpv_cod_sucu');
					$cliente_nombre = $oIfx->f('clpv_nom_clpv');
					$ruc = $oIfx->f('clpv_ruc_clpv');
				}
			}
			$oIfx->Free();

			// direccion
			$sql = "select min( dire_dir_dire ) as dire  from saedire where
					dire_cod_empr = $idempresa and
					dire_cod_clpv = $cod_prove ";
			$direccion = consulta_string_func($sql, 'dire', $oIfx, '');

			// email
			$sql = "select min( emai_ema_emai ) as ema  from saeemai where
					emai_cod_empr = $idempresa and
					emai_cod_clpv = $cod_prove";
			$fprv_email_clpv = consulta_string_func($sql, 'ema', $oIfx, '');


			if (!empty($rise)) {
				$rise = 'S';
			} else {
				$rise = 'N';
			}

			$doble_trib = $aForm['doble_trib'];
			if (!empty($doble_trib)) {
				$doble_trib = 'S';
			} else {
				$doble_trib = 'N';
			}

			$suje_rete = $aForm['suje_rete'];
			if (!empty($suje_rete)) {
				$suje_rete = 'S';
			} else {
				$suje_rete = 'N';
			}

			$comprobante    = $aForm['comprobante'];
			$tipo_doc       = $aForm['tipo_doc'];
			$empl_soli      = $aForm['empl_soli'];
			$empl_auto      = $aForm['empl_auto'];
			$tipo_pago      = $aForm['tipo_pago'];
			$forma_pago     = $aForm['forma_pago'];

			//datos forma de pago exterior
			$regimen_fiscal = $aForm['regimen_fiscal'];
			$doble_tributac = $aForm['doble_tributac'];
			$pago_sujeto_ret = $aForm['pago_sujeto_ret'];

			if (!empty($regimen_fiscal)) {
				$regimen_fiscal = 'S';
			} else {
				$regimen_fiscal = 'N';
			}

			if (!empty($doble_tributac)) {
				$doble_tributac = 'S';
			} else {
				$doble_tributac = 'N';
			}

			if (!empty($pago_sujeto_ret)) {
				$pago_sujeto_ret = 'S';
			} else {
				$pago_sujeto_ret = 'N';
			}

			//OTROS DATOS DEL PROVEEDOR
			$telefono       = $aForm['proveedor_telefono'];

			// BIENES
			$valor_grab12b  = $aForm['valor_grab12b'];
			$valor_grab0b   = $aForm['valor_grab0b'];
			$iceb           = $aForm['iceb'];
			$ivab           = $aForm['ivab'];
			$ivabp          = $aForm['ivabp'];

			// SERVICIOS
			$valor_grab12s  = $aForm['valor_grab12s'];
			$valor_grab0s   = $aForm['valor_grab0s'];
			$ices           = $aForm['ices'];
			$ivas           = $aForm['ivas'];
			$totals         = $aForm['totals'];

			// TOTAL
			$valor_grab12t  = $aForm['valor_grab12t'];
			$valor_grab0t   = $aForm['valor_grab0t'];
			$icet           = $aForm['icet'];
			$ivat           = $aForm['ivat'];
			//no objeto y excento de iva
			$valor_noObjIva = $aForm['valor_noObjIva'];
			$valor_exentoIva = $aForm['valor_exentoIva'];

			$formato         = $aForm['formato'];

			$detalles_iva_multiple = obtener_iva_multiple_factura($aForm);
			if (count($detalles_iva_multiple) > 0 && !empty($aForm['iva_multiple_json'])) {
				$valor_grab12b = 0;
				$valor_grab0b = 0;
				$ivab = 0;
				$valor_grab12s = 0;
				$valor_grab0s = 0;
				$ivas = 0;
				foreach ($detalles_iva_multiple as $detalle_iva) {
					if ($detalle_iva['tipo'] == 'bienes') {
						if ($detalle_iva['porcentaje_iva'] > 0) {
							$valor_grab12b += $detalle_iva['base_imponible'];
						} else {
							$valor_grab0b += $detalle_iva['base_imponible'];
						}
						$ivab += $detalle_iva['valor_iva'];
					} else {
						if ($detalle_iva['porcentaje_iva'] > 0) {
							$valor_grab12s += $detalle_iva['base_imponible'];
						} else {
							$valor_grab0s += $detalle_iva['base_imponible'];
						}
						$ivas += $detalle_iva['valor_iva'];
					}
				}
				$valor_grab12t = $valor_grab12b + $valor_grab12s;
				$valor_grab0t = $valor_grab0b + $valor_grab0s;
				$ivat = $ivab + $ivas;
				$totals = round(($valor_grab12t + $valor_grab0t + $icet + $ivat + $valor_exentoIva + $valor_noObjIva), 2);
			}

			if (empty($valor_exentoIva)) {
				$valor_exentoIva = 0.00;
			}

			$no_obj_iva = $aForm['no_obj_iva'];
			if (!empty($no_obj_iva)) {
				$no_obj_iva = 'S';
			} else {
				$no_obj_iva = '';
			}

			// DATOS RETENCION EMPRESA
			//$num_rete 	= $aForm['num_rete'];
			$serie_rete = $aForm['serie_rete'];
			$auto_rete 	= $aForm['auto_rete'];
			$cad_rete 	= $aForm['cad_rete'];
			//$cad_rete = $aForm['cad_rete'];
			$electronica = $aForm['electronica'];


			// DATOS RETENCION PROVEEDOR
			// BIENES
			$codigo_ret_fue_b   = $aForm['codigo_ret_fue_b'];
			$porc_ret_fue_b     = $aForm['porc_ret_fue_b'];
			$base_fue_b         = $aForm['base_fue_b'];
			$val_fue_b          = $aForm['val_fue_b'];

			// SERVICIOS
			$codigo_ret_fue_s   = $aForm['codigo_ret_fue_s'];
			$porc_ret_fue_s     = $aForm['porc_ret_fue_s'];
			$base_fue_s         = $aForm['base_fue_s'];
			$val_fue_s          = $aForm['val_fue_s'];

			//RETENCION IVA BIENES
			$codigo_ret_iva_b   = $aForm['codigo_ret_iva_b'];
			$porc_ret_iva_b     = $aForm['porc_ret_iva_b'];
			$base_iva_b         = $aForm['base_iva_b'];
			$val_iva_b          = ($aForm['val_iva_b'] != '') ? $aForm['val_iva_b'] : 0;

			//RETENCION IVA SERVICIOS
			$codigo_ret_iva_s   = $aForm['codigo_ret_iva_s'];
			$porc_ret_iva_s     = $aForm['porc_ret_iva_s'];
			$base_iva_s         = $aForm['base_iva_s'];
			$val_iva_s          = ($aForm['val_iva_s'] != '') ? $aForm['val_iva_s'] : 0;

			// DATOS AUTORIZADOS PROVEEDOR Y CREDITO FISCAL
			$auto_prove         = strtoupper($aForm['auto_prove']);
			$fecha_validez      = $aForm['fecha_validez'];
			$sust_trib          = $aForm['sust_trib'];

			// CUENTAS CONTABLES
			$fisc_b             = trim($aForm['fisc_b']);
			$fisc_s             = trim($aForm['fisc_s']);
			$gasto1             = trim($aForm['gasto1']);
			$val_gasto1         = $aForm['val_gasto1'];
			$gasto2             = trim($aForm['gasto2']);
			$val_gasto2         = $aForm['val_gasto2'];

			//centro de costos
			$ccosn_3            = $aForm['cod_ccosn_3'];
			$ccosn_4            = $aForm['cod_ccosn_4'];


			////DATOS 

			$f_pag_divi          = $aForm['f_pago_divi'];
			$ir_divi         	 = $aForm['ir_pago_divi'];
			$anio_divi           = $aForm['anio_divi'];



			/*
			if ($electronica == 'S') {
				$sqlRetp = 'S';
			} else {
				$sqlRetp = 'N';
			}

			$sql = "select retp_sec_retp
					from saeretp where 
					retp_cod_empr = $idempresa and
					retp_cod_sucu = $idsucursal and
					retp_act_retp = 1 and
					retp_elec_sn = '$sqlRetp'";
			$num_rete     = consulta_string_func($sql, 'retp_sec_retp', $oIfx, '');
			$num_rete     = secuencial(2, '', $num_rete, 9);
			*/

			// $num_rete           = $aForm['num_rete'];


			$electronica = $aForm['electronica'];
			if ($electronica == 'S') {
				$sqlRetp = 'S';
				$sql = "select retp_sec_retp
					from saeretp where 
					retp_cod_empr = $idempresa and
					retp_cod_sucu = $idsucursal and
					retp_act_retp = 1 and
					retp_elec_sn = '$sqlRetp'";
				$num_rete     = consulta_string_func($sql, 'retp_sec_retp', $oIfx, '');
				$num_rete     = secuencial(2, '', $num_rete, 9);
			} else {
				$sqlRetp = 'N';
				$num_rete           = $aForm['num_rete'];
			}



			$Logs->crearLog($factura, '', "Numero Retencion: $num_rete, $sql");

			// M O N E D A
			$sql_moneda         = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
			$moneda             = consulta_string_func($sql_moneda, 'pcon_mon_base', $oIfx, '');

			// COMPENSACION DEL IVA
			$comp2iva           = vacios($aForm['comp_2'], 0);
			$comp3iva           = vacios($aForm['comp_3'], 0);
			$comp4iva           = vacios($aForm['comp_4'], 0);
			$comp_tot_iva       = $comp2iva + $comp3iva + $comp4iva;


			// C O D I G O     D E L     E M P L E A D O     I N F O R M I X
			$sql = "SELECT usua_cod_empl, usua_nom_usua FROM SAEUSUA WHERE USUA_COD_USUA = $usuario_informix ";
			if ($oIfx->Query($sql)) {
				if ($oIfx->NumFilas() > 0) {
					$empleado = $oIfx->f('usua_cod_empl');
					$usua_nom_usua = $oIfx->f('usua_nom_usua');
				}
			}
			$oIfx->Free();

			//  EJERCICIO SAEFPRV
			list($anio_fac, $m1, $d1) = explode('-', $aForm['fecha_contable']);
			$fecha_ejer = $anio_fac . '-12-31';
			$sql = "select ejer_cod_ejer from saeejer where ejer_fec_finl = '$fecha_ejer' and ejer_cod_empr = $idempresa ";
			$idejer_fact = consulta_string_func($sql, 'ejer_cod_ejer', $oIfx, 1);

			list($anio_cont, $m2, $d2) = explode('-', $aForm['fecha_contable']);
			$idprdo_cont = $m2;
			$fecha_ejer = $anio_cont . '-12-31';
			$sql = "select ejer_cod_ejer from saeejer where ejer_fec_finl = '$fecha_ejer' and ejer_cod_empr = $idempresa ";
			$idejer_cont = consulta_string_func($sql, 'ejer_cod_ejer', $oIfx, 1);

			$c = $aForm['fecha_contable'];

			//Control de Facturas
			$sql_control = "select count(*) as contador from saeminv where minv_cod_empr = $idempresa and
							minv_cod_sucu = $idsucursal and
							minv_ser_docu = '$serie' and  
							minv_cod_clpv = $cod_prove and	 
							minv_fac_prov = '$factura' and
							minv_est_minv <> '0'";
			$contador_ = consulta_string_func($sql_control, 'contador', $oIfx, '');

			$sql_control_ = "select count(*) as cont
						   from saefprv	   where 
						   fprv_cod_empr     = $idempresa
						   and fprv_cod_sucu = $idsucursal
						   and fprv_cod_clpv = $cod_prove
						   and fprv_num_fact = '$factura'
						   and fprv_num_seri = '$serie'
						   
						   ";

			$contador_1 = consulta_string_func($sql_control_, 'cont', $oIfx, 0);

			$Logs->crearLog($factura, '', "Control: $sql_control : $contador_ $sql_control_ : $contador_1");

			if ($contador_ > 0 || $contador_1 > 0) {
				$oReturn->script("Swal.fire({
									title: 'Factura duplicada',
									text: 'Ya existe una factura registrada para este proveedor con la misma serie y número.',
									type: 'warning',
									confirmButtonText: 'Aceptar',
									width: '40%'
								})");
				$oReturn->script("jsRemoveWindowLoad();");

				$oReturn->assign("ctrl", "value", 10);
			} else {
				if ($factura_inicio != '' && $factura_fin != '') {

					//codigo clpv
					$clpvCoa = $cod_prove;

					if (!empty($clpvCompania)) {
						$clpvCoa = $clpvCompania;
					}

					$tipo_factura = $aForm['tipo_factura'];
					if ($tipo_factura == 2) {		// PREIMPRESAS

						//validacion fecha
						if (strlen($fecha_validez) < 8) {
							$fecha_validez = date("Y/m/d");
						}

						$sql = "select count(*) as cont from saecoa where 
												clpv_cod_empr = $idempresa and
												clpv_cod_clpv = $clpvCoa and
												coa_fec_vali  = '$fecha_validez' and
												coa_fact_ini  = '$factura_inicio' and
												coa_fact_fin  = '$factura_fin' and
												coa_seri_docu = '$serie' and
												coa_aut_usua  = '$auto_prove'  ";
						$cont_coa = consulta_string_func($sql, 'cont', $oIfx, 0);
						if ($cont_coa == 0) {

							$sqlc = "select coa_cod_coa from saecoa order by 1 desc limit 1";
							$codcoa = consulta_string_func($sqlc, 'coa_cod_coa', $oIfx, 0) + 1;
							$sql = "insert into saecoa ( coa_cod_coa, clpv_cod_sucu, clpv_cod_empr ,    clpv_cod_clpv, 
															coa_aut_usua,  coa_aut_impr,      coa_fact_ini,
															coa_fact_fin,  coa_seri_docu,     coa_fec_vali,  
															coa_est_coa  ) 
													values( $codcoa,  $clpv_cod_sucu,      $idempresa,        $clpvCoa,      
															'$auto_prove',   '',               '$factura_inicio',
															'$factura_fin' , '$serie',         '$fecha_validez',  
															'1'  );";
							$oIfx->QueryT($sql);
							$Logs->crearLog($factura, "", "Coa Insert $sql_update");
						} else {
							$sql_update = "update saecoa set coa_fact_ini = '$factura_inicio', 
																coa_fact_fin = '$factura_fin', 
																coa_fec_vali = '$fecha_validez' 
															where clpv_cod_empr = $idempresa and 
																clpv_cod_clpv = $clpvCoa";
							$oIfx->QueryT($sql_update);
							$Logs->crearLog($factura, "", "Coa Update $sql_update");
						}
					} // FIN PREIMPRESAS
					$controles = ($factura >= $factura_inicio && $factura <= $factura_fin);
				} else {
					$fecha_validez = $fecha_emis;
					$controles = ($factura != '');
				}

				if ($controles) {

					// ACTUALIZAR CLAVE DE ACCESO
					$clave_acc    = $aForm['clave_acceso'];
					if (!empty($clave_acc)) {
						$sql          = "update comercial.clave_acceso set estado = 'S' where empr_cod_empr = $idempresa and clave_acceso = '$clave_acc' ";
						$oCon->QueryT($sql);
					}


					// PROVEEDOR
					$sql = "select  clpv_cod_tprov, clv_con_clpv, clpv_cod_paisp, clpv_cod_cuen
								from saeclpv where
								clpv_cod_clpv = '$cod_prove' and
								clpv_cod_empr = $idempresa and
								clpv_clopv_clpv = 'PV' ";
					$prove_tprov = '';
					if ($oIfx->Query($sql)) {
						if ($oIfx->NumFilas() > 0) {
							$prove_tprov   = $oIfx->f('clpv_cod_tprov');
							$clv_con_clpv  = $oIfx->f('clv_con_clpv');
							$clpv_cod_pais = $oIfx->f('clpv_cod_paisp');
							$clpv_cod_cuen = $oIfx->f('clpv_cod_cuen');
						}
					}
					$oIfx->Free();
					// comentario
					$class = new mayorizacion_class();
					unset($array);
					$array = $class->secu_asto($oIfx, $idempresa, $idsucursal, 4, $fecha_contable, $usuario_informix, $tipo_doc);
					foreach ($array as $val) {
						$secu_asto  = $val[0];
						$secu_dia   = $val[1];
						$asto_cod   = $val[0];
						$comp_cod   = $val[1];
						$tidu       = $val[2];
						$idejer     = $val[3];
						$idprdo     = $val[4];
						$moneda     = $val[5];
						$tcambio    = $val[6];
						$empleado   = $val[7];
						$usua_nom   = $val[8];
					} // fin foreach   

					$moneda = $aForm['moneda'];

					$Logs->crearLog($factura, $secu_asto, "Asto: $secu_asto, Tidu: $tidu, Comprobante: $comp_cod");

					if ($electronica == 'S') {
						$fprv_aprob_sri = 'N';
						//$fprv_clav_sri = generaClaveAccesoSri($oIfx, '07', $idempresa, $idsucursal, $fecha_emis, $num_rete, $serie_rete);
						$fprv_auto_sri = $fprv_clav_sri;
					} else {
						$fprv_aprob_sri = 'S';
						$fprv_auto_sri  = $aForm['auto_rete'];
						$fprv_clav_sri = '';
					}

					if (empty($actividad)) {
						$actividad = 0;
					}

					if (empty($proyecto)) {
						$proyecto = 0;
					}

					if (empty($porc_ret_fue_b)) {
						$porc_ret_fue_b = 0;
					}

					if (empty($porc_ret_iva_b)) {
						$porc_ret_iva_b = 0;
					}



					if (empty($porc_ret_fue_s)) {
						$porc_ret_fue_s = 0;
					}

					if (empty($porc_ret_iva_s)) {
						$porc_ret_iva_s = 0;
					}

					if (empty($cotizacion_digitada)) {
						$cotizacion_digitada = 1;
					}
					$val_iva_s = number_format($val_iva_s, 2, '.', '');


					// SAEFPRV
					$usuario_session = $_SESSION['U_ID'];
					$fecha_servidor_asd = date('Y-m-d H:i:s');
					$factura_general_fprv = $factura;



					// ------------------------------------------------------------------
					// Script retencion control de saetran y tipo para la retencion unicamente 332 Adrian47
					// ------------------------------------------------------------------
					$comprobante = $aForm['comprobante'];
					$tipo_factura = $aForm['tipo_factura'];

					$sql_saetran = "SELECT trans_tip_comp from saetran where tran_cod_tran = '$comprobante' and trans_tip_comp is not null;";
					$trans_tip_comp = consulta_string_func($sql_saetran, 'trans_tip_comp', $oIfx, '');

					$numero_retenciones = count($aDataGridRet);

					if ($trans_tip_comp == '01' && $tipo_factura == 1 && $numero_retenciones < 2) {
						foreach ($aDataGridRet as $key47 => $data_retencion) {
							$codigo_retencion = $data_retencion['Cta Ret'];
							if ($codigo_retencion == '332') {
								$fprv_aprob_sri = 'S';
							}
						}
					}
					// ------------------------------------------------------------------
					// FIN Script retencion control de saetran y tipo para la retencion unicamente 332
					// ------------------------------------------------------------------


					$sql = "insert into saefprv (fprv_cod_empr, fprv_cod_sucu, fprv_cod_ejer,  fprv_cod_tran,
												 fprv_cod_clpv, fprv_num_fact, fprv_fec_emis,  fprv_num_dias,
												 fprv_cod_rtf1 ,fprv_cod_rtf2, fprv_cod_riva1, fprv_cod_riva2,
												 fprv_val_grab ,fprv_val_gra0, fprv_val_piva,  fprv_val_viva,
												 fprv_val_vice, fprv_val_totl, fprv_por_ret1,  fprv_por_ret2,
												 fprv_val_ret1, fprv_val_ret2, fprv_por_iva1,  fprv_por_iva2,
												 fprv_val_iva1, fprv_val_iva2, fprv_num_auto,  fprv_num_seri,
												 fprv_fec_vali, fprv_cre_fisc, fprv_cta_cfis,  fprv_cta_gast,
												 fprv_num_rete, fprv_det_fprv, 
												 fprv_val_grbs,
												 fprv_val_gr0s ,fprv_val_ices ,fprv_cta_fiss , fprv_cta_gst1 ,
												 fprv_val_bas1 ,fprv_val_bas2 , fprv_val_bas3 ,
												 fprv_val_bas4 ,fprv_val_gas1 ,fprv_val_gas2 , fprv_cod_pafa ,
												 fprv_cod_tidu ,fprv_mnt_noi , fprv_est_rise ,
												 fprv_emp_soli ,fprv_emp_aut , fprv_cod_mone , fprv_cot_fprv ,
												 fprv_cod_libro ,fprv_ruc_prov ,fprv_cod_pcch ,fprv_sec_caja ,
												 fprv_ser_rete ,fprv_aut_rete ,fprv_fec_rete , fprv_fec_regc ,fprv_rete_fec,
												 fprv_cod_fpagop ,	fprv_cod_tprov ,
												 fprv_cod_tpago ,   fprv_cod_paisp ,    fprv_ani_fprv ,  fprv_mes_fprv ,
												 fprv_apl_conv ,    fprv_pag_exte,      fprv_email_clpv,
												 fprv_nom_clpv,     fprv_dir_clpv,      fprv_tel_clpv,
												 fprv_cod_ccs1,     fprv_cod_ccs2,      fprv_val_noi,
												 fprv_val_exe,      fprv_reg_fis ,      fprv_cod_asto, fprv_num_mayo,
												 fprv_comp2_iva ,   fprv_comp3_iva ,    fprv_comp4_iva ,
												 fprv_tot_compiva , fprv_cod_ftrn ,		fprv_aprob_sri,
												 fprv_auto_sri,		fprv_cod_proy, 		fprv_cod_actv,
												 fprv_clav_sri,fprv_cod_pgs,fprv_cod_pedi,fprv_cod_solipag,
												 fprv_fech_server, fprv_user_web $campo_fprv)
											values($idempresa, $idsucursal,  $idejer_fact, '$comprobante', 
												   $cod_prove, '$factura',   '$fecha_emision_ad', $plazo,
												   '$codigo_ret_fue_b', '$codigo_ret_fue_s', '$codigo_ret_iva_b', '$codigo_ret_iva_s',
												   $valor_grab12b, $valor_grab0b,  $ivabp,   $ivat,
												   $iceb,   $totals,  '$porc_ret_fue_b', '$porc_ret_fue_s',
												   $val_fue_b,   $val_fue_s, '$porc_ret_iva_b', '$porc_ret_iva_s',
												   $val_iva_b,   $val_iva_s,   '$auto_prove', '$serie',
												   '$fecha_validez',  '$sust_trib',  '$fisc_b', '$gasto1',
												   '$num_rete',  '$detalle' ,  
												   '$valor_grab12s',
												   '$valor_grab0s',  '$ices',  '$fisc_s', '$gasto2' ,
												   '$base_fue_b',  '$base_fue_s', '$base_iva_b' ,
												   '$base_iva_s',  '$val_gasto1', '$val_gasto2' , '' ,
												   '$tipo_doc',   '$no_obj_iva',   '$rise',
												   '$empl_soli',  '$empl_auto' , $moneda, $cotizacion_digitada,
												   0,       '$ruc',   '', '' ,
												   '$serie_rete',  '$auto_rete',  '$cad_rete',  '$fecha_reg_con' ,$fecha_retencion,
												   '$forma_pago',  '$prove_tprov',
												   '$tipo_pago', '$clpv_cod_pais', $anio_fac,  $m1,
												   '$doble_tributac', '$pago_sujeto_ret',    '$fprv_email_clpv',
												   '$cliente_nombre', '$direccion', '$telefono',
												   '$ccosn_3', '$ccosn_4', '$valor_noObjIva',
												   '$valor_exentoIva', '$regimen_fiscal', '$secu_asto', '$comp_cod',
												   '$comp2iva',         '$comp3iva',    '$comp4iva',
												   '$comp_tot_iva',   '$formato' ,	'$fprv_aprob_sri',
												   '$fprv_auto_sri',	'$proyecto', '$actividad',
												   '$fprv_clav_sri',$codpgs,$cod_oc,$pgs_cod_pgs,
													'$fecha_servidor_asd', '$usuario_session' $val_tip_rent
												); ";
					$oIfx->QueryT($sql);

					$Logs->crearLog($factura, $secu_asto, $sql);
					if (!guardar_iva_multiple_factura($oIfx, $idempresa, $idsucursal, $factura, $serie, $cod_prove, $usuario_session, $aForm)) {
						throw new Exception('No se pudo guardar el detalle de IVA multiple');
					}

					$sql = "select trans_tip_comp from saetran where
									tran_cod_empr = $idempresa  and
									tran_cod_modu = 4 and
									tran_cod_tran =  '$comprobante'  ";
					$tipo_comprob = consulta_string_func($sql, 'trans_tip_comp', $oIfx, '');
					$serie_tmp = substr($serie, 0, 3);
					$estab_tmp = substr($serie, 3, 6);

					if (!empty($no_obj_iva)) {
						$basenograiva = $valor_grab0t;
						$baseimponible = 0;
					} else {
						$basenograiva = 0;
						$baseimponible = $valor_grab0t;
					}
					$basenograiva 	= number_format($basenograiva, 2, '.', '');
					$baseimponible 	= number_format($baseimponible, 2, '.', '');
					$baseimpgrav 	= number_format($valor_grab12t, 2, '.', '');
					$montoice 		= number_format($icet, 2, '.', '');
					$montoiva 		= number_format($ivat, 2, '.', '');

					$serie_rete_tmp = substr($serie_rete, 0, 3);
					$estab_rete_tmp = substr($serie_rete, 3, 6);

					// M A Y O R I Z A C I O N       
					$ret_asumido    = $aForm['ret_asumido'];
					$formato        = $aForm['formato'];

					// SAEASTO
					$sql = $class->saeasto(
						$oIfx,
						$secu_asto,
						$idempresa,
						$idsucursal,
						$idejer,
						$idprdo,
						$moneda,
						$usuario_informix,
						$comprobante,
						$cliente_nombre,
						$totals,
						$fecha_contable,
						$detalle,
						$secu_dia,
						$fecha_contable,
						$tidu,
						$usua_nom_usua,
						$user_web,
						4,
						$formato
					);

					$Logs->crearLog($factura, $secu_asto, $sql);

					$val_fue_b = vacios($val_fue_b, 0);
					$val_fue_s = vacios($val_fue_s, 0);
					$val_iva_b = vacios($val_iva_b, 0);
					$val_iva_s = vacios($val_iva_s, 0);


					// NUEVO DIR , RET , DIAIRO
					// SAEDIR
					$x = 1;
					$j = 1;
					$cod_dir = 0;
					if (count($aDataGridDir) > 0) {
						foreach ($aDataGridDir as $aValues) {
							$aux = 0;
							$total = 0;
							foreach ($aValues as $aVal) {
								if ($aux == 0) {
									// CONT
									$cod_dir++;
								} elseif ($aux == 1) {
									// CLPV COD
									$clpv_cod  = $aVal;
									$sql = "select  clpv_nom_clpv from saeclpv where clpv_cod_clpv = $clpv_cod and clpv_cod_empr = $idempresa ";
									$clpv_nom = consulta_string_func($sql, 'clpv_nom_clpv', $oIfx, '');
								} elseif ($aux == 2) {
									// CCLI
									$ccli_cod = $aVal;
								} elseif ($aux == 3) {
									// TIPO
									$tipo = $aVal;
								} elseif ($aux == 4) {
									// FACTURA
									$factura  = $aVal;
								} elseif ($aux == 5) {
									// FECHA VENCE
									//$fec_vence = fecha_informix_func($aVal);
									$fec_vence = ($aVal);
									$array_f = explode("-", $fec_vence);
									if ($array_f[0] != '') {
										$fec_vence = $aVal;
									}
									//$fec_vence = ($aVal);
								} elseif ($aux == 6) {
									// DETALLE
									$detalle = $aVal;
								} elseif ($aux == 7) {
									// COTIZACION
									$cotiza = $aVal;
								} elseif ($aux == 8) {
									// DEBITO
									$debito = $aVal;
								} elseif ($aux == 9) {
									// CREDITO
									$credito = $aVal;
								} elseif ($aux == 10) {
									// DEBITO EXT
									$debito_ext = $aVal;
								} elseif ($aux == 11) {
									// CREDITO EXT
									$credito_ext = $aVal;

									$sql = $class->saedir(
										$oIfx,
										$idempresa,
										$idsucursal,
										$idprdo,
										$idejer,
										$secu_asto,
										$clpv_cod,
										4,
										$tipo,
										$factura,
										$fec_vence,
										$detalle,
										$debito,
										$credito,
										$debito_ext,
										$credito_ext,
										'DB',
										'',
										'',
										'',
										'',
										'',
										'',
										$user_web,
										$cod_dir,
										$cotiza,
										$clpv_nom,
										$ccli_cod
									);
									$Logs->crearLog($factura, $secu_asto, $sql);
								}
								$aux++;
							}
							$x++;
							$j++;
						} // fin foreach

					}

					// RET

					if (count($aDataGridRet) > 0) {
						$x = 1;
						$j = 1;
						foreach ($aDataGridRet as $aValues) {
							$aux = 0;
							$total = 0;
							foreach ($aValues as $aVal) {
								if ($aux == 0) {
									$ret_secu = $aVal + 1;
								} elseif ($aux == 1) {
									$cta_ret = $aVal;
								} elseif ($aux == 2) {
									$clpv_cod = $aVal;
									$sql = "select  clpv_nom_clpv, clpv_ruc_clpv from saeclpv where clpv_cod_clpv = $clpv_cod and clpv_cod_empr = $idempresa ";
									if ($oIfx->Query($sql)) {
										$clpv_nom = $oIfx->f('clpv_nom_clpv');
										$clpv_ruc = $oIfx->f('clpv_ruc_clpv');
									}

									$sql = "select dire_dir_dire from saedire where 
                                                        dire_cod_empr = $idempresa and
                                                        dire_cod_clpv = $clpv_cod ";
									$clpv_dir = consulta_string_func($sql, 'dire_dir_dire', $oIfx, '');
									$sql = "select emai_ema_emai from saeemai where
                                                        emai_cod_empr = $idempresa  and
                                                        emai_cod_clpv = $clpv_cod ";
									$clpv_correo = consulta_string_func($sql, 'emai_ema_emai', $oIfx, '');
								} elseif ($aux == 3) {
									$factura = $aVal;
								} elseif ($aux == 4) {
									$ret_clpv = $aVal;
								} elseif ($aux == 5) {
									$ret_porc = $aVal;
								} elseif ($aux == 6) {
									$ret_base = $aVal;
								} elseif ($aux == 7) {
									$ret_val = $aVal;
								} elseif ($aux == 8) {
									$ret_num = $aVal;
								} elseif ($aux == 9) {
									$ret_det = $aVal;
								} elseif ($aux == 10) {
									$cotiza  = $aVal;
								} elseif ($aux == 11) {
									$debito  = $aVal;
								} elseif ($aux == 12) {
									$credito_ext = $aVal;
								} elseif ($aux == 13) {
									$debito_ext  = $aVal;
								} elseif ($aux == 14) {
									$credito = $aVal;

									if (empty(trim($factura))) {
										$factura = $factura_general_fprv;
									}

									// ------------------------------------------------------------------
									// Script retencion control de saetran y tipo para la retencion unicamente 332 Adrian47
									// ------------------------------------------------------------------
									$comprobante = $aForm['comprobante'];
									$tipo_factura = $aForm['tipo_factura'];

									$sql_saetran = "SELECT trans_tip_comp from saetran where tran_cod_tran = '$comprobante' and trans_tip_comp is not null;";
									$trans_tip_comp = consulta_string_func($sql_saetran, 'trans_tip_comp', $oIfx, '');

									$numero_retenciones = count($aDataGridRet);

									if ($trans_tip_comp == '01' && $tipo_factura == 1 && $numero_retenciones < 2) {
										foreach ($aDataGridRet as $key47 => $data_retencion) {
											$codigo_retencion = $data_retencion['Cta Ret'];
											if ($codigo_retencion == '332') {
												$num_rete = 000000000;
											}
										}
									}
									// ------------------------------------------------------------------
									// FIN Script retencion control de saetran y tipo para la retencion unicamente 332
									// ------------------------------------------------------------------




									$sql = $class->saeret(
										$oIfx,
										$idempresa,
										$idsucursal,
										$idprdo,
										$idejer,
										$secu_asto,
										$clpv_cod,
										$clpv_nom,
										$clpv_dir,
										'',
										$clpv_ruc,
										$ret_secu,
										$cta_ret,
										$ret_porc,
										$ret_base,
										$ret_val,
										$num_rete,
										$ret_det,
										$debito,
										$credito,
										$debito_ext,
										$credito_ext,
										$factura,
										$serie_rete,
										$auto_rete,
										'',
										$clpv_correo,
										$sqlRetp,
										$cotiza,
										$f_pag_divi,
										$anio_divi,
										$ir_divi
									);
									$Logs->crearLog($factura, $secu_asto, $sql);
								}
								$aux++;
							}
							$x++;
							$j++;
						} // fin foreach
					}

					//$oReturn->alert(count($aDataDiar));
					// DIARIO
					if (count($aDataDiar) > 0) {
						$x = 1;
						$j = 1;
						$total = 0;
						$dasi_cod = 0;
						foreach ($aDataDiar as $aValues) {
							$aux = 0;
							foreach ($aValues as $aVal) {
								if ($aux == 0) {
									$dasi_cod++;
								} elseif ($aux == 1) {
									$cta_cod = $aVal;
								} elseif ($aux == 2) {
									$cta_nom = $aVal;
								} elseif ($aux == 3) {
									$documento = $aVal;
								} elseif ($aux == 4) {
									$cotiza = $aVal;
								} elseif ($aux == 5) {
									$debito = $aVal;
								} elseif ($aux == 6) {
									$credito = $aVal;
									$total += $debito;
								} elseif ($aux == 7) {
									$debito_ext  = $aVal;
								} elseif ($aux == 8) {
									$credito_ext = $aVal;
								} elseif ($aux == 11) {
									// BENEFICIARIO
									$ben_cheq = $aVal;
								} elseif ($aux == 12) {
									// CTA BANCARIA
									$cta_cheq = $aVal;
								} elseif ($aux == 13) {
									// CHEQUE
									$num_cheq = $aVal;
								} elseif ($aux == 14) {
									// FECHA VENC
									$fec_cheq = $aVal;
								} elseif ($aux == 15) {
									// FORMATO CHEQUE
									$form_cheq = $aVal;
								} elseif ($aux == 16) {
									// CTA COD
									$cod_cheq = $aVal;
								} elseif ($aux == 17) {
									$detalle_dasi = $aVal;
								} elseif ($aux == 18) {
									$ccosn_cod = $aVal;
								} elseif ($aux == 19) {
									$act_cod   = $aVal;

									$opBand = 'S';
									$opBacn = 'N';
									$opFlch = '';
									if (!empty($cta_cheq)) {
										$opBacn = 'S';
										$opFlch = 1;
									}

									if (empty($num_cheq)) {
										$num_cheq = $documento;
									}

									//$oReturn->alert('debito '.$detalle_dasi);
									//$oReturn->alert('credi '.$credito);

									// DASI

									// Adrian47 
									// Metodo unicamente para Cruz Roja 
									$sql_empr = "SELECT empr_ruc_empr FROM saeempr where empr_cod_empr = $idempresa";
									$ruc_empr     = consulta_string($sql_empr, 'empr_ruc_empr', $oIfx, '');
									if ($ruc_empr == '1791241746001') {
										if (empty($ccosn_cod) || $ccosn_cod == '') {
											$ccosn_cod  = $aForm["ccosn"];
										}
									}




									$sql = $class->saedasi(
										$oIfx,
										$idempresa,
										$idsucursal,
										$cta_cod,
										$idprdo,
										$idejer,
										$ccosn_cod,
										$debito,
										$credito,
										$debito_ext,
										$credito_ext,
										$cotiza,
										$detalle_dasi,
										'',
										'',
										$user_web,
										$secu_asto,
										$dasi_cod_ret,
										$dasi_dir,
										$dasi_cta_ret,
										$opBand,
										$opBacn,
										$opFlch,
										$num_cheq,
										$act_cod
									);
									$Logs->crearLog($factura, $secu_asto, $sql);

									if ($cod_cheq > 0) {
										// INGRESO DE CHEQUE
										$sql = "insert into saedchc ( dchc_cod_ctab,   dchc_cod_asto ,
                                                                              asto_cod_empr,   asto_cod_sucu ,
                                                                              asto_cod_ejer,   asto_num_prdo ,
                                                                              dchc_num_dchc,   dchc_val_dchc ,
                                                                              dchc_cta_dchc,   dchc_fec_dchc ,
                                                                              dchc_benf_dchc,  dchc_cod_cuen ,
                                                                              dchc_nom_banc ,  dchc_con_fila )
                                                                    values ( $cod_cheq,        '$secu_asto',
                                                                             $idempresa,       $idsucursal,
                                                                             $idejer,          $idprdo,
                                                                             '$num_cheq',      $credito,
                                                                             '$cta_cheq',      '$fec_cheq',
                                                                             '$ben_cheq',      '$cta_cod',
                                                                             '$cta_nom',        $dasi_cod    ) ";
										$oIfx->QueryT($sql);
										$Logs->crearLog($factura, $secu_asto, $sql);

										// UPDATE CHEQUE
										$sql = "update saectab set ctab_num_cheq = '$num_cheq' where
                                                            ctab_cod_empr = $idempresa and
                                                            ctab_cod_sucu = $idsucursal and
                                                            ctab_cod_ctab = $cod_cheq ";
										$oIfx->QueryT($sql);
										$Logs->crearLog($factura, $secu_asto, $sql);
									}
								}
								$aux++;
							}
							$x++;
							$j++;
						} // fin foreach
					} // fin


					// ACTUALIZACION SAEASTO
					if (empty($total)) {
						$total = '0';
					}
					$sql = "update saeasto set asto_est_asto = 'MY', 
									asto_vat_asto = $total  where
									asto_cod_empr = $idempresa  and
									asto_cod_sucu = $idsucursal and
									asto_cod_asto = '$secu_asto' and
									asto_cod_ejer = $idejer and
									asto_num_prdo = $idprdo and
									asto_cod_empr = $idempresa and     
									asto_cod_sucu = $idsucursal and
									asto_user_web = $user_web ";
					$oIfx->QueryT($sql);
					$Logs->crearLog($factura, $secu_asto, $sql);


					// ORDE DE PRODUCCION
					unset($array_op);
					$array_op = $aForm['orden_op'];
					$fec_op   = $aForm['fecha_emision'];
					if (count($array_op) > 0) {
						foreach ($array_op as $val) {
							$op_cod 	= $val[0];

							$sql = "select  orpr_sec_orpr, orpr_cod_orpr   from saeorpr where	orpr_cod_empr = $idempresa and orpr_sec_orpr = '$op_cod'   ";
							$orpr_cod_orpr = consulta_string_func($sql, 'orpr_cod_orpr', $oIfx, '');

							$sql = "insert into orpr_fac_prove (	empr_cod_empr,		sucu_cod_sucu,		clpv_cod_clpv,
																	clpv_nom_clpv, 		clpv_ruc_clpv,		fprv_num_fact,
																	fprv_num_serie,		fprv_fech_fact,		orpr_sec_orpr,	
																	orpr_cod_orpr,		fprv_cod_asto,		fecha_server,
																	usuario_id,			fprv_tot_fprv )
														 values(    $idempresa,			$idsucursal,		$cod_prove,
																	 '$cliente_nombre' 	,'$ruc',			'$factura',
																	 '$serie',			'$fec_op',			$op_cod,
																	 '$orpr_cod_orpr',  '$secu_asto',		now(),
																	  $user_web,		$total
																) ";
							$oCon->QueryT($sql);

							$sql = "update saeorpr set orpr_est_fact = 'S' where orpr_cod_empr = $idempresa and orpr_sec_orpr = '$op_cod' ";
							$oIfx->QueryT($sql);
						}
					}

					// REEMBOLSO
					if (isset($aDataGrid)) {
						if (count($aDataGrid) > 0) {
							/*    0   'Id',               1   'Comprobante',      2   'Iden',         3   'Ruc',          
							4   'Fecha Emision',    5   'Proveedor',        6   'Factura',      7   'Serie',            
							8   'Estab',            9   'Fecha Validez',    10  'Autorizacion', 11   'Rise', 
							12  'NoIVa',            13  'Fecha Registro',   14  'Detalle',      15  'Cta',           
							16  'Valor14b',         17  'Valor0b',          18  'Iceb',         19  'Ivab',    
							20  'Ivapb',            21  'Valor14s',         22  'Valor0s',      23  'Ices',     
							24  'Ivas',             25  'Total',            26  'Valor14t',     27  'Valor0t',          
							28  'Icet',             29   'Ivat',            30  'NoObjiva',     31  'ExceIva',          
							32  'Centro Costo',     33   'Eliminar' 
							*/
							$cont = 0;
							foreach ($aDataGrid as $aValues) {
								$aux = 0;
								foreach ($aValues as $aVal) {
									if ($aux == 0) {
										$idreemb = $cont + 1;
									} elseif ($aux == 1) {
										$tran_reemb = $aVal;
									} elseif ($aux == 2) {
										$iden_reemb = $aVal;
									} elseif ($aux == 3) {
										$ruc_reemb   = $aVal;
									} elseif ($aux == 4) {
										$fecha_emision_reemb = $aVal;
									} elseif ($aux == 5) {
										$clpv_reemb = $aVal;
									} elseif ($aux == 6) {
										$factura_reemb = $aVal;
									} elseif ($aux == 7) {
										$serie_reemb = $aVal;
									} elseif ($aux == 8) {
										$estab_reemb = $aVal;
									} elseif ($aux == 9) {
										$fecha_validez_reemb = $aVal;
									} elseif ($aux == 10) {
										$auto_reemb = $aVal;
									} elseif ($aux == 11) {
										$rise_reemb = $aVal;
									} elseif ($aux == 12) {
										$noiva_reemb = $aVal;
									} elseif ($aux == 13) {
										$fecha_reg_reemb = $aVal;
									} elseif ($aux == 14) {
										$detalle_reemb = $aVal;
									} elseif ($aux == 15) {
										$cta_gasto_reemb = $aVal;
									} elseif ($aux == 16) {
										$valor14b_reemb = $aVal;
									} elseif ($aux == 17) {
										$valor_grab0b_reemb = $aVal;
									} elseif ($aux == 18) {
										$iceb_reemb = $aVal;
									} elseif ($aux == 19) {
										$ivab_reemb = $aVal;
									} elseif ($aux == 20) {
										$ivabp_reemb = $aVal;
									} elseif ($aux == 21) {
										$valor14s_reemb = $aVal;
									} elseif ($aux == 22) {
										$valor0s_reemb = $aVal;
									} elseif ($aux == 23) {
										$ices_reemb = $aVal;
									} elseif ($aux == 24) {
										$ivas_reemb = $aVal;
									} elseif ($aux == 25) {
										$total_reemb = $aVal;
									} elseif ($aux == 26) {
										$valor14t_reemb = $aVal;
									} elseif ($aux == 27) {
										$valor0t_reemb = $aVal;
									} elseif ($aux == 28) {
										$icet_reemb = $aVal;
									} elseif ($aux == 29) {
										$ivat_reemb = $aVal;
									} elseif ($aux == 30) {
										$no_obj_iva_reemb = $aVal;
									} elseif ($aux == 31) {
										$exceiva_reemb = $aVal;
									} elseif ($aux == 32) {
										$ccosn_gasto_reemb = $aVal;
									} elseif ($aux == 33) {
										$totalb = $valor14b_reemb + $valor_grab0b_reemb + $ivab_reemb + $iceb_reemb;
										$totals = $valor14s_reemb + $valor0s_reemb      + $ivas_reemb + $ices_reemb;

										$anio_ad = substr($fecha_emision_reemb, 0, 4);
										$mes_ad = substr($fecha_emision_reemb, 5, 2);

										$sql = "insert into saefprr ( 
												fprr_cod_empr ,       fprr_cod_sucu  ,    fprr_cod_ejer  ,    fprr_cod_tran ,
												fprr_fac_fprv ,     fprr_clpv_fprv ,    fprr_seri_fprv ,    fprr_cod_mone ,
												fprr_det_fprr ,     fprr_ruc_prov ,     fprr_nom_prov ,     fprr_num_seri  ,
												fprr_num_esta ,     fprr_num_fact  ,    fprr_num_auto ,     fprr_fec_vali ,
												fprr_fec_emis ,     fprr_val_grab ,     fprr_val_gra0 ,     fprr_val_gras ,
												fprr_val_grs0 ,     fprr_val_pivb ,     fprr_val_vivb ,     fprr_val_pivs ,
												fprr_val_vivs ,     fprr_val_vicb ,     fprr_val_ices ,     fprr_val_totb ,

												fprr_val_tots ,     fprr_val_totl ,     fprr_mnt_noi ,      fprr_est_rise ,
												fprr_ban_espe ,     fprr_cot_fprr ,     fprr_cod_libro ,    fprr_cod_pcch ,
												fprr_sec_caja ,     fprr_fec_regc ,     fprr_ani_fprr ,     fprr_mes_fprr ,
												fprr_cta_gast ,     fprr_val_noi ,      fprr_val_exe,       fprr_cod_ccosn,
												fprr_tip_iden 	)
									   values ( $idempresa,         $idsucursal,        $idejer_fact,       '$tran_reemb',  
												'$factura',         $cod_prove,         '$serie',           '$moneda',
												'$detalle_reemb',   '$ruc_reemb'    ,   '$clpv_reemb'    ,   '$serie_reemb',
												'$estab_reemb',     '$factura_reemb',   '$auto_reemb',       '$fecha_validez_reemb'    ,
												'$fecha_emision_reemb', '$valor14b_reemb', '$valor_grab0b_reemb', '$valor14s_reemb',
												'$valor0s_reemb',    '$ivabp_reemb',     '$ivab_reemb',       '$ivabp_reemb',
												'$ivas_reemb',      '$iceb_reemb',      '$ices_reemb',       '$totalb',
												'$totals',          '$total_reemb',     '$noiva_reemb',      '$rise_reemb',
												 '',                '1',                0,                  0,
												 '',                '$fecha_reg_reemb',  '$anio_ad',                 '$mes_ad',
												 '$cta_gasto_reemb', '$no_obj_iva_reemb', '$exceiva_reemb',  '$ccosn_gasto_reemb' ,
												 '$iden_reemb'	); ";

										$oIfx->QueryT($sql);
										$Logs->crearLog($factura, $secu_asto, $sql);
									}
									$aux++;
								}
								$cont++;
							}
						}
					}

					//archivos adjuntos
					$aDataGirdAdj = isset($_SESSION['aDataGirdAdj']) ? $_SESSION['aDataGirdAdj'] : array();
					if (isset($aDataGirdAdj)) {
						if (count($aDataGirdAdj) > 0) {
							foreach ($aDataGirdAdj as $aValues) {
								$aux = 0;
								foreach ($aValues as $aVal) {
									if ($aux == 0) {
										$idAdj = $aVal;
									} elseif ($aux == 1) {
										$titulo = $aVal;
									} elseif ($aux == 2) {
										$adjunto = $aVal;

										$sql = "insert into comercial.adjuntos (id_empresa, id_sucursal, id_clpv, id_ejer, id_prdo,
																	tipo_doc, asto, documento, titulo, ruta, estado,
																	fecha_server, user_web)
															values($idempresa, $idsucursal, $cod_prove, $idejer_fact, $idprdo_cont,
																	'FPRV', '$asto_cod', '$factura', '$titulo', '$adjunto', 'A',
																	'$fechaServer', $usuario_web)";
										$oCon->QueryT($sql);
										$Logs->crearLog($factura, $secu_asto, $sql);
									}
									$aux++;
								} //fin foreach
							} //fin foreach
						} //fin if
					}




					//beneficiarios
					$aDataGirdAdj_1 = $_SESSION['aDataGirdAdj_1'];
					if (isset($aDataGirdAdj_1)) {
						if (count($aDataGirdAdj_1) > 0) {
							$fecha_1 = $aForm['fecha_emision'];
							foreach ($aDataGirdAdj_1 as $aValuesBen) {
								$aux = 0;
								foreach ($aValuesBen as $aValBen) {
									if ($aux == 0) {
										$idAdj = $aValBen;
									} elseif ($aux == 1) {
										$idContrato = $aValBen;
									} elseif ($aux == 2) {
										$idClpv = $aValBen;
									} elseif ($aux == 3) {
										$nomClpv = $aValBen;
									} elseif ($aux == 4) {
										$valorClpv = $aValBen;
										if (empty($valorClpv)) {
											$valorClpv = 0;
										}

										$sql = "insert into proy_fact_benf(id_empresa, id_sucursal, id_prove, serie, factura,
												fecha, asto, ejer, prdo, id_clpv, id_contrato, 
												valor, estado, fecha_server, user_web)
												values($idempresa, $idsucursal, $cod_prove, '$serie', '$factura',
												'$fecha_1', '$asto_cod', '$idejer_fact', '$idprdo_cont', $idClpv, $idContrato,
												$valorClpv, 'A', '$fechaServer', $usuario_web)";
										$oCon->QueryT($sql);
										//$oReturn->alert($sql);
									}
									$aux++;
								} //fin foreach
							} //fin foreach
						} //fin if
					}




					// SECUENCIAL DE LA RETENCION  UPDATE SECUENCIA SAESECU
					$sql = "select retp_sec_retp 
							from saeretp 
							where retp_cod_empr = $idempresa and
							retp_cod_sucu = $idsucursal and
							retp_act_retp = 1 and
							retp_elec_sn = '$sqlRetp'";
					//$oReturn->alert($sql);
					$retp_sec_retp = consulta_string_func($sql, 'retp_sec_retp', $oIfx, 0);

					if ($num_rete != '000000000') {
						$sql = "update saeretp set retp_sec_retp = ($retp_sec_retp+1) 
								where retp_cod_empr = $idempresa and
								retp_cod_sucu = $idsucursal and
								retp_act_retp = 1 and
								retp_elec_sn = '$sqlRetp'";
						$oIfx->QueryT($sql);
						$Logs->crearLog($factura, $secu_asto, $sql);
					}



					//ACTUALIZACION ESTADO SOLICITUD DE PAGOS
					if (!empty($codpgs)) {

						$sql = "select fprv_cod_asto, fprv_val_totl from saefprv where fprv_num_fact='$factura' and fprv_cod_clpv=$cod_prove and  fprv_num_seri = '$serie'";
						$fasto = consulta_string($sql, 'fprv_cod_asto', $oIfx, '');
						$ftotal = consulta_string($sql, 'fprv_val_totl', $oIfx, 0);
						$sql = "select dmcp_num_fac from saedmcp where dmcp_cod_asto='$fasto'";
						$nfact = consulta_string($sql, 'dmcp_num_fac', $oIfx, '');


						if (!empty($nfact)) {

							$sqlf = "update saedpgs set dpgs_est_fact='S' where dpgs_cod_dpgs=$codpgs";
							$oIfx->QueryT($sqlf);

							$sqld = "update saedpgs set dpgs_ncom_dpgs='$nfact', dpgs_vpag_dpgs=$ftotal  where dpgs_cod_dpgs=$codpgs";
							$oIfx->QueryT($sqld);
						}

						//$sqlp="update saepgs set pgs_est_pgs=7 where pgs_cod_pgs=$codpgs";
						//$oIfx->QueryT($sqlp);


					}

					if (!empty($cod_oc)) {
						//VALDACION ORDEN DE SERVICIOS

						$sqltip = "select minv_tip_ord from saeminv, saedmov where minv_num_comp=dmov_num_comp and dmov_num_comp=$cod_oc";
						$tipord = consulta_string($sqltip, 'minv_tip_ord', $oIfx, 0);

						if ($tipord == 1) {

							$sqlf = " select sum(fprv_val_grbs) as totser, sum(fprv_val_grab) as totbie,sum (fprv_val_gr0s) as totalgr0s from saefprv where fprv_cod_pedi=$cod_oc";
							$totalser = intval(consulta_string($sqlf, 'totser', $oIfx, 0));

							$totalbie = intval(consulta_string($sqlf, 'totbie', $oIfx, 0));

							$totalser0 = intval(consulta_string($sqlf, 'totalgr0s', $oIfx, 0));

							if ($totalser > 0) {
								$totalfacturas = round(consulta_string($sqlf, 'totser', $oIfx, 0), 2);
							} elseif ($totalbie > 0) {
								$totalfacturas = round(consulta_string($sqlf, 'totbie', $oIfx, 0), 2);
							} elseif ($totalser0 > 0) {
								$totalfacturas = round(consulta_string($sqlf, 'totalgr0s', $oIfx, 0), 2);
							}


							$sqlminv = "select minv_tot_minv from saeminv, saedmov where minv_num_comp=dmov_num_comp and dmov_num_comp=$cod_oc";
							$totalcomp = round(consulta_string($sqlminv, 'minv_tot_minv', $oIfx, 0), 2);

							if ($totalfacturas == $totalcomp) {

								$sqlu = "update saeminv set  minv_cer_sn         = 'S' ,
							minv_fech_modi      = CURRENT_DATE,
							minv_usua_modi      = $usuario_web where
							minv_cod_empr       = $idempresa 
							and  minv_num_comp=$cod_oc";
								$oIfx->QueryT($sqlu);
							}
						}
					}




					// ---------------------------------------------------------------------
					// Actualizar secuecial automatico de factura de gasto
					// ---------------------------------------------------------------------

					if ($sec_automatico == 'S') {
						$factura_ad = strtoupper($aForm['factura']);
						$sql_update_para_pv = "UPDATE saepccp set pccp_nur_orpa = '$factura_ad'
															where pccp_cod_empr = $idempresa
										";
						$oIfx->QueryT($sql_update_para_pv);
					}

					// ---------------------------------------------------------------------
					// Actualizar secuecial automatico de factura de gasto
					// ---------------------------------------------------------------------



					$oIfx->QueryT('COMMIT WORK;');
					$oCon->QueryT('COMMIT WORK;');



					$importacion_modulos = $_SESSION['GESTION_IMPORTACION'];
					$tipo_fgasto = $_SESSION['TIPO_FGASTO'];
					if (!empty($importacion_modulos)) {
						if (empty($idempresa)) {
							$idempresa = 0;
						}
						if (empty($idsucursal)) {
							$idsucursal = 0;
						}
						if (empty($idejer_fact)) {
							$idejer_fact = 0;
						}
						if (empty($idprdo_cont)) {
							$idprdo_cont = 0;
						}
						if (empty($cod_prove)) {
							$cod_prove = 0;
						}
						if (empty($secu_asto)) {
							$secu_asto = 0;
						}
						$descripcion_modulo = 'MODULO FACTURA DE GASTO SAEFPRV';
						$id_movimiento_actualzar = $importacion_modulos;

						if ($tipo_fgasto == 'costo') {
							$estado = 'FGC';
						} else if ($tipo_fgasto == 'gasto') {
							$estado = 'FGG';
						} else {
							$estado = 'FG';
						}

						$tran = 'FAC_GAST_' . $estado;
						$oReturn->script("actualizar_estado_gestion_importacion($id_movimiento_actualzar, $idempresa, $idsucursal, $idejer_fact, $idprdo_cont, $cod_prove, '$secu_asto', '$tran', '$descripcion_modulo', '$estado')");
					}



					/*
					$ImportacionModulos = $_SESSION['ImportacionModulos'];
					if (!empty($ImportacionModulos)) {
						$idbodega = 0;
						$desc_modulo = 'FAC_GAST';
						$cod_saecoga = 0;
						$desc_saecoga = '';
						$oReturn->script('insertar_estado_importacion(
							\'' . $idempresa . '\',
							\'' . $idsucursal . '\',
							\'' . $idbodega . '\',
							\'' . $idejer_fact . '\',
							\'' . $idprdo_cont . '\',
							\'' . $cod_prove . '\',
							\'' . $secu_asto . '\',
							\'' . $cod_saecoga . '\',
							\'' . $desc_saecoga . '\',
							\'' . $desc_modulo . '\'
						)');
					}
					*/




					$msj = "Factura: $factura, Retencion: $num_rete, Diario: $secu_asto";

					$oReturn->script("Swal.fire({
										type: 'success',
										width: '40%',
										title: 'Factura - Gasto Ingresada Correctamente ',
										text: '$msj'
									})");

					$oReturn->assign("fprv_cod_fprv", "value", $ruc);

					// ASIENTOS 
					$oReturn->assign("asto_cod", "value", $secu_asto);
					$oReturn->assign("ejer_cod", "value", $idejer_cont);
					$oReturn->assign("prdo_cod", "value", $idprdo_cont);
					$oReturn->assign("claveAcceso", "value", $fprv_clav_sri);

					$oReturn->script("jsRemoveWindowLoad();");
					$array_print[] = array($claveAcceso, $cod_prove, $factura, $idejer_fact, $secu_asto, $fecha_emision);

					$oReturn->script("refreshTablaIn();");
				} else {
					$msj = 'La Factura numero ' . $factura . ' debe estar dentro del intervalo ' . $factura_inicio . ' - ' . $factura_fin;
					$oReturn->script("Swal.fire({
										type: 'error',
										width: '35%',
										title: 'Opss',
										text: '$msj'
									})");

					$oReturn->assign("ctrl", "value", 1);
					$oReturn->script("jsRemoveWindowLoad();");
				}
			}
		} catch (Exception $e) {
			// rollback
			$oIfx->QueryT('ROLLBACK WORK;');
			$oCon->QueryT('ROLLBACK WORK;');
			$error = $e->getMessage();
			if (stripos($error, 'llave duplicada') !== false || stripos($error, 'unicidad') !== false || stripos($error, 'duplicate') !== false) {
				$oReturn->script("Swal.fire({
									title: 'Factura duplicada',
									text: 'Ya existe una factura registrada para este proveedor con la misma serie y número.',
									type: 'warning',
									confirmButtonText: 'Aceptar',
									width: '40%'
								})");
			} else {
				$oReturn->script("Swal.fire({
									title: 'No se pudo guardar',
									text: 'Ocurrió un error al guardar la factura. Revise los datos e intente nuevamente.',
									type: 'error',
									confirmButtonText: 'Aceptar',
									width: '40%'
								})");
			}
			$oReturn->assign("ctrl", "value", 1);
			$oReturn->script("jsRemoveWindowLoad();");
		}
	} else {
		$oReturn->script('alerts("Su sesion ha caducado, vuelva a ingresar al Sistema", "info");');
		$oReturn->script("jsRemoveWindowLoad();");
	}

	return $oReturn;
}

function firmar($nombre_archivo = '', $clave_acceso = '', $ruc = '', $id_docu = '', $correo = '', $cod_prove = '', $factura = '', $idejer_fact = '', $asto_cod = '', $fecha_emis = '')
{
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	$pathpdf = "/Jireh/Comprobantes_Electronicos/generados/";

	$sqlUpdate = $_SESSION['sqlUpdate'];

	$idEmpresa = $_SESSION['U_EMPRESA'];


	//SETEAMOS EL WEB SERVICE PARA FIRMAR LOS COMPROBANTES
	$clientOptions = array(
		"useMTOM" => FALSE,
		'trace' => 1,
		'stream_context' => stream_context_create(array('http' => array('protocol_version' => 1.0)))
	);

	try {
		$wsdlFirma = new SoapClient("http://localhost:8080/WebServFirma/firmaComprobante?WSDL", $clientOptions);

		//CONSULTAMOS LOS DATOS DEL TOKEN
		$sqlConf = "select sucu_tip_ambi, sucu_tip_toke from saesucu where sucu_cod_empr = $idEmpresa";

		if ($oIfx->Query($sqlConf)) {
			$tipoAmbiente = $oIfx->f("sucu_tip_ambi");
			$tiempoEspera = 3;
			$token = $oIfx->f("sucu_tip_toke");
		}

		$serv = "/Jireh/";
		$ruta = $serv . "Comprobantes_Electronicos";

		// CARPETA EMPRESA
		$pathFirmados = $ruta . "/firmados";

		if (!file_exists($ruta)) {
			mkdir($ruta);
		}

		if (!file_exists($pathFirmados)) {
			mkdir($pathFirmados);
		}

		$pathArchivo = "/Jireh/Comprobantes_Electronicos/generados/" . $nombre_archivo;

		$password = null;

		$aFirma = array(
			"ruc" => $ruc,
			"tipoAmbiente" => $tipoAmbiente,
			"tiempoEspera" => $tiempoEspera,
			"token" => $token,
			"pathArchivo" => $pathArchivo,
			"pathFirmados" => $pathFirmados,
			"password" => $password
		);

		$respFirm = $wsdlFirma->FirmarDocumento($aFirma);

		$respFirm = strtoupper($respFirm->return);

		if ($respFirm == null) {
			//$oReturn->alert("FIRMA");
			$oReturn->script("validaAutoriza('$nombre_archivo','$clave_acceso','$id_docu', '$correo', $cod_prove, '$factura', $idejer_fact, '$asto_cod', '$fecha_emis')");
		} else {
			$tipo_mesaje = 'info';
			$mensaje = 'El archivo fue guardado pero no fue firmado : ' . $respFirm;
			$oReturn->script('alerts("' . $mensaje . '", "' . $tipo_mesaje . '");');



			$sqlError = "update saefprv set fprv_erro_sri = '$respFirm' " . $sqlUpdate;
			$oIfx->QueryT($sqlError);

			//$_SESSION['pdf'] = reporte_retencionGasto($sqlUpdate, 'docu');
			$_SESSION['pdf'] = reporte_retencionGasto($id, $clave_acceso, $rutapdf, $cod_prove, $factura, $idejer_fact, $asto_cod, $fecha_emis);

			$oReturn->script('generar_pdf()');
		}
	} catch (SoapFault $e) {
		$tipo_mesaje = 'info';
		$mensaje = 'NO HAY CONECCION CON LA FIRMA';
		$oReturn->script('alerts("' . $mensaje . '", "' . $tipo_mesaje . '");');



		//$_SESSION['pdf'] = reporte_retencionGasto($sqlUpdate, 'docu');
		$_SESSION['pdf'] = reporte_retencionGasto($id, $clave_acceso, $rutapdf, $cod_prove, $factura, $idejer_fact, $asto_cod, $fecha_emis);

		$oReturn->script('generar_pdf()');

		$sqlError = "update saefprv set fprv_erro_sri = 'NO HUBO CONECCION CON LA FIRMA' " . $sqlUpdate;
		$oIfx->QueryT($sqlError);
	}
	return $oReturn;
}

function validaAutoriza($nombre_archivo = '', $clave_acceso = '', $id_docu = '', $correo = '', $cod_prove = '', $factura = '', $idejer_fact = '', $asto_cod = '', $fecha_emis = '')
{
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idEmpresa = $_SESSION['U_EMPRESA'];
	$idSucursal = $_SESSION['U_SUCURSAL'];

	$sqlUpdate = $_SESSION['sqlUpdate'];

	$oReturn = new xajaxResponse();

	$pathpdf = "/Jireh/Comprobantes_Electronicos/generados/";

	$clientOptions = array(
		"useMTOM" => FALSE,
		'trace' => 1,
		'stream_context' => stream_context_create(array('http' => array('protocol_version' => 1.0)))
	);

	//HACEMOS LA VALIDACION DEL COMPROBANTE SUBIENDO EL ARCHIVO XML YA FIRMADO
	try {

		$sql = "select sucu_tip_ambi from saesucu where sucu_cod_sucu = $idSucursal and sucu_cod_empr = $idEmpresa";
		$sucu_tip_ambi = consulta_string_func($sql, 'sucu_tip_ambi', $oIfx, 1);

		if ($sucu_tip_ambi == 1) {
			$wsdlValiComp = new SoapClient("https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantes?wsdl", $clientOptions);
		} else {
			$wsdlValiComp = new SoapClient("https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantes?wsdl", $clientOptions);
		}

		$rutaFirm = "/Jireh/Comprobantes_Electronicos/firmados/" . $nombre_archivo;
		$xml = file_get_contents($rutaFirm);

		$aArchivo = array("xml" => $xml);

		$valiComp = new stdClass();
		$valiComp = $wsdlValiComp->validarComprobante($aArchivo);

		$RespuestaRecepcionComprobante = $valiComp->RespuestaRecepcionComprobante;
		$estado = $RespuestaRecepcionComprobante->estado;

		if ($estado == 'RECIBIDA') {
			// $oReturn->alert("RECIBIDA");
			$oReturn->script("autorizaComprobante('$clave_acceso', '$id_docu' , '$correo', $cod_prove, '$factura', $idejer_fact, '$asto_cod', '$fecha_emis')");
		} else {
			$comprobantes = $RespuestaRecepcionComprobante->comprobantes;
			$comprobante = $comprobantes->comprobante;
			$mensajes = $comprobante->mensajes;
			$mensaje = $mensajes->mensaje;
			// $mensaje2 = $mensaje->mensaje;
			$informacionAdicional = strtoupper($mensaje->informacionAdicional);

			$error = "El archivo fue guardado, pero no fue enviado ni autorizado :" . " \n" . " * " . $informacionAdicional;
			$oReturn->alert($error);

			$sqlError = 'update saefprv set fprv_erro_sri ="' . $informacionAdicional . '"' . $sqlUpdate;

			$oIfx->QueryT($sqlError);

			//$_SESSION['pdf'] = reporte_retencionGasto($sqlUpdate, 'docu');
			$_SESSION['pdf'] = reporte_retencionGasto($id, $clave_acceso, $rutapdf, $cod_prove, $factura, $idejer_fact, $asto_cod, $fecha_emis);

			$oReturn->script('generar_pdf()');
		}
	} catch (SoapFault $e) {
		$oReturn->alert("NO HAY CONECCION AL SRI (VALIDAR) " . $e);
		//$_SESSION['pdf'] = reporte_retencionGasto($sqlUpdate, 'docu');
		$_SESSION['pdf'] = reporte_retencionGasto($id, $clave_acceso, $rutapdf, $cod_prove, $factura, $idejer_fact, $asto_cod, $fecha_emis);
		$oReturn->script('generar_pdf()');

		$sqlError = "update saefprv set fprv_erro_sri = 'NO HUBO CONECCION AL SRI (VALIDAR)' " . $sqlUpdate;
		$oIfx->QueryT($sqlError);
	}

	return $oReturn;
}

function autorizaComprobante($clave_acceso = '', $id_docu = '', $correo = '', $cod_prove = '', $factura = '', $idejer_fact = '', $asto_cod = '', $fecha_emis = '')
{
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	$sqlUpdate = $_SESSION['sqlUpdate'];
	$idEmpresa = $_SESSION['U_EMPRESA'];
	$idSucursal = $_SESSION['U_SUCURSAL'];

	$pathpdf = "/Jireh/Comprobantes_Electronicos/generados/";

	try {
		$clientOptions = array(
			"useMTOM" => FALSE,
			'trace' => 1,
			'stream_context' => stream_context_create(array('http' => array('protocol_version' => 1.0)))
		);

		$sql = "select sucu_tip_ambi from saesucu where sucu_cod_sucu = $idSucursal and sucu_cod_empr = $idEmpresa";
		$sucu_tip_ambi = consulta_string_func($sql, 'sucu_tip_ambi', $oIfx, 1);

		if ($sucu_tip_ambi == 1) {
			$wsdlAutoComp = new SoapClient("https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantes?wsdl", $clientOptions);
		} else {
			$wsdlAutoComp = new SoapClient("https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantes?wsdl", $clientOptions);
		}

		//RECUPERA LA AUTORIZACION DEL COMPROBANTE
		$aClave = array("claveAccesoComprobante" => $clave_acceso);

		$autoComp = new stdClass();
		$autoComp = $wsdlAutoComp->autorizacionComprobante($aClave);

		$RespuestaAutorizacionComprobante = $autoComp->RespuestaAutorizacionComprobante;
		$claveAccesoConsultada = $RespuestaAutorizacionComprobante->claveAccesoConsultada;
		$autorizaciones = $RespuestaAutorizacionComprobante->autorizaciones;
		$autorizacion = $autorizaciones->autorizacion;

		//$oReturn->alert(var_dump($RespuestaAutorizacionComprobante));
		if (count($autorizacion) > 1) {
			$estado = $autorizacion[0]->estado;
			$numeroAutorizacion = $autorizacion[0]->numeroAutorizacion;
			$fechaAutorizacion = $autorizacion[0]->fechaAutorizacion;
			$ambiente = $autorizacion[0]->ambiente;
			$comprobante = $autorizacion[0]->comprobante;
			$mensajes = $autorizacion[0]->mensajes;
			$mensaje = $mensajes->mensaje;
		} else {
			$estado = $autorizacion->estado;
			$numeroAutorizacion = $autorizacion->numeroAutorizacion;
			$fechaAutorizacion = $autorizacion->fechaAutorizacion;
			$ambiente = $autorizacion->ambiente;
			$comprobante = $autorizacion->comprobante;
			$mensajes = $autorizacion->mensajes;
			$mensaje = $mensajes->mensaje;
		}

		if ($estado == 'AUTORIZADO') {
			$tipo_mesaje = 'success';
			$mensaje = 'El comprobante fue autorizado';
			$oReturn->script('alerts("' . $mensaje . '", "' . $tipo_mesaje . '");');

			//$oReturn->script("update_comprobante('$numeroAutorizacion','$fechaAutorizacion','$id_docu')");
			update_comprobante($clave_acceso, $numeroAutorizacion, $fechaAutorizacion, $id_docu);

			$dia = substr($claveAccesoConsultada, 0, 2);
			$mes = substr($claveAccesoConsultada, 2, 2);
			$an = substr($claveAccesoConsultada, 4, 4);

			//CREO LOS DIRECTORIOS DE LOS RIDES
			$serv = "/Jireh";
			$rutaRide = $serv . "/RIDE";
			$rutaComp = $rutaRide . "/RETENCIONES PROVEEDOR";
			$rutaAo = $rutaComp . "/" . $an;
			$rutaMes = $rutaAo . "/" . $mes;
			$rutaDia = $rutaMes . "/" . $dia;

			if (!file_exists($rutaRide)) {
				mkdir($rutaRide);
			}

			if (!file_exists($rutaComp)) {
				mkdir($rutaComp);
			}

			if (!file_exists($rutaAo)) {
				mkdir($rutaAo);
			}

			if (!file_exists($rutaMes)) {
				mkdir($rutaMes);
			}

			if (!file_exists($rutaDia)) {
				mkdir($rutaDia);
			}

			$numero = substr($claveAccesoConsultada, 24, 15);
			$nombre = "ReteP_" . $numero . "_" . "$dia-$mes-$an" . ".xml";

			//FORMO EL RIDE
			$ride .= '<?xml version="1.0" encoding="UTF-8"?>';
			$ride .= '<autorizacion>';
			$ride .= "<estado>$estado</estado>";
			$ride .= "<numeroAutorizacion>$numeroAutorizacion</numeroAutorizacion>";
			$ride .= "<fechaAutorizacion>$fechaAutorizacion</fechaAutorizacion>";
			$ride .= "<ambiente>$ambiente</ambiente>";
			$ride .= "<comprobante><![CDATA[$comprobante]]></comprobante>";
			$ride .= '</autorizacion>';

			// ruta del xml
			$archivo_xml = fopen($rutaDia . '/' . $nombre, "w+");
			fwrite($archivo_xml, $ride);
			fclose($archivo_xml);

			$ride = '' . $rutaDia . '/' . $nombre;

			//$_SESSION['pdf'] = genera_pdf($rutaDia, $nombre, $rutaPdf);
			$_SESSION['pdf'] = reporte_retencionGasto($id, $clave_acceso, $rutapdf, $cod_prove, $factura, $idejer_fact, $asto_cod, $fecha_emis);

			$oReturn->script('generar_pdf()');

			envio_correo_adj($correo, $ride, $rutaPdf);
		} else {

			$mensFina = "COMPROBANTE GUARDADO, FIRMADO, ENVIADO, PERO NO AUTORIZADO:";

			$informacionAdicional = strtoupper($mensaje[0]->informacionAdicional);
			// $oReturn->alert($informacionAdicional);
			if ($informacionAdicional != '') {
				$posi = strpos($informacionAdicional, ':');
				$mensBDD = substr($informacionAdicional, $posi + 2, -1);
				$mensFina .= " \n " . substr($informacionAdicional, $posi + 2, strlen($informacionAdicional));
			} elseif (is_array($mensaje)) {
				foreach ($mensaje as $fila) {
					$val = " \n ";
					$mensFina .= $val . ' * ' . $fila->mensaje;
					$mensBDD .= preg_quote(strtoupper($fila->mensaje)) . " | ";
				}
			}
			$oReturn->alert($mensFina);

			$sqlError = "update saefprv set fprv_erro_sri = '$mensBDD' " . $sqlUpdate;
			$oIfx->QueryT($sqlError);

			//$_SESSION['pdf'] = reporte_retencionGasto($sqlUpdate, 'docu');
			$_SESSION['pdf'] = reporte_retencionGasto($id, $clave_acceso, $rutapdf, $cod_prove, $factura, $idejer_fact, $asto_cod, $fecha_emis);

			$oReturn->script('generar_pdf()');
		}
	} catch (SoapFault $e) {
		$oReturn->alert("NO HAY CONECCION AL SRI (AUTORIZAR)");
		//$_SESSION['pdf'] = reporte_retencionGasto($sqlUpdate, 'docu');
		$_SESSION['pdf'] = reporte_retencionGasto($id, $clave_acceso, $rutapdf, $cod_prove, $factura, $idejer_fact, $asto_cod, $fecha_emis);

		$oReturn->script('generar_pdf()');

		$sqlError = "update saefprv set fprv_erro_sri = 'NO HUBO CONECCION AL SRI (AUTORIZAR)' " . $sqlUpdate;
		$oIfx->QueryT($sqlError);
	}

	return $oReturn;
}

function update_comprobante($claveAcceso = '', $numeroAutorizacion, $fechaAutorizacion, $id_docu)
{
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$user_sri = $_SESSION['U_ID'];
	$sqlUpdate = $_SESSION['sqlUpdate'];

	$oReturn = new xajaxResponse();

	$sqlUpdaComp = "update saefprv set 
                    fprv_aprob_sri = 'S',
                    fprv_auto_sri = '$numeroAutorizacion',
                    fprv_user_sri = $user_sri,
                    fprv_fech_sri = '$fechaAutorizacion',
                    fprv_user_web  = $user_sri,
                    fprv_erro_sri = '',
                    fprv_clav_sri  = '$claveAcceso' " . $sqlUpdate;

	if (!($oIfx->QueryT($sqlUpdaComp)))
		$oReturn->alert("Error al actualizar el comprobante");

	return $oReturn;
}

/* * ************************************************************************* */
/* DF01 :: G E N E R A    EL   S E C U E N C I A L   D E L    P E D I D O   */
/* * ************************************************************************* */

function cargarPlanillas($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$oReturn = new xajaxResponse();

	$cliente = $aForm['cliente'];


	$sql = "SELECT id, nombre from comercial.plantilla_clpv where id_clpv like '%$cliente%' and (id_clpv = '' OR id_clpv = NULL)";
	$i = 1;
	if ($oCon->Query($sql)) {
		$oReturn->script('eliminar_lista_planilla();');
		if ($oCon->NumFilas() > 0) {
			do {
				$oReturn->script(('anadir_elemento_planilla(' . $i++ . ',\'' . $oCon->f('id') . '\', \'' . $oCon->f('nombre') . '\' )'));
			} while ($oCon->SiguienteRegistro());
		}
	}
	$oCon->Free();



	/*
	Adrian47
    $sql = "select id, concat(codigo, ' - ', nombre) as nombre from comercial.plantilla_clpv where id_clpv = $cliente";
    $i = 1;
    if ($oCon->Query($sql)) {
        $oReturn->script('eliminar_lista_planilla();');
        if ($oCon->NumFilas() > 0) {
            do {
                $oReturn->script(('anadir_elemento_planilla(' . $i++ . ',\'' . $oCon->f('id') . '\', \'' . $oCon->f('nombre') . '\' )'));
            } while ($oCon->SiguienteRegistro());
        }
    }
	$oCon->Free();
	*/

	return $oReturn;
}

function cargarValoresPlanillaClpv($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$oReturn = new xajaxResponse();

	//varibales de session
	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm["sucursal"];
	unset($_SESSION['aDataGirdDist']);
	$aLabelGrid = array('Id', 'Cuenta', 'Nombre Cuenta', 'Centro Costo', 'Nombre CCosto', 'Valor', 'Eliminar');

	//variables del formulario
	$cliente = $aForm['cliente'];
	$planilla = $aForm['planilla'];

	try {

		//query tipo de retencion
		$sqlRet = "select tret_cod, tret_porct
					from saetret where
					tret_cod_empr = $idempresa";
		if ($oIfx->Query($sqlRet)) {
			if ($oIfx->NumFilas() > 0) {
				unset($arrayPorc);
				do {
					$arrayPorc[$oIfx->f('tret_cod')] = $oIfx->f('tret_porct');
				} while ($oIfx->SiguienteRegistro());
			}
		}
		$oIfx->Free();

		//query cuenta
		$sqlCue = "select cuen_cod_cuen, cuen_nom_cuen
					from saecuen where
					cuen_cod_empr = $idempresa";
		if ($oIfx->Query($sqlCue)) {
			if ($oIfx->NumFilas() > 0) {
				unset($arrayCuenta);
				do {
					$arrayCuenta[$oIfx->f('cuen_cod_cuen')] = $oIfx->f('cuen_nom_cuen');
				} while ($oIfx->SiguienteRegistro());
			}
		}
		$oIfx->Free();

		$sql = "select id, codigo, nombre, detalle, cuenta_aplicada, credito_bienes,
				credito_servicios, rete_fuente_bienes, rete_fuente_servicios, rete_iva_bienes, 
				rete_iva_servicios, estado
				from comercial.plantilla_clpv
				where id_empresa = $idempresa and id = $planilla
				-- and	id_clpv = $cliente
				";

		if ($oCon->Query($sql)) {
			if ($oCon->NumFilas() > 0) {
				do {
					$id = $oCon->f('id');
					$codigo = $oCon->f('codigo');
					$nombre = $oCon->f('nombre');
					$detalle = $oCon->f('detalle');
					$cuenta_aplicada = $oCon->f('cuenta_aplicada');
					$credito_bienes = $oCon->f('credito_bienes');
					$credito_servicios = $oCon->f('credito_servicios');
					$rete_fuente_bienes = $oCon->f('rete_fuente_bienes');
					$rete_fuente_servicios = $oCon->f('rete_fuente_servicios');
					$rete_iva_bienes = $oCon->f('rete_iva_bienes');
					$rete_iva_servicios = $oCon->f('rete_iva_servicios');
					$estado = $oCon->f('estado');
				} while ($oCon->SiguienteRegistro());

				$oReturn->assign('detalle', 'value', $detalle);

				//valores bienes
				$valor_grab12b = $aForm['valor_grab12b'];
				$valor_grab0b = $aForm['valor_grab0b'];

				//valores servicios
				$valor_grab12s = $aForm['valor_grab12s'];
				$valor_grab0s = $aForm['valor_grab0s'];


				// ------------------------------------------------
				// Control para cambiar el valor de bienes a servicios
				// ------------------------------------------------

				if ((!empty($credito_bienes) && !empty($credito_servicios))) {
					// las dos cuentas tienen valor y no se puede identificar a donde enviar, bien o servicio
				} else {


					if ((empty($credito_bienes) && empty($credito_servicios))) {
						if ($valor_grab12b > 0 && $valor_grab12s > 0) {
						} else {
							if ((!empty($rete_fuente_bienes) && !empty($rete_fuente_servicios))) {
							} else {
								if (!empty($rete_fuente_bienes) && $valor_grab12s > 0) {
									$oReturn->assign('valor_grab0b', 'value', $valor_grab12s);
									$oReturn->assign('valor_grab0s', 'value', 0);
									$oReturn->assign('valor_grab12b', 'value', 0);
									$oReturn->assign('valor_grab12s', 'value', 0);
									$oReturn->script('totales1(47)');
								}

								if (!empty($rete_fuente_servicios) && $valor_grab12b > 0) {
									$oReturn->assign('valor_grab0s', 'value', $valor_grab12b);
									$oReturn->assign('valor_grab0b', 'value', 0);
									$oReturn->assign('valor_grab12b', 'value', 0);
									$oReturn->assign('valor_grab12s', 'value', 0);
									$oReturn->script('totales1(47)');
								}
							}
						}
					} else {
						if (!empty($credito_bienes) && $valor_grab12s > 0) {
							$oReturn->assign('valor_grab12b', 'value', $valor_grab12s);
							$oReturn->assign('valor_grab12s', 'value', 0);
							$oReturn->script('totales1(47)');
						}

						if (!empty($credito_servicios) && $valor_grab12b > 0) {
							$oReturn->assign('valor_grab12s', 'value', $valor_grab12b);
							$oReturn->assign('valor_grab12b', 'value', 0);
							$oReturn->script('totales1(47)');
						}
					}
				}
				// ------------------------------------------------
				// FIN Control para cambiar el valor de bienes a servicios
				// ------------------------------------------------

			}
		}
		$oCon->Free();

		$oReturn->script('cargarPlanillaClpv()');
	} catch (Exception $e) {
		$oReturn->alert($e->getMessage());
	}

	return $oReturn;
}

function cargarPlanillaClpv($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$oReturn = new xajaxResponse();

	//varibales de session
	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm["sucursal"];
	unset($_SESSION['aDataGirdDist']);
	$aLabelGrid = array('Id', 'Cuenta', 'Nombre Cuenta', 'Centro Costo', 'Nombre CCosto', 'Valor', 'Eliminar');

	//variables del formulario
	$cliente = $aForm['cliente'];
	$planilla = $aForm['planilla'];

	try {

		//query tipo de retencion
		$sqlRet = "select tret_cod, tret_porct
					from saetret where
					tret_cod_empr = $idempresa";
		if ($oIfx->Query($sqlRet)) {
			if ($oIfx->NumFilas() > 0) {
				unset($arrayPorc);
				do {
					$arrayPorc[$oIfx->f('tret_cod')] = $oIfx->f('tret_porct');
				} while ($oIfx->SiguienteRegistro());
			}
		}
		$oIfx->Free();

		//query cuenta
		$sqlCue = "select cuen_cod_cuen, cuen_nom_cuen
					from saecuen where
					cuen_cod_empr = $idempresa";
		if ($oIfx->Query($sqlCue)) {
			if ($oIfx->NumFilas() > 0) {
				unset($arrayCuenta);
				do {
					$arrayCuenta[$oIfx->f('cuen_cod_cuen')] = $oIfx->f('cuen_nom_cuen');
				} while ($oIfx->SiguienteRegistro());
			}
		}
		$oIfx->Free();

		$sql = "select id, codigo, nombre, detalle, cuenta_aplicada, credito_bienes,
				credito_servicios, rete_fuente_bienes, rete_fuente_servicios, rete_iva_bienes, 
				rete_iva_servicios, estado
				from comercial.plantilla_clpv
				where id_empresa = $idempresa and id = $planilla
				-- and	id_clpv = $cliente
				";

		if ($oCon->Query($sql)) {
			if ($oCon->NumFilas() > 0) {
				do {
					$id = $oCon->f('id');
					$codigo = $oCon->f('codigo');
					$nombre = $oCon->f('nombre');
					$detalle = $oCon->f('detalle');
					$cuenta_aplicada = $oCon->f('cuenta_aplicada');
					$credito_bienes = $oCon->f('credito_bienes');
					$credito_servicios = $oCon->f('credito_servicios');
					$rete_fuente_bienes = $oCon->f('rete_fuente_bienes');
					$rete_fuente_servicios = $oCon->f('rete_fuente_servicios');
					$rete_iva_bienes = $oCon->f('rete_iva_bienes');
					$rete_iva_servicios = $oCon->f('rete_iva_servicios');
					$estado = $oCon->f('estado');
				} while ($oCon->SiguienteRegistro());

				$oReturn->assign('detalle', 'value', $detalle);

				//valores bienes
				$valor_grab12b = $aForm['valor_grab12b'];
				$valor_grab0b = $aForm['valor_grab0b'];

				//valores servicios
				$valor_grab12s = $aForm['valor_grab12s'];
				$valor_grab0s = $aForm['valor_grab0s'];


				if ($valor_grab12b > 0 || $valor_grab0b > 0) {
					if (!empty($rete_fuente_bienes)) {
						$oReturn->assign('codigo_ret_fue_b', 'value', $rete_fuente_bienes);
						$oReturn->assign('porc_ret_fue_b', 'value', $arrayPorc[$rete_fuente_bienes]);


						// --------------------------------------------------------------------------------
						// Pasar valor de retencion proveedor Adrian47
						// --------------------------------------------------------------------------------
						$sql = "select tret_cod, tret_det_ret, tret_porct,tret_cta_cre
									from saetret where
									tret_cod_empr = $idempresa and
									tret_ban_retf = 'IR' and
									tret_ban_crdb = 'CR' and
									tret_cod      = '$rete_fuente_bienes'
									order by 1 ";
						$oIfx->Query($sql);
						$cont = $oIfx->NumFilas();

						if ($cont == 1) {
							if ($oIfx->Query($sql)) {
								if ($oIfx->NumFilas() > 0) {
									$codigo  = $oIfx->f('tret_cod');
									$porc    = $oIfx->f('tret_porct');
									$cuen_cod = $oIfx->f('tret_cta_cre');
								}
							}

							$oReturn->assign("codigo_ret_fue_b", "value",    $codigo);
							$oReturn->assign("porc_ret_fue_b", "value",      $porc);
							$base = 0;
							$base = $aForm['base_fue_b'];
							$resp = 0;
							$resp = round((($base * $porc) / 100), 2);
							$oReturn->assign("val_fue_b", "value",           $resp);
							$oReturn->script('genera_comprobante();');


							if ($rete_fuente_bienes == 327) {
								$oReturn->script('muestra_divi();');
							}
						} else {
							$oReturn->script('ventana_cod_ret_b(0, 0);');
						}
						// --------------------------------------------------------------------------------
						// FIN Pasar valor de retencion proveedor Adrian47
						// --------------------------------------------------------------------------------
					}

					if (!empty($rete_iva_bienes)) {
						$oReturn->assign('codigo_ret_iva_b', 'value', $rete_iva_bienes);
						$oReturn->assign('porc_ret_iva_b', 'value', $arrayPorc[$rete_iva_bienes]);

						// --------------------------------------------------------------------------------
						// Pasar valor de retencion proveedor Adrian47
						// --------------------------------------------------------------------------------
						$sql = "select tret_cod, tret_det_ret, tret_porct,tret_cta_cre
									from saetret where
									tret_cod_empr = $idempresa and
									tret_ban_retf = 'RI'  and
									tret_ban_crdb = 'CR'  and
									tret_cod      = '$rete_iva_bienes'
									order by 1 ";
						$oIfx->Query($sql);
						$cont = $oIfx->NumFilas();
						//$oReturn->alert($cont);

						if ($cont == 1) {
							if ($oIfx->Query($sql)) {
								if ($oIfx->NumFilas() > 0) {
									$codigo  = $oIfx->f('tret_cod');
									$porc    = $oIfx->f('tret_porct');
									$cuen_cod = $oIfx->f('tret_cta_cre');
								}
							}
							$oReturn->assign("codigo_ret_iva_b", "value",    $codigo);
							$oReturn->assign("porc_ret_iva_b", "value",      $porc);
							$base = 0;
							$base = $aForm['base_iva_b'];
							$resp = 0;
							$resp = round((($base * $porc) / 100), 2);
							$oReturn->assign("val_iva_b", "value",           $resp);
							$oReturn->script('genera_comprobante();');
						} else {
							$oReturn->script('ventana_cod_ret_iva(0, 1);');
						}
						// --------------------------------------------------------------------------------
						// FIN Pasar valor de retencion proveedor Adrian47
						// --------------------------------------------------------------------------------
					}
				} else {
					$oReturn->assign('codigo_ret_fue_b', 'value', '');
					$oReturn->assign('porc_ret_fue_b', 'value', '');
					$oReturn->assign('codigo_ret_iva_b', 'value', '');
					$oReturn->assign('porc_ret_iva_b', 'value', '');
				}

				if ($valor_grab12s > 0 || $valor_grab0s > 0) {
					if (!empty($rete_fuente_servicios)) {
						$oReturn->assign('codigo_ret_fue_s', 'value', $rete_fuente_servicios);
						$oReturn->assign('porc_ret_fue_s', 'value', $arrayPorc[$rete_fuente_servicios]);

						// --------------------------------------------------------------------------------
						// FIN Pasar valor de retencion proveedor Adrian47
						// --------------------------------------------------------------------------------


						$sql = "select tret_cod, tret_det_ret, tret_porct,tret_cta_cre
									from saetret where
									tret_cod_empr = $idempresa and
									tret_ban_retf = 'IR' and
									tret_ban_crdb = 'CR' and
									tret_cod      = '$rete_fuente_servicios'
									order by 1 ";
						$oIfx->Query($sql);
						$cont = $oIfx->NumFilas();

						if ($cont == 1) {
							if ($oIfx->Query($sql)) {
								if ($oIfx->NumFilas() > 0) {
									$codigo  = $oIfx->f('tret_cod');
									$porc    = $oIfx->f('tret_porct');
									$cuen_cod = $oIfx->f('tret_cta_cre');
								}
							}


							$oReturn->assign("codigo_ret_fue_s", "value",    $codigo);
							$oReturn->assign("porc_ret_fue_s", "value",      $porc);
							$base = 0;
							$base = $aForm['base_fue_s'];
							$resp = 0;
							$resp = round((($base * $porc) / 100), 2);
							$oReturn->assign("val_fue_s", "value",           $resp);
							$oReturn->script('genera_comprobante();');


							if ($rete_fuente_servicios == 327) {
								$oReturn->script('muestra_divi();');
							}
						} else {
							$oReturn->script('ventana_cod_ret_b(1, 0);');
						}

						// --------------------------------------------------------------------------------
						// FIN Pasar valor de retencion proveedor Adrian47
						// --------------------------------------------------------------------------------
					}

					if (!empty($rete_iva_servicios)) {
						$oReturn->assign('codigo_ret_iva_s', 'value', $rete_iva_servicios);
						$oReturn->assign('porc_ret_iva_s', 'value', $arrayPorc[$rete_iva_servicios]);

						// --------------------------------------------------------------------------------
						// Pasar valor de retencion proveedor Adrian47
						// --------------------------------------------------------------------------------
						$sql = "select tret_cod, tret_det_ret, tret_porct,tret_cta_cre
									from saetret where
									tret_cod_empr = $idempresa and
									tret_ban_retf = 'RI'  and
									tret_ban_crdb = 'CR'  and
									tret_cod      = '$rete_iva_servicios'
									order by 1 ";
						$oIfx->Query($sql);
						$cont = $oIfx->NumFilas();
						//$oReturn->alert($cont);

						if ($cont == 1) {
							if ($oIfx->Query($sql)) {
								if ($oIfx->NumFilas() > 0) {
									$codigo  = $oIfx->f('tret_cod');
									$porc    = $oIfx->f('tret_porct');
									$cuen_cod = $oIfx->f('tret_cta_cre');
								}
							}
							$oReturn->assign("codigo_ret_iva_s", "value",    $codigo);
							$oReturn->assign("porc_ret_iva_s", "value",      $porc);
							$base = 0;
							$base = $aForm['base_iva_s'];
							$resp = 0;
							$resp = round((($base * $porc) / 100), 2);
							$oReturn->assign("val_iva_s", "value",           $resp);
							$oReturn->script('genera_comprobante();');
						} else {
							$oReturn->script('ventana_cod_ret_iva(1, 1);');
						}
						// --------------------------------------------------------------------------------
						// FIN Pasar valor de retencion proveedor Adrian47
						// --------------------------------------------------------------------------------
					}
				} else {
					$oReturn->assign('codigo_ret_fue_s', 'value', '');
					$oReturn->assign('porc_ret_fue_s', 'value', '');
					$oReturn->assign('codigo_ret_iva_s', 'value', '');
					$oReturn->assign('porc_ret_iva_s', 'value', '');
				}

				if (!empty($credito_bienes)) {
					$oReturn->assign('fisc_b', 'value', $credito_bienes);
					$oReturn->assign('fisc_b_tmp', 'innerHTML', $arrayCuenta[$credito_bienes]);
				}

				if (!empty($credito_servicios)) {
					$oReturn->assign('fisc_s', 'value', $credito_servicios);
					$oReturn->assign('fisc_s_tmp', 'innerHTML', $arrayCuenta[$credito_servicios]);
				}

				$oReturn->script('genera_comprobante();');
			}
		}
		$oCon->Free();

		//totales
		$val_gasto1 = $aForm['val_gasto1'] + $aForm['valor_exentoIva'] + $aForm['valor_noObjIva'];

		if (empty($id)) {
			$id = $aForm['planilla'];
		}


		// --------------------------------------------------------------------------
		// CUENTAS ADICIONALES PERU
		// --------------------------------------------------------------------------
		$empr_cod_pais = $_SESSION['U_PAIS_COD'];
		$sql_codInter_pais = "SELECT pais_codigo_inter from saepais where pais_cod_pais = $empr_cod_pais;";
		$codigo_pais = consulta_string_func($sql_codInter_pais, 'pais_codigo_inter', $oIfx, 0);

		$sql_pcon = "SELECT pcon_cue_niif from saepcon WHERE pcon_cod_empr = $idempresa;";
		$pcon_cue_niif = consulta_string_func($sql_pcon, 'pcon_cue_niif', $oIfx, 0);
		// --------------------------------------------------------------------------
		// FIN CUENTAS ADICIONALES PERU
		// --------------------------------------------------------------------------



		//query plantilla ccosn
		$sql = "select id, cuenta, centro_costos, porcentaje
				from comercial.plantilla_ccosn
				where id_plantilla = $id 
				-- and id_clpv = $cliente
				";
		//$oReturn->alert($sql);

		$centro_costos_plantilla = 'N';
		if ($oCon->Query($sql)) {
			if ($oCon->NumFilas() > 0) {
				$oReturn->assign('gasto1', 'value', $oCon->f('cuenta'));
				$oReturn->script('cargar_centroCostos3();');
				$array_cuentas_ccosn_ad = array();
				do {
					$centro_costos_plantilla = 'S';

					//$codigo = $oCon->f('codigo');
					$ccosn_cod = $oCon->f('centro_costos');
					$porcentaje = $oCon->f('porcentaje');
					$cta_cod = $oCon->f('cuenta');

					$valor = ($val_gasto1 * $porcentaje) / 100;

					$array_cuentas_ccosn_ad[$cta_cod] += $valor;

					// --------------------------------------------------------------------------
					// CUENTAS ADICIONALES PERU
					// --------------------------------------------------------------------------

					if ($codigo_pais == 51 && $pcon_cue_niif == 'S') {

						// Cuando es Peru baja dos cuentas adicionales: cuan_ana_deb y cuen_anal_cre
						//$sql_cuen = "SELECT cuen_ana_deb, cuen_ana_cre from saecuen where cuen_cod_cuen = '$cta_cod';";
						//$cuen_ana_deb = consulta_string_func($sql_cuen, 'cuen_ana_deb', $oIfx, '');

						//nombre cuenta
						$sql = "select cuen_nom_cuen from saecuen where cuen_cod_cuen = '$cta_cod' and cuen_cod_empr = $idempresa";
						$cta_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

						$sql = "select ccosn_nom_ccosn from saeccosn where ccosn_cod_ccosn = '$ccosn_cod' and ccosn_cod_empr = $idempresa";
						$ccosn_nom = consulta_string_func($sql, 'ccosn_nom_ccosn', $oIfx, '');

						if (!empty($cta_cod)) {
							//GUARDA LOS DATOS DEL DETALLE
							$cont = count($aDataGrid);

							$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
							$aDataGrid[$cont][$aLabelGrid[1]] = $cta_cod;
							$aDataGrid[$cont][$aLabelGrid[2]] = $cta_nom;
							$aDataGrid[$cont][$aLabelGrid[3]] = $ccosn_cod;
							$aDataGrid[$cont][$aLabelGrid[4]] = $ccosn_nom;
							$aDataGrid[$cont][$aLabelGrid[5]] = $valor;
							$aDataGrid[$cont][$aLabelGrid[6]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																	onMouseOver="drc(\'Presione aqui para Eliminar\', \'Eliminar\'); return true;"
																	onMouseOut="javascript:nd(); return true;"
																	title = "Presione aqui para Eliminar"
																	style="cursor: hand !important; cursor: pointer !important;"
																	onclick="javascript:xajax_elimina_detalle_dist(' . $cont . ');"
																	alt="Eliminar"
																	align="bottom" />';


							$_SESSION['aDataGirdDist'] = $aDataGrid;
							$sHtml = mostrar_grid_dist();
							// TOTALES
							$array = $_SESSION['aDataGirdDist'];
							$total     = 0;
							foreach ($array as $aValues) {
								$aux = 0;
								foreach ($aValues as $aVal) {
									if ($aux == 5) {
										$total += $aVal;
									}
									$aux++;
								}
								$cont++;
							}
						}
					} else {

						//nombre cuenta
						$sql = "select cuen_nom_cuen from saecuen where cuen_cod_cuen = '$cta_cod' and cuen_cod_empr = $idempresa";
						$cta_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

						$sql = "select ccosn_nom_ccosn from saeccosn where ccosn_cod_ccosn = '$ccosn_cod' and ccosn_cod_empr = $idempresa";
						$ccosn_nom = consulta_string_func($sql, 'ccosn_nom_ccosn', $oIfx, '');

						//gasto1 - val_gasto1
						//gasto2 - val_gasto2

						//GUARDA LOS DATOS DEL DETALLE
						$cont = count($aDataGrid);

						$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
						$aDataGrid[$cont][$aLabelGrid[1]] = $cta_cod;
						$aDataGrid[$cont][$aLabelGrid[2]] = $cta_nom;
						$aDataGrid[$cont][$aLabelGrid[3]] = $ccosn_cod;
						$aDataGrid[$cont][$aLabelGrid[4]] = $ccosn_nom;
						$aDataGrid[$cont][$aLabelGrid[5]] = $valor;
						$aDataGrid[$cont][$aLabelGrid[6]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																onMouseOver="drc(\'Presione aqui para Eliminar\', \'Eliminar\'); return true;"
																onMouseOut="javascript:nd(); return true;"
																title = "Presione aqui para Eliminar"
																style="cursor: hand !important; cursor: pointer !important;"
																onclick="javascript:xajax_elimina_detalle_dist(' . $cont . ');"
																alt="Eliminar"
																align="bottom" />';


						$_SESSION['aDataGirdDist'] = $aDataGrid;
						$sHtml = mostrar_grid_dist();
						// TOTALES
						$array = $_SESSION['aDataGirdDist'];
						$total     = 0;
						foreach ($array as $aValues) {
							$aux = 0;
							foreach ($aValues as $aVal) {
								if ($aux == 5) {
									$total += $aVal;
								}
								$aux++;
							}
							$cont++;
						}
					}
					// --------------------------------------------------------------------------
					// FIN CUENTAS ADICIONALES PERU
					// --------------------------------------------------------------------------




					//
				} while ($oCon->SiguienteRegistro());

				// --------------------------------------------------------------------------
				// CUENTAS ADICIONALES PERU
				// --------------------------------------------------------------------------


				$total_distribucion_ccosn = 0;
				if ($codigo_pais == 51 && $pcon_cue_niif == 'S') {

					$existe_cuenta_aplicada_distri = 'N';
					$valor_cuenta_aplicad = $array_cuentas_ccosn_ad[$cuenta_aplicada];
					if ($valor_cuenta_aplicad > 0) {
						$existe_cuenta_aplicada_distri = 'S';
					}



					foreach ($array_cuentas_ccosn_ad as $cuenta_contable => $valor_cuenta) {
						$total_distribucion_ccosn += $valor_cuenta;
					}


					// Cuando es Peru baja dos cuentas adicionales: cuan_ana_deb y cuen_anal_cre
					$sql_cuen = "SELECT cuen_ana_deb, cuen_ana_cre from saecuen where cuen_cod_cuen = '$cuenta_aplicada';";
					$cuen_ana_cre = consulta_string_func($sql_cuen, 'cuen_ana_cre', $oIfx, '');

					//nombre cuenta
					$sql = "select cuen_nom_cuen from saecuen where cuen_cod_cuen = '$cuen_ana_cre' and cuen_cod_empr = $idempresa";
					$cta_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

					if (!empty($cuen_ana_cre)) {


						$oReturn->assign("cod_cta", "value", $cuen_ana_cre);
						$oReturn->assign("nom_cta", "value", $cta_nom);
						$oReturn->assign("detalla_diario", "value", 'CUENTA ANALITICA CREDITO');
						// CR => Credito || DB => Debito
						$oReturn->assign("crdb", "value", 'CR');
						$oReturn->assign("val_cta", "value", $total_distribucion_ccosn);
						$oReturn->script("anadir_dasi();");
					}
				}

				// --------------------------------------------------------------------------
				// FIN CUENTAS ADICIONALES PERU
				// --------------------------------------------------------------------------


			}
		}
		$oCon->Free();

		///VALIDACION CEUNTA APLICADA


		// --------------------------------------------------------------------------
		// CUENTAS ADICIONALES PERU
		// --------------------------------------------------------------------------




		if ($codigo_pais == 51 && $pcon_cue_niif == 'S') {

			if (!empty($cuenta_aplicada) && $existe_cuenta_aplicada_distri == 'N') {

				//nombre cuenta
				$sql = "select cuen_nom_cuen from saecuen where cuen_cod_cuen = '$cuenta_aplicada' and cuen_cod_empr = $idempresa";
				$cta_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');


				$oReturn->assign("cod_cta", "value", $cuenta_aplicada);
				$oReturn->assign("nom_cta", "value", $cta_nom);
				$oReturn->assign("detalla_diario", "value", 'CUENTA CRUCE DEBITO');
				// CR => Credito || DB => Debito
				$oReturn->assign("crdb", "value", 'DB');
				$oReturn->assign("val_cta", "value", $total_distribucion_ccosn);
				$oReturn->script("anadir_dasi();");
			} else if (!empty($cuenta_aplicada) && $existe_cuenta_aplicada_distri == 'S') {

				$sql_cuen = "SELECT cuen_ana_deb, cuen_ana_cre from saecuen where cuen_cod_cuen = '$cuenta_aplicada';";
				$cuen_ana_deb = consulta_string_func($sql_cuen, 'cuen_ana_deb', $oIfx, '');

				//nombre cuenta
				$sql = "select cuen_nom_cuen from saecuen where cuen_cod_cuen = '$cuen_ana_deb' and cuen_cod_empr = $idempresa";
				$cta_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');


				$oReturn->assign("cod_cta", "value", $cuen_ana_deb);
				$oReturn->assign("nom_cta", "value", $cta_nom);
				$oReturn->assign("detalla_diario", "value", 'CUENTA CRUCE DEBITO');
				// CR => Credito || DB => Debito
				$oReturn->assign("crdb", "value", 'DB');
				$oReturn->assign("val_cta", "value", $total_distribucion_ccosn);
				$oReturn->script("anadir_dasi();");
			}
		}
		// --------------------------------------------------------------------------
		// FIN CUENTAS ADICIONALES PERU
		// --------------------------------------------------------------------------

		unset($_SESSION['SESSION_CUENTA_APLICADA']);
		unset($_SESSION['SESSINO_COSTOS_PLANTILLA']);

		$_SESSION['SESSION_CUENTA_APLICADA'] = $cuenta_aplicada;
		$_SESSION['SESSINO_COSTOS_PLANTILLA'] = $centro_costos_plantilla;
	} catch (Exception $e) {
		$oReturn->alert($e->getMessage());
	}

	return $oReturn;
}

function cargar_lista_correo($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $_SESSION['U_SUCURSAL'];

	$oReturn = new xajaxResponse();

	$cliente = $aForm['cliente'];
	$sql = "select emai_cod_emai, emai_ema_emai from saeemai where 
                   emai_cod_empr= $idempresa and 
                   emai_cod_clpv = '$cliente'";

	// $oReturn->alert($sql);
	$i = 1;
	if ($oIfx->Query($sql)) {
		$oReturn->script('eliminar_lista_correo();');
		if ($oIfx->NumFilas() > 0) {
			$correo = $oIfx->f('emai_cod_emai');
			do {
				$oReturn->script(('anadir_elemento_correo(' . $i++ . ',\'' . $oIfx->f('emai_cod_emai') . '\', \'' . $oIfx->f('emai_ema_emai') . '\' )'));
			} while ($oIfx->SiguienteRegistro());
			$oReturn->assign('proveedor_email', 'value', $correo);
		}
	}

	return $oReturn;
}

function cargarActividades($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();

	$oReturn = new xajaxResponse();

	$proyecto = $aForm['proyecto'];

	$sql = "select p.id, t.codigo, t.name
			from comercial.tree_elements t, comercial.tree_planifica p
			where t.Id = p.id_tree and
			p.id_proyecto = $proyecto";
	$i = 1;
	if ($oCon->Query($sql)) {
		$oReturn->script('eliminar_lista_actividad();');
		if ($oCon->NumFilas() > 0) {
			do {
				$detalle = $oCon->f('codigo') . ' - ' . $oCon->f('name');
				$oReturn->script(('anadir_elemento_actividad(' . $i++ . ',\'' . $oCon->f('id') . '\', \'' . $detalle . '\' )'));
			} while ($oCon->SiguienteRegistro());
		}
	}
	$oCon->Free();

	return $oReturn;
}

function secuencial_pedido($op, $serie, $as_codigo_pedido, $ceros_sql)
{
	//string 
	$ls_codigo;
	$ceros;
	$ls_codigos;

	//integer 
	$li_codigo;
	$ceros1;
	$ll_numeros;
	$ll_codigo;

	if (isset($as_codigo_pedido) or $as_codigo_pedido == '') {
		$li_codigo = ($as_codigo_pedido);

		$li_codigo = 0;
	} else {
		$li_codigo = $as_codigo_pedido;
	}

	$li_codigo = $as_codigo_pedido;

	$li_codigo = $li_codigo + 1;
	$ll_numeros = strlen(($li_codigo));
	$ceros = cero_mas('0', $ceros_sql);
	$ceros1 = strlen($ceros);
	$ll_codigo = $ceros1 - $ll_numeros;

	switch ($op) {
		case 1:
			// secuencial user
			$ls_codigos = $serie . '-' . (cero_mas('0', $ll_codigo)) . ($li_codigo);
			break;
		case 2:
			// secuencial normal					
			$ls_codigos = (cero_mas('0', $ll_codigo)) . ($li_codigo);
			break;
	}

	return $ls_codigos;
}

function cero_mas($caracter, $num)
{
	if (isset($num)) {
		if ($num > 0) {
			for ($i = 1; $i <= $num; $i++) {
				$arreglo[$i] = $caracter;
			}

			while (list($i, $Valor) = each($arreglo)) {
				$cadena .= $Valor;
			}
		} else {
			$cadena = '';
		}
	}

	return $cadena;
}

function cancelar_pedido()
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	$aDataGrid = $_SESSION['aDataGird'];
	$aDataPrueba = $_SESSION['aDataPrueba'];
	unset($_SESSION['aDataGird']);
	unset($_SESSION['aDataPrueba']);
	$sScript = "xajax_genera_formulario_pedido();";
	$oReturn = new xajaxResponse();
	$oReturn->clear("divFormularioDetalle", "innerHTML");
	$oReturn->script($sScript);
	return $oReturn;
}


function fecha_sri($fecha)
{
	$fecha_array = explode('/', $fecha);
	$d = $fecha_array[0];
	$m = $fecha_array[1];
	$y = $fecha_array[2];

	return ($d . '' . $m . '' . $y);
}

function fecha_mysql($fecha)
{
	$fecha_array = explode('/', $fecha);
	$m = $fecha_array[0];
	$y = $fecha_array[2];
	$d = $fecha_array[1];

	return ($d . '/' . $m . '/' . $y);
}

function fecha_mysql2($fecha)
{
	$fecha_array = explode('/', $fecha);
	$m = $fecha_array[0];
	$y = $fecha_array[2];
	$d = $fecha_array[1];

	return ($y . '/' . $m . '/' . $d);
}

function fecha_emision($fecha)
{
	$fecha_array = explode('/', $fecha);
	$m = $fecha_array[0];
	$y = $fecha_array[2];
	$d = $fecha_array[1];

	return ($d . '/' . $m . '/' . $y);
}

function getDiasMes($mes, $anio)
{
	if (is_callable("cal_days_in_month")) {
		return cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
	} else {
		//Lo hacemos a mi manera. 
		return date("d", mktime(0, 0, 0, $mes + 1, 0, $anio));
	}
}

function restaFechas($dFecIni, $dFecFin)
{
	$dFecIni = str_replace("-", "", $dFecIni);
	$dFecIni = str_replace("/", "", $dFecIni);
	$dFecFin = str_replace("-", "", $dFecFin);
	$dFecFin = str_replace("/", "", $dFecFin);

	ereg("([0-9]{1,2})([0-9]{1,2})([0-9]{2,4})", $dFecIni, $aFecIni);

	ereg("([0-9]{1,2})([0-9]{1,2})([0-9]{2,4})", $dFecFin, $aFecFin);

	$date1 = mktime(0, 0, 0, $aFecIni[2], $aFecIni[1], $aFecIni[3]);
	$date2 = mktime(0, 0, 0, $aFecFin[2], $aFecFin[1], $aFecFin[3]);

	return round(($date2 - $date1) / (60 * 60 * 24));
}

function consulta($sql, $campo, $Conexion)
{

	$total_mes_stock = 0;
	if ($Conexion->Query($sql)) {
		if ($Conexion->NumFilas() > 0) {
			$total_mes_stock = $Conexion->f($campo);
			if (empty($total_mes_stock)) {
				$total_mes_stock = 0;
			}
		} else {
			$total_mes_stock = 0;
		}
	}
	$Conexion->Free();
	//$Conexion->Desconectar();

	return $total_mes_stock;
}

function digitoVerificador($cadena)
{
	//$cadena = "040820140117914132530011001001000000063272775261";
	$pivote = 7;

	$longitudCadena = strlen($cadena);
	for ($i = 0; $i < $longitudCadena; $i++) {
		if ($pivote == 1)
			$pivote = 7;
		$caracter = substr($cadena, $i, 1);
		$temporal = $caracter * $pivote;
		$pivote--;
		$cantidadTotal += $temporal;
	}

	$div = $cantidadTotal % 11;
	$digitoVerificador = 11 - $div;

	if ($digitoVerificador == 10)
		$digitoVerificador = 1;
	if ($digitoVerificador == 11)
		$digitoVerificador = 0;

	return $digitoVerificador;
}

function tipoReteIva($codigo)
{

	if ($codigo == '721')
		$tipo_codigo = '1';
	else if ($codigo == '723')
		$tipo_codigo = '2';
	if ($codigo == '725')
		$tipo_codigo = '3';

	return $tipo_codigo;
}

//TIPO DE FACTURA
function tipo_factura($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $_SESSION['U_SUCURSAL'];
	$array_datos = $_SESSION['DATOS_FACT_PROV'];
	$tipo_factura = $aForm['tipo_factura'];
	$cliente = $aForm['cliente'];
	$clpvCompania = $aForm['clpvCompania'];
	$fecha_emision = $aForm['fecha_emision'];
	$fecha_vence = $aForm['fecha_vence'];
	$sec_automatico = $aForm['sec_automatico'];
	$num_digi_fact  = $_SESSION['U_NUM_DIGI'];



	$S_PAIS_API_SRI = $_SESSION['S_PAIS_API_SRI'];
	$tipoIdentificacion = $_SESSION['TIPO_IDENTIFICACION'];
	if ($S_PAIS_API_SRI == 593 && ($tipoIdentificacion == '01' || $tipoIdentificacion == '02')) {
		$tipo_input = 'number';
	} else {
		$tipo_input = 'text';
	}



	if ($cliente != '') {
		if ($tipo_factura == 1) {
			//FACTURA ELECTRONICA

			$ifu->AgregarCampoNumerico('serie', 'Serie|left', true, '', 80, 10);

			$ifu->AgregarCampoNumerico('auto_prove', 'Autorizacion|left', true, '', 800, 49);
			$ifu->AgregarComandoAlCambiarValor('auto_prove', "validaAutorizacion('electronica','escribir');");

			$ifu->AgregarCampoTexto('factura', 'Factura|left', true, '', 90, 30);
			$ifu->AgregarComandoAlEscribir('factura', "validaFactura('electronica','escribir');");
			$ifu->AgregarComandoAlQuitarEnfoque('factura', "validaFactura('electronica','enfoque');");

			$ifu->AgregarCampoNumerico('clave_acceso', 'Clave|left', true, '', 350, 49);
			$ifu->AgregarComandoAlEscribir('clave_acceso', 'clave_acceso_buscar(' . $idempresa . ', event )');

			//selecciona ambiente de sucursal
			$sql = "select sucu_tip_ambi from saesucu where sucu_cod_empr = $idempresa and sucu_cod_sucu = $idsucursal";
			$sucu_tip_ambi = consulta_string_func($sql, 'sucu_tip_ambi', $oIfx, 2);

			if ($sucu_tip_ambi == 1) {
				$op = 'N';
			} else {
				$op = 'S';
			}

			$ifu->AgregarCampoSi_No('ambiente_sri', '|left', $op);

			//$evento = "validaFactura('impresa','escribir'); validaFactura('impresa','enfoque'); ";
			//$evento2= "validaAutorizacion('electronica','escribir'); ";
			$evento3 = "clave_acceso_buscar(' . $idempresa . ', event ); ";

			$pais_cero_ele = $_SESSION['U_PAIS_CERO_ELE'];
			if ($pais_cero_ele == 'S') {
				$evento  = "validaFactura('electronica','escribir'); ";
				$evento0 = "validaFactura('electronica','enfoque'); ";
				$evento2 = "validaAutorizacion('electronica','escribir');";
				$evento22 = "validaAutorizacion('electronica','enfoque');";
				$evento1 = "validaSerie('escribir',1); ";
				$evento11 = "validaSerie('enfoque', 1); ";
			}

			if ($sec_automatico == 'S') {
				$sql_sec_auto = "SELECT pccp_nur_orpa FROM saepccp where pccp_cod_empr = $idempresa";
				$secuencial_fgasto = consulta_string_func($sql_sec_auto, 'pccp_nur_orpa', $oIfx, '') + 1;
				$secuencial_fgasto = str_pad($secuencial_fgasto, 9, '0', STR_PAD_LEFT);
				$digitos_serie = $_SESSION['U_PAIS_DIG_SERP'];
				$serie_prove = str_pad(9, $digitos_serie, '9', STR_PAD_LEFT);
			} else {
				$secuencial_fgasto = '';
			}


			$sHtml .=	'<tr>
                            <td colspan="4">
                                <table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
                                    <tr>
                                        <td>Produccion:</td>
										<td>' . $ifu->ObjetoHtml('ambiente_sri') . ' </td>
										<td>
											 <a href="#" onClick="javascript:redireccionar()" style="color: blue;">Web Entidad</a>
										</td>
                                        <td>
										<input type="text" class="form-control input-sm" id="clave_acceso" name="clave_acceso" 
										value="" style="width:200px; text-align:right; height:25px" onkeyup="' . $evento3 . '"/>
                                        </td>                                        
                                        <td>
                                            <div class="btn btn-success btn-sm" onclick="clave_acceso_sri(2);">
                                                <span class="glyphicon glyphicon-retweet"></span>
                                                Genera
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                             </td>
						</tr>
						<tr>
                            <td colspan="4">
                                <table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
									<tr>
										<td>' . $ifu->ObjetoHtmlLBL('auto_prove') . '</td>
										<td colspan="3">
											<input type="' . $tipo_input . '" class="form-control input-sm" id="auto_prove" name="auto_prove" 
												value="' . $auto_prove . '" style="width:400px; text-align:right; height:25px" onkeyup="' . $evento2 . '" onchange="' . $evento22 . '"/>
										</td>
									</tr>
									<tr>	
                                        <td>' . $ifu->ObjetoHtmlLBL('serie') . '</td>
										<td>
											<input type="' . $tipo_input . '" class="form-control input-sm" id="serie" name="serie" 
											value="' . $serie_prove . '"
											style="width:120px; height:25px; text-align:right"  onkeyup="' . $evento1 . '" onchange="' . $evento11 . '" />
										</td>
                                        <td>' . $ifu->ObjetoHtmlLBL('factura') . '</td>
										<td>
											<input type="' . $tipo_input . '" class="form-control input-sm" id="factura" name="factura" 
											value="' . $secuencial_fgasto . '"
											style="width:150px; text-align:right ; height:25px; "  onkeyup="' . $evento . '" onchange="' . $evento0 . '"/>
										</td>
                                        
                                    </tr>
                                </table>
                            </td>							
						</tr>';

			$oReturn->assign('divFactura', 'innerHTML', $sHtml);
			if (!empty($array_datos)) {
				$serie_xml = $array_datos[0];
				$fact_xml = $array_datos[1];
				$auto_xml = $array_datos[2];
				$oReturn->assign('serie', 'value', $serie_xml);
				$oReturn->assign('factura', 'value', $fact_xml);
				$oReturn->assign('auto_prove', 'value', $auto_xml);
				$oReturn->assign('clave_acceso', 'value', $auto_xml);
				$oReturn->script('totales(this)');
				$oReturn->script('totales1(this)');
			}
			$oReturn->assign('clave_acceso', 'focus()', '');
		} elseif ($tipo_factura == 2) {

			if (!empty($clpvCompania)) {
				$cliente = $clpvCompania;
			}

			// and coa_est_coa	  = '1' and coa_elec_sn='N' 
			$sql = "select  max(coa_fec_vali) as coa_fec_vali, coa_aut_usua, coa_seri_docu, coa_fact_ini, coa_fact_fin, coa_aut_impr
					from saecoa where
					clpv_cod_empr = $idempresa and
					clpv_cod_clpv = $cliente 
					group by coa_fec_vali,2,3,4,5 ,6";


			if ($oIfx->Query($sql)) {
				if ($oIfx->NumFilas() > 0) {
					do {
						$fec_cadu_prove = ($oIfx->f('coa_fec_vali'));
						// 2019/12/31
						// list($a, $b, $c) = explode('/', $fec_cadu_prove);
						// $fec_cadu_prove  = $a . '-' . $b . '-' . $c;
						$auto_prove     = $oIfx->f('coa_aut_usua');
						$serie_prove 	= $oIfx->f('coa_seri_docu');
						$ini_prove 		= ($oIfx->f('coa_fact_ini'));
						$fin_prove 		= ($oIfx->f('coa_fact_fin'));
						$a_prove 		= $oIfx->f('coa_aut_impr');
					} while ($oIfx->SiguienteRegistro());
				} else {
					$auto_prove   = cero_mas('0', $_SESSION['U_PAIS_DIG_AUTOP']);
					$serie_prove  = cero_mas('0', $_SESSION['U_PAIS_DIG_SERP']);
					$a_prove      = '9999999999';
					$fec_cadu_prove      = '2029/12/31';
					$ini_prove  = '1';
					$fin_prove  = '99999999999';
				}
			}
			$oIfx->Free();

			$ifu->AgregarCampoNumerico('auto_prove', 'Autorizacion|left', true, $auto_prove, 200, 100);
			$ifu->AgregarComandoAlCambiarValor('auto_prove', "validaAutorizacion('impresa','escribir');");

			$ifu->AgregarCampoTexto('serie', 'Serie|left', true, $serie_prove, 80, 10);

			$ifu->AgregarCampoFecha('fecha_validez', 'Fecha Caducidad|left', true, $fec_cadu_prove);
			$ifu->AgregarCampoTexto('factura_inicio', 'Factura Inicio|left', true, $ini_prove, 90, 15);
			$ifu->AgregarCampoTexto('factura_fin', 'Factura Fin|left', true, $fin_prove, 70, 15);

			$ifu->AgregarCampoTexto('factura', 'Factura|left', true, '', 90, 9);
			$ifu->AgregarComandoAlEscribir('factura', "validaFactura('impresa','escribir');");
			$ifu->AgregarComandoAlQuitarEnfoque('factura', "validaFactura('impresa','enfoque');");

			$pais_cero_pre = $_SESSION['U_PAIS_CERO_PRE'];
			if ($pais_cero_pre == 'S') {
				$evento  = "validaFactura('impresa','escribir'); ";
				$evento0 = "validaFactura('impresa','enfoque'); ";
				$evento2 = "validaAutorizacion('impresa','escribir');";
				$evento22 = "validaAutorizacion('impresa','enfoque');";
				$evento1 = "validaSerie('escribir',2); ";
				$evento11 = "validaSerie('enfoque',2); ";
			}

			if ($sec_automatico == 'S') {
				$sql_sec_auto = "SELECT pccp_nur_orpa FROM saepccp where pccp_cod_empr = $idempresa";
				$secuencial_fgasto = consulta_string_func($sql_sec_auto, 'pccp_nur_orpa', $oIfx, '') + 1;
				$secuencial_fgasto = str_pad($secuencial_fgasto, 9, '0', STR_PAD_LEFT);
				$digitos_serie = $_SESSION['U_PAIS_DIG_SERP'];
				$serie_prove = str_pad(9, $digitos_serie, '9', STR_PAD_LEFT);
			} else {
				$secuencial_fgasto = '';
			}

			$sHtml .= '<tr>
                            <td colspan="4" >
                                <table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
                                    <tr>
                                        <td>' . $ifu->ObjetoHtmlLBL('fecha_validez') . '</td>
										<td> <input type="date" name="fecha_validez" id="fecha_validez" step="1" value="' . $fec_cadu_prove . '">   </td>
										<td style="display:none">' . $ifu->ObjetoHtmlLBL('factura_inicio') . '</td>
										<td style="display:none">
										<input type="text" class="form-control input-sm" id="factura_inicio" name="factura_inicio" value="' . $ini_prove . '" style="width:90px; text-align:right; height:25px"/>
										</td>                                        
                                        <td style="display:none">' . $ifu->ObjetoHtmlLBL('factura_fin') . '</td>
										<td style="display:none">
											<input type="text" class="form-control input-sm" id="factura_fin" name="factura_fin" value="' . $fin_prove . '" style="width:90px; text-align:right; height:25px"/>
										</td>
                                    </tr>
                                </table>
                            </td>						  
						</tr>
                        <tr>
							<td colspan="4">
								<table class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;">
									<tr>
										<td>' . $ifu->ObjetoHtmlLBL('auto_prove') . '</td>
										<td colspan="3">
											<input type="' . $tipo_input . '" class="form-control input-sm" id="auto_prove" name="auto_prove" 
												value="' . $auto_prove . '" style="width:400px; text-align:right; height:25px" 
												onkeyup="' . $evento2 . '" onchange="' . $evento22 . '"/>
										</td>
									</tr>
									<tr>	
										<td>' . $ifu->ObjetoHtmlLBL('serie') . '</td>
										<td>
											<input type="' . $tipo_input . '" class="form-control input-sm" id="serie" name="serie" 
											value="' . $serie_prove . '"
											style="width:120px; height:25px; text-align:right"  onkeyup="' . $evento1 . '" onchange="' . $evento11 . '" />
										</td>
										<td>' . $ifu->ObjetoHtmlLBL('factura') . '</td>
										<td>
											<input type="' . $tipo_input . '" class="form-control input-sm" id="factura" name="factura" style="width:150px; text-align:right ; height:25px; " value="' . $secuencial_fgasto . '"  onkeyup="' . $evento . '" onchange="' . $evento0 . '"/>
										</td>
										
									</tr>
								</table>
							</td>		
                        </tr>';

			$oReturn->assign('divFactura', 'innerHTML', $sHtml);
			$oReturn->assign('serie', 'focus()', '');
		} elseif ($tipo_factura == '') {
			$oReturn->assign('divFactura', 'innerHTML', '');
		}
	}


	$importacion_modulos = $_SESSION['GESTION_IMPORTACION'];
	if (!empty($importacion_modulos)) {

		$sql_saeinfimp = "SELECT * from saeinfimp where infimp_cod_infimp = $importacion_modulos ";
		$infimp_auto_prove = consulta_string($sql_saeinfimp, 'infimp_auto_prove', $oIfx, 0);
		$infimp_seri_prove = consulta_string($sql_saeinfimp, 'infimp_seri_prove', $oIfx, 0);
		$infimp_fact_prove = consulta_string($sql_saeinfimp, 'infimp_fact_prove', $oIfx, 0);


		$oReturn->assign('fecha_validez', 'value', date('Y-m-d'));
		$oReturn->assign('auto_prove', 'value', $infimp_auto_prove);
		$oReturn->assign('serie', 'value', $infimp_seri_prove);
		$oReturn->assign('factura', 'value', $infimp_fact_prove);

		// Calculamos los totales de VAlores factura incluido impuestos
		$oReturn->script('totales(this)');
	}



	return $oReturn;
}

function validar_factura($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$idempresa 		= $_SESSION['U_EMPRESA'];
	$idsucursal 	= $_SESSION['U_SUCURSAL'];
	$u_pais_dig_face = $_SESSION['U_PAIS_DIG_FACE'];

	$tipo_factura 	= $aForm['tipo_factura'];
	$cliente 		= $aForm['cliente'];
	$factura_inicio = $aForm['factura_inicio'] * 1;
	$factura_fin 	= $aForm['factura_fin'] * 1;
	$factura 		= $aForm['factura'];
	$fecha_emision	= $aForm['fecha_emision'];
	$fecha_validez	= $aForm['fecha_validez'];
	$serie      	= $aForm['serie'];
	$factura    	= $aForm['factura'];
	$detalle    	= $aForm['detalle'];
	$serie_factura = $serie . '-' . $factura;

	$sql = "select empr_cod_pais from saeempr where empr_cod_empr='$idempresa'";
	$pais = consulta_string($sql, 'empr_cod_pais', $oIfx, '');
	//$oReturn->alert($pais);

	if ($tipo_factura == 2) {
		// RANGOS DE FACTURAS
		if ($factura >= $factura_inicio && $factura <= $factura_fin) {
			$oReturn->assign('detalla_diario', 'value', $detalle . ' ' . $factura);
			$oReturn->assign('documento', 'value', $factura);
		} else {
			$oReturn->assign('documento', 'value', '');
			$oReturn->assign('detalla_diario', 'value', '');
			$oReturn->assign('factura', 'value', '');
			$oReturn->alert('::.ERROR.:: La Factura numero ' . $factura . ' debe estar dentro del intervalo ' . $factura_inicio . ' - ' . $factura_fin . ' ');
			$oReturn->assign('factura', 'value', '0');
		}
		// FECHA DE CADUCIDAD
		if ($pais == '1') {
			if ($fecha_validez < $fecha_emision) {
				$oReturn->assign('documento', 'value', '');
				$oReturn->assign('factura', 'value', '');
				$oReturn->assign('detalla_diario', 'value', '');
				$oReturn->alert('::.ERROR.:: La Fecha de Caducidad es menor a la Fecha de Ingreso de Factura ');
			} else {
				$oReturn->assign('detalla_diario', 'value', $detalle . ' ' . $factura);
				$oReturn->assign('documento', 'value', $factura);
			}
		} else {
			$oReturn->assign('detalla_diario', 'value', $detalle . ' ' . $factura);
			$oReturn->assign('documento', 'value', $factura);
		}
	}

	return $oReturn;
}

function fecha_mysql_func2($fecha)
{
	$fecha_array = explode('/', $fecha);
	$m = $fecha_array[0];
	$y = $fecha_array[2];
	$d = $fecha_array[1];

	return ($y . '/' . $m . '/' . $d);
}

function reporte($aForm = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$array = $_SESSION['Print'];

	$cont = count($array);

	if ($cont > 0) {
		foreach ($array as $val) {
			$clave_acceso	= $val[0];
			$cod_prove 		= $val[1];
			$factura 		= $val[2];
			$idejer_fact 	= $val[3];
			$asto_cod 		= $val[4];
			$fecha_emis 	= $val[5];
		}
	}

	//$oReturn->alert($array);

	$_SESSION['pdf'] = reporte_retencionGasto($id, $clave_acceso, $rutapdf, $cod_prove, $factura, $idejer_fact, $asto_cod, $fecha_emis);
	//$_SESSION['pdf'] = reporte_retencionGasto($sqlRetGas, 'docu');
	$oReturn->script('generar_pdf()');

	return $oReturn;
}

function centroCostos3($aForm = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$idempresa = $_SESSION['U_EMPRESA'];

	$cuen_cod = $aForm['gasto1'];

	$sql = "select cuen_cod_cuen, cuen_nom_cuen, cuen_ccos_cuen
				from saecuen where
				cuen_cod_empr = $idempresa and
				cuen_mov_cuen = 1 and
				cuen_cod_cuen = '$cuen_cod' ";
	$ccos_cod = consulta_string_func($sql, 'cuen_ccos_cuen', $oIfx, '');

	$ifu->AgregarCampoTexto('ccosn_3', 'Centro Costo|left', false, '', 200, 280);
	$ifu->AgregarComandoAlEscribir('ccosn_3', 'auto_ccosn(' . $idempresa . ', event , 1, 1 );form1.ccosn_3.value=form1.ccosn_3.value.toUpperCase();');

	$ifu->AgregarCampoTexto('cod_ccosn_3', 'Centro Costo|left', false, '', 100, 100);
	$ifu->AgregarComandoAlEscribir('cod_ccosn_3', 'auto_ccosn(' . $idempresa . ', event , 2, 1 );');



	$table  = '<span class="fecha_letra">Centro Costo</span>';
	$table .= $ifu->ObjetoHtml('ccosn_3');
	$table .= '<span class="fecha_letra">Codigo</span>';
	$table .= $ifu->ObjetoHtml('cod_ccosn_3');

	if ($ccos_cod == 'S') {
		$oReturn->assign('gasto_val_centroCostos', 'innerHTML', $table);
		$oReturn->assign("ccosn_3", "placeholder", "PRESIONE ENTER O F4 ....");
	} else {
		// CCOSN 3
		$oReturn->assign('gasto_val_centroCostos', 'innerHTML', '');
		$oReturn->assign("ccosn_3", "innerHTML", '');
	}

	return $oReturn;
}

function centroCostos4($aForm = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$idempresa = $_SESSION['U_EMPRESA'];

	$cuen_cod = $aForm['gasto2'];

	$sql = "select cuen_cod_cuen, cuen_nom_cuen, cuen_ccos_cuen
				from saecuen where
				cuen_cod_empr = $idempresa and
				cuen_mov_cuen = 1 and
				cuen_cod_cuen = '$cuen_cod' ";
	$ccos_cod = consulta_string_func($sql, 'cuen_ccos_cuen', $oIfx, '');


	$ifu->AgregarCampoTexto('ccosn_4', 'Centro Costo|left', false, '', 200, 280);
	$ifu->AgregarComandoAlEscribir('ccosn_4', 'auto_ccosn(' . $idempresa . ', event , 1, 2 );form1.ccosn_4.value=form1.ccosn_4.value.toUpperCase();');

	$ifu->AgregarCampoTexto('cod_ccosn_4', 'Centro Costo|left', false, '', 100, 100);
	$ifu->AgregarComandoAlEscribir('cod_ccosn_4', 'auto_ccosn(' . $idempresa . ', event , 2, 2 );');

	$table  = '<span class="fecha_letra">Centro Costo</span>';
	$table .= $ifu->ObjetoHtml('ccosn_4');
	$table .= '<span class="fecha_letra">Codigo</span>';
	$table .= $ifu->ObjetoHtml('cod_ccosn_4');



	if ($ccos_cod == 'S') {
		$oReturn->assign('gasto_val2_centroCostos', 'innerHTML', $table);
		$oReturn->assign("ccosn_4", "placeholder", "PRESIONE ENTER O F4 ....");
	} else {
		// CCOSN 4
		$oReturn->assign('gasto_val2_centroCostos', 'innerHTML', '');
		$oReturn->assign("ccosn_4", "innerHTML", '');
	}

	return $oReturn;
}

function genera_formulario_clave()
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $_SESSION['U_SUCURSAL'];

	//selecciona ambiente de sucursal
	$sql = "select sucu_tip_ambi from saesucu where sucu_cod_empr = $idempresa and sucu_cod_sucu = $idsucursal";
	$sucu_tip_ambi = consulta_string_func($sql, 'sucu_tip_ambi', $oIfx, 2);

	if ($sucu_tip_ambi == 1) {
		$op = 'N';
	} else {
		$op = 'S';
	}

	$ifu->AgregarCampoTexto('clave_acceso', 'Clave Acceso|left', true, '', 400, 49);
	//$ifu->AgregarComandoAlCambiarValor('clave_acceso', 'clave_acceso_sri()');

	$ifu->AgregarCampoSi_No('ambiente_sri', 'Producción|left', $op);


	$sHtml .=	'<fieldset style="border:#999999 1px solid; padding:2px; text-align:center; width:98%;">
					<legend class="Titulo">CLAVE ACCESO</legend>';
	$sHtml .=	'<table align="center" cellpadding="0" cellspacing="2" width="99%">
					<tr>
						<th colspan="2" align="center" class="diagrama">CLAVE DE ACCESO</th>
					</tr>';
	$sHtml .=	'<tr>
                    <td colspan="2" align="center">Ambiente Produccion: ' . $ifu->ObjetoHtml('ambiente_sri') . '</td>
                 </tr>
				 <tr>
                    <td colspan="2" align="center">' . $ifu->ObjetoHtml('clave_acceso') . '</td>
                 </tr>
				 <tr>
                    <td colspan="2" align="center">
						<input type="button" value="Generar"
                        onClick="javascript:clave_acceso_sri(2)"
                        class="myButton_BT"
                        style="width:100px; height: 25px;"/></td>
                 </tr>';
	$sHtml .= '</table>';

	$oReturn->assign("divReporteClave", "innerHTML", $sHtml);
	$oReturn->assign("clave_acceso", "focus()", "");

	return $oReturn;
}





function clave_acceso($aForm = '', $tipo)
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oCon = new Dbo;
	$oCon->DSN = $DSN;
	$oCon->Conectar();


	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$idempresa 	= $_SESSION['U_EMPRESA'];
	$idsucursal = $_SESSION['U_SUCURSAL'];

	$sucursal = $aForm['sucursal'];
	$sust_trib = $aForm['sust_trib'];
	//variables formulario
	if ($tipo == '1') {
		$clave_acceso	= $aForm['clave_acceso_'];
		$pos          	= $aForm['clave_acceso_'];
	} else {
		$clave_acceso	= $aForm['clave_acceso'];
		$pos          	= $aForm['clave_acceso'];
	}





	$ambiente_sri 	= $aForm['ambiente_sri'];
	unset($_SESSION['DATOS_FACT_PROV']);
	//$ruc			= $aForm['ruc'];
	if ($ambiente_sri == "") {
		$ambiente_sri = "S";
	}
	$sql = "select empr_ruc_empr from saeempr where empr_cod_empr = $idempresa ";
	$ruc = consulta_string_func($sql, 'empr_ruc_empr', $oIfx, 0);

	
	try {





		$headers = array(
			"Content-Type:application/json",
			"Token-Api:9c0ab4af-30dc-4b85-93e2-f0cd28dd7e51"
		);
		$data = array(
			"clave_acceso" => $clave_acceso
		);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_URL, URL_JIREH_WS . "/api/facturacion/electronica/autorizacion/comprobante");
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$respuesta = curl_exec($ch);
		$autoComp[$pos] = (object) json_decode($respuesta, true);
		$data = $autoComp[$pos];
		// $autorizado = $autoComp[$pos]->estado;


		// var_dump($autoComp[$pos]);
		// exit;
		// Adrian47



		//$clientOptions = array(
		//"useMTOM" => FALSE,
		//'trace' => 1,
		//'stream_context' => stream_context_create(array('http' => array('protocol_version' => 1.0) ) )
		//);   

		//if($ambiente_sri == 'S'){
		//	$wsdlAutoComp[$pos] = new  SoapClient("https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl", $clientOptions);
		//}else{
		//	$wsdlAutoComp[$pos] = new  SoapClient("https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl", $clientOptions);
		//}     

		////RECUPERA LA AUTORIZACION DEL COMPROBANTE
		//$aClave = array("claveAccesoComprobante" => $clave_acceso);

		//$autoComp[$pos] = new stdClass();
		//$autoComp[$pos] = $wsdlAutoComp[$pos]->autorizacionComprobante($aClave);

		$RespuestaAutorizacionComprobante[$pos]	= $data;
		$claveAccesoConsultada[$pos] 			= $RespuestaAutorizacionComprobante[$pos]->claveAccesoConsultada;
		// $autorizaciones[$pos] 					= $RespuestaAutorizacionComprobante[$pos]->autorizaciones;
		// $autorizacion[$pos]						= $autorizaciones[$pos]->autorizacion; 
		$autorizacion[$pos]						= 1;

		// var_dump($autorizaciones[$pos]);
		// exit;

		if (count($autorizacion[$pos]) > 1) {
			$estado[$pos] 				= $autorizacion[$pos][0]->estado;
			$numeroAutorizacion[$pos] 	= $autorizacion[$pos][0]->numeroAutorizacion;
			$fechaAutorizacion[$pos] 	= $autorizacion[$pos][0]->fechaAutorizacion;
			$ambiente[$pos] 			= $autorizacion[$pos][0]->ambiente;
			$comprobante[$pos] 			= $autorizacion[$pos][0]->comprobante;
			$mensajes[$pos] 			= $autorizacion[$pos][0]->mensajes;
			$mensaje[$pos] 				= $mensajes[$pos]->mensaje;
		} else {
			$estado[$pos] 				= $RespuestaAutorizacionComprobante[$pos]->estado;
			$numeroAutorizacion[$pos] 	= $RespuestaAutorizacionComprobante[$pos]->numeroAutorizacion;
			$fechaAutorizacion[$pos] 	= $RespuestaAutorizacionComprobante[$pos]->fechaAutorizacion;
			$ambiente[$pos] 			= $RespuestaAutorizacionComprobante[$pos]->ambiente;
			$comprobante[$pos] 			= $RespuestaAutorizacionComprobante[$pos]->comprobante;
			$mensajes[$pos] 			= $RespuestaAutorizacionComprobante[$pos]->mensajes;
			$mensaje[$pos] 				= $mensajes[$pos]->mensaje;
		}

		$xml	=	'';

		$xml 	.=	"$comprobante[$pos]";
		//$xml 	.=	'</autorizacion>';


		// var_dump($RespuestaAutorizacionComprobante[$pos]);
		// exit;


		if ($estado[$pos] == 'AUTORIZADO') {

			//$oReturn->alert("COMPROBANTE: $estado[$pos] $clave_acceso");

			// GUARDAR EN XML
			// CREAR CARPETA ANEXO
			$serv = "/Jireh";
			$ruta = $serv . "/Doc Electronicos Web SRI";
			$ruta1 = "Doc_Electronicos";
			// CARPETA EMPRESA
			$ruta_empr = $ruta1 . "/" . $idempresa;

			if (!file_exists($ruta1)) {
				mkdir($ruta1);
			}

			if (!file_exists($ruta_empr)) {
				mkdir($ruta_empr);
			}

			// ruta del xml
			$nombre = $clave_acceso . ".xml";
			$archivo = fopen($nombre, "w+");
			fwrite($archivo, $xml);
			fclose($archivo);

			// ruta del xml
			$archivo_xml = fopen($ruta_empr . '/' . $nombre, "w+");
			$ruta_xml    = $ruta_empr . '/' . $nombre;
			fwrite($archivo_xml, $xml);
			fclose($archivo_xml);

			$xmlParse 			= simplexml_load_file($ruta_xml);

			$estab 			= $xmlParse->infoTributaria->estab;
			$ptoEmi 		= $xmlParse->infoTributaria->ptoEmi;
			$secuencial 	= $xmlParse->infoTributaria->secuencial;
			$codDoc 	= $xmlParse->infoTributaria->codDoc;
			$identificacionComprador = $xmlParse->infoFactura->identificacionComprador;
			$fechaEmision = strval($xmlParse->infoFactura->fechaEmision);
			$identificacionProveedor = $xmlParse->infoTributaria->ruc;
			$totalImpuesto 	= $xmlParse->infoFactura->totalConImpuestos->totalImpuesto;
			$detalles = $xmlParse->detalles->detalle;
			//$detalle=$detalles[0];
			foreach ($detalles as $arreglo1) {
				$deta = $arreglo1->descripcion;
			}

			$totalbien = 0;
			$totalserv = 0;
			$totalexcento = 0;
			$valor_grab12b = 0;
			$valor_grab0s = 0;
			$iva_multiple_xml = array();
			foreach ($totalImpuesto as $bases) {
				$codigoPorcentaje	= (string)$bases->codigoPorcentaje;
				$baseImponible 		= (float)$bases->baseImponible;
				$valor 				= (float)$bases->valor;
				$porcentajeIva 		= porcentaje_iva_desde_xml_sri($bases);

				if ($codigoPorcentaje == 7) {
					$totalexcento = $totalexcento + $baseImponible;
					continue;
				}

				agregar_iva_multiple_xml_sri($iva_multiple_xml, 'bienes', $porcentajeIva, $baseImponible, $valor);
				if ($porcentajeIva > 0) {
					$valor_grab12b = $valor_grab12b + $baseImponible;
					$totalbien = $totalbien + $baseImponible;
				} else {
					$valor_grab0s = $valor_grab0s + $baseImponible;
					$totalserv = $totalserv + $baseImponible;
				}
			}





			$serie = $estab . $ptoEmi;
			$secuencial = $secuencial . '';


			$sql = "select clpv_cod_clpv from saeclpv where clpv_ruc_clpv='$identificacionProveedor' and 
				clpv_clopv_clpv='PV' and clpv_cod_empr='$idempresa'";

			$cod_clpv = consulta_string_func($sql, 'clpv_cod_clpv', $oIfx, 0);

			// VALIDACION POR EJERCICIO
			$id_anio = date("Y");
			$id_mes  = date("m");
			$fechaActual = date("Y-m-d");

			$sql = "SELECT ejer_cod_ejer from saeejer where DATE_PART('year', ejer_fec_inil) = $id_anio and ejer_cod_empr = $idempresa ";
			$ejer_cod_ejer = consulta_string_func($sql, 'ejer_cod_ejer', $oIfx, 0);


			$sql_control = "select count(*) as contador from saefprv where fprv_cod_empr = $idempresa and
							fprv_cod_sucu = $sucursal and
                            fprv_num_seri = '$serie' and  
                            fprv_cod_clpv = $cod_clpv and	
							fprv_num_fact = '$secuencial' and
							fprv_cod_ejer='$ejer_cod_ejer'
							";
			$contador_ = consulta_string($sql_control, 'contador', $oIfx, '');


	//		$tipo=2; 
	//		exit;
			if ($ruc <> $identificacionComprador)
			{
				$mensaje = 'Esta Factura NO fue Emitida a esta Empresa';
				$tipo_mesaje = 'info';
				$oReturn->script('alerts("' . $mensaje . '", "' . $tipo_mesaje . '");');
			}
			else
			{
				if ($contador_ > 0) {
					$oReturn->script('alerts("Factura ya ingresada", "error");');
				} else {

					if ($tipo == '2') {
						if ($ruc == $identificacionComprador . '') {
							$mensaje = 'Validacion ejecutada correctamente';
							$tipo_mesaje = 'success';
							$oReturn->script('alerts("' . $mensaje . '", "' . $tipo_mesaje . '");');
							$oReturn->assign('auto_prove', 'value', $numeroAutorizacion[$pos]);
							$oReturn->assign('serie', 'value', $serie);
							$oReturn->assign('factura', 'value', $secuencial);
							$oReturn->assign('valor_grab12b', 'value', $valor_grab12b);
							$oReturn->assign('valor_grab0b', 'value', $valor_grab0s);
							$oReturn->assign('valor_grab12t', 'value', $totalbien);
							$oReturn->assign('valor_grab0t', 'value', $totalserv);
							$oReturn->assign('valor_exentoIva', 'value', $totalexcento);

											$oReturn->script(script_cargar_iva_multiple_xml_sri($iva_multiple_xml));
						} else {
							$mensaje = 'El numero de identificacion del Proveedor: ' . $ruc . ' no coincide con la identificacion del archivo xml: ' . $identificacionComprador;
							$tipo_mesaje = 'info';
							$oReturn->script('alerts("' . $mensaje . '", "' . $tipo_mesaje . '");');
						}
					} else {
						// $oReturn->alert($ruc.'');
						//$oReturn->alert($identificacionProveedor.'');
						//if($ruc == $identificacionProveedor.''){
						$sql = "select clpv_cod_clpv from saeclpv where clpv_ruc_clpv='$identificacionProveedor' and 
							clpv_clopv_clpv='PV' and clpv_cod_empr='$idempresa'";
						$cod_clpv = consulta_string_func($sql, 'clpv_cod_clpv', $oIfx, 0);
						$array_fec = explode("/", $fechaEmision);
						$newDate = $array_fec[2] . '-' . $array_fec[1] . '-' . $array_fec[0];

						if ($cod_clpv > 0) {
							$sql = "SELECT tran_cod_tran, trans_tip_comp, tran_des_tran
										FROM saetran WHERE
										(trans_tip_comp is not null ) AND
										tran_cod_empr = $idempresa AND
										tran_cod_sucu = $sucursal and trans_tip_comp='$codDoc'
										order by 1";

							$tran_cod_tran = consulta_string_func($sql, 'tran_cod_tran', $oIfx, 0);
							$oReturn->script('cargarDatosClpv(' . $cod_clpv . ', \'' . $tran_cod_tran . '\' )');
							$tipoRuc = $aForm['tipoRuc'];



							$mensaje = 'Validacion ejecutada correctamente';
							$tipo_mesaje = 'success';
							$oReturn->script('alerts("' . $mensaje . '", "' . $tipo_mesaje . '");');
							$oReturn->assign('auto_prove', 'value', $numeroAutorizacion[$pos]);
							$oReturn->assign('serie', 'value', $serie);




							$oReturn->assign('fecha_emision', 'value', $newDate);

							$oReturn->assign('factura', 'value', $secuencial);

							if (empty($valor_grab12b)) {
								$valor_grab12b = 0;
							}
							if (empty($valor_grab0s)) {
								$valor_grab0s = 0;
							}
							if (empty($totalbien)) {
								$totalbien = 0;
							}
							if (empty($totalserv)) {
								$totalserv = 0;
							}

							$oReturn->assign('valor_grab12b', 'value', $valor_grab12b);
							$oReturn->assign('valor_grab0b', 'value', $valor_grab0s);
							$oReturn->assign('valor_grab12t', 'value', $totalbien);
							$oReturn->assign('valor_grab0t', 'value', $totalserv);
							$oReturn->assign('valor_exentoIva', 'value', $totalexcento);
											$oReturn->script(script_cargar_iva_multiple_xml_sri($iva_multiple_xml));
							

							$oReturn->assign('tipo_factura', 'value', '1');
							/*CAMBIO CARACT ESPECIALES FACTURAS AD*/
							$deta = iconv('UTF-8', 'ASCII//TRANSLIT', $deta);
							$deta = preg_replace('/[^a-zA-Z0-9 ]/', '', $deta);
							$oReturn->assign('detalle', 'value',  ".$deta.");

							$_SESSION['DATOS_FACT_PROV'] = array($serie, $secuencial, $numeroAutorizacion[$pos]);
						} else {
							$oReturn->alert('El proveedor no se encuentra registrado en el sistema');
						}

						/*}else{
							$oReturn->alert('El numero de identificacion del Proveedor: '. $ruc . ' no coincide con la identificacion del archivo xml: ' .$identificacionComprador);
						}*/
					}
				}
			}



			//Return->alert($ruc);

			/*foreach ($xmlParse->comprobante as $val){
				$oReturn->alert($val);
			}*/
			//$oReturn->alert($ruta_xml);
			//$oReturn->alert($ruc);


		} else {
			$informacionAdicional = (strtoupper($mensaje[$clave_acceso]->informacionAdicional));
			$informacionAdicional = preg_replace('([^A-Za-z0-9 ])', '', strtoupper($mensaje[$clave_acceso][0]->informacionAdicional));
			$informacionAdicional = htmlspecialchars_decode($informacionAdicional);
			$oReturn->alert('Error...' . $informacionAdicional);
		}
	} catch (SoapFault $e) {
		$oReturn->alert($pos . ' NO HUBO CONECCION AL SRI (AUTORIZAR)');
	}

	return $oReturn;
}


function cargar_reemb($aForm = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$oReturn = new xajaxResponse();

	$idempresa = $_SESSION['U_EMPRESA'];
	$tran      = $aForm['comprobante'];

	$sql = "select count(*) as cont from saetran where
                tran_cod_empr = $idempresa and
                tran_rem_tran = '1'  and
                tran_cod_tran = '$tran' ";
	$cont = consulta_string_func($sql, 'cont', $oIfxA, 0);

	//$oReturn->alert($sql);
	if ($cont > 0) {
		$tbl = '<div class="btn btn-success btn-sm" title="REEMBOLSO" onclick="reembolso();">
					<span class="glyphicon glyphicon-usd"></span>
					REEMBOLSO
				</div>';
	}


	// ---------------------------------------------------------------------------------------------------------------
	// Factura a afectar
	// ---------------------------------------------------------------------------------------------------------------
	if ($tran == 'NDB') {
		$oReturn->script('habilitar_factura_afect()');
	}
	// ---------------------------------------------------------------------------------------------------------------
	// FIN Factura a afectar
	// ---------------------------------------------------------------------------------------------------------------




	$oReturn->assign('reemb', 'innerHTML', $tbl);
	return $oReturn;
}

function genera_formulario_reembolso($sAccion = 'nuevo', $aForm = '', $total_fact = '', $detalle = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$idempresa          = $_SESSION['U_EMPRESA'];
	$idsucursal         = $_SESSION['U_SUCURSAL'];
	$usuario_informix   = $_SESSION['U_USER_INFORMIX'];

	// D E T A L L E     D E S C R I P C I O N
	$aDataGrid = $_SESSION['aDataGird'];

	$sql = "select empr_iva_empr,  * from saeempr where empr_cod_empr = $idempresa ";
	$iva = consulta_string_func($sql, 'empr_iva_empr', $oIfx, 0);

	switch ($sAccion) {
		case 'nuevo':

			//PARAMETRO TIPO TRANSACCION
			$sql = "SELECT tran_cod_tran
                        FROM saetran WHERE
                         tran_cod_empr  = $idempresa AND
                         tran_cod_sucu  = $idsucursal AND
                         trans_tip_comp = '01' and
                         tran_cod_modu  = 4  ";
			$tran_par = consulta_string_func($sql, 'tran_cod_tran', $oIfx, '');


			// TRAN            
			$ifu->AgregarCampoLista('comprobante_reemb', 'Comprobante|left', true, 170, 150);

			$sql = "SELECT tran_cod_tran, tran_des_tran
                             FROM saetran WHERE
                           ( trans_tip_comp is not null ) AND
                             tran_cod_empr = $idempresa AND
                             tran_cod_sucu = $idsucursal ";
			if ($oIfx->Query($sql)) {
				if ($oIfx->NumFilas() > 0) {
					do {
						$ifu->AgregarOpcionCampoLista('comprobante_reemb', $oIfx->f('tran_des_tran'), $oIfx->f('tran_cod_tran'));
					} while ($oIfx->SiguienteRegistro());
				}
			}

			$ifu->cCampos["comprobante_reemb"]->xValor = $tran_par;

			// RUC 01
			// CEDULA 02
			// PASAPORTE 03
			// CONSUMIDOR FINAL 07
			// EXTRANJERIA 04

			$ifu->AgregarCampoLista('iden_reemb', 'Tipo Identificacion|left', true, 100, 150);
			$ifu->AgregarOpcionCampoLista('iden_reemb', 'RUC', '01');
			$ifu->AgregarOpcionCampoLista('iden_reemb', 'CEDULA', '02');
			$ifu->AgregarOpcionCampoLista('iden_reemb', 'PASAPORTE', '03');

			$ifu->AgregarCampoFecha(
				'fecha_emision_reemb',
				'Fecha Emision|left',
				true,
				date('Y') . '/' . date('m') . '/' . date('d')
			);

			//Consulta de Clientes
			$ifu->AgregarCampoTexto('ruc_reemb', 'Ruc|left', true, '', 120, 120);
			$ifu->AgregarComandoAlCambiarValor('ruc_reemb', "validar_cedula_len()");

			$ifu->AgregarCampoTexto('clpv_remb', 'Proveedor|left', true, '', 350, 200);
			$ifu->AgregarComandoAlEscribir('clpv_remb', 'this.value=this.value.toUpperCase()');

			$ifu->AgregarCampoTexto('factura_reemb', 'No Factura|left', true, '', 100, 10);
			$ifu->AgregarComandoAlCambiarValor('factura_reemb', "cargar_fact_reemb(1)");
			$ifu->AgregarCampoTexto('serie_reemb', 'Punto Emision|left', true, '', 100, 3);
			$ifu->AgregarComandoAlCambiarValor('serie_reemb', "cargar_fact_reemb(2)");
			$ifu->AgregarCampoTexto('estab_reemb', 'Establecimiento|left', true, '', 100, 3);
			$ifu->AgregarComandoAlCambiarValor('estab_reemb', "cargar_fact_reemb(3)");
			$ifu->AgregarCampoFecha('fecha_val_reemb', 'Fecha Validez|left', true, date('Y') . '/' . date('m') . '/' . date('d'));
			$ifu->AgregarCampoTexto('auto_reemb', 'Autorizacion|left', true, '', 350, 49);
			$ifu->AgregarCampoCheck('rise_reemb', 'Rise|left', false, 'N');
			$ifu->AgregarCampoCheck('noiva_reemb', 'No Iva|left', false, 'N');
			$ifu->AgregarCampoFecha('fecha_reg_reemb', 'Fecha Registro|left', true, date('Y') . '/' . date('m') . '/' . date('d'));

			$ifu->AgregarCampoTexto('detalle_reemb', 'Detalle|left', false, $detalle, 700, 1000);
			$ifu->AgregarComandoAlEscribir('detalle_reemb', 'this.value=this.value.toUpperCase()');

			$ifu->AgregarCampoTexto('cta_gasto_reemb', 'Cta Gasto|left', false, '', 300, 200);
			$ifu->AgregarCampoTexto('cta_gasto_nreemb', 'Cta Gasto|left', false, '', 300, 200);
			$ifu->AgregarComandoAlEscribir('cta_gasto_reemb', 'cuenta_gasto_reemb( 0, event )');

			$ifu->AgregarCampoTexto('ccosn_gasto_reemb', 'Centro Costo|left', false, '', 300, 200);
			$ifu->AgregarCampoTexto('ccosn_gasto_nreemb', 'Centro Costo|left', false, '', 300, 200);
			$ifu->AgregarComandoAlEscribir('ccosn_gasto_reemb', 'cargar_ccosn( 0, event )');



			// DATOS FACTURA PROVEEDOR
			// BIENES
			$ifu->AgregarCampoNumerico('valor_grab12b_reemb', 'Valor Grab ' . $iva . '%|left', true, 0, 100, 100);
			$ifu->AgregarComandoAlCambiarValor('valor_grab12b_reemb', 'totales_reemb(this)');
			$ifu->AgregarCampoNumerico('valor_grab0b_reemb', 'Valor Grab  0%|left', true, 0, 100, 100);
			$ifu->AgregarComandoAlCambiarValor('valor_grab0b_reemb', 'totales_reemb(this)');
			$ifu->AgregarCampoNumerico('iceb_reemb', 'ICE|left', true, 0, 100, 100);
			$ifu->AgregarComandoAlCambiarValor('iceb_reemb', 'totales_reemb(this)');
			$ifu->AgregarCampoNumerico('ivab_reemb', 'IVA|left', true, 0, 100, 100);
			$ifu->AgregarComandoAlPonerEnfoque('ivab_reemb', 'this.blur(this)');
			$ifu->AgregarCampoNumerico('ivabp_reemb', 'IVA %|left', true, $iva, 100, 100);

			// SERVICIOS
			$ifu->AgregarCampoNumerico('valor_grab12s_reemb', 'Valor Grab ' . $iva . '%|left', true, 0, 100, 100);
			$ifu->AgregarComandoAlCambiarValor('valor_grab12s_reemb', 'totales1_reemb(this)');
			$ifu->AgregarCampoNumerico('valor_grab0s_reemb', 'Valor Grab  0%|left', true, 0, 100, 100);
			$ifu->AgregarComandoAlCambiarValor('valor_grab0s_reemb', 'totales1_reemb(this)');
			$ifu->AgregarCampoNumerico('ices_reemb', 'ICE|left', true, 0, 100, 100);
			$ifu->AgregarComandoAlCambiarValor('ices_reemb', 'totales_reemb(this)');
			$ifu->AgregarCampoNumerico('ivas_reemb', 'IVA|left', true, 0, 100, 100);
			$ifu->AgregarCampoNumerico('totals_reemb', 'Total|left', true, 0, 100, 100);

			// TOTAL
			$ifu->AgregarCampoNumerico('valor_grab12t_reemb', 'Valor Grab ' . $iva . '%|left', true, 0, 100, 100);
			$ifu->AgregarCampoNumerico('valor_grab0t_reemb', 'Valor Grab  0%|left', true, 0, 100, 100);
			$ifu->AgregarCampoNumerico('icet_reemb', 'ICE|left', true, 0, 100, 100);
			$ifu->AgregarCampoNumerico('ivat_reemb', 'IVA|left', true, 0, 100, 100);
			$ifu->AgregarCampoNumerico('valor_noObjIva_reemb', 'Valor No Objeto Iva|left', true, 0, 100, 100);
			$ifu->AgregarCampoNumerico('valor_exentoIva_reemb', 'Valor Exento Iva|left', true, 0, 100, 100);
			$ifu->AgregarCampoCheck('no_obj_iva_reemb', 'Monto no Objeto Iva|left', true, 'N');

			$ifu->AgregarCampoLista('tipo_factura_reemb', 'T. Factura|left', true, 170, 100);
			$ifu->AgregarOpcionCampoLista('tipo_factura_reemb', 'ELECTRONICA', 1);
			$ifu->AgregarOpcionCampoLista('tipo_factura_reemb', 'PREIMPRESA', 2);
			$ifu->AgregarComandoAlCambiarValor('tipo_factura_reemb', 'cargar_factura_reemb()');


			$total_reemb = 0;
			$saldo_reemb = 0;
			if ($total_fact == 0) {
				$op = '';
				unset($_SESSION['aDataGird']);
				$sHtml2 = "";
				$oReturn->assign("divReembolsoDet", "innerHTML", $sHtml2);
			} else {

				$op = '';
				$cont = count($aDataGrid);
				if ($cont > 0) {
					$sHtml2 = mostrar_grid();
					$total_reemb = 0;
					foreach ($aDataGrid as $aValues) {
						$aux = 0;
						foreach ($aValues as $aVal) {
							if ($aux == 25) {
								$total_reemb += $aVal;
							}
							$aux++;
						}
						$cont++;
					}
				} else {
					$sHtml2 = "";
				}
				$oReturn->assign("divReembolsoDet", "innerHTML", $sHtml2);
			}

			$saldo_reemb = round($total_fact, 2) - round($total_reemb, 2);

			break;
	}

	$sHtml .= '<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
				<tr>
					<td class="bg-primary" colspan="8">FACTURA REEMBOlSO</td>
				</tr>
               <tr class="msgFrm"><td colspan="10" align="center">Los campos con * son de ingreso obligatorio</td></tr>';
	$sHtml .= '<tr>                        
                        <td>' . $ifu->ObjetoHtmlLBL('comprobante_reemb') . '</td>                        
                        <td colspan="3">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                                <tr>
                                    <td>' . $ifu->ObjetoHtml('comprobante_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtmlLBL('iden_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('iden_reemb') . '</td>     
                                    <td>' . $ifu->ObjetoHtmlLBL('fecha_emision_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('fecha_emision_reemb') . '</td>
                                    <td class="letra_rojo">VALOR FACTURA: ' . $total_fact . '</td>
                                </tr>
                            </table>
                        </td>  
               </tr>';
	$sHtml .= '<tr>
                        <td>' . $ifu->ObjetoHtmlLBL('ruc_reemb') . '</td>
                        <td colspan="3">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                                <tr>
                                    <td>' . $ifu->ObjetoHtml('ruc_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtmlLBL('clpv_remb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('clpv_remb') . '</td>
                                </tr>
                            </table>
                        </td>                        
               </tr>';
	$sHtml .= '<tr>
                        <td>' . $ifu->ObjetoHtmlLBL('tipo_factura_reemb') . '</td>
                        <td colspan="3">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                                <tr>
                                    <td>' . $ifu->ObjetoHtml('tipo_factura_reemb') . '</td>
                                </tr>
                            </table>
                        </td>                        
               </tr>';
	$sHtml .= '<tr>
							<td colspan="4">
								<table id = "divFactura_reemb" class="table table-striped table-condensed" style="width: 100%; margin-bottom: 0px;"></table>
							</td>                                                        
						</tr>';
	$sHtml .= '<tr>
						<td>' . $ifu->ObjetoHtmlLBL('estab_reemb') . '</td>
                        <td colspan="3">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
								<tr>
									
									
									
                                    <td>' . $ifu->ObjetoHtml('estab_reemb') . '</td>
									
                                    <td>' . $ifu->ObjetoHtmlLBL('serie_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('serie_reemb') . '</td>      
									
									<td>' . $ifu->ObjetoHtmlLBL('factura_reemb') . '</td>
									<td>' . $ifu->ObjetoHtml('factura_reemb') . '</td>
									
                                    <td>' . $ifu->ObjetoHtmlLBL('fecha_val_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('fecha_val_reemb') . '</td>
                                </tr>
                            </table>
                        </td>    
               </tr>';
	$sHtml .= '<tr>
                        <td>' . $ifu->ObjetoHtmlLBL('auto_reemb') . '</td>
                        <td colspan="3">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                                <tr>
                                    <td>' . $ifu->ObjetoHtml('auto_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtmlLBL('rise_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('rise_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtmlLBL('noiva_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('noiva_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtmlLBL('fecha_reg_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('fecha_reg_reemb') . '</td>
                                 </tr>
                            </table>
                        </td>   
               </tr>';
	$sHtml .= '<tr>
                        <td>' . $ifu->ObjetoHtmlLBL('detalle_reemb') . '</td>
                        <td colspan="3">' . $ifu->ObjetoHtml('detalle_reemb') . '</td>
               </tr>';
	$sHtml .= '<tr>
                        <td>' . $ifu->ObjetoHtmlLBL('cta_gasto_reemb') . '</td>
                        <td colspan="3">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                                <tr>
                                    <td>' . $ifu->ObjetoHtml('cta_gasto_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('cta_gasto_nreemb') . '</td>
                                </tr>
                            </table>
                        </td>                        
               </tr>';
	$sHtml .= '<tr>
                        <td>' . $ifu->ObjetoHtmlLBL('ccosn_gasto_reemb') . '</td>
                        <td colspan="3">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                                <tr>
                                    <td>' . $ifu->ObjetoHtml('ccosn_gasto_reemb') . '</td>
                                    <td>' . $ifu->ObjetoHtml('ccosn_gasto_nreemb') . '</td>
                                </tr>
                            </table>
                        </td>                        
               </tr>';
	$sHtml .= '</table>';

	//PARA EL TIPO DE AUTORIZACION
	$sHtml .= '<table id = "divFactura" align="center" cellpadding="0" cellspacing="2" width="100%" border="1" colspan="10"></table>';

	$sHtml .= '<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">';
	$sHtml .= '<tr>
                        <td colspan="4">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                                <tr>
                                    <td valign="top">
                                        <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
											<tr>
												<td colspan="4">DATOS FACTURA PROVEEDOR</td>
											</tr>
                                            <tr>
                                                <td>
													<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
														<tr>
															<td colspan="4">BIENES</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('valor_grab12b_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('valor_grab12b_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('valor_grab0b_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('valor_grab0b_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('iceb_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('iceb_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('ivab_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('ivab_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('ivabp_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('ivabp_reemb') . '</td>
														</tr>
														<tr>
															<td>&nbsp;</td>
															<td>&nbsp;</td>
														</tr>
													</table>
                                                </td>
                                                <td>
													<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
														<tr>
															<td colspan="4">SERVICIOS</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('valor_grab12s_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('valor_grab12s_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('valor_grab0s_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('valor_grab0s_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('ices_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('ices_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('ivas_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('ivas_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('totals_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('totals_reemb') . '</td>
														</tr>
														<tr>
															<td>&nbsp;</td>
															<td>&nbsp;</td>
														</tr>
													</table>
                                                </td>
                                                <td>
													<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
														<tr>
															<td colspan="4">TOTAL</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('valor_grab12t_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('valor_grab12t_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('valor_grab0t_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('valor_grab0t_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('icet_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('icet_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('ivat_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('ivat_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('valor_noObjIva_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('valor_noObjIva_reemb') . '</td>
														</tr>
														<tr>
															<td>
															' . $ifu->ObjetoHtmlLBL('valor_exentoIva_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('valor_exentoIva_reemb') . '</td>
														</tr>
														<tr style="display: none;">
															<td>
															' . $ifu->ObjetoHtmlLBL('no_obj_iva_reemb') . '</td>
															<td>
															' . $ifu->ObjetoHtml('no_obj_iva_reemb') . '</td>
														</tr>
													</table>
                                                </td>
                                            </tr>
										</table>
                                     </td>
                                 </tr>
                            </table>
                        </td>
                   </tr>';
	$sHtml .= '<tr>
                <td colspan="4" align="left" height="25px">
                    <table>
                        <tr>
                            <td class="letra_rojo">VALOR FACTURA: ' . $total_fact . '</td>
                            <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
                            <td class="letra_rojo">VALOR REEMBOLSO:</td>
                            <td class="letra_rojo" id="total_reembolso">' . $total_reemb . '</td>
                            <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
                            <td class="letra_rojo">SALDO:</td>
                            <td class="letra_rojo" id="saldo_reembolso">' . $saldo_reemb . '</td>
                        </tr>
                    </table>
              </tr>';
	$sHtml .= '<tr>
                <td colspan="4" align="center">
					<div class="btn btn-primary btn-sm" onclick="agregar_reembolso();">
						<span class="glyphicon glyphicon-hand-down"></span>
						Agregar Reembolso
					</div>
              </tr>';
	$sHtml .= '</table>';


	$oReturn->assign("divReembolso", "innerHTML", $sHtml);
	return $oReturn;
}

function totales_reemb($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	//Definiciones
	$oReturn = new xajaxResponse();

	// BIENES
	$valor_grab12b = $aForm['valor_grab12b_reemb'];
	$valor_grab0b = $aForm['valor_grab0b_reemb'];
	$iceb = $aForm['iceb_reemb'];
	$ivabp = $aForm['ivabp_reemb'];
	$ivab = round((($ivabp / 100) * $valor_grab12b), 2);


	// SERVICIOS
	$valor_grab12s = $aForm['valor_grab12s_reemb'];
	$valor_grab0s = $aForm['valor_grab0s_reemb'];
	$ices = $aForm['ices_reemb'];
	$ivas = round((($ivabp / 100) * $valor_grab12s), 2);


	// TOTALES
	$valor_grab12t = $valor_grab12b + $valor_grab12s;
	$valor_grab0t = $valor_grab0b + $valor_grab0s;
	$icet = $iceb + $ices;
	$ivat = $ivab + $ivas;



	$totals = round(($valor_grab12t + $valor_grab0t + $icet + $ivat), 2);

	$oReturn->assign("ivab_reemb", "value", $ivab);
	$oReturn->assign("ivas_reemb", "value", $ivas);
	$oReturn->assign("totals_reemb", "value", $totals);
	$oReturn->assign("valor_grab12t_reemb", "value", $valor_grab12t);
	$oReturn->assign("valor_grab0t_reemb", "value", $valor_grab0t);
	$oReturn->assign("icet_reemb", "value", $icet);
	$oReturn->assign("ivat_reemb", "value", $ivat);

	return $oReturn;
}


/*********************************************/
/*   M O S T R A R     D A T A    G R I D    */
/********************************************/
function agrega_modifica_grid($nTipo = 0,  $aForm = '', $id = '', $total_fact = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$aDataGrid = $_SESSION['aDataGird'];

	$aLabelGrid = array(
		'Id',
		'Comprobante',
		'Iden',
		'Ruc',
		'Fecha Emision',
		'Proveedor',
		'Factura',
		'Serie',
		'Estab',
		'Fecha Validez',
		'Autorizacion',
		'Rise',
		'NoIVa',
		'Fecha Registro',
		'Detalle',
		'Cta',
		'Valor14b',
		'Valor0b',
		'Iceb',
		'Ivab',
		'Ivapb',
		'Valor14s',
		'Valor0s',
		'Ices',
		'Ivas',
		'Total',
		'Valor14t',
		'Valor0t',
		'Icet',
		'Ivat',
		'NoObjiva',
		'ExceIva',
		'Centro Costo',
		'Eliminar'
	);
	$oReturn = new xajaxResponse();

	$idempresa  =  $_SESSION['U_EMPRESA'];
	$idsucursal =  $_SESSION['U_SUCURSAL'];
	$decimal = 6;

	//$oReturn->alert('hola');

	$tran_cod    = $aForm['comprobante_reemb'];
	$iden        = $aForm['iden_reemb'];
	$fecha_emis  = $aForm['fecha_emision_reemb'];
	$ruc_clpv    = $aForm['ruc_reemb'];
	$clpv_nom    = $aForm['clpv_remb'];
	$factura     = $aForm['factura_reemb'];
	$serie       = $aForm['serie_reemb'];
	$estab       = $aForm['estab_reemb'];
	$fecha_valid = $aForm['fecha_val_reemb'];
	$autoriza    = $aForm['auto_reemb'];
	$rise        = $aForm['rise_reemb'];
	if (!empty($rise)) {
		$rise = 'S';
	} else {
		$rise = 'N';
	}
	$noiva       = $aForm['noiva_reemb'];
	if (!empty($noiva)) {
		$noiva = 'S';
	} else {
		$noiva = 'N';
	}
	$fecha_reg   = $aForm['fecha_reg_reemb'];
	$detalle     = $aForm['detalle_reemb'];
	$cta         = $aForm['cta_gasto_reemb'];
	$valor14b    = $aForm['valor_grab12b_reemb'];
	$valor0b     = $aForm['valor_grab0b_reemb'];
	$iceb        = $aForm['iceb_reemb'];
	$ivab        = $aForm['ivab_reemb'];
	$ivapb       = $aForm['ivabp_reemb'];
	$valor14s    = $aForm['valor_grab12s_reemb'];
	$valor0s     = $aForm['valor_grab0s_reemb'];
	$ices        = $aForm['ices_reemb'];
	$ivas        = $aForm['ivas_reemb'];
	$total       = $aForm['totals_reemb'];
	$valor14t    = $aForm['valor_grab12t_reemb'];
	$valor0t     = $aForm['valor_grab0t_reemb'];
	$icet        = $aForm['icet_reemb'];
	$ivat        = $aForm['ivat_reemb'];
	$noobjiva    = $aForm['valor_noObjIva_reemb'];
	$exceiva     = $aForm['valor_exentoIva_reemb'];
	$cod_ccosn   = $aForm['ccosn_gasto_reemb'];


	// TOTALES
	$array = $_SESSION['aDataGird'];

	$total_remb  = 0;
	if (count($array) > 0) {
		foreach ($array as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 25) {
					$total_remb += $aVal;
				}
				$aux++;
			}
			$cont++;
		}
	}

	$total_remb += $total;

	if (round($total_remb, 2) <= round($total_fact, 2)) {

		if ($nTipo == 0) {

			//GUARDA LOS DATOS DEL DETALLE
			$cont = count($aDataGrid);
			$cont++;
			if (!empty($aDataGrid[$cont])) {
				$cont++;
			}

			$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
			$aDataGrid[$cont][$aLabelGrid[1]] = $tran_cod;
			$aDataGrid[$cont][$aLabelGrid[2]] = $iden;
			$aDataGrid[$cont][$aLabelGrid[3]] = $ruc_clpv;
			$aDataGrid[$cont][$aLabelGrid[4]] = $fecha_emis;
			$aDataGrid[$cont][$aLabelGrid[5]] = $clpv_nom;
			$aDataGrid[$cont][$aLabelGrid[6]] = $factura;
			$aDataGrid[$cont][$aLabelGrid[7]] = $serie;
			$aDataGrid[$cont][$aLabelGrid[8]] = $estab;
			$aDataGrid[$cont][$aLabelGrid[9]] = $fecha_valid;
			$aDataGrid[$cont][$aLabelGrid[10]] = $autoriza;
			$aDataGrid[$cont][$aLabelGrid[11]] = $rise;
			$aDataGrid[$cont][$aLabelGrid[12]] = $noiva;
			$aDataGrid[$cont][$aLabelGrid[13]] = $fecha_reg;
			$aDataGrid[$cont][$aLabelGrid[14]] = $detalle;
			$aDataGrid[$cont][$aLabelGrid[15]] = $cta;
			$aDataGrid[$cont][$aLabelGrid[16]] = $valor14b;
			$aDataGrid[$cont][$aLabelGrid[17]] = $valor0b;
			$aDataGrid[$cont][$aLabelGrid[18]] = $iceb;
			$aDataGrid[$cont][$aLabelGrid[19]] = $ivab;
			$aDataGrid[$cont][$aLabelGrid[20]] = $ivapb;
			$aDataGrid[$cont][$aLabelGrid[21]] = $valor14s;
			$aDataGrid[$cont][$aLabelGrid[22]] = $valor0s;
			$aDataGrid[$cont][$aLabelGrid[23]] = $ices;
			$aDataGrid[$cont][$aLabelGrid[24]] = $ivas;
			$aDataGrid[$cont][$aLabelGrid[25]] = $total;
			$aDataGrid[$cont][$aLabelGrid[26]] = $valor14t;
			$aDataGrid[$cont][$aLabelGrid[27]] = $valor0t;
			$aDataGrid[$cont][$aLabelGrid[28]] = $icet;
			$aDataGrid[$cont][$aLabelGrid[29]] = $ivat;
			$aDataGrid[$cont][$aLabelGrid[30]] = $noobjiva;
			$aDataGrid[$cont][$aLabelGrid[31]] = $exceiva;
			$aDataGrid[$cont][$aLabelGrid[32]] = $cod_ccosn;
			$aDataGrid[$cont][$aLabelGrid[33]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
                                                            onMouseOver="drc(\'Presione aqui para Eliminar\', \'Eliminar\'); return true;"
                                                            onMouseOut="javascript:nd(); return true;"
                                                            title = "Presione aqui para Eliminar"
                                                            style="cursor: hand !important; cursor: pointer !important;"
                                                            onclick="javascript:xajax_elimina_detalle(' . $cont . ');"
                                                            alt="Eliminar"
                                                            align="bottom" />';
		}

		$_SESSION['aDataGird'] = $aDataGrid;
		$sHtml = mostrar_grid();
		// TOTALES
		$array = $_SESSION['aDataGird'];
		$total     = 0;
		foreach ($array as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 25) {
					$total += $aVal;
				}
				$aux++;
			}
			$cont++;
		}

		//$oReturn->alert($total);
		$oReturn->assign("total_reembolso", "innerHTML", $total);
		$oReturn->assign("saldo_reembolso", "innerHTML", round(($total_fact - $total), 2));
		$oReturn->assign("divReembolsoDet", "innerHTML", $sHtml);
		$oReturn->script("limpiar();");

		$oReturn->script('parent.genera_comprobante();');
	} else {
		$oReturn->alert('SobrePaso el Valor Total de la Factura....!!!!');
	}


	return $oReturn;
}

function mostrar_grid()
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa =  $_SESSION['U_EMPRESA'];
	$aDataGrid = $_SESSION['aDataGird'];
	$aLabelGrid = array(
		'Id',
		'Comprobante',
		'Iden',
		'Ruc',
		'Fecha Emision',
		'Proveedor',
		'Factura',
		'Serie',
		'Estab',
		'Fecha Validez',
		'Autorizacion',
		'Rise',
		'NoIVa',
		'Fecha Registro',
		'Detalle',
		'Cta',
		'Valor14b',
		'Valor0b',
		'Iceb',
		'Ivab',
		'Ivapb',
		'Valor14s',
		'Valor0s',
		'Ices',
		'Ivas',
		'Total',
		'Valor14t',
		'Valor0t',
		'Icet',
		'Ivat',
		'NoObjiva',
		'ExceIva',
		'Centro Costo',
		'Eliminar'
	);

	$cont = 0;
	$total     = 0;
	foreach ($aDataGrid as $aValues) {
		$aux = 0;
		foreach ($aValues as $aVal) {
			if ($aux == 0) {
				$aDatos[$cont][$aLabelGrid[$aux]] = $cont + 1;
			} elseif ($aux == 1) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 2) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 3) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 4) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 5) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 7) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 6) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 8) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 9) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 25) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
				$total += $aVal;
			} elseif ($aux == 33) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
                                                                        <img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
                                                                        title = "Presione aqui para Eliminar"
                                                                        style="cursor: hand !important; cursor: pointer !important;"
                                                                        onclick="javascript:xajax_elimina_detalle(' . $cont . ');"
                                                                        alt="Eliminar"
                                                                        align="bottom" />
                                                                    </div>';
			} else
				$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
			$aux++;
		}
		$cont++;
	}
	// tOTALES
	// unset($array_tot);
	//$array_tot = array('','','','',$tot_boton, $tot_botonr, $total);

	return genera_grid($aDatos, $aLabelGrid, 'Reembolso', 98, null, $array_tot);
}

function elimina_detalle($id = null)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$aLabelGrid = array(
		'Id',
		'Comprobante',
		'Iden',
		'Ruc',
		'Fecha Emision',
		'Proveedor',
		'Factura',
		'Serie',
		'Estab',
		'Fecha Validez',
		'Autorizacion',
		'Rise',
		'NoIVa',
		'Fecha Registro',
		'Detalle',
		'Cta',
		'Valor14b',
		'Valor0b',
		'Iceb',
		'Ivab',
		'Ivapb',
		'Valor14s',
		'Valor0s',
		'Ices',
		'Ivas',
		'Total',
		'Valor14t',
		'Valor0t',
		'Icet',
		'Ivat',
		'NoObjiva',
		'ExceIva',
		'Centro Costo',
		'Eliminar'
	);
	$aDataGrid = $_SESSION['aDataGird'];
	$contador = count($aDataGrid);
	if ($contador > 1) {
		unset($aDataGrid[$id]);
		$_SESSION['aDataGird'] = $aDataGrid;
		$sHtml = mostrar_grid();
		$oReturn->assign("divReembolsoDet", "innerHTML", $sHtml);
	} else {
		unset($aDataGrid[0]);
		$_SESSION['aDataGird'] = $aDatos;
		$sHtml = "";
		$oReturn->assign("divReembolsoDet", "innerHTML", $sHtml);
	}

	$oReturn->script('parent.genera_comprobante();');

	return $oReturn;
}

function cargar_fact_reemb($op, $aForm = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	//Definiciones
	$oReturn = new xajaxResponse();

	$idempresa = $_SESSION['U_EMPRESA'];
	$factura   = $aForm['factura_reemb'];
	$serie     = $aForm['serie_reemb'];
	$estab     = $aForm['estab_reemb'];
	$ruc_reemb = $aForm['ruc_reemb'];

	$cero    = 0;
	switch ($op) {
		case '1':
			# code...
			$factura = secuencial_pedido(2, '', ($factura - 1), 9);
			$oReturn->assign("factura_reemb", "value", $factura);
			break;
		case '2':
			# code...
			$len   = 3 - strlen($serie);
			$cero  = cero_mas('0', $len);
			$serie = $cero . $serie;
			$oReturn->assign("serie_reemb", "value", $serie);
			break;
		case '3':
			# code...
			$len   = 3 - strlen($estab);
			$cero  = cero_mas('0', $len);
			$estab = $cero . $estab;
			$oReturn->assign("estab_reemb", "value", $estab);
			break;
		default:
			# code...
			break;
	}

	// CONTROL DE FACTURA
	$sql = "select count(*) as cont from saefprr where
				fprr_cod_empr = $idempresa and
				fprr_ruc_prov = '$ruc_reemb' and
				fprr_num_fact = '$factura' and
				fprr_num_seri = '$serie' and
				fprr_num_esta = '$estab' ";
	//$oReturn->alert($sql);
	$cont = consulta_string_func($sql, 'cont', $oIfx, '0');
	if ($cont > 0) {
		$oReturn->alert('!!! Factura Repetida....');
		$oReturn->assign("factura_reemb", "value", '');
	}

	// CONTROL DE FACTURA INGRESADA EN GRID

	$aDataGrid = $_SESSION['aDataGird'];
	$cont = count($aDataGrid);
	if ($cont > 0) {
		foreach ($aDataGrid as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 3) {
					$ruc_grid = $aVal;
				} elseif ($aux == 6) {
					$factura_grid = $aVal;
				} elseif ($aux == 7) {
					$serie_grid = $aVal;
				} elseif ($aux == 8) {
					$estab_grid = $aVal;

					if ($factura == $factura_grid && $serie == $serie_grid && $estab == $estab_grid) {
						$oReturn->alert('!!! Factura Repetida....');
						$oReturn->assign("factura_reemb", "value", '');
					}
				}
				$aux++;
			}
			$cont++;
		}
	}

	return $oReturn;
}

function genera_formulario_correo($sAccion = 'nuevo', $aForm = '', $sucursal = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();


	switch ($sAccion) {
		case 'nuevo':

			//Consulta de Clientes
			$ifu->AgregarCampoTexto('correo', 'Correo|left', true, '', 220, 120);
			break;
	}


	$sHtml .= '<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
				<tr>
					<td colspan="4" class="bg-primary">CORREO</td>
				</tr>
				<tr class="msgFrm">
					<td colspan="10" align="center">Los campos con * son de ingreso obligatorio</td>
				</tr>';
	$sHtml .= '<tr>                        
                        <td class="labelFrm">' . $ifu->ObjetoHtmlLBL('correo') . '</td>                        
                        <td colspan="3">
                            <table>
                                <tr>
                                    <td>' . $ifu->ObjetoHtml('correo') . '</td>
                                </tr>
                            </table>
                        </td>  
               </tr>';
	$sHtml .= '<tr>                                               
					<td colspan="4" align="center">
						<div class="btn btn-primary btn-sm" onclick="guardar();">
							<span class="glyphicon glyphicon-plus-sign"></span>
							Agregar
						</div>
					</td>  
               </tr>';

	$sHtml .= '</table>';


	$oReturn->assign("divReembolso", "innerHTML", $sHtml);
	return $oReturn;
}

function guardar_correo($aForm = '', $cliente = '',  $sucursal = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();
	$correo  = $aForm['correo'];

	$idempresa = $_SESSION['U_EMPRESA'];
	$sql = "insert into saeemai ( emai_cod_empr, emai_cod_sucu, emai_cod_clpv,  emai_ema_emai ) 
                        values  ( $idempresa,    $sucursal,     $cliente,       '$correo' ); ";
	$oIfx->QueryT($sql);

	$oReturn->alert('Ingresando Correctamente...');

	return $oReturn;
}

function cargar_electronica($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa   = $_SESSION['U_EMPRESA'];
	$oReturn 	 = new xajaxResponse();

	$electronica = $aForm['electronica'];
	$idsucursal  = $aForm['sucursal'];

	if ($electronica == 'S') {
		$sql = "select retp_sec_retp, retp_num_seri, retp_fech_cadu , retp_num_auto
							from saeretp where 
							retp_cod_empr = $idempresa and
							retp_cod_sucu = $idsucursal and
							retp_act_retp = 1 and 
							retp_elec_sn  = 'S' ";
		$num_rete     = consulta_string_func($sql, 'retp_sec_retp', $oIfx, '');
		$num_rete     = secuencial(2, '', $num_rete, 9);
		$seri_rete 	  = consulta_string_func($sql, 'retp_num_seri', $oIfx, '');
		$ret_fec_auto = fecha_mysql_func_(consulta_string_func($sql, 'retp_fech_cadu', $oIfx, date("m/d/Y")));
		$rete_auto    = consulta_string_func($sql, 'retp_num_auto', $oIfx, '');

		$oReturn->script("automatico();");
	} else {
		$sql = "select retp_sec_retp, retp_num_seri, retp_fech_cadu , retp_num_auto
							from saeretp where 
							retp_cod_empr = $idempresa and
							retp_cod_sucu = $idsucursal and
							retp_act_retp = 1 and 
							retp_elec_sn  = 'N' ";
		$num_rete     = consulta_string_func($sql, 'retp_sec_retp', $oIfx, '');
		$num_rete     = secuencial(2, '', $num_rete, 9);
		$seri_rete 	  = consulta_string_func($sql, 'retp_num_seri', $oIfx, '');
		//$ret_fec_auto = fecha_mysql_func_(consulta_string_func($sql, 'retp_fech_cadu', $oIfx, date("m/d/Y") ));
		$ret_fec_auto = '12-12-2021';
		$rete_auto    = consulta_string_func($sql, 'retp_num_auto', $oIfx, '');
		$oReturn->script("manual();");
	}

	$oReturn->assign("num_rete",   "value", $num_rete);
	$oReturn->assign("serie_rete", "value", $seri_rete);
	$oReturn->assign("auto_rete",  "value", $rete_auto);
	$oReturn->assign("cad_rete",   "value", $ret_fec_auto);

	return $oReturn;
}

// DISTRIBUCION
// DISTRIBUCION
function genera_formulario_distribucion($sAccion = 'nuevo', $aForm = '', $total_fact = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$idempresa          = $_SESSION['U_EMPRESA'];
	$usuario_informix   = $_SESSION['U_USER_INFORMIX'];

	// D E T A L L E     D E S C R I P C I O N
	$aDataGrid = $_SESSION['aDataGirdDist'];

	switch ($sAccion) {
		case 'nuevo':

			$ifu->AgregarCampoTexto('cta_gasto_dist', 'Cta Gasto|left', false, '', 100, 200);
			$ifu->AgregarCampoTexto('cta_gasto_ndist', 'Cta Gasto|left', false, '', 300, 200);
			$ifu->AgregarComandoAlEscribir('cta_gasto_dist', 'cuenta_gasto_dist( 0, event, 2 )');

			$ifu->AgregarCampoTexto('ccosn_gasto_dist', 'Centro Costo|left', false, '', 100, 200);
			$ifu->AgregarCampoTexto('ccosn_gasto_ndist', 'Centro Costo|left', false, '', 300, 200);
			$ifu->AgregarComandoAlEscribir('ccosn_gasto_dist', 'cargar_ccosn( 0, event )');
			$ifu->AgregarCampoNumerico('valor_dist', 'Valor|left', false, '', 80, 100);
			$ifu->AgregarComandoAlEscribir('valor_dist', 'agregar_dist_tecla(event)');

			$ifu->AgregarCampoTexto('detalleDistribucion', 'Detalle|left', false, '', 500, 200);


			$total_reemb = 0;
			$saldo_reemb = 0;
			if ($total_fact == 0) {
				$op = '';
				unset($_SESSION['aDataGirdDist']);
				$sHtml2 = "";
				$oReturn->assign("divDistribucionDet", "innerHTML", $sHtml2);
			} else {
				$total_fact = round($total_fact, 2);
				$op = '';
				$cont = count($aDataGrid);
				if ($cont > 0) {
					$sHtml2 = mostrar_grid_dist();
					$total_reemb = 0;
					foreach ($aDataGrid as $aValues) {
						$aux = 0;
						foreach ($aValues as $aVal) {
							if ($aux == 5) {
								$total_reemb += round($aVal, 2);
							}
							$aux++;
						}
						$cont++;
					}
				} else {
					$sHtml2 = "";
				}
				$oReturn->assign("divDistribucionDet", "innerHTML", $sHtml2);
			}
			//$oReturn->alert($sHtml2);
			$saldo_reemb = round($total_fact - $total_reemb, 2);

			break;
	}

	$sHtml .= '<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
				<tr>
					<td colspan="4" class="bg-primary">DISTRIBUCION</td>
				</tr>
               <tr class="msgFrm"><td colspan="10" align="center">Los campos con * son de ingreso obligatorio</td></tr>';
	$sHtml .= '<tr style="display:none">
                        <td>' . $ifu->ObjetoHtmlLBL('cta_gasto_dist') . '</td>
                        <td colspan="3">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                                <tr>
                                    <td>' . $ifu->ObjetoHtml('cta_gasto_dist') . '</td>
                                    <td>' . $ifu->ObjetoHtml('cta_gasto_ndist') . '</td>
                                </tr>
                            </table>
                        </td>                        
               </tr>';
	$sHtml .= '<tr style="display:none">
                        <td>' . $ifu->ObjetoHtmlLBL('ccosn_gasto_dist') . '</td>
                        <td colspan="3">
                            <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                                <tr>
                                    <td>' . $ifu->ObjetoHtml('ccosn_gasto_dist') . '</td>
                                    <td>' . $ifu->ObjetoHtml('ccosn_gasto_ndist') . '</td>
                                </tr>
                            </table>
                        </td>                        
               </tr>';
	$sHtml .= '<tr style="display:none">';
	$sHtml .= '<td>' . $ifu->ObjetoHtmlLBL('detalleDistribucion') . '</td>';
	$sHtml .= '<td colspan="3">' . $ifu->ObjetoHtml('detalleDistribucion') . '</td>';
	$sHtml .= '</tr>';
	$sHtml .= '<tr style="display:none">
                        <td>' . $ifu->ObjetoHtmlLBL('valor_dist') . '</td>
						<td colspan="3" >' . $ifu->ObjetoHtml('valor_dist') . '</td>                      
               </tr>';

	$sHtml .= '</table>';

	$sHtml .= '<tr>
                <td colspan="4" align="left" height="25px">
                    <table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
                        <tr>
                            <td class="letra_rojo">VALOR: ' . $total_fact . '</td>
                            <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
                            <td class="letra_rojo">VALOR DISTRIBUCION:</td>
                            <td class="letra_rojo" id="total_dist">' . $total_reemb . '</td>
                            <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
                            <td class="letra_rojo">SALDO:</td>
                            <td class="letra_rojo" id="saldo_dist">' . $saldo_reemb . '</td>
                        </tr>
                    </table>
              </tr>';
	$sHtml .= '<tr>
                <td colspan="4" align="center">
					<div class="btn btn-primary btn-sm" onclick="agregar_dist();">
						<span class="glyphicon glyphicon-plus-sign"></span>
						Agregar
					</div>
              </tr>';
	$sHtml .= '</table>';

	// CENTRO DE COSTOS
	$table_op .= '<table class="table table-striped table-condensed table-bordered table-hover" style="width: 90%; margin-top: 20px;" align="center">';
	$table_op .= '<tr>
						<td align="center" class="fecha_letra">N.-</th>
						<td align="center" class="fecha_letra">Codigo</th>
						<td align="center" class="fecha_letra">Centro Costo</th>
						<td align="center" class="fecha_letra">%</th>
						<td align="center" class="fecha_letra">Valor</th>
				</tr>';
	$sql = "select  ccosn_cod_ccosn,  ccosn_nom_ccosn, *
					from saeccosn where
					ccosn_cod_empr  = $idempresa and
					ccosn_mov_ccosn = '1' and
					ccosn_impr_sn   = 'N' 
					order by 1 ";
	$i = 1;
	unset($array_ccosn);
	if ($oIfx->Query($sql)) {
		if ($oIfx->NumFilas() > 0) {
			do {
				$ccosn_cod_ccosn  = $oIfx->f('ccosn_cod_ccosn');
				$ccosn_nom_ccosn  = $oIfx->f('ccosn_nom_ccosn');

				$ifu->AgregarCampoNumerico($ccosn_cod_ccosn . '_porc', 'Valor|left', true, '0', 80, 100);
				$ifu->AgregarComandoAlCambiarValor($ccosn_cod_ccosn . '_porc', 'cargar_datos_ccosn()');

				$ifu->AgregarCampoNumerico($ccosn_cod_ccosn . '_val', 'Valor|left', true, '0', 80, 100);
				$ifu->AgregarComandoAlCambiarValor($ccosn_cod_ccosn . '_val', 'cargar_datos_ccosnV()');

				$table_op .= '<tr>';
				$table_op .= '<td align="right">' . $i . '</td>';
				$table_op .= '<td align="right">' . $ccosn_cod_ccosn . '</td>';
				$table_op .= '<td align="left" >' . $ccosn_nom_ccosn . '</td>';
				$table_op .= '<td align="right" >' . $ifu->ObjetoHtml($ccosn_cod_ccosn . '_porc') . '</td>';
				$table_op .= '<td align="right" >' . $ifu->ObjetoHtml($ccosn_cod_ccosn . '_val') . '</td>';
				$table_op .= '</tr>';

				$i++;

				$array_ccosn[] =  array($ccosn_cod_ccosn,  $ccosn_nom_ccosn);
			} while ($oIfx->SiguienteRegistro());

			$table_op .= '<tr>';
			$table_op .= '<td align="right"></td>';
			$table_op .= '<td align="right"></td>';
			$table_op .= '<td align="left" ></td>';
			$table_op .= '<td align="right" id="total_ccosnp" class="letra_rojo">0.00</td>';
			$table_op .= '<td align="right" id="total_ccosn"  class="letra_rojo">0.00</td>';
			$table_op .= '</tr>';
		}
	}

	$table_op .= '</table>';

	$_SESSION['U_ARRAY_CCOSN'] = $array_ccosn;

	$oReturn->assign("divDistribucion", "innerHTML", $sHtml . $table_op);
	$oReturn->assign("cta_gasto_dist", "focus()", "");
	return $oReturn;
}

function agrega_modifica_grid_dist($nTipo = 0,  $aForm = '', $id = '', $total_fact = '', $cuen_cod)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$aDataGrid    = $_SESSION['aDataGirdDist'];
	$array_ccosn  = $_SESSION['U_ARRAY_CCOSN'];

	$aLabelGrid = array(
		'Id',
		'Cuenta',
		'Nombre Cuenta',
		'Centro Costo',
		'Nombre CCosto',
		'Valor',
		'Detalle',
		'Eliminar'
	);
	$oReturn = new xajaxResponse();

	$idempresa  =  $_SESSION['U_EMPRESA'];

	//$oReturn->alert('hola');

	$cta_cod     = $aForm['cta_gasto_dist'];
	$cta_nom     = $aForm['cta_gasto_ndist'];
	$ccosn_cod   = $aForm['ccosn_gasto_dist'];
	$ccosn_nom   = $aForm['ccosn_gasto_ndist'];
	$valor       = $aForm['valor_dist'];
	$detalleDistribucion = $aForm['detalleDistribucion'];

	// TOTALES
	$array = $_SESSION['aDataGirdDist'];
	$total_remb  = 0;
	if (count($array) > 0) {
		foreach ($array as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 5) {
					$total_remb += round($aVal, 2);
				}
				$aux++;
			}
			$cont++;
		}
	}

	$total_remb += $valor;
	$total_fact = round($total_fact, 2);
	$total_remb = round($total_remb, 2);

	$sql = "select cuen_nom_cuen from saecuen where cuen_cod_empr = $idempresa and cuen_cod_cuen = '$cuen_cod'  ";
	$cta_nom = consulta_string_func_func_func($sql, 'cuen_nom_cuen', $oIfx, 0);
	//$oReturn->alert($total_remb.'*'.$total_fact);
	if ($total_remb <= $total_fact) {

		if ($nTipo == 0) {

			if (count($array_ccosn) > 0) {
				foreach ($array_ccosn as $val) {
					$ccosn_cod_ccosn  = $val[0];
					$ccosn_nom_ccosn  = $val[1];
					$porc_ccosn		  = $aForm[$ccosn_cod_ccosn . '_porc'];
					$val_ccosn  	  = $aForm[$ccosn_cod_ccosn . '_val'];
					if ($porc_ccosn > 0) {
						//GUARDA LOS DATOS DEL DETALLE
						$cont = count($aDataGrid);

						$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
						$aDataGrid[$cont][$aLabelGrid[1]] = $cuen_cod;
						$aDataGrid[$cont][$aLabelGrid[2]] = $cta_nom;
						$aDataGrid[$cont][$aLabelGrid[3]] = $ccosn_cod_ccosn;
						$aDataGrid[$cont][$aLabelGrid[4]] = $ccosn_nom_ccosn;
						$aDataGrid[$cont][$aLabelGrid[5]] = $val_ccosn;
						$aDataGrid[$cont][$aLabelGrid[6]] = $detalleDistribucion;
						$aDataGrid[$cont][$aLabelGrid[7]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																			onMouseOver="drc(\'Presione aqui para Eliminar\', \'Eliminar\'); return true;"
																			onMouseOut="javascript:nd(); return true;"
																			title = "Presione aqui para Eliminar"
																			style="cursor: hand !important; cursor: pointer !important;"
																			onclick="javascript:xajax_elimina_detalle_dist(' . $cont . ');"
																			alt="Eliminar"
																			align="bottom" />';
					}
				}
			}
		}

		$_SESSION['aDataGirdDist'] = $aDataGrid;
		$sHtml = mostrar_grid_dist();
		// TOTALES
		$array = $_SESSION['aDataGirdDist'];
		$total     = 0;
		foreach ($array as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 5) {
					$total += $aVal;
				}
				$aux++;
			}
			$cont++;
		}

		//$oReturn->alert($total);
		$oReturn->assign("total_dist", "innerHTML", $total);
		$oReturn->assign("saldo_dist", "innerHTML", (round($total_fact - $total, 2)));
		$oReturn->assign("divDistribucionDet", "innerHTML", $sHtml);
		$oReturn->script("limpiar();");
		$oReturn->assign("cta_gasto_dist", "focus()", "");
	} else {
		$oReturn->alert('SobrePaso el Valor Total....!!!!');
	}


	return $oReturn;
}

function mostrar_grid_dist()
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa =  $_SESSION['U_EMPRESA'];
	$aDataGrid = $_SESSION['aDataGirdDist'];
	$aLabelGrid = array(
		'Id',
		'Cuenta',
		'Nombre Cuenta',
		'Centro Costo',
		'Nombre CCosto',
		'Valor',
		'Detalle',
		'Eliminar'
	);

	$cont = 0;
	$total = 0;
	foreach ($aDataGrid as $aValues) {
		$aux = 0;
		foreach ($aValues as $aVal) {
			if ($aux == 0) {
				$aDatos[$cont][$aLabelGrid[$aux]] = $cont + 1;
			} elseif ($aux == 1) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 2) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
			} elseif ($aux == 3) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
			} elseif ($aux == 4) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
			} elseif ($aux == 5) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
				$total += $aVal;
			} elseif ($aux == 6) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
			} elseif ($aux == 7) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
                                                                        <img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
                                                                        title = "Presione aqui para Eliminar"
                                                                        style="cursor: hand !important; cursor: pointer !important;"
                                                                        onclick="javascript:xajax_elimina_detalle_dist(' . $cont . ');"
                                                                        alt="Eliminar"
                                                                        align="bottom" />
                                                                    </div>';
			} else
				$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
			$aux++;
		}
		$cont++;
	}
	// tOTALES
	// unset($array_tot);
	//$array_tot = array('','','','',$tot_boton, $tot_botonr, $total);

	return genera_grid($aDatos, $aLabelGrid, 'Datos', 95, null, $array_tot);
}

function elimina_detalle_dist($id = null)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$aLabelGrid = array(
		'Id',
		'Cuenta',
		'Nombre Cuenta',
		'Centro Costo',
		'Nombre CCosto',
		'Valor',
		'Detalle',
		'Eliminar'
	);

	$aDataGrid = $_SESSION['aDataGirdDist'];
	$contador = count($aDataGrid);
	if ($contador > 1) {
		unset($aDataGrid[$id]);
		$_SESSION['aDataGirdDist'] = $aDataGrid;
		$sHtml = mostrar_grid_dist();
		$oReturn->assign("divDistribucionDet", "innerHTML", $sHtml);
	} else {
		unset($aDataGrid[0]);
		$_SESSION['aDataGirdDist'] = $aDatos;
		$sHtml = "";
		$oReturn->assign("divDistribucionDet", "innerHTML", $sHtml);
	}

	$oReturn->script('total_dist();');
	return $oReturn;
}

function totales_dist($aForm = '', $total_fact = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$aDataGrid   = $_SESSION['aDataGirdDist'];
	$total_dist  = 0;
	$saldo_dist  = 0;

	if ($total_fact > 0) {
		$op = '';
		$cont = count($aDataGrid);
		if ($cont > 0) {
			$total_dist = 0;
			foreach ($aDataGrid as $aValues) {
				$aux = 0;
				foreach ($aValues as $aVal) {
					if ($aux == 5) {
						$total_dist += $aVal;
					}
					$aux++;
				}
				$cont++;
			}
		}
	}

	$saldo_dist = $total_fact - $total_dist;

	$oReturn->assign("total_dist", "innerHTML", $total_dist);
	$oReturn->assign("saldo_dist", "innerHTML", ($total_fact - $total_dist));

	return $oReturn;
}

// GENERA COMPROBANTE
function genera_comprobante($aForm = '')
{
	//Definiciones
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();
	//      VARIABLES
	$idempresa      = $_SESSION['U_EMPRESA'];
	$idsucursal     = $aForm['sucursal'];
	$usuario_web    = $_SESSION['U_ID'];
	$aDataGrid      = $_SESSION['aDataGirdDist'];

	$cod_prove      = $aForm['cliente'];
	$factura      	= $aForm['factura'];
	$retencion      = $aForm['num_rete'];

	// BIENES
	$valor_grab12b  = $aForm['valor_grab12b'];
	$valor_grab0b   = $aForm['valor_grab0b'];
	$iceb           = $aForm['iceb'];
	$ivab           = $aForm['ivab'];
	$ivabp          = $aForm['ivabp'];

	// SERVICIOS
	$valor_grab12s  = $aForm['valor_grab12s'];
	$valor_grab0s   = $aForm['valor_grab0s'];
	$ices           = $aForm['ices'];
	$ivas           = $aForm['ivas'];
	$totals         = $aForm['totals'];

	// TOTAL
	$valor_grab12t  = $aForm['valor_grab12t'];
	$valor_grab0t   = $aForm['valor_grab0t'];
	$icet           = $aForm['icet'];
	$ivat           = $aForm['ivat'];

	//no objeto y excento de iva
	$valor_noObjIva = $aForm['valor_noObjIva'];
	$valor_exentoIva = $aForm['valor_exentoIva'];

	$ret_asumido    = $aForm['ret_asumido'];

	if (empty($valor_exentoIva)) {
		$valor_exentoIva = 0.00;
	}

	// DATOS RETENCION PROVEEDOR
	// BIENES
	$codigo_ret_fue_b   = $aForm['codigo_ret_fue_b'];
	$porc_ret_fue_b     = $aForm['porc_ret_fue_b'];
	$base_fue_b         = $aForm['base_fue_b'];
	$val_fue_b          = $aForm['val_fue_b'];

	// SERVICIOS
	$codigo_ret_fue_s   = $aForm['codigo_ret_fue_s'];
	$porc_ret_fue_s     = $aForm['porc_ret_fue_s'];
	$base_fue_s         = $aForm['base_fue_s'];
	$val_fue_s          = $aForm['val_fue_s'];

	//RETENCION IVA BIENES
	$codigo_ret_iva_b   = $aForm['codigo_ret_iva_b'];
	$porc_ret_iva_b     = $aForm['porc_ret_iva_b'];
	$base_iva_b         = $aForm['base_iva_b'];
	$val_iva_b          = ($aForm['val_iva_b'] != '') ? $aForm['val_iva_b'] : 0;

	//RETENCION IVA SERVICIOS
	$codigo_ret_iva_s   = $aForm['codigo_ret_iva_s'];
	$porc_ret_iva_s     = $aForm['porc_ret_iva_s'];
	$base_iva_s         = $aForm['base_iva_s'];
	$val_iva_s          = ($aForm['val_iva_s'] != '') ? $aForm['val_iva_s'] : 0;

	// CUENTAS CONTABLES
	$fisc_b             = trim($aForm['fisc_b']);
	$fisc_s             = trim($aForm['fisc_s']);
	$gasto1             = trim($aForm['gasto1']);
	$val_gasto1         = $aForm['val_gasto1'];
	$gasto2             = trim($aForm['gasto2']);
	$val_gasto2         = $aForm['val_gasto2'];

	//centro de costos
	$ccosn_3            = $aForm['cod_ccosn_3'];
	$ccosn_4            = $aForm['cod_ccosn_4'];

	$no_obj_iva         = $aForm['no_obj_iva'];
	if (!empty($no_obj_iva)) {
		$basenograiva = $valor_grab0t;
		$baseimponible = 0;
	} else {
		$basenograiva = 0;
		$baseimponible = $valor_grab0t;
	}

	$basenograiva   = number_format($basenograiva, 2, '.', '');
	$baseimponible  = number_format($baseimponible, 2, '.', '');
	$baseimpgrav    = number_format($valor_grab12t, 2, '.', '');
	$montoice       = number_format($icet, 2, '.', '');
	$montoiva       = number_format($ivat, 2, '.', '');

	// REPORTE

	$table_op .= '<table class="table table-hover table-bordered table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
					<tr>
						<td colspan="9" class="bg-primary">COMPROBANTE</td>
					</tr>';
	$table_op .= '<tr>
                        <td align="center">N.-</td>
                        <td align="center">Cuenta</td>
                        <td align="center">Nombre</td>
						<td align="center">Factura</td>
						<td align="center">Retencion</td>
                        <td align="center">Debito</td>
                        <td align="center">Credito</td>
                        <td align="center">Centro Costo</td>
						<td align="center">Detalle</td>
                </tr>';

	// CTA PROVEEDOR
	// PROVEEDOR
	$val_fue_b = vacios($val_fue_b, 0);
	$val_fue_s = vacios($val_fue_s, 0);
	$val_iva_b = vacios($val_iva_b, 0);
	$val_iva_s = vacios($val_iva_s, 0);

	$valor_ret_asumid = 0;
	if ($ret_asumido == 'S') {
		// RETNCION ASUMIDA
		$cre_ml    			= $totals;
		$valor_ret_asumid 	= $val_fue_b + $val_fue_s + $val_iva_b + $val_iva_s;
	} else {
		// RETENCION NOMRAL
		$cre_ml    = $totals - $val_fue_b - $val_fue_s - $val_iva_b - $val_iva_s;
	}



	$sql = "select  clpv_cod_tprov, clv_con_clpv, clpv_cod_paisp, clpv_cod_cuen
                from saeclpv where
                clpv_cod_clpv = '$cod_prove' and
                clpv_cod_empr = $idempresa and
                clpv_clopv_clpv = 'PV' ";
	$prove_tprov = '';
	if ($oIfx->Query($sql)) {
		if ($oIfx->NumFilas() > 0) {
			$clpv_cod_cuen = $oIfx->f('clpv_cod_cuen');
		}
	}
	$oIfx->Free();

	$i = 1;
	$table_op .= detalle_compr($idempresa, $clpv_cod_cuen, $ccos, 0, $cre_ml, $i, $oIfx, $factura, $retencion);
	$i++;

	/*$class->saedasi($oIfx, $idempresa, $idsucursal, $clpv_cod_cuen, $idprdo_cont, 
                    $idejer_cont, $ccos, 0, $cre_ml, 0, $cre_ml, 
                    1, $detalle, $cod_prove, $comprobante, $usuario_web, $asto_cod, '', 1, '');
    */

	$tot_cre = 0;
	$tot_deb = 0;
	$tot_cre += $cre_ml;
	$x = 1;
	if (!empty($codigo_ret_fue_b)) {
		$sql = "select  tret_cta_cre from saetret where
                    tret_cod_empr = $idempresa and
                    tret_cod = '$codigo_ret_fue_b' ";
		$ret_cuen = consulta_string_func($sql, 'tret_cta_cre', $oIfx, '');

		/*
		$class->saedasi($oIfx, $idempresa, $idsucursal, $ret_cuen, $idprdo_cont, $idejer_cont, 
                        $ccos, 0, $val_fue_b, 0, $val_fue_b, 
                        1, $detalle, $cod_prove, $comprobante, $usuario_web, $asto_cod, $x, '', $codigo_ret_fue_b );
        */
		$table_op .= detalle_compr($idempresa, $ret_cuen, $ccos, 0, $val_fue_b, $i, $oIfx, '', $retencion);

		$i++;
		$tot_cre += $val_fue_b;
	}

	if (!empty($codigo_ret_fue_s)) {
		$sql = "select  tret_cta_cre from saetret where
                    tret_cod_empr = $idempresa and
                    tret_cod = '$codigo_ret_fue_s' ";
		$ret_cuen = consulta_string_func($sql, 'tret_cta_cre', $oIfx, '');

		/*$class->saedasi($oIfx, $idempresa, $idsucursal, $ret_cuen, $idprdo_cont, $idejer_cont, 
                        $ccos, 0, $val_fue_s, 0, $val_fue_s, 
                        1, $detalle, $cod_prove, $comprobante, $usuario_web, $asto_cod, $x, '', $codigo_ret_fue_s );
        */
		$table_op .= detalle_compr($idempresa, $ret_cuen, $ccos, 0, $val_fue_s, $i, $oIfx, '', $retencion);
		$i++;
		$tot_cre += $val_fue_s;
	}

	if (!empty($codigo_ret_iva_b)) {
		$sql = "select  tret_cta_cre from saetret where
                    tret_cod_empr = $idempresa and
                    tret_cod = '$codigo_ret_iva_b' ";
		$ret_cuen = consulta_string_func($sql, 'tret_cta_cre', $oIfx, '');

		/*$class->saedasi($oIfx, $idempresa, $idsucursal, $ret_cuen, $idprdo_cont, $idejer_cont, 
                        $ccos, 0, $val_iva_b, 0, $val_iva_b, 
                        1, $detalle, $cod_prove, $comprobante, $usuario_web, $asto_cod, $x, '', $codigo_ret_iva_b );
        */
		$table_op .= detalle_compr($idempresa, $ret_cuen, $ccos, 0, $val_iva_b, $i, $oIfx, '', $retencion);
		$i++;
		$tot_cre += $val_iva_b;
	}

	if (!empty($codigo_ret_iva_s)) {
		$sql = "select  tret_cta_cre from saetret where
                    tret_cod_empr = $idempresa and
                    tret_cod = '$codigo_ret_iva_s' ";
		//$oReturn->alert($sql);
		$ret_cuen = consulta_string_func($sql, 'tret_cta_cre', $oIfx, '');

		/*$class->saedasi($oIfx, $idempresa, $idsucursal, $ret_cuen, $idprdo_cont, $idejer_cont, 
                        $ccos, 0, $val_iva_s, 0, $val_iva_s, 
                        1, $detalle, $cod_prove, $comprobante, $usuario_web, $asto_cod, $x, '', $codigo_ret_iva_s );
        */
		$table_op .= detalle_compr($idempresa, $ret_cuen, $ccos, 0, $val_iva_s, $i, $oIfx, '', $retencion);
		$i++;
		$tot_cre += $val_iva_s;
	}


	// DASI  CTAS
	if (!empty($fisc_b)) {
		/*$class->saedasi($oIfx, $idempresa, $idsucursal, $fisc_b, $idprdo_cont, 
                        $idejer_cont, '', $ivab, 0, $ivab, 0, 
                        1, $detalle, $cod_prove, $comprobante, $usuario_web, $asto_cod, '', '', '');
        */
		$table_op .= detalle_compr($idempresa, $fisc_b, '', $ivab, 0, $i, $oIfx, '', '');

		$tot_deb += $ivab;
		$i++;

		//$oReturn->alert($fisc_b .' '.detalle_compr($idempresa, $fisc_b, '', $ivab, 0, $i, $oIfx, '', ''));
	}

	if (!empty($fisc_s)) {
		/*$class->saedasi($oIfx, $idempresa, $idsucursal, $fisc_s, $idprdo_cont, $idejer_cont, '', $ivas, 0, $ivas, 0, 
                        1, $detalle, $cod_prove, $comprobante, $usuario_web, $asto_cod, '', '', '');
        */
		$table_op .= detalle_compr($idempresa, $fisc_s, '', $ivas, 0, $i, $oIfx, '', '');

		$i++;
		$tot_deb += $ivas;
	}



	if (count($aDataGrid) > 0) {
		/*0       'Id',            1       'Cuenta',             2      'Nombre Cuenta',
        3       'Centro Costo',  4       'Nombre CCosto',      5        'Valor',    
        6       'Eliminar'  */
		foreach ($aDataGrid as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 0) {
					$i       = $cont + 1;
				} elseif ($aux == 1) {
					$cuen_cod = $aVal;
				} elseif ($aux == 2) {
					$cuen_nom = $aVal;
				} elseif ($aux == 3) {
					$ccos_cod = $aVal;
				} elseif ($aux == 4) {
					$ccos_nom = $aVal;
				} elseif ($aux == 5) {
					$valor    = $aVal;
				} elseif ($aux == 6) {
					$detalleDistribucion = $aVal;
					$table_op .= detalle_compr($idempresa, $cuen_cod, $ccos_cod, $valor, 0, $i, $oIfx, $factura, $retencion, $detalleDistribucion);
					$tot_deb += $valor;
					//$oReturn->alert( $cuen_cod);
				}
				$aux++;
			}
			$cont++;
		}
	} else {
		if (!empty($gasto1)) {
			/*$class->saedasi($oIfx, $idempresa, $idsucursal, $gasto1, $idprdo_cont, $idejer_cont, 
                            $ccosn_3, $val_gasto1, 0, $val_gasto1, 0, 
                            1, $detalle, $cod_prove, $comprobante, $usuario_web, $asto_cod, '', '', '');
            */
			$table_op .= detalle_compr($idempresa, $gasto1, $ccosn_3, $val_gasto1, 0, $i, $oIfx, '', '');
			$i++;
			$tot_deb += $val_gasto1;
		}
	}

	// REEMBOLSO
	$aDataGridReemb = $_SESSION['aDataGird'];
	if (count($aDataGridReemb) > 0) {
		unset($array_dasi_c);
		unset($array_tmp);
		$pos = 0;
		foreach ($aDataGridReemb as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 0) {
					$ii        = $cont + 1;
				} elseif ($aux == 15) {
					$cuen_cod = $aVal;
				} elseif ($aux == 6) {
					$factura  = $aVal;
				} elseif ($aux == 32) {
					$ccos_cod = $aVal;
				} elseif ($aux == 26) {
					$valor14tr = round($aVal, 2);
				} elseif ($aux == 27) {
					$valor0tr = round($aVal, 2);
				} elseif ($aux == 33) {
					$valor_reemb = 0;
					$valor_reemb = $valor14tr + $valor0tr;

					$array_dasi_c[$cuen_cod][$ccos_cod] += $valor_reemb;
					$array_tmp[$pos] = array($cuen_cod, $ccos_cod);

					$pos++;
					$tot_deb += $valor_reemb;
				}
				$aux++;
			}
			$cont++;
		}

		if (count($array_tmp) > 0) {
			unset($array_cta);
			$array_cta = array_unique($array_tmp);
			$cta_prod = '';
			$cta_cosn = '';
			foreach ($array_cta as $val) {
				$cta_prod = $val[0];
				$cta_cosn = $val[1];
				$valor_reemb = 0;
				$valor_reemb = round($array_dasi_c[$cta_prod][$cta_cosn], 2);

				$table_op .= detalle_compr($idempresa, $cta_prod, $cta_cosn, $valor_reemb, 0, $i, $oIfx, '', '', '');

				$i++;
			} // fin for
		} // fin if

	} else {
		if ($val_gasto2 > 0) {
			/*$class->saedasi($oIfx, $idempresa, $idsucursal, $gasto2, $idprdo_cont, $idejer_cont, 
					$ccosn_4, $val_gasto2, 0, $val_gasto2, 0, 
					1, $detalle, $cod_prove, $comprobante, $usuario_web, $asto_cod, '', '', '');
			*/
			$table_op .= detalle_compr($idempresa, $gasto2, $ccosn_4, $val_gasto2, 0, $i, $oIfx, '', '');
			$tot_deb += $val_gasto2;
			$i++;
		}
	} // fin reembo       

	// RETENCION Asumida
	if ($valor_ret_asumid > 0) {
		$sql = "select pccp_cret_asumi   from saepccp where	pccp_cod_empr = $idempresa ";
		$cuenta_re_asum = consulta_string_func($sql, 'pccp_cret_asumi', $oIfx, '');

		$table_op .= detalle_compr($idempresa, $cuenta_re_asum, '', $valor_ret_asumid, 0, $i, $oIfx, '', '');
		$tot_deb += $valor_ret_asumid;
		$i++;
	}

	// TOTALES
	$table_op .= '<tr class="danger">
					<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>
					<td align="right" class="fecha_letra">Total:</td>
					<td align="right" class="fecha_letra">' . $tot_deb . '</td>
					<td align="right" class="fecha_letra">' . $tot_cre . '</td>
					<td align="center" colspan="2"></td>
                </tr>';
	$table_op .= '</table>';

	$table_op = '';
	$table_op .= '<table class="table table-hover table-bordered table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
					<tr>
						<td colspan="9" class="bg-primary">COMPROBANTE</td>
					</tr>';
	$oReturn->assign("divComprobante", "innerHTML", $table_op);

	// SERIE - FACTURA
	$serie = $aForm['serie'];
	$fact  = $aForm['factura'];

	//$oReturn->alert($serie);
	$oReturn->assign("documento", "value", $serie . '-' . $fact);

	return $oReturn;
}

function detalle_compr($idempresa, $cod_cuen, $cod_ccosn, $debito, $credito, $i, $oIfx, $factura, $retencion, $detalle = '')
{
	$sql = "select  cuen_nom_cuen  from saecuen where
                cuen_cod_empr = $idempresa and
                cuen_cod_cuen = '$cod_cuen' and
				cuen_mov_cuen = '1' ";
	$cuen_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

	$table_op = '';

	if (!empty($cuen_nom)) {
		$table_op .= '<tr>';
		$table_op .= '<td align="right">' . $i . '</td>';
		$table_op .= '<td align="left">' . $cod_cuen . '</td>';
		$table_op .= '<td align="left">' . $cuen_nom . '</td>';
		$table_op .= '<td align="left">' . $factura . '</td>';
		$table_op .= '<td align="left">' . $retencion . '</td>';
		$table_op .= '<td align="right">' . $debito . '</td>';
		$table_op .= '<td align="right">' . $credito . '</td>';
		$table_op .= '<td align="right">' . $cod_ccosn . '</td>';
		$table_op .= '<td align="left">' . $detalle . '</td>';
		$table_op .= '</tr>';
	}

	return $table_op;
}

// CUENTA RETENCION FUENTE BIENES
function cod_ret_b($op, $ret_nom, $aForm = '', $tipo = 0)
{
	//Definiciones
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	//      VARIABLES
	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm['sucursal'];
	$tipo_retencion_ad = $aForm['tipo_retencion_ad'];

	if ($op == 0) {
		$ret_nom = $aForm['codigo_ret_fue_b'];
	} elseif ($op == 1) {
		$ret_nom = $aForm['codigo_ret_fue_s'];
	}

	$sql = "select tret_cod, tret_det_ret, tret_porct, tret_cta_cre
                from saetret where
                tret_cod_empr = $idempresa and
                tret_ban_retf = 'IR' and
                tret_ban_crdb = 'CR' and
                tret_cod      = '$ret_nom'
                order by 1 ";
	$oIfx->Query($sql);
	$cont = $oIfx->NumFilas();

	if ($cont == 1) {
		if ($oIfx->Query($sql)) {
			if ($oIfx->NumFilas() > 0) {
				$codigo  = $oIfx->f('tret_cod');
				$porc    = $oIfx->f('tret_porct');
				$cuen_cod = $oIfx->f('tret_cta_cre');
			}
		}



		// -----------------------------------------------------------------------------------------------------------
		// Traemos la configuracion de los decimales
		// -----------------------------------------------------------------------------------------------------------
		$empr_cod_pais = $_SESSION['U_PAIS_COD'];

		// TIPO RETENCION => RETENCION O DETRACCION
		//$sql_tipo_rete = "select * from comercial.pais_etiq_imp p where p.pais_cod_pais = $empr_cod_pais and impuesto = 'TIPO_RETENCION' ";
		//$tipo_rete = consulta_string_func($sql_tipo_rete, 'etiqueta', $oIfx, '');

		// DECIMALES DETRACCION
		$sql_decimales_rete = "select * from comercial.pais_etiq_imp p where p.pais_cod_pais = $empr_cod_pais and impuesto = 'DECIMALES_RETENCION' ";
		$decimales_rete = consulta_string_func($sql_decimales_rete, 'etiqueta', $oIfx, 0);
		if (empty($decimales_rete)) {
			$decimales_rete = 2;
		}


		// -----------------------------------------------------------------------------------------------------------
		// FIN Traemos la configuracion de los decimales
		// -----------------------------------------------------------------------------------------------------------



		if ($op == 0) {
			$oReturn->assign("codigo_ret_fue_b", "value",    $codigo);
			$oReturn->assign("porc_ret_fue_b", "value",      $porc);
			$base = 0;
			$base = $aForm['base_fue_b'];
			$resp = 0;
			$resp = number_format(round((($base * $porc) / 100), $decimales_rete), $decimales_rete + 1, '.', '');
			$oReturn->assign("val_fue_b", "value",           $resp);
			$oReturn->script('genera_comprobante();');
		} else {
			$oReturn->assign("codigo_ret_fue_s", "value",    $codigo);
			$oReturn->assign("porc_ret_fue_s", "value",      $porc);
			$base = 0;
			$base = $aForm['base_fue_s'];
			$resp = 0;
			$resp = number_format(round((($base * $porc) / 100), $decimales_rete), $decimales_rete + 1, '.', '');
			$oReturn->assign("val_fue_s", "value",           $resp);
			$oReturn->script('genera_comprobante();');
		}


		if ($ret_nom == 327) {
			$oReturn->script('muestra_divi();');
		}
	} else {
		$oReturn->script('ventana_cod_ret_b(' . $op . ', ' . $tipo . ');');
	}


	return $oReturn;
}

// CUENTA RETENCION IVA BIENES
function cod_ret_iva($op, $ret_nom, $aForm = '', $tipo = 0)
{
	//Definiciones
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	//      VARIABLES
	$idempresa      = $_SESSION['U_EMPRESA'];
	$idsucursal     = $aForm['sucursal'];

	if ($op == 0) {
		$ret_nom = $aForm['codigo_ret_iva_b'];
	} elseif ($op == 1) {
		$ret_nom = $aForm['codigo_ret_iva_s'];
	}

	$sql = "select tret_cod, tret_det_ret, tret_porct,tret_cta_cre
                from saetret where
                tret_cod_empr = $idempresa and
                tret_ban_retf = 'RI'  and
                tret_ban_crdb = 'CR'  and
                tret_cod      = '$ret_nom'
                order by 1 ";
	$oIfx->Query($sql);
	$cont = $oIfx->NumFilas();
	//$oReturn->alert($cont);

	if ($cont == 1) {
		if ($oIfx->Query($sql)) {
			if ($oIfx->NumFilas() > 0) {
				$codigo  = $oIfx->f('tret_cod');
				$porc    = $oIfx->f('tret_porct');
				$cuen_cod = $oIfx->f('tret_cta_cre');
			}
		}



		// -----------------------------------------------------------------------------------------------------------
		// Traemos la configuracion de los decimales
		// -----------------------------------------------------------------------------------------------------------
		$empr_cod_pais = $_SESSION['U_PAIS_COD'];

		// TIPO RETENCION => RETENCION O DETRACCION
		//$sql_tipo_rete = "select * from comercial.pais_etiq_imp p where p.pais_cod_pais = $empr_cod_pais and impuesto = 'TIPO_RETENCION' ";
		//$tipo_rete = consulta_string_func($sql_tipo_rete, 'etiqueta', $oIfx, '');

		// DECIMALES DETRACCION
		$sql_decimales_rete = "select * from comercial.pais_etiq_imp p where p.pais_cod_pais = $empr_cod_pais and impuesto = 'DECIMALES_RETENCION' ";
		$decimales_rete = consulta_string_func($sql_decimales_rete, 'etiqueta', $oIfx, 0);
		if (empty($decimales_rete)) {
			$decimales_rete = 2;
		}


		// -----------------------------------------------------------------------------------------------------------
		// FIN Traemos la configuracion de los decimales
		// -----------------------------------------------------------------------------------------------------------



		if ($op == 0) {
			$oReturn->assign("codigo_ret_iva_b", "value",    $codigo);
			$oReturn->assign("porc_ret_iva_b", "value",      $porc);
			$base = 0;
			$base = $aForm['base_iva_b'];
			$resp = 0;
			$resp = number_format(round((($base * $porc) / 100), $decimales_rete), $decimales_rete + 1, '.', '');
			//$resp = number_format($resp, 2, '.', ',');
			$oReturn->assign("val_iva_b", "value",           $resp);
			$oReturn->script('genera_comprobante();');
		} else {
			$oReturn->assign("codigo_ret_iva_s", "value",    $codigo);
			$oReturn->assign("porc_ret_iva_s", "value",      $porc);
			$base = 0;
			$base = $aForm['base_iva_s'];
			$resp = 0;
			$resp = number_format(round((($base * $porc) / 100), $decimales_rete), $decimales_rete + 1, '.', '');
			//$resp = number_format($resp, 2, '.', ',');
			$oReturn->assign("val_iva_s", "value",           $resp);
			$oReturn->script('genera_comprobante();');
		}
	} else {
		$oReturn->script('ventana_cod_ret_iva(' . $op . ', ' . $tipo . ');');
	}


	return $oReturn;
}


function selectCuentaContable($aForm = '', $cod = '', $op = 0, $tipo = 0)
{
	//Definiciones
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	//VARIABLES
	$idempresa = $_SESSION['U_EMPRESA'];

	try {

		$sql = "select cuen_cod_cuen, cuen_nom_cuen, cuen_ccos_cuen
				from saecuen where
				cuen_cod_empr = $idempresa and
				cuen_mov_cuen = 1 and
				cuen_cod_cuen = '$cod'";
		//$oReturn->alert($sql);
		if ($oIfx->Query($sql)) {
			if ($oIfx->NumFilas() > 0) {
				$cuen_cod_cuen  = $oIfx->f('cuen_cod_cuen');
				$cuen_nom_cuen  = $oIfx->f('cuen_nom_cuen');
				$cuen_ccos_cuen  = $oIfx->f('cuen_ccos_cuen');
			}
		}
		$oIfx->Free();

		if (!empty($cuen_nom_cuen)) {
			if ($tipo == 0) {
				if ($op == 0) {
					$oReturn->assign("fisc_b", "value", $cuen_cod_cuen);
					$oReturn->assign("fisc_b_tmp", "innerHTML", $cuen_nom_cuen);
				} else {
					$oReturn->assign("fisc_s", "value", $cuen_cod_cuen);
					$oReturn->assign("fisc_s_tmp", "innerHTML", $cuen_nom_cuen);
				}
			} elseif ($tipo == 1) {
				if ($op == 0) {
					$oReturn->assign("gasto1", "value", $cuen_cod_cuen);
					$oReturn->assign("gasto_val", "innerHTML", $cuen_nom_cuen);
					$oReturn->script('cargar_centroCostos3();');
				} else {
					$oReturn->assign("gasto2", "value", $cuen_cod_cuen);
					$oReturn->assign("gasto_val2", "innerHTML", $cuen_nom_cuen);
					$oReturn->script('cargar_centroCostos4();');
				}
			} elseif ($tipo == 2) {
				$oReturn->assign("cta_gasto_dist", "value", $cuen_cod_cuen);
				$oReturn->assign("cta_gasto_ndist", "value", $cuen_nom_cuen);
			}

			$oReturn->script("genera_comprobante();");
		} else {
			$oReturn->script('ventana_cuenta_fisc(' . $op . ');');
		}
	} catch (Exception $e) {
		$oReturn->alert($e->getMessage());
	}

	return $oReturn;
}

function selectTret($aForm = '', $cod = '', $op = 0, $tipo = 0)
{
	//Definiciones
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	//VARIABLES
	$idempresa = $_SESSION['U_EMPRESA'];

	try {


		// -----------------------------------------------------------------------------------------------------------
		// Traemos la configuracion de los decimales
		// -----------------------------------------------------------------------------------------------------------
		$empr_cod_pais = $_SESSION['U_PAIS_COD'];

		// TIPO RETENCION => RETENCION O DETRACCION
		//$sql_tipo_rete = "select * from comercial.pais_etiq_imp p where p.pais_cod_pais = $empr_cod_pais and impuesto = 'TIPO_RETENCION' ";
		//$tipo_rete = consulta_string_func($sql_tipo_rete, 'etiqueta', $oIfx, '');

		// DECIMALES DETRACCION
		$sql_decimales_rete = "select * from comercial.pais_etiq_imp p where p.pais_cod_pais = $empr_cod_pais and impuesto = 'DECIMALES_RETENCION' ";
		$decimales_rete = consulta_string_func($sql_decimales_rete, 'etiqueta', $oIfx, 0);
		if (empty($decimales_rete)) {
			$decimales_rete = 2;
		}


		// -----------------------------------------------------------------------------------------------------------
		// FIN Traemos la configuracion de los decimales
		// -----------------------------------------------------------------------------------------------------------






		$sql = "select tret_porct, tret_cta_cre, tret_imp_fijo , tret_mont_min
				from saetret where
				tret_cod_empr = $idempresa and
				-- tret_ban_retf = 'IR'  and
				tret_ban_crdb = 'CR' and
				tret_cod = '$cod'";
		//$oReturn->alert($sql);
		if ($oIfx->Query($sql)) {
			if ($oIfx->NumFilas() > 0) {
				$tret_porct    = $oIfx->f('tret_porct');
				$tret_cta_cre  = $oIfx->f('tret_cta_cre');
				$tret_imp_fijo = $oIfx->f('tret_imp_fijo');
				$tret_mont_min = $oIfx->f('tret_mont_min');
			}
		}
		$oIfx->Free();

		if ($tipo == 0) {
			$tot_compra = $aForm['valor_grab12t'] + $aForm['valor_grab0t'];
			if ($op == 0) {
				if ($tot_compra <= $tret_mont_min) {
					//$oReturn->alert('menor');
					$base = $aForm['base_fue_b'];
					$resp = number_format(round(($base * $tret_porct) / 100, $decimales_rete), $decimales_rete + 1, '.', '');
				} else {
					//$oReturn->alert('mayor');
					if ($tret_imp_fijo > 0) {
						$base = abs($tot_compra - $tret_mont_min);
						/*$oReturn->alert($tret_imp_fijo);
						$oReturn->alert($base);
						$oReturn->alert($tret_porct);*/
						$resp = number_format(round(($base * $tret_porct) / 100, $decimales_rete) + $tret_imp_fijo, $decimales_rete + 1, '.', '');
					} else {
						$base = $aForm['base_fue_b'];
						$resp = number_format(round(($base * $tret_porct) / 100, $decimales_rete), $decimales_rete + 1, '.', '');
					}
				}



				$oReturn->assign("codigo_ret_fue_b", "value", $cod);
				$oReturn->assign("porc_ret_fue_b", "value", $tret_porct);
				$oReturn->assign("val_fue_b", "value", $resp);

				$oReturn->script("genera_comprobante();");
			} else {

				$base = $aForm['base_fue_s'];

				$resp = number_format(round(($base * $tret_porct) / 100, $decimales_rete), $decimales_rete + 1, '.', '');

				$oReturn->assign("codigo_ret_fue_s", "value", $cod);
				$oReturn->assign("porc_ret_fue_s", "value", $tret_porct);
				$oReturn->assign("val_fue_s", "value", $resp);

				$oReturn->script("genera_comprobante();");
			}
		} elseif ($tipo == 1) {
			if ($op == 0) {

				$base = $aForm['base_iva_b'];

				$resp = number_format(round(($base * $tret_porct) / 100, $decimales_rete), $decimales_rete + 1, '.', '');

				$oReturn->assign("codigo_ret_iva_b", "value", $cod);
				$oReturn->assign("porc_ret_iva_b", "value", $tret_porct);
				$oReturn->assign("val_iva_b", "value", $resp);

				$oReturn->script("genera_comprobante();");
			} else {

				$base = $aForm['base_iva_s'];

				$resp = number_format(round(($base * $tret_porct) / 100, $decimales_rete), 2, '.', '');

				$oReturn->assign("codigo_ret_iva_s", "value", $cod);
				$oReturn->assign("porc_ret_iva_s", "value", $tret_porct);
				$oReturn->assign("val_iva_s", "value", $resp);

				$oReturn->script("genera_comprobante();");
			}
		}
	} catch (Exception $e) {
		$oReturn->alert($e->getMessage());
	}

	return $oReturn;
}

// CUENTA BIENES
function cuenta_fisc($op, $cuen_nom, $aForm = '')
{
	//Definiciones
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	//      VARIABLES
	$idempresa      = $_SESSION['U_EMPRESA'];
	$idsucursal     = $aForm['sucursal'];

	if ($op == 0) {
		$cuen_nom = $aForm['fisc_b'];
	} elseif ($op == 1) {
		$cuen_nom = $aForm['fisc_s'];
	}

	if (empty($cuen_nom)) {
		$sql_tmp = '';
	} elseif (!empty($cuen_nom)) {
		$numero     = str_replace('.', '', $cuen_nom);
		$is_numeric = is_numeric($numero);
		if ($is_numeric == true) {
			$sql_tmp = " and cuen_cod_cuen = '$cuen_nom' ";
		} else {
			$sql_tmp = " and cuen_nom_cuen = UPPER('$cuen_nom%') ";
		}
	}

	$sql = "select cuen_cod_cuen, cuen_nom_cuen, cuen_nom_ingl from saecuen where
                cuen_cod_empr = $idempresa and
				cuen_mov_cuen = 1 
                $sql_tmp
                order by cuen_cod_cuen ";
	$oIfx->Query($sql);
	$cont = $oIfx->NumFilas();

	if ($cont == 1) {
		if ($oIfx->Query($sql)) {
			if ($oIfx->NumFilas() > 0) {
				$cod_cuen  = $oIfx->f('cuen_cod_cuen');
				$nom_cuen  = $oIfx->f('cuen_nom_cuen');
			}
		}

		if ($op == 0) {
			$oReturn->assign("fisc_b", "value",             $cod_cuen);
			$oReturn->assign("fisc_b_tmp", "innerHTML",     $nom_cuen);
		} else {
			$oReturn->assign("fisc_s", "value",             $cod_cuen);
			$oReturn->assign("fisc_s_tmp", "innerHTML",     $nom_cuen);
		}

		$oReturn->script("genera_comprobante();");
	} else {
		$oReturn->script('ventana_cuenta_fisc(' . $op . ');');
	}


	return $oReturn;
}

// CUENTA GASTO
function cuenta_gasto($op, $cuen_nom, $aForm = '')
{
	//Definiciones
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	//      VARIABLES
	$idempresa      = $_SESSION['U_EMPRESA'];
	$idsucursal     = $aForm['sucursal'];

	if ($op == 0) {
		$cuen_nom = $aForm['gasto1'];
	} elseif ($op == 1) {
		$cuen_nom = $aForm['gasto2'];
	}

	if (empty($cuen_nom)) {
		$sql_tmp = '';
	} elseif (!empty($cuen_nom)) {
		$numero     = str_replace('.', '', $cuen_nom);
		$is_numeric = is_numeric($numero);
		if ($is_numeric == true) {
			$sql_tmp = " and cuen_cod_cuen = '$cuen_nom' ";
		} else {
			$sql_tmp = " and cuen_nom_cuen = UPPER('$cuen_nom%') ";
		}
	}

	$sql = "select cuen_cod_cuen, cuen_nom_cuen, cuen_nom_ingl from saecuen where
                cuen_cod_empr = $idempresa and
				cuen_mov_cuen = '1'
                $sql_tmp
                order by cuen_cod_cuen ";
	$oIfx->Query($sql);
	$cont = $oIfx->NumFilas();

	if ($cont == 1) {
		if ($oIfx->Query($sql)) {
			if ($oIfx->NumFilas() > 0) {
				$cod_cuen  = $oIfx->f('cuen_cod_cuen');
				$nom_cuen  = $oIfx->f('cuen_nom_cuen');
			}
		}

		if ($op == 0) {
			$oReturn->assign("gasto1", "value",             $cod_cuen);
			$oReturn->assign("gasto_val", "innerHTML",     $nom_cuen);

			// CCOSN 3
			$oReturn->assign('gasto_val_centroCostos', 'innerHTML', '');
			$oReturn->assign("ccosn_3", "innerHTML", '');
		} else {
			$oReturn->assign("gasto2", "value",             $cod_cuen);
			$oReturn->assign("gasto_val2", "innerHTML",     $nom_cuen);

			// CCOSN 4
			$oReturn->assign('gasto_val2_centroCostos', 'innerHTML', '');
			$oReturn->assign("ccosn_4", "innerHTML", '');
		}

		$oReturn->script("genera_comprobante();");
	} else {
		$oReturn->script('ventana_cuenta_gasto(' . $op . ');');
	}


	return $oReturn;
}

function genera_documento($aForm = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$cliente = $aForm['cliente'];
	$factura = $aForm['factura'];
	$fecha_emision = $aForm['fecha_emision'];
	$asientoContable = $aForm['asto_cod'];
	$ejer_cod = $aForm['ejer_cod'];
	$prdo_cod = $aForm['prdo_cod'];
	$sucursal = $aForm['sucursal'];
	$claveAcceso = $aForm['claveAcceso'];
	$electronica = $aForm['electronica'];
	$id = 0;
	$electronica = 'S';
	if ($electronica == 'S') {
		//$oReturn->alert($id.'*'.$claveAcceso.'*'.$rutapdf.'*'.$cliente.'*'.$factura.'*'.$ejer_cod.'*'.$asientoContable.'*'.$fecha_emision.'*'.$sucursal);
		$_SESSION['pdf'] = reporte_retencionGasto($id, $claveAcceso, $rutapdf, $cliente,  $factura,  $ejer_cod,  $asientoContable,  $fecha_emision, $sucursal);

		$oReturn->script('generar_pdf()');
	} else {
		unset($_SESSION['pdf_rete']);

		//$array_fer=explode('/',$fecha);
		//$fecha=$array_fer[1].'/'.$array_fer[2].'/'.$array_fer[0];
		$_SESSION['pdf_rete'] = reporte_retencionGastoPre($id, $claveAcceso, $rutapdf, $cliente,  $factura,  $ejer_cod,  $asientoContable,  $fecha_emision, $sucursal);

		$oReturn->script('vista_previa_rete()');
	}

	return $oReturn;
}


// FFORMA DE PAGO
function forma_pago_form($aForm = '')
{

	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo();
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa  = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm['sucursal'];

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$ret_asumido    = $aForm['ret_asumido'];

	$ifu->AgregarCampoListaSQL('forma_pago_prove', 'Forma de Pago|LEFT', "select  fpag_cod_fpag, fpag_des_fpag  from saefpag where
																			fpag_cod_empr = $idempresa and
																			fpag_cod_modu = 10 and
																			fpag_cod_sucu = $idsucursal	", false, 'auto');

	$ifu->AgregarCampoNumerico('dia', 'Dias|left', true, 0, 40, 3);
	$ifu->AgregarCampoNumerico('cuota', 'Cuotas|left', true, 0, 100, 10);

	$totals         = $aForm['totals'];
	$val_fue_b      = $aForm['val_fue_b'];
	$val_fue_s      = $aForm['val_fue_s'];
	$val_iva_b      = ($aForm['val_iva_b'] != '') ? $aForm['val_iva_b'] : 0;
	$val_iva_s      = ($aForm['val_iva_s'] != '') ? $aForm['val_iva_s'] : 0;

	$val_fue_b = vacios($val_fue_b, 0);
	$val_fue_s = vacios($val_fue_s, 0);
	$val_iva_b = vacios($val_iva_b, 0);
	$val_iva_s = vacios($val_iva_s, 0);

	if ($ret_asumido == 'S') {
		// RETNCION ASUMIDA
		$cre_ml    			= $totals;
	} else {
		// RETENCION NOMRAL
		$cre_ml    = $totals - $val_fue_b - $val_fue_s - $val_iva_b - $val_iva_s;
	}

	$sHtml_fp = '';
	$sHtml_fp = mostrar_gridFp();

	$sHtml .= ' <table class="table table-striped table-condensed" style="width: 98%; margin-bottom: 0px;" align="center">';
	$sHtml .= '<tr height="20">';
	$sHtml .= '<td>' . $ifu->ObjetoHtmlLBL('forma_pago_prove') . '</td>';
	$sHtml .= '<td>' . $ifu->ObjetoHtml('forma_pago_prove') . '</td>';
	$sHtml .= '<td>' . $ifu->ObjetoHtmlLBL('dia') . '</td>';
	$sHtml .= '<td>' . $ifu->ObjetoHtml('dia') . '</td>';
	$sHtml .= '<td>' . $ifu->ObjetoHtmlLBL('cuota') . '</td>';
	$sHtml .= '<td>' . $ifu->ObjetoHtml('cuota') . '</td>';
	$sHtml .= '<td align="right" >
					<div class="btn btn-primary btn-sm" onClick="javascript:cargar_fp( )" >
						<span class="glyphicon glyphicon-cog"></span>
						Agregar
					</div>
			   </td>';
	$sHtml .= '</tr>';
	$sHtml .= '</table>';

	$modal  = '<div id="mostrarmodal" class="modal fade" role="dialog">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">FORMA DE PAGO</h4>
							<h4 class="modal-title">VALOR A CANCELAR ' . $cre_ml . '</h4>
                        </div>
                        <div class="modal-body">';
	$modal .= $sHtml;

	$sHtml .= '</div>';
	$modal .= '<div id="gridFp" style="margin-top: 20px;">' . $sHtml_fp . '</div>';
	$modal .= '          
                        <div class="modal-footer">
                            <button type="button" class="btn btn-danger" data-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
             </div>';

	//$oReturn->alert($sHtml);	

	$oReturn->assign("miModalFP", "innerHTML", $modal);
	$oReturn->script("abre_modal();");

	return $oReturn;
}

function agrega_modifica_gridFp($nTipo = 0,  $aForm = '', $id = '', $total_fact = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$oReturn = new xajaxResponse();

	$aDataGrid = $_SESSION['aDataGirdFp'];

	$aLabelGrid = array('Id', 'Fecha', 'Dias', 'Fecha Final', 'Forma Pago', 'Porcentaje', 'Valor', 'Eliminar');

	$ret_asumido    = $aForm['ret_asumido'];

	$totals         = $aForm['totals'];
	$val_fue_b      = $aForm['val_fue_b'];
	$val_fue_s      = $aForm['val_fue_s'];
	$val_iva_b      = ($aForm['val_iva_b'] != '') ? $aForm['val_iva_b'] : 0;
	$val_iva_s      = ($aForm['val_iva_s'] != '') ? $aForm['val_iva_s'] : 0;

	$val_fue_b 		= vacios($val_fue_b, 0);
	$val_fue_s 		= vacios($val_fue_s, 0);
	$val_iva_b 		= vacios($val_iva_b, 0);
	$val_iva_s 		= vacios($val_iva_s, 0);

	if ($ret_asumido == 'S') {
		// RETNCION ASUMIDA
		$total_fp 	= $totals;
	} else {
		// RETENCION NOMRAL
		$total_fp    = $totals - $val_fue_b - $val_fue_s - $val_iva_b - $val_iva_s;
	}

	// $total_fp 		= $totals - $val_fue_b - $val_fue_s - $val_iva_b - $val_iva_s;

	$fpag_cod 		= $aForm['forma_pago_prove'];
	$dia            = $aForm['dia'];
	$cuota          = $aForm['cuota'];
	$fecha_emis     = $aForm['fecha_emision'];

	$valor_cuota    = round(($total_fp / $cuota), 2);

	for ($i = 1; $i <= $cuota; $i++) {
		//GUARDA LOS DATOS DEL DETALLE
		$cont = count($aDataGrid);

		if ($i == 1) {
			$fecha_venc = sumar_dias_post($fecha_emis, $dia); //  Y/m/d
		} else {
			$fecha_emis = $fecha_venc;
			$fecha_venc = sumar_dias_post($fecha_emis, $dia); //  Y/m/d
		}

		$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
		$aDataGrid[$cont][$aLabelGrid[1]] = $fecha_emis;
		$aDataGrid[$cont][$aLabelGrid[2]] = $dia;
		$aDataGrid[$cont][$aLabelGrid[3]] = $fecha_venc;
		$aDataGrid[$cont][$aLabelGrid[4]] = $fpag_cod;
		$aDataGrid[$cont][$aLabelGrid[5]] = $porc;
		$aDataGrid[$cont][$aLabelGrid[6]] = $valor_cuota;
		$aDataGrid[$cont][$aLabelGrid[7]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
													title = "Presione aqui para Eliminar"
													style="cursor: hand !important; cursor: pointer !important;"
													onclick="javascript:xajax_elimina_detalleFp(' . $cont . ');"
													alt="Eliminar"
													align="bottom" />';
		$_SESSION['aDataGirdFp'] = $aDataGrid;
		$sHtml = mostrar_gridFp();
		$oReturn->assign("gridFp", "innerHTML", $sHtml);
	}

	return $oReturn;
}

function mostrar_gridFp()
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa = $_SESSION['U_EMPRESA'];
	$aDataGrid = $_SESSION['aDataGirdFp'];
	$aLabelGrid = array('Id', 'Fecha', 'Dias', 'Fecha Final', 'Forma Pago', 'Porcentaje', 'Valor', 'Eliminar');

	$cont = 0;
	$total     = 0;
	if (count($aDataGrid) > 0) {
		foreach ($aDataGrid as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 0) {
					$aDatos[$cont][$aLabelGrid[$aux]] = $cont + 1;
				} elseif ($aux == 1) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
				} elseif ($aux == 2) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
				} elseif ($aux == 3) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
				} elseif ($aux == 4) {
					// Fp
					$sql = "select fpag_des_fpag from saefpag where
								fpag_cod_empr = $idempresa and
								fpag_cod_fpag = '$aVal' ";
					$fpag_nom = consulta_string_func($sql, 'fpag_des_fpag', $oIfx, '');
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $fpag_nom . '</div>';
				} elseif ($aux == 7) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
															<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
															title = "Presione aqui para Eliminar"
															style="cursor: hand !important; cursor: pointer !important;"
															onclick="javascript:xajax_elimina_detalleFp(' . $cont . ');"
															alt="Eliminar"
															align="bottom" />
														</div>';
				} else
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				$aux++;
			}
			$cont++;
		}
	}
	return genera_grid($aDatos, $aLabelGrid, 'Forma Pago', 98, null, $array_tot);
}

function elimina_detalleFp($id = null)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$aLabelGrid = array('Id', 'Fecha', 'Dias', 'Fecha Final', 'Forma Pago', 'Porcentaje', 'Valor', 'Eliminar');
	$aDataGrid  = $_SESSION['aDataGirdFp'];
	$contador   = count($aDataGrid);
	if ($contador > 1) {
		unset($aDataGrid[$id]);
		$_SESSION['aDataGirdFp'] = $aDataGrid;
		$sHtml = mostrar_gridFp();
		$oReturn->assign("gridFp", "innerHTML", $sHtml);
	} else {
		unset($aDataGrid[0]);
		$_SESSION['aDataGirdFp'] = $aDatos;
		$sHtml = "";
		$oReturn->assign("gridFp", "innerHTML", $sHtml);
	}

	return $oReturn;
}


function genera_pdf_doc($idempresa, $idsucursal, $asto_cod, $ejer_cod, $prdo_cod)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfxA = new Dbo();
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();
	unset($_SESSION['pdf']);
	$oReturn = new xajaxResponse();

	$tipo     = $aForm['documento'];


	$sql = "select asto_cod_modu, asto_tipo_mov from saeasto where asto_cod_asto='$asto_cod'";
	$tipomov = consulta_string($sql, 'asto_tipo_mov', $oIfx, '');
	$codmodu = consulta_string($sql, 'asto_cod_modu', $oIfx, '');

	if ($tipomov == 'DI') {
		$sql = "select ftrn_ubi_web from saeftrn where ftrn_tip_movi='$tipomov' and ftrn_cod_modu=$codmodu and ftrn_ubi_web is not null";
		$ubi = consulta_string($sql, 'ftrn_ubi_web', $oIfx, '');
		if (empty($ubi)) {
			$ubi = 'Include/Formatos/comercial/diario.php';
		}
		include_once('../../' . $ubi . '');
		$diario = formato_diario($idempresa, $idsucursal, $asto_cod, $ejer_cod, $prdo_cod);
	} elseif ($tipomov == 'EG') {
		$sql = "select ftrn_ubi_web from saeftrn where ftrn_tip_movi='$tipomov' and ftrn_cod_modu=$codmodu and ftrn_ubi_web is not null";
		$ubi = consulta_string($sql, 'ftrn_ubi_web', $oIfx, '');
		if (empty($ubi)) {
			$ubi = 'Include/Formatos/comercial/egreso.php';
		}
		include_once('../../' . $ubi . '');
		$diario = formato_egreso($idempresa, $idsucursal, $asto_cod, $ejer_cod, $prdo_cod);
	} elseif ($tipomov == 'IN') {
		$sql = "select ftrn_ubi_web from saeftrn where ftrn_tip_movi='$tipomov' and ftrn_cod_modu=$codmodu and ftrn_ubi_web is not null";
		$ubi = consulta_string($sql, 'ftrn_ubi_web', $oIfx, '');
		if (empty($ubi)) {
			$ubi = 'Include/Formatos/comercial/ingreso.php';
		}
		include_once('../../' . $ubi . '');
		$diario = formato_ingreso($idempresa, $idsucursal, $asto_cod, $ejer_cod, $prdo_cod);
	}
	$_SESSION['pdf'] = $diario;

	$oReturn->script('generar_pdf()');
	return $oReturn;
}

function liqCompras($idempresa, $idsucursal, $asto_cod, $ejer_cod, $prdo_cod)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfxA = new Dbo();
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();
	unset($_SESSION['pdf']);
	$oReturn = new xajaxResponse();

	$tipo     = $aForm['documento'];
	//$oReturn->alert($tipo);

	$diario = generar_liqCompras_pdf($idempresa, $idsucursal, $asto_cod, $ejer_cod, $prdo_cod);
	$_SESSION['pdf'] = $diario;

	$oReturn->script('generar_pdf()');
	return $oReturn;
}


function validar_cedula_len($aForm = null)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$tipo = $aForm['iden_reemb'];
	$iden = $aForm['ruc_reemb'];

	/*$ifu->AgregarOpcionCampoLista('iden_reemb', 'RUC', '01');
	$ifu->AgregarOpcionCampoLista('iden_reemb', 'CEDULA', '02');
	$ifu->AgregarOpcionCampoLista('iden_reemb', 'PASAPORTE', '03');
		*/

	$len = strlen($iden);

	if ($tipo == '01') {
		// RUC
		if ($len > 13) {
			$oReturn->alert('Ruc solo debe tener 13 Digitos...');
			$oReturn->assign("ruc_reemb", "value", '');
		} elseif ($len < 13) {
			$oReturn->alert('Ruc solo debe tener 13 Digitos...');
			$oReturn->assign("ruc_reemb", "value", '');
		} elseif ($len == 13) {
			$oReturn->script("validar_cedula();");
		}
	} elseif ($tipo == '02') {
		// CEDULA
		if ($len > 10) {
			$oReturn->alert('Cedula solo debe tener 10 Digitos...');
			$oReturn->assign("ruc_reemb", "value", '');
		} elseif ($len < 10) {
			$oReturn->alert('Cedula solo debe tener 10 Digitos...');
			$oReturn->assign("ruc_reemb", "value", '');
		} elseif ($len == 10) {
			$oReturn->script("validar_cedula();");
		}
	} elseif ($tipo == '03') {
		// PASAPORTE
		$oReturn->script("validar_cedula();");
	}

	return $oReturn;
}


//TIPO DE FACTURA
function tipo_factura_reemb($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $_SESSION['U_SUCURSAL'];

	$tipo_factura = $aForm['tipo_factura_reemb'];
	$cliente 	  = $aForm['clpv_remb'];
	$clpvCompania = $aForm['clpvCompania'];

	if ($cliente != '') {

		if ($tipo_factura == 1) {
			//FACTURA ELECTRONICA			
			$ifu->AgregarCampoNumerico('clave_acceso', 'Clave|left', true, '', 400, 49);
			$ifu->AgregarComandoAlEscribir('clave_acceso', 'clave_acceso_buscar(' . $idempresa . ', event )');

			//selecciona ambiente de sucursal
			$sql = "select sucu_tip_ambi from saesucu where sucu_cod_empr = $idempresa and sucu_cod_sucu = $idsucursal";
			$sucu_tip_ambi = consulta_string_func($sql, 'sucu_tip_ambi', $oIfx, 2);

			if ($sucu_tip_ambi == 1) {
				$op = 'N';
			} else {
				$op = 'S';
			}

			$ifu->AgregarCampoSi_No('ambiente_sri', '|left', $op);

			$sHtml .=	'<tr>
                            <td colspan="4">
                                <table 

                            Ambiente de Produccion: ' . $ifu->ObjetoHtml('ambiente_sri') . ' &nbsp;&nbsp; <a href="#" onClick="javascript:redireccionar()" style="color: blue;">Web SRI</a></td>
						</tr>
						<tr>
							<td colspan="4">' . $ifu->ObjetoHtml('clave_acceso') . '
								<div class="btn btn-success btn-sm" onclick="clave_acceso_sri_reemb();">
									<span class="glyphicon glyphicon-retweet"></span>
									Genera
								</div>
							</td>
						</tr>';

			$oReturn->assign('divFactura_reemb', 'innerHTML', $sHtml);
			$oReturn->assign('clave_acceso', 'focus()', '');
		} elseif ($tipo_factura == 2) {

			if (!empty($clpvCompania)) {
				$cliente = $clpvCompania;
			}

			$oReturn->assign('divFactura_reemb', 'innerHTML', '');
			$oReturn->assign('serie', 'focus()', '');
		} elseif ($tipo_factura == '') {
			$oReturn->assign('divFactura_reemb', 'innerHTML', '');
		}
	}


	return $oReturn;
}


function clave_acceso_reemb($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oIfxA = new Dbo;
	$oIfxA->DSN = $DSN_Ifx;
	$oIfxA->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$idempresa 	= $_SESSION['U_EMPRESA'];
	$idsucursal = $_SESSION['U_SUCURSAL'];

	//variables formulario
	$clave_acceso	= $aForm['clave_acceso'];
	$pos          	= $aForm['clave_acceso'];
	$ambiente_sri 	= $aForm['ambiente_sri'];
	$ruc			= $aForm['ruc_reemb'];

	$sql = "select empr_ruc_empr from saeempr where empr_cod_empr = $idempresa ";
	//$ruc =consulta_string_func($sql, 'empr_ruc_empr', $oIfx, 0);


	try {
		$headers = array(
			"Content-Type:application/json",
			"Token-Api:9c0ab4af-30dc-4b85-93e2-f0cd28dd7e51"
		);
		$data = array(
			"clave_acceso" => $clave_acceso
		);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_URL, URL_JIREH_WS . "/api/facturacion/electronica/autorizacion/comprobante");
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$respuesta = curl_exec($ch);
		$autoComp[$pos] = (object) json_decode($respuesta, true);
		$data = $autoComp[$pos];
		// $autorizado = $autoComp[$pos]->estado;


		// var_dump($autoComp[$pos]);
		// exit;
		// Adrian47



		//$clientOptions = array(
		//"useMTOM" => FALSE,
		//'trace' => 1,
		//'stream_context' => stream_context_create(array('http' => array('protocol_version' => 1.0) ) )
		//);   

		//if($ambiente_sri == 'S'){
		//	$wsdlAutoComp[$pos] = new  SoapClient("https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl", $clientOptions);
		//}else{
		//	$wsdlAutoComp[$pos] = new  SoapClient("https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl", $clientOptions);
		//}     

		////RECUPERA LA AUTORIZACION DEL COMPROBANTE
		//$aClave = array("claveAccesoComprobante" => $clave_acceso);

		//$autoComp[$pos] = new stdClass();
		//$autoComp[$pos] = $wsdlAutoComp[$pos]->autorizacionComprobante($aClave);

		$RespuestaAutorizacionComprobante[$pos]	= $data;
		$claveAccesoConsultada[$pos] 			= $RespuestaAutorizacionComprobante[$pos]->claveAccesoConsultada;
		// $autorizaciones[$pos] 					= $RespuestaAutorizacionComprobante[$pos]->autorizaciones;
		// $autorizacion[$pos]						= $autorizaciones[$pos]->autorizacion; 
		$autorizacion[$pos]						= 1;

		// var_dump($autorizaciones[$pos]);
		// exit;

		if (count($autorizacion[$pos]) > 1) {
			$estado[$pos] 				= $autorizacion[$pos][0]->estado;
			$numeroAutorizacion[$pos] 	= $autorizacion[$pos][0]->numeroAutorizacion;
			$fechaAutorizacion[$pos] 	= $autorizacion[$pos][0]->fechaAutorizacion;
			$ambiente[$pos] 			= $autorizacion[$pos][0]->ambiente;
			$comprobante[$pos] 			= $autorizacion[$pos][0]->comprobante;
			$mensajes[$pos] 			= $autorizacion[$pos][0]->mensajes;
			$mensaje[$pos] 				= $mensajes[$pos]->mensaje;
		} else {
			$estado[$pos] 				= $RespuestaAutorizacionComprobante[$pos]->estado;
			$numeroAutorizacion[$pos] 	= $RespuestaAutorizacionComprobante[$pos]->numeroAutorizacion;
			$fechaAutorizacion[$pos] 	= $RespuestaAutorizacionComprobante[$pos]->fechaAutorizacion;
			$ambiente[$pos] 			= $RespuestaAutorizacionComprobante[$pos]->ambiente;
			$comprobante[$pos] 			= $RespuestaAutorizacionComprobante[$pos]->comprobante;
			$mensajes[$pos] 			= $RespuestaAutorizacionComprobante[$pos]->mensajes;
			$mensaje[$pos] 				= $mensajes[$pos]->mensaje;
		}

		$xml	=	'';

		$xml 	.=	"$comprobante[$pos]";
		//$xml 	.=	'</autorizacion>';


		// var_dump($RespuestaAutorizacionComprobante[$pos]);
		// exit;


		if ($estado[$pos] == 'AUTORIZADO') {

			//$oReturn->alert("COMPROBANTE: $estado[$pos] $clave_acceso");

			// GUARDAR EN XML
			// CREAR CARPETA ANEXO
			$serv = "/Jireh";
			$ruta = $serv . "/Doc Electronicos Web SRI";
			$ruta1 = "Doc_Electronicos";
			// CARPETA EMPRESA
			$ruta_empr = $ruta1 . "/" . $idempresa;
			if (!file_exists($ruta1)) {
				mkdir($ruta1);
			}

			if (!file_exists($ruta_empr)) {
				mkdir($ruta_empr);
			}

			// ruta del xml
			$nombre = $clave_acceso . ".xml";
			$archivo = fopen($nombre, "w+");
			fwrite($archivo, $xml);
			fclose($archivo);

			// ruta del xml
			$archivo_xml = fopen($ruta_empr . '/' . $nombre, "w+");
			$ruta_xml    = $ruta_empr . '/' . $nombre;
			fwrite($archivo_xml, $xml);
			fclose($archivo_xml);

			/*$dia = substr($claveAccesoConsultada[$pos], 0, 2);
            $mes = substr($claveAccesoConsultada[$pos], 2, 2);
            $an = substr($claveAccesoConsultada[$pos], 4, 4);*/

			$xmlParse 			= simplexml_load_file($ruta_xml);
			/*$autorizacion 	= $xmlParse->autorizacion;
			$estado 			= $xmlParse->estado;
			$comprobante 		= $xmlParse->comprobante;
			$numeroAutorizacion = $xmlParse->numeroAutorizacion;*/

			//$ruc = $xmlParse->comprobante->factura->infoTributaria;

			//foreach ($xmlParse->comprobante as $comprobante) {
			//$factura 		= $comprobante->factura;
			//$infoTributaria = $factura->infoTributaria;
			//$razonSocial 	= $infoTributaria->razonSocial;

			//$oReturn->alert('b'.$razonSocial);
			//}

			$estab 			= $xmlParse->infoTributaria->estab;
			$ptoEmi 		= $xmlParse->infoTributaria->ptoEmi;
			$secuencial 	= $xmlParse->infoTributaria->secuencial;
			// $identificacionComprador = $xmlParse->infoFactura->identificacionComprador;
			$identificacionComprador = $xmlParse->infoTributaria->ruc;
			$totalImpuesto 	= $xmlParse->infoFactura->totalConImpuestos->totalImpuesto;



			$totalbien = 0;
			$totalserv = 0;
			foreach ($totalImpuesto as $bases) {
				$codigoPorcentaje	= $bases->codigoPorcentaje;
				$baseImponible 		= $bases->baseImponible;
				$valor 				= $bases->valor;

				if ($codigoPorcentaje == 2) {
					$valor_grab12b = $baseImponible . '';
					$totalbien = $totalbien + $valor_grab12b;
				} elseif ($codigoPorcentaje == 0) {
					$valor_grab0s = $baseImponible . '';
					$totalserv = $totalserv + $valor_grab0s;
				}
			}


			$serie = $estab . $ptoEmi;
			$secuencial = $secuencial . '';

			if ($ruc == $identificacionComprador . '') {
				$oReturn->alert('Validacion ejecutada correctamente...');
				$oReturn->assign('auto_reemb',  'value',  $numeroAutorizacion[$pos]);
				$oReturn->assign('serie_reemb', 'value',  substr($serie, 3, 3));
				$oReturn->assign('estab_reemb', 'value',  substr($serie, 0, 3));
				$oReturn->assign('factura_reemb', 'value', $secuencial);
				/*$oReturn->assign('valor_grab12b', 'value', $valor_grab12b);
				$oReturn->assign('valor_grab0s', 'value', $valor_grab0s);
				$oReturn->assign('valor_grab12t', 'value', $totalbien);
				$oReturn->assign('valor_grab0t', 'value', $totalserv);
				$oReturn->script('totales(this)');
				$oReturn->script('totales1(this)');*/
			} else {
				$oReturn->alert('El numero de identificacion del Proveedor: ' . $ruc . ' no coincide con la identificacion del archivo xml: ' . $identificacionComprador);
			}

			//$oReturn->alert($ruc);

			/*foreach ($xmlParse->comprobante as $val){
				$oReturn->alert($val);
			}*/
			//$oReturn->alert($ruta_xml);
			//$oReturn->alert($ruc);


		} else {
			$informacionAdicional = (strtoupper($mensaje[$clave_acceso]->informacionAdicional));
			$informacionAdicional = preg_replace('([^A-Za-z0-9 ])', '', strtoupper($mensaje[$clave_acceso][0]->informacionAdicional));
			$informacionAdicional = htmlspecialchars_decode($informacionAdicional);
			$oReturn->alert('Error...' . $informacionAdicional);
		}
	} catch (SoapFault $e) {
		$oReturn->alert($pos . ' NO HUBO CONECCION AL SRI (AUTORIZAR)');
	}

	return $oReturn;
}


// DISTRIBUCION CENTRO DE COSTOS PORCENTAJE
function distribucion_ccosn($aForm = '', $total_fact = '')
{
	//Definiciones
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$array   = $_SESSION['U_ARRAY_CCOSN'];
	// D E T A L L E     D E S C R I P C I O N
	$aDataGrid = $_SESSION['aDataGirdDist'];
	$total_distribucion_m = $aForm['total_distribucion_m'];
	if (count($array) > 0) {
		$tot_porc = 0;
		$tot_val  = 0;
		foreach ($array as $val) {
			$ccosn_cod_ccosn  = $val[0];
			$ccosn_nom_ccosn  = $val[1];
			$porc_ccosn		  = $aForm[$ccosn_cod_ccosn . '_porc'];
			if ($porc_ccosn >= 0) {
				$subt = 0;
				$subt = round(($porc_ccosn * $total_fact) / 100, 2);

				$tot_porc += $porc_ccosn;
				$tot_val  += $subt;

				if ($tot_porc > 100) {
					$oReturn->alert('Sobrepaso el 100 %, Por favor revisar....');
					$oReturn->assign($ccosn_cod_ccosn . '_val', "value", 0);
					$oReturn->assign($ccosn_cod_ccosn . '_porc', "value", 0);

					$tot_porc -= $porc_ccosn;
					$tot_val  -= $subt;
				} else {
					$oReturn->assign($ccosn_cod_ccosn . '_val', "value", $subt);
				}
			}
		}
	}


	$oReturn->assign("total_ccosnp", "innerHTML", $tot_porc);
	$oReturn->assign("total_ccosn",  "innerHTML", $tot_val);
	// SALDO
	$saldo = 0;
	$saldo = round($aForm['val_cta'] - $tot_val, 2);
	$Porcentaje = round(($saldo * 100) / $total_distribucion_m, 2);
	$oReturn->assign("saldo_ccosn",  "innerHTML", $saldo);
	$oReturn->assign("porcen_ccosn",  "innerHTML", $Porcentaje);

	return $oReturn;
}



// DISTRIBUCION CENTRO DE COSTOS VALOR
function distribucion_ccosnV($aForm = '', $total_fact = '')
{
	//Definiciones
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$array   = $_SESSION['U_ARRAY_CCOSN'];
	// D E T A L L E     D E S C R I P C I O N
	$aDataGrid = $_SESSION['aDataGirdDist'];

	if (count($array) > 0) {
		$tot_porc = 0;
		$tot_val  = 0;
		foreach ($array as $val) {
			$ccosn_cod_ccosn  = $val[0];
			$ccosn_nom_ccosn  = $val[1];
			$porc_ccosn		  = $aForm[$ccosn_cod_ccosn . '_porc'];
			$val_ccosn		  = $aForm[$ccosn_cod_ccosn . '_val'];
			if ($val_ccosn >= 0) {
				$subt = 0;
				//$subt = round(($porc_ccosn*$total_fact)/100,2);
				$porc_ccosn = round(($val_ccosn * 100) / $total_fact, 2);

				$tot_porc += $porc_ccosn;
				$tot_val  += $val_ccosn;

				if ($tot_porc > 100) {
					$oReturn->alert('Sobrepaso el 100 %, Por favor revisar....');
					$oReturn->assign($ccosn_cod_ccosn . '_val', "value", 0);
					$oReturn->assign($ccosn_cod_ccosn . '_porc', "value", 0);

					$tot_porc -= $porc_ccosn;
					$tot_val  -= $val_ccosn;
				} else {
					$oReturn->assign($ccosn_cod_ccosn . '_porc', "value", $val_ccosn);
				}
			}
		}
	}


	$oReturn->assign("total_ccosnp", "innerHTML", $tot_porc);
	$oReturn->assign("total_ccosn",  "innerHTML", $tot_val);

	// SALDO
	$saldo = 0;
	$saldo = $aForm['val_cta'] - $tot_val;
	$oReturn->assign("saldo_ccosn",  "innerHTML", $saldo);
	//$oReturn->alert($saldo);
	return $oReturn;
}



/***** DIRECTORIO - RETENCION - DIARIO */
// DIRECTRIO SIN FACTURA
function agrega_modifica_grid_dir_ori($nTipo = 0, $aForm, $id = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	unset($_SESSION['aDataGirdDir']);
	unset($_SESSION['aDataGirdDiar']);

	$aDataGrid_Dir = $_SESSION['aDataGirdDir'];
	$aDataDiar = $_SESSION['aDataGirdDiar'];

	$aLabelGrid = array(
		'Fila',
		'Cliente',
		'Subcliente',
		'Tipo',
		'Factura',
		'Fec. Vence',
		'Detalle',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'DI'
	);

	$aLabelDiar = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad',
		'DIR',
		'RET'
	);

	$oReturn = new xajaxResponse();

	// VARIABLES
	$tran_cod   = $aForm["tran"];
	$detalle    = $aForm["det_dir"];
	$doc        = $aForm["documento"];
	$idsucursal = $aForm["sucursal"];
	$ccosn_cod  = $aForm["ccosn"];
	$act_cod    = $aForm["actividad"];
	$ccli_cod   = $aForm["ccli"];
	$idempresa  = $_SESSION['U_EMPRESA'];
	$mone_cod   = $aForm["moneda"];
	$serie_ad   = $aForm["serie"];
	$factura_ad   = trim($aForm["factura"]);
	$serie_factura_ad = $serie_ad . '-' . $factura_ad;
	$doc = $serie_factura_ad;
	$aDataGirdFp = $_SESSION['aDataGirdFp'];


	$sql = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
	$mone_base = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

	if ($mone_cod == $mone_base) {
		$sql = "select pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
		$mone_extr = consulta_string_func($sql, 'pcon_seg_mone', $oIfx, '');

		$sql = "select tcam_valc_tcam from saetcam where
				mone_cod_empr = $idempresa and
				tcam_cod_mone = $mone_extr and
				tcam_fec_tcam in (select max(tcam_fec_tcam)  from saetcam where
											mone_cod_empr = $idempresa and
											tcam_cod_mone = $mone_extr)";

		$coti = $aForm['cotizacion_ext']; //consulta_string_func($sql, 'tcam_val_tcam', $oIfx, 0);

	} else {
		$coti = $aForm['cotizacion'];
	}

	//$oReturn->alert($coti);
	$totals    = $aForm['totals'];
	$val_fue_b = $aForm['val_fue_b'];
	$val_fue_s = $aForm['val_fue_s'];
	$val_iva_b = ($aForm['val_iva_b'] != '') ? $aForm['val_iva_b'] : 0;
	$val_iva_s = ($aForm['val_iva_s'] != '') ? $aForm['val_iva_s'] : 0;

	$val_fue_b = vacios($val_fue_b, 0);
	$val_fue_s = vacios($val_fue_s, 0);
	$val_iva_b = vacios($val_iva_b, 0);
	$val_iva_s = vacios($val_iva_s, 0);
	$ret_asumido = $aForm['ret_asumido'];

	$valor_ret_asumid = 0;
	if ($ret_asumido == 'S') {
		// RETNCION ASUMIDA
		$cre_ml    			= $totals;
		$valor_ret_asumid 	= $val_fue_b + $val_fue_s + $val_iva_b + $val_iva_s;
	} else {
		// RETENCION NOMRAL
		$cre_ml    = $totals - $val_fue_b - $val_fue_s - $val_iva_b - $val_iva_s;
	}

	if ($nTipo == 0) {
		$cont = 0;
		$contd = 0;


		// ---------------------------------------------------------------------------------------------------------------
		// Factura a afectar
		// ---------------------------------------------------------------------------------------------------------------

		$factura_afectar   = trim($aForm["factura_afectar"]);
		$comprobante   = $aForm["comprobante"];


		if ($comprobante == 'NDB') {
			if (!empty($factura_afectar)) {
				$fact_num = $factura_afectar;
				$fact_num_temp_ad = strtoupper($aForm['serie'] . '-' . $aForm['factura'] . '-000');
			} else {
				$fact_num = strtoupper($aForm['serie'] . '-' . $aForm['factura'] . '-000');
				$fact_num_temp_ad = $fact_num;
			}
		} else {
			$fact_num  = strtoupper($aForm['serie'] . '-' . $aForm['factura'] . '-000');
			$fact_num_temp_ad = $fact_num;
		}

		// ---------------------------------------------------------------------------------------------------------------
		// FIN Factura a afectar
		// ---------------------------------------------------------------------------------------------------------------


		//$fact_num  = strtoupper($aForm['serie'] . '-' . $aForm['factura'] . '-000');
		$fec_emis  = $aForm['fecha_emision'];
		$fec_venc  = $aForm['fecha_vence'];
		$clpv_cod  = $aForm['cliente'];
		$tran_cod  = $aForm['comprobante'];
		$det_dir   = $aForm['detalle'];

		$sql = "select tran_cod_cuen from saetran where 
								  tran_cod_tran = '$tran_cod' and 
								  tran_cod_modu = 4 and
								  tran_cod_sucu = $idsucursal and
								  tran_cod_empr = $idempresa and
								( tran_ant_tran = 1 or 
								  tran_cie_tran = 1   
								) ";
		$clpv_cuen = consulta_string_func($sql, 'tran_cod_cuen', $oIfx, '');
		//$oReturn->alert($clpv_cuen);

		// CUENTAS DE CLPV
		$sql       = "select clpv_cod_cuen, grpv_cod_grpv, clpv_clopv_clpv from saeclpv where clpv_cod_empr = $idempresa and clpv_cod_clpv = $clpv_cod ";
		$tipo      = consulta_string_func($sql, 'clpv_clopv_clpv', $oIfx, '');

		if (empty($clpv_cuen)) {
			$clpv_cuen = consulta_string_func($sql, 'clpv_cod_cuen', $oIfx, '');
			if (empty($clpv_cuen)) {
				$clpv_gr = consulta_string_func($sql, 'grpv_cod_grpv', $oIfx, '');
				$sql = "select  grpv_cta_grpv  from saegrpv where
										grpv_cod_empr = $idempresa and
										grpv_cod_grpv = '$clpv_gr' ";
				$clpv_cuen = consulta_string_func($sql, 'grpv_cta_grpv', $oIfx, '');
			}
		}

		// NOMBRE CUENtA
		$sql = "select cuen_nom_cuen from saecuen where 
			                    cuen_cod_empr = $idempresa and
			                    cuen_cod_cuen = '$clpv_cuen' ";
		$cuen_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		//$tipo  	   = $val[12];
		$ccli  	   = $aForm['ccli'];
		$txt       = abs(str_replace(",", "", $cre_ml));

		$valDirCre = 0;
		$valDiaCre = 0;
		$valDirDeb = 0;
		$valDiaDeb = 0;

		if ($tipo == 'CL') {
			$modulo = 3;
		} elseif ($tipo == 'PV') {
			$modulo = 4;
		}

		//tipo de transaccion
		$sql = "select trans_tip_tran from saetran where tran_cod_tran = '$tran_cod' and tran_cod_modu = $modulo and tran_cod_empr = $idempresa ";
		//echo $sql;exit;
		$trans_tip_tran = consulta_string_func($sql, 'trans_tip_tran', $oIfx, '');

		if (count($aDataGirdFp) > 0) {
			// CON FORMA DE Pago
			foreach ($aDataGirdFp as $aValues) {
				$aux = 0;
				foreach ($aValues as $aVal) {
					if ($aux == 0) {
						$tmp_fp = $aVal + 1;
					} elseif ($aux == 1) {
						$fecha_emis_fp = $aVal;
					} elseif ($aux == 2) {
						$dias_fp  = $aVal;
					} elseif ($aux == 3) {
						$fecha_venc_fp = $aVal;
					} elseif ($aux == 6) {
						$valor_fp = $aVal;
						$fact_tmp = $aForm['serie'] . '-' . $aForm['factura'] . '-00' . $tmp_fp;
						$txt       = abs(str_replace(",", "", $valor_fp));

						if ($tmp_fp == 1) {
							$oReturn->assign("factura_dir", "value", $fact_tmp);
						}

						if ($trans_tip_tran == 'DB') {
							$valDirDeb = $txt;
							$valDiaDeb = $txt;
						} elseif ($trans_tip_tran == 'CR') {
							$valDirCre = $txt;
							$valDiaCre = $txt;
						}

						// DIRECTORIO
						$cont = count($aDataGrid_Dir);
						$aDataGrid_Dir[$cont][$aLabelGrid[0]] = floatval($cont);
						$aDataGrid_Dir[$cont][$aLabelGrid[1]] = $clpv_cod;
						$aDataGrid_Dir[$cont][$aLabelGrid[2]] = $ccli_cod;
						$aDataGrid_Dir[$cont][$aLabelGrid[3]] = $tran_cod;
						$aDataGrid_Dir[$cont][$aLabelGrid[4]] = $fact_tmp;
						$aDataGrid_Dir[$cont][$aLabelGrid[5]] = $fecha_venc_fp;
						$aDataGrid_Dir[$cont][$aLabelGrid[6]] = $det_dir . ' ' . $fact_tmp;
						$aDataGrid_Dir[$cont][$aLabelGrid[7]] = $coti;

						if ($mone_cod == $mone_base) {

							// moneda local
							$cre_tmp = 0;
							$deb_tmp = 0;
							if ($coti > 0) {
								$cre_tmp = round(($valDirCre / $coti), 2);
							}

							if ($coti > 0) {
								$deb_tmp = round(($valDirDeb / $coti), 2);
							}

							$aDataGrid_Dir[$cont][$aLabelGrid[8]] = $valDirDeb;
							$aDataGrid_Dir[$cont][$aLabelGrid[9]] = $valDirCre;
							$aDataGrid_Dir[$cont][$aLabelGrid[10]] = $deb_tmp;
							$aDataGrid_Dir[$cont][$aLabelGrid[11]] = $cre_tmp;
						} else {
							// moneda extra

							$aDataGrid_Dir[$cont][$aLabelGrid[8]] = $valDirDeb * $coti;
							$aDataGrid_Dir[$cont][$aLabelGrid[9]] = $valDirCre * $coti;

							$aDataGrid_Dir[$cont][$aLabelGrid[10]] = $valDirDeb;
							$aDataGrid_Dir[$cont][$aLabelGrid[11]] = $valDirCre;
						}

						$aDataGrid_Dir[$cont][$aLabelGrid[12]] = '';

						$contd = count($aDataDiar);
						$aDataGrid_Dir[$cont][$aLabelGrid[13]] = '<div align="center">
																				<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																				title = "Presione aqui para Eliminar"
																				style="cursor: hand !important; cursor: pointer !important;"
																				onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 1);"
																				alt="Eliminar"
																				align="bottom" />
																			</div>';


						$aDataGrid_Dir[$cont][$aLabelGrid[14]] = $contd;

						// DIARIO					
						$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
						$aDataDiar[$contd][$aLabelDiar[1]] = $clpv_cuen;
						$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
						$aDataDiar[$contd][$aLabelDiar[3]] = $doc;
						$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

						if ($mone_cod == $mone_base) {
							// moneda local
							$cre_tmp = 0;
							$deb_tmp = 0;
							if ($coti > 0) {
								$cre_tmp = round(($valDirCre / $coti), 2);
							}

							if ($coti > 0) {
								$deb_tmp = round(($valDirDeb / $coti), 2);
							}

							$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb;
							$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre;
							$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
							$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
						} else {
							// moneda extra
							$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb * $coti;
							$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre * $coti;
							$aDataDiar[$contd][$aLabelDiar[7]] = $valDirDeb;
							$aDataDiar[$contd][$aLabelDiar[8]] = $valDirCre;
						}

						$aDataDiar[$contd][$aLabelDiar[9]] = '';
						$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																				title = "Presione aqui para Eliminar"
																				style="cursor: hand !important; cursor: pointer !important;"
																				onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
																				alt="Eliminar"
																				align="bottom" />';
						$aDataDiar[$contd][$aLabelDiar[11]] = '';
						$aDataDiar[$contd][$aLabelDiar[12]] = '';
						$aDataDiar[$contd][$aLabelDiar[13]] = '';
						$aDataDiar[$contd][$aLabelDiar[14]] = '';
						$aDataDiar[$contd][$aLabelDiar[15]] = '';
						$aDataDiar[$contd][$aLabelDiar[16]] = '';
						$aDataDiar[$contd][$aLabelDiar[17]] = $det_dir . ' ' . $fact_tmp;
						$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_cod;
						$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
						$aDataDiar[$contd][$aLabelDiar[20]] = $cont;
					}
					$aux++;
				} //fin foreach
			} //fin foreach

		} else {

			$oReturn->assign("factura_dir", "value", $fact_num_temp_ad);
			//echo $trans_tip_tran;exit;
			// SIN FORMA DE Pago
			if ($trans_tip_tran == 'DB') {
				$valDirDeb = $txt;
				$valDiaDeb = $txt;
			} elseif ($trans_tip_tran == 'CR') {
				$valDirCre = $txt;
				$valDiaCre = $txt;
			}

			// DIRECTORIO
			$cont = count($aDataGrid_Dir);
			$aDataGrid_Dir[$cont][$aLabelGrid[0]] = floatval($cont);
			$aDataGrid_Dir[$cont][$aLabelGrid[1]] = $clpv_cod;
			$aDataGrid_Dir[$cont][$aLabelGrid[2]] = $ccli_cod;
			$aDataGrid_Dir[$cont][$aLabelGrid[3]] = $tran_cod;
			$aDataGrid_Dir[$cont][$aLabelGrid[4]] = $fact_num;
			$aDataGrid_Dir[$cont][$aLabelGrid[5]] = $fec_venc;
			$aDataGrid_Dir[$cont][$aLabelGrid[6]] = $det_dir . ' ' . $fact_tmp;
			$aDataGrid_Dir[$cont][$aLabelGrid[7]] = $coti;

			if ($mone_cod == $mone_base) {
				// moneda local
				//echo '1';exit;
				$cre_tmp = 0;
				$deb_tmp = 0;
				if ($coti > 0) {
					$cre_tmp = round(($valDirCre / $coti), 2);
				}

				if ($coti > 0) {
					$deb_tmp = round(($valDirDeb / $coti), 2);
				}

				$aDataGrid_Dir[$cont][$aLabelGrid[8]] = $valDirDeb;
				$aDataGrid_Dir[$cont][$aLabelGrid[9]] = $valDirCre;
				$aDataGrid_Dir[$cont][$aLabelGrid[10]] = $deb_tmp;
				$aDataGrid_Dir[$cont][$aLabelGrid[11]] = $cre_tmp;
			} else {
				// moneda extra

				$aDataGrid_Dir[$cont][$aLabelGrid[8]] = $valDirDeb * $coti;
				$aDataGrid_Dir[$cont][$aLabelGrid[9]] = $valDirCre * $coti;

				$aDataGrid_Dir[$cont][$aLabelGrid[10]] = $valDirDeb;
				$aDataGrid_Dir[$cont][$aLabelGrid[11]] = $valDirCre;
			}

			$aDataGrid_Dir[$cont][$aLabelGrid[12]] = '';

			$contd = count($aDataDiar);
			$aDataGrid_Dir[$cont][$aLabelGrid[13]] = '<div align="center">
																	<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																	title = "Presione aqui para Eliminar"
																	style="cursor: hand !important; cursor: pointer !important;"
																	onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
																	alt="Eliminar"
																	align="bottom" />
																</div>';


			$aDataGrid_Dir[$cont][$aLabelGrid[14]] = $contd;

			// DIARIO					
			$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
			$aDataDiar[$contd][$aLabelDiar[1]] = $clpv_cuen;
			$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
			$aDataDiar[$contd][$aLabelDiar[3]] = $doc;
			$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

			if ($mone_cod == $mone_base) {
				// moneda local
				$cre_tmp = 0;
				$deb_tmp = 0;
				if ($coti > 0) {
					$cre_tmp = round(($valDirCre / $coti), 2);
				}

				if ($coti > 0) {
					$deb_tmp = round(($valDirDeb / $coti), 2);
				}

				$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb;
				$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre;
				$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
				$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
			} else {
				// moneda extra
				$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb * $coti;
				$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre * $coti;
				$aDataDiar[$contd][$aLabelDiar[7]] = $valDirDeb;
				$aDataDiar[$contd][$aLabelDiar[8]] = $valDirCre;
			}

			$aDataDiar[$contd][$aLabelDiar[9]] = '';
			$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																	title = "Presione aqui para Eliminar"
																	style="cursor: hand !important; cursor: pointer !important;"
																	onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
																	alt="Eliminar"
																	align="bottom" />';
			$aDataDiar[$contd][$aLabelDiar[11]] = '';
			$aDataDiar[$contd][$aLabelDiar[12]] = '';
			$aDataDiar[$contd][$aLabelDiar[13]] = '';
			$aDataDiar[$contd][$aLabelDiar[14]] = '';
			$aDataDiar[$contd][$aLabelDiar[15]] = '';
			$aDataDiar[$contd][$aLabelDiar[16]] = '';
			$aDataDiar[$contd][$aLabelDiar[17]] = $det_dir . ' ' . $fact_tmp;
			$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_cod;
			$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
			$aDataDiar[$contd][$aLabelDiar[20]] = $cont;
		}
	}



	/*********************************************************************************/
	// R E T E N C I O N
	/*********************************************************************************/
	unset($_SESSION['aDataGirdRet']);
	$aDataGrid = $_SESSION['aDataGirdRet'];
	$aLabelGrid = array(
		'Fila',
		'Cta Ret',
		'Cliente',
		'Factura',
		'Ret Cliente',
		'Porc(%)',
		'Base Impo',
		'Valor',
		'N.- Retencion',
		'Detalle',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'DI'
	);
	// VARIABLES
	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm["sucursal"];
	$mone_cod  = $aForm["moneda"];
	$clpv_cod  = $aForm['cliente'];
	$factura   = $aForm['factura'];
	$retencion = $aForm['num_rete'];
	$det_ret   = $aForm['detalle'];

	// DATOS RETENCION PROVEEDOR
	// BIENES
	$codigo_ret_fue_b   = $aForm['codigo_ret_fue_b'];
	$porc_ret_fue_b     = $aForm['porc_ret_fue_b'];
	$base_fue_b         = $aForm['base_fue_b'];
	$val_fue_b          = $aForm['val_fue_b'];

	// SERVICIOS
	$codigo_ret_fue_s   = $aForm['codigo_ret_fue_s'];
	$porc_ret_fue_s     = $aForm['porc_ret_fue_s'];
	$base_fue_s         = $aForm['base_fue_s'];
	$val_fue_s          = $aForm['val_fue_s'];

	//RETENCION IVA BIENES
	$codigo_ret_iva_b   = $aForm['codigo_ret_iva_b'];
	$porc_ret_iva_b     = $aForm['porc_ret_iva_b'];
	$base_iva_b         = $aForm['base_iva_b'];
	$val_iva_b          = ($aForm['val_iva_b'] != '') ? $aForm['val_iva_b'] : 0;

	//RETENCION IVA SERVICIOS
	$codigo_ret_iva_s   = $aForm['codigo_ret_iva_s'];
	$porc_ret_iva_s     = $aForm['porc_ret_iva_s'];
	$base_iva_s         = $aForm['base_iva_s'];
	$val_iva_s          = ($aForm['val_iva_s'] != '') ? $aForm['val_iva_s'] : 0;


	$sql       = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
	$mone_base = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

	if ($mone_cod == $mone_base) {
		$sql      = "select pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
		$mone_extr = consulta_string_func($sql, 'pcon_seg_mone', $oIfx, '');

		$coti = $aForm["cotizacion_ext"];
	} else {
		$coti = $aForm["cotizacion"];
	}

	$valDirCre = 0;
	$valDiaCre = 0;
	$valDirDeb = 0;
	$valDiaDeb = 0;

	$val_deb   = 0;
	$val_cre   = 0;

	// RETENCION BIEN
	if (!empty($codigo_ret_fue_b) && $val_fue_b != '') {
		$val_cre 	= $val_fue_b;
		$valDirCre 	= $val_fue_b;
		$valDiaCre 	= $val_fue_b;

		$sql = "select  tret_cta_cre from saetret where
                    tret_cod_empr = $idempresa and
                    tret_cod = '$codigo_ret_fue_b' ";
		$cta_cre = consulta_string_func($sql, 'tret_cta_cre', $oIfx, '');

		$sql = "select cuen_nom_cuen from saecuen where 
				cuen_cod_empr = $idempresa and
				cuen_cod_cuen = '$cta_cre' ";
		$cuen_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		$cont = count($aDataGrid);
		$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
		$aDataGrid[$cont][$aLabelGrid[1]] = $codigo_ret_fue_b;
		$aDataGrid[$cont][$aLabelGrid[2]] = $clpv_cod;
		$aDataGrid[$cont][$aLabelGrid[3]] = $factura;
		$aDataGrid[$cont][$aLabelGrid[4]] = $clpv_cod;
		$aDataGrid[$cont][$aLabelGrid[5]] = $porc_ret_fue_b;
		$aDataGrid[$cont][$aLabelGrid[6]] = $base_fue_b;
		$aDataGrid[$cont][$aLabelGrid[7]] = $val_fue_b;
		$aDataGrid[$cont][$aLabelGrid[8]] = $retencion;
		$aDataGrid[$cont][$aLabelGrid[9]] = $det_ret;
		$aDataGrid[$cont][$aLabelGrid[10]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb;
			$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre;
			$aDataGrid[$cont][$aLabelGrid[13]] = $deb_tmp;
			$aDataGrid[$cont][$aLabelGrid[14]] = $cre_tmp;
		} else {
			// moneda extra

			$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb * $coti;
			$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre * $coti;

			$aDataGrid[$cont][$aLabelGrid[13]] = $valDirDeb;
			$aDataGrid[$cont][$aLabelGrid[14]] = $valDirCre;
		}

		$aDataGrid[$cont][$aLabelGrid[15]] = '';

		$contd = count($aDataDiar);
		$aDataGrid[$cont][$aLabelGrid[16]] = '<div align="center">
												<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalle_ret(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ');"
												alt="Eliminar"
												align="bottom" />
											</div>';
		$aDataGrid[$cont][$aLabelGrid[17]] = $contd;

		// DIARIO			
		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $cta_cre . $cta_deb;
		$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
		$aDataDiar[$contd][$aLabelDiar[3]] = $doc;
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra
			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $valDirDeb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $valDirCre;
		}

		$vacio = '-1';
		$aDataDiar[$contd][$aLabelDiar[9]] 	= '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																title = "Presione aqui para Eliminar"
																style="cursor: hand !important; cursor: pointer !important;"
																onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', ' . $cont . ', 2);"
																alt="Eliminar"
																align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]]	= '';
		$aDataDiar[$contd][$aLabelDiar[12]]	= '';
		$aDataDiar[$contd][$aLabelDiar[13]]	= '';
		$aDataDiar[$contd][$aLabelDiar[14]]	= '';
		$aDataDiar[$contd][$aLabelDiar[15]]	= '';
		$aDataDiar[$contd][$aLabelDiar[16]]	= '';
		$aDataDiar[$contd][$aLabelDiar[17]]	= $det_dir;
		$aDataDiar[$contd][$aLabelDiar[18]] = '';
		$aDataDiar[$contd][$aLabelDiar[19]] = '';
		$aDataDiar[$contd][$aLabelDiar[20]] = '';
		$aDataDiar[$contd][$aLabelDiar[21]] = $cont;
	}

	// RETENCION SERVICIO
	if (!empty($codigo_ret_fue_s) && $val_fue_s != '') {
		$valDirCre = 0;
		$valDiaCre = 0;
		$valDirDeb = 0;
		$valDiaDeb = 0;

		$val_deb   = 0;
		$val_cre   = 0;

		$val_cre 	= $val_fue_s;
		$valDirCre 	= $val_fue_s;
		$valDiaCre 	= $val_fue_s;

		$sql = "select  tret_cta_cre from saetret where
                    tret_cod_empr = $idempresa and
                    tret_cod = '$codigo_ret_fue_s' ";
		$cta_cre = consulta_string_func($sql, 'tret_cta_cre', $oIfx, '');

		$sql = "select cuen_nom_cuen from saecuen where 
				cuen_cod_empr = $idempresa and
				cuen_cod_cuen = '$cta_cre' ";
		$cuen_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		$cont = count($aDataGrid);
		$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
		$aDataGrid[$cont][$aLabelGrid[1]] = $codigo_ret_fue_s;
		$aDataGrid[$cont][$aLabelGrid[2]] = $clpv_cod;
		$aDataGrid[$cont][$aLabelGrid[3]] = $factura;
		$aDataGrid[$cont][$aLabelGrid[4]] = $clpv_cod;
		$aDataGrid[$cont][$aLabelGrid[5]] = $porc_ret_fue_s;
		$aDataGrid[$cont][$aLabelGrid[6]] = $base_fue_s;
		$aDataGrid[$cont][$aLabelGrid[7]] = $val_fue_s;
		$aDataGrid[$cont][$aLabelGrid[8]] = $retencion;
		$aDataGrid[$cont][$aLabelGrid[9]] = $det_ret;
		$aDataGrid[$cont][$aLabelGrid[10]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb;
			$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre;
			$aDataGrid[$cont][$aLabelGrid[13]] = $deb_tmp;
			$aDataGrid[$cont][$aLabelGrid[14]] = $cre_tmp;
		} else {
			// moneda extra

			$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb * $coti;
			$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre * $coti;

			$aDataGrid[$cont][$aLabelGrid[13]] = $valDirDeb;
			$aDataGrid[$cont][$aLabelGrid[14]] = $valDirCre;
		}

		$aDataGrid[$cont][$aLabelGrid[15]] = '';

		$contd = count($aDataDiar);
		$aDataGrid[$cont][$aLabelGrid[16]] = '<div align="center">
												<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalle_ret(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ');"
												alt="Eliminar"
												align="bottom" />
											</div>';
		$aDataGrid[$cont][$aLabelGrid[17]] = $contd;

		// DIARIO			
		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $cta_cre . $cta_deb;
		$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
		$aDataDiar[$contd][$aLabelDiar[3]] = $doc;
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra
			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $valDirDeb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $valDirCre;
		}

		$vacio = '-1';
		$aDataDiar[$contd][$aLabelDiar[9]] 	= '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																title = "Presione aqui para Eliminar"
																style="cursor: hand !important; cursor: pointer !important;"
																onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', ' . $cont . ', 2);"
																alt="Eliminar"
																align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]]	= '';
		$aDataDiar[$contd][$aLabelDiar[12]]	= '';
		$aDataDiar[$contd][$aLabelDiar[13]]	= '';
		$aDataDiar[$contd][$aLabelDiar[14]]	= '';
		$aDataDiar[$contd][$aLabelDiar[15]]	= '';
		$aDataDiar[$contd][$aLabelDiar[16]]	= '';
		$aDataDiar[$contd][$aLabelDiar[17]]	= $det_dir;
		$aDataDiar[$contd][$aLabelDiar[18]] = '';
		$aDataDiar[$contd][$aLabelDiar[19]] = '';
		$aDataDiar[$contd][$aLabelDiar[20]] = '';
		$aDataDiar[$contd][$aLabelDiar[21]] = $cont;
	}


	// RETENCION IVA BIEN
	if (!empty($codigo_ret_iva_b) && $val_iva_b != '') {
		$valDirCre = 0;
		$valDiaCre = 0;
		$valDirDeb = 0;
		$valDiaDeb = 0;

		$val_deb   = 0;
		$val_cre   = 0;

		$val_cre 	= $val_iva_b;
		$valDirCre 	= $val_iva_b;
		$valDiaCre 	= $val_iva_b;

		$sql = "select  tret_cta_cre from saetret where
                    tret_cod_empr = $idempresa and
                    tret_cod = '$codigo_ret_iva_b' ";
		$cta_cre = consulta_string_func($sql, 'tret_cta_cre', $oIfx, '');

		$sql = "select cuen_nom_cuen from saecuen where 
				cuen_cod_empr = $idempresa and
				cuen_cod_cuen = '$cta_cre' ";
		$cuen_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		$cont = count($aDataGrid);
		$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
		$aDataGrid[$cont][$aLabelGrid[1]] = $codigo_ret_iva_b;
		$aDataGrid[$cont][$aLabelGrid[2]] = $clpv_cod;
		$aDataGrid[$cont][$aLabelGrid[3]] = $factura;
		$aDataGrid[$cont][$aLabelGrid[4]] = $clpv_cod;
		$aDataGrid[$cont][$aLabelGrid[5]] = $porc_ret_iva_b;
		$aDataGrid[$cont][$aLabelGrid[6]] = $base_iva_b;
		$aDataGrid[$cont][$aLabelGrid[7]] = $val_iva_b;
		$aDataGrid[$cont][$aLabelGrid[8]] = $retencion;
		$aDataGrid[$cont][$aLabelGrid[9]] = $det_ret;
		$aDataGrid[$cont][$aLabelGrid[10]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb;
			$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre;
			$aDataGrid[$cont][$aLabelGrid[13]] = $deb_tmp;
			$aDataGrid[$cont][$aLabelGrid[14]] = $cre_tmp;
		} else {
			// moneda extra

			$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb * $coti;
			$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre * $coti;

			$aDataGrid[$cont][$aLabelGrid[13]] = $valDirDeb;
			$aDataGrid[$cont][$aLabelGrid[14]] = $valDirCre;
		}

		$aDataGrid[$cont][$aLabelGrid[15]] = '';

		$contd = count($aDataDiar);
		$aDataGrid[$cont][$aLabelGrid[16]] = '<div align="center">
												<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalle_ret(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ');"
												alt="Eliminar"
												align="bottom" />
											</div>';
		$aDataGrid[$cont][$aLabelGrid[17]] = $contd;

		// DIARIO			
		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $cta_cre . $cta_deb;
		$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
		$aDataDiar[$contd][$aLabelDiar[3]] = $doc;
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra
			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $valDirDeb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $valDirCre;
		}

		$vacio = '-1';
		$aDataDiar[$contd][$aLabelDiar[9]] 	= '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																title = "Presione aqui para Eliminar"
																style="cursor: hand !important; cursor: pointer !important;"
																onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', ' . $cont . ', 2);"
																alt="Eliminar"
																align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]]	= '';
		$aDataDiar[$contd][$aLabelDiar[12]]	= '';
		$aDataDiar[$contd][$aLabelDiar[13]]	= '';
		$aDataDiar[$contd][$aLabelDiar[14]]	= '';
		$aDataDiar[$contd][$aLabelDiar[15]]	= '';
		$aDataDiar[$contd][$aLabelDiar[16]]	= '';
		$aDataDiar[$contd][$aLabelDiar[17]]	= $det_dir;
		$aDataDiar[$contd][$aLabelDiar[18]] = '';
		$aDataDiar[$contd][$aLabelDiar[19]] = '';
		$aDataDiar[$contd][$aLabelDiar[20]] = '';
		$aDataDiar[$contd][$aLabelDiar[21]] = $cont;
	}

	// RETENCION IVA BIEN
	if (!empty($codigo_ret_iva_s) && $val_iva_s != '') {
		//$oReturn->alert($val_iva_s);
		$valDirCre = 0;
		$valDiaCre = 0;
		$valDirDeb = 0;
		$valDiaDeb = 0;

		$val_deb   = 0;
		$val_cre   = 0;

		$val_cre 	= $val_iva_s;
		$valDirCre 	= $val_iva_s;
		$valDiaCre 	= $val_iva_s;

		$sql = "select  tret_cta_cre from saetret where
                    tret_cod_empr = $idempresa and
                    tret_cod = '$codigo_ret_iva_s' ";
		$cta_cre = consulta_string_func($sql, 'tret_cta_cre', $oIfx, '');

		$sql = "select cuen_nom_cuen from saecuen where 
				cuen_cod_empr = $idempresa and
				cuen_cod_cuen = '$cta_cre' ";
		$cuen_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		$cont = count($aDataGrid);
		$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
		$aDataGrid[$cont][$aLabelGrid[1]] = $codigo_ret_iva_s;
		$aDataGrid[$cont][$aLabelGrid[2]] = $clpv_cod;
		$aDataGrid[$cont][$aLabelGrid[3]] = $factura;
		$aDataGrid[$cont][$aLabelGrid[4]] = $clpv_cod;
		$aDataGrid[$cont][$aLabelGrid[5]] = $porc_ret_iva_s;
		$aDataGrid[$cont][$aLabelGrid[6]] = $base_iva_s;
		$aDataGrid[$cont][$aLabelGrid[7]] = $val_iva_s;
		$aDataGrid[$cont][$aLabelGrid[8]] = $retencion;
		$aDataGrid[$cont][$aLabelGrid[9]] = $det_ret;
		$aDataGrid[$cont][$aLabelGrid[10]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb;
			$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre;
			$aDataGrid[$cont][$aLabelGrid[13]] = $deb_tmp;
			$aDataGrid[$cont][$aLabelGrid[14]] = $cre_tmp;
		} else {
			// moneda extra

			$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb * $coti;
			$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre * $coti;

			$aDataGrid[$cont][$aLabelGrid[13]] = $valDirDeb;
			$aDataGrid[$cont][$aLabelGrid[14]] = $valDirCre;
		}

		$aDataGrid[$cont][$aLabelGrid[15]] = '';

		$contd = count($aDataDiar);
		$aDataGrid[$cont][$aLabelGrid[16]] = '<div align="center">
												<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalle_ret(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ');"
												alt="Eliminar"
												align="bottom" />
											</div>';
		$aDataGrid[$cont][$aLabelGrid[17]] = $contd;

		// DIARIO			
		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $cta_cre . $cta_deb;
		$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
		$aDataDiar[$contd][$aLabelDiar[3]] = $doc;
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra
			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $valDirDeb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $valDirCre;
		}

		$vacio = '-1';
		$aDataDiar[$contd][$aLabelDiar[9]] 	= '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
																title = "Presione aqui para Eliminar"
																style="cursor: hand !important; cursor: pointer !important;"
																onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', ' . $cont . ', 2);"
																alt="Eliminar"
																align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]]	= '';
		$aDataDiar[$contd][$aLabelDiar[12]]	= '';
		$aDataDiar[$contd][$aLabelDiar[13]]	= '';
		$aDataDiar[$contd][$aLabelDiar[14]]	= '';
		$aDataDiar[$contd][$aLabelDiar[15]]	= '';
		$aDataDiar[$contd][$aLabelDiar[16]]	= '';
		$aDataDiar[$contd][$aLabelDiar[17]]	= $det_dir;
		$aDataDiar[$contd][$aLabelDiar[18]] = '';
		$aDataDiar[$contd][$aLabelDiar[19]] = '';
		$aDataDiar[$contd][$aLabelDiar[20]] = '';
		$aDataDiar[$contd][$aLabelDiar[21]] = $cont;
	}

	/***********************************************************************************/
	// C U E N T A S    D E   G A S T O
	/***********************************************************************************/
	// BIENES
	$valor_grab12b  = $aForm['valor_grab12b'];
	$valor_grab0b   = $aForm['valor_grab0b'];
	$iceb           = $aForm['iceb'];
	$ivab           = $aForm['ivab'];
	$ivabp          = $aForm['ivabp'];

	// SERVICIOS
	$valor_grab12s  = $aForm['valor_grab12s'];
	$valor_grab0s   = $aForm['valor_grab0s'];
	$ices           = $aForm['ices'];
	$ivas           = $aForm['ivas'];
	$totals         = $aForm['totals'];

	// TOTAL
	$valor_grab12t  = $aForm['valor_grab12t'];
	$valor_grab0t   = $aForm['valor_grab0t'];
	$icet           = $aForm['icet'];
	$ivat           = $aForm['ivat'];

	// CUENTAS CONTABLES
	$fisc_b         = trim($aForm['fisc_b']);
	$fisc_s         = trim($aForm['fisc_s']);
	$gasto1         = trim($aForm['gasto1']);
	$val_gasto1     = $aForm['val_gasto1'];
	$gasto2         = trim($aForm['gasto2']);
	$val_gasto2     = $aForm['val_gasto2'];

	//centro de costos
	$ccosn_3        = $aForm['cod_ccosn_3'];
	$ccosn_4        = $aForm['cod_ccosn_4'];


	// if (empty($documento)) {
	$serie_docu_ad = $aForm['serie'];
	$fact_docu_ad = $aForm['factura'];
	$documento = $serie_docu_ad . '-' . $fact_docu_ad;
	// }


	if (($ivat) > 0 && empty($fisc_s)) {

		$cred = 0;
		$deb  = $ivat;

		$sql = "select pccp_cre_fis  from saepccp where	pccp_cod_empr = $idempresa";
		$fisc_b = consulta_string_func($sql, 'pccp_cre_fis', $oIfx, '');

		$sql = "select cuen_nom_cuen from saecuen where 
			                    cuen_cod_empr = $idempresa and
			                    cuen_cod_cuen = '$fisc_b' ";
		$nom_cta = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		//echo $sql;exit;			
		// DIARIO
		$contd = count($aDataDiar);
		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $fisc_b;
		$aDataDiar[$contd][$aLabelDiar[2]] = $nom_cta;
		$aDataDiar[$contd][$aLabelDiar[3]] = $documento;
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;

			if ($coti > 0) {
				$cre_tmp = round(($cred / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($deb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra         
			$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
		}

		$vacio = '-1';
		$aDataDiar[$contd][$aLabelDiar[9]] = '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
												alt="Eliminar"
												align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]] = '';
		$aDataDiar[$contd][$aLabelDiar[12]] = '';
		$aDataDiar[$contd][$aLabelDiar[13]] = '';
		$aDataDiar[$contd][$aLabelDiar[14]] = '';
		$aDataDiar[$contd][$aLabelDiar[15]] = '';
		$aDataDiar[$contd][$aLabelDiar[16]] = '';
		$aDataDiar[$contd][$aLabelDiar[17]] = $det_dir . ' ' . $fact_tmp;;
		$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_cod;
		$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
	}

	if (!empty($fisc_s)) {
		// $cred = 0;
		// $deb  = $ivas;

		$cred = 0;
		$deb  = $ivat;

		$sql = "select cuen_nom_cuen from saecuen where 
			                    cuen_cod_empr = $idempresa and
			                    cuen_cod_cuen = '$fisc_s' ";
		$nom_cta = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		// DIARIO
		$contd = count($aDataDiar);
		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $fisc_s;
		$aDataDiar[$contd][$aLabelDiar[2]] = $nom_cta;
		$aDataDiar[$contd][$aLabelDiar[3]] = $documento;
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($cred / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($deb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra         
			$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
		}

		$vacio = '-1';
		$aDataDiar[$contd][$aLabelDiar[9]] = '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
												alt="Eliminar"
												align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]] = '';
		$aDataDiar[$contd][$aLabelDiar[12]] = '';
		$aDataDiar[$contd][$aLabelDiar[13]] = '';
		$aDataDiar[$contd][$aLabelDiar[14]] = '';
		$aDataDiar[$contd][$aLabelDiar[15]] = '';
		$aDataDiar[$contd][$aLabelDiar[16]] = '';
		$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
		$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_cod;
		$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
	}

	// DISTRIBUCION CENTRO COSTOS
	$aDataGrid_Dist      = $_SESSION['aDataGirdDist'];
	//$oReturn->alert('asas '.count($aDataGrid_Dist));
	if (count($aDataGrid_Dist) > 0) {
		foreach ($aDataGrid_Dist as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 0) {
					$i       = $cont + 1;
				} elseif ($aux == 1) {
					$cuen_cod = $aVal;
				} elseif ($aux == 2) {
					$cuen_nom = $aVal;
				} elseif ($aux == 3) {
					$ccos_cod = $aVal;
				} elseif ($aux == 4) {
					$ccos_nom = $aVal;
				} elseif ($aux == 5) {
					$valor    = $aVal;
				} elseif ($aux == 6) {
					$detalleDistribucion = $aVal;
					$cred = 0;
					$deb  = $valor;

					// DIARIO
					$contd = count($aDataDiar);
					$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
					$aDataDiar[$contd][$aLabelDiar[1]] = $cuen_cod;
					$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
					$aDataDiar[$contd][$aLabelDiar[3]] = $documento;
					$aDataDiar[$contd][$aLabelDiar[4]] = $coti;


					if ($mone_cod == $mone_base) {
						// moneda local
						$cre_tmp = 0;
						$deb_tmp = 0;
						if ($coti > 0) {
							$cre_tmp = round(($cred / $coti), 2);
						}

						if ($coti > 0) {
							$deb_tmp = round(($deb / $coti), 2);
						}

						$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
						$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
						$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
						$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
					} else {
						// moneda extra         
						$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
						$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
						$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
						$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
					}

					$vacio = '-1';
					$aDataDiar[$contd][$aLabelDiar[9]] = '';
					$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
															title = "Presione aqui para Eliminar"
															style="cursor: hand !important; cursor: pointer !important;"
															onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
															alt="Eliminar"
															align="bottom" />';
					$aDataDiar[$contd][$aLabelDiar[11]] = '';
					$aDataDiar[$contd][$aLabelDiar[12]] = '';
					$aDataDiar[$contd][$aLabelDiar[13]] = '';
					$aDataDiar[$contd][$aLabelDiar[14]] = '';
					$aDataDiar[$contd][$aLabelDiar[15]] = '';
					$aDataDiar[$contd][$aLabelDiar[16]] = '';
					$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
					$aDataDiar[$contd][$aLabelDiar[18]] = $ccos_cod;
					$aDataDiar[$contd][$aLabelDiar[19]] = '';
				}
				$aux++;
			}
			$cont++;
		}
	} else {
		if (!empty($gasto1)) {
			$cred = 0;
			$deb  = $val_gasto1;

			$sql = "select cuen_nom_cuen from saecuen where 
											cuen_cod_empr = $idempresa and
											cuen_cod_cuen = '$gasto1' ";
			$nom_cta = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

			// DIARIO
			$contd = count($aDataDiar);
			$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
			$aDataDiar[$contd][$aLabelDiar[1]] = $gasto1;
			$aDataDiar[$contd][$aLabelDiar[2]] = $nom_cta;
			$aDataDiar[$contd][$aLabelDiar[3]] = $documento;
			$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

			if ($mone_cod == $mone_base) {
				// moneda local
				$cre_tmp = 0;
				$deb_tmp = 0;
				if ($coti > 0) {
					$cre_tmp = round(($cred / $coti), 2);
				}

				if ($coti > 0) {
					$deb_tmp = round(($deb / $coti), 2);
				}

				$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
				$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
				$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
				$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
			} else {
				// moneda extra         
				$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
				$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
				$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
				$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
			}

			$vacio = '-1';
			$aDataDiar[$contd][$aLabelDiar[9]] = '';
			$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
															title = "Presione aqui para Eliminar"
															style="cursor: hand !important; cursor: pointer !important;"
															onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
															alt="Eliminar"
															align="bottom" />';
			$aDataDiar[$contd][$aLabelDiar[11]] = '';
			$aDataDiar[$contd][$aLabelDiar[12]] = '';
			$aDataDiar[$contd][$aLabelDiar[13]] = '';
			$aDataDiar[$contd][$aLabelDiar[14]] = '';
			$aDataDiar[$contd][$aLabelDiar[15]] = '';
			$aDataDiar[$contd][$aLabelDiar[16]] = '';
			$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
			$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_3;
			$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
		}
	} // fin if data grid dist ccosn



	/*********************************************************************************/
	// R E E M B O L S O
	/*********************************************************************************/
	// REEMBOLSO
	$aDataGridReemb = $_SESSION['aDataGird'];
	if (count($aDataGridReemb) > 0) {
		unset($array_dasi_c);
		unset($array_tmp);
		$pos = 0;
		foreach ($aDataGridReemb as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 0) {
					$ii        = $cont + 1;
				} elseif ($aux == 15) {
					$cuen_cod = $aVal;
				} elseif ($aux == 6) {
					$factura  = $aVal;
				} elseif ($aux == 32) {
					$ccos_cod = $aVal;
				} elseif ($aux == 26) {
					$valor14tr = round($aVal, 2);
				} elseif ($aux == 27) {
					$valor0tr = round($aVal, 2);
				} elseif ($aux == 33) {
					$valor_reemb = 0;
					$valor_reemb = $valor14tr + $valor0tr;

					$array_dasi_c[$cuen_cod][$ccos_cod] += $valor_reemb;
					$array_tmp[$pos] = array($cuen_cod, $ccos_cod);

					$pos++;
				}
				$aux++;
			}
			$cont++;
		}

		if (count($array_tmp) > 0) {
			unset($array_cta);
			$array_cta = array_unique($array_tmp);
			$cta_prod = '';
			$cta_cosn = '';
			foreach ($array_cta as $val) {
				$cta_prod 	 = $val[0];
				$cta_cosn 	 = $val[1];
				$valor_reemb = 0;
				$valor_reemb = round($array_dasi_c[$cta_prod][$cta_cosn], 2);

				//$table_op .= detalle_compr($idempresa, $cta_prod, $cta_cosn, $valor_reemb, 0, $i, $oIfx, '', '', '');

				$cred = 0;
				$deb  = $valor_reemb;

				$sql = "select cuen_nom_cuen from saecuen where 
										cuen_cod_empr = $idempresa and
										cuen_cod_cuen = '$cta_prod' ";
				$nom_cta = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

				// DIARIO
				$contd = count($aDataDiar);
				$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
				$aDataDiar[$contd][$aLabelDiar[1]] = $cta_prod;
				$aDataDiar[$contd][$aLabelDiar[2]] = $nom_cta;
				$aDataDiar[$contd][$aLabelDiar[3]] = '';
				$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

				if ($mone_cod == $mone_base) {
					// moneda local
					$cre_tmp = 0;
					$deb_tmp = 0;
					if ($coti > 0) {
						$cre_tmp = round(($cred / $coti), 2);
					}

					if ($coti > 0) {
						$deb_tmp = round(($deb / $coti), 2);
					}

					$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
					$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
					$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
					$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
				} else {
					// moneda extra         
					$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
					$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
					$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
					$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
				}

				$vacio = '-1';
				$aDataDiar[$contd][$aLabelDiar[9]] = '';
				$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
														title = "Presione aqui para Eliminar"
														style="cursor: hand !important; cursor: pointer !important;"
														onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
														alt="Eliminar"
														align="bottom" />';
				$aDataDiar[$contd][$aLabelDiar[11]] = '';
				$aDataDiar[$contd][$aLabelDiar[12]] = '';
				$aDataDiar[$contd][$aLabelDiar[13]] = '';
				$aDataDiar[$contd][$aLabelDiar[14]] = '';
				$aDataDiar[$contd][$aLabelDiar[15]] = '';
				$aDataDiar[$contd][$aLabelDiar[16]] = '';
				$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
				$aDataDiar[$contd][$aLabelDiar[18]] = $cta_cosn;
				$aDataDiar[$contd][$aLabelDiar[19]] = '';

				$i++;
			} // fin for
		} // fin if

	}



	if (!empty($gasto2)) {
		$cred = 0;
		$deb  = $val_gasto2;

		$sql = "select cuen_nom_cuen from saecuen where 
			                    cuen_cod_empr = $idempresa and
			                    cuen_cod_cuen = '$gasto1' ";
		$nom_cta = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		// DIARIO
		$contd = count($aDataDiar);
		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $gasto2;
		$aDataDiar[$contd][$aLabelDiar[2]] = $nom_cta;
		$aDataDiar[$contd][$aLabelDiar[3]] = $documento;
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($cred / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($deb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra         
			$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
		}

		$vacio = '-1';
		$aDataDiar[$contd][$aLabelDiar[9]] = '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
												alt="Eliminar"
												align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]] = '';
		$aDataDiar[$contd][$aLabelDiar[12]] = '';
		$aDataDiar[$contd][$aLabelDiar[13]] = '';
		$aDataDiar[$contd][$aLabelDiar[14]] = '';
		$aDataDiar[$contd][$aLabelDiar[15]] = '';
		$aDataDiar[$contd][$aLabelDiar[16]] = '';
		$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
		$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_4;
		$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
	}


	// RETENCION Asumida
	if ($valor_ret_asumid > 0) {
		$sql = "select pccp_cret_asumi   from saepccp where	pccp_cod_empr = $idempresa ";
		$cuenta_re_asum = consulta_string_func($sql, 'pccp_cret_asumi', $oIfx, '');

		$cred = 0;
		$deb  = $valor_ret_asumid;

		$sql = "select cuen_nom_cuen from saecuen where 
								cuen_cod_empr = $idempresa and
								cuen_cod_cuen = '$cuenta_re_asum' ";
		$nom_cta = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		// DIARIO
		$contd = count($aDataDiar);
		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $cuenta_re_asum;
		$aDataDiar[$contd][$aLabelDiar[2]] = $nom_cta;
		$aDataDiar[$contd][$aLabelDiar[3]] = '';
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($cred / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($deb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra         
			$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
		}

		$vacio = '-1';
		$aDataDiar[$contd][$aLabelDiar[9]] = '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
												title = "Presione aqui para Eliminar"
												style="cursor: hand !important; cursor: pointer !important;"
												onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
												alt="Eliminar"
												align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]] = '';
		$aDataDiar[$contd][$aLabelDiar[12]] = '';
		$aDataDiar[$contd][$aLabelDiar[13]] = '';
		$aDataDiar[$contd][$aLabelDiar[14]] = '';
		$aDataDiar[$contd][$aLabelDiar[15]] = '';
		$aDataDiar[$contd][$aLabelDiar[16]] = '';
		$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
		$aDataDiar[$contd][$aLabelDiar[18]] = '';
		$aDataDiar[$contd][$aLabelDiar[19]] = '';

		$i++;

		//$table_op .= detalle_compr($idempresa, $cuenta_re_asum, '', $valor_ret_asumid, 0, $i, $oIfx, '', '');
		//$tot_deb += $valor_ret_asumid;
		//$i++;

	}

	// RETENCION
	if (count($aDataGrid) > 0) {
		$_SESSION['aDataGirdRet'] = $aDataGrid;
		$sHtml = mostrar_grid_ret($idempresa, $idsucursal);
		$oReturn->assign("divRet", "innerHTML", $sHtml);
	}



	$_SESSION['aDataGirdDir'] = $aDataGrid_Dir;
	$sHtml = mostrar_grid_dir($idempresa, $idsucursal);
	$oReturn->assign("divDir", "innerHTML", $sHtml);

	// DIARIO
	$sHtml = '';
	$_SESSION['aDataGirdDiar'] = $aDataDiar;
	$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
	$oReturn->assign("divDiario", "innerHTML", $sHtml);




	// --------------------------------------------------------------------------
	// VALIDACIONES PERU
	// --------------------------------------------------------------------------
	$empr_cod_pais = $_SESSION['U_PAIS_COD'];
	$sql_codInter_pais = "SELECT pais_codigo_inter from saepais where pais_cod_pais = $empr_cod_pais;";
	$codigo_pais = consulta_string_func($sql_codInter_pais, 'pais_codigo_inter', $oIfx, 0);

	$sql_pcon = "SELECT pcon_cue_niif from saepcon WHERE pcon_cod_empr = $idempresa;";
	$pcon_cue_niif = consulta_string_func($sql_pcon, 'pcon_cue_niif', $oIfx, 0);

	$tipo_retencion_ad = $aForm['tipo_retencion_ad'];


	if ($tipo_retencion_ad == 'D') {
		$sql_retencion_bien = "SELECT pccp_tran_det from saepccp where pccp_cod_empr = $idempresa";
		$pccp_tran_det = consulta_string_func($sql_retencion_bien, 'pccp_tran_det', $oIfx, '');
		if (!empty($pccp_tran_det)) {
			if ($val_fue_s > 0) {
				$valor_detra = $val_fue_s;
			} else {
				$valor_detra = $val_fue_b;
			}

			$existe_tipo = 'N';
			foreach ($aDataGrid_Dir as $key => $data_dir) {
				$tipo_existe = $data_dir['Tipo'];
				if ($tipo_existe == $pccp_tran_det) {
					$existe_tipo = 'S';
				}
			}


			if ($existe_tipo == 'N' && $valor_detra > 0) {

				$numero_factura_dire = $aForm['factura_dir'];
				$array_factura = explode("-", $numero_factura_dire);
				$nueva_factura_detr = $array_factura[0] . '-' . $array_factura[1] . '-DET';


				//$oReturn->assign('factura_dir', 'value', $nueva_factura_detr);
				//$oReturn->assign('tran', 'value', $pccp_tran_det);
				//$oReturn->assign('det_dir', 'value', 'CUENTA DETRACCION');
				//$oReturn->assign('fact_valor', 'value', $valor_detra);
				//$oReturn->script('anadir_dir();');
				//$oReturn->assign('factura_dir', 'value', $numero_factura_dire);



				// -------------------------------------------------------------------------------------
				// DIRECTORIO
				// -------------------------------------------------------------------------------------
				//$aDataGrid_Dir  = $_SESSION['aDataGirdDir'];
				$fact_num  = $nueva_factura_detr;
				$fec_emis  = $aForm['fecha_emision'];
				$fec_venc  = $aForm['fecha_vence'];
				$clpv_cod  = $aForm['clpv_cod'];
				$tran_cod  = $pccp_tran_det;
				$det_dir   = 'CUENTA DETRACCION';

				$aLabelGrid = array(
					'Fila',
					'Cliente',
					'Subcliente',
					'Tipo',
					'Factura',
					'Fec. Vence',
					'Detalle',
					'Cotizacion',
					'Debito Moneda Local',
					'Credito Moneda Local',
					'Debito Moneda Ext',
					'Credito Moneda Ext',
					'Modificar',
					'Eliminar',
					'DI'
				);

				// CUENTAS DE CLPV
				$sql       = "select clpv_cod_cuen, grpv_cod_grpv, clpv_clopv_clpv from saeclpv where clpv_cod_empr = $idempresa and clpv_cod_clpv = $clpv_cod ";
				$tipo      = consulta_string_func($sql, 'clpv_clopv_clpv', $oIfx, '');


				$valDirCre = 0;
				$valDiaCre = 0;
				$valDirDeb = 0;
				$valDiaDeb = 0;

				if ($tipo == 'CL') {
					$modulo = 3;
				} elseif ($tipo == 'PV') {
					$modulo = 4;
				}


				//tipo de transaccion
				$sql = "select trans_tip_tran from saetran where tran_cod_tran = '$tran_cod' and tran_cod_modu = $modulo and tran_cod_empr = $idempresa ";
				$trans_tip_tran = consulta_string_func($sql, 'trans_tip_tran', $oIfx, '');

				//$oReturn->alert($sql);

				if ($trans_tip_tran == 'DB') {
					$valDirDeb = $valor_detra;
					$valDiaDeb = $valor_detra;
				} elseif ($trans_tip_tran == 'CR') {
					$valDirCre = $valor_detra;
					$valDiaCre = $valor_detra;
				}


				$cont = count($aDataGrid_Dir);
				$aDataGrid_Dir[$cont][$aLabelGrid[0]] = floatval($cont);
				$aDataGrid_Dir[$cont][$aLabelGrid[1]] = $clpv_cod;
				$aDataGrid_Dir[$cont][$aLabelGrid[2]] = $ccli_cod;
				$aDataGrid_Dir[$cont][$aLabelGrid[3]] = $tran_cod;
				$aDataGrid_Dir[$cont][$aLabelGrid[4]] = $fact_num;
				$aDataGrid_Dir[$cont][$aLabelGrid[5]] = $fec_venc;
				$aDataGrid_Dir[$cont][$aLabelGrid[6]] = $det_dir;
				$aDataGrid_Dir[$cont][$aLabelGrid[7]] = $coti;

				if ($mone_cod == $mone_base) {
					// moneda local
					$cre_tmp = 0;
					$deb_tmp = 0;
					if ($coti > 0) {
						$cre_tmp = round(($valDirCre / $coti), 2);
					}

					if ($coti > 0) {
						$deb_tmp = round(($valDirDeb / $coti), 2);
					}

					$aDataGrid_Dir[$cont][$aLabelGrid[8]] = $valDirDeb;
					$aDataGrid_Dir[$cont][$aLabelGrid[9]] = $valDirCre;
					$aDataGrid_Dir[$cont][$aLabelGrid[10]] = $deb_tmp;
					$aDataGrid_Dir[$cont][$aLabelGrid[11]] = $cre_tmp;
				} else {
					// moneda extra

					$aDataGrid_Dir[$cont][$aLabelGrid[8]] = $valDirDeb * $coti;
					$aDataGrid_Dir[$cont][$aLabelGrid[9]] = $valDirCre * $coti;

					$aDataGrid_Dir[$cont][$aLabelGrid[10]] = $valDirDeb;
					$aDataGrid_Dir[$cont][$aLabelGrid[11]] = $valDirCre;
				}

				$aDataGrid_Dir[$cont][$aLabelGrid[12]] = '';

				$contd = count($aDataDiar);
				$aDataGrid_Dir[$cont][$aLabelGrid[13]] = '<div align="center">
															<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
															title = "Presione aqui para Eliminar"
															style="cursor: hand !important; cursor: pointer !important;"
															onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1 );"
															alt="Eliminar"
															align="bottom" />
														</div>';


				$aDataGrid_Dir[$cont][$aLabelGrid[14]] = $contd;


				$_SESSION['aDataGirdDir'] = $aDataGrid_Dir;
				$sHtml = mostrar_grid_dir($idempresa, $idsucursal);
				$oReturn->assign("divDir", "innerHTML", $sHtml);


				// -------------------------------------------------------------------------------------
				// FIN DIRECTORIO
				// -------------------------------------------------------------------------------------

			}
		}
	}


	// --------------------------------------------------------------------------
	// FIN VALIDACIONES PERU
	// --------------------------------------------------------------------------





	// TOTAL DIARIO
	$oReturn->script("total_diario();");








	// ------------------------------------------------------------------


	$cuenta_aplicada = $_SESSION['SESSION_CUENTA_APLICADA'];
	$centro_costos_plantilla = $_SESSION['SESSINO_COSTOS_PLANTILLA'];

	if (!empty($cuenta_aplicada) && $centro_costos_plantilla == 'N') {

		$sql = "select cuen_nom_cuen from saecuen where cuen_cod_cuen = '$cuenta_aplicada' and cuen_cod_empr = $idempresa";
		$cta_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		$aDataDiar = $_SESSION['aDataGirdDiar'];

		$aLabelDiar = array(
			'Fila',
			'Cuenta',
			'Nombre',
			'Documento',
			'Cotizacion',
			'Debito Moneda Local',
			'Credito Moneda Local',
			'Debito Moneda Ext',
			'Credito Moneda Ext',
			'Modificar',
			'Eliminar',
			'Beneficiario',
			'Cuenta Bancaria',
			'Cheque',
			'Fecha Venc',
			'Formato Cheque',
			'Codigo Ctab',
			'Detalle',
			'Centro Costo',
			'Centro Actividad'
		);

		$mone_cod   = $aForm["moneda"];
		$documento  = $aForm["documento"];
		$act_cod    = $aForm["actividad"];
		$val_cta    = str_replace(",", "", $aForm['val_cta']);
		$tipo = 'DB';
		if ($tipo == 'DB') {
			$cred = 0;
			$deb  = $val_cta;
		}

		$sql        = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
		$mone_base  = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

		if ($mone_cod == $mone_base) {
			$sql      = "select pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
			$mone_extr = consulta_string_func($sql, 'pcon_seg_mone', $oIfx, '');

			$sql = "select tcam_valc_tcam from saetcam where
					mone_cod_empr = $idempresa and
					tcam_cod_mone = $mone_extr and
					tcam_fec_tcam in (
										select max(tcam_fec_tcam)  from saetcam where
												mone_cod_empr = $idempresa and
												tcam_cod_mone = $mone_extr
									)  ";

			$coti = $aForm["cotizacion_ext"]; //consulta_string_func($sql, 'tcam_val_tcam', $oIfx, 0);
		} else {
			$coti = $aForm["cotizacion"];
		}

		// DIARIO
		$contd = count($aDataDiar);

		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $cuenta_aplicada;
		$aDataDiar[$contd][$aLabelDiar[2]] = $cta_nom;
		$aDataDiar[$contd][$aLabelDiar[3]] = $documento;
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($cred / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($deb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra         
			$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
		}

		$vacio = '-1';
		$aDataDiar[$contd][$aLabelDiar[9]] = '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
													title = "Presione aqui para Eliminar"
													style="cursor: hand !important; cursor: pointer !important;"
													onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
													alt="Eliminar"
													align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]] = '';
		$aDataDiar[$contd][$aLabelDiar[12]] = '';
		$aDataDiar[$contd][$aLabelDiar[13]] = '';
		$aDataDiar[$contd][$aLabelDiar[14]] = '';
		$aDataDiar[$contd][$aLabelDiar[15]] = '';
		$aDataDiar[$contd][$aLabelDiar[16]] = '';
		$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
		$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_cod;
		$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;

		//var_dump($aDataDiar);
		//exit;

		$sHtml = '';
		$_SESSION['aDataGirdDiar'] = $aDataDiar;
		$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
		$oReturn->assign("divDiario", "innerHTML", $sHtml);
		// TOTAL DIARIO
		$oReturn->script("total_diario();");
		$oReturn->script("cerrar_ventana();");


		//$oReturn->assign("cod_cta", "value", $cuenta_aplicada);
		//$oReturn->assign("detalla_diario", "value", $detalle);
		//$oReturn->assign("crdb", "value", 'DB');
		//$oReturn->assign("nom_cta", "value", $arrayCuenta[$cuenta_aplicada]);
		//$oReturn->script("anadir_dasi();");
	}





	// ---------------------------------------------------------------------








	// SERIE - FACTURA
	$serie = $aForm['serie'];
	$fact  = $aForm['factura'];

	//$oReturn->alert($serie);
	$oReturn->assign("documento", "value", $serie . '-' . $fact);

	return $oReturn;
}

function mostrar_grid_dir($idempresa, $idsucursal)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$aDataGrid  = $_SESSION['aDataGirdDir'];
	$aLabelGrid = array(
		'Fila',
		'Cliente',
		'Subcliente',
		'Tipo',
		'Factura',
		'Fec. Vence',
		'Detalle',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'DI'
	);

	$cont    = 0;
	$tot_cre = 0;
	$tot_deb = 0;
	if (count($aDataGrid) > 0) {
		foreach ($aDataGrid as $aValues) {
			$aux     = 0;
			foreach ($aValues as $aVal) {

				$valor_aval = $aVal;

				if ($aux == 0) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . ($cont + 1) . '</div>';
				} elseif ($aux == 1) {
					$sql = "select  clpv_nom_clpv from saeclpv where clpv_cod_clpv = $aVal and clpv_cod_empr = $idempresa ";
					$clpv_nom = consulta_string_func($sql, 'clpv_nom_clpv', $oIfx, '');
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $clpv_nom . '</div>';
				} elseif ($aux == 2) {

					if (empty($aVal)) {
						$aVal = 0;
					}
					$sql = "select ccli_nom_conta from saeccli 	where
													ccli_cod_empr = $idempresa and
													ccli_cod_ccli = '$aVal' ";
					$ccli_nom = consulta_string_func($sql, 'ccli_nom_conta', $oIfx, '');

					if (empty($ccli_nom)) {
						$ccli_nom = '';
					}

					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $ccli_nom . '</div>';
				} elseif ($aux == 3) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
				} elseif ($aux == 4) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
				} elseif ($aux == 5) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
				} elseif ($aux == 6) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
				} elseif ($aux == 7) {	//Cotizacion
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 6), 6, '.', ',') . '</div>';
				} elseif ($aux == 8) {	// DEBITO
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
					$tot_deb += $aVal;
				} elseif ($aux == 9) {	//CREDITO
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
					$tot_cre += $aVal;
				} elseif ($aux == 10) {	//DEBITO EXT
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
				} elseif ($aux == 11) {	//CREDITO EXT
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
				} elseif ($aux == 14) {	//DI
					$di = $aVal;
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
				} elseif ($aux == 12) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
																			<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/pencil.png"
																			title = "Presione aqui para Eliminar"
																			style="cursor: hand !important; cursor: pointer !important;"
																			onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ');"
																			alt="Eliminar"
																			align="bottom" />
																		</div>';
				} else
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				$aux++;
			}
			$cont++;
		}
	}

	$array = array('', '', '', '', '', '', '', '', $tot_deb, $tot_cre);

	return genera_grid_dir($aDatos, $aLabelGrid, 'DIRECTORIO', 99, '', $array);
}

function elimina_detalle_dir($id = null, $idempresa, $idsucursal, $id_di = '', $id_ret, $op = 1)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$aLabelGrid = array(
		'Id',
		'Cliente',
		'Subcliente',
		'Tipo',
		'Factura',
		'Fec. Vence',
		'Detalle',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'DI'
	);

	unset($aDataGrid);
	$contador  = 0;

	$aDataGrid = $_SESSION['aDataGirdDir'];
	$contador  = count($aDataGrid);

	if ($contador > 0) {
		if ($id >= 0) {
			unset($aDataGrid[$id]);
			$aDataGrid = array_values($aDataGrid);
			$cont 	   = 0;
			foreach ($aDataGrid as $aValues) {
				$aux     = 0;
				foreach ($aValues as $aVal) {
					if ($aux == 0) {
						$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . ($cont + 1) . '</div>';
					} elseif ($aux == 12) {
						$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
																				<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/pencil.png"
																				title = "Presione aqui para Eliminar"
																				style="cursor: hand !important; cursor: pointer !important;"
																				onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ');"
																				alt="Eliminar"
																				align="bottom" />
																			</div>';
					} else
						$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
					$aux++;
				}
				$cont++;
			}

			$_SESSION['aDataGirdDir'] = $aDatos;
			$sHtml = mostrar_grid_dir($idempresa, $idsucursal);
			$oReturn->assign("divDir", "innerHTML", $sHtml);
		} // fin fif		
	}

	// DIARIO	
	$aLabelGrid = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad',
		'DIR',
		'RET'
	);

	unset($aDataGrid);
	$contador   = 0;
	$aDataGrid  = $_SESSION['aDataGirdDiar'];
	$contador   = count($aDataGrid);
	unset($aDatos);
	if ($contador > 0) {
		if ($id_di >= 0) {
			unset($aDataGrid[$id_di]);
			$aDataGrid = array_values($aDataGrid);
			$cont = 0;
			foreach ($aDataGrid as $aValues) {
				$aux = 0;
				foreach ($aValues as $aVal) {
					if ($aux == 0) {
						$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . ($cont + 1) . '</div>';
					} elseif ($aux == 9) {
						$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
																	<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/pencil.png"
																	title = "Presione aqui para Eliminar"
																	style="cursor: hand !important; cursor: pointer !important;"
																	onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ');"
																	alt="Eliminar"
																	align="bottom" />
																</div>';
					} else
						$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
					$aux++;
				}
				$cont++;
			}
			$_SESSION['aDataGirdDiar'] = $aDatos;
			$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
			$oReturn->assign("divDiario", "innerHTML", $sHtml);
		}
	}

	// RETENCION
	if ($id_ret >= 0) {
		$aLabelGrid = array(
			'Fila',
			'Cta Ret',
			'Cliente',
			'Factura',
			'Ret Cliente',
			'Porc(%)',
			'Base Impo',
			'Valor',
			'N.- Retencion',
			'Detalle',
			'Cotizacion',
			'Debito Moneda Local',
			'Credito Moneda Local',
			'Debito Moneda Ext',
			'Credito Moneda Ext',
			'Modificar',
			'Eliminar',
			'DI'
		);
		unset($aDataGrid);
		$contador   = 0;

		$aDataGrid  = $_SESSION['aDataGirdRet'];
		$contador   = count($aDataGrid);

		unset($aDatos);
		if ($contador > 1) {
			unset($aDataGrid[$id_ret]);
			$aDataGrid = array_values($aDataGrid);
			$cont = 0;
			foreach ($aDataGrid as $aValues) {
				$aux = 0;
				foreach ($aValues as $aVal) {
					if ($aux == 0) {
						$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . ($cont + 1) . '</div>';
					} elseif ($aux == 15) {
						$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
																	<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/pencil.png"
																	title = "Presione aqui para Eliminar"
																	style="cursor: hand !important; cursor: pointer !important;"
																	onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ');"
																	alt="Eliminar"
																	align="bottom" />
																</div>';
					} else
						$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
					$aux++;
				}
				$cont++;
			}

			$_SESSION['aDataGirdRet'] = $aDatos;
			$sHtml = mostrar_grid_ret($idempresa, $idsucursal);
			$oReturn->assign("divRet", "innerHTML", $sHtml);
		} else {
			unset($aDataGrid[0]);
			$_SESSION['aDataGirdRet'] = $aDatos;
			$sHtml = "";
			$oReturn->assign("divRet", "innerHTML", $sHtml);
		}
	} // fin reten if

	// TOTAL DIARIO
	$oReturn->script("total_diario();");

	return $oReturn;
}

// DIARIO
function agrega_modifica_grid_dia($nTipo = 0, $aForm = '', $id = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$aDataDiar = $_SESSION['aDataGirdDiar'];

	$aLabelDiar = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad'
	);
	$oReturn = new xajaxResponse();

	// VARIABLES
	$cod_cta    = $aForm["cod_cta"];
	$nom_cta    = $aForm["nom_cta"];
	$val_cta    = str_replace(",", "", $aForm['val_cta']);
	$idempresa  = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm["sucursal"];
	$detalle    = $aForm["detalla_diario"];
	$ccosn_cod  = $aForm["ccosn"];
	$act_cod    = $aForm["actividad"];
	$documento  = $aForm["documento"];
	$mone_cod   = $aForm["moneda"];


	if (!empty($cod_cta)) {


		$sql        = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
		$mone_base  = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

		if ($mone_cod == $mone_base) {
			$sql      = "select pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
			$mone_extr = consulta_string_func($sql, 'pcon_seg_mone', $oIfx, '');

			$sql = "select tcam_valc_tcam from saetcam where
					mone_cod_empr = $idempresa and
					tcam_cod_mone = $mone_extr and
					tcam_fec_tcam in (
										select max(tcam_fec_tcam)  from saetcam where
												mone_cod_empr = $idempresa and
												tcam_cod_mone = $mone_extr
									)  ";

			$coti = $aForm["cotizacion_ext"]; //consulta_string_func($sql, 'tcam_val_tcam', $oIfx, 0);
		} else {
			$coti = $aForm["cotizacion"];
		}

		$tipo     = $aForm['crdb'];

		if ($tipo == 'CR') {
			$cred = $val_cta;
			$deb  = 0;
		} elseif ($tipo == 'DB') {
			$cred = 0;
			$deb  = $val_cta;
		}

		if ($nTipo == 0) {
			// DIARIO
			$contd = count($aDataDiar);

			$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
			$aDataDiar[$contd][$aLabelDiar[1]] = $cod_cta;
			$aDataDiar[$contd][$aLabelDiar[2]] = $nom_cta;
			$aDataDiar[$contd][$aLabelDiar[3]] = $documento;
			$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

			if ($mone_cod == $mone_base) {
				// moneda local
				$cre_tmp = 0;
				$deb_tmp = 0;
				if ($coti > 0) {
					$cre_tmp = round(($cred / $coti), 2);
				}

				if ($coti > 0) {
					$deb_tmp = round(($deb / $coti), 2);
				}

				$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
				$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
				$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
				$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
			} else {
				// moneda extra         
				$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
				$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
				$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
				$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
			}

			$vacio = '-1';
			$aDataDiar[$contd][$aLabelDiar[9]] = '';
			$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
														title = "Presione aqui para Eliminar"
														style="cursor: hand !important; cursor: pointer !important;"
														onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1, 2);"
														alt="Eliminar"
														align="bottom" />';
			$aDataDiar[$contd][$aLabelDiar[11]] = '';
			$aDataDiar[$contd][$aLabelDiar[12]] = '';
			$aDataDiar[$contd][$aLabelDiar[13]] = '';
			$aDataDiar[$contd][$aLabelDiar[14]] = '';
			$aDataDiar[$contd][$aLabelDiar[15]] = '';
			$aDataDiar[$contd][$aLabelDiar[16]] = '';
			$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
			$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_cod;
			$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
			//$oReturn->alert('asas');
		}

		// DIARIO
		$sHtml = '';
		$_SESSION['aDataGirdDiar'] = $aDataDiar;
		$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
		$oReturn->assign("divDiario", "innerHTML", $sHtml);


		// TOTAL DIARIO
		$oReturn->script("total_diario();");

		$oReturn->script("cerrar_ventana();");

		// --------------------------------------------------------------------------
		// CUENTAS ADICIONALES PERU
		// --------------------------------------------------------------------------
		$empr_cod_pais = $_SESSION['U_PAIS_COD'];
		$sql_codInter_pais = "SELECT pais_codigo_inter from saepais where pais_cod_pais = $empr_cod_pais;";
		$codigo_pais = consulta_string_func($sql_codInter_pais, 'pais_codigo_inter', $oIfx, 0);

		$sql_pcon = "SELECT pcon_cue_niif from saepcon WHERE pcon_cod_empr = $idempresa;";
		$pcon_cue_niif = consulta_string_func($sql_pcon, 'pcon_cue_niif', $oIfx, 0);

		if ($codigo_pais == 51 && $pcon_cue_niif == 'S') {
			// Cuando es Peru baja dos cuentas adicionales: cuan_ana_deb y cuen_anal_cre
			$sql_cuen = "SELECT cuen_ana_deb, cuen_ana_cre from saecuen where cuen_cod_cuen = '$cod_cta';";
			$cuen_ana_deb = consulta_string_func($sql_cuen, 'cuen_ana_deb', $oIfx, 0);
			$cuen_ana_cre = consulta_string_func($sql_cuen, 'cuen_ana_cre', $oIfx, 0);
			$existe_cuenta = 'N';
			foreach ($aDataDiar as $key => $value) {
				if ($value['Cuenta'] == $cuen_ana_deb || $value['Cuenta'] == $cuen_ana_cre) {
					$existe_cuenta = 'S';
				}
			}
			if ($existe_cuenta == 'N') {

				$valor_grab12t = $aForm['valor_grab12t'];
				$valor_grab0t = $aForm['valor_grab0t'];
				$icet = $aForm['icet'];
				$total_adicional = round($valor_grab12t + $valor_grab0t + $icet, 2);

				// --------------------------------------
				// DEBITO
				// --------------------------------------

				$sql_cuen_deb = "SELECT cuen_nom_cuen from saecuen where cuen_cod_cuen = '$cuen_ana_deb';";
				$cuen_nom_cuen_deb = consulta_string_func($sql_cuen_deb, 'cuen_nom_cuen', $oIfx, 0);
				$oReturn->assign("cod_cta", "value", $cuen_ana_deb);
				$oReturn->assign("nom_cta", "value", $cuen_nom_cuen_deb);
				$oReturn->assign("detalla_diario", "value", 'CUENTA ADICIONAL DEBITO');
				// CR => Credito || DB => Debito
				$oReturn->assign("crdb", "value", 'DB');
				$oReturn->assign("val_cta", "value", $total_adicional);
				$oReturn->script("anadir_dasi();");

				// --------------------------------------
				// CREDITO
				// --------------------------------------


				$sql_cuen_cre = "SELECT cuen_nom_cuen from saecuen where cuen_cod_cuen = '$cuen_ana_cre';";
				$cuen_nom_cuen_cre = consulta_string_func($sql_cuen_cre, 'cuen_nom_cuen', $oIfx, 0);
				$oReturn->assign("cod_cta", "value", $cuen_ana_cre);
				$oReturn->assign("nom_cta", "value", $cuen_nom_cuen_cre);
				$oReturn->assign("detalla_diario", "value", 'CUENTA ADICIONAL CREDITO');
				// CR => Credito || DB => Debito
				$oReturn->assign("crdb", "value", 'CR');
				$oReturn->assign("val_cta", "value", $total_adicional);
				$oReturn->script("anadir_dasi();");
			}
		}

		// --------------------------------------------------------------------------
		// FIN CUENTAS ADICIONALES PERU
		// --------------------------------------------------------------------------

		$oReturn->assign("cod_cta", "value", '');
		$oReturn->assign("nom_cta", "value", '');
		$oReturn->assign("val_cta", "value", '');
	}






	return $oReturn;
}






function mostrar_grid_dia($idempresa, $idsucursal)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN, $DSN_Ifx;

	$oCnx = new Dbo();
	$oCnx->DSN = $DSN;
	$oCnx->Conectar();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa  = $_SESSION['U_EMPRESA'];
	$aDataGrid  = $_SESSION['aDataGirdDiar'];

	$aLabelGrid = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad',
		'DIR',
		'RET'
	);
	$cont    = 0;
	$tot_cre = 0;
	$tot_cre_ext = 0;
	$tot_deb = 0;
	$tot_deb_ext = 0;
	if (count($aDataGrid) > 0) {
		foreach ($aDataGrid as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 0) {
					$aDatos[$cont][$aLabelGrid[$aux]] = ($cont + 1);
				} elseif ($aux == 1) {
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				} elseif ($aux == 2) {
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				} elseif ($aux == 4) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 6), 6, '.', ',') . '</div>';
				} elseif ($aux == 5) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
					$tot_deb += $aVal;
				} elseif ($aux == 6) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
					$tot_cre += $aVal;
				} elseif ($aux == 7) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
					$tot_deb_ext += $aVal;
				} elseif ($aux == 8) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
					$tot_cre_ext += $aVal;
				} elseif ($aux == 9) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/pencil.png"
																		title = "Presione aqui para Eliminar"
																		style="cursor: hand !important; cursor: pointer !important;"
																		onclick="javascript:modificar_valor(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ');"
																		alt="Eliminar"
																		align="bottom" />';
				} elseif ($aux == 11) {
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				} elseif ($aux == 12) {
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				} elseif ($aux == 13) {
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				} elseif ($aux == 14) {
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				} elseif ($aux == 15) {
					// FORMATO
					if (empty($aVal)) {
						$aVal = 0;
					}
					$sql = "select  ftrn_des_ftrn  from saeftrn where
										ftrn_cod_empr = $idempresa and
										ftrn_cod_ftrn = '$aVal'  ";
					$ftrn_nom = consulta_string_func($sql, 'ftrn_des_ftrn', $oIfx, 0);
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $ftrn_nom . '</div>';
				} elseif ($aux == 16) {
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				} elseif ($aux == 17) {
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				} elseif ($aux == 18) {
					// CENTRO COSTO 
					$sql = "select ccosn_cod_ccosn, ( ccosn_nom_ccosn || ' - ' || ccosn_cod_ccosn ) as  ccosn_nom_ccosn from saeccosn where
										ccosn_cod_empr  = $idempresa and
										ccosn_mov_ccosn = 1 and
										ccosn_cod_ccosn = '$aVal' ";
					$ccosn_nom = consulta_string_func($sql, 'ccosn_nom_ccosn', $oIfx, '');
					$aDatos[$cont][$aLabelGrid[$aux]] = $ccosn_nom;
				} elseif ($aux == 19) {
					// CENTRO ACTIVIDAD
					$sql = "select cact_cod_cact , ( cact_nom_cact ||  ' - ' || cact_cod_cact ) as cact_nom_cact from saecact where
											cact_cod_empr = $idempresa and
											cact_cod_cact = '$aVal'  ";
					$act_nom = consulta_string_func($sql, 'cact_nom_cact', $oIfx, '');
					$aDatos[$cont][$aLabelGrid[$aux]] = $act_nom;
				} else
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				$aux++;
			}
			$cont++;
		}
	}

	$array = array('', '', '', '', '', $tot_deb, $tot_cre, $tot_deb_ext, $tot_cre_ext);

	return genera_grid_dir($aDatos, $aLabelGrid, 'DIARIO', 99, '', $array);
}

function elimina_detalle_dia($id = null, $idempresa, $idsucursal)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$aLabelDiar = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito',
		'Credito',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad',
		'DIR',
		'RET'
	);

	$aDataGrid = $_SESSION['aDataGirdDiar'];
	$contador = count($aDataGrid);
	if ($contador > 1) {
		unset($aDataGrid[$id]);
		$_SESSION['aDataGirdDiar'] = $aDataGrid;

		$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
		$oReturn->assign("divDiario", "innerHTML", $sHtml);
	} else {
		unset($aDataGrid[0]);
		$_SESSION['aDataGirdDiar'] = $aDatos;
		$sHtml = "";
		$oReturn->assign("divDiario", "innerHTML", $sHtml);

		$aDataGridDir = $_SESSION['aDataGirdDir'];
		$contadorDir = count($aDataGridDir);
		if ($contadorDir > 1) {
			/*unset($aDataGridDir[$id]);
            $_SESSION['aDataGirdDir'] = $aDataGridDir;
            $sHtml = mostrar_grid_dir($idempresa, $idsucursal);
            $oReturn->assign("divDir","innerHTML",$sHtml);*/
		} else {
			unset($aDataGridDir[0]);
			$_SESSION['aDataGirdDir'] = $aDatos;
			$sHtml = "";
			$oReturn->assign("divDir", "innerHTML", $sHtml);
		}
	}


	return $oReturn;
}


// RETENCION
function agrega_modifica_grid_ret($nTipo = 0, $aForm = '', $id = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$aDataGrid = $_SESSION['aDataGirdRet'];
	$aDataDiar = $_SESSION['aDataGirdDiar'];

	$aLabelGrid = array(
		'Fila',
		'Cta Ret',
		'Cliente',
		'Factura',
		'Ret Cliente',
		'Porc(%)',
		'Base Impo',
		'Valor',
		'N.- Retencion',
		'Detalle',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'DI'
	);

	$aLabelDiar = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad',
		'DIR',
		'RET'
	);

	$oReturn = new xajaxResponse();

	// VARIABLES
	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm["sucursal"];
	$mone_cod  = $aForm["moneda"];
	$clpv_cod  = $aForm['cliente'];
	$factura   = $aForm['factura'];
	$retencion = $aForm['num_rete'];
	$det_ret   = $aForm['detalle'];
	$cta_cre   = $aForm["cta_cre"];

	$sql       = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
	$mone_base = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

	if ($mone_cod == $mone_base) {
		$sql      = "select pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
		$mone_extr = consulta_string_func($sql, 'pcon_seg_mone', $oIfx, '');

		$coti = $aForm["cotizacion_ext"];
	} else {
		$coti = $aForm["cotizacion"];
	}

	$porc_ret  = $aForm["ret_porc"];
	$base_ret  = str_replace(",", "", $aForm['ret_base']);
	$val_ret   = round(($base_ret * $porc_ret / 100), 2);
	$cod_ret   = $aForm["cod_ret"];


	$valDirCre = 0;
	$valDiaCre = 0;
	$valDirDeb = 0;
	$valDiaDeb = 0;

	$val_deb   = 0;
	$val_cre   = 0;

	$sql = "select cuen_nom_cuen from saecuen where 
				cuen_cod_empr = $idempresa and
				cuen_cod_cuen = '$cta_cre' ";
	$val_cre 	= $val_ret;
	$valDirCre 	= $val_ret;
	$valDiaCre 	= $val_ret;
	/*
	if($tipo=='CR'){
		// CREDITO
		$sql = "select cuen_nom_cuen from saecuen where 
				cuen_cod_empr = $idempresa and
				cuen_cod_cuen = '$cta_cre' ";      
		$val_cre 	= $val_ret;
		$valDirCre 	= $val_ret;
		$valDiaCre 	= $val_ret;
	}else{
		// DEBITO
		$sql = "select cuen_nom_cuen from saecuen where 
				cuen_cod_empr = $idempresa and
				cuen_cod_cuen = '$cta_deb' ";
		$val_deb 	= $val_ret;
		$valDirDeb 	= $val_ret;
		$valDiaDeb 	= $val_ret;
	}*/
	$cuen_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

	$cont = count($aDataGrid);
	$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
	$aDataGrid[$cont][$aLabelGrid[1]] = $cod_ret;
	$aDataGrid[$cont][$aLabelGrid[2]] = $clpv_cod;
	$aDataGrid[$cont][$aLabelGrid[3]] = $factura;
	$aDataGrid[$cont][$aLabelGrid[4]] = $clpv_cod;
	$aDataGrid[$cont][$aLabelGrid[5]] = $porc_ret;
	$aDataGrid[$cont][$aLabelGrid[6]] = $base_ret;
	$aDataGrid[$cont][$aLabelGrid[7]] = $val_ret;
	$aDataGrid[$cont][$aLabelGrid[8]] = number_format($retencion, 4, '.', '');
	$aDataGrid[$cont][$aLabelGrid[9]] = $det_ret;
	$aDataGrid[$cont][$aLabelGrid[10]] = $coti;

	if ($mone_cod == $mone_base) {
		// moneda local
		$cre_tmp = 0;
		$deb_tmp = 0;
		if ($coti > 0) {
			$cre_tmp = round(($valDirCre / $coti), 2);
		}

		if ($coti > 0) {
			$deb_tmp = round(($valDirDeb / $coti), 2);
		}

		$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb;
		$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre;
		$aDataGrid[$cont][$aLabelGrid[13]] = $deb_tmp;
		$aDataGrid[$cont][$aLabelGrid[14]] = $cre_tmp;
	} else {
		// moneda extra

		$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb * $coti;
		$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre * $coti;

		$aDataGrid[$cont][$aLabelGrid[13]] = $valDirDeb;
		$aDataGrid[$cont][$aLabelGrid[14]] = $valDirCre;
	}

	$aDataGrid[$cont][$aLabelGrid[15]] = '';

	$contd = count($aDataDiar);
	$aDataGrid[$cont][$aLabelGrid[16]] = '<div align="center">
											<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
											title = "Presione aqui para Eliminar"
											style="cursor: hand !important; cursor: pointer !important;"
											onclick="javascript:xajax_elimina_detalle_ret(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ');"
											alt="Eliminar"
											align="bottom" />
										</div>';
	$aDataGrid[$cont][$aLabelGrid[17]] = $contd;

	// DIARIO			
	$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
	$aDataDiar[$contd][$aLabelDiar[1]] = $cta_cre . $cta_deb;
	$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
	$aDataDiar[$contd][$aLabelDiar[3]] = $doc;
	$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

	if ($mone_cod == $mone_base) {
		// moneda local
		$cre_tmp = 0;
		$deb_tmp = 0;
		if ($coti > 0) {
			$cre_tmp = round(($valDirCre / $coti), 2);
		}

		if ($coti > 0) {
			$deb_tmp = round(($valDirDeb / $coti), 2);
		}

		$aDataDiar[$contd][$aLabelDiar[5]] = number_format($valDiaDeb, 4, '.', '');
		$aDataDiar[$contd][$aLabelDiar[6]] = number_format($valDiaCre, 4, '.', '');
		$aDataDiar[$contd][$aLabelDiar[7]] = number_format($deb_tmp, 4, '.', '');
		$aDataDiar[$contd][$aLabelDiar[8]] = number_format($cre_tmp, 4, '.', '');
	} else {
		// moneda extra
		$aDataDiar[$contd][$aLabelDiar[5]] = number_format($valDiaDeb * $coti, 4, '.', '');
		$aDataDiar[$contd][$aLabelDiar[6]] = number_format($valDiaCre * $coti, 4, '.', '');
		$aDataDiar[$contd][$aLabelDiar[7]] = number_format($valDirDeb, 4, '.', '');
		$aDataDiar[$contd][$aLabelDiar[8]] = number_format($valDirCre, 4, '.', '');
	}

	$vacio = '-1';
	$aDataDiar[$contd][$aLabelDiar[9]] 	= '';
	$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
															title = "Presione aqui para Eliminar"
															style="cursor: hand !important; cursor: pointer !important;"
															onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', ' . $cont . '  );"
															alt="Eliminar"
															align="bottom" />';
	$aDataDiar[$contd][$aLabelDiar[11]]	= '';
	$aDataDiar[$contd][$aLabelDiar[12]]	= '';
	$aDataDiar[$contd][$aLabelDiar[13]]	= '';
	$aDataDiar[$contd][$aLabelDiar[14]]	= '';
	$aDataDiar[$contd][$aLabelDiar[15]]	= '';
	$aDataDiar[$contd][$aLabelDiar[16]]	= '';
	$aDataDiar[$contd][$aLabelDiar[17]]	= $det_dir;
	$aDataDiar[$contd][$aLabelDiar[18]] = '';
	$aDataDiar[$contd][$aLabelDiar[19]] = '';
	$aDataDiar[$contd][$aLabelDiar[20]] = '';
	$aDataDiar[$contd][$aLabelDiar[21]] = $cont;


	// RETENCION
	$_SESSION['aDataGirdRet'] = $aDataGrid;
	$sHtml = mostrar_grid_ret($idempresa, $idsucursal);
	$oReturn->assign("divRet", "innerHTML", $sHtml);

	// DIARIO
	$sHtml = '';
	$_SESSION['aDataGirdDiar'] = $aDataDiar;
	$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
	$oReturn->assign("divDiario", "innerHTML", $sHtml);

	// TOTAL DIARIO
	$oReturn->script("total_diario();");

	return $oReturn;
}

function mostrar_grid_ret($idempresa, $idsucursal)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$aDataGrid  = $_SESSION['aDataGirdRet'];
	$aLabelGrid = array(
		'Fila',
		'Cta Ret',
		'Cliente',
		'Factura',
		'Ret Cliente',
		'Porc(%)',
		'Base Impo',
		'Valor',
		'N.- Retencion',
		'Detalle',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'DI'
	);
	$cont        = 0;
	$tot_cre     = 0;
	$tot_deb     = 0;
	$tot_cre_ext = 0;
	$tot_deb_ext = 0;
	foreach ($aDataGrid as $aValues) {
		$aux     = 0;
		foreach ($aValues as $aVal) {
			if ($aux == 0) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . ($cont + 1) . '</div>';
			} elseif ($aux == 2) {
				$sql = "select  clpv_nom_clpv from saeclpv where clpv_cod_clpv = $aVal and clpv_cod_empr = $idempresa ";
				$clpv_nom = consulta_string_func($sql, 'clpv_nom_clpv', $oIfx, '');
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $clpv_nom . '</div>';
			} elseif ($aux == 3) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
			} elseif ($aux == 4) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
			} elseif ($aux == 5) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 6) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . $aVal . '</div>';
			} elseif ($aux == 7) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
			} elseif ($aux == 8) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
			} elseif ($aux == 9) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="left">' . $aVal . '</div>';
			} elseif ($aux == 10) {		// COTIZACION
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 6), 6, '.', ',') . '</div>';
			} elseif ($aux == 11) {		// DEBITO Moneda
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
				$tot_deb += $aVal;
			} elseif ($aux == 12) {		// Credito Moneda
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
				$tot_cre += $aVal;
			} elseif ($aux == 13) {		// DEBITO Moneda Ext
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
				$tot_deb_ext += $aVal;
			} elseif ($aux == 14) {	// Credito Moneda Ext
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . number_format(round($aVal, 2), 2, '.', ',') . '</div>';
				$tot_cre_ext += $aVal;
			} elseif ($aux == 15) {
				$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
                                                                <img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/pencil.png"
                                                                title = "Presione aqui para Eliminar"
                                                                style="cursor: hand !important; cursor: pointer !important;"
                                                                onclick="javascript:xajax_elimina_detalle_ret(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ');"
                                                                alt="Eliminar"
                                                                align="bottom" />
                                                            </div>';
			} else
				$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
			$aux++;
		}
		$cont++;
	}
	$array = array('', '', '', '', '', '', '', '', '', '', '', $tot_deb, $tot_cre, $tot_deb_ext, $tot_cre_ext, '', '');
	return genera_grid_dir($aDatos, $aLabelGrid, 'RETENCION', 99, '', $array);
}

function elimina_detalle_ret($id = null, $idempresa, $idsucursal, $id_di)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oReturn = new xajaxResponse();

	$aLabelGrid = array(
		'Fila',
		'Cta Ret',
		'Cliente',
		'Factura',
		'Ret Cliente',
		'Porc(%)',
		'Base Impo',
		'Valor',
		'N.- Retencion',
		'Detalle',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'DI'
	);

	$aDataGrid = $_SESSION['aDataGirdRet'];

	$contador = count($aDataGrid);
	if ($contador > 1) {
		unset($aDataGrid[$id]);
		$aDataGrid = array_values($aDataGrid);
		$cont 	   = 0;

		foreach ($aDataGrid as $aValues) {
			$aux     = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 0) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . ($cont + 1) . '</div>';
				} elseif ($aux == 15) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
																			<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/pencil.png"
																			title = "Presione aqui para Eliminar"
																			style="cursor: hand !important; cursor: pointer !important;"
																			onclick="javascript:xajax_elimina_detalle_ret(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ');"
																			alt="Eliminar"
																			align="bottom" />
																		</div>';
				} else
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				$aux++;
			}
			$cont++;
		}

		$_SESSION['aDataGirdRet'] = $aDatos;
		$sHtml = mostrar_grid_ret($idempresa, $idsucursal);
		$oReturn->assign("divRet", "innerHTML", $sHtml);
	} else {
		unset($aDataGrid[0]);
		$_SESSION['aDataGirdRet'] = $aDatos;
		$sHtml = "";
		$oReturn->assign("divRet", "innerHTML", $sHtml);
	}


	// DIARIO						
	$aLabelGrid = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito',
		'Credito',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad',
		'DIR',
		'RET'
	);

	unset($aDataGrid);
	$contador   = 0;
	$aDataGrid  = $_SESSION['aDataGirdDiar'];
	$contador   = count($aDataGrid);
	unset($aDatos);
	if ($contador > 1) {
		unset($aDataGrid[$id_di]);
		$aDataGrid = array_values($aDataGrid);
		$cont = 0;
		foreach ($aDataGrid as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 0) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="right">' . ($cont + 1) . '</div>';
				} elseif ($aux == 7) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
                                                                <img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/pencil.png"
                                                                title = "Presione aqui para Eliminar"
                                                                style="cursor: hand !important; cursor: pointer !important;"
                                                                onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ');"
                                                                alt="Eliminar"
                                                                align="bottom" />
                                                            </div>';
				} elseif ($aux == 8) {
					$aDatos[$cont][$aLabelGrid[$aux]] = '<div align="center">
                                                                <img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
                                                                title = "Presione aqui para Eliminar"
                                                                style="cursor: hand !important; cursor: pointer !important;"
                                                                onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ');"
                                                                alt="Eliminar"
                                                                align="bottom" />
                                                            </div>';
				} else
					$aDatos[$cont][$aLabelGrid[$aux]] = $aVal;
				$aux++;
			}
			$cont++;
		}

		$_SESSION['aDataGirdDiar'] = $aDatos;
		$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
		$oReturn->assign("divDiario", "innerHTML", $sHtml);
	} else {
		unset($aDataGrid[0]);
		$_SESSION['aDataGirdDiar'] = $aDatos;
		$sHtml = "";
		$oReturn->assign("divDiario", "innerHTML", $sHtml);
	}

	// TOTAL DIARIO
	$oReturn->script("total_diario();");

	return $oReturn;
}

function cargar_coti($aForm = '')
{
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	//variables del formulario
	$idempresa = $_SESSION['U_EMPRESA'];
	$mone_cod  = $aForm['moneda'];
	//PAIS
	$S_PAIS_API_SRI = $_SESSION['S_PAIS_API_SRI'];

	//PERU: PARA EL CMABIO DE MONEDA SE CONSIDERA LA FECHA DE EMISION DEL COMPORBANTE
	if ($S_PAIS_API_SRI == '51') {
		$fech  = $aForm['fecha_emision'];
	} else {
		$fech  = $aForm['fecha_contable'];
	}
	//$fech=date('m/d/Y');
	$sql = "select tcam_val_tcam from saetcam where
                mone_cod_empr = $idempresa and
                tcam_cod_mone = $mone_cod and
                tcam_fec_tcam in (
                                    select max(tcam_fec_tcam)  from saetcam where
                                            mone_cod_empr = $idempresa and
                                            tcam_cod_mone = $mone_cod and tcam_fec_tcam<='$fech'
                                )  ";

	$coti = consulta_string_func($sql, 'tcam_val_tcam', $oIfx, 0);
	$oReturn->assign("cotizacion", "value", $coti);
	$sql = "select max(mone_cod_mone) as moneda from saemone where mone_cod_empr=$idempresa";
	$moneda = consulta_string_func($sql, 'moneda', $oIfx, 0);
	$sql = "select tcam_val_tcam from saetcam where
                mone_cod_empr = $idempresa and
                tcam_cod_mone = $moneda and
                tcam_fec_tcam in (
                                    select max(tcam_fec_tcam)  from saetcam where
                                            mone_cod_empr = $idempresa and
                                            tcam_cod_mone = $moneda and tcam_fec_tcam<='$fech'
                                )  ";

	$coti_e = consulta_string_func($sql, 'tcam_val_tcam', $oIfx, 0);
	$oReturn->assign("cotizacion_ext", "value", $coti_e);
	return $oReturn;
}

function retencion_ingreso(
	$idempresa,
	$idsucursal,
	$cod_ret,
	$clpv_cod,
	$fact_ret,
	$porc_ret,
	$base_ret,
	$val_ret,
	$num_ret,
	$det_ret,
	$coti,
	$cta_cre,
	$cuen_nom,
	$det_dir,
	$mone_cod,
	$mone_base,
	$valDirDeb,
	$valDirCre
) {

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	$aDataGrid = $_SESSION['aDataGirdRet'];
	$aDataDiar = $_SESSION['aDataGirdDiar'];

	// RETENCION
	$cont = count($aDataGrid);
	$aDataGrid[$cont][$aLabelGrid[0]] = floatval($cont);
	$aDataGrid[$cont][$aLabelGrid[1]] = $cod_ret;
	$aDataGrid[$cont][$aLabelGrid[2]] = $clpv_cod;
	$aDataGrid[$cont][$aLabelGrid[3]] = $fact_ret;
	$aDataGrid[$cont][$aLabelGrid[4]] = $clpv_cod;
	$aDataGrid[$cont][$aLabelGrid[5]] = $porc_ret;
	$aDataGrid[$cont][$aLabelGrid[6]] = $base_ret;
	$aDataGrid[$cont][$aLabelGrid[7]] = $val_ret;
	$aDataGrid[$cont][$aLabelGrid[8]] = $num_ret;
	$aDataGrid[$cont][$aLabelGrid[9]] = $det_ret;
	$aDataGrid[$cont][$aLabelGrid[10]] = $coti;

	if ($mone_cod == $mone_base) {
		// moneda local
		$cre_tmp = 0;
		$deb_tmp = 0;
		if ($coti > 0) {
			$cre_tmp = round(($valDirCre / $coti), 2);
		}

		if ($coti > 0) {
			$deb_tmp = round(($valDirDeb / $coti), 2);
		}

		$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb;
		$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre;
		$aDataGrid[$cont][$aLabelGrid[13]] = $deb_tmp;
		$aDataGrid[$cont][$aLabelGrid[14]] = $cre_tmp;
	} else {
		// moneda extra

		$aDataGrid[$cont][$aLabelGrid[11]] = $valDirDeb * $coti;
		$aDataGrid[$cont][$aLabelGrid[12]] = $valDirCre * $coti;

		$aDataGrid[$cont][$aLabelGrid[13]] = $valDirDeb;
		$aDataGrid[$cont][$aLabelGrid[14]] = $valDirCre;
	}

	$aDataGrid[$cont][$aLabelGrid[15]] = '';

	$contd = count($aDataDiar);
	$aDataGrid[$cont][$aLabelGrid[16]] = '<div align="center">
											<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
											title = "Presione aqui para Eliminar"
											style="cursor: hand !important; cursor: pointer !important;"
											onclick="javascript:xajax_elimina_detalle_ret(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ');"
											alt="Eliminar"
											align="bottom" />
										</div>';
	$aDataGrid[$cont][$aLabelGrid[17]] = $contd;

	// DIARIO			
	$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
	$aDataDiar[$contd][$aLabelDiar[1]] = $cta_cre . $cta_deb;
	$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
	$aDataDiar[$contd][$aLabelDiar[3]] = $doc;
	$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

	if ($mone_cod == $mone_base) {
		// moneda local
		$cre_tmp = 0;
		$deb_tmp = 0;
		if ($coti > 0) {
			$cre_tmp = round(($valDirCre / $coti), 2);
		}

		if ($coti > 0) {
			$deb_tmp = round(($valDirDeb / $coti), 2);
		}

		$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb;
		$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre;
		$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
		$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
	} else {
		// moneda extra
		$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb * $coti;
		$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre * $coti;
		$aDataDiar[$contd][$aLabelDiar[7]] = $valDirDeb;
		$aDataDiar[$contd][$aLabelDiar[8]] = $valDirCre;
	}

	$vacio = '-1';
	$aDataDiar[$contd][$aLabelDiar[9]] 	= '';
	$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
															title = "Presione aqui para Eliminar"
															style="cursor: hand !important; cursor: pointer !important;"
															onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', ' . $cont . '  );"
															alt="Eliminar"
															align="bottom" />';
	$aDataDiar[$contd][$aLabelDiar[11]]	= '';
	$aDataDiar[$contd][$aLabelDiar[12]]	= '';
	$aDataDiar[$contd][$aLabelDiar[13]]	= '';
	$aDataDiar[$contd][$aLabelDiar[14]]	= '';
	$aDataDiar[$contd][$aLabelDiar[15]]	= '';
	$aDataDiar[$contd][$aLabelDiar[16]]	= '';
	$aDataDiar[$contd][$aLabelDiar[17]]	= $det_dir;
	$aDataDiar[$contd][$aLabelDiar[18]] = '';
	$aDataDiar[$contd][$aLabelDiar[19]] = '';
	$aDataDiar[$contd][$aLabelDiar[20]] = '';
	$aDataDiar[$contd][$aLabelDiar[21]] = $cont;

	$_SESSION['aDataGirdRet'] = $aDataGrid;
	$_SESSION['aDataGirdDiar'] = $aDataDiar;

	return 'OK';
}

// MODIFICAR VALOR
function form_modificar_valor($id, $idempresa, $idsucursal, $aForm = '')
{

	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo();
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$fu = new Formulario();
	$fu->DSN = $DSN;

	$oReturn = new xajaxResponse();

	$mone_cod = $aForm['moneda'];
	$sql      = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
	$mone_base = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

	$aDataGrid  = $_SESSION['aDataGirdDiar'];

	$opcion = 0;
	$title  = '';
	if ($mone_cod != $mone_base) {
		// MONEDA LOCAL
		$debito  = $aDataGrid[$id]['Debito Moneda Local'];
		$credito = $aDataGrid[$id]['Credito Moneda Local'];
		$opcion  = 1;
		$title   = 'MONEDA LOCAL';
	} else {
		// MONEDA EXTRANJERA
		$debito  = $aDataGrid[$id]['Debito Moneda Ext'];
		$credito = $aDataGrid[$id]['Credito Moneda Ext'];
		$opcion  = 2;
		$title   = 'MONEDA EXTRANJERA';
	}

	$fu->AgregarCampoNumerico('debito_mod',  'Debito|left', false, $debito,  100, 100);
	$fu->AgregarCampoNumerico('credito_mod', 'Credito|left', false, $credito, 100, 100);

	$sHtml .= '<table class="table table-striped table-condensed" style="width: 90%; margin-bottom: 0px;" align="center">';
	$sHtml .= '<tr height="20">';
	$sHtml .= '<td>' . $fu->ObjetoHtmlLBL('debito_mod') . '</td>';
	$sHtml .= '<td>' . $fu->ObjetoHtml('debito_mod') . '</td>';
	$sHtml .= '</tr>';
	$sHtml .= '<tr height="20">';
	$sHtml .= '<td>' . $fu->ObjetoHtmlLBL('credito_mod') . '</td>';
	$sHtml .= '<td>' . $fu->ObjetoHtml('credito_mod') . '</td>';
	$sHtml .= '</tr>';
	$sHtml .= '</table>';

	$modal  = '<div id="mostrarmodal5" class="modal fade" role="dialog" >
                <div class="modal-dialog modal-lg" style="width: 400px;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">' . $title . '</h4>
                        </div>
                        <div class="modal-body">';
	$modal .= $sHtml;
	$modal .= '          </div>
                        <div class="modal-footer">
							<div class="btn btn-primary btn-sm" onClick="javascript:procesar( ' . $id . ', ' . $opcion . '  )" >
								<span class="glyphicon glyphicon-cog"></span>
								Procesar
							</div>
                            <button type="button" class="btn btn-danger" data-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
             </div>';

	$oReturn->assign("miModal_Diario", "innerHTML", $modal);
	$oReturn->script("abre_modal_modi();");

	return $oReturn;
}


function modificar_valor($id, $opcion, $aForm = '')
{
	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo();
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	$aDataGrid  = $_SESSION['aDataGirdDiar'];
	$aLabelDiar = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad',
		'DIR',
		'RET'
	);

	$idempresa  = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm['sucursal'];
	$credito    = $aForm['credito_mod'];
	$debito     = $aForm['debito_mod'];

	if ($opcion == 1) {
		// MONEDA LOCAL		
		$aDataGrid[$id][$aLabelDiar[5]] = $debito;
		$aDataGrid[$id][$aLabelDiar[6]] = $credito;
	} else {
		// MONEDA EXTRANJERA
		$aDataGrid[$id][$aLabelDiar[7]] = $debito;
		$aDataGrid[$id][$aLabelDiar[8]] = $credito;
	}

	$sHtml = '';
	$_SESSION['aDataGirdDiar'] = $aDataGrid;
	$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
	$oReturn->assign("divDiario", "innerHTML", $sHtml);

	// TOTAL DIARIO
	$oReturn->script("total_diario();");

	return $oReturn;
}

// TRANSACCIONES
function cargar_lista_tran($aForm = '', $op)
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	//variables del formulario
	$empresa  = $aForm['empresa'];
	$sucursal = $aForm['sucursal'];
	$modulo   = null;

	if ($op == 'CL') {
		$modulo = 3;
	} elseif ($op == 'PV') {
		$modulo = 4;
	}

	$sql = "select tran_cod_tran, tran_des_tran, trans_tip_tran 
			from saetran where
			tran_cod_empr = $empresa and
			tran_cod_sucu = $sucursal and
			tran_cod_modu = $modulo 
			order by 2";
	$i = 1;
	if ($oIfx->Query($sql)) {
		$oReturn->script('eliminar_lista_tran();');
		if ($oIfx->NumFilas() > 0) {
			do {
				$detalle =  $oIfx->f('tran_cod_tran') . ' || ' . $oIfx->f('tran_des_tran') . ' || ' . $oIfx->f('trans_tip_tran');
				$oReturn->script(('anadir_elemento_tran(' . $i++ . ',\'' . $oIfx->f('tran_cod_tran') . '\',  \'' . $detalle . '\' )'));
			} while ($oIfx->SiguienteRegistro());
		}
	}
	$oIfx->Free();

	// TRANSACCION POR DEFECTO
	$sql = "select pccp_aut_pago from saepccp where pccp_cod_empr = $empresa ";
	$tran_cod_tran = consulta_string_func($sql, 'pccp_aut_pago', $oIfx, 0);
	$oReturn->assign('tran', 'value', $tran_cod_tran);

	return $oReturn;
}

// SUBCLIENTE
function cargar_lista_subcliente($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn = new xajaxResponse();

	//variables de session
	$idempresa = $_SESSION['U_EMPRESA'];

	//variables del formulario
	$empresa = $aForm['empresa'];
	$cliente = $aForm['clpv_cod'];

	$sql = "select ccli_cod_ccli, ccli_nom_conta  
			from saeccli where
			ccli_cod_empr = $empresa and 
			ccli_cod_clpv = $cliente";
	//$oReturn->alert($sql);
	$i = 1;
	if ($oIfx->Query($sql)) {
		$oReturn->script('eliminar_lista_subcliente();');
		if ($oIfx->NumFilas() > 0) {
			do {
				$oReturn->script(('anadir_elemento_subcliente(' . $i++ . ',\'' . $oIfx->f('ccli_cod_ccli') . '\', \'' . $oIfx->f('ccli_nom_conta') . '\' )'));
			} while ($oIfx->SiguienteRegistro());
		}
	}
	$oIfx->Free();

	return $oReturn;
}

// DIRECTRIO SIN FACTURA
function agrega_modifica_grid_dir_ori_add($nTipo = 0, $aForm, $id = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	//unset($_SESSION['aDataGirdDir']);
	//unset($_SESSION['aDataGirdDiar']);

	$aDataGrid_Dir  = $_SESSION['aDataGirdDir'];
	$aDataDiar 		= $_SESSION['aDataGirdDiar'];

	$aLabelGrid = array(
		'Fila',
		'Cliente',
		'Subcliente',
		'Tipo',
		'Factura',
		'Fec. Vence',
		'Detalle',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'DI'
	);

	$aLabelDiar = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad',
		'DIR',
		'RET'
	);

	$oReturn = new xajaxResponse();

	// VARIABLES
	$tran_cod   = $aForm["tran"];
	$detalle    = $aForm["det_dir"];
	$doc        = $aForm["documento"];
	$idsucursal = $aForm["sucursal"];
	$ccosn_cod  = $aForm["ccosn"];
	$act_cod    = $aForm["actividad"];
	$ccli_cod   = $aForm["ccli"];
	$idempresa  = $_SESSION['U_EMPRESA'];
	$mone_cod   = $aForm["moneda"];

	$sql      = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
	$mone_base = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

	if ($mone_cod == $mone_base) {
		$sql      = "select pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
		$mone_extr = consulta_string_func($sql, 'pcon_seg_mone', $oIfx, '');

		$sql = "select tcam_valc_tcam from saetcam where
				mone_cod_empr = $idempresa and
				tcam_cod_mone = $mone_extr and
				tcam_fec_tcam in (
									select max(tcam_fec_tcam)  from saetcam where
											mone_cod_empr = $idempresa and
											tcam_cod_mone = $mone_extr
								)  ";

		$coti = $aForm['cotizacion_ext']; //consulta_string_func($sql, 'tcam_val_tcam', $oIfx, 0);

	} else {
		$coti = $aForm['cotizacion'];
	}

	//$oReturn->alert($coti);
	$totals    = $aForm['totals'];
	$val_fue_b = $aForm['val_fue_b'];
	$val_fue_s = $aForm['val_fue_s'];
	$val_iva_b = ($aForm['val_iva_b'] != '') ? $aForm['val_iva_b'] : 0;
	$val_iva_s = ($aForm['val_iva_s'] != '') ? $aForm['val_iva_s'] : 0;

	$val_fue_b = vacios($val_fue_b, 0);
	$val_fue_s = vacios($val_fue_s, 0);
	$val_iva_b = vacios($val_iva_b, 0);
	$val_iva_s = vacios($val_iva_s, 0);
	$cre_ml    = $aForm['fact_valor'];

	if ($nTipo == 0) {
		$cont = 0;
		$contd = 0;
		$fact_num  = $aForm['factura_dir'];
		$fec_emis  = $aForm['fecha_emision'];
		$fec_venc  = $aForm['fecha_vence'];
		$clpv_cod  = $aForm['clpv_cod'];
		$tran_cod  = $aForm['tran'];
		$det_dir   = $aForm['det_dir'];


		$sql = "select tran_cod_cuen from saetran where 
								  tran_cod_tran = '$tran_cod' and 
								  tran_cod_modu = 4 and
								  tran_cod_sucu = $idsucursal and
								  tran_cod_empr = $idempresa and
								( tran_ant_tran = 1 or 
								  tran_cie_tran = 1   
								) ";
		$clpv_cuen = consulta_string_func($sql, 'tran_cod_cuen', $oIfx, '');
		//$oReturn->alert($clpv_cuen);

		// CUENTAS DE CLPV
		$sql       = "select clpv_cod_cuen, grpv_cod_grpv, clpv_clopv_clpv from saeclpv where clpv_cod_empr = $idempresa and clpv_cod_clpv = $clpv_cod ";
		$tipo      = consulta_string_func($sql, 'clpv_clopv_clpv', $oIfx, '');

		if (empty($clpv_cuen)) {
			$clpv_cuen = consulta_string_func($sql, 'clpv_cod_cuen', $oIfx, '');
			if (empty($clpv_cuen)) {
				$clpv_gr = consulta_string_func($sql, 'grpv_cod_grpv', $oIfx, '');
				$sql = "select  grpv_cta_grpv  from saegrpv where
										grpv_cod_empr = $idempresa and
										grpv_cod_grpv = '$clpv_gr' ";
				$clpv_cuen = consulta_string_func($sql, 'grpv_cta_grpv', $oIfx, '');
			}
		}

		// NOMBRE CUENtA
		$sql = "select cuen_nom_cuen from saecuen where 
			                    cuen_cod_empr = $idempresa and
			                    cuen_cod_cuen = '$clpv_cuen' ";
		$cuen_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

		//$tipo  	   = $val[12];
		$ccli  	   = $aForm['ccli'];
		$txt       = abs(str_replace(",", "", $cre_ml));

		$valDirCre = 0;
		$valDiaCre = 0;
		$valDirDeb = 0;
		$valDiaDeb = 0;

		if ($tipo == 'CL') {
			$modulo = 3;
		} elseif ($tipo == 'PV') {
			$modulo = 4;
		}

		//tipo de transaccion
		$sql = "select trans_tip_tran from saetran where tran_cod_tran = '$tran_cod' and tran_cod_modu = $modulo and tran_cod_empr = $idempresa ";
		$trans_tip_tran = consulta_string_func($sql, 'trans_tip_tran', $oIfx, '');

		//$oReturn->alert($sql);

		if ($trans_tip_tran == 'DB') {
			$valDirDeb = $txt;
			$valDiaDeb = $txt;
		} elseif ($trans_tip_tran == 'CR') {
			$valDirCre = $txt;
			$valDiaCre = $txt;
		}

		// DIRECTORIO
		$cont = count($aDataGrid_Dir);
		$aDataGrid_Dir[$cont][$aLabelGrid[0]] = floatval($cont);
		$aDataGrid_Dir[$cont][$aLabelGrid[1]] = $clpv_cod;
		$aDataGrid_Dir[$cont][$aLabelGrid[2]] = $ccli_cod;
		$aDataGrid_Dir[$cont][$aLabelGrid[3]] = $tran_cod;
		$aDataGrid_Dir[$cont][$aLabelGrid[4]] = $fact_num;
		$aDataGrid_Dir[$cont][$aLabelGrid[5]] = $fec_venc;
		$aDataGrid_Dir[$cont][$aLabelGrid[6]] = $det_dir;
		$aDataGrid_Dir[$cont][$aLabelGrid[7]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataGrid_Dir[$cont][$aLabelGrid[8]] = $valDirDeb;
			$aDataGrid_Dir[$cont][$aLabelGrid[9]] = $valDirCre;
			$aDataGrid_Dir[$cont][$aLabelGrid[10]] = $deb_tmp;
			$aDataGrid_Dir[$cont][$aLabelGrid[11]] = $cre_tmp;
		} else {
			// moneda extra

			$aDataGrid_Dir[$cont][$aLabelGrid[8]] = $valDirDeb * $coti;
			$aDataGrid_Dir[$cont][$aLabelGrid[9]] = $valDirCre * $coti;

			$aDataGrid_Dir[$cont][$aLabelGrid[10]] = $valDirDeb;
			$aDataGrid_Dir[$cont][$aLabelGrid[11]] = $valDirCre;
		}

		$aDataGrid_Dir[$cont][$aLabelGrid[12]] = '';

		$contd = count($aDataDiar);
		$aDataGrid_Dir[$cont][$aLabelGrid[13]] = '<div align="center">
															<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
															title = "Presione aqui para Eliminar"
															style="cursor: hand !important; cursor: pointer !important;"
															onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1 );"
															alt="Eliminar"
															align="bottom" />
														</div>';


		$aDataGrid_Dir[$cont][$aLabelGrid[14]] = $contd;

		// DIARIO

		$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
		$aDataDiar[$contd][$aLabelDiar[1]] = $clpv_cuen;
		$aDataDiar[$contd][$aLabelDiar[2]] = $cuen_nom;
		$aDataDiar[$contd][$aLabelDiar[3]] = $doc;
		$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

		if ($mone_cod == $mone_base) {
			// moneda local
			$cre_tmp = 0;
			$deb_tmp = 0;
			if ($coti > 0) {
				$cre_tmp = round(($valDirCre / $coti), 2);
			}

			if ($coti > 0) {
				$deb_tmp = round(($valDirDeb / $coti), 2);
			}

			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre;
			$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
			$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
		} else {
			// moneda extra
			$aDataDiar[$contd][$aLabelDiar[5]] = $valDiaDeb * $coti;
			$aDataDiar[$contd][$aLabelDiar[6]] = $valDiaCre * $coti;
			$aDataDiar[$contd][$aLabelDiar[7]] = $valDirDeb;
			$aDataDiar[$contd][$aLabelDiar[8]] = $valDirCre;
		}

		$aDataDiar[$contd][$aLabelDiar[9]] = '';
		$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
															title = "Presione aqui para Eliminar"
															style="cursor: hand !important; cursor: pointer !important;"
															onclick="javascript:xajax_elimina_detalle_dir(' . $cont . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1 );"
															alt="Eliminar"
															align="bottom" />';
		$aDataDiar[$contd][$aLabelDiar[11]] = '';
		$aDataDiar[$contd][$aLabelDiar[12]] = '';
		$aDataDiar[$contd][$aLabelDiar[13]] = '';
		$aDataDiar[$contd][$aLabelDiar[14]] = '';
		$aDataDiar[$contd][$aLabelDiar[15]] = '';
		$aDataDiar[$contd][$aLabelDiar[16]] = '';
		$aDataDiar[$contd][$aLabelDiar[17]] = $det_dir;
		$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_cod;
		$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
		$aDataDiar[$contd][$aLabelDiar[20]] = $cont;
	}





	$_SESSION['aDataGirdDir'] = $aDataGrid_Dir;
	$sHtml = mostrar_grid_dir($idempresa, $idsucursal);
	$oReturn->assign("divDir", "innerHTML", $sHtml);



	// DIARIO
	$sHtml = '';
	$_SESSION['aDataGirdDiar'] = $aDataDiar;
	$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
	$oReturn->assign("divDiario", "innerHTML", $sHtml);



	// TOTAL DIARIO
	$oReturn->script("total_diario();");

	return $oReturn;
}

//DISTRIBUCION DE CUENTAS
function form_distri($aForm = '')
{

	global $DSN, $DSN_Ifx;
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo();
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$idempresa  = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm['sucursal'];
	$cuen_cod   = $aForm['cod_cta'];
	$cuen_nom   = $aForm['nom_cta'];
	$val_cta    = $aForm['val_cta'];


	$ifu = new Formulario;
	$ifu->DSN = $DSN_Ifx;

	$oReturn = new xajaxResponse();

	$sHtml .= '<table class="table table-striped table-condensed" style="width: 99%; margin-bottom: 0px;">
				<tr>
					<td colspan="4" class="bg-primary">DISTRIBUCION</td>
				</tr>';
	$sHtml .= '</table>';

	// CENTRO DE COSTOS
	$table_op .= '<table class="table table-striped table-condensed table-bordered table-hover" style="width: 90%; margin-top: 20px;" align="center">';
	$table_op .= '<tr>
						<td class="fecha_letra">SALDO:</td>
						<td class="fecha_letra" id="saldo_ccosn">0.00</td>
						<td class="fecha_letra">PORCENTAJE:</td>
						<td class="fecha_letra" id="porcen_ccosn">0.00</td>
						<td colspan="1" align="right">
							<div class="modal-footer">                    
								<button type="button" class="btn btn-info dropdown-toggle" data-dismiss="modal" onclick="agregar_dist();">Procesar</button>
								<button type="button" class="btn btn-danger" data-dismiss="modal">Cerrar</button>
							</div>
						</td>
				</tr>
				<tr>
						<td align="center" class="fecha_letra">N.-</th>
						<td align="center" class="fecha_letra">Codigo</th>
						<td align="center" class="fecha_letra">Centro Costo</th>
						<td align="center" class="fecha_letra">%</th>
						<td align="center" class="fecha_letra">Valor</th>
				</tr>';

	$sql = "select  ccosn_cod_ccosn,  ccosn_nom_ccosn, *
					from saeccosn where
					ccosn_cod_empr  = $idempresa and
					ccosn_mov_ccosn = '1' and
					ccosn_impr_sn   = 'N' 
					order by 1 ";
	$i = 1;
	unset($array_ccosn);
	if ($oIfx->Query($sql)) {
		if ($oIfx->NumFilas() > 0) {
			do {
				$ccosn_cod_ccosn  = $oIfx->f('ccosn_cod_ccosn');
				$ccosn_nom_ccosn  = $oIfx->f('ccosn_nom_ccosn');

				$ifu->AgregarCampoNumerico($ccosn_cod_ccosn . '_porc', 'Valor|left', true, '0', 80, 100);
				$ifu->AgregarComandoAlCambiarValor($ccosn_cod_ccosn . '_porc', 'cargar_datos_ccosn()');

				$ifu->AgregarCampoNumerico($ccosn_cod_ccosn . '_val', 'Valor|left', true, '0', 80, 100);
				$ifu->AgregarComandoAlCambiarValor($ccosn_cod_ccosn . '_val', 'cargar_datos_ccosnV()');

				$table_op .= '<tr>';
				$table_op .= '<td align="right">' . $i . '</td>';
				$table_op .= '<td align="right">' . $ccosn_cod_ccosn . '</td>';
				$table_op .= '<td align="left" >' . $ccosn_nom_ccosn . '</td>';
				$table_op .= '<td align="right" >' . $ifu->ObjetoHtml($ccosn_cod_ccosn . '_porc') . '</td>';
				$table_op .= '<td align="right" >' . $ifu->ObjetoHtml($ccosn_cod_ccosn . '_val') . '</td>';
				$table_op .= '</tr>';

				$i++;

				$array_ccosn[] =  array($ccosn_cod_ccosn,  $ccosn_nom_ccosn);
			} while ($oIfx->SiguienteRegistro());

			$table_op .= '<tr>';
			$table_op .= '<td align="right"></td>';
			$table_op .= '<td align="right"></td>';
			$table_op .= '<td align="left" ></td>';
			$table_op .= '<td align="right" id="total_ccosnp" class="letra_rojo">0.00</td>';
			$table_op .= '<td align="right" id="total_ccosn"  class="letra_rojo">0.00</td>';
			$table_op .= '</tr>';
		}
	}

	$table_op .= '</table>';

	unset($_SESSION['U_ARRAY_CCOSN']);
	$_SESSION['U_ARRAY_CCOSN'] = $array_ccosn;


	$modal  = '<div id="mostrarmodal6" class="modal fade" role="dialog">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">' . $cuen_cod . ' - ' . substr($cuen_nom, 0, 50) . '</h4>
                            <h4 class="modal-title"><strong>VALOR: ' . $val_cta . '  </strong><input type="hidden" value="' . $val_cta . '" id="total_distribucion_m" name="total_distribucion_m"></h4>
                        </div>
                        <div class="modal-body">';
	$modal .= $sHtml;

	$sHtml .= '</div>';
	$modal .= '<div id="gridFp" style="margin-top: 20px;">' . $table_op . '</div>';
	$modal .= '          
                        <div class="modal-footer">                    
                            <button type="button" class="btn btn-info dropdown-toggle" data-dismiss="modal" onclick="agregar_dist();">Procesar</button>
                            <button type="button" class="btn btn-danger" data-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
             </div>';

	//$oReturn->alert($sHtml);	

	$oReturn->assign("miModalDistri", "innerHTML", $modal);
	$oReturn->script("abre_modal_distri();");

	return $oReturn;
}

function procesar_distri($aForm = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$fu = new Formulario;
	$fu->DSN = $DSN;

	$aDataDiar = $_SESSION['aDataGirdDiar'];
	$array_ccosn  = $_SESSION['U_ARRAY_CCOSN'];

	$aLabelDiar = array(
		'Fila',
		'Cuenta',
		'Nombre',
		'Documento',
		'Cotizacion',
		'Debito Moneda Local',
		'Credito Moneda Local',
		'Debito Moneda Ext',
		'Credito Moneda Ext',
		'Modificar',
		'Eliminar',
		'Beneficiario',
		'Cuenta Bancaria',
		'Cheque',
		'Fecha Venc',
		'Formato Cheque',
		'Codigo Ctab',
		'Detalle',
		'Centro Costo',
		'Centro Actividad'
	);
	$oReturn = new xajaxResponse();

	$cod_cta    = $aForm["cod_cta"];
	$nom_cta    = $aForm["nom_cta"];
	$idempresa  = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm["sucursal"];
	$detalle    = $aForm["detalla_diario"];
	$documento  = $aForm["documento"];
	$mone_cod   = $aForm["moneda"];
	$tipo       = $aForm['crdb'];

	$sql        = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
	$mone_base  = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

	if ($mone_cod == $mone_base) {
		$sql      = "select pcon_seg_mone from saepcon where pcon_cod_empr = $idempresa ";
		$mone_extr = consulta_string_func($sql, 'pcon_seg_mone', $oIfx, '');

		$sql = "select tcam_valc_tcam from saetcam where
                mone_cod_empr = $idempresa and
                tcam_cod_mone = $mone_extr and
                tcam_fec_tcam in (
                                    select max(tcam_fec_tcam)  from saetcam where
                                            mone_cod_empr = $idempresa and
                                            tcam_cod_mone = $mone_extr
                                )  ";

		$coti = $aForm["cotizacion_ext"]; //consulta_string_func($sql, 'tcam_val_tcam', $oIfx, 0);
	} else {
		$coti = $aForm["cotizacion"];
	}


	if (count($array_ccosn) > 0) {
		foreach ($array_ccosn as $val) {
			$ccosn_cod_ccosn  = $val[0];
			$ccosn_nom_ccosn  = $val[1];
			$porc_ccosn		  = $aForm[$ccosn_cod_ccosn . '_porc'];
			$val_ccosn  	  = $aForm[$ccosn_cod_ccosn . '_val'];
			if ($tipo == 'CR') {
				$cred = $val_ccosn;
				$deb  = 0;
			} elseif ($tipo == 'DB') {
				$cred = 0;
				$deb  = $val_ccosn;
			}
			if ($porc_ccosn > 0) {
				// DIARIO
				$contd = count($aDataDiar);
				$aDataDiar[$contd][$aLabelDiar[0]] = floatval($contd);
				$aDataDiar[$contd][$aLabelDiar[1]] = $cod_cta;
				$aDataDiar[$contd][$aLabelDiar[2]] = $nom_cta;
				$aDataDiar[$contd][$aLabelDiar[3]] = $documento;
				$aDataDiar[$contd][$aLabelDiar[4]] = $coti;

				if ($mone_cod == $mone_base) {
					// moneda local
					$cre_tmp = 0;
					$deb_tmp = 0;
					if ($coti > 0) {
						$cre_tmp = round(($cred / $coti), 2);
					}

					if ($coti > 0) {
						$deb_tmp = round(($deb / $coti), 2);
					}

					$aDataDiar[$contd][$aLabelDiar[5]] = $deb;
					$aDataDiar[$contd][$aLabelDiar[6]] = $cred;
					$aDataDiar[$contd][$aLabelDiar[7]] = $deb_tmp;
					$aDataDiar[$contd][$aLabelDiar[8]] = $cre_tmp;
				} else {
					// moneda extra         
					$aDataDiar[$contd][$aLabelDiar[5]] = $deb * $coti;
					$aDataDiar[$contd][$aLabelDiar[6]] = $cred * $coti;
					$aDataDiar[$contd][$aLabelDiar[7]] = $deb;
					$aDataDiar[$contd][$aLabelDiar[8]] = $cred;
				}

				$vacio = '-1';
				$aDataDiar[$contd][$aLabelDiar[9]] = '';
				$aDataDiar[$contd][$aLabelDiar[10]] = '<img src="' . $_COOKIE['JIREH_IMAGENES'] . 'iconos/delete_1.png"
                                                                title = "Presione aqui para Eliminar"
                                                                style="cursor: hand !important; cursor: pointer !important;"
                                                                onclick="javascript:xajax_elimina_detalle_dir(' . $vacio . ', ' . $idempresa . ', ' . $idsucursal . ', ' . $contd . ', -1 );"
                                                                alt="Eliminar"
                                                                align="bottom" />';
				$aDataDiar[$contd][$aLabelDiar[11]] = '';
				$aDataDiar[$contd][$aLabelDiar[12]] = '';
				$aDataDiar[$contd][$aLabelDiar[13]] = '';
				$aDataDiar[$contd][$aLabelDiar[14]] = '';
				$aDataDiar[$contd][$aLabelDiar[15]] = '';
				$aDataDiar[$contd][$aLabelDiar[16]] = '';
				$aDataDiar[$contd][$aLabelDiar[17]] = $detalle;
				$aDataDiar[$contd][$aLabelDiar[18]] = $ccosn_cod_ccosn;
				$aDataDiar[$contd][$aLabelDiar[19]] = $act_cod;
			}
		}
	}

	// DIARIO
	$sHtml = '';
	$_SESSION['aDataGirdDiar'] = $aDataDiar;
	$sHtml = mostrar_grid_dia($idempresa, $idsucursal);
	$oReturn->assign("divDiario", "innerHTML", $sHtml);

	$oReturn->assign("cod_cta", "value", '');
	$oReturn->assign("nom_cta", "value", '');
	$oReturn->assign("val_cta", "value", '');

	// TOTAL DIARIO
	$oReturn->script("total_diario();");

	return $oReturn;
}

function lista_boostrap($oIfx, $sql, $campo_defecto, $campo_id, $campo_nom)
{
	$optionEmpr = '';
	if ($oIfx->Query($sql)) {
		if ($oIfx->NumFilas() > 0) {
			do {
				$empr_cod_empr = $oIfx->f($campo_id);
				$empr_nom_empr = htmlentities($oIfx->f($campo_nom));

				$selectedEmpr = '';
				if ($empr_cod_empr == $campo_defecto) {
					$selectedEmpr = 'selected';
				}

				$optionEmpr .= '<option value="' . $empr_cod_empr . '" ' . $selectedEmpr . '>' . $empr_nom_empr . '</option>';
			} while ($oIfx->SiguienteRegistro());
		}
	}
	$oIfx->Free();

	return $optionEmpr;
}

function total_diario($aForm = '')
{
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn    = new xajaxResponse();

	$aDataGrid  = $_SESSION['aDataGirdDiar'];

	$idempresa  = $_SESSION['U_EMPRESA'];
	$mone_cod   = $aForm["moneda"];
	$cta_cod   = $aForm['cod_cta'];
	$sql        = "select pcon_mon_base from saepcon where pcon_cod_empr = $idempresa ";
	$mone_base  = consulta_string_func($sql, 'pcon_mon_base', $oIfx, '');

	$cont        = 0;
	$tot_cre     = 0;
	$tot_deb     = 0;
	$tot_cre_ext = 0;
	$tot_deb_ext = 0;

	if (count($aDataGrid) > 0) {
		foreach ($aDataGrid as $aValues) {
			$aux = 0;
			foreach ($aValues as $aVal) {
				if ($aux == 5) {
					$tot_deb += $aVal;
				} elseif ($aux == 6) {
					$tot_cre += $aVal;
				} elseif ($aux == 7) {
					$tot_deb_ext += $aVal;
				} elseif ($aux == 8) {
					$tot_cre_ext += $aVal;
				}
				$aux++;
			}
			$cont++;
		}
	}

	if ($mone_cod == $mone_base) {
		$oReturn->assign("val_cta", "value", abs($tot_cre - $tot_deb));
	} else {
		$oReturn->assign("val_cta", "value", abs($tot_cre_ext - $tot_deb_ext));
	}



	return $oReturn;
}



function totales_inciva($aForm = '')
{
	//Definiciones
	global $DSN_Ifx, $DSN;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	//Definiciones
	$oReturn = new xajaxResponse();


	// CONTRIBUYENTE ESPECIAL
	$clpv = $aForm['cliente'];
	$sql = "select clpv_etu_clpv from saeclpv where
                        clpv_clopv_clpv = 'PV' and
                        clpv_cod_clpv = '$clpv' ";
	$clpv_etu_clpv = consulta_string($sql, 'clpv_etu_clpv', $oIfx, 'N');

	$total_inciva = 0;
	$total_inciva = $aForm['total_inciva'];
	$iva_calc     = 0;
	$iva_calc     = ($aForm['ivabp'] / 100) + 1;
	$valor_grab12b = round(($total_inciva / $iva_calc), 2);

	// BIENES
	//$valor_grab12b  = $aForm['valor_grab12b'];
	$oReturn->assign("valor_grab12b", "value", $valor_grab12b);

	$valor_grab0b   = $aForm['valor_grab0b'];
	$iceb           = $aForm['iceb'];
	$ivabp          = $aForm['ivabp'];
	$ivab           = round((($ivabp / 100) * $valor_grab12b), 2);

	// BIENES 16 ITBIS
	$valor_grab16b  = $aForm['valor_grab16b'];
	$iva16bp        = $aForm['iva16bp'];
	$iva16b         = round((($iva16bp / 100) * $valor_grab16b), 2);


	// SERVICIOS
	$valor_grab12s  = $aForm['valor_grab12s'];
	$valor_grab0s   = $aForm['valor_grab0s'];
	$ices           = $aForm['ices'];
	$ivas           = round((($ivabp / 100) * $valor_grab12s), 2);


	// TOTALES
	$valor_grab12t  = $valor_grab12b + $valor_grab12s + $valor_grab16b;
	$valor_grab0t   = $valor_grab0b + $valor_grab0s;
	$icet           = $iceb + $ices;
	$ivat           = $ivab + $ivas + $iva16b;

	// RETENCIONES
	$base_fue_b = $valor_grab12b + $valor_grab0b + $valor_grab16b;
	$base_fue_s = $valor_grab12s + $valor_grab0s;
	$base_iva_b = $ivab + $iva16b;
	$base_iva_s = $ivas;

	if ($clpv_etu_clpv == '1') {
		// contribuyente especial
		//$base_iva_b = 0;
		//$base_iva_s = 0;
	}

	// VALOR RETENIDO
	$porc_ret_fue_b = $aForm['porc_ret_fue_b'];
	$porc_ret_fue_s = $aForm['porc_ret_fue_s'];
	$val_ret_fue_b = round((($base_fue_b * $porc_ret_fue_b) / 100), 2);
	$val_ret_fue_s = round((($base_fue_s * $porc_ret_fue_s) / 100), 2);

	$porc_ret_iva_b = $aForm['porc_ret_iva_b'];
	$porc_ret_iva_s = $aForm['porc_ret_iva_s'];
	$val_ret_iva_b = round((($base_iva_b * $porc_ret_iva_b) / 100), 2);
	$val_ret_iva_s = round((($base_iva_s * $porc_ret_iva_s) / 100), 2);

	// IMPUESTO GASOLINA
	$imp_gasto = $aForm['pais_imp_comb'];
	$num_galo  = $aForm['num_galon'];

	$icet = 0;
	if ($imp_gasto > 0) {
		$icet = round($num_galo * $imp_gasto, 2);
	}

	$totals = round(($valor_grab12t + $valor_grab0t + $icet + $ivat), 2);

	$oReturn->assign("iceb", "value",           $icet);
	$oReturn->assign("ivab", "value",           $ivab);
	$oReturn->assign("iva16b", "value",         $iva16b);
	$oReturn->assign("ivas", "value",           $ivas);
	$oReturn->assign("totals", "value",         $totals);
	$oReturn->assign("valor_grab12t", "value",  $valor_grab12t);
	$oReturn->assign("valor_grab0t", "value",   $valor_grab0t);
	$oReturn->assign("icet", "value",           $icet);
	$oReturn->assign("ivat", "value",           $ivat);
	$oReturn->assign("base_fue_b", "value",     $base_fue_b);
	$oReturn->assign("base_fue_s", "value",     $base_fue_s);

	//if($aForm['codigo_ret_iva_b']!='')
	$oReturn->assign("base_iva_b", "value",     $base_iva_b);

	// if($aForm['codigo_ret_iva_s']!='')
	$oReturn->assign("base_iva_s", "value",     $base_iva_s);

	$oReturn->assign("val_gasto1", "value", ($valor_grab12t + $valor_grab0t + $icet));

	$oReturn->assign("val_fue_b", "value", $val_ret_fue_b);
	$oReturn->assign("val_fue_s", "value", $val_ret_fue_s);
	$oReturn->assign("val_iva_b", "value", $val_ret_iva_b);
	$oReturn->assign("val_iva_s", "value", $val_ret_iva_s);

	$oReturn->script('recalculo();');
	return $oReturn;
}


function cargarDatosOP($clpv_cod, $aForm = '')
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	global $DSN_Ifx;

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$oReturn   = new xajaxResponse();
	$idempresa = $_SESSION['U_EMPRESA'];
	$idsucursal = $aForm['sucursal'];

	// OP         
	$sql = "select  orpr_sec_orpr, orpr_cod_orpr   from saeorpr where
				orpr_cod_empr  = $idempresa and
				orpr_est_fact  = 'N' and
				orpr_bod_fabr in (  select bode_cod_bode from saebode where
										bode_cod_empr = $idempresa and
										bode_cod_clpv = $clpv_cod )
				order by 2 ";

	$i = 0;
	$msn = "...Seleccione una Opcion...";
	$txt = 'orden_op';
	$oReturn->script('borrar_lista( \'' . $txt . '\' )');
	if ($oIfx->Query($sql)) {
		if ($oIfx->NumFilas() > 0) {
			do {
				$id   = $oIfx->f('orpr_sec_orpr');
				$nom  = $oIfx->f('orpr_cod_orpr');
				$oReturn->script(('anadir_elemento(' . $i . ',' . $id . ', \'' . $nom . '\', \'' . $txt . '\' )'));
				$i++;
			} while ($oIfx->SiguienteRegistro());
			$oReturn->script(('anadir_elemento(' . $i . ',"", \'' . $msn . '\', \'' . $txt . '\' )'));
		}
	}
	$oIfx->Free();

	return $oReturn;
}



// FACTURAS
function reporte_facturas($idempresa, $idsucursal, $clpv_cod, $factura, $tran_clpv, $det)
{
	//Definiciones
	global $DSN_Ifx;

	session_start();

	$oIfx = new Dbo;
	$oIfx->DSN = $DSN_Ifx;
	$oIfx->Conectar();

	$ifu = new Formulario;
	$ifu->DSN = $DSN;

	$oReturn = new xajaxResponse();
	unset($_SESSION['U_FACTURA']);



	// CUENTAS DE CLPV
	$sql = "select clpv_cod_cuen, grpv_cod_grpv from saeclpv where clpv_cod_empr = $idempresa and clpv_cod_clpv = $clpv_cod ";
	$clpv_cuen = consulta_string_func($sql, 'clpv_cod_cuen', $oIfx, '');
	if (empty($clpv_cuen)) {
		$clpv_gr = consulta_string_func($sql, 'grpv_cod_grpv', $oIfx, '');
		$sql = "select  grpv_cta_grpv  from saegrpv where
                        grpv_cod_empr = $idempresa and
                        grpv_cod_grpv = '$clpv_gr' ";
		$clpv_cuen = consulta_string_func($sql, 'grpv_cta_grpv', $oIfx, '');
	}

	// NOMBRE CUENtA
	$sql = "select cuen_nom_cuen from saecuen where 
                    cuen_cod_empr = $idempresa and
                    cuen_cod_cuen = '$clpv_cuen' ";
	$cuen_nom = consulta_string_func($sql, 'cuen_nom_cuen', $oIfx, '');

	$ifu->AgregarCampoCheck('check', '', true, 'N');
	$ifu->AgregarComandoAlCambiarValor('check', "cargar_tot();");

	// TRANSACCIONAL
	$sql = "select trim(para_fac_cxp) as tran  from saepara where para_cod_empr = $idempresa and para_cod_sucu = $idsucursal ";
	$tran_cod = consulta_string_func($sql, 'tran', $oIfx, '');


	$sql = "select sucu_cod_sucu, sucu_nom_sucu from saesucu where sucu_cod_empr = $idempresa ";
	unset($array_sucu);
	$array_sucu = array_dato($oIfx, $sql, 'sucu_cod_sucu', 'sucu_nom_sucu');


	$table_op .= '<table class="table table-bordered table-hover" align="center" style="width: 98%;">
					 <tr><td colspan="8" align="center" class="bg-primary">FACTURAS</td></tr>';
	$table_op .= '<tr>
                            <td align="center" class="bg-primary">N.-</td>
                            <td align="center" class="bg-primary">Sucursal</td>
                            <td align="center" class="bg-primary">Tran</td>
                            <td align="center" class="bg-primary">Factura</td>
                            <td align="center" class="bg-primary">F. Emision</td>
                            <td align="center" class="bg-primary">F. Vence1</td>                            
                            <td align="center" class="bg-primary" style="display:none">Valor Rete.</td>
                            <td align="center" class="bg-primary">Saldo</td>
                            <td align="right" class="bg-primary"></td> 
                     </tr>';

	//$sql_sp = "execute  procedure sp_consulta_factprove_web( $idempresa, $idsucursal , $clpv_cod, '$factura' )";
	//dmcp_cod_sucu = $idsucursal and FILTRO SUCURSAL 
	$sql_sp = "select dmcp_num_fac,  min(dcmp_fec_emis) as dcmp_fec_emis, max(dmcp_fec_ven) as dmcp_fec_ven, 
					   MAX((select sum(COALESCE(dcmp_deb_ml,0) - COALESCE(dcmp_cre_ml,0))
								from saedmcp y	where 
								y.dmcp_cod_empr = saedmcp.dmcp_cod_empr and 
								y.clpv_cod_clpv = saedmcp.clpv_cod_clpv and
								y.dmcp_num_fac  = saedmcp.dmcp_num_fac)) saldo
						from saedmcp WHERE 
						dmcp_cod_empr = $idempresa 	AND 
						clpv_cod_clpv = $clpv_cod	AND 
						dmcp_est_dcmp <>'AN'  AND
						saedmcp.dmcp_num_fac in ( select trim(dmcp_num_fac) from saedmcp  WHERE 
													dmcp_cod_empr   = $idempresa AND 
													clpv_cod_clpv   = $clpv_cod and
													dcmp_fec_emis  <= CURRENT_DATE group by dmcp_num_fac
													having sum(dcmp_deb_ml) - sum(dcmp_cre_ml) <> 0 
												)
						group by dmcp_num_fac							
						having  sum(dcmp_deb_ml) - sum(dcmp_cre_ml) <> 0     
						ORDER BY dmcp_num_fac ";

	// $oReturn->alert($sql_sp);
	unset($array);
	$total   = 0;
	$i       = 1;
	$tot_ret = 0;
	//$oReturn->alert($sql_sp);
	if ($oIfx->Query($sql_sp)) {
		if ($oIfx->NumFilas() > 0) {
			do {
				$tran     = $tran_cod;
				$fact_num = $oIfx->f('dmcp_num_fac');
				$fec_emis = $oIfx->f('dcmp_fec_emis');
				$fec_venc = $oIfx->f('dmcp_fec_ven');
				$dmcp_cod_tran = $oIfx->f('dmcp_cod_tran');

				$saldo    = abs($oIfx->f('saldo'));
				$saldo_condicion    = $oIfx->f('saldo');
				//echo $saldo;exit;
				$ret      = $oIfx->f('ret');
				$sucu_nom = $array_sucu[$oIfx->f('dmcp_cod_sucu')];
				$bgcolor = '';
				if ($saldo_condicion > 0) {
					$tran = '';
				}

				if (empty($sucu_nom)) {
					$sucu_nom = 'MATRIZ';
				}

				if (empty($fec_venc)) {
					$fec_venc = $oIfx->f('dcmp_fec_emis');
				}


				$ifu->AgregarCampoTexto($i, '', false, 0, 100, 50);

				$ifu->AgregarComandoAlCambiarValor($i, "xajax_calculo( xajax.getFormValues('form1')) ");
				$ifu->AgregarComandoAlEscribir($i, 'mascara(this,cpf)');

				$array[$i] = array(
					$i,
					$fact_num,
					$fec_emis,
					$fec_venc,
					$saldo,
					$idempresa,
					$idsucursal,
					$clpv_cod,
					$tran_clpv,
					$det,
					$clpv_cuen,
					$cuen_nom
				);

				if ($sClass == 'off') $sClass = 'on';
				else $sClass = 'off';
				if ($saldo_condicion > 0) {
					$table_op .= '<tr bgcolor="yellow">';
				} else {
					$table_op .= '<tr  height="20" class="' . $sClass . '"
                                            onMouseOver="javascript:this.className=\'link\';"
                                            onMouseOut="javascript:this.className=\'' . $sClass . '\';">';
				}

				//$tran = $dmcp_cod_tran;
				$fec_emis = str_replace('/', '', $fec_emis);
				$fec_venc = str_replace('/', '', $fec_venc);
				$table_op .= '<td align="right">' . $i . '</td>';
				$table_op .= '<td align="left">' . $sucu_nom . '</td>';
				$table_op .= '<td align="right">' . $tran . '</td>';
				$table_op .= '<td align="right">' . $fact_num . '</td>';
				$table_op .= '<td align="right">' . $fec_emis . '</td>';
				$table_op .= '<td align="right">' . $fec_venc . '</td>';
				$table_op .= '<td align="right" style="display:none">' . $ret . '</td>';
				$table_op .= '<td align="right">' . number_format($saldo, 2, '.', ',') . '</td>';
				if ($saldo_condicion < 0) {
					$table_op .= '<td align="right">
										<div id ="imagen1" class="btn btn-success btn-sm" onclick="seleccionar_factura_ad(\'' . $fact_num . '\')"title="">
											<span class="glyphicon glyphicon-check"></span>
										</div>
                                    </td>';
				} else {
					$table_op .= '<td colspan="1">VALOR A CRUZAR CON FACTURAS</td>';
				}

				$table_op .= '</tr>';
				$i++;
				$total   += $saldo;
				$tot_ret += $ret;
			} while ($oIfx->SiguienteRegistro());
			$table_op .= '<tr height="20" class="' . $sClass . '"
                                            onMouseOver="javascript:this.className=\'link\';"
                                            onMouseOut="javascript:this.className=\'' . $sClass . '\';">';
			$table_op .= '<td align="right"></td>';
			$table_op .= '<td align="right"></td>';
			$table_op .= '<td align="right"></td>';
			$table_op .= '<td align="right"></td>';
			$table_op .= '<td align="right" class="fecha_letra">TOTAL:</td>';
			$table_op .= '<td align="right" class="fecha_letra" style="display:none">' . $tot_ret . '</td>';
			$table_op .= '<td align="right" class="fecha_letra">' . number_format($total, 2, '.', ',') . '</td>';
			$table_op .= '<td align="right" class="letra_rojo" id="tot_cobro">0.00</td>';
			$table_op .= '<td align="right"></td>';
			$table_op .= '</tr>';
		} else {
			$table_op = '<span class="fecha_letra">Sin Datos...</span>';
		}
	}
	$oIfx->Free();
	$table_op .= '</table>';

	$_SESSION['U_FACTURA'] = $array;

	$oReturn->assign("divFormularioDetalle", "innerHTML", $table_op);

	return $oReturn;
}







/*
 * :::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::: */
/* PROCESO DE REQUEST DE LAS FUNCIONES MEDIANTE AJAX NO MODIFICAR */
$xajax->processRequest();
/* :::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::: */
