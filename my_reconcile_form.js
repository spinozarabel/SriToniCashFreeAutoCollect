jQuery(document).ready(function($) 
{
  // initialize datatables with pre-drawn table done by HTML and PHP on page
  var table = $('#table-order-reconcile').DataTable( {
                                                      "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "All"]]
                                                    } );

  $('button').click( function() 
  {
    // when the button on top of table is clicked
    // 
    // the 1st column in the table contains the open orders
    var order_column_array =  table.columns().data()[0];

    // serach for all inputs (which occur in column PaymentId). This data is serialized with idnames=paymentid&etc.
    var paymentidinputs = table.$('input').serialize();

    // separate the string at the '&' into an array
    var payments_array = paymentidinputs.split('&');

    // remove the prepend idname
    payments_array = payments_array.map(x => x.substring(x.indexOf('=') + 1));

    console.log(order_column_array);
    console.log(payments_array);
    
    $.post(
      my_reconcile_script_ajax_obj.ajax_url,              // url given in localize script in wordpress php
			{                                                   // POST request
				_ajax_nonce: my_reconcile_script_ajax_obj.nonce,  // nonce extracted and sent
				action: "spzrbl_reconcile",         	            // hook added for action wp_ajax_spzrbl_reconcile in php
				table_data: [order_column_array,payments_array]   // This is accessed in PHP handler as  $_POST['table_data']
			},
            function(data_from_server) 					// data is JSON data sent back by server in response, wp_send_json($server_city_response)
				{
          // data sent back from server response to ajax call
        }
    );
    
  } );

} );
