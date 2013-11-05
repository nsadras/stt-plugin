<?php
/*
Plugin Name: Send to Top
*/


// set up database when plugin is activated

class Send_To_Top{
    public $table_name;
    public $schemas; // array(slug -> array(readable => "News", ordered => 5 ))
 
    public function __construct(){
        register_activation_hook('send_to_top/send_to_top.php' , array('Send_To_Top', 'stt_activate'));
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('add_meta_boxes', array($this, 'add_ui') );
        add_action('admin_enqueue_scripts', array($this, 'stt_scripts'));
        add_action('wp_ajax_stt_update', array($this, 'handle_ajax'));
	    add_filter('posts_clauses', array($this, 'set_query'), 10, 2);
        add_filter('stt_get_schemas', array($this, 'set_schemas'));
        global $wpdb;
        $this->table_name = $wpdb->prefix . "custom_order";
    }

    public function register_menu(){
        $hook = add_submenu_page('tools.php', 'Send To Top Settings Menu', 'Send To Top', 'publish_posts', 'stt_menu.php', array($this, 'render_menu'));
        //add_action("admin_print_scripts-$hook", array($this, 'render_menu_assets');
    }

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

        <!-- admin page markup -->
        <h2> Send to Top </h2>
        <p> Use this page to reset order schemas </p>
        <form method="post" name="stt_menu_form">
            <?php wp_nonce_field("stt_menu_form"); ?>    
            <select name="stt_menu_action" id="stt_menu_dropdown">
                <?php
                    $this->schemas = apply_filters('stt_get_schemas', $this->schemas);
                    $keys = array_keys($this->schemas); 
                    for($i = 0, $size = count($this->schemas); $i < $size; $i++){
                        $curr_key = $keys[$i]; // keys are schema slugs
                        echo '<option value ="' . $curr_key . '">' . $this->schemas[$curr_key]['readable'] . '</option>';
                    }
                ?>
            </select>
            <input class="stt_button" type="submit" value="Reset Schema"></input>
        </form>
        <?php

    }

    public function render_menu_assets(){

    }

    /**
        Process Admin Panel action
    **/
    protected function process_action($action) {
        global $wpdb;
        $wpdb->delete($this->table_name,array('order_scheme' => "$action"));
    }

    public function set_schemas($schemas){
        $schemas = array('arts' => array('readable' => 'A&E', 'ordered' => 3),'uncategorized' => array('readable' => 'Uncategorized', 'ordered' => 3),'key3' => array('readable'=>'option3'));
        return $schemas;
    }

    public function stt_activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . "custom_order";
        if($wpdb->get_var("SHOW TABLES LIKE '" . $table_name . "'") != $table_name) { // check if table exists
            $sql = "CREATE TABLE $table_name (
                id int NOT NULL AUTO_INCREMENT,
                priority int NOT NULL,
                order_scheme char(30),
                post_id bigint(20) unsigned,
                primary key (id),
                FOREIGN KEY (post_id) REFERENCES wp_posts(ID)
            );";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql ); 
        }
    }

    // add send to top button and drop down menu to edit posts page
    public function add_ui() {
        add_meta_box(
            'stt_button',
            __( 'Send To Top', 'stt' ),
            array($this, 'render_form'),
            'post'
        );
    }

    // print html for form
    public function render_form( $post ){
        $pid = $post->ID;
        $this->schemas = apply_filters('stt_get_schemas', $this->schemas);
        $keys = array_keys($this->schemas); 
            echo '<select id="stt_dropdown">'; 
        for($i = 0, $size = count($this->schemas); $i < $size; $i++){
            $curr_key = $keys[$i]; // keys are schema slugs
            echo '<option value ="' . $curr_key . '">' . $this->schemas[$curr_key]['readable'] . '</option>';
        }
            echo  '</select>
                  <input class="stt_button" type="submit" value="Send to Top"></input>
                  <script type="text/javascript">var GLOBAL_post_id = ' . $pid . ';</script>';
    }

    // load AJAX js file
    public function stt_scripts(){
        wp_enqueue_script('stt-ajax-request', plugins_url() . '/send_to_top/request.js');
    }
    // Request handler
    public function handle_ajax(){
        global $wpdb;
        $pid = intval($_POST['post_id']);
        $order_scheme = strval($_POST['order_scheme']);
        $timestamp = time();
        //var_dump($order_scheme);
        $affected = $wpdb->update($this->table_name, array('priority'=>$timestamp), array('post_id'=>$pid,'order_scheme'=>"$order_scheme"));
        if ( $affected == 0 ){
            trigger_error($wpdb->insert($this->table_name, array('post_id'=>$pid,'order_scheme'=>"$order_scheme", 'priority'=>$timestamp)));
        }
        $this->schemas = apply_filters('stt_get_schemas', $this->schemas);
	    $num_ordered = $this->schemas["$order_scheme"]['ordered'];
	    $curr_count = $wpdb->get_var("select count(*) from $this->table_name where order_scheme='$order_scheme'");
        $to_delete = $curr_count - $num_ordered;
        if ( $to_delete < 0){
            $to_delete = 0;
        }
        //$var_dump($to_delete);
        $sql = "delete from wp_custom_order where order_scheme='$order_scheme' order by priority asc limit $to_delete"; // remove all posts that are not in the top $num_ordered from the custom table
	    $rows = $wpdb->query($sql);
        die($rows);
    }
    
    public function join($join_statement){
        $join_statement .= ' left outer join wp_custom_order on wp_posts.ID = wp_custom_order.post_id';
        return $join_statement;
    }
    public function set_ordering($orderby, $query){
        $orderby = ' wp_custom_order.priority DESC';
        //print_r($query);
        return $orderby;
    }

    public function set_schema($where, $query){
	// where wp_custom_order.order_scheme = something
    }

    public function set_query($clauses, $query){
        $category = $query->get('category_name');
        $clauses['join'] .= " left outer join (select * from wp_custom_order where order_scheme = \"$category\") wp_custom_order on wp_posts.ID = wp_custom_order.post_id";
        $clauses['orderby'] =  ' wp_custom_order.priority DESC';
        $clauses['where'] .= " and wp_posts.post_status = 'publish'";
        return $clauses; 
    }
}


$send_to_top = new Send_To_Top();

?>
