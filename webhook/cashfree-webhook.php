<?php

require_once __DIR__.'/../sritoni_cashfree.php';            // plugin file
require_once __DIR__.'/../cfAutoCollect.inc.php';           // API class file
require_once __DIR__.'/../sritoni_cashfree_settings.php';   // API settings file

class CF_webhook
{
    /**
     * API client instance to communicate with Razorpay API
     *
     */
    protected $api;

    /**
     * Event constants
     */
    const AMOUNT_COLLECTED          = 'AMOUNT_COLLECTED';

	const VERBOSE			 		= true;							// MA
	const TIMEZONE					= 'Asia/Kolkata';				// MA

    function __construct()
    {
        // no need for site name argument to be passed in since this is WP environment
        $this->api 		= new CfAutoCollect;
        // sets verbose mode based on constant defined above
		$this->verbose	= self::VERBOSE;
        // sets timezone object to IST
		$this->timezone =  new DateTimeZone(self::TIMEZONE);
        // setup property clientSecret using api data, needed for webhook signature verification
        $this->clientSecret = $this->api->get_clientSecret();
    }

    /**
     * Process a Razorpay Webhook. We exit in the following cases:
     * - Successful processed
     * - Exception while fetching the payment
     *
     * It passes on the webhook in the following cases:
     * - Signature mismatch
     * - Secret isn't setup
     * - Event not recognized
     */
    public function process()
    {
        $data = $_POST;
        $signature = $_POST["signature"];
        unset($data["signature"]);
        ksort($data);
        // check if signature is verified
        $signature_verified = $this->verify_signature($data, $signature);
        if (!$signature_verified)
        {
            error_log('Signature not verified for Webhook, below is dump of webhook packet');
            foreach ($data as $key => $value)
            {
                error_log($key." : ".$value);
            }
            return;
        }
        // signature is verified, process webhook further
        switch ($data['event'])
        {
            case self::AMOUNT_COLLECTED:
                if ($this->verbose)
                    {
    					error_log('webhook event type: ' . $data['event'] );
    				}
				return $this->amountCollected($data);

            default:
                return ;
        }
    }

    /**
    *
    * @param data is webhook data without signature and sorted by keys
	* @param signature is the value extracted form webhook and passed in
    * returns boolean true if signature verified, otherwise returns false
    */
    protected function verify_signature($data, $signature)
    {
        $postData = "";

        foreach ($data as $key => $value)
        {
          if (strlen($value) > 0)
          {
            $postData .= $value;
          }
        }
        $clientSecret = $this->clientSecret;
error_log($clientSecret . "ajn4rhdj");   // for debugging only, delete when done
        $hash_hmac = hash_hmac('sha256', $postData, $clientSecret, true) ;
        $computedSignature = base64_encode($hash_hmac);
        if ($signature == $computedSignature)
        {
            if ($this->verbose)
            {
                error_log("Webhook Signature verified");
                foreach ($data as $key => $value)
                {
                    error_log($key." : ".$value);
                }
            }
            return true;
        }
        else
        {
          error_log("webhook Signature FAILED verification");
          return false;
        }
    }

	/**
     * Handling the virtual acount credited webhook
     *
     * @param array $data Webook Data
     */
    protected function amountCollected(array $data)
    {
        // payment datetime object already in IST derived from webhook data directly
        $payment_datetime	= DateTime::createFromFormat('Y-m-d H:i:s', $data["paymentTime"]);

		// get the vAccountId from webhook
        $vAccountId     = $data["vAccountId"];
		// convert this to an integer to remove any leading 0s that mayhave been used for padding
        $moodleuserid   = (int)$vAccountId;
		// use this as login to get WP user ID
		$wp_user 	= get_user_by('login', $moodleuserid);       // get user by login (same as Moodle userid in User tables)
		$wp_userid 	= $wp_user->ID ?? "web_hook_wpuser_not_found";      // get WP user ID
        // cannot reconcile using order id in payment info since cashfree doesn't provide any
		// so we follow the old method of reconciliation by checking all open orders
		// Idempotent: Is this payment already reconciled?	If so webhook is redundant, exit
		if ( $this->anyReconciledOrders($payment_id, $wp_userid)  )
			{
				return;
			}


		// If we have reached this far, webhook data is fresh and so let's reconcile webhook payment against any valid open on-hold vabacs order
		$open_orders = $this->getOpenOrders($wp_userid);

		// if null exit, there are no open orders for this user to reconcile
		if ( $open_orders == null )
			{
				return;
			}
		else
			{
				// we do have open orders for this user, so lets see if we can reconcile the webhook payment to one of these open orders
				$reconciledOrder = $this->reconcileOrder($open_orders, $data, $payment_datetime, $wp_userid);
				// if reconciled order is null then exit
				if ( $reconciledOrder == null )
					{
						return;
					}
				// If we got here, we must have a $reconcileOrder, lets update the meta and status
				$this->orderUpdateMetaSetCompleted($reconciledOrder, $data, $payment_datetime, $wp_userid);
				return;
			}

	}

    /**
     * Returns the order amount, rounded as integer
     * @param WC_Order $order WooCommerce Order instance
     * @return int Order Amount
     */
    public function getOrderAmountAsInteger($order)
    {
        if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>='))
        {
            return (int) round($order->get_total() * 100);
        }

        return (int) round($order->order_total * 100);
    }


	/**
     * Gets any orders that maybe already reconciled with this payment
	 * return false if if no reconciled orders already exist for this webhook payment
	 * return true if this payment is alreay present in an existing completed / processsing order
     */
    protected function anyReconciledOrders($payment_id, $wp_userid)
    {
        $payment_id     = $data["referenceId"];
        $args = array(
						'status' 			=> array(
														'processing',
														'completed',
													),
						'limit'				=> 1,			// at least one order exists for this payment?
						'payment_method' 	=> 'vabacs',
						'customer_id'		=> $wp_userid,
						'meta-key'			=> "va_payment_id",
						'meta_value'		=> $payment_id,
					 );
		$orders_completed = wc_get_orders( $args ); // these are orders for this user either processing or completed

		if (!empty($orders_completed))
		{	// we already have completed orders that reconcile this payment ID so this webhook must be old or redundant, so quit
			if ($this->verbose)
			{
				error_log('Following orders already completed using this payment_id:' . $payment_id);
				foreach ($orders_completed as $order)
						{
							error_log( 'Order No: ' . $order->get_id() );
						}
			}
			// true, reconciled eorders exist
			return true;
		}
		// false, reconciled orders don't exist for this payment
		return false;
    }

	/**
     * returns any open orders object for this user
     */
    protected function getOpenOrders($wp_userid)
    {
        $args = array(
						'status' 			=> 'on-hold',
						'payment_method' 	=> 'vabacs',
						'customer_id'		=> $wp_userid,
					 );
		$orders = wc_get_orders( $args );

		if (empty($orders))
			{	// No orders exist for this webhook payment ID, log that fact and return null
				if ($this->verbose)
					{
						error_log('No Orders on-hold for this user so cannot reconcile this webhook payment');
					}
				return null;
			}
		// we have valid open orders for this user
		if ($this->verbose)
					{
						foreach ($orders as $order)
						{
							error_log('Order No: ' . $order->get_id() . ' Open for this user');
                            error_log('Lets see if the above order can be reconciled with this webhook payment');
						}
					}
		return $orders;
    }

	/**
     * take all open orders and see if they can be reconciled against webhook payment
	 * Order is reconciled if:
	 * 1. Payments are same
	 * 2. Payment date is after Order creation date
	 * 3. Order user is same as user associated with payment: (This was already in the query for wc_get_orders)
	 * 4. Order is on-hold: (This was already in the query for wc_get_orders)
	 * 5. Payment method is VABACS: (This was already in the query for wc_get_orders)
	 * return null or reconciled order object
     */
    protected function reconcileOrder($orders, $data, $payment_datetime, $wp_userid)
    {
        foreach ($orders as $key => $order)
		{
			$order_creation_datetime		= new DateTime( '@' . $order->get_date_created()->getTimestamp());
			$order_creation_datetime->setTimezone($this->timezone);	// this needs adjustment to timezone since derived from unix timestamp

			if 	(
					( $data["amount"] == $order->get_total()       )	&&		// payment amount matches order amount in paise
					( $payment_datetime > $order_creation_datetime )						// payment is after order creation
				)
			{
				// we satisfy all conditions, this order reconciles with the webhook payment
                if ($this->verbose)
                {
                    error_log('Order No: ' . $order->get_id() . ' is reconcilable with webhook payment');
                }
				return $order;
			}
			else
			{
				// This order does not reconcile with our webhook payment so check for next order in loop`
				continue;
			}

		}
		// we have checked all orders and none can be reconciled with our webhook payment
		return null;
    }

	/**
     * Updates Meta of Reconciled Order and changes its status to completed
     */
    protected function orderUpdateMetaSetCompleted($order, $data, $payment_datetime, $wp_userid)
    {
		$order_note = 'Payment received by Cashfree Virtual Account ID: ' . $data["vAccountId"] .
					              ' Payment Reference ID: ' . $data["referenceId"] . '  on: ' . $payment_datetime->format('Y-m-d H:i:s') .
					              ' utr reference: ' . $data["utr"];

		$order->add_order_note($order_note);

		$order->update_meta_data('va_payment_id', $data["referenceId"]);
		$order->update_meta_data('amount_paid_by_va_payment', $data["amount"]);  // in Rs
		$order->update_meta_data('bank_reference', $data["utr"]);
		//$order->update_meta_data('payment_notes_by_customer', $payment_obj->description);
		$order->save;

		$transaction_arr	= array(
										'payment_id'		=> $data["referenceId"],
										'payment_date'		=> $payment_datetime->format('Y-m-d H:i:s'),
										'va_id'				=> $data["vAccountId"],
										'bank_reference'	=> $data["utr"],
									);

		$transaction_id = json_encode($transaction_arr);

		$order->payment_complete($transaction_id);

		if ($this->verbose) {
			error_log('Order:' . $order->get_id() .
                    ' status payment complete, meta updated with Webhook payment:' . $transaction_id );
		}

        return true;
    }

	/*
	*  extracts order no if any, present on the payment description as entered by payer
	*  If the payment is valid and amounts and dates are reconciled then this order is considered reconcilable
	   The function either returns null or the the reconciled order
	*/
	protected function reconcileOrderUsingPaymentInfo($payment_obj, $wp_userid, $payment_datetime)
	{
		// extract payment information from payment object
		$str = $payment_obj->description;
		if ( empty($str)  )
			{
				error_log(print_r('payment description: ' . $str , true));
				return null;
			}
		$str = str_replace(array('+','-'), '', $str);
		$orderIdInPayment = abs((int) filter_var($str, FILTER_SANITIZE_NUMBER_INT));

		// see if an order exists with this order number and with necessary other details
		$order = wc_get_order($orderIdInPayment);
		// return if order doesn't exist and reconcilde using usual way
		if ( empty($order) )
			{
				error_log(print_r('Extracted order ID from payment description: ' . $orderIdInPayment , true));
				error_log(print_r('wc_get_order object was empty, so returning' , true));
				return null;
			}
		// so we ow have a valid order although we don;t know if amounts and dates are compatible so lets check.
		$order_creation_datetime		= new DateTime( '@' . $order->get_date_created()->getTimestamp());
		$order_creation_datetime->setTimezone($this->timezone);

		if 	(
					( $payment_obj->amount == round($order->get_total() * 100) ) 	&&		// payment amount matches order amount in paise
					( $payment_datetime > $order_creation_datetime )						// payment is after order creation
																						)
			{
				// we satisfy all conditions, this order reconciles with the webhook payment
				if ($this->verbose)
					{
						error_log(print_r('Reconciled order No: ' . $order->get_id() . ' using Order number in payment description', true));
						error_log(print_r($payment_obj , true));
					}
				return $order;
			}
		// even though we could get some order based on payment description of payer, this is  not reconcilable so return null
		return null;

	}

}
