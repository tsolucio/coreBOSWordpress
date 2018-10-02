<?php
/**
 * Plugin Name:       coreBOS WP
 * Description:       coreBOS WP Plugin
 * Version:           1.0.0
 * Author:            JPL TSolucio, S. L.
 * Author URI:        https://tsolucio.com
 * Text Domain:       tsolucio
 * License:           Vizsage
 * License URI:       http://corebos.org/documentation/doku.php?id=en:devel:vizsage
 * GitHub Plugin URI: https://github.com/tsolucio/coreBOSWordpress
 */
 
// Constants
if(!defined('COREBOS_WP_URL'))
	define('COREBOS_WP_URL', plugin_dir_url( __FILE__ ));
if(!defined('COREBOS_WP_PATH'))
	define('COREBOS_WP_PATH', plugin_dir_path( __FILE__ ));


class CBWP {
    public $url = "";
    public $user = "";
    public $password = "";
    public $wsClient = null;
    public $currentUser = "";

    // Constructor
    public function __construct() {
        require_once('cncb/cbwsclib/WSClient.php');

        // Connection parameters
        $this->url = get_option('cbwp_url');
        $this->user = get_option('cbwp_username');
        $this->password = get_option('cbwp_password');
        
        $this->wsClient = new Vtiger_WSClient($this->url);

        // Login
        if (!$this->wsClient->doLogin($this->user, $this->password)) {
            //wp_die('Could not connect to your coreBOS Application. Verify your coreBOS WP Plugin Settings.');
        }

        add_action('swpm_front_end_profile_edited', array($this, 'after_profile_edit_callback'));
        add_action('wp_enqueue_scripts', array($this, 'add_scripts'));
        add_action('wp_ajax_save_project', array($this, 'save_project'));
        add_action('wpcf7_mail_sent', array($this, 'save_project_from_wpcf7'));
        add_action('admin_menu', array($this, 'settings_page'));
    }

    // Adding scripts
    public function add_scripts() {
        wp_enqueue_script('cb_wp_js', COREBOS_WP_URL. '/assets/js/main.js', array('jquery'), 1.0);
        wp_localize_script('cb_wp_js', 'cbwpJS', array('ajaxurl' => admin_url( 'admin-ajax.php' )));
    }

    // Update user information
    public function after_profile_edit_callback($member_info)
    {
        // Get wsid
        $query = "select id from Accounts where cod = ".$member_info["extra_info"].";";
        $result  = $this->wsClient->doQuery($query);

        if ($result) {
            $wsid = $result[0]["id"];

            // Update information
            $data = array(
                'email1' => $member_info["email"],
                'phone' => $member_info["phone"],
                'bill_street' => $member_info["address_street"],
                'bill_city' => $member_info["address_city"],
                'bill_state' => $member_info["address_state"],
                'bill_code' => $member_info["address_zipcode"],
                'bill_country' => $member_info["country"],
                'id' => $wsid
            );

            $moduleName = "Accounts";
            $update = $this->wsClient->doRevise($moduleName, $data);
        }
    }

    // Add Project
    public function save_project() {
        global $wpdb;

        if (isset($_POST['service']) && isset($_POST['time']) && isset($_POST['desc'])) {
            $service = $_POST['service'];
            $time = $_POST['time'];
            $description = $_POST['desc'];

            $serviceName = $service_wsid = $status = $contact_wsid = "";

            // Get service name
            $result = $wpdb->get_row($wpdb->prepare("SELECT name FROM wbk_services WHERE id = $service"));
            if ($result) {
                $serviceName = $result->name;
            }

            // Get service wsid
            $query = "select id from Services where servicename = '".$serviceName."';";
            $result  = $this->wsClient->doQuery($query);
            if ($result) {
                $service_wsid = $result[0]["id"];
            }

            // Get booking status
            $query = "SELECT status FROM wbk_appointments WHERE description = '$description' and service_id = '$service' and time = '$time'";
            $result = $wpdb->get_row($wpdb->prepare($query));
            if ($result) {
                $status = $result->status;
            }

            // Get account wsid
            $query = "select id from Accounts where cod = '".$this->currentUser->extra_info."';";
            $result  = $this->wsClient->doQuery($query);
            if ($result) {
                $contact_wsid = $result[0]["id"];
            }

            // Add booking as project in coreBOS
            if ($serviceName != "" && $service_wsid != "" && $status != "" && $contact_wsid != "") {
                $moduleName = 'Project';
                $data = array(
                    'projectname' => $serviceName,
                    'linktoaccountscontacts' => $contact_wsid,
                    'projectstatus' => $status,
                    'viacontacto' => "Website",
                    'startdate' => date("Y-m-d"),
                    'srvid' => $service_wsid,
                    'assigned_user_id' => $this->wsClient->_userid
                );

                $create = $this->wsClient->doCreate($moduleName, $data);
            }
        }

        wp_die();
    }

    // Creating settings page on Administrator dashboard
    public function settings_page() {
        $page_title = 'coreBOS WP Settings Page';
        $menu_title = 'coreBOS WP';
        $capability = 'manage_options';
        $slug = 'smashing_fields';
        $callback = array($this, 'settings_page_content');
        $icon = 'dashicons-admin-plugins';
        $position = 100;

        add_menu_page($page_title, $menu_title, $capability, $slug, $callback, $icon, $position);
    }

    // Settings page layout
    public function settings_page_content() {
        if (isset($_POST['cbwp_updated']) && $_POST['cbwp_updated'] === 'true') {
            $this->handle_settings_form();
        }
        ?>
        <div class="wrap">
            <h2>coreBOS WP Settings Page</h2>
            <hr />
            <form method="POST">
                <input type="hidden" name="cbwp_updated" value="true" />
                <?php wp_nonce_field('cbwp_settings_update', 'cbwp_settings_form'); ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th><label for="cbwp_url">Application URL</label></th>
                            <td><input name="cbwp_url" id="cbwp_url" type="text" value="<?php echo get_option('cbwp_url'); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label for="cbwp_username">Username</label></th>
                            <td><input name="cbwp_username" id="cbwp_username" type="text" value="<?php echo get_option('cbwp_username'); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label for="cbwp_password">Password</label></th>
                            <td><input name="cbwp_password" id="cbwp_password" type="text" value="<?php echo get_option('cbwp_password'); ?>" class="regular-text" /></td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="Update">
                </p>
            </form>
        </div>
        <?php
    }

    // Handling settings page
    public function handle_settings_form() {
        if (!isset($_POST['cbwp_settings_form']) || !wp_verify_nonce($_POST['cbwp_settings_form'], 'cbwp_settings_update')) {
            ?>
            <div class="error">
               <p>Sorry, your nonce was not correct. Please try again.</p>
            </div>
            <?php
            exit;
        } else {
            /*
             * TO DO.
             * Check if user is Admininstrator before update settings value.
             */

            if (true) {
                // Update settings
                $cbwp_url = sanitize_text_field($_POST['cbwp_url']);
                $cbwp_username = sanitize_text_field($_POST['cbwp_username']);
                $cbwp_password = sanitize_text_field($_POST['cbwp_password']);

                update_option('cbwp_url', $cbwp_url);
                update_option('cbwp_username', $cbwp_username);
                update_option('cbwp_password', $cbwp_password);

                ?>
                <div class="updated">
                    <p>Your coreBOS WP settings updated.</p>
                </div>
                <?php
            } else {
                ?>
                 <div class="error">
                    <p>You can not update codeBOS WP Settings.</p>
                </div>
                <?php
            }
        }
    }

    
    // Add project from wpcf7
    public function save_project_from_wpcf7($contact_form) {
        $submission = WPCF7_Submission::get_instance();

        if ($submission) {
            $projectname = $service_wsid = $status = $contact_wsid = $description = "";
            $servicename = $contact_form->title();
            $posted_data = $submission->get_posted_data();

            // Get service wsid
            $query = "select id from Services where servicename = '".$servicename."';";
            $result  = $this->wsClient->doQuery($query);
            if ($result) {
                $service_wsid = $result[0]["id"];
            }

            // Get account wsid
            $query = "select id from Accounts where cod = '".$this->currentUser->extra_info."';";
            $result  = $this->wsClient->doQuery($query);
            if ($result) {
                $contact_wsid = $result[0]["id"];
            }

            if (isset($posted_data['your-subject']) && !empty($posted_data['your-subject'])) {
                $projectname = $posted_data['your-subject'];
            }

            if (isset($posted_data['your-message']) && !empty($posted_data['your-message'])) {
                $description = $posted_data['your-message'];
            }

            $status = "Pending";

            // Add booking as project in coreBOS
            if ($projectname != "" && $service_wsid != "" && $status != "" && $contact_wsid != "") {
                $moduleName = 'Project';
                $data = array(
                    'projectname' => $projectname,
                    'linktoaccountscontacts' => $contact_wsid,
                    'projectstatus' => $status,
                    'viacontacto' => "Website",
                    'startdate' => date("Y-m-d"),
                    'srvid' => $service_wsid,
                    'description' => $description,
                    'assigned_user_id' => $this->wsClient->_userid
                );

                $create = $this->wsClient->doCreate($moduleName, $data);
            }
        }
    }
}

global $cbwp;

// Creating object after other plugins
add_action('plugins_loaded', function() {
    $cbwp = new CBWP();

    if (SwpmMemberUtils::is_member_logged_in()) {
        $member_id = SwpmMemberUtils::get_logged_in_members_id();
        $swpm_user = SwpmMemberUtils::get_user_by_id($member_id);

        $cbwp->currentUser = $swpm_user;
    }
});