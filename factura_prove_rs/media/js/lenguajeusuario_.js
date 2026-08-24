$(document).ready(function() {	
	var nomClpv = document.getElementById('nomClpv').value;
	var table = $('#datatable-keytable').DataTable( {
		keys: {
			focus: ':eq(0)'
    	},
		dom: 'Bfrtip',
        "buttons": [
            'copy', 'csv', 'excel', 'pdf', 'print'
        ],
		"select": true,
		"stateSave": false,
		"searching": true,
		"pageLength": 10,
		"bDeferRender": true,	
		"sPaginationType": "full_numbers",
		"ajax": {
			"url": "busca_cliente.php?nomClpv="+nomClpv,
	    	"type": "POST"
		},					
		"columns": [
			{ "data": "clpv_cod_clpv" },
			{ "data": "clv_con_clpv" },
			{ "data": "clpv_ruc_clpv" },
			{ "data": "clpv_nom_clpv" },
			{ "data": "clpv_est_clpv" },
			{ "data": "selecciona"}
		],
		
		"oLanguage": {
            "sProcessing":     "Procesando...",
		    "sLengthMenu": 'Mostrar <select>'+ 
		        '<option value="10">10</option>'+
		        '<option value="60">60</option>'+
		        '<option value="90">90</option>'+
		        '<option value="120">120</option>'+
		        '<option value="150">150</option>'+
		        '<option value="-1">Todo</option>'+
		        '</select> registros',    
		    "sZeroRecords":    "No se encontraron resultados",
		    "sEmptyTable":     "Ningún dato disponible en esta tabla",
		    "sInfo":           "Mostrando del (_START_ al _END_) de un total de _TOTAL_ registros",
		    "sInfoEmpty":      "Mostrando del 0 al 0 de un total de 0 registros",
		    "sInfoFiltered":   "(filtrado de un total de _MAX_ registros)",
		    "sInfoPostFix":    "",
		    "sSearch":         "Filtrar:",
		    "sUrl":            "",
		    "sInfoThousands":  ",",
		    "sLoadingRecords": "Por favor espere - cargando...",
		    "oPaginate": {
		        "sFirst":    "Primero",
		        "sLast":     "Último",
		        "sNext":     "Siguiente",
		        "sPrevious": "Anterior"
		    },
		    "oAria": {
		        "sSortAscending":  ": Activar para ordenar la columna de manera ascendente",
		        "sSortDescending": ": Activar para ordenar la columna de manera descendente"
		    }
        }
	});

	table.on('key', function (e, datatable, key, cell, originalEvent) {
    	if (key === 13) { // return
        	///seleccionaItem(cell.index());
        	seleccionaItem(cell.data());

    	}
	});

});