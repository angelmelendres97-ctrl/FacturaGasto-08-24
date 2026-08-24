<?php
/**
 * HTML2PDF Librairy - example
 *
 * HTML => PDF convertor
 * distributed under the LGPL License
 *
 * @author      Laurent MINGUET <webmaster@html2pdf.fr>
 *
 * isset($_GET['vuehtml']) is not mandatory
 * it allow to display the result in the HTML format
 */
    // get the HTML
    ob_start();
    $num = 'CMD01-'.date('ymd');
    $nom = 'DUPONT Alphonse';
    $date = '01/01/2012';
?>
<style type="text/css">
<!--
    div.zone { border: none; border-radius: 6mm; background: #FFFFFF; border-collapse: collapse; padding:3mm; font-size: 2.7mm;}
    h1 { padding: 0; margin: 0; color: #DD0000; font-size: 7mm; }
    h2 { padding: 0; margin: 0; color: #222222; font-size: 5mm; position: relative; }
-->
</style>

<page backimgw="70%" backtop="10mm" backbottom="10mm" backleft="20mm" backright="10mm"><table style="width: 100%;"><tr><b><td style="width: 70%;"> <table> <tr> <td align="left"><img src="/var/www/Facturacion_Electronica_prueba/WebApp/imagenes/iconos/logo_comprobantes.png"></td> </tr><tr><td style="font-size: 20px">ALITECNO COMERCIO DE INSUMOS PARA LA INDUSTRIA DE ALIMENTOS</td></tr><tr><td style="font-size: 20px">RUC : 1791307860001</td></tr><tr><td style="font-size: 20px">DIRECCION : AV. GALO PLAZA LASSO N46-51 Y DE LAS RETAMAS</td></tr><tr><td style="font-size: 20px">Contribuyente Especial #:155</td></tr><tr><td style="font-size: 20px">Obligado a llevar Contabilidad :SI</td></tr> </table></td></b><b><td style="width: 30%;" align="center"> <table> <tr><td style="border: 1px inset black; font-size: 20px; ">GUIA DE REMISION</td> </tr> <tr><td style="border-left: 1; border-right: 1">SERIE : </td>< </tr> <tr><td style="border-left: 1; border-right: 1">001-001</td> </tr> <tr><td style="font-size: 20px; color: red; border-left: 1; border-right: 1;">000062506</td> </tr> <tr><td style="border-left: 1; border-right: 1; border-top:1">AUTORIZACION : </td> </tr> <tr><td style="border-left: 1; border-right: 1">0212201413571317913078600010143777652</td> </tr> <tr><td style="border-left: 1; border-right: 1; border-top:1" >FECHA AUTORIZACION : </td> </tr> <tr><td style="border-left: 1; border-right: 1; border-bottom:1">2014-12-02T13:57:13.693-05:00</td> </tr> </table></td></b></tr></table> <br><br><br> <table style="width: 100%" > <tr> <b><td colspan="4"> DATOS DEL TRANSPORTISTA : </td></b> </tr> <tr> <b><td style="width: 15%"> NOMBRE : </td></b> <td style="width: 55% ">CESAR ONTANEDA</td> <b><td style="width: 15% "> RUC/CI : </td></b> <td style="width: 15% ">0502814841</td> </tr> <tr> <b><td style="width: 15% "> PARTIDA : </td></b> <td style="width: 55% ">AV. GALO PLAZA LASSO N46-51 Y DE LAS RETAMAS</td> <b><td style="width: 15% "> PLACA : </td></b> <td style="width: 15% ">PCO0006</td> </tr> <tr> <b><td style="width: 15% "> FECHA INICIO : </td></b> <td style="width: 55% ">12/02/2014</td> <b><td style="width: 15% "> FECHA FIN : </td></b> <td style="width: 15% ">12/02/2014</td> </tr> </table> <br><br><br> <table style="width: 100%" > <tr> <b><td colspan="3"> DATOS DEL DESTINATARIO : </td></b> </tr> <tr> <b><td style="width: 15%"> NOMBRE : </td></b> <td style="width: 55% ">ABAD CARRION MIGUEL ANGEL</td> <b><td style="width: 15% "> RUC/CI : </td></b> <td style="width: 15% ">0703248013</td> </tr> <tr> <b><td style="width: 15%"> DESTINO : </td></b> <td style="width: 55% ">REAL AUDIENCIA Y ALFONSO YEPEZ</td> </tr> <tr> <b><td style="width: 15% "> MOTIVO: </td></b> <td style="width: 55% ">AAASSS</td> </tr> <table style="width: 100%"> <tr> <b> <td style="width: 15%;  border: 1px inset black;">CODIGO</td> </b> <b> <td style="width: 70%;  border: 1px inset black;">DESCRIPCION</td> </b> <b> <td style="width: 15%;  border: 1px inset black;">CANTIDAD</td> </b> </tr> <tr> <td style="width: 15%; border: 1px inset black;">COT060</td> <td style="width: 70%; border: 1px inset black;"> NO USAR FOOT WASH PREMIUM</td> <td style="width: 15%; border: 1px inset black;">1.0</td> </tr> </table> <br><br><br></page>



<?php
     $content = ob_get_clean();

    // convert
    require_once(dirname(__FILE__).'/../html2pdf.class.php');
    try
    {
        $html2pdf = new HTML2PDF('P', 'A4', 'fr', true, 'UTF-8', 0);
        $html2pdf->pdf->SetDisplayMode('fullpage');
        $html2pdf->writeHTML($content, isset($_GET['vuehtml']));
        $html2pdf->Output('ticket.pdf');
    }
    catch(HTML2PDF_exception $e) {
        echo $e;
        exit;
    }

