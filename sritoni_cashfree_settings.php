<?php
/**
 * Sub woocommerce menu class
 * Adds a submenu item and page for settings for Sritoni Razorpay plugin
 * @author Madhu <madhu.avasarala@gmail.com>
 * @author Mostafa <mostafa.soufi@hotmail.com>
 */
class sritoni_razorpay_settings {
 
    /**
     * Holds the values to be used in the fields callbacks
     */
    public $options;
	
	/**
     * Autoload method
     * @return void
     */
    public function __construct() {
        add_action( 'admin_menu', array($this, 'create_sritoni_razorpay_settings_page') );
		
		//call register settings function
	    add_action( 'admin_init', array($this, 'init_sritoni_razorpay_settings' ) );
    }
 
    /**
     * Register woocommerce submenu trigered by add_action 'admin_menu'
     * @return void
     */
    public function create_sritoni_razorpay_settings_page() 
	{
        // add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable $function = '' )
		add_submenu_page( 
            'woocommerce', 'SriToni Settings', 'SriToni Settings', 'manage_options', 'sritoni_settings', array($this, 'sritoni_razorpay_settings_page')
        );
    }
	
	
 
    /**
     * Renders the form for getting settings values for plugin
	 * The settings consist of: Razorpay merchant ID, key, Moodle API key
     * @return void
     */
    public function sritoni_razorpay_settings_page() 
	{
		
		?>
		<div class="wrap">
            <h1>SriToni Razorpay Settings</h1>
            <form method="post" action="options.php">
            <?php
                // This prints out all hidden setting fields
                settings_fields( 'sritoni_settings' );
                do_settings_sections( 'sritoni_settings' ); // slug of page
                submit_button();
            ?>
            </form>
        </div>
        <?php
    }
	
	
	/**
	*
	*/
	public function init_sritoni_razorpay_settings() 
	{ 
		// register_setting( string $option_group, string $option_name, array $args = array() )
		register_setting( 'sritoni_settings', 'sritoni_settings' );
		
		// add_settings_section( $id, $title, $callback, $page );
		add_settings_section( 'razorpay_api_section', 'Razorpay API Settings', array( $this, 'print_section_info' ), 'sritoni_settings' );
		add_settings_section( 'moodle_api_section', 'Moodle API Settings', array( $this, 'print_section_info' ), 'sritoni_settings' );
		
		
		// add_settings_field( $id, $title, $callback, $page, $section, $args );
		add_settings_field( 'reconcile', 'Try Reconciling Payments', array( $this, 'reconcile_callback' ), 'sritoni_settings', 'razorpay_api_section' );
		add_settings_field( 'razorpay_secret', 'Razorpay API secret', array( $this, 'razorpay_secret_callback' ), 'sritoni_settings', 'razorpay_api_section' );
		add_settings_field( 'razorpay_key', 'Razorpay API Key', array( $this, 'razorpay_key_callback' ), 'sritoni_settings', 'razorpay_api_section' );
		
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
     * Get the settings option array and print razorpay_key value
     */
    public function razorpay_key_callback()
    {
        
	$settings = (array) get_option( 'sritoni_settings' );
	$field = "razorpay_key";
	$value = esc_attr( $settings[$field] );
	
	echo "<input type='text' name='sritoni_settings[$field]' value='$value' />";

    }
		
	
	/** 
     * Get the settings option array and print razorpay_secret value
     */
    public function razorpay_secret_callback()
    {
		$settings = (array) get_option( 'sritoni_settings' );
		$field = "razorpay_secret";
		$value = esc_attr( $settings[$field] );
	
		echo "<input type='text' name='sritoni_settings[$field]' value='$value' />";
    }
	
	/** 
     * Get the settings option array and print moodle_token value
     */
    public function moodle_token_callback()
    {
        $settings = (array) get_option( 'sritoni_settings' );
		$field = "moodle_token";
		$value = esc_attr( $settings[$field] );
	
		echo "<input type='text' name='sritoni_settings[$field]' value='$value' />";
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
        if( isset( $input['razorpay_key'] ) )
            $new_input['razorpay_key'] = sanitize_text_field( $input['razorpay_key'] );

        if( isset( $input['razorpay_secret'] ) )
            $new_input['razorpay_secret'] = sanitize_text_field( $input['razorpay_secret'] );
		
		if( isset( $input['moodle_token'] ) )
            $new_input['moodle_token'] = sanitize_text_field( $input['moodle_token'] );
		
		if( !($input['reconcile']) )
            $new_input['reconcile'] = 0;

        return $new_input;
		
    }
	
	
 
}
 