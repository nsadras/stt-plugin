<?php
/**
 * Plugin Name: Send to Top
 */


class Send_To_Top{

    /**
     * Registers hooks for this plugin.
     *
     * @return void
     */
    public function __construct(){
        register_activation_hook('send_to_top/send_to_top.php' , array('Send_To_Top', 'stt_activate'));
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('add_meta_boxes', array($this, 'add_ui') );
        add_action('admin_enqueue_scripts', array($this, 'stt_scripts'));
        add_action('wp_ajax_stt_update', array($this, 'handle_ajax'));
        add_filter('posts_clauses', array($this, 'set_query'), 10, 2);
        add_filter('stt_get_schemas', array($this, 'set_schemas'));
    }

    /**
     * Gets the MySQL table name
     *
     * @return string
     */
    public function table_name() {
        global $wpdb;
        return $wpdb->prefix . "custom_order";
    }

    /**
     * Gets the list of registered schemas
     *
     * @internal Must be called on use, in case of late bindings.
     * @return array
     */
    public function get_schemas() {
        return apply_filters('stt_get_schemas', array());
    }

    /**
     * Get the currently selected schema, if exists.
     *
     * @return NULL | string
     */
    public function get_schema() {
        return apply_filters('stt_get_schema', NULL);
    }

    /**
     * Creates admin menu item
     * 
     * @return void
     */
    public function register_menu(){
        $hook = add_submenu_page('tools.php', 'Send To Top Settings Menu', 'Send To Top', 'publish_posts', 'stt_menu.php', array($this, 'render_menu'));
    }

    /**
     * Renders the administration menu
     *
     * @return void
     */
    public function render_menu(){
        if (!current_user_can('publish_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        if (isset($_POST['_wpnonce'])) {
            if (!check_admin_referer("stt_menu_form")) { ?>
                <div class="error"><p>Your session has timed out. Please try again.</p></div><?php
            } else {
                $operation = $this->process_action(@$_POST["stt_menu_action"]);
                if (is_wp_error($operation)) { ?>
                    <div class="error"><p><strong>An error occurred: <?php echo $operation->get_error_message(); ?></strong></p></div><?php
                } else { ?>
                    <div class="updated"><p><strong><?php echo (is_string($operation) ? $operation : 'Finished.'); ?></strong></p></div><?php
                }
            }
        }

        ?>
    <div class="wrap">
        <div id="icon-tools" class="icon32"><br /></div>
        <h2>Send to Top</h2>
        <p>Use this page to reset order schemas</p>
        <form method="post" name="stt_menu_form">
            <?php wp_nonce_field("stt_menu_form"); ?>
            <select name="stt_menu_action" id="stt_menu_dropdown">
                <?php
                    foreach ($this->get_schemas() as $schema_key => $schema){
                        echo '<option value ="' . esc_attr($schema_key) . '">' . esc_html($schema['readable']) . '</option>';
                    }
                ?>
            </select>
            <input class="button button-primary" type="submit" value="Reset Ordering"/>
        </form>
    </div>
        <?php

    }

    /**
     * Process Admin Panel action
     *
     * @return void | WP_Error
     */
    protected function process_action($action) {
        global $wpdb;
        if (!$action || !array_key_exists($action, $this->get_schemas())) {
            return new WP_Error('invalid-schema', 'Invalid ordering schema selected');
        } else {
            $affected = $wpdb->delete(static::table_name(), array('order_schema' => $action));
        }
    }

    /**
     * Initializes database when plugin is activated
     *
     * @return void
     */
    public function stt_activate() {
        global $wpdb;
        $table_version = get_option('stt_table_version');
        if (!$table_version) {
            // Insert latest version of the database.
            $table_name = static::table_name();
            $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
                id int NOT NULL AUTO_INCREMENT,
                priority int NOT NULL,
                order_schema char(30),
                post_id bigint(20) unsigned,
                primary key (id),
                FOREIGN KEY (post_id) REFERENCES wp_posts(ID)
            );";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );
        }
    }

    /**
     * Adds send to top button and drop down menu to edit posts page
     *
     * @return void
     */
    public function add_ui() {
        add_meta_box('stt_button', 'Send To Top', array($this, 'render_form'), 'post');
        add_meta_box('stt_button', 'Send To Top', array($this, 'render_form'), 'slide');
    }

    /**
     * Print html for send to top button on posts page
     *
     * @return void
     */
    public function render_form( $post ){
        $pid = $post->ID;
        echo '<select class="js-stt-dropdown">';
        foreach($this->get_schemas() as $schema_key => $schema){
            echo '<option value ="' . $schema_key . '">' . $schema['readable'] . '</option>';
        }
        echo  '</select>
               <input class="button button-primary js-stt-button" type="submit" value="Send to Top" />
               <script type="text/javascript">var GLOBAL_post_id = ' . $pid . '; var GLOBAL_ajax_nonce = "' . wp_create_nonce('stt_update') . '";</script>';
    }

    /**
     * Enqueues the javascript for the post page button AJAX interaction.
     *
     * @return void
     */
    public function stt_scripts(){
        wp_enqueue_script('stt-ajax-request', plugins_url() . '/send_to_top/request.js');
    }

    /**
     * Handles AJAX request from JavaScript frontend
     *
     * @internal Ends execution.
     * @return void
     */
    public function handle_ajax() {
        check_ajax_referer('stt_update', 'security');
        if (!current_user_can('publish_posts')) {
            header('Status: 403 Forbidden');
            wp_die('You do not have sufficient permissions to access this page.',
                '', array('response' => 403));
        }
        if (!preg_match('/^\d+$/', @$_POST['post_id'])) {
            header('Status: 400 Bad Request');
            wp_die('Invalid post ID in request.',
                '', array('response' => 400));
        }
        if (!@$_POST['order_schema'] ||
            !array_key_exists($_POST['order_schema'], $this->get_schemas())) {
            header('Status: 400 Bad Request');
            wp_die('Invalid order schema in request.',
                '', array('response' => 400));
        }
        global $wpdb;
        $pid = intval($_POST['post_id']);
        $order_schemas = get_schemas();
        $order_schema = $_POST['order_schema'];
        $order_schema_data = $order_schemas[$order_schema];
        $timestamp = time();
        $table_name = static::table_name();
        $affected = $wpdb->update($table_name, array('priority' => $timestamp),
            array('post_id' => $pid, 'order_schema' => $order_schema));
        if ( $affected ){
            wp_die('Successful request.', '', array('response' => 200));
        } else {
            $wpdb->insert($table_name, array('post_id' => $pid,
                'order_schema' => $order_schema, 'priority' => $timestamp));
        }
        
        /**
         * Remove all posts that are not in the top $ordered from the custom table
         */
        $threshold_priority = $wpdb->get_var($wpdb->prepare(
            "select priority from {$table_name}
                where order_schema = %s
                order by priority desc
                limit %d , 1",
            $order_schema, $order_schema_data['ordered']
        ));

        if ($threshold_priority) {
            $wpdb->query($wpdb->prepare(
                "delete from {$table_name}
                    where order_schema = %s and priority <= %d",
                $order_schema, $threshold_priority
            ));
        }
    }

    /**
     * Modifies the main query to reorder posts.
     */
    public function set_query($clauses, $query){
        global $wpdb;
        $schema = $this->get_schema();
        if ($schema) {
            $table_name = static::table_name();
            $clauses['join'] .= $wpdb->prepare(" left outer join (select * from " .
                $table_name . " where order_schema = %s) " . $this->table_name .
                " on " . $wpdb->prefix . "posts.ID = " . $this->table_name .
                ".post_id", $schema);
            $clauses['orderby'] =  " {$table_name}.priority DESC ";
            // $clauses['where'] .= " and ". $wpdb->prefix ."posts.post_status = 'publish'";
        }
        return $clauses;
    }

}


$send_to_top = new Send_To_Top();

