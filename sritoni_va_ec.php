<?php
/*
*/

// if directly called die. Use standard WP and Moodle practices
if (!defined( "ABSPATH" ) && !defined( "MOODLE_INTERNAL" ) )
    {
    	die( 'No script kiddies please!' );
    }

// class definition begins
class sritoni_va_ec
{
  protected $var1 = 'variable declaration here';
  const VERBOSE   = true;

  public function __construct()
  {
    $this->verbose      = self::VERBOSE;

    /* hook for adding custom columns on woocommerce orders page
    add_filter( 'manage_edit-shop_order_columns',         'orders_add_mycolumns' );
    // hook for updating my new column valus based on passed order details
    add_action( 'manage_shop_order_posts_custom_column',  'set_orders_newcolumn_values', 2 );
    // hook for callback function to be done after order's status is changed to completed
    add_action( 'woocommerce_order_status_completed',     'moodle_on_order_status_completed', 10, 1 );
    */

    // add_action('plugins_loaded', array($this, 'init_class_functions'));

  }

  public function init_class_functions()
  {
    add_submenu_page( 'woocommerce',	'VA-payments',	'VA-payments',	'manage_options',	'woo-VA-payments',		array($this, 'VA_payments_callback' ));

    //add_submenu_page( 'woocommerce',	'reconcile',	'reconcile',	'manage_options',	'reconcile-payments',	array($this, 'reconcile_payments_callback' ));
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

  public function VA_payments_callback()
  {
  	$timezone = new DateTimeZone('Asia/Kolkata');
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
  	$payments	= $cashfree_api->getPaymentsForVirtualAccount($va_id);	// list of payments msde into this VA
      // if no payments made exit
      if (empty($payments))
      {
          echo 'No payments made into this VA';
          return;
      }
  	//
  	foreach ($payments as $key=> $payment)
  		{
  			//error_log(print_r("payment details: " . "for number: " .$key ,true));
  			//error_log(print_r($payment ,true));
  			$payment_id			= $payment->referenceId;
  			$payment_amount	= $payment->amount;	        // in ruppees

        $payment_date       = $payment->paymentTime;    // example 2007-06-28 15:29:26

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
  			// get all orderes in process or completed with search parameters as shown above
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
  *	This implements the callback for the sub-menu page reconcile.
  *   When this manu page is accessed from the admin menu under Woocommerce, it tries to reconcile all open orders against payments made
  *   Normally the reconciliation should be done as soon as a payment is made by a webhook issued by the payment gateway.
  *	Should the webhook reconciliation fail for whatever reason, an on-demand reconciliation can be forced by accessing this page.
  */
  public function reconcile_payments_callback()
  {
  	$maxReturn		=	3;					// maximum number of payments to be returned for any payment account to reconcile
  	$max_orders		=	30;					// maximum number of orders that are reconciled in one go
  	$timezone		= new DateTimeZone("Asia/Kolkata");
  	// get all open orders for all users
  	$args = array(
  						'status' 			=> 'on-hold',
  						'payment_method' 	=> 'vabacs',
  				  );
  	$orders = wc_get_orders( $args );
  	// if no orders on-hold then nothing t reconcile so exit
  	if (empty($orders))
  	{
  		echo 'No orders on-hold, nothing to reconcile';
  		return;
  	}
  	// Instantiate payment gateway API along with authetication
  	$cashfree_api 			= new CfAutoCollect; // new cashfree Autocollect API object
  	// For each order get the last 3 payments made. Assumption is that there are not more than 3 payments made after an order is placed
  	$order_count = 0;
  	foreach ($orders as $order)
  	{
  		// get user payment account ID details from order
  		$va_id		= get_post_meta($order->id, 'va_id', true) ?? 'unable to get VAID from order meta';
  		// get the last few payments bade by tis account
  		$payments	= $cashfree_api->getPaymentsForVirtualAccount($va_id, $maxReturn);
  		foreach ($payments as $payment)
  		{
  			if ( !reconcilable1_ma($order, $payment, $timezone) )
  				{
  				// this payment is not reconcilable either due to mismatch in payment or dates or both
  				continue;	// continue next payment
  				}
  			else
  				{
  					reconcile1_ma($order, $payment, $timezone);
                      echo 'Order No: ' . $order->id . ' Reconciled with Payment ID: ' . $payment->referenceId;
  					break;	// break out of payment loop and process next order
  				}
  		}
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
  function reconcilable1_ma($order, $payment, $timezone)
  {
      // since order datetime is from time stamp whereas payment datetime is form actula date and time
      // we will only use settimezone for order datetime and not payment datetime.
  	$order_total			= $order->get_total();
  	// $order_total_p			= (int) round($order_total * 100);
  	$order_created_datetime	= new DateTime( '@' . $order->get_date_created()->getTimestamp());
      $order_created_datetime->setTimezone($timezone);
      // we don't care about the time zone adjustment since it will be common for all dates for comparison purpses
  	//
  	$payment_amount 		= $payment->amount;      // in ruppees
      $payment_date       = $payment->paymentTime;     // example 2007-06-28 15:29:26
      $payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date);
      // $payment_datetime->setTimezone($timezone);

  	return ( ($order_total == $payment_amount) && ($payment_datetime > $order_created_datetime) );

  } // END OF function reconcilable1_ma($order, $payment, $timezone)

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
  public function reconcile1_ma($order, $payment, $timezone)
  {
  	$order_created_datetime	= new DateTime( '@' . $order->get_date_created()->getTimestamp());
  	$order_created_datetime->setTimezone($timezone);

      $payment_date       = $payment->paymentTime;     // example 2007-06-28 15:29:26
      $payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date); // this is already IST
  	$order_note = 'Payment received by cashfree Virtual Account ID: ' . get_post_meta($order->id, 'va_id', true) .
  					' Payment ID: ' . $payment->referenceId . '  on: ' . $payment_datetime->format('Y-m-d H:i:s') .
  					' UTR reference: ' . $payment->utr;
  	$order->add_order_note($order_note);

  	$order->update_meta_data('va_payment_id', 				$payment->referenceId);
  	$order->update_meta_data('amount_paid_by_va_payment', 	$payment->amount);  // in Rs
  	$order->update_meta_data('bank_reference', 				$payment->utr);
  	// $order->update_meta_data('payment_notes_by_customer', 	$payment_obj->description);
  	$order->save;

  	$transaction_arr	= array(
  									'payment_id'		=> $payment->referenceId,
  									'payment_date'		=> $payment_datetime->format('Y-m-d H:i:s'),
  									'va_id'				=> get_post_meta($order->id, 'va_id', true),
  									'utr'	            => $payment->utr,
  								);

  	$transaction_id = json_encode($transaction_arr);

  	$order->payment_complete($transaction_id);

      return true;
  }

}           // end of class definition
