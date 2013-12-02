<?php
/**
 * Plugin Name: Send to Top
 */


class Send_To_Top{

    /** A cache of the re-order data. */
    private $schemas_by_post_id = null;

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
        add_filter('manage_post_posts_columns', array($this, 'register_column'));
        add_filter('manage_slide_posts_columns', array($this, 'register_column'), 2000, 1);
        add_action('manage_posts_custom_column', array($this, 'retrieve_column'), 10, 2);
        add_action('quick_edit_custom_box', array($this, 'quick_edit'), 10, 2);
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
     * Retrieves the list of schemas in which post is ordered.
     *
     * @param int $post_id The post ID
     * @return array Schema list
     */
    public function get_schemas_by_post_id($post_id) {
        global $wpdb;
        if (is_null($this->schemas_by_post_id)) {
            $table_name = static::table_name();
            $order_records = $wpdb->get_results(
                "SELECT priority, order_schema, post_id FROM {$table_name} ORDER BY priority DESC");
            $order_records_by_schema = array();
            foreach ($order_records as $order_record) {
                $order_schema = $order_record->order_schema;
                $order_post_id = $order_record->post_id;
                if (!array_key_exists($order_schema, $order_records_by_schema)) {
                    $order_records_by_schema[$order_schema] = array();
                }
                $order_records_by_schema[$order_schema][] = array(
                    $order_post_id,
                    count($order_records_by_schema[$order_schema]),
                );
            }
            $schemas_by_post_id = array();
            foreach ($order_records_by_schema as $order_schema => $order_record_data) {
                foreach ($order_record_data as $order_record_datum) {
                    $order_post_id = $order_record_datum[0];
                    $order_ranking = $order_record_datum[1] + 1; // 0-index to 1-index
                    if (!array_key_exists($order_post_id, $schemas_by_post_id)) {
                        $schemas_by_post_id[$order_post_id] = array();
                    }
                    $schemas_by_post_id[$order_post_id][] = array(
                        'schema' => $order_schema,
                        'ranking' => $order_ranking,
                    );
                }
            }
            $this->schemas_by_post_id = $schemas_by_post_id;
        }
        if (array_key_exists($post_id, $this->schemas_by_post_id))
            return $this->schemas_by_post_id[$post_id];
        else
            return null;
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
     * Creates the (pseudo) post column
     *
     * @return array columns
     */
    public function register_column($columns) {
        $columns['stt-order'] = 'Order';
        return $columns;
    }

    /**
     * Populates the post column value
     *
     * @return void
     */
    public function retrieve_column($column_name, $id) {
        if ($column_name == 'stt-order') {
            $post_order_records = static::get_schemas_by_post_id($id);
            if ($post_order_records) {
                $first = true;
                foreach ($post_order_records as $post_order_record) {
                    if (!$first) echo '<br />';
                    $first = false;
                    $ranking = $post_order_record['ranking'];
                    $schema = $post_order_record['schema'];
                    $schemas = $this->get_schemas();
                    if (array_key_exists($schema, $schemas)) {
                        $schema = $schemas[$schema]['readable'];
                    } else {
                        $schema = "($schema)";
                    }
                    echo "#" . $ranking . " on " . $schema;
                }
            } else {
                echo 'None';
            }
        }
    }

    /**
     * Renders the quick edit menu
     *
     * @internal NOTE the -1 below is a signal to the JavaScript that the ID should be auto-populated
     * @return void
     */
    public function quick_edit($column_name, $post_type) {
        if ($column_name == 'stt-order') { ?>
            <fieldset class="inline-edit-col-right">
                <div class="inline-edit-col">
                <span class="title">Reorder</span>
                <select class="js-stt-dropdown">
                <option value="NULL" disabled="disabled" selected="selected">Select an option</option>
                <option value="NULL" disabled="disabled"></option>
                <?php
                    foreach($this->get_schemas() as $schema_key => $schema){
                        echo '<option value ="' . $schema_key . '">' . $schema['readable'] . '</option>';
                    }
                ?>
                </select>
                <input class="button button-primary js-stt-button" type="submit" value="Send to Top" disabled="disabled" />
                <script type="text/javascript">var GLOBAL_post_id = -1; <?php
                    /* See internal note in function comment about post_id */
                    ?> var GLOBAL_ajax_nonce = "<?php echo wp_create_nonce('stt_update'); ?>";</script>
                </div>
            </fieldset>
        <?php
        }
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
            <option value="NULL" disabled="disabled" selected="selected">Select an option</option>
            <option value="NULL" disabled="disabled"></option>
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
                primary key (id)
            );";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );
            update_option('stt_table_version', '1');
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
        echo '<p><strong>Reorder this post to the top of a section.</strong> Pressing the following button will not take you away from this page.</p>';
        echo '<select class="js-stt-dropdown">';
        echo '<option value="NULL" disabled="disabled" selected="selected">Select an option</option>';
        echo '<option value="NULL" disabled="disabled"></option>';
        foreach($this->get_schemas() as $schema_key => $schema){
            echo '<option value ="' . $schema_key . '">' . $schema['readable'] . '</option>';
        }
        echo  '</select>
               <input class="button button-primary js-stt-button" type="submit" value="Send to Top" disabled="disabled" />
               <script type="text/javascript">var GLOBAL_post_id = ' . $pid . '; var GLOBAL_ajax_nonce = "' . wp_create_nonce('stt_update') . '";</script>';
    }

    /**
     * Enqueues the javascript for the post page button AJAX interaction.
     *
     * @return void
     */
    public function stt_scripts(){
        wp_enqueue_script('stt-ajax-request', plugins_url() . '/send_to_top/request.js?version=2');
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
        $order_schemas = static::get_schemas();
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
        $schemas = static::get_schemas();
        $schema = @$query->query_vars["stt_schema"];
        if (!$schema && $query->is_main_query()) {
            $schema = static::get_schema();
        }
        if (!array_key_exists($schema, $schemas)) {
            $schema = null;
        }

        global $wpdb;
        if ($schema) {
            $table_name = static::table_name();
            $clauses['join'] .= $wpdb->prepare(" left outer join (select * from " .
                $table_name . " where order_schema = %s) " . $table_name .
                " on " . $wpdb->prefix . "posts.ID = " . $table_name .
                ".post_id", $schema);
            $clauses['orderby'] =  " {$table_name}.priority DESC, " . @$clauses['orderby'];
        }
        return $clauses;
    }

}


$send_to_top = new Send_To_Top();

