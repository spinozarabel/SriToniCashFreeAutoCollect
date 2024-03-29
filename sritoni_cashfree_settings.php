<?php
/**
 * removed cashfree api key/secret as well as sritoni url and token settings. These need to come in via config file
 * ver 7 added settings field for reading fees_csv file
 * ver 6 added setting for beneficiary name
 * ver 5 added setting for url hosting moodle
 * ver 4 added setting for verify_webhook_ip
 * ver 3 added sanitize
 * ver 2 added ip_whitelist option
 * Sub woocommerce menu class
 * Adds a submenu item and page for settings for Sritoni cashfree plugin
 * @author Madhu <madhu.avasarala@gmail.com>
 * @author Mostafa <mostafa.soufi@hotmail.com>
 * Updated for Cashfree on 20191001
 */
class sritoni_cashfree_settings {

    /**
     * Holds the values to be used in the fields callbacks
     */
    public $options;

	/**
     * Autoload method
     * @return void
     */
    public function __construct() {
        add_action( 'admin_menu', array($this, 'create_sritoni_cashfree_settings_page') );

		//call register settings function
	    add_action( 'admin_init', array($this, 'init_sritoni_cashfree_settings' ) );
    }

    /**
     * Register woocommerce submenu trigered by add_action 'admin_menu'
     * @return void
     */
    public function create_sritoni_cashfree_settings_page()
	{
        // add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable $function = '' )
		add_submenu_page(
            'sritoni-payments', 'SriToni Settings', 'SriToni Settings', 'manage_options', 'sritoni_settings', array($this, 'sritoni_cashfree_settings_page')
        );
    }



    /**
     * Renders the form for getting settings values for plugin
	 * The settings consist of: cashfree merchant ID, key, Moodle API key
     * @return void
     */
    public function sritoni_cashfree_settings_page()
	{

		?>
		<div class="wrap">
            <h1>SriToni cashfree Settings</h1>
            <form method="post" action="options.php">
            <?php
                // https://codex.wordpress.org/Settings_API
                // following is for hidden fields and security of form submission per api
                settings_fields( 'sritoni_settings' );
                // prints out the sections and fields per API
                do_settings_sections( 'sritoni_settings' ); // slug of page
                submit_button();    // wordpress submit button for form
            ?>
            </form>
        </div>
        <?php
    }


	/**
	*
	*/
	public function init_sritoni_cashfree_settings()
	{
		// register_setting( string $option_group, string $option_name, array $args = array() )
        $args = array(
                        'sanitize_callback' => array( $this, 'sanitize' ),  // function name for callback
            //          'default' => NULL,                  // default values when calling get_options
                     );
		register_setting( 'sritoni_settings', 'sritoni_settings' );

		// add_settings_section( $id, $title, $callback, $page );
		add_settings_section( 'cashfree_api_section', 'cashfree API Settings', array( $this, 'print_section_info' ), 'sritoni_settings' );
		add_settings_section( 'sritoni_api_section', 'Sritoni API Settings', array( $this, 'print_section_info' ), 'sritoni_settings' );
        add_settings_section( 'student_section', 'Student related Settings', array( $this, 'print_section_info' ), 'sritoni_settings' );


		// add_settings_field( $id, $title, $callback, $page, $section, $args );
        add_settings_field( 'production',               'Check box if Production and Not Test',     array( $this, 'production_callback' ),               'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'reconcile',                'Try Reconciling Payments?',                array( $this, 'reconcile_callback' ),                'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'is_sritonicashfree_debug', 'Check box to log to error.log',            array( $this, 'is_sritonicashfree_debug_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'is_cfwebhook_debug',       'Check box to log CF webhook to error.log', array( $this, 'is_cfwebhook_debug_callback' ),       'sritoni_settings', 'cashfree_api_section' );
        //add_settings_field( 'cashfree_secret', 'cashfree API client Secret', array( $this, 'cashfree_secret_callback' ), 'sritoni_settings', 'cashfree_api_section' );
		//add_settings_field( 'cashfree_key', 'cashfree API Client Key or ID', array( $this, 'cashfree_key_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        
        add_settings_field( 'beneficiary_name', 'Beneficiary Name of Cashfree Account', array( $this, 'cashfree_beneficiary_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'ifsc_code', 'IFSC Code',                                   array( $this, 'ifsc_code_callback' ),            'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'accounts_prefix', 'Prefix of Accounts',                    array( $this, 'accounts_prefix_callback' ),      'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'ip_whitelist', 'comma separated IPs to be whitelisted for webhook', array( $this, 'ip_whitelist_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'domain_whitelist', 'comma separated webhook domains to be whitelisted ', array( $this, 'domain_whitelist_callback' ), 'sritoni_settings', 'cashfree_api_section' );

        // added verify_webhook_ip setting in ver 1.3
		add_settings_field( 'verify_webhook_ip', 'Verify if Webhook IP is in whitelist?', array( $this, 'verify_webhook_ip_callback' ), 'sritoni_settings', 'cashfree_api_section' );

        //add_settings_field( 'sritoni_url', 'Sritoni host URL', array( $this, 'sritoni_url_callback' ), 'sritoni_settings', 'sritoni_api_section' );
        //add_settings_field( 'sritoni_token', 'Sritoni API Token', array( $this, 'sritoni_token_callback' ), 'sritoni_settings', 'sritoni_api_section' );

        add_settings_field( 'studentcat_possible', 'Comma separated list of permissible student categories', array( $this, 'studentcat_possible_callback' ), 'sritoni_settings', 'student_section' );
        add_settings_field( 'group_possible', 'Comma separated list of permissible student groups', array( $this, 'group_possible_callback' ), 'sritoni_settings', 'student_section' );
        add_settings_field( 'whitelist_idnumbers', 'Comma separated list of whitelisted user ID numbers', array( $this, 'whitelist_idnumbers_callback' ), 'sritoni_settings', 'student_section' );
        add_settings_field( 'courseid_groupingid', 'Comma separated pairs of course ID-grouping ID', array( $this, 'courseid_groupingid_callback' ), 'sritoni_settings', 'student_section' );

        add_settings_field( 'get_csv_fees_file', 'Check box to get CSV fees file and process', array( $this, 'get_csv_fees_file_callback' ), 'sritoni_settings', 'student_section' );
        add_settings_field( 'csv_fees_file_path', 'Full path of CSV fees file, can be published Google CSV file', array( $this, 'csv_fees_file_path_callback' ), 'sritoni_settings', 'student_section' );
    }

	/**
     * Print the Section text
     */
    public function print_section_info()
    {
        print 'Enter your settings below:';
    }


    /**
     * Get the settings option array and print get_csv_fees_file value
     */
    public function is_cfwebhook_debug_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
        $field = "is_cfwebhook_debug";
        $checked = $settings[$field] ?? 0;

        ?>
            <input name="sritoni_settings[is_cfwebhook_debug]" id="sritoni_settings[is_cfwebhook_debug]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
        <?php
    }


    /**
     * Get the settings option array and print get_csv_fees_file value
     */
    public function is_sritonicashfree_debug_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
        $field = "is_sritonicashfree_debug";
        $checked = $settings[$field] ?? 0;

        ?>
            <input name="sritoni_settings[is_sritonicashfree_debug]" id="sritoni_settings[is_sritonicashfree_debug]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
        <?php
    }

    /**
     * Get the settings option array and print the full path of theCSV fees file
     */
    public function csv_fees_file_path_callback()
    {

    $settings = (array) get_option( 'sritoni_settings' );
    $field = "csv_fees_file_path";
    $value = esc_attr( $settings[$field] );

    echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
            value='$value'  size='80' class='code' />";

    }

    /**
     * Get the settings option array and print get_csv_fees_file value
     */
    public function get_csv_fees_file_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
        $field = "get_csv_fees_file";
        $checked = $settings[$field] ?? 0;

        ?>
            <input name="sritoni_settings[get_csv_fees_file]" id="sritoni_settings[get_csv_fees_file]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
        <?php
    }

    /**
    *  Comma separated list of course ID - Grouping // ID
    * for example: 116-24,100-29
    * This specifies a grouping ID for a given course ID from the calling activity
    */
    public function courseid_groupingid_callback()
    {

    $settings = (array) get_option( 'sritoni_settings' );
    $field = "courseid_groupingid";
    $value = esc_attr( $settings[$field] );

    echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
            value='$value' size='80' class='code' />example:116-24,100-29";

    }

    /**
    *  Comma separated list of ID numbers of users who need to be whitelsited
    * for these users no checks are done regarding their group or student category
    */
    public function whitelist_idnumbers_callback()
    {

    $settings = (array) get_option( 'sritoni_settings' );
    $field = "whitelist_idnumbers";
    $value = esc_attr( $settings[$field] );

    echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
            value='$value' size='80' class='code' />example:HSEA001,WHS1234";

    }

    /**
    *  Comma separated list of permissible groups that should correspond to product categories
    * if student's group extracted from grouping is not in this user is rejected
    */
    public function group_possible_callback()
    {

    $settings = (array) get_option( 'sritoni_settings' );
    $field = "group_possible";
    $value = esc_attr( $settings[$field] );

    echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
            value='$value' size='80' class='code' />example:grade4,grade5,grade6,grade7";

    }

    public function studentcat_possible_callback()
    {

    $settings = (array) get_option( 'sritoni_settings' );
    $field = "studentcat_possible";
    $value = strtolower(esc_attr( $settings[$field] ));

    echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
            value='$value' size='80' class='code' />These should be exactly as defined in Moodle but in lower case";

    }

    /**
     * Get the settings option array and print comma separated ip_whitelsit string
    */
    public function domain_whitelist_callback()
    {

	$settings = (array) get_option( 'sritoni_settings' );
	$field = "domain_whitelist";
	$value = esc_attr( $settings[$field] );

    echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
            value='$value' size='80' class='code' />example:cashfree.com,madhu.ddns.net";

    }

    /**
     * Get the settings option array and print comma separated ip_whitelsit string
    */
    public function ip_whitelist_callback()
    {

	$settings = (array) get_option( 'sritoni_settings' );
	$field = "ip_whitelist";
	$value = esc_attr( $settings[$field] );

	echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
            value='$value' size='80' class='code' />example:24.12.10.1,30.18.27.1";

    }

	/**
     * Get the settings option array and print cashfree_key value
     */
    public function cashfree_key_callback()
    {

	$settings = (array) get_option( 'sritoni_settings' );
	$field = "cashfree_key";
	$value = esc_attr( $settings[$field] );

	echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
            value='$value'  size='50' class='code' />Cashfree Account API access Key";

    }


	/**
     * Get the settings option array and print cashfree_secret value
     */
    public function cashfree_secret_callback()
    {
		$settings = (array) get_option( 'sritoni_settings' );
		$field = "cashfree_secret";
		$value = esc_attr( $settings[$field] );

        echo "<input type='password' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
                value='$value'  size='50' class='code' />Cashfree Account API access Secret";
    }

    /**
     * Get the settings option array and print cashfree beneficiary name
     */
    public function cashfree_beneficiary_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "beneficiary_name";
		$value = esc_attr( $settings[$field] );

        echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
                value='$value'  size='50' class='code' />Cashfree Account Beneficiary Name, ex: Head Start Educational Trust";
    }

    /**
     * Get the settings option array and print cashfree account IFSC code
     */
    public function ifsc_code_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "ifsc_code";
		$value = esc_attr( $settings[$field] );

        echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
                value='$value'  size='50' class='code' />Cashfree Account IFSC Code, ex: YESB0CMSNOC";
    }


    /**
     * Get the settings option array and print cashfree account prefix
     */
    public function accounts_prefix_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "accounts_prefix";
		$value = esc_attr( $settings[$field] );

        echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
                value='$value'  size='50' class='code' />Cashfree Accounts prefix, ex: 808081HS";
    }

	/**
     * Get the settings option array and print moodle_token value
     */
    public function sritoni_token_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "sritoni_token";
		$value = esc_attr( $settings[$field] );

        echo "<input type='password' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
                value='$value'  size='50' class='code' />Token is an alphanumeric string, and not displayed due to security";
    }

    /**
     * Get the settings option array and print moodle_token value
     */
    public function sritoni_url_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "sritoni_url";
		$value = esc_attr( $settings[$field] );

        echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
                value='$value'  size='50' class='code' />example:https://sritonilearningservices.com/sritoni no slash at end";
    }

	/**
     * Get the settings option array and print reconcile value
     */
    public function reconcile_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "reconcile";
		$checked = $settings[$field] ?? 0;

		?>
			<input name="sritoni_settings[reconcile]" id="sritoni_settings[reconcile]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
		<?php
    }

    /**
     *
     */
    public function production_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "production";
		$checked = $settings[$field] ?? 0;

		?>
			<input name="sritoni_settings[production]" id="sritoni_settings[production]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
		<?php
    }

    /**
     *  added in ver 1.3
     */
    public function verify_webhook_ip_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
        $field = "verify_webhook_ip";
        $checked = $settings[$field] ?? 0;

        ?>
            <input name="sritoni_settings[verify_webhook_ip]" id="sritoni_settings[verify_webhook_ip]" type="checkbox"
                value="1" class="code"<?php checked( $checked, 1, true ); ?>/>
        <?php
    }

	/**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {

		$new_input = array();
        if( isset( $input['cashfree_key'] ) )
            $new_input['cashfree_key'] = sanitize_text_field( $input['cashfree_key'] );

        if( isset( $input['cashfree_secret'] ) )
            $new_input['cashfree_secret'] = sanitize_text_field( $input['cashfree_secret'] );

		if( isset( $input['sritoni_token'] ) )
            $new_input['sritoni_token'] = sanitize_text_field( $input['sritoni_token'] );

        if( isset( $input['sritoni_url'] ) )
            $new_input['sritoni_url'] = sanitize_text_field( $input['sritoni_url'] );

        if( isset( $input['ip_whitelist'] ) )
            $new_input['ip_whitelist'] = sanitize_text_field( $input['ip_whitelist'] );

        if( isset( $input['domain_whitelist'] ) )
            $new_input['domain_whitelist'] = sanitize_text_field( $input['domain_whitelist'] );

		if( !empty($input['reconcile']) )
            $new_input['reconcile'] = 0;

        if( !empty($input['production']) )
            $new_input['production'] = 0;
		// added in ver 1.3
        if( !empty($input['verify_webhook_ip']) )
            $new_input['verify_webhook_ip'] = 0;

        if( !empty($input['is_sritonicashfree_debug']) )
        $new_input['is_sritonicashfree_debug'] = 0;

        if( !empty($input['is_cfwebhook_debug']) )
        $new_input['is_cfwebhook_debug'] = 0;

        // added in ver 6
        if( isset( $input['beneficiary_name'] ) )
            $new_input['beneficiary_name'] = sanitize_text_field( $input['beneficiary_name'] );

        if( isset( $input['ifsc_code'] ) )
        $new_input['ifsc_code'] = sanitize_text_field( $input['ifsc_code'] );

        if( isset( $input['accounts_prefix'] ) )
        $new_input['accounts_prefix'] = sanitize_text_field( $input['accounts_prefix'] );

        if( !empty($input['get_csv_fees_file']) )
            $new_input['get_csv_fees_file'] = 0;

        if( isset( $input['csv_fees_file_path'] ) )
            $new_input['csv_fees_file_path'] = sanitize_text_field( $input['csv_fees_file_path'] );

        return $new_input;

    }



}
