<?php
/**
*Plugin Name: SriToni Cashfree Autocollect
*Plugin URI:
*Description: SriToni e-commerce plugin using Cashfree API, focussed on Virtual Accounts
*Version: 2019103100
*Author: Madhu Avasarala
*Author URI: http://sritoni.org
*Text Domain: sritoni_cashfree_autocollect
*Domain Path:
*/
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once(__DIR__."/MoodleRest.php");   				// Moodle REST API driver for PHP
require_once(__DIR__."/sritoni_cashfree_settings.php"); // file containing class for settings submenu and page
// this is tile containing the class definition for virtual accounts e-commerce
require_once(__DIR__."/sritoni_va_ec.php");

require_once(__DIR__."/MoodleRest.php");
require_once(__DIR__."/cfAutoCollect.inc.php");         // contains cashfree api class
require_once(__DIR__."/webhook/cashfree-webhook.php");  // contains webhook class


if ( is_admin() )
{ // add sub-menu for a new payments page. This function is a method belonging to the class sritoni_va_ec
  add_action('admin_menu', 'add_submenu_sritoni_tools');

  // add a new submenu for sritoni cashfree plugin settings in Woocommerce. This is to be done only once!!!!
  $sritoniCashfreeSettings = new sritoni_cashfree_settings();
}

// instantiate the class for sritoni virtual account e-commerce
$verbose = get_option("sritoni_settings")["is_sritonicashfree_debug"] ?? false;
$sritoni_va_ec       = new sritoni_va_ec($verbose);

// wait for all plugins to be loaded before initializing the new VABACS gateway
add_action('plugins_loaded', 'init_vabacs_gateway_class');

// hook action for post that has action set to cf_wc_webhook
// When that action is discovered the function cf_webhook_init is fired
// https://sritoni.org/hset-payments/wp-admin/admin-post.php?action=cf_wc_webhook
add_action('admin_post_nopriv_cf_wc_webhook', 'cf_webhook_init', 10);



function add_submenu_sritoni_tools()
{
	// add submenu page for testing various application API needed for SriToni operation
	add_submenu_page( 	'woocommerce',	                     // parent slug
						'SriToni Tools',                     // page title	
						'SriToni Tools',	                 // menu title
						'manage_options',	                 // capability
						'sritoni-tools',	                 // menu slug
						'sritoni_tools_render');             // callback
}

function sritoni_tools_render()
{
	// this is for rendering the API test onto the sritoni_tools page
	?>
		<h1> Click on button to test corresponding Server connection and API</h1>
		<form action="" method="post" id="form1">
			<input type="submit" name="button" 	value="test_moodle_connection"/>
			<input type="submit" name="button" 	value="test_cashfree_connection"/>
			<input type="submit" name="button" 	value="test_custom_code"/>
		</form>

		
	<?php

	$button = sanitize_text_field( $_POST['button'] );

	switch ($button) 
	{
		case 'test_moodle_connection':
			test_moodle_connection();
			break;

		case 'test_cashfree_connection':
			test_cashfree_connection();
			break;

		case 'test_custom_code':
			test_custom_code();
			break;	
		
		default:
			// do nothing
			break;
	}
}


function init_vabacs_gateway_class()
{
	// if current user's email does not have headstart.edu.in, set the user meta as an admission fee payer only
	$current_user = wp_get_current_user();

	// if logged in user's email does not have headstart AND user's login is not all numbers user is here for admissions payment
	if (stripos($current_user->data->user_email, 'headstart.edu.in') === false || !preg_match("/^\d+$/", $current_user->data->user_login))
	{
		// logged in user does not have a headstart email ID AND does not have a numeric login username
		// set the user meta as an admission fee payer only
		update_user_meta($current_user->ID, 'admission_fee_payer_only',	'Yes');

	}
	else
	{
		// regular Head Start Intranet user
		update_user_meta($current_user->ID, 'admission_fee_payer_only',	'No');
	}

	class WC_Gateway_VABACS extends WC_Payment_Gateway
  {  // MA
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
	public function get_country_locale()
  {
		if ( empty( $this->locale ) )
    {
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
  function add_vabacs( $methods )
  {
    $methods[] = 'WC_Gateway_VABACS';
    return $methods;
  }
}

// -------------------------payment gateway ends here--------------------------------------------



/**
* https://sritoni.org/hset-payments/wp-admin/admin-post.php?action=cf_wc_webhook
* add_action('admin_post_nopriv_cf_wc_webhook', 'cf_webhook_init', 10);
* This is set to a priority of 10
*/
function cf_webhook_init()
{
	$verbose = get_option( 'sritoni_settings')["is_cfwebhook_debug"] ?? true;

    $cfWebhook = new CF_webhook($verbose);

    $cfWebhook->process();
}

function test_moodle_connection()
{
	// read in the Moodle API config array
	$config			= include( __DIR__."/sritonicashfree_config.php");
	$moodle_url 	= $config["moodle_url"] . '/webservice/rest/server.php';
	$moodle_token	= $config["moodle_token"];

	// prepare the Moodle Rest API object
	$MoodleRest = new MoodleRest();
	$MoodleRest->setServerAddress($moodle_url);
	$MoodleRest->setToken( $moodle_token ); // get token from ignore_key file
	$MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY); // Array is default. You can use RETURN_JSON or RETURN_XML too.
	// $MoodleRest->setDebug();
	// get moodle user details associated with this completed order from SriToni
	$parameters   = array("criteria" => array(array("key" => "id", "value" => 73)));

	// get moodle user satisfying above criteria
	$moodle_users = $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);
	if ( !( $moodle_users["users"][0] ) )
  	{
  		// failed to communicate effectively to moodle server so exit
  		echo nl2br("couldn't communicate to moodle server. \n");
  		return;
  	}
	echo "<h3>Connection to moodle server was successfull: Here are the details of Moodle user object for id:73</h3>";
  	$moodle_user   = $moodle_users["users"][0];
	echo "<pre>" . print_r($moodle_user, true) ."</pre>";
}

function test_cashfree_connection()
{
	// since wee need to interact with Cashfree , create a new API instamve.
	// this will also take care of getting  your API creedentials automatically.
	$cashfree_api    = new CfAutoCollect; // new cashfree Autocollect API object

	$va_id = "0073";	// VAID of sritoni1 moodle1 user

	// So first we get a list of last 3 payments made to the VAID contained in this HOLD order
	$payments        = $cashfree_api->getPaymentsForVirtualAccount($va_id, 1);
	echo "<h3> Payments made by userid 0073:</h3>";
	echo "<pre>" . print_r($payments, true) ."</pre>";

	echo "<h3> PaymentAccount details of userid 0073:</h3>";
	$vAccount = $cashfree_api->getvAccountGivenId($va_id);
	echo "<pre>" . print_r($vAccount, true) ."</pre>";

	$payment_id = "42726818";
	$payment = $cashfree_api->getPaymentById($payment_id);

	echo "<h3> Payment details of paymentID: 42726818</h3>";
	echo "<pre>" . print_r($payment, true) ."</pre>";
}

function test_LDAP_connection()
{
	$config = include("ldapwpsync_config.php");

	$ldapserver = $config['ldaps_server'];
    $ldapuser   = $config['ldap_admin'];
    $ldappass   = $config['ldap_password'];
    $ldaptree   = $config['ldap_tree'];
	$ldapfilter = $config['ldapfilter'];

	// echo "<pre>" . print_r($this->config, true) ."</pre>";

      // echo "<pre>" . print_r($this->config['wpusers_email_whitelist'], true) ."</pre>";

      // connect to LDAP server
      $ldapconn = ldap_connect($ldapserver) or die("Could not connect to LDAP server.");

      // if we are here then we did not die so we must have connected to LDAP server
      // but first set protocol version
    	ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);

    	// bind using admin account
      $ldapbind = ldap_bind($ldapconn, $ldapuser, $ldappass) or die ("Error trying to bind: " . ldap_error($ldapconn));

      // verify binding and if good search and download entries based on filter set below
      if ($ldapbind)
      {
        echo nl2br("LDAP Connection and Authenticated bind successful...\n");
        // $ldapsearch contains the search, $data contains all the entries
        //
        $result = ldap_search($ldapconn,$ldaptree, $ldapfilter) or die ("Error in search query: " . ldap_error($ldapconn));
        $data   = ldap_get_entries($ldapconn, $result);
        //
        // print number of entries found
    	$ldapcount = ldap_count_entries($ldapconn, $result);
        echo nl2br("Number of entries found in LDAP directory: " . $ldapcount . "\n");
      }
    	else
      {
        echo "LDAP bind failed...";
      }
}

function test_custom_code()
{
	// we get to tet whatever we want here. Typically display contents of varoables and objects for debugging
	$order_id = 590;

	$order = wc_get_order( $order_id );

	//print out the order details using Woo Commerce functions
	echo "<h3> Woocommerce Order details:</h3>";
	echo nl2br("Order Billing name is got using get_billing_first_name method: " . $order->get_billing_first_name() . "\n");
	echo nl2br("Order Billing name is got using get_billing_last_name method: " . $order->get_billing_last_name() . "\n");
	echo nl2br("Order Payment Title is got using get_payment_method_title method: " . $order->get_payment_method_title() . "\n");

	echo "<h4> Woocommerce Order Item details using a loop:</h4>";
	foreach ( $order->get_items() as $item_id => $item ) 
	{

		$product_name = $item->get_name();
		echo nl2br("Order item product name using item->get_name merhod: " . $product_name . "\n");
	 }

	echo nl2br("Order items number: " . count($order->get_items()) . "\n");
}
