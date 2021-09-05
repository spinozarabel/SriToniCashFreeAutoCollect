<?php
// if directly called die. Use standard WP practices
defined( 'ABSPATH' ) or die( 'No direct access allowed!' );

// class definition begins
class sritoni_payment_schedules
{
    public $hook_suffix_menu_page_payment_schedules;            // hook suffix of main menu page
    public $hook_suffix_submenu_page_payment_schedules_setup;   // hook suffix of sub-menu page

    public function __construct($verbose = false)
    {
        $this->verbose  = $verbose;
        $this->timezone = new DateTimeZone('Asia/Kolkata');

        // load actions for admin
            if (is_admin()) $this->define_admin_hooks();

        // load public facing actions
        $this->define_public_hooks();

        // execute an initialization routime conveniently placed in a function called init_function
        $this->init_function();

        // add_filter( 'woocommerce_grouped_price_html', 'max_grouped_price', 10, 3 );
  
    }   // End Of Constructor
  
  
    private function define_admin_hooks()
    {
        // add sub-menu for a new payments page. This function is a method belonging to the class sritoni_va_ec
        add_action( 'admin_menu',               [$this, 'add_payment_schedules_menu'] );

        // add action to register and enque the javascripts. Since we need this on the admin menu use the admin enque scripts
        add_action( 'admin_enqueue_scripts',    [$this, 'add_my_scripts']   );
    }



    private function define_public_hooks()
    {
        //
    }

    private function init_function()
    {
        //
    }

    public function add_payment_schedules_menu()
    {
        $this->hook_suffix_menu_page_payment_schedules =
            add_menu_page(  
                        'sritoni payments',                    // $page_title, 
                        'sritoni payments',                    // $menu_title,
                        'manage_options',                       // $capability,
                        'sritoni-payments',                    // $menu_slug
                        [$this, 'payment_schedules_setup_page_render'] );      // callable function

        
        $this->hook_suffix_submenu_page_payment_schedules_setup = 
            add_submenu_page( 
                        'sritoni-payments',	                            // string $parent_slug
                        'payment schedules setup',	                        // string $page_title
                        'setup',                                            // string $menu_title	
                        'manage_options',                                   // string $capability	
                        'payment-schedules-setup',                          // string $menu_slug		
                        [$this, 'payment_schedules_setup_page_render'] );   // callable $function = ''
    }


    /**
     *  register and enque jquery scripts with nonce for ajax calls. Load only for desired page
    *   called by add_action( 'wp_enqueue_scripts', 'add_my_scripts' );
    */
    public function add_my_scripts($hook_suffix)
    // register and enque jquery scripts wit nonce for ajax calls
    {
        // load script only on desired page-otherwise script looks for non-existent entities and creates errors
        if ($this->hook_suffix_submenu_page_payment_schedules_setup == $hook_suffix) 
        {
        
            // https://developer.wordpress.org/plugins/javascript/enqueuing/
            //wp_register_script($handle            , $src                                 , $deps         , $ver, $in_footer)
            wp_register_script('payment_schedules_setup_script', plugins_url('payment_schedules_setup.js', __FILE__), array('jquery'),'', true);

            wp_enqueue_script('payment_schedules_setup_script');

            $payment_schedules_setup_script_nonce = wp_create_nonce('payment_schedules_setup_script');
            // note the key here is the global my_ajax_obj that will be referenced by our Jquery in city.js
            //  wp_localize_script( string $handle,       string $object_name, associative array )
            wp_localize_script('payment_schedules_setup_script', 'payment_schedules_setup_ajax_obj', array(
                                                                                            'ajax_url' => admin_url( 'admin-ajax.php' ),
                                                                                            'nonce'    => $payment_schedules_setup_script_nonce,
                                                                                            ));
        }
    }

    public function payment_schedules_setup_page_render()
    {
        $timezone          = $this->timezone;   // new DateTimeZone('Asia/Kolkata');
        ?>
            <h3>Filter Users, optionally select and Submit to Setup Payent schedules for selected Users</h3>
            <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.25/css/jquery.dataTables.css">
        
            <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.js"></script>
        
            <button type="submit">Filter, select, and submit</button>
            <table id="table-payment-schedules-setup" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th>MoodleId</th>
                        <th>WPuserId</th>
                        <th>Institution</th>
                        <th>Class</th>
                        <th>Category</th>
                        <th>Total</th>
                        <th>Installments</th>
                        <th>Triggered</th>
                    </tr>
                </thead>
                    <tbody>
                    </tbody>
            </table>
        <?php
    }

}   // end of class definition