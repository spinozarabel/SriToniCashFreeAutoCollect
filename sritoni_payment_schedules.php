<?php
// if directly called die. Use standard WP practices
defined( 'ABSPATH' ) or die( 'No direct access allowed!' );

// class definition begins
class sritoni_payment_schedules
{
    public $hook_suffix_menu_page_payment_schedules;            // hook suffix of main menu page
    public $hook_suffix_submenu_page_payment_schedules_setup;   // hook suffix of sub-menu page

    public $blog_id;

    public $timezone;



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
        // Once institution is selected by JQ the selected institution is sent to handler by Ajax
        // the action in the Ajax call  must be spzrbl_institution to match this action call.
        add_action('wp_ajax_spzrbl_institution', [$this, 'spzrbl_ajax_institution_handler'] );
    }

    private function init_function()
    {
        //
        $this->blog_id = get_current_blog_id();
        $this->studentcat_array = explode( ",", get_option("sritoni_settings")["studentcat_possible"] );

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


        $studentcat_array   = $this->studentcat_array;
        $institution_array  = ['HSEA', 'HSMHC', 'WHSMHC', 'EYM'];
        $class_array        = [1,2,3,4,5,6,7,8,9,10,11,12];      

        ?>
            <h3>Filter Users, optionally select and Submit to Setup Payent schedules for selected Users</h3>
            <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.25/css/jquery.dataTables.css">
        
            <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.js"></script>
        
            <button type="submit">Filter, select, and submit</button>

 

            <div style="display: inline-block" name="institution-select" id="institution-select">
                Institution: <select  id="institution" name="institution">

                                <option value="" disabled selected>Select institution</option>

                                <?php
                                    foreach($institution_array as $index => $institution) 
                                    {
                                        echo '<option value="' . $institution . '">' . $institution . '</option>';
                                    }
                                    unset ($institution);
                                ?>
                            </select>
            </div>

            <div style="display: inline-block" name="student-class-select" id="student-class-select">
            
                                        
                Class:  <select name="student-class" id="student-class">
                            <option value="">Select Institution first</option>
                        </select>
            </div>

            <div style="display: inline-block" name="category-select" id="category-select">
            
                                        
                Category:   <select name="category" id="category">
                                <option value="" disabled selected>Select Category</option>
                                <?php
                                    foreach($studentcat_array as $index => $studentcat) 
                                    {
                                        echo '<option value="' . $studentcat . '">' . $studentcat . '</option>';
                                    }
                                    unset ($studentcat);
                                ?>
                            </select>
            </div>

                

            <table id="table-payment-schedules-setup" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th>Name</th>
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
                        <?php
                        /*
                            // display the list of users and their meta in this loop
                            $args = array( 'blog_id' => $this->blog_id );

                            // using WP built-in method, get filtered users
                            $wp_users = get_users($args);

                            foreach ($wp_users as $wp_user):

                                $wp_user_id = $wp_user->ID;

                                $institution    = get_user_meta( $wp_user_id,  'sritoni_institution',   true );
                                $class          = get_user_meta( $wp_user_id,  'grade_or_class',         true );
                                $studentcat     = get_user_meta( $wp_user_id,  'sritoni_student_category',    true );

                                $is_payment_scheduled   = get_user_meta( $wp_user_id,  'is_payment_scheduled',    true );

                                switch (true)
                                {
                                    case (stripos('general', $studentcat) !== false):
                                        $installments = 1;
                                        break;
                                    case (stripos('installment2', $studentcat) !== false):
                                        $installments = 2;
                                        break;
                                    case (stripos('installment3', $studentcat) !== false):
                                        $installments = 3;
                                        break;
                                    case (stripos('installment4', $studentcat) !== false):
                                        $installments = 4;
                                        break;
                                }

                                ?>
                                    <tr>
                                        <th><?php echo htmlspecialchars($wp_user->data->display_name); ?></th>
                                        <th><?php echo htmlspecialchars($wp_user->data->user_login); ?></th>
                                        <th><?php echo htmlspecialchars($wp_user_id); ?></th>
                                        <th><?php echo htmlspecialchars($institution); ?></th>
                                        <th><?php echo htmlspecialchars($class); ?></th>
                                        <th><?php echo htmlspecialchars($studentcat); ?></th>
                                        <th><?php echo htmlspecialchars($total); ?></th>
                                        <th><?php echo htmlspecialchars($installments); ?></th>
                                        <th><?php echo htmlspecialchars($is_payment_scheduled); ?></th>
                                    </tr>
                                <?php
                            endforeach;
                        */    
                        ?>
                    </tbody>
            </table>
        <?php
    }

    /**
     *  When the admin user changes the institution value from dropdown menu that value is sent back by Ajax
     */
    public function spzrbl_ajax_institution_handler()
    {
        
        $institution = sanitize_text_field(POST['institution']);

        error_log('Ajax handler triggered');
        error_log('institution value sent:' . $institution);

        switch (true)
        {
            case ('HSEA' === $institution):
                $student_classes = [
                                        'grade1',
                                        'grade2',
                                        'grade3',
                                        'grade4',
                                        'grade5',
                                        'grade6',
                                        'grade7',
                                        'grade8',
                                        'grade9',
                                        'grade10',
                                        'grade11',
                                        'grade12',
                ];
                break;
            
            case ('WHSMHC' === $institution):
                $student_classes = [
                                        'WHSprimary1',
                                        'WHSprimary2',
                                        'WHSprimary3',
                ];
                break;

            case ('HSMHC' === $institution):
                $student_classes = [
                                        'hsmhc1',
                                        'hsmhc2',
                                        'hsmhc3',
                ];
                break;
        }

        wp_send_json($student_classes);	// This will be used by Javascript to show table of selected schools only

	    // finished now die
        wp_die(); // all ajax handlers should die when finished

    }

}   // end of class definition