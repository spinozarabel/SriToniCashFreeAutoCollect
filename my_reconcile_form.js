jQuery(document).ready(function($) 
{
  var table = $('#table-order-reconcile').DataTable();

  $('button').click( function() 
  {
    var table_data =  table.column('#column-1').data();
    
    $.post(
      my_reconcile_script_ajax_obj.ajax_url,              // url given in localize script in wordpress php
			{                                                   // POST request
				_ajax_nonce: my_reconcile_script_ajax_obj.nonce,  // nonce extracted and sent
				action: "spzrbl_reconcile",         	            // hook added for action wp_ajax_spzrbl_city in php file
				table_data: table_data                               	// city from dropdown by user. This is accesed by server phphandler as $_POST['city']
			},
            function(data_from_server) 					// data is JSON data sent back by server in response, wp_send_json($server_city_response)
				{
          // data sent back from server response to ajax call
        }
    );
    
  } );

} );
