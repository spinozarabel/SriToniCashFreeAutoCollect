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

    // hook for adding custom columns on woocommerce orders page
    add_filter( 'manage_edit-shop_order_columns',         'orders_add_mycolumns' );
    // hook for updating my new column valus based on passed order details
    add_action( 'manage_shop_order_posts_custom_column',  'set_orders_newcolumn_values', 2 );
    // hook for callback function to be done after order's status is changed to completed
    add_action( 'woocommerce_order_status_completed',     'moodle_on_order_status_completed', 10, 1 );

    $this->add_VA_payments_submenu();
  }

  public function add_VA_payments_submenu()
  {

      /*
  	add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable $function = '' )
  	*					parent slug		newsubmenupage	submenu title  	capability			new submenu slug		callback for display page
  	*/
  	add_submenu_page( 'woocommerce',	'VA-payments',	'VA-payments',	'manage_options',	'woo-VA-payments',		array($this, 'VA_payments_callback' ));

  	/*
  	* add another submenu page for reconciling orders and payments on demand from admin menu
  	*/
  	add_submenu_page( 'woocommerce',	'reconcile',	'reconcile',	'manage_options',	'reconcile-payments',	'reconcile_payments_callback' );

  	return;
  }         // end of function add_VA_payments_submenu() definition

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

}           // end of class definition
