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
        add_action('add_meta_boxes', array($this, 'add_ui') );
        //add_filter('posts_orderby', array($this, 'set_ordering'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'stt_scripts'));
        add_action('wp_ajax_stt_update', array($this, 'handle_ajax'));
        //add_filter('posts_join_paged', array($this, 'join'));
	    //add_filter('posts_where', array($this, 'set_schema'), 10, 2);
	    add_filter('posts_clauses', array($this, 'set_query'), 10, 2);
        add_filter('stt_get_schemas', array($this, 'set_schemas'));
        // TODO: consolidate posts_orderby, posts_join_paged, and posts_where using posts_clauses
        global $wpdb;
        $this->table_name = $wpdb->prefix . "custom_order";
    }

    public function set_schemas($schemas){
        $schemas = array('arts' => array('readable' => 'A&E'),'uncategorized' => array('readable' => 'Uncategorized'),'key3' => array('readable'=>'option3'));
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
        //echo $curr_key;
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
	    $num_ordered = $this->schemas["$order_scheme"]['ordered'];
	    $sql = "delete from wp_custom_order where order_scheme=$order_scheme order by priority asc limit (select count(*)-$num_ordered
 from wp_custom_order)"; // remove all posts that are not in the top $num_ordered from the custom table
	    $wpdb->query($sql);
        die();
    }
    
    public function join($join_statement){
        $join_statement .= ' left outer join wp_custom_order on wp_posts.ID = wp_custom_order.post_id';
        return $join_statement;
    }
    public function set_ordering($orderby, $query){
        /**if ( $query->is_category() ){ //$query->is_home() && $query->is_main_query() ) {
            $query->set('orderby', 'title');
            $query->set('order', 'ASC');
        }
        switch($query->get('category_name')){
            case "news":
                $query->set('orderby','title');
                break;
            case "opinion":
                $query->set('orderby','modified');
                break;
            case "uncategorized":
                $query->set('orderby','#');
                break;
            default:
                $query->set('orderby','title');
        }
        $query->set('order','ASC');**/
        //$query->set('order',' wp_custom_order.priority wp_posts.id DESC');
        //$query->set('orderby','date');

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
