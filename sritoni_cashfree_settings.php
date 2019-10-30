<?php
/**
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
            'woocommerce', 'SriToni Settings', 'SriToni Settings', 'manage_options', 'sritoni_settings', array($this, 'sritoni_cashfree_settings_page')
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
		add_settings_section( 'moodle_api_section', 'Moodle API Settings', array( $this, 'print_section_info' ), 'sritoni_settings' );
        add_settings_section( 'student_section', 'Student related Settings', array( $this, 'print_section_info' ), 'sritoni_settings' );


		// add_settings_field( $id, $title, $callback, $page, $section, $args );
        add_settings_field( 'production', 'Check box if Production and Not Test', array( $this, 'production_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'reconcile', 'Try Reconciling Payments?', array( $this, 'reconcile_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'cashfree_secret', 'cashfree API client Secret', array( $this, 'cashfree_secret_callback' ), 'sritoni_settings', 'cashfree_api_section' );
		add_settings_field( 'cashfree_key', 'cashfree API Client Key or ID', array( $this, 'cashfree_key_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'ip_whitelist', 'comma separated IPs to be whitelisted for webhook', array( $this, 'ip_whitelist_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'domain_whitelist', 'comma separated webhook domains to be whitelisted ', array( $this, 'domain_whitelist_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        // added verify_webhook_ip setting in ver 1.3
		add_settings_field( 'verify_webhook_ip', 'Verify if Webhook IP is in whitelist?', array( $this, 'verify_webhook_ip_callback' ), 'sritoni_settings', 'cashfree_api_section' );

        add_settings_field( 'moodle_token', 'Moodle API Token', array( $this, 'moodle_token_callback' ), 'sritoni_settings', 'moodle_api_section' );

        add_settings_field( 'studentcat_possible', 'Comma separated list of permissible student categories', array( $this, 'studentcat_possible_callback' ), 'sritoni_settings', 'student_section' );
        add_settings_field( 'group_possible', 'Comma separated list of permissible student groups', array( $this, 'group_possible_callback' ), 'sritoni_settings', 'student_section' );
        add_settings_field( 'whitelist_idnumbers', 'Comma separated list of whitelisted user ID numbers', array( $this, 'whitelist_idnumbers_callback' ), 'sritoni_settings', 'student_section' );
        add_settings_field( 'courseid_groupingid', 'Comma separated pairs of course ID-grouping ID', array( $this, 'courseid_groupingid_callback' ), 'sritoni_settings', 'student_section' );

	}

	/**
     * Print the Section text
     */
    public function print_section_info()
    {
        print 'Enter your settings below:';
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
            value='$value'  size='50' class='code' />";

    }


	/**
     * Get the settings option array and print cashfree_secret value
     */
    public function cashfree_secret_callback()
    {
		$settings = (array) get_option( 'sritoni_settings' );
		$field = "cashfree_secret";
		$value = esc_attr( $settings[$field] );

        echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
                value='$value'  size='50' class='code' />";
    }

	/**
     * Get the settings option array and print moodle_token value
     */
    public function moodle_token_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "moodle_token";
		$value = esc_attr( $settings[$field] );

        echo "<input type='text' name='sritoni_settings[$field]' id='sritoni_settings[$field]'
                value='$value'  size='50' class='code' />";
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

		if( isset( $input['moodle_token'] ) )
            $new_input['moodle_token'] = sanitize_text_field( $input['moodle_token'] );

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

        return $new_input;

    }



}
