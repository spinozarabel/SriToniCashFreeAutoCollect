<?php
/*
*/

// if directly called die. Use standard WP and Moodle practices
if (!defined( "ABSPATH" ) && !defined( "MOODLE_INTERNAL" ) )
    {
    	die( 'No script kiddies please!' );
    }

// class definition begins for Virtual Account e-commerce
class sritoni_va_ec
{
  const VERBOSE   = true;

  public function __construct()
  {
    $this->verbose  = self::VERBOSE;
    $this->timezone = new DateTimeZone('Asia/Kolkata');

    // hook for adding custom columns on woocommerce orders page
    add_filter( 'manage_edit-shop_order_columns',               [$this, 'orders_add_mycolumns'] );

    // hook for updating my new column valus based on passed order details
    add_action( 'manage_shop_order_posts_custom_column',        [$this, 'set_orders_newcolumn_values'], 2 );

    // hook for callback function to be done after order's status is changed to completed
    add_action( 'woocommerce_order_status_completed',           [$this, 'moodle_on_order_status_completed'], 10, 1 );

    // Filter products on the shop page based on user meta: sritoni_student_category, grade_or_class
    add_action( 'woocommerce_product_query',                    [$this, 'installment_pre_get_posts_query'] );

    // This adds an action just before saving any order at checkout to update the order meta for va_id
    add_action('woocommerce_checkout_create_order',             [$this, 'ma_update_order_meta_atcheckout'], 20, 2);

    // add filter to change the price of product in shop and product pages
    add_filter( 'woocommerce_product_get_price',                [$this, 'spz_change_price'], 10, 2 );

    // adds text to product just before add-to-cart button based on user meta
    add_filter( 'woocommerce_before_add_to_cart_button',        [$this, 'spz_product_customfield_display']);

    // This function adds the fee payment items to cart item data
    add_filter( 'woocommerce_add_cart_item_data',               [$this, 'spz_add_cart_item_data'], 10, 3 );

    // Display custom item data in the cart we added above
    add_filter( 'woocommerce_get_item_data',                    [$this, 'spz_get_cart_item_data'], 10, 2 );

    //
    add_action( 'woocommerce_checkout_create_order_line_item',  [$this, 'spz_checkout_create_order_line_item'], 10, 4 );

    $this->init_function();
    //
    // add_action('plugins_loaded',                                [$this, 'init_function']);

    // add_filter( 'woocommerce_grouped_price_html', 'max_grouped_price', 10, 3 );

  } // End Of Constructor

  private function init_function()
  {
    $this->moodle_token 	          = get_option( 'sritoni_settings')["sritoni_token"];
    $this->moodle_url               = get_option( 'sritoni_settings')["sritoni_url"] . '/webservice/rest/server.php';

    $this->get_csv_fees_file        = get_option( 'sritoni_settings')["get_csv_fees_file"] ?? false;
    $this->csv_file                 = get_option( 'sritoni_settings')["csv_fees_file_path"];
    // get the reconcile or not flag from settings. If true then we try to reconcile whatever was missed by webhook
    $this->missed_webhook_reconcile = get_option( 'sritoni_settings' )["reconcile"] ?? 0;

    if ($this->get_csv_fees_file)
    {
        // read file and parse to associative array. To access this in a function, make this a global there
        $this->fees_csv             = $this->csv_to_associative_array($this->csv_file);
    }
  }

  /** add_VA_payments_submenu()
  *   is the callback function for the add_action admin_menu hook above
  *   adds a sub-menu item in the woocommerce main menu called VA-payments with slug woo-VA-payments
  *   the callback function when this sub-menu is clicked on is VA_payments_callback and is defined elsewhere
  */
  public function add_VA_payments_submenu()
  {

      /*
  	add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable $function = '' )
  	*					parent slug		newsubmenupage	submenu title  	capability			new submenu slug		callback for display page
  	*/
  	add_submenu_page( 'woocommerce',	'VA-payments',	'VA-payments',	'manage_options',	'woo-VA-payments',		[$this, 'VA_payments_callback'] );

  	/*
  	* add another submenu page for reconciling orders and payments on demand from admin menu
  	*/
  	add_submenu_page( 'woocommerce',	'reconcile',	'reconcile',	'manage_options',	'reconcile-payments',	[$this, 'reconcile_payments_callback'] );

  	return;
  }
  /**
  *   Callback function to add_submenu_page( 'woocommerce',	'VA-payments',	'VA-payments',	....
  *   displays Virtual payments. Normal access to this page is by clicking on a VA payment from the Orders menu
  *   By clicking on the payment, the user details are passed into this page, even though admin is viewing this page
  */
  public function VA_payments_callback()
  {
  	$timezone          = $this->timezone;   // new DateTimeZone('Asia/Kolkata');
  	// values passed in from orders page VA_ID link, see around line 1049
  	$va_id				     = $_GET["va_id"];
  	$user_display_name = $_GET["display_name"];
  	$user_id			     = $_GET["user_id"];			// this is passed in value of wordpress userid
  	// top line displayed on page
      echo 'Recent payments for Virtual Account: ' . "<b>" . $va_id . "</b>" . ' of User: ' . "<b>" . $user_display_name . "</b>";
  	?>
  	<style>
  	  table {
  		border-collapse: collapse;
  	  }
  	  th, td {
  		border: 1px solid orange;
  		padding: 10px;
  		text-align: left;
  	  }
  </style>
  	<table style="width:100%">
  		<tr>
  			<th>Payment ID</th>
  			<th>Payment Amount</th>
  			<th>Payment Date</th>
  			<th>Order ID</th>
  			<th>Order Amount</th>
  			<th>Order Created Date</th>
  			<th>Order Item</th>
  			<th>Payer Notes</th>
  			<th>Payer Bank Reference</th>
  		</tr>
  	<?php
  	// create new instance of payment gateway API. In WP no need to pass site name Since
      // settings are unique per site. Site name is only required for call from Moodle
  	$cashfree_api 	= new CfAutoCollect; // new cashfree API object
  	//
  	$payments	= $cashfree_api->getPaymentsForVirtualAccount($va_id);	// list of payments made into this VA
      // if no payments made exit
      if (empty($payments))
      {
          echo 'No payments made into this VA:' . $va_id . ' for this user: ' . $user_display_name;
          return;
      }
  	//
  	foreach ($payments as $key=> $payment)
  		{
  			//error_log(print_r("payment details: " . "for number: " .$key ,true));
  			//error_log(print_r($payment ,true));
  			$payment_id			  = $payment->referenceId;
  			$payment_amount	  = $payment->amount;	        // in ruppees

        $payment_date     = $payment->paymentTime;    // example 2007-06-28 15:29:26

  			$payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date);
  			//$payment_datetime->setTimezone($timezone);

  			$args = array(
              					'status' 			    => array(
                          													'completed',
                          											    ),
              					'limit'				    =>	1,
              					'payment_method' 	=> 'vabacs',
              					'customer_id'		  => $user_id,
              					'meta-key'			  => "va_payment_id",
              					'meta_value'		  => $payment_id,
              				 );
  			// get all orderes completed with search parameters as shown above
  			$orders 						= wc_get_orders( $args );
  			$order 							= $orders[0] ?? null;
  			if ($order)
  			{	// order is reconciled with this payment get order details for table display
  				$order_id					= $order->get_id();
  				$order_amount 		= $order->get_total();
  				//$order_transaction_id 		= $order->get_transaction_id();
  				$order_datetime		= new DateTime( '@' . $order->get_date_created()->getTimestamp());
  				$order_datetime->setTimezone($timezone);

  				$items						= $order->get_items();

  				// get prodct name associated with this order
  				// per our restrictions there should be only 1 bundled item per order
  				// however, since we only want the bundled order name, we break after 1st item in loop below
  				foreach ($items as $item_key => $item )
  				{
  					$item_name    = $item->get_name();	// this is the name of the bundled product
  					break;
  				}
  				// print out row of table for this reconciled payment
  				?>
  				<tr>
  					<td><?php echo htmlspecialchars($payment_id); ?></td>
  					<td><?php echo htmlspecialchars(get_woocommerce_currency_symbol() . $payment_amount); ?></td>
  					<td><?php echo htmlspecialchars($payment_datetime->format('M-d-Y H:i:s')); ?></td>
  					<td><?php echo htmlspecialchars($order_id); ?></td>
  					<td><?php echo htmlspecialchars(get_woocommerce_currency_symbol() . $order_amount); ?></td>
  					<td><?php echo htmlspecialchars($order_datetime->format('M-d-Y H:i:s')); ?></td>
  					<td><?php echo htmlspecialchars($item_name); ?></td>
  					<td><?php echo htmlspecialchars($order->get_meta('payment_notes_by_customer', $single = true)); ?></td>
  					<td><?php echo htmlspecialchars($order->get_meta('bank_reference', $single = true)); ?></td>
  				</tr>
  				<?php
  			}
  			else
  			{	// no order exists for this payment so continue onto next payment in foreach loop
  				?>
  				<tr>
  					<td><?php echo htmlspecialchars($payment_id); ?></td>
  					<td><?php echo htmlspecialchars(get_woocommerce_currency_symbol() . $payment_amount); ?></td>
  					<td><?php echo htmlspecialchars($payment_datetime->format('M-d-Y H:i:s')); ?></td>
  					<td style="color:red">Unreconciled</td>
  					<td>na</td>
  					<td>na</td>
  					<td>na</td>
  				</tr>
  				<?php
  				continue;
  			}
  		}
  }         // end of public function VA_payments_callback

  /**
  *	  This implements the callback for the sub-menu page reconcile.
  *   add_submenu_page( 'woocommerce',	'reconcile',	'reconcile',	'manage_options',	'reconcile-payments',	[$this, 'reconcile_payments_callback'] );
  *   When this manu page is accessed from the admin menu under Woocommerce, it tries to reconcile all open orders against payments made
  *   Normally the reconciliation should be done as soon as a payment is made by a webhook issued by the payment gateway.
  *	  Should the webhook reconciliation fail for whatever reason, an on-demand reconciliation can be forced by accessing this page.
  *
  */
  public function reconcile_payments_callback()
  {
  	$maxReturn	=	3;					// maximum number of payments to be returned for any payment account to reconcile
  	$max_orders	=	30;					// maximum number of orders that are reconciled in one go
  	$timezone		= $this->timezone; // new DateTimeZone("Asia/Kolkata");
  	// get all open orders for all users
  	$args = array(
        						'status' 			    => 'on-hold',
        						'payment_method' 	=> 'vabacs',
        				  );
  	$orders = wc_get_orders( $args );

  	// if no orders on-hold then nothing t reconcile so exit
  	if (empty($orders))
  	{
  		echo 'No orders on-hold, nothing to reconcile';
  		return;
  	}
    // so we do have some open orders to reconcile!
  	// Instantiate payment gateway API along with authetication
  	$cashfree_api 			= new CfAutoCollect; // new cashfree Autocollect API object

  	// For each order get the last 3 payments made. Assumption is that there are not more than 3 payments made after an order is placed
  	$order_count = 0;
  	foreach ($orders as $order)
  	{
  		// get user payment account ID details from order
  		$va_id		= get_post_meta($order->id, 'va_id', true) ?? 'unable to get VAID from order meta';

  		// get the last few payments bade by this account
  		$payments	= $cashfree_api->getPaymentsForVirtualAccount($va_id, $maxReturn);

  		foreach ($payments as $payment)
  		{
        $this->order    = $order;
        $this->payment  = $payment;

  			if ( !$this->reconcilable1_ma() )
  				{
    				// this payment is not reconcilable either due to mismatch in payment or dates or both
    				continue;	// continue next payment
  				}
  			else
  				{
            // this order is reconcilable. So go reconcile the order against the payment
    				$this->reconcile1_ma();

            echo 'Order No: ' . $order->id . ' Reconciled with Payment ID: ' . $payment->referenceId;

            // break out of payment loop and process next open order if any
    				break;
  				}
  		}
      // how many orders processed so far?
  		$order_count +=	1;

  		if ($order_count >= $max_orders)
  		{
  			break; // exit out of orders loop
  		}
  	}
  }

  /**
  *  @param order is the full order object under consideration
  *  @param payment is the full payment object being considered
  *  @param timezone is the full timezone object needed for order objects timestamp
  *  return a boolean value if the payment and order can be reconciled
  *  Conditions for reconciliation are: (We assume payment method is VABACS and this payment is not reconciled in any order before
  *  1. Payments must be equal
  *  2. Order creation Date must be before Payment Date
  */
  public function reconcilable1_ma()
  {
    $timezone = $this->timezone;
    $order    = $this->order;
    $payment  = $this->payment;

    // since order datetime is from time stamp whereas payment datetime is from actual date and time
    // we will only use settimezone for order datetime and not payment datetime.
  	$order_total			= $order->get_total();

  	// $order_total_p			= (int) round($order_total * 100);

  	$order_created_datetime	= new DateTime( '@' . $order->get_date_created()->getTimestamp());
    $order_created_datetime->setTimezone($timezone);

  	//
  	$payment_amount 		= $payment->amount;          // in ruppees
    $payment_date       = $payment->paymentTime;     // example 2007-06-28 15:29:26
    $payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date);


  	return ( ($order_total == $payment_amount) && ($payment_datetime > $order_created_datetime) );

  } // END OF function reconcilable1_ma

  /**
  *  @param order is the order object
  *  @param payment is the payment object
  *  @param reconcile is a settings boolean option for non-webhook reconciliation
  *  @param reconcilable is a boolean variable indicating wether order and payment are reconcilable or not
  *  @param timezone is passed in to calculate time of order creation using timestamp
  *  return a boolean value if the payment and order have been reconciled successfully
  *  Conditions for reconciliation are: (We assume payment method is VABACS and this payment is not reconciled in any order before
  *  1. Payments must be equal
  *  2. Order creation Date must be before Payment Date
  *  Reconciliation means that payment is marked complete and order meta updated suitably
  */
  public function reconcile1_ma()
  {
    $timezone = $this->timezone;
    $order    = $this->order;
    $payment  = $this->payment;

  	$order_created_datetime	= new DateTime( '@' . $order->get_date_created()->getTimestamp());
  	$order_created_datetime->setTimezone($timezone);

    $payment_date     = $payment->paymentTime;     // example 2007-06-28 15:29:26

    $payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date); // this is already IST

  	$order_note = 'Payment received by cashfree Virtual Account ID: ' . get_post_meta($order->id, 'va_id', true) .
  					      ' Payment ID: ' . $payment->referenceId . '  on: ' . $payment_datetime->format('Y-m-d H:i:s') .
  					      ' UTR reference: ' . $payment->utr;
  	$order->add_order_note($order_note);

  	$order->update_meta_data('va_payment_id', 				     $payment->referenceId);
  	$order->update_meta_data('amount_paid_by_va_payment',  $payment->amount);        // in Rs
  	$order->update_meta_data('bank_reference', 			 	     $payment->utr);
  	// $order->update_meta_data('payment_notes_by_customer', 	$payment_obj->description);

  	$order->save;
    // create an array of all information to be packed into a JSON string as transaction ID
  	$transaction_arr	= array(
            									'payment_id'	 => $payment->referenceId,
            									'payment_date' => $payment_datetime->format('Y-m-d H:i:s'),
            									'va_id'				 => get_post_meta($order->id, 'va_id', true),
            									'utr'	         => $payment->utr,
            								 );

  	$transaction_id = json_encode($transaction_arr);

  	$order->payment_complete($transaction_id);

    return true;
  }                   // End Of  function reconcile1_ma

  /** function orders_add_mycolumns($columns)
  *   @param columns
  *   This function is called by add_filter( 'manage_edit-shop_order_columns', 'orders_add_mycolumns' )
  *   adds new columns after order_status column called Student
  *   adds 2 new columns after order_total called VApymnt and VAid
  */
  public function orders_add_mycolumns($columns)
  {
  	$new_columns = array();

    foreach ( $columns as $column_name => $column_info )
		{

			$new_columns[ $column_name ] = $column_info;

			if ( 'order_status' === $column_name )
				{
                    // add a new column called student AFTER order status column
				    $new_columns['Student'] = "Student";
				}

			if ( 'order_total' === $column_name )
				{
            // add these new columns AFTER order_total column
    				$new_columns['VApymnt'] = "VApymnt";
    				$new_columns['VAid']    = "VAid";
				}
		}

    return $new_columns;
  }                                 // end of function orders_add_mycolumns

  /** set_orders_newcolumn_values($columns)
  *   @param colname
  *   sets values to be displayed in the new columns in orders page
  *   This is callback for add_action( 'manage_shop_order_posts_custom_column', 'set_orders_newcolumn_values', 2 );
  *   For Student we display students display name.
  *   For VAid we display the VAid with a link to the payments page if payment method is VABACS
  *   If a VABACS order is completed or processed we display the payment amount and date extracted from order
  *   If a VABACS order is on-hold, we get the last 3 payments made to the order related VAID
  *      If any of the payments are yet to be reconciled we then try to reconcile any of the payments to the order
  *      provided the reconcile flag in settings page is set.
  *      If reconciled display the payment amount and date. If not display that payment is pending, with no date
  *   This is a backup for reconciliation of payments with orders wif the webhook does not work
  */
  public function set_orders_newcolumn_values($colname)
  {
  	global $post;

    $timezone				  = $this->timezone; // new DateTimeZone("Asia/Kolkata");

  	// Proceed only for new columns added by us, return otherwise
  	if ( !( ($colname == 'VApymnt') || ($colname == 'VAid') || ($colname == 'Student') )  )
		{
			return;
		}

  	$order = wc_get_order( $post->ID );

  	// Only continue if we have an order.
  	if ( empty( $order ) )
		{
			return;
		}

  	// are we allowed to reconcile outside of cashfree webhook? check with option setting
  	$reconcile				 = get_option( 'sritoni_settings' )["reconcile"] ?? 0;

  	// get order details up ahead of treating all the cases below.
  	$order_status			 = $order->get_status();
  	$payment_method		 = $order->get_payment_method();
  	$va_id 					   = get_post_meta($order->id, 'va_id', true) ?? ""; 	// this is the VA _ID contained in order meta
  	$user_id 				   = $order->get_user_id();
  	$order_user 			 = get_user_by('id', $user_id);
  	$user_display_name = $order_user->display_name;

  	$reconcilable = false;	// preset flag to start with that order is not reconcilable

  	switch (true)
  	{

  		case ( $payment_method != "vabacs" ) :
  		  // for orders that are not VABACS no need to do anything go to print section breaking out of switch
  		break;


  		case ( ( 'processing' == $order_status ) || ( 'completed' == $order_status ) ) :

  			// for orders processing or completed get payment data from order for display later on
  			$payment_amount 	= get_post_meta($order->id, 'amount_paid_by_va_payment', true); // in Rs.

  			$payment_datetime	= new DateTime( '@' . $order->get_date_paid()->getTimestamp());
  			$payment_datetime->setTimezone($timezone);	// adusted for local time zone
  			$payment_date		  = $payment_datetime->format('Y-m-d H:i:s');

  		break;     // out of switch structure

  		// Reconcile on-hold orders only if reconcile flag in settings is set, otherwise miss
  		case ( ( 'on-hold' == $order_status ) && ( $reconcile == 1 ) ):

        // since wee need to interact with Cashfree , create a new API instamve
        $cashfree_api    = new CfAutoCollect; // new cashfree Autocollect API object

  			// So first we get a list of last 3 payments made to the VAID contained in this HOLD order
  			$payments        = $cashfree_api->getPaymentsForVirtualAccount($va_id, 3);

        // what happens if there are no payents made and this is null?
        if (empty($payments))
        {
          // no reconciliation without payments, so exit and print dummy data
          $payment_amount     = "n/a";
          $payment_datetime   = "n/a";

          break;  // break out of switch structure and go to print
        }
  			// Loop through the payments to check which one is already reconciled and which one is not
  			foreach ($payments as $key=> $payment)
				{

					$payment_id			= $payment->referenceId;
					$args 				= array(
          												'status' 			    => array(
                      																				'processing',
                      																				'completed',
                      																			),
          												'limit'				    => 1,			// at least one order exists for this payment?
          												'payment_method'  => "vabacs",
          												'customer_id'		  => $user_id,
          												'meta-key'			  => "va_payment_id",
          												'meta_value'		  => $payment_id,
          											);
					// get all orderes in process or completed with search parameters as shown above
					$payment_already_reconciled 	= !empty( wc_get_orders( $args ) );

					if ( $payment_already_reconciled )
						{
							// this payment is already reconciled so loop over to next payment
							continue;	// continue the for each loop next iteration
						}
					// Now we have a payment that is unreconciled. See if it is a potential candidate for reconciliation
          $this->order     = $order;
          $this->payment   = $payment;

					if ( !$this->reconcilable_ma() )
						{
						// this payment is not reconcilable either due to mismatch in payment or dates or both
						continue;	// continue next iteration of loop payment
						}
					// we now have a reconcilable paymet against this HOLD order so we get out of the for loop
          // we only reconcile the 1st reconcilable payment.
          // if multiple payments are made only 1 payment will be reconciled

					$reconcilable = true;
					break;	// out of payments loop but still in Switch statement. $payment is the payment to be reconciled
				}	        // end of for each payments loop

  		case ( ($reconcilable == true) && ($reconcile == 1) ) :
        // we cannot get here without going through case statement immediately above and dropping down braking out of for each payments loop
  			// we will reconcle since all flags are go
        $this->reconcile    = $reconcile;
        $this->reconcilable = $reconcilable;

  			$this->reconcile_ma();

        $payment_date     = $payment->paymentTime;    // example 2007-06-28 15:29:26
  			$payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date);

  			$payment_amount 		= $payment->amount;    // already in rupees
  	}		    // end of SWITCH structure

  	if ( 'VApymnt' === $colname )
  	{
  		// for orders completed this will be information extracted from the order details
  		// for orders on hold, if no payments exist for this VAID then 0 and Jan 1, 1970 is displayed
  		// for orders on hold, if last payment exists for this VAID, amount and last payment date are displayed

  		switch (true)
  		{
  			case ( $payment_method != "vabacs" ) :
  				echo $payment_method;
  			break;

  			case ( ($reconcilable == true) && ($reconcile == 1) ) :
  				echo get_woocommerce_currency_symbol() . number_format($payment_amount) . " " . $payment_datetime->format('M-d-Y H:i:s');
  			break;

  			case ( ( 'processing' == $order_status ) || ( 'completed' == $order_status ) ) :
  				echo get_woocommerce_currency_symbol() . number_format($payment_amount) . " " . $payment_datetime->format('M-d-Y H:i:s');
  			break;

  			default:
  				echo "Payment Pending";

  		}
  	}


  	if ( 'VAid' === $colname  )
  	{
  		switch (true)
  		{
  			case ($payment_method == "vabacs") :
                  // display the VA ID with a link that when clicked takes you to payments made for that account
  				//$link_address = 'https://dashboard.cashfree.com/#/app/virtualaccounts/' . $va_id;
  				$data = array(
  								"va_id"			=>	$va_id,
  								"display_name"	=> $user_display_name,
  								"user_id"		=>	$user_id,
  							 );
  				$url_va_payments			= admin_url( 'admin.php?page=woo-VA-payments&', 'https' );
  				$url_va_payments_given_vaid	= $url_va_payments . http_build_query($data, '', '&amp;');
  				echo "<a href='$url_va_payments_given_vaid'>$va_id</a>";
  			break;

  			default:
  				echo "N/A";
  		}
  	}


  	if ( 'Student' === $colname )
  	{
  		echo $order_user->display_name;
  	}
  } // end of function set_orders_newcolumn_values

  /**
  *  @param order is the full order object under consideration
  *  @param payment is the full payment object being considered
  *  @param timezone is the full timezone object needed for order objects timestamp
  *  return a boolean value if the payment and order can be reconciled
  *  Conditions for reconciliation are: (We assume payment method is VABACS and this payment is not reconciled in any order before
  *  1. Payments must be equal
  *  2. Order creation Date must be before Payment Date
  */
  public function reconcilable_ma()
  {
    // since order datetime is from time stamp whereas payment datetime is form actula date and time
    // we will only use settimezone for order datetime and not payment datetime.
    $order    = $this->order;
    $payment  = $this->payment;
    $timezone = $this->timezone;

  	$order_total			      = $order->get_total();
  	$order_created_datetime	= new DateTime( '@' . $order->get_date_created()->getTimestamp());
    $order_created_datetime->setTimezone($timezone);

  	//
  	$payment_amount 		= $payment->amount;      // in ruppees
    $payment_date       = $payment->paymentTime;     // example 2007-06-28 15:29:26
    $payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date);

  	return ( ($order_total == $payment_amount) && ($payment_datetime > $order_created_datetime) );

  } // end of function reconcilable_ma

  /**
  *  @param order is the order object
  *  @param payment is the payment object
  *  @param reconcile is a settings boolean option for non-webhook reconciliation
  *  @param reconcilable is a boolean variable indicating wether order and payment are reconcilable or not
  *  @param timezone is passed in to calculate time of order creation using timestamp
  *  return a boolean value if the payment and order have been reconciled successfully
  *  Conditions for reconciliation are: (We assume payment method is VABACS and this payment is not reconciled in any order before
  *  1. Payments must be equal
  *  2. Order creation Date must be before Payment Date
  *  Reconciliation means that payment is marked complete and order meta updated suitably
  */
  public function reconcile_ma($order, $payment, $reconcile, $reconcilable, $timezone)
  {
    $reconcile    = $this->reconcile;
    $reconcilable = $this->reconcilable;
    $timezone     = $this->timezone;
    $order        = $this->order;
    $payment      = $this->payment;

  	if 	(($reconcile == 0)        ||
  			 ($reconcilable == false))
		{
			return false;	// just a safety check
		}
  	$order_created_datetime	= new DateTime( '@' . $order->get_date_created()->getTimestamp());
  	$order_created_datetime->setTimezone($timezone);

    $payment_date     = $payment->paymentTime;     // example 2007-06-28 15:29:26
    $payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date); // this is already IST

  	$order_note = 'Payment received by cashfree Virtual Account ID: ' . get_post_meta($order->id, 'va_id', true) .
  					      ' Payment ID: ' . $payment->referenceId . '  on: ' . $payment_datetime->format('Y-m-d H:i:s') .
  					      ' UTR reference: ' . $payment->utr;

  	$order->add_order_note($order_note);

  	$order->update_meta_data('va_payment_id', 				     $payment->referenceId);
  	$order->update_meta_data('amount_paid_by_va_payment',  $payment->amount);          // in Rs
  	$order->update_meta_data('bank_reference', 				     $payment->utr);

  	$order->save;

  	$transaction_arr	= array(
            									'payment_id'		=> $payment->referenceId,
            									'payment_date'	=> $payment_datetime->format('Y-m-d H:i:s'),
            									'va_id'				  => get_post_meta($order->id, 'va_id', true),
            									'utr'	          => $payment->utr,
            								 );

  	$transaction_id = json_encode($transaction_arr);

  	$order->payment_complete($transaction_id);

      return true;
  }       // end of function reconcile_ma

  /**
  * @param order is the passed in order object under consideration at checkout
  * @param data is an aray that contains the order meta keys and values when passed in
  * We update the order's va_id meta at checkout.
  */
  public function ma_update_order_meta_atcheckout( $order, $data )
  {
  	// get user associated with this order
  	$payment_method 	    = $order->get_payment_method(); 			// Get the payment method ID

  	// if not vabacs then return, do nothing
  	if ( $payment_method != 'vabacs' )
  	{
  		return;
  	}

  	// get the user ID from order
  	$user_id   			        = $order->get_user_id(); 					// Get the costumer ID
  	// get the user meta
  	$va_id 				          = get_user_meta( $user_id, 'va_id', true );	// get the needed user meta value
    $sritoni_institution    = get_user_meta( $user_id, 'sritoni_institution', true ) ?? 'not set';
    $grade_for_current_fees = get_user_meta( $user_id, 'grade_for_current_fees', true ) ?? 'not set';

      // update order meta using above
  	$order->update_meta_data('va_id',                   $va_id);
    $order->update_meta_data('sritoni_institution',     $sritoni_institution);
    $order->update_meta_data('grade_for_current_fees',  $grade_for_current_fees);

  	return;
  }             // end of function ma_update_order_meta_atcheckout

  /**
  *  This function changes the price displayed in shop and product pages as follows:
  *  It gets the price according grade of logged in user
  *  Price changes applied only to products in product category:grade-dependent-price
  */
  public function spz_change_price($price, $product)
  {
      global $fees_csv;

      // check for programmable category, return if not
      if ( !has_term( 'programmable', 'product_cat', $product->get_id() ) )
      {
        return $price;
      }

      // this product belongs to category grade-dependent-price
      // lets get the price for this user
      // Get the current user
      $current_user 	= wp_get_current_user();
  	  $user_id 		    = $current_user->ID;

      // read the current user's meta
  	  $studentcat 	            = get_user_meta( $user_id, 'sritoni_student_category', true );
  	  $grade_or_class	          = get_user_meta( $user_id, 'grade_or_class', true );
      $grade_for_current_fees   = get_user_meta( $user_id, 'grade_for_current_fees', true );
      $current_fees             = get_user_meta( $user_id, 'current_fees', true ) ?? 0;
      $arrears_amount           = get_user_meta( $user_id, 'arrears_amount', true );
      // set price to full price based on grade of student using lookup table
      // $full_price_fee = $fees_csv[0][$grade_or_class] ?? 0;
      /*
      if (!has_term( 'arrears', 'product_cat', $product->get_id() ))
      {
          // check if user studentcat is installment2 or installment3
          /*
          if (strpos($studentcat, "installment") !== false)
          {
              $num_installments = (int) $studentcat[-1];

              if ($num_installments === 2 || $num_installments === 3)
              {
                  $installment_price = $current_fees/$num_installments;
                  return round($installment_price, 2);
              }
          }

          // not installment nor arrears so return full current amount due
          return $current_fees;
      }

      else
      {
          // this is an arrears product as well as programmable product
          // so return the arrears amount as price
          return $arrears_amount;
      }
      */
      return $current_fees + $arrears_amount;

  }                   // end of function spz_change_price


  /**
  *  setup by add_filter( 'woocommerce_before_add_to_cart_button', 'spz_product_customfield_display');
  *  This function adds text to product just before add-o-cart button
  *  The text is grabbed from user meta dependent on product category: includes current fee, arrears fee, etc.
  *
  */
  public function spz_product_customfield_display()
  {
      // TODO check for programmable product category before doing this
      // get user meta for curent fees description
      $current_user = wp_get_current_user();
      $user_id 		  = $current_user->ID;

      // read the current user's meta
      $current_fee_description 	= get_user_meta( $user_id, 'current_fee_description', true );
      $arrears_description      = get_user_meta( $user_id, 'arrears_description', true );

      // decode json to object
      $current_item             = json_decode($current_fee_description, true);

      // start building HTML for ordered list display
      $output = "<ol>
                      <li>Current fees due for " . $current_item["fees_for"]
                                             . " for AY:"
                                             . $current_item["ay"]
                                             . " of "
                                             . get_woocommerce_currency_symbol()
                                             . number_format($current_item["amount"])
                  . "</li>";

      // decode the arrears array and list them out also
      $arrears_items = json_decode($arrears_description, true);

      foreach ($arrears_items as $item)
      {
          $output .= "<li>Arrears fees due for " . $item["fees_for"]
                                 . " for AY:"
                                 . $item["ay"]
                                 . " of "
                                 . get_woocommerce_currency_symbol()
                                 . number_format($item["amount"])
                                 . "</li>";
      }
      // close the tag
      $output .= "</ol>";

      // display this just above the add-to-cart button`
      echo $output;
  }                           // end of function spz_product_customfield_display


  /**
  *  setup by add_filter( 'woocommerce_add_cart_item_data', 'spz_add_cart_item_data', 10, 3 );
  *  This function adds the fee payment items to cart item data
  */
  public function spz_add_cart_item_data( $cart_item_data, $product_id, $variation_id )
  {
  	/*
  	 error_log('cart item_data object');
  	 error_log(print_r($cart_item_data, true));
  	*/

    // get user meta of logged in user
    $current_user 	= wp_get_current_user();
    $user_id 		= $current_user->ID;

    // read the current user's meta
    $current_fee_description 	= get_user_meta( $user_id, 'current_fee_description', true );
    $arrears_description      = get_user_meta( $user_id, 'arrears_description', true );
    // decode json to object
    $current_item             = json_decode($current_fee_description, true);

  	// add as cart item data, otherwise won;t see this when product is in cart
  	 $cart_item_data['current_item'] = "Current fees due for "  . $current_item["fees_for"]
  																. " for AY:"
  																. $current_item["ay"]
  																. " of "
  																. get_woocommerce_currency_symbol()
  																. number_format($current_item["amount"]);

    $arrears_items = json_decode($arrears_description, true);
    foreach ($arrears_items as $key => $item)
    {
        $index                  = "arrears" . ($key + 1);
        $cart_item_data[$index] = "Arrears fees due for " . $item["fees_for"]
                                . " for AY:"
                                . $item["ay"]
                                . " of "
                                . get_woocommerce_currency_symbol()
                                . number_format($item["amount"]);
    }

	  return $cart_item_data;
  }                             // end of function spz_add_cart_item_data

  /**
  *   This callback gets the cart item data
  */
  public function spz_get_cart_item_data( $item_data, $cart_item_data )
  {
    if( isset( $cart_item_data['current_item'] ) )
     {
     	$item_data[] = array(
                 						'key'   => 'current_item',
                 						'value' => wc_clean( $cart_item_data['current_item'] ),
                 					);
     }

     $arrears_description   = $this->spz_get_user_meta("arrears_description");
     $arrears_items         = json_decode($arrears_description, true);

     foreach ($arrears_items as $key => $item)
     {
       $index = "arrears" . ($key + 1);
       if( isset( $cart_item_data[$index] ) )
  		 {
      	$item_data[] = array(
                  						'key'   => $index,
                  						'value' => wc_clean( $cart_item_data[$index] ),
                  					);
  		 }
     }
  	 return $item_data;
  }                           // end of function spz_get_cart_item_data

  /**
   *  Deprecated Add order item meta.  see function below instead
   *
  */
  public function add_order_item_meta ( $item_id, $values )
  {

  	if ( isset( $values [ 'current_item' ] ) )
      {

  		$custom_data  = $values [ 'current_item' ];
  		wc_add_order_item_meta( $item_id, 'current_item', $custom_data['current_item'] );
  	}
      $arrears_description   = $this->spz_get_user_meta("arrears_description");
      $arrears_items         = json_decode($arrears_description, true);

      foreach ($arrears_items as $key => $item)
      {
          $index = "arrears" . ($key + 1);
          if ( isset( $values [ $index ] ) )
          {

      		$custom_data  = $values [ $index ];
      		wc_add_order_item_meta( $item_id, $index, $custom_data[$index] );
      	}
      }

  }                 // end of function add_order_item_meta

  /**
  *  This is used instead of deprecated woocommerce_add_order_item_meta above
  *  @param item is an instance of WC_Order_Item_Product
  *  @param cart_item_key is the cart item unique hash key
  *  @param values is the cart item
  *  @param order an instance of the WC_Order object
  *  We are copying data from cart item to order item
  */
  public function spz_checkout_create_order_line_item($item, $cart_item_key, $values, $order)
  {
      if( isset( $values['current_item'] ) )
      {
          // overwrite if it exists already
          $item->add_meta_data('current_item', $values['current_item'], true);
      }

      $arrears_description   = $this->spz_get_user_meta("arrears_description");
      $arrears_items         = json_decode($arrears_description, true);

      foreach ($arrears_items as $key => $arrears_item)
      {
          $index = "arrears" . ($key + 1);
          if ( isset( $values [ $index ] ) )
          {
              // overwrite if it exists already
              $item->add_meta_data($index, $values[$index], true);
      	}
      }
  }                 // end of function spz_checkout_create_order_line_item

  /**
  *
  */
  public function spz_get_user_meta($field)
  {
    $current_user = wp_get_current_user();
  	$user_id 		  = $current_user->ID;
    $meta         = get_user_meta( $user_id, $field, true );
    return $meta;
  }

  /**
   * This routine is attributed to https://github.com/rap2hpoutre/csv-to-associative-array
    *
   * The items in the 1st line (column headers) become the fields of the array
   * each line of the CSV file is parsed into a sub-array using these fields
   * The 1st index of the array is an integer pointing to these sub arrays
   * The 1st row of the CSV file is ignored and index 0 points to 2nd line of CSV file
   * This is the example data:
   *
   * grade1,grade2,grade3
   * 10000,20000,30000
   *
   * This is the associative array
   * Array
   *(
   *  [0] => Array
   *      (
   *          [grade1] => 10000
   *          [grade2] => 20000
   *          [grade3] => 30000
   *      )
   * )
   */
  public function csv_to_associative_array($file, $delimiter = ',', $enclosure = '"')
  {
      if (($handle = fopen($file, "r")) !== false)
      {
          $headers = fgetcsv($handle, 0, $delimiter, $enclosure);
          $lines = [];
          while (($data = fgetcsv($handle, 0, $delimiter, $enclosure)) !== false)
          {
              $current = [];
              $i = 0;
              foreach ($headers as $header)
              {
                  $current[$header] = $data[$i++];
              }
              $lines[] = $current;
          }
          fclose($handle);
          return $lines;
  	}
  }

  public function max_grouped_price( $price_this_get_price_suffix, $instance, $child_prices )
  {

      return wc_price(array_sum($child_prices));
  } // end of unused function max_grouped_price

  /**
 * Filter products on the shop page based on user meta: sritoni_student_category, grade_or_class
 * The filter is not applicable to shop managers and administrators
 * If user meta sritoni_student_category is installment then only products belonging to BOTH
 *    categories Installment AND that pointed to by user meta "grade_or_class".
 * If user meta "sritoni_student_category" does not contain installment then only show products
 *    NOT in Installment category AND any products in Common OR pointed by user meta "grade_or_class"
 * https://docs.woocommerce.com/document/exclude-a-category-from-the-shop-page/
 * https://stackoverflow.com/questions/39004800/how-do-i-hide-woocommerce-products-with-a-given-category-based-on-user-role
 */
  public function installment_pre_get_posts_query( $q )
  {
  	// Get the current user
    $current_user 	= wp_get_current_user();
  	$user_id 		    = $current_user->ID;
  	$studentcat 	  = get_user_meta( $user_id, 'sritoni_student_category', true );
  	$grade_or_class	= get_user_meta( $user_id, 'grade_or_class', true );

    // get user meta of grade to pay for
    $grade_for_current_fees	= get_user_meta( $user_id, 'grade_for_current_fees', true );

    // get arrears amount from user meta
    $arrears_amount	        = get_user_meta( $user_id, 'arrears_amount', true );

    // if student has arrears we set a string corresponding to arrears category
    $arrears        = ($arrears_amount > 0) ? "arrears" : "";

  	if ( in_array( "shop_manager", $current_user->roles )  || in_array( "administrator", $current_user->roles ) )
  	{
  		return; // no product filter for admin and shop_manager just return
  	}

  	$tax_query = (array) $q->get( 'tax_query' );

  	if (  strpos($studentcat, "installment") !==false )
  	{
          // product has category of installments but just display products belonging to grade_or_class
  		$tax_query[] = array(
  			'relation' => 'OR',
  				array(
  				   'taxonomy' => 'product_cat',
  				   'field' => 'slug',
  				   'terms' => array( $grade_or_class, $arrears, $grade_for_current_fees, "common" ), 	//
  				   'operator' => 'IN'										//
  					 ),									// OR
  				array(
  				   'taxonomy' => 'product_cat',
  				   'field' => 'slug',
  				   'terms' => array( $grade_or_class, $arrears, $grade_for_current_fees, "common" ),
  				   'operator' => 'IN'
  				     )
  							);
  		$q->set( 'tax_query', $tax_query );
  	}
  	else
  	{
          // products are for non-installment category just again display all products
          // of categories: grade, arrears, common
  		$tax_query[] = array(
  			'relation' => 'OR',
  				array(
  				   'taxonomy' => 'product_cat',
  				   'field' => 'slug',
  				   'terms' => array( $grade_or_class, $arrears, $grade_for_current_fees, "common" ),
  				   'operator' => 'IN'
  					 ),												// AND
  				array(
  				   'taxonomy' => 'product_cat',
  				   'field' => 'slug',
  				   'terms' => array( $grade_or_class, $arrears, $grade_for_current_fees, "common" ), 	// OR of terms
  				   'operator' => 'IN'
  					 )
  							);
  		$q->set( 'tax_query', $tax_query );
  	}
  }           // end of function installment_pre_get_posts_query

}             // end of class definition
