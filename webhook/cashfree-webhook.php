<?php

// version 1.3 gets setting to verify IP of webhook
// version 1.2 gets ip_whitelist string from options
// version 1.1 checks if webhook IP is whitelisted

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
     * Process a Cashfree Webhook. We exit in the following cases:
     * - Check that the IP is whitelisted
     * . Extract the signature and verify /**
     * . Once IP is in whitelist and signature is verified process the webhook
     * . only event 'amount_colected' is processed
     */
    public function process()
    {
        $ip_whitelist_arr       = array();      // declare an empty array
        $domain_ip_arr          = array();      // declare empty array
        $domain_whitelist_arr   = array();      // declare empty array
        $domain_ip_arr          = array();      // declare empty array
        // get comma separated string of whitelisted IP's
        $ip_whitelist_str  = get_option( 'sritoni_settings')['ip_whitelist'];
        $domain_whitelist  = get_option( 'sritoni_settings')['domain_whitelist'];
        // convert this into an array of IP's
        if ( !empty($ip_whitelist_str) )
            {
                $ip_whitelist_arr  = explode("," , $ip_whitelist_str);
            }
        // get ips associated with domain
        if ( !empty($domain_whitelist) )
            {
                $domain_whitelist_arr = explode("," , $domain_whitelist);

            }
        foreach ($domain_whitelist_arr as $domain)
        {
            $domain_ip      = (array) gethostbynamel($domain);
            $domain_ip_arr  = array_merge($domain_ip_arr, $domain_ip);
        }
        // make a master whitelsited ip array
        $whitelist_ip_arr = array_merge($ip_whitelist_arr, $domain_ip_arr);
        // get IP of webhook server
        $ip_source = $_SERVER['REMOTE_ADDR'];

        // get the setting wether to verify the IP of the webhook
        $verify_webhook_ip = get_option( 'sritoni_settings')['verify_webhook_ip'] ?? 0;
        // If flag to verify webhook IP is set then verify if webhook is whitelsited
        if ($verify_webhook_ip)
        {
            if (!in_array($ip_source, $whitelist_ip_arr))
            {
                // do not trust this webhook since its IP is not in whitelist
                // but log its contents just so we can see what it contains
                error_log('IP of Webhook not in whitelsit-rejected, below is dump of webhook packet');
                error_log('IP address of webhook is: ' . $ip_source);
                foreach ($data as $key => $value)
                {
                    error_log($key." : ".$value);
                }
                return;
            }
            else
            {
                ($this->verbose ? error_log('IP of Webhook IS in whitelist..continue processing: ' . $ip_source) : false);
            }
        }
        else
        {
            ($this->verbose ? error_log('Webhook IP NOT checked against whitelist per setting..continue processing: ' . $ip_source) : false);
        }

        $data = $_POST;
        $signature = $_POST["signature"];

        // prepare data for signature verification
        unset($data["signature"]);
        ksort($data);
        // check if signature is verified
        $signature_verified = $this->verify_signature($data, $signature);
        if ($this->verbose)
        {
            error_log('IP whitelist array');
            error_log(print_r($whitelist_ip_arr, true));

            error_log('ip of webhook source: ' . $ip_source);

            error_log('Wbhook Signature verified?: ' . $signature_verified);

            error_log('Below is dump of webhook packet');
            error_log(print_r($data, true));
        }
        if (!$signature_verified)
        {
            // signature is not valid, log and die
            error_log('Signature not verified for Webhook, below is dump of webhook packet');
            foreach ($data as $key => $value)
            {
                error_log($key." : ".$value);
            }
            return;
        }
        // if reached this far, webhook signature is verified, IP is whitelsited, process webhook
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
        // error_log($clientSecret . "ajn4rhdj");   // for debugging only, delete when done
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
          foreach ($data as $key => $value)
          {
              error_log($key." : ".$value);
          }
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
        // get payment ID of webhook
        $payment_id = $data["referenceId"];
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
		if ( empty($open_orders) )
			{
                return;
			}
		else
			{
				// we do have open orders for this user, so lets see if we can reconcile the webhook payment to one of these open orders
				$reconciledOrder = $this->reconcileOrder($open_orders, $data, $payment_datetime, $wp_userid);
				// if reconciled order is null then exit
				if ( empty($reconciledOrder) )
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
     * @param payment_id
     * @param wp_userid
     * Gets any orders that maybe already reconciled with this payment
	 * return false if if no reconciled orders already exist for this webhook payment
	 * return true if this payment is alreay present in an existing completed / processsing order
     */
    protected function anyReconciledOrders($payment_id, $wp_userid)
    {
        //$payment_id     = $data["referenceId"];
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
				error_log('Following order already completed using this payment_id:' . $payment_id);
				foreach ($orders_completed as $order)
						{
							error_log( 'Order No: ' . $order->get_id() );
						}
			}
			// true, reconciled orders do exist, return boolean true
			return true;
		}
		// false, reconciled orders don't exist for this payment
		return false;
    }

	/**
    * @param wp_userid is the wordpress user id, also the WC customer id
     * returns all vabacs orders for this user thar are on-hold
     * returns null if there are no orders on-hold for this wp-userid
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
		// we have valid open orders for this user, can be more than 1 but typically only 1 should exist
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
        if ($this->verbose)
                {
                    error_log('All on-hold vabacs orders for this user checked, none can be reconciled with this payment');
                }
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
