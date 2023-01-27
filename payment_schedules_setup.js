jQuery(document).ready(function($) 
{
    // initialize datatables with pre-drawn table done by HTML and PHP on page
    var table = $('#table-payment-schedules-setup').DataTable();



    // when submit button is clicked  do server side processing of data sent by Ajax call
    $('button').click( function() 
      {
        // when the button on top of table is clicked 
        // 
        // the 3rd column contains wpuserid
        // var wp_user_id_array  = table.columns().data()[2];
        // var total_array       = table.columns().data()[6];

        // serach for all inputs (which occur in column PaymentId). This data is serialized with idnames=paymentid&etc.
        // var paymentidinputs = table.$('input').serialize();

        // separate the string at the '&' into an array
        //var payments_array = paymentidinputs.split('&');

        // remove the prepend idname
        // payments_array = payments_array.map(x => x.substring(x.indexOf('=') + 1));

        var datatoserver =    {
                                institution:      $('#institution').val(),
                                student_class:    $('#student-class').val(),
                                category:         $('#category').val(),
                                total:            $('#set-total').val(),
                                duedate1:         $('#duedate1').val(),
                                duedate2:         $('#duedate2').val(),
                                duedate3:         $('#duedate3').val(),
                                duedate4:         $('#duedate4').val(),
                                wp_user_id_array: table.columns().data()[2],
                                num_installments: table.columns().data()[7]
                              };
        
        $.post(
          payment_schedules_setup_ajax_obj.ajax_url,              // localized ascript handle and url in enque script php
          {                                                   
            _ajax_nonce: payment_schedules_setup_ajax_obj.nonce,  // nonce extracted and sent
            action: "spzrbl_setup_payment_schedules",         	  // we have to setup action spzrbl_setup_payment_schedules in php
            datatoserver:  datatoserver      // This is accessed in PHP handler as  $_POST['datatoserver']
          },
          function(data_from_server) 					// data is JSON data sent back by server in response, wp_send_json($server_city_response)
            {
              // data sent back from server response to ajax call
            }
        );
        
      } );

    // when institution selection changes, send that value back by Ajax call
    $('#institution').on('change', function()
    {
          var this2 = this;                  	//save for possible later use
          $.post(payment_schedules_setup_ajax_obj.ajax_url,
          {                                                      //POST request
            _ajax_nonce: payment_schedules_setup_ajax_obj.nonce, //nonce extracted and sent
            action: "spzrbl_institution",         	             // hook added for action wp_ajax_spzrbl_city in php file
            institution: this.value               	             // dropdown value. This is accesed by server $_POST['institution']
          },

          function(student_classes) 					// JSON data sent by server, wp_send_json($server_institution_response)
          {
            // remove existing student_class select options and add new ones from returned AJAX student_classes
            var student_class = $('#student-class');	// select form element with id="student_class"

            if(student_class.prop)                    // some artificat of .prop vs .attr
            {
              var options = student_class.prop('options');
            }
            else
            {
              var options = student_class.attr('options');
            }

            $('option', student_class).remove();		// remove old options from the select since institution may have changed

            if(student_classes.length)
            {
              $.each(student_classes, function(key, val)
              {
                // new Option(optionText, optionValue)
                options[key] = new Option(val, val);	// create new options based on institution selected
              });
            }
          });
    

    } );

    // change the category to refresh the table with new data from server 
    $('#institution-select').on('change', function()
    {
      var this2 = this;                  	//save for possible later use

      // compose data for sending to server by Ajax call
      var dropdown_selects = {institution:$('#institution').val(),
                              student_class:$('#student-class').val(),
                              category:$('#category').val(),
                              total:$('#set-total').val()
                              };
          $.post(payment_schedules_setup_ajax_obj.ajax_url,
          {                                                      //POST request
            _ajax_nonce: payment_schedules_setup_ajax_obj.nonce, //nonce extracted and sent
            action: "spzrbl_send_data",         	               // hook added for action wp_ajax_spzrbl_city in php file
            dropdown_selects: dropdown_selects               	   // dropdown value. This is accesed by server $_POST['institution']
          },

          function(data) 					// JSON data sent by server, wp_send_json($server_institution_response)
          {
            // the data is expected to be formatted to be directly used by datatables
            table.clear();
            table.rows.add(data);
            table.draw();
          } );
    } );

} );
