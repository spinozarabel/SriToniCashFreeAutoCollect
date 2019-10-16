<?php
/**
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
		register_setting( 'sritoni_settings', 'sritoni_settings' );

		// add_settings_section( $id, $title, $callback, $page );
		add_settings_section( 'cashfree_api_section', 'cashfree API Settings', array( $this, 'print_section_info' ), 'sritoni_settings' );
		add_settings_section( 'moodle_api_section', 'Moodle API Settings', array( $this, 'print_section_info' ), 'sritoni_settings' );


		// add_settings_field( $id, $title, $callback, $page, $section, $args );
		add_settings_field( 'reconcile', 'Try Reconciling Payments?', array( $this, 'reconcile_callback' ), 'sritoni_settings', 'cashfree_api_section' );
		add_settings_field( 'cashfree_secret', 'cashfree API client Secret', array( $this, 'cashfree_secret_callback' ), 'sritoni_settings', 'cashfree_api_section' );
		add_settings_field( 'cashfree_key', 'cashfree API Client Key or ID', array( $this, 'cashfree_key_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'ip_whitelist', 'comma separated IPs          ', array( $this, 'ip_whitelist_callback' ), 'sritoni_settings', 'cashfree_api_section' );
        add_settings_field( 'domain_whitelist', 'domain to be whitelisted ', array( $this, 'domain_whitelist_callback' ), 'sritoni_settings', 'cashfree_api_section' );

		add_settings_field( 'moodle_token', 'Moodle API Token', array( $this, 'moodle_token_callback' ), 'sritoni_settings', 'moodle_api_section' );
	}

	/**
     * Print the Section text
     */
    public function print_section_info()
    {
        print 'Enter your settings below:';
    }

    /**
     * Get the settings option array and print comma separated ip_whitelsit string
    */
    public function domain_whitelist_callback()
    {

	$settings = (array) get_option( 'sritoni_settings' );
	$field = "domain_whitelist";
	$value = esc_attr( $settings[$field] );

	echo "<input type='text' name='sritoni_settings[$field]' value='$value' size='50' />";

    }

    /**
     * Get the settings option array and print comma separated ip_whitelsit string
    */
    public function ip_whitelist_callback()
    {

	$settings = (array) get_option( 'sritoni_settings' );
	$field = "ip_whitelist";
	$value = esc_attr( $settings[$field] );

	echo "<input type='text' name='sritoni_settings[$field]' value='$value' size='50' />";

    }

	/**
     * Get the settings option array and print cashfree_key value
     */
    public function cashfree_key_callback()
    {

	$settings = (array) get_option( 'sritoni_settings' );
	$field = "cashfree_key";
	$value = esc_attr( $settings[$field] );

	echo "<input type='text' name='sritoni_settings[$field]' value='$value'  size='50' />";

    }


	/**
     * Get the settings option array and print cashfree_secret value
     */
    public function cashfree_secret_callback()
    {
		$settings = (array) get_option( 'sritoni_settings' );
		$field = "cashfree_secret";
		$value = esc_attr( $settings[$field] );

		echo "<input type='text' name='sritoni_settings[$field]' value='$value'  size='50' />";
    }

	/**
     * Get the settings option array and print moodle_token value
     */
    public function moodle_token_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "moodle_token";
		$value = esc_attr( $settings[$field] );

		echo "<input type='text' name='sritoni_settings[$field]' value='$value'  size='50' />";
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
			<input name="sritoni_settings[reconcile]" type="checkbox" value="1"<?php checked( $checked, 1, true ); ?>/>
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

		if( !($input['reconcile']) )
            $new_input['reconcile'] = 0;

        return $new_input;

    }



}
