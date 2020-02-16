<?php
/**
*Plugin Name: SriToni Cashfree Autocollect
*Plugin URI:
*Description: SriToni Admin interface to Cashfree Autocollect Virtual accounts
*Version: 2019103100
*Author: Madhu Avasarala
*Author URI: http://sritoni.org
*Text Domain: sritoni_cashfree_autocollect
*Domain Path:
*/
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once(__DIR__."/sritoni_cashfree.php");  	    // main plugin file contains vabacs pg
require_once(__DIR__."/MoodleRest.php");   				// Moodle REST API driver for PHP
require_once(__DIR__."/sritoni_cashfree_settings.php"); // file containing class for settings submenu and page
require_once(__DIR__."/cfAutoCollect.inc.php");         // contains cashfree api class
require_once(__DIR__."/webhook/cashfree-webhook.php");  // contains webhook class

if ( is_admin() )
{ // add sub-menu for a new payments page
  add_action('admin_menu', 'add_VA_payments_submenu');
  // Now add a new submenu for sritoni cashfree plugin settings in Woocommerce. This is to be done only once!!!!
  $sritoniCashfreeSettings = new sritoni_cashfree_settings();
}

$moodle_token 	     = get_option( 'sritoni_settings')["sritoni_token"];
$moodle_url         = get_option( 'sritoni_settings')["sritoni_url"] . '/webservice/rest/server.php';

$get_csv_fees_file  = get_option( 'sritoni_settings')["get_csv_fees_file"] ?? false;
$csv_file           = get_option( 'sritoni_settings')["csv_fees_file_path"];

add_action('plugins_loaded', 'init_vabacs_gateway_class');
// hook action for post that has action set to cf_wc_webhook
// When that action is discovered the function cf_webhook_init is fired
// https://sritoni.org/hset-payments/wp-admin/admin-post.php?action=cf_wc_webhook
add_action('admin_post_nopriv_cf_wc_webhook', 'cf_webhook_init', 10);

if ($get_csv_fees_file)
{
    // read file and parse to associative array. To access this in a function, make this a global there
    $fees_csv = csv_to_associative_array($csv_file);
}


function init_vabacs_gateway_class()
	{
	class WC_Gateway_VABACS extends WC_Payment_Gateway {  // MA
	/**
	 * Array of locales
	 *
	 * @var array
	 */
	public $locale;
	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id                 = 'vabacs';  // MA
		$this->icon               = apply_filters( 'woocommerce_bacs_icon', '' );
		$this->has_fields         = false;
		$this->method_title       = __( 'Offline Bank Transfer to Cashfree Virtual Account', 'woocommerce' );
		$this->method_description = __( 'BACS to Individual Cashfree Virtual Account-offline direct bank transfer', 'woocommerce' );
		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();
		// Define user set variables.
		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->instructions = $this->get_option( 'instructions' );
		// BACS account fields shown on the thanks page and in emails.
		$this->account_details = get_option(
			'woocommerce_bacs_accounts',
			array(
				array(
					'account_name'   => $this->get_option( 'account_name' ),
					'account_number' => $this->get_option( 'account_number' ),
					'sort_code'      => $this->get_option( 'sort_code' ),
					'bank_name'      => $this->get_option( 'bank_name' ),
					'iban'           => $this->get_option( 'iban' ),
					'bic'            => $this->get_option( 'bic' ),
				),
			)
		);
		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_account_details' ) );
		add_action( 'woocommerce_thankyou_vabacs', array( $this, 'thankyou_page' ) );
		// Customer Emails.
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}
	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable bank transfer', 'woocommerce' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'Direct bank transfer', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
				'default'     => __( 'Make your payment directly into Assigned Virtual Bank Account. Please use your Order ID as the payment reference. Your order will not be shipped until the funds have cleared in our account.', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'instructions'    => array(
				'title'       => __( 'Instructions', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Instructions that will be added to the thank you page and emails.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'account_details' => array(
				'type' => 'account_details',
			),
		);
	}
	/**
	 * Generate account details html.
	 *
	 * @return string
	 */
	public function generate_account_details_html() {
		ob_start();
		$country = WC()->countries->get_base_country();
		$locale  = $this->get_country_locale();
		// Get sortcode label in the $locale array and use appropriate one.
		$sortcode = isset( $locale[ $country ]['sortcode']['label'] ) ? $locale[ $country ]['sortcode']['label'] : __( 'Sort code', 'woocommerce' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Account details:', 'woocommerce' ); ?></th>
			<td class="forminp" id="bacs_accounts">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<thead>
							<tr>
								<th class="sort">&nbsp;</th>
								<th><?php esc_html_e( 'Account name', 'woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Account number', 'woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Bank name', 'woocommerce' ); ?></th>
								<th><?php echo esc_html( $sortcode ); ?></th>
								<th><?php esc_html_e( 'IBAN', 'woocommerce' ); ?></th>
								<th><?php esc_html_e( 'BIC / Swift', 'woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody class="accounts">
							<?php
							$i = -1;
							if ( $this->account_details ) {
								foreach ( $this->account_details as $account ) {
									$i++;
									echo '<tr class="account">
										<td class="sort"></td>
										<td><input type="text" value="' . esc_attr( wp_unslash( $account['account_name'] ) ) . '" name="bacs_account_name[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( $account['account_number'] ) . '" name="bacs_account_number[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( wp_unslash( $account['bank_name'] ) ) . '" name="bacs_bank_name[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( $account['sort_code'] ) . '" name="bacs_sort_code[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( $account['iban'] ) . '" name="bacs_iban[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( $account['bic'] ) . '" name="bacs_bic[' . esc_attr( $i ) . ']" /></td>
									</tr>';
								}
							}
							?>
						</tbody>
						<tfoot>
							<tr>
								<th colspan="7"><a href="#" class="add button"><?php esc_html_e( '+ Add account', 'woocommerce' ); ?></a> <a href="#" class="remove_rows button"><?php esc_html_e( 'Remove selected account(s)', 'woocommerce' ); ?></a></th>
							</tr>
						</tfoot>
					</table>
				</div>
				<script type="text/javascript">
					jQuery(function() {
						jQuery('#bacs_accounts').on( 'click', 'a.add', function(){
							var size = jQuery('#bacs_accounts').find('tbody .account').length;
							jQuery('<tr class="account">\
									<td class="sort"></td>\
									<td><input type="text" name="bacs_account_name[' + size + ']" /></td>\
									<td><input type="text" name="bacs_account_number[' + size + ']" /></td>\
									<td><input type="text" name="bacs_bank_name[' + size + ']" /></td>\
									<td><input type="text" name="bacs_sort_code[' + size + ']" /></td>\
									<td><input type="text" name="bacs_iban[' + size + ']" /></td>\
									<td><input type="text" name="bacs_bic[' + size + ']" /></td>\
								</tr>').appendTo('#bacs_accounts table tbody');
							return false;
						});
					});
				</script>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}
	/**
	 * Save account details table.
	 */
	public function save_account_details() {
		$accounts = array();
		// phpcs:disable WordPress.Security.NonceVerification.NoNonceVerification -- Nonce verification already handled in WC_Admin_Settings::save()
		if ( isset( $_POST['bacs_account_name'] ) && isset( $_POST['bacs_account_number'] ) && isset( $_POST['bacs_bank_name'] )
			 && isset( $_POST['bacs_sort_code'] ) && isset( $_POST['bacs_iban'] ) && isset( $_POST['bacs_bic'] ) ) {
			$account_names   = wc_clean( wp_unslash( $_POST['bacs_account_name'] ) );
			$account_numbers = wc_clean( wp_unslash( $_POST['bacs_account_number'] ) );
			$bank_names      = wc_clean( wp_unslash( $_POST['bacs_bank_name'] ) );
			$sort_codes      = wc_clean( wp_unslash( $_POST['bacs_sort_code'] ) );
			$ibans           = wc_clean( wp_unslash( $_POST['bacs_iban'] ) );
			$bics            = wc_clean( wp_unslash( $_POST['bacs_bic'] ) );
			foreach ( $account_names as $i => $name ) {
				if ( ! isset( $account_names[ $i ] ) ) {
					continue;
				}
				$accounts[] = array(
					'account_name'   => $account_names[ $i ],
					'account_number' => $account_numbers[ $i ],
					'bank_name'      => $bank_names[ $i ],
					'sort_code'      => $sort_codes[ $i ],
					'iban'           => $ibans[ $i ],
					'bic'            => $bics[ $i ],
				);
			}
		}
		// phpcs:enable
		update_option( 'woocommerce_bacs_accounts', $accounts );
	}
	/**
	 * Output for the order received page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		if ( $this->instructions ) {
			echo wp_kses_post( wpautop( wptexturize( wp_kses_post( $this->instructions ) ) ) );
		}
		$this->bank_details( $order_id );
	}
	/**
	 * Add content to the WC emails.
	 *
	 * @param WC_Order $order Order object.
	 * @param bool     $sent_to_admin Sent to admin.
	 * @param bool     $plain_text Email format: plain text or HTML.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( ! $sent_to_admin && 'vabacs' === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
			if ( $this->instructions ) {
				echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
			}
			$this->bank_details( $order->get_id() );
		}
	}
	/**
	 * Get bank details and place into a list format.
	 *
	 * @param int $order_id Order ID.
	 */
	private function bank_details( $order_id = '' ) {
		if ( empty( $this->account_details ) ) {
			return;
		}
		// Get order and store in $order.
		$order = wc_get_order( $order_id );
		// get the user ID of user who placed this order
		$user_id = $order->get_user_id();
		// Since we have only one of these payment gateways per intranet sites
		// the user meta names are same for the different sites
		// they need to be populated appropriately in the doConnector.php based on target site
		// get user meta bank details for associated cashfree VA
		$va_account_name = get_user_meta( $user_id, 'beneficiary_name', true );
		$va_account_number = get_user_meta( $user_id, 'account_number', true );
		$va_ifsc_code = get_user_meta( $user_id, 'va_ifsc_code', true );

		// Get the order country and country $locale.
		$country = $order->get_billing_country();
		$locale  = $this->get_country_locale();
		// Get sortcode label in the $locale array and use appropriate one.
		$sortcode = isset( $locale[ $country ]['sortcode']['label'] ) ? $locale[ $country ]['sortcode']['label'] : __( 'Sort code', 'woocommerce' );
		$bacs_accounts = apply_filters( 'woocommerce_bacs_accounts', $this->account_details );
		if ( ! empty( $bacs_accounts ) ) {
			$account_html = '';
			$has_details  = false;
			foreach ( $bacs_accounts as $bacs_account ) {
				$bacs_account = (object) $bacs_account;
				if ( $bacs_account->account_name ) {
					$account_html .= '<h3 class="wc-bacs-bank-details-account-name">' . wp_kses_post( wp_unslash( $va_account_name ) ) . '</h3>' . PHP_EOL;
				}
				$account_html .= '<ul class="wc-bacs-bank-details order_details bacs_details">' . PHP_EOL;
				// BACS account fields shown on the thanks page and in emails.
				$account_fields = apply_filters(
					'woocommerce_bacs_account_fields',
					array(
						'bank_name'      => array(
							'label' => __( 'Bank', 'woocommerce' ),
							'value' => $bacs_account->bank_name,
						),
						'account_number' => array(
							'label' => __( 'Account number', 'woocommerce' ),
							'value' => $va_account_number,
						),
						'sort_code'      => array(
							'label' => $sortcode,
							'value' => $va_ifsc_code,
						),
						'iban'           => array(
							'label' => __( 'IBAN', 'woocommerce' ),
							'value' => $bacs_account->iban,
						),
						'bic'            => array(
							'label' => __( 'BIC', 'woocommerce' ),
							'value' => $bacs_account->bic,
						),
					),
					$order_id
				);
				foreach ( $account_fields as $field_key => $field ) {
					if ( ! empty( $field['value'] ) ) {
						$account_html .= '<li class="' . esc_attr( $field_key ) . '">' . wp_kses_post( $field['label'] ) . ': <strong>' . wp_kses_post( wptexturize( $field['value'] ) ) . '</strong></li>' . PHP_EOL;
						$has_details   = true;
					}
				}
				$account_html .= '</ul>';
			}
			if ( $has_details ) {
				echo '<section class="woocommerce-bacs-bank-details"><h2 class="wc-bacs-bank-details-heading">' . esc_html__( 'Our bank details', 'woocommerce' ) . '</h2>' . wp_kses_post( PHP_EOL . $account_html ) . '</section>';
			}
		}
	}
	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order->get_total() > 0 ) {
			// Mark as on-hold (we're awaiting the payment).
			$order->update_status( apply_filters( 'woocommerce_vabacs_process_payment_order_status', 'on-hold', $order ), __( 'Awaiting BACS payment', 'woocommerce' ) );
		} else {
			$order->payment_complete();
		}
		// Remove cart.
		WC()->cart->empty_cart();
		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
	/**
	 * Get country locale if localized.
	 *
	 * @return array
	 */
	public function get_country_locale() {
		if ( empty( $this->locale ) ) {
			// Locale information to be used - only those that are not 'Sort Code'.
			$this->locale = apply_filters(
				'woocommerce_get_bacs_locale',
				array(
					'AU' => array(
						'sortcode' => array(
							'label' => __( 'BSB', 'woocommerce' ),
						),
					),
					'CA' => array(
						'sortcode' => array(
							'label' => __( 'Bank transit number', 'woocommerce' ),
						),
					),
					'IN' => array(
						'sortcode' => array(
							'label' => __( 'IFSC', 'woocommerce' ),
						),
					),
					'IT' => array(
						'sortcode' => array(
							'label' => __( 'Branch sort', 'woocommerce' ),
						),
					),
					'NZ' => array(
						'sortcode' => array(
							'label' => __( 'Bank code', 'woocommerce' ),
						),
					),
					'SE' => array(
						'sortcode' => array(
							'label' => __( 'Bank code', 'woocommerce' ),
						),
					),
					'US' => array(
						'sortcode' => array(
							'label' => __( 'Routing number', 'woocommerce' ),
						),
					),
					'ZA' => array(
						'sortcode' => array(
							'label' => __( 'Branch code', 'woocommerce' ),
						),
					),
				)
			);
		}
		return $this->locale;
	}
	}
	add_filter( 'woocommerce_payment_gateways', 'add_vabacs' );
		function add_vabacs( $methods ) {
		    $methods[] = 'WC_Gateway_VABACS';
		    return $methods;
		}
}

// -------------------------payment gateway ends here--------------------------------------------



// hook for adding custom columns on woocommerce orders page
add_filter( 'manage_edit-shop_order_columns', 'orders_add_mycolumns' );
// hook for updating my new column valus based on passed order details
add_action( 'manage_shop_order_posts_custom_column', 'set_orders_newcolumn_values', 2 );
// hook for callback function to be done after order's status is changed to completed
add_action( 'woocommerce_order_status_completed', 'moodle_on_order_status_completed', 10, 1 );

/** moodle_on_order_status_completed()
*   is the callback function for the add_action woocommerce_order_status_completed action hook above
*   It calls the Moodle REST API using the php driver included at very top
*   It gets the associated moodle user details and updates the user payments meta with order data
*   It gets the user details from Moodle using $order
*   Any existing order details are in the 'payments' field and extracted as a json string and decoded
*   The new order data is inserted into the array. All of this is encoded back into JSON
*   The JSON encoded data is written back to SRiToni by using core-users-update API
*   Based on payments array conditions are checked for valid 'fees paid' status.
*   Based on the conditions the user field 'fees paid' is updated to yes or no
* No API calls are made to any payment gateway, relies only on order data and meta data
*/
function moodle_on_order_status_completed( $order_id )
{
	global $blog_id, $moodle_token, $moodle_url;

	$debug						= true;  // controls debug messages to error log

    // get all details from order and nothing but order using just order_id
	$order 						= wc_get_order( $order_id );
	$user_id 					= $order->get_user_id();  // this is WP user id
	$user						= $order->get_user();     // get wpuser information associated with order
	$username					= $user->user_login;      // username in WP which is moodle system userid
	$grade_or_class				= get_user_meta( $user_id, 'grade_or_class', true );
	$moodle_user_id				= strval($username);      // in case a strict string is expected by Moodle API
	//
	$order_amount 				= $order->get_total();
	$order_transaction_id 		= $order->get_transaction_id();
	$order_completed_date 		= $order->get_date_completed();
	$order_completed_timestamp 	= $order_completed_date->getTimestamp();
	$va_id_hset 				= get_user_meta( $user_id, 'va_id_hset', true );
	$items						= $order->get_items();

	// get prodct name associated with this order
	// per our restrictions there should be only 1 bundled item per order
	// however, since we only want the bundled order name, we break after 1st item in loop below
	foreach ($items as $item_key => $item )
	{
		$item_name    = $item->get_name();	// this is the name of the bundled product
		break;
	}
	// get sub-site name which will serve as payee name
	$order_payee				=	get_blog_details( $blog_id )->blogname;
	// prepare the data we want to write to Moodle user field called payments, of type text area
    // all data is from order
	$data = array(
					"order_id"					=>	$order_id,
					"order_amount"				=>	$order_amount,
					"order_transaction_id"		=>	$order_transaction_id,
					"order_product_name"		=>	$item_name,
					"order_completed_timestamp"	=>	$order_completed_timestamp,
					"order_payee"				=>  $order_payee, // this will be hset-payments or hsea-llp-payments
					"order_grade"				=>  $grade_or_class, // this is the grade that the user is/was when payment was made
				 );
	// prepare the Moodle Rest API object
	$MoodleRest = new MoodleRest();
	$MoodleRest->setServerAddress($moodle_url);
	$MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
	$MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.
	$MoodleRest->setDebug();
	// get moodle user details associated with this completed order from SriToni
	$parameters = array("criteria" => array(array("key" => "id", "value" => $moodle_user_id)));
	// get moodle user satisfying above criteria
	$moodle_users 		= $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);

	if ( !( $moodle_users["users"][0] ) )
	{
		// failed to communicate effectively to moodle server so exit
		error_log(print_r("couldn't communicate to moodle server regarding order: " . $order_id ,true));
		return;
	}

	$moodle_user		= $moodle_users["users"][0]; // see object returned in documentation

	$custom_fields 		= $moodle_user["customfields"];  // get custom fields associative array

	$existing 			= null ; //initialize to null

	// search for index key of our field having shorname as payments
	foreach ($custom_fields as $key => $field )
	{
		// $field is an array for an individual user profile field with 4 elements in this array
		if ( $field["shortname"] == "payments" )
		{
			if ($debug)
			{
					error_log("existing raw value in profile field payments");
					error_log(print_r($field["value"] ,true));

			}

			if ($field["value"]) 		// if value exists assume it is a json string and decode it
			{
				// strip off html and other tags that got added on by Moodle
				$string_without_tags = strip_tags(html_entity_decode($field["value"]));

				$existing	= json_decode($string_without_tags, true); // decode into an array

			}
			$field_payments_key	= $key;  // this is the key for the payments field

		}
		if ( $field["shortname"] == "fees" )
		{
			if ($field["value"]) 		// This is the present value of this field
			{
				// strip off html and other tahs that got added on somehow
				$fees_paid = strtolower(strip_tags(html_entity_decode($field["value"])));

			}
			$field_fees_key	= $key;  // this is the key for the payments field
		}

		if ( $field["shortname"] == "studentcat" )
		{
			if ($field["value"]) 		// for example: {general, installment, etc}
			{
				// strip off html and other tahs that got added on somehow
				$studentcat			= strtolower(strip_tags(html_entity_decode($field["value"])));

			}
			$field_studentcat_key		= $key;  // this is the key for studentcat field
		}
	}

	if ($debug)
	{
					error_log("existing payment information in profile field payments");
					error_log(print_r($existing ,true));

					error_log("status of user profile field fees");
					error_log(print_r($fees_paid ,true));
	}
	// if $existing already has elements in it then add this payment at index 0
	// if not $existing is empty so add this payment explicitly at index 0
	if($existing)
	{
		array_unshift($existing, $data);
	}
	else
	{
		$existing[0] = $data;
	}

	$existing_json 		= json_encode($existing);

	// create the users array in format needed for Moodle RSET API
	$users = array("users" => array(array(	"id" 			=> $moodle_user_id,
											"customfields" 	=> array(array(	"type"	=>	"payments",
																			"value"	=>	$existing_json,
																		  )
																    )
										 )
								   )
				  );
	// now to update the user's profiel field payments with latest completed payment
	$ret = $MoodleRest->request('core_user_update_users', $users, MoodleRest::METHOD_POST);

    // put function here to check and update profile_field fees paid based on payments array

	return;
}


/** add_VA_payments_submenu()
*   is the callback function for the add_action admin_menu hook above
*   adds a sub-menu item in the woocommerce main menu called VA-payments with slug woo-VA-payments
*   the callback function when this sub-menu is clicked on is VA_payments_callback and is defined elsewhere
*/
function add_VA_payments_submenu()
{

    /*
	add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable $function = '' )
	*					parent slug		newsubmenupage	submenu title  	capability			new submenu slug		callback for display page
	*/
	add_submenu_page( 'woocommerce',	'VA-payments',	'VA-payments',	'manage_options',	'woo-VA-payments',		'VA_payments_callback' );

	/*
	* add another submenu page for reconciling orders and payments on demand from admin menu
	*/
	add_submenu_page( 'woocommerce',	'reconcile',	'reconcile',	'manage_options',	'reconcile-payments',	'reconcile_payments_callback' );

	return;
}

/** VA_payments_callback()
*   is the callback function for the sub-menu VA-payments
*   This function decides what to do when the sub-menu is clicked
*   Prescribed way to get to this page is by clicking on an account in the orders page for any order
*   3 parameters are passed: va_id, display_name, user_id. We use these to display status of
*   all payments made into this VA and any reconciled orders for them
*/
function VA_payments_callback()
{
	$timezone = new DateTimeZone('Asia/Kolkata');
	// values passed in from orders page VA_ID link, see around line 1049
	$va_id				= $_GET["va_id"];
	$user_display_name	= $_GET["display_name"];
	$user_id			= $_GET["user_id"];			// this is passed in value of wordpress userid
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
			$payment_amount		= $payment->amount;	        // in ruppees

            $payment_date       = $payment->paymentTime;    // example 2007-06-28 15:29:26

			$payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date);
			//$payment_datetime->setTimezone($timezone);

			$args = array(
					'status' 			=> array(
													'completed',
											    ),
					'limit'				=>	1,
					'payment_method' 	=> 'vabacs',
					'customer_id'		=> $user_id,
					'meta-key'			=> "va_payment_id",
					'meta_value'		=> $payment_id,
				 );
			// get all orderes in process or completed with search parameters as shown above
			$orders 						= wc_get_orders( $args );
			$order 							= $orders[0] ?? null;
			if ($order)
			{	// order is reconciled with this payment get order details for table display
				$order_id					= $order->get_id();
				$order_amount 				= $order->get_total();
				//$order_transaction_id 		= $order->get_transaction_id();
				$order_datetime				= new DateTime( '@' . $order->get_date_created()->getTimestamp());
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




}

/**
*	This implements the callback for the sub-menu page reconcile.
*   When this manu page is accessed from the admin menu under Woocommerce, it tries to reconcile all open orders against payments made
*   Normally the reconciliation should be done as soon as a payment is made by a webhook issued by the payment gateway.
*	Should the webhook reconciliation fail for whatever reason, an on-demand reconciliation can be forced by accessing this page.
*/
function reconcile_payments_callback()
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

}

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
function reconcile1_ma($order, $payment, $timezone)
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

/** function orders_add_mycolumns($columns)
*   @param columns
*   This function is called by add_filter( 'manage_edit-shop_order_columns', 'orders_add_mycolumns' )
*   adds new columns after order_status column called Student
*   adds 2 new columns after order_total called VApymnt and VAid
*/
function orders_add_mycolumns($columns)
{
	$new_columns = array();

    foreach ( $columns as $column_name => $column_info )
		{

			$new_columns[ $column_name ] = $column_info;

			if ( 'order_status' === $column_name )
				{
				$new_columns['Student'] = "Student";
				}

			if ( 'order_total' === $column_name )
				{
				$new_columns['VApymnt'] = "VApymnt";
				$new_columns['VAid'] = "VAid";
				}
		}

    return $new_columns;
}

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
function set_orders_newcolumn_values($colname)
{
	global $post;
	// Proceed only for new columns added by us
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
	$timezone				= new DateTimeZone("Asia/Kolkata");
	$cashfree_api 			= new CfAutoCollect; // new cashfree Autocollect API object
	// get the reconcile or not flag from settings. If true then we try to reconcile whatever was missed by webhook
	$reconcile				= get_option( 'sritoni_settings' )["reconcile"] ?? 0;
	// get order details up ahead of treating all the cases below
	$order_status			= $order->get_status();
	$payment_method			= $order->get_payment_method();
	$va_id 					= get_post_meta($order->id, 'va_id', true) ?? ""; 	// this is the VA _ID contained in order meta
	$user_id 				= $order->get_user_id();
	$order_user 			= get_user_by('id', $user_id);
	$user_display_name 		= $order_user->display_name;

	$reconcilable = false;	// preset flag to indicate that order is not reconcilable

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
			$payment_date		= $payment_datetime->format('Y-m-d H:i:s');

		break;     // out of switch structure

		// Reconcile on-hold orders only if reconcile flag in settings is set, otherwise miss
		case ( ( 'on-hold' == $order_status ) && ( $reconcile == 1 ) ):
			// So first we get a list of last 3 payments made to the VAID contained in this HOLD order
			$payments	= $cashfree_api->getPaymentsForVirtualAccount($va_id,3);
            // what happens if there are no payents made and this is null?
            if (empty($payments))
            {
                $payment_amount     = "n/a";
                $payment_datetime   = "n/a";
                break;  // break out of switch structure and go to print
            }
			// Loop through the paymenst to check which one is already reconciled and which one is not
			foreach ($payments as $key=> $payment)
				{

					$payment_id			= $payment->referenceId;
					$args 				= array(
												'status' 			=> array(
																				'processing',
																				'completed',
																			),
												'limit'				=> 1,			// at least one order exists for this payment?
												'payment_method' 	=> "vabacs",
												'customer_id'		=> $user_id,
												'meta-key'			=> "va_payment_id",
												'meta_value'		=> $payment_id,
												);
					// get all orderes in process or completed with search parameters as shown above
					$payment_already_reconciled 	= !empty( wc_get_orders( $args ) );

					if ( $payment_already_reconciled )
						{
							// this payment is already reconciled so loop over to next payment
							continue;	// continue the for each loop next iteration
						}
					// Now we have a payment that is unreconciled. See if it is a potential candidate for reconciliation
					if ( !reconcilable_ma($order, $payment, $timezone) )
						{
						// this payment is not reconcilable either due to mismatch in payment or dates or both
						continue;	// continue next iteration of loop payment
						}
					// we now have a reconcilable paymet against this order so we get out of the for loop
                    // we only reconcile the 1st reconcilable payment.
                    // if multiple payments are made only 1 payment will be reconciled
					$reconcilable = true;
					break;	// out of loop but still in Switch statement. $payment is the payment to be reconciled
				}	// end of for each loop

		case ( ($reconcilable == true) && ($reconcile == 1) ) :
            // we cannot get here without going through case statement immediately above
			// we will reconcle since all flags are go
			reconcile_ma($order, $payment, $reconcile, $reconcilable, $timezone);
            $payment_date       = $payment->paymentTime;    // example 2007-06-28 15:29:26
			$payment_datetime	=  DateTime::createFromFormat('Y-m-d H:i:s', $payment_date);
			// $payment_datetime->setTimezone($timezone);
			$payment_amount 		= $payment->amount;    // already in rupees
	}		// end of SWITCH structure

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
function reconcilable_ma($order, $payment, $timezone)
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

}

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
function reconcile_ma($order, $payment, $reconcile, $reconcilable, $timezone)
{
	if 	(	($reconcile == 0) ||
			($reconcilable == false)	)
		{
			return false;	// just a safety check
		}
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
function installment_pre_get_posts_query( $q )
{
	// Get the current user
    $current_user 	= wp_get_current_user();
	$user_id 		= $current_user->ID;
	$studentcat 	= get_user_meta( $user_id, 'sritoni_student_category', true );
	$grade_or_class	= get_user_meta( $user_id, 'grade_or_class', true );
    $arrears_amount	= get_user_meta( $user_id, 'arrears_amount', true );
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
				   'terms' => array( $grade_or_class, "common" ), 	//
				   'operator' => 'IN'										//
					 ),									// OR
				array(
				   'taxonomy' => 'product_cat',
				   'field' => 'slug',
				   'terms' => array( $grade_or_class, "common" ),
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
				   'terms' => array( $grade_or_class, $arrears, "common" ),
				   'operator' => 'IN'
					 ),												// AND
				array(
				   'taxonomy' => 'product_cat',
				   'field' => 'slug',
				   'terms' => array( $grade_or_class, $arrears, "common" ), 	// OR of terms
				   'operator' => 'IN'
					 )
							);
		$q->set( 'tax_query', $tax_query );
	}


}
add_action( 'woocommerce_product_query', 'installment_pre_get_posts_query' );

/*
function max_grouped_price( $price_this_get_price_suffix, $instance, $child_prices ) {

    return wc_price(array_sum($child_prices));
}

add_filter( 'woocommerce_grouped_price_html', 'max_grouped_price', 10, 3 );
*/

// This adds an action just before saving any order at checkout to update the order meta for va_id
add_action('woocommerce_checkout_create_order', 'ma_update_order_meta_atcheckout', 20, 2);

/**
* @param order is the passed in order object under consideration at checkout
* @param data is an aray that contains the order meta keys and values when passed in
* We update the order's va_id meta at checkout.
*/
function ma_update_order_meta_atcheckout( $order, $data )
{
	// get user associated with this order
	$payment_method 	= $order->get_payment_method(); 			// Get the payment method ID
	// if not vabacs then return, do nothing
	if ( $payment_method != 'vabacs' )
	{
		return;
	}
	// get the user ID from order
	$user_id   			    = $order->get_user_id(); 					// Get the costumer ID
	// get the user meta for VA
	$va_id 				    = get_user_meta( $user_id, 'va_id', true );	// get the needed user meta value
    $sritoni_institution    = get_user_meta( $user_id, 'sritoni_institution', true ) ?? 'not set';

	$order->update_meta_data('va_id', $va_id);
    $order->update_meta_data('sritoni_institution', $sritoni_institution);

	return;
}

// add filter to change the price of product in shop and product pages
add_filter( 'woocommerce_product_get_price', 'spz_change_price', 10, 2 );

/**
*  This function changes the price displayed in shop and product pages as follows:
*  It gets the price according grade of logged in user
*  Price changes applied only to products in product category:grade-dependent-price
*/
function spz_change_price($price, $product)
{
    global $fees_csv;

    // check for grade dependent price category, return if not
    if ( !has_term( 'grade-dependent-price', 'product_cat', $product->get_id() ) )
    {
        return $price;
    }

    // this product belongs to category grade-dependent-price
    // lets get the price for this user
    // Get the current user
    $current_user 	= wp_get_current_user();
	$user_id 		= $current_user->ID;

	$studentcat 	          = get_user_meta( $user_id, 'sritoni_student_category', true );
	$grade_or_class	          = get_user_meta( $user_id, 'grade_or_class', true );
    $grade_for_current_fees   = get_user_meta( $user_id, 'grade_for_current_fees', true );
    $current_fees             = get_user_meta( $user_id, 'current_fees', true );
    $arrears_amount           = get_user_meta( $user_id, 'arrears_amount', true );
    // set price to full price based on grade of student using lookup table
    // $full_price_fee = $fees_csv[0][$grade_or_class] ?? 0;
    if (!has_term( 'arrears', 'product_cat', $product->get_id() ))
    {
        // check if user studentcat is installment2 or installment3
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

}

/**
* https://sritoni.org/hset-payments/wp-admin/admin-post.php?action=cf_wc_webhook
* add_action('admin_post_nopriv_cf_wc_webhook', 'cf_webhook_init', 10);
* This is set to a priority of 10
*/
function cf_webhook_init()
{
    $cfWebhook = new CF_webhook();

    $cfWebhook->process();
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
function csv_to_associative_array($file, $delimiter = ',', $enclosure = '"')
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
