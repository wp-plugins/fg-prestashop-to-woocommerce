<?php
/**
 * Plugin Name: FG PrestaShop to WooCommerce
 * Plugin Uri:  https://wordpress.org/plugins/fg-prestashop-to-woocommerce/
 * Description: A plugin to migrate PrestaShop e-commerce solution to WooCommerce
 * Version:     1.12.0
 * Author:      Frédéric GILLES
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

if ( !defined('WP_LOAD_IMPORTERS') ) return;

require_once 'compatibility.php';

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) ) {
		require_once $class_wp_importer;
	}
}

if ( !function_exists( 'fgp2wc_load' ) ) {
	add_action( 'plugins_loaded', 'fgp2wc_load', 20 );
	
	function fgp2wc_load() {
		new fgp2wc();
	}
}

if ( !class_exists('fgp2wc', false) ) {
	class fgp2wc extends WP_Importer {
		
		public $plugin_options;					// Plug-in options
		public $default_language = 1;			// Default language ID
		public $media_count = 0;				// Number of imported medias
		protected $post_type = 'post';			// post or page
		protected $prestashop_version = '';		// PrestaShop DB version
		protected $default_backorders = 'no';	// Allow backorders
		protected $product_types = array();
		protected $imported_categories = array();
		protected $global_tax_rate = 0;
		private $product_cat_prefix = 'ps_product_cat_';
		
		/**
		 * Sets up the plugin
		 */
		public function __construct() {
			$this->plugin_options = array();
			
			add_action( 'init', array($this, 'init') ); // Hook on init
			add_action( 'admin_enqueue_scripts', array($this, 'enqueue_scripts') );
			add_action( 'fgp2wc_post_test_database_connection', array($this, 'get_prestashop_info'), 9 );
			add_action( 'fgp2wc_post_test_database_connection', array ($this, 'test_woocommerce_activation') );
			add_action( 'load-importer-fgp2wc', array($this, 'add_help_tab'), 20 );
			add_action( 'fgp2wc_import_notices', array ($this, 'display_media_count') );
			add_action( 'fgp2wc_post_empty_database', array ($this, 'delete_woocommerce_data'), 10, 1 );
		}
		
		/**
		 * Initialize the plugin
		 */
		public function init() {
			load_plugin_textdomain( 'fgp2wc', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		
			register_importer('fgp2wc', __('PrestaShop', 'fgp2wc'), __('Import PrestaShop e-commerce solution to WooCommerce', 'fgp2wc'), array($this, 'dispatch'));
			
			// Suspend the cache during the migration to avoid exhausted memory problem
			wp_suspend_cache_addition(true);
			wp_suspend_cache_invalidation(true);
		}
		
		/**
		 * Loads Javascripts in the admin
		 */
		public function enqueue_scripts() {
			wp_enqueue_script('jquery');
		}
		
		/**
		 * Display admin notice
		 */
		public function display_admin_notice( $message )	{
			echo '<div class="updated"><p>['.__CLASS__.'] '.$message.'</p></div>';
			error_log('[INFO] [' . __CLASS__ . '] ' . $message);
		}

		/**
		 * Display admin error
		 */
		public function display_admin_error( $message )	{
			echo '<div class="error"><p>['.__CLASS__.'] '.$message.'</p></div>';
			error_log('[ERROR] [' . __CLASS__ . '] ' . $message);
		}

		/**
		 * Dispatch the actions
		 */
		public function dispatch() {
			set_time_limit(7200);
			
			// Default values
			$this->plugin_options = array(
				'automatic_empty'				=> 0,
				'url'							=> null,
				'hostname'						=> 'localhost',
				'port'							=> 3306,
				'database'						=> null,
				'username'						=> 'root',
				'password'						=> '',
				'prefix'						=> 'ps_',
				'skip_media'					=> 0,
				'first_image'					=> 'as_is_and_featured',
				'import_external'				=> 0,
				'import_duplicates'				=> 0,
				'force_media_import'			=> 0,
				'meta_keywords_in_tags'			=> 0,
				'import_as_pages'				=> 0,
				'timeout'						=> 5,
				'price'							=> 'without_tax',
				'first_image_not_in_gallery'	=> false,
			);
			$options = get_option('fgp2wc_options');
			if ( is_array($options) ) {
				$this->plugin_options = array_merge($this->plugin_options, $options);
			}
			
			// Check if the upload directory is writable
			$upload_dir = wp_upload_dir();
			if ( !is_writable($upload_dir['basedir']) ) {
				$this->display_admin_error(__('The wp-content directory must be writable.', 'fgp2wc'));
			}
			
			if ( isset($_POST['empty']) ) {

				// Delete content
				if ( check_admin_referer( 'empty', 'fgp2wc_nonce' ) ) { // Security check
					if ($this->empty_database($_POST['empty_action'])) { // Empty WP database
						$this->display_admin_notice(__('WordPress content removed', 'fgp2wc'));
					} else {
						$this->display_admin_error(__('Couldn\'t remove content', 'fgp2wc'));
					}
					wp_cache_flush();
				}
			}
			
			elseif ( isset($_POST['save']) ) {
				
				// Save database options
				$this->save_plugin_options();
				$this->display_admin_notice(__('Settings saved', 'fgp2wc'));
			}
			
			elseif ( isset($_POST['test']) ) {
				
				// Save database options
				$this->save_plugin_options();
				
				// Test the database connection
				if ( check_admin_referer( 'parameters_form', 'fgp2wc_nonce' ) ) { // Security check
					$this->test_database_connection();
				}
			}
			
			elseif ( isset($_POST['import']) ) {
				
				// Save database options
				$this->save_plugin_options();
				
				// Automatic empty
				if ( $this->plugin_options['automatic_empty'] ) {
					if ($this->empty_database('all')) {
						$this->display_admin_notice(__('WordPress content removed', 'fgp2wc'));
					} else {
						$this->display_admin_error(__('Couldn\'t remove content', 'fgp2wc'));
					}
					wp_cache_flush();
				}
				
				// Import content
				if ( check_admin_referer( 'parameters_form', 'fgp2wc_nonce' ) ) { // Security check
					$this->import();
				}
			}
			
			$this->admin_build_page(); // Display the form
		}
		
		/**
		 * Build the option page
		 * 
		 */
		private function admin_build_page() {
			$cat_count = count(get_categories(array('hide_empty' => 0)));
			$tags_count = count(get_tags(array('hide_empty' => 0)));
			
			$data = $this->plugin_options;
			
			$data['title'] = __('Import PrestaShop', 'fgp2wc');
			$data['description'] = __('This plugin will import products, categories, tags, images and CMS from PrestaShop to WooCommerce/WordPress.<br />Compatible with PrestaShop versions 1.3 to 1.6.', 'fgp2wc');
			$data['description'] .= "<br />\n" . __('For any issue, please read the <a href="http://wordpress.org/plugins/fg-prestashop-to-woocommerce/faq/" target="_blank">FAQ</a> first.', 'fgp2wc');
			$data['posts_count'] = $this->count_posts('post');
			$data['pages_count'] = $this->count_posts('page');
			$data['media_count'] = $this->count_posts('attachment');
			$data['products_count'] = $this->count_posts('product');
			$data['database_info'] = array(
				sprintf(_n('%d category', '%d categories', $cat_count, 'fgp2wc'), $cat_count),
				sprintf(_n('%d post', '%d posts', $data['posts_count'], 'fgp2wc'), $data['posts_count']),
				sprintf(_n('%d page', '%d pages', $data['pages_count'], 'fgp2wc'), $data['pages_count']),
				sprintf(_n('%d product', '%d products', $data['products_count'], 'fgp2wc'), $data['products_count']),
				sprintf(_n('%d media', '%d medias', $data['media_count'], 'fgp2wc'), $data['media_count']),
				sprintf(_n('%d tag', '%d tags', $tags_count, 'fgp2wc'), $tags_count),
			);
			
			// Hook for modifying the admin page
			$data = apply_filters('fgp2wc_pre_display_admin_page', $data);
			
			include('admin_build_page.tpl.php');
			
			// Hook for doing other actions after displaying the admin page
			do_action('fgp2wc_post_display_admin_page');
			
		}
		
		/**
		 * Count the number of posts for a post type
		 * @param string $post_type
		 */
		public function count_posts($post_type) {
			$count = 0;
			$excluded_status = array('trash', 'auto-draft');
			$tab_count = wp_count_posts($post_type);
			foreach ( $tab_count as $key => $value ) {
				if ( !in_array($key, $excluded_status) ) {
					$count += $value;
				}
			}
			return $count;
		}
		
		/**
		 * Add an help tab
		 * 
		 */
		public function add_help_tab() {
			$screen = get_current_screen();
			$screen->add_help_tab(array(
				'id'	=> 'fgp2wc_help_instructions',
				'title'	=> __('Instructions'),
				'content'	=> '',
				'callback' => array($this, 'help_instructions'),
			));
			$screen->add_help_tab(array(
				'id'	=> 'fgp2wc_help_options',
				'title'	=> __('Options'),
				'content'	=> '',
				'callback' => array($this, 'help_options'),
			));
			$screen->set_help_sidebar(__('<a href="http://wordpress.org/plugins/fg-prestashop-to-woocommerce/faq/" target="_blank">FAQ</a>'), 'fgp2wc');
		}
		
		/**
		 * Instructions help screen
		 * 
		 * @return string Help content
		 */
		public function help_instructions() {
			include('help-instructions.tpl.php');
		}
		
		/**
		 * Options help screen
		 * 
		 * @return string Help content
		 */
		public function help_options() {
			include('help-options.tpl.php');
		}
		
		/**
		 * Open the connection on the PrestaShop database
		 *
		 * return boolean Connection successful or not
		 */
		protected function prestashop_connect() {
			global $prestashop_db;

			if ( !class_exists('PDO') ) {
				$this->display_admin_error(__('PDO is required. Please enable it.', 'fgp2wc'));
				return false;
			}
			try {
				$prestashop_db = new PDO('mysql:host=' . $this->plugin_options['hostname'] . ';port=' . $this->plugin_options['port'] . ';dbname=' . $this->plugin_options['database'], $this->plugin_options['username'], $this->plugin_options['password'], array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''));
				if ( defined('WP_DEBUG') && WP_DEBUG ) {
					$prestashop_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Display SQL errors
				}
			} catch ( PDOException $e ) {
				$this->display_admin_error(__('Couldn\'t connect to the PrestaShop database. Please check your parameters. And be sure the WordPress server can access the PrestaShop database.', 'fgp2wc') . '<br />' . $e->getMessage());
				return false;
			}
			return true;
		}
		
		/**
		 * Execute a SQL query on the PrestaShop database
		 * 
		 * @param string $sql SQL query
		 * @return array Query result
		 */
		public function prestashop_query($sql) {
			global $prestashop_db;
			$result = array();
			
			try {
				$query = $prestashop_db->query($sql, PDO::FETCH_ASSOC);
				if ( is_object($query) ) {
					foreach ( $query as $row ) {
						$result[] = $row;
					}
				}
				
			} catch ( PDOException $e ) {
				$this->display_admin_error(__('Error:', 'fgp2wc') . $e->getMessage());
			}
			return $result;
		}
		
		/**
		 * Delete all posts, medias and categories from the database
		 *
		 * @param string $action	newposts = removes only new imported posts
		 * 							all = removes all
		 * @return boolean
		 */
		private function empty_database($action) {
			global $wpdb;
			$result = true;
			
			$wpdb->show_errors();
			
			// Hook for doing other actions before emptying the database
			do_action('fgp2wc_pre_empty_database', $action);
			
			$sql_queries = array();
			
			if ( $action == 'all' ) {
				// Remove all content
				$start_id = 1;
				update_option('fgp2wc_start_id', $start_id);
				
				$sql_queries[] = "TRUNCATE $wpdb->commentmeta";
				$sql_queries[] = "TRUNCATE $wpdb->comments";
				$sql_queries[] = "TRUNCATE $wpdb->term_relationships";
				$sql_queries[] = "TRUNCATE $wpdb->postmeta";
				$sql_queries[] = "TRUNCATE $wpdb->posts";
				$sql_queries[] = <<<SQL
-- Delete Terms
DELETE FROM $wpdb->terms
WHERE term_id > 1 -- non-classe
SQL;
				$sql_queries[] = <<<SQL
-- Delete Terms taxonomies
DELETE FROM $wpdb->term_taxonomy
WHERE term_id > 1 -- non-classe
SQL;
				$sql_queries[] = "ALTER TABLE $wpdb->terms AUTO_INCREMENT = 2";
				$sql_queries[] = "ALTER TABLE $wpdb->term_taxonomy AUTO_INCREMENT = 2";
			} else {
				// Remove only new imported posts
				// WordPress post ID to start the deletion
				$start_id = intval(get_option('fgp2wc_cms_start_id'));
				if ( $start_id != 0) {
					
					$sql_queries[] = <<<SQL
-- Delete Comments meta
DELETE FROM $wpdb->commentmeta
WHERE comment_id IN
	(
	SELECT comment_ID FROM $wpdb->comments
	WHERE comment_post_ID IN
		(
		SELECT ID FROM $wpdb->posts
		WHERE (post_type IN ('post', 'page', 'attachment', 'revision')
		OR post_status = 'trash'
		OR post_title = 'Brouillon auto')
		AND ID >= $start_id
		)
	);
SQL;

					$sql_queries[] = <<<SQL
-- Delete Comments
DELETE FROM $wpdb->comments
WHERE comment_post_ID IN
	(
	SELECT ID FROM $wpdb->posts
	WHERE (post_type IN ('post', 'page', 'attachment', 'revision')
	OR post_status = 'trash'
	OR post_title = 'Brouillon auto')
	AND ID >= $start_id
	);
SQL;

					$sql_queries[] = <<<SQL
-- Delete Term relashionships
DELETE FROM $wpdb->term_relationships
WHERE `object_id` IN
	(
	SELECT ID FROM $wpdb->posts
	WHERE (post_type IN ('post', 'page', 'attachment', 'revision')
	OR post_status = 'trash'
	OR post_title = 'Brouillon auto')
	AND ID >= $start_id
	);
SQL;

					$sql_queries[] = <<<SQL
-- Delete Post meta
DELETE FROM $wpdb->postmeta
WHERE post_id IN
	(
	SELECT ID FROM $wpdb->posts
	WHERE (post_type IN ('post', 'page', 'attachment', 'revision')
	OR post_status = 'trash'
	OR post_title = 'Brouillon auto')
	AND ID >= $start_id
	);
SQL;

					$sql_queries[] = <<<SQL
-- Delete Posts
DELETE FROM $wpdb->posts
WHERE (post_type IN ('post', 'page', 'attachment', 'revision')
OR post_status = 'trash'
OR post_title = 'Brouillon auto')
AND ID >= $start_id;
SQL;
				}
			}
			
			// Execute SQL queries
			if ( count($sql_queries) > 0 ) {
				foreach ( $sql_queries as $sql ) {
					$result &= $wpdb->query($sql);
				}
			}
			
			// Hook for doing other actions after emptying the database
			do_action('fgp2wc_post_empty_database', $action);
			
			// Re-count categories and tags items
			$this->terms_count();
			
			// Update cache
			$this->clean_cache();
			
			$this->optimize_database();
			
			$wpdb->hide_errors();
			return ($result !== false);
		}

		/**
		 * Optimize the database
		 *
		 */
		protected function optimize_database() {
			global $wpdb;
			
			$sql = <<<SQL
OPTIMIZE TABLE 
`$wpdb->commentmeta` ,
`$wpdb->comments` ,
`$wpdb->options` ,
`$wpdb->postmeta` ,
`$wpdb->posts` ,
`$wpdb->terms` ,
`$wpdb->term_relationships` ,
`$wpdb->term_taxonomy`
SQL;
			$wpdb->query($sql);
		}
		
		/**
		 * Delete all woocommerce data
		 *
		 */
		public function delete_woocommerce_data($action) {
			global $wpdb;
			global $wc_product_attributes;
			
			$wpdb->show_errors();
			
			$sql_queries = array();
			$sql_queries[] = <<<SQL
-- Delete WooCommerce term metas
TRUNCATE {$wpdb->prefix}woocommerce_termmeta
SQL;
			$sql_queries[] = <<<SQL
-- Delete WooCommerce attribute taxonomies
TRUNCATE {$wpdb->prefix}woocommerce_attribute_taxonomies
SQL;

			$sql_queries[] = <<<SQL
-- Delete WooCommerce order items
TRUNCATE {$wpdb->prefix}woocommerce_order_items
SQL;

			$sql_queries[] = <<<SQL
-- Delete WooCommerce order item metas
TRUNCATE {$wpdb->prefix}woocommerce_order_itemmeta
SQL;

			// Execute SQL queries
			if ( count($sql_queries) > 0 ) {
				foreach ( $sql_queries as $sql ) {
					$wpdb->query($sql);
				}
			}
			
			// Reset the WC pages flags
			$wc_pages = array('shop', 'cart', 'checkout', 'myaccount');
			foreach ( $wc_pages as $wc_page ) {
				update_option('woocommerce_' . $wc_page . '_page_id', 0);
			}
			
			// Empty attribute taxonomies cache
			delete_transient('wc_attribute_taxonomies');
			$wc_product_attributes = array();
			
			// Reset the PrestaShop last imported post ID
			update_option('fgp2wc_last_prestashop_cms_id', 0);
			update_option('fgp2wc_last_prestashop_product_id', 0);
			
			$wpdb->hide_errors();
			
			$this->display_admin_notice(__('WooCommerce data deleted', 'fgp2wc'));
			
			// Recreate WooCommerce default data
			if ( class_exists('WC_Install') ) {
				WC_Install::create_pages();
				$this->display_admin_notice(__('WooCommerce default data created', 'fgp2wc'));
			}
		}
		
		/**
		 * Test the database connection
		 * 
		 * @return boolean
		 */
		function test_database_connection() {
			global $prestashop_db;
			
			if ( $this->prestashop_connect() ) {
				try {
					$prefix = $this->plugin_options['prefix'];
					
					// Test that the "product" table exists
					$result = $prestashop_db->query("DESC ${prefix}product");
					if ( !is_a($result, 'PDOStatement') ) {
						$errorInfo = $prestashop_db->errorInfo();
						throw new PDOException($errorInfo[2], $errorInfo[1]);
					}
					
					$this->display_admin_notice(__('Connected with success to the PrestaShop database', 'fgp2wc'));
					
					do_action('fgp2wc_post_test_database_connection');
					
					return true;
					
				} catch ( PDOException $e ) {
					$this->display_admin_error(__('Couldn\'t connect to the PrestaShop database. Please check your parameters. And be sure the WordPress server can access the PrestaShop database.', 'fgp2wc') . '<br />' . $e->getMessage());
					return false;
				}
				$prestashop_db = null;
			}
		}
		
		/**
		 * Test if the WooCommerce plugin is activated
		 *
		 * @return bool True if the WooCommerce plugin is activated
		 */
		public function test_woocommerce_activation() {
			if ( !class_exists('WooCommerce', false) ) {
				$this->display_admin_error(__('Error: the <a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce plugin</a> must be installed and activated to import the products.', 'fgp2wc'));
				return false;
			}
			return true;
		}

		/**
		 * Get some PrestaShop information
		 *
		 */
		public function get_prestashop_info() {
			$message = __('PrestaShop data found:', 'fgp2wc') . '<br />';
			
			// Products
			$products_count = $this->get_products_count();
			$message .= sprintf(_n('%d product', '%d products', $products_count, 'fgp2wc'), $products_count) . '<br />';
			
			// Articles
			$posts_count = $this->get_cms_count();
			$message .= sprintf(_n('%d article', '%d articles', $posts_count, 'fgp2wc'), $posts_count) . '<br />';
			
			// Employees
			$employees_count = $this->get_employees_count();
			$message .= sprintf(_n('%d employee', '%d employees', $employees_count, 'fgp2wc'), $employees_count) . '<br />';
			
			// Customers
			$customers_count = $this->get_customers_count();
			$message .= sprintf(_n('%d customer', '%d customers', $customers_count, 'fgp2wc'), $customers_count) . '<br />';
			
			// Orders
			$orders_count = $this->get_orders_count();
			$message .= sprintf(_n('%d order', '%d orders', $orders_count, 'fgp2wc'), $orders_count) . '<br />';
			
			$message = apply_filters('fgp2wc_pre_display_prestashop_info', $message);
			
			$this->display_admin_notice($message);
		}
		
		/**
		 * Get the number of PrestaShop products
		 * 
		 * @return int Number of products
		 */
		private function get_products_count() {
			$prefix = $this->plugin_options['prefix'];
			$sql = "
				SELECT COUNT(*) AS nb
				FROM ${prefix}product
			";
			$result = $this->prestashop_query($sql);
			$products_count = isset($result[0]['nb'])? $result[0]['nb'] : 0;
			return $products_count;
		}
		
		/**
		 * Get the number of PrestaShop articles
		 * 
		 * @return int Number of articles
		 */
		private function get_cms_count() {
			$prefix = $this->plugin_options['prefix'];
			$sql = "
				SELECT COUNT(*) AS nb
				FROM ${prefix}cms
			";
			$result = $this->prestashop_query($sql);
			$cms_count = isset($result[0]['nb'])? $result[0]['nb'] : 0;
			return $cms_count;
		}
		
		/**
		 * Get the number of PrestaShop employees
		 * 
		 * @return int Number of employees
		 */
		private function get_employees_count() {
			$prefix = $this->plugin_options['prefix'];
			$sql = "
				SELECT COUNT(*) AS nb
				FROM ${prefix}employee
				WHERE active = 1
			";
			$result = $this->prestashop_query($sql);
			$employees_count = isset($result[0]['nb'])? $result[0]['nb'] : 0;
			return $employees_count;
		}
		
		/**
		 * Get the number of PrestaShop customers
		 * 
		 * @return int Number of customers
		 */
		private function get_customers_count() {
			$prefix = $this->plugin_options['prefix'];
			$sql = "
				SELECT COUNT(*) AS nb
				FROM ${prefix}customer
				WHERE active = 1
			";
			$result = $this->prestashop_query($sql);
			$customers_count = isset($result[0]['nb'])? $result[0]['nb'] : 0;
			return $customers_count;
		}
		
		/**
		 * Get the number of PrestaShop orders
		 * 
		 * @return int Number of orders
		 */
		private function get_orders_count() {
			$prefix = $this->plugin_options['prefix'];
			$sql = "
				SELECT COUNT(*) AS nb
				FROM ${prefix}orders
			";
			$result = $this->prestashop_query($sql);
			$orders_count = isset($result[0]['nb'])? $result[0]['nb'] : 0;
			return $orders_count;
		}
		
		/**
		 * Save the plugin options
		 *
		 */
		private function save_plugin_options() {
			$this->plugin_options = array_merge($this->plugin_options, $this->validate_form_info());
			update_option('fgp2wc_options', $this->plugin_options);
			
			// Hook for doing other actions after saving the options
			do_action('fgp2wc_post_save_plugin_options');
		}
		
		/**
		 * Validate POST info
		 *
		 * @return array Form parameters
		 */
		private function validate_form_info() {
			// Add http:// before the URL if it is missing
			$url = filter_input(INPUT_POST, 'url', FILTER_SANITIZE_URL);
			if ( !empty($url) && (preg_match('#^https?://#', $url) == 0) ) {
				$url = 'http://' . $url;
			}
			return array(
				'automatic_empty'				=> filter_input(INPUT_POST, 'automatic_empty', FILTER_VALIDATE_BOOLEAN),
				'url'							=> $url,
				'hostname'						=> filter_input(INPUT_POST, 'hostname', FILTER_SANITIZE_STRING),
				'port'							=> filter_input(INPUT_POST, 'port', FILTER_SANITIZE_NUMBER_INT),
				'database'						=> filter_input(INPUT_POST, 'database', FILTER_SANITIZE_STRING),
				'username'						=> filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING),
				'password'						=> filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING),
				'prefix'						=> filter_input(INPUT_POST, 'prefix', FILTER_SANITIZE_STRING),
				'skip_media'					=> filter_input(INPUT_POST, 'skip_media', FILTER_VALIDATE_BOOLEAN),
				'first_image'					=> filter_input(INPUT_POST, 'first_image', FILTER_SANITIZE_STRING),
				'import_external'				=> filter_input(INPUT_POST, 'import_external', FILTER_VALIDATE_BOOLEAN),
				'import_duplicates'				=> filter_input(INPUT_POST, 'import_duplicates', FILTER_VALIDATE_BOOLEAN),
				'force_media_import'			=> filter_input(INPUT_POST, 'force_media_import', FILTER_VALIDATE_BOOLEAN),
				'meta_keywords_in_tags'			=> filter_input(INPUT_POST, 'meta_keywords_in_tags', FILTER_VALIDATE_BOOLEAN),
				'import_as_pages'				=> filter_input(INPUT_POST, 'import_as_pages', FILTER_VALIDATE_BOOLEAN),
				'timeout'						=> filter_input(INPUT_POST, 'timeout', FILTER_SANITIZE_NUMBER_INT),
				'price'							=> filter_input(INPUT_POST, 'price', FILTER_SANITIZE_STRING),
				'first_image_not_in_gallery'	=> filter_input(INPUT_POST, 'first_image_not_in_gallery', FILTER_VALIDATE_BOOLEAN),
			);
		}
		
		/**
		 * Import
		 *
		 */
		private function import() {
			global $prestashop_db;
			
			if ( $this->prestashop_connect() ) {
				
				$time_start = microtime(true);
				
				// Check prerequesites before the import
				$do_import = apply_filters('fgp2wc_pre_import_check', true);
				if ( !$do_import) return;
				
				$this->post_type = ($this->plugin_options['import_as_pages'] == 1) ? 'page' : 'post';

				// Hook for doing other actions before the import
				do_action('fgp2wc_pre_import');
				
				$this->product_types = $this->create_woocommerce_product_types(); // (Re)create the WooCommerce product types
				$this->global_tax_rate = $this->get_default_tax_rate();
				$this->import_configuration();
				if ( !isset($this->premium_options['skip_cms']) || !$this->premium_options['skip_cms'] ) {
					$this->import_cms();
				}
				if ( !isset($this->premium_options['skip_products']) || !$this->premium_options['skip_products'] ) {
					$this->import_product_categories();
					$this->import_products();
				}				
				// Hook for doing other actions after the import
				do_action('fgp2wc_post_import');
				
				// Hook for other notices
				do_action('fgp2wc_import_notices');
				
				// Debug info
				if ( defined('WP_DEBUG') && WP_DEBUG ) {
					$this->display_admin_notice(sprintf("Memory used: %s bytes<br />\n", number_format(memory_get_usage())));
					$time_end = microtime(true);
					$this->display_admin_notice(sprintf("Duration: %d sec<br />\n", $time_end - $time_start));
				}
				
				$prestashop_db = null;
				
				wp_cache_flush();
			}
		}
		
		/**
		 * Create the WooCommerce product types
		 *
		 * @return array Product types
		 */
		private function create_woocommerce_product_types() {
			$tab_types = array();
			$taxonomy = 'product_type';
			$product_types = array(
				'simple',
				'grouped',
				'variable',
				'external',
			);
			
			foreach ( $product_types as $product_type ) {
				$term = get_term_by('slug', $product_type, $taxonomy);
				if ( !empty($term) ) {
					$tab_types[$product_type] = $term->term_id;
				} else {
					$new_term = wp_insert_term($product_type, $taxonomy);
					if ( !is_wp_error($new_term) ) {
						$tab_types[$product_type] = $new_term['term_id'];
					}
				}
			}
			return $tab_types;
		}
		
		/**
		 * Import PrestaShop configuration
		 */
		private function import_configuration() {
			$config = $this->get_configuration();
			$this->default_language = $config['PS_LANG_DEFAULT'];
			$this->prestashop_version = isset($config['PS_VERSION_DB'])? $config['PS_VERSION_DB'] : 0;
			$this->default_backorders = ($config['PS_ORDER_OUT_OF_STOCK'] == 1)? 'yes' : 'no';
		}

		/**
		 * Import CMS data
		 */
		private function import_cms() {
			$this->import_cms_categories();
			$this->import_cms_articles();
		}
		
		/**
		 * Import CMS categories
		 *
		 * @return int Number of CMS categories imported
		 */
		private function import_cms_categories() {
			$cat_count = 0;
			$categories = $this->get_cms_categories();
			if ( is_array($categories) ) {
				$terms = array('1'); // unclassified category
				foreach ( $categories as $category ) {
					
					if ( get_category_by_slug($category['slug']) ) {
						continue; // Do not import already imported category
					}
					
					// Insert the category
					$new_category = array(
						'cat_name' 				=> $category['name'],
						'category_description'	=> $category['description'],
						'category_nicename'		=> $category['slug'],
					);
					
					// Hook before inserting the category
					$new_category = apply_filters('fgp2wc_pre_insert_category', $new_category, $category);
					
					if ( ($cat_id = wp_insert_category($new_category)) !== false ) {
						$cat_count++;
						$terms[] = $cat_id;
					}
					
					// Hook after inserting the category
					do_action('fgp2wc_post_insert_category', $cat_id, $category);
				}
				
				// Update the categories with their parent ids
				// We need to do it in a second step because the children categories
				// may have been imported before their parent
				foreach ( $categories as $category ) {
					$cat = get_category_by_slug($category['slug']);
					if ( $cat ) {
						// Parent category
						if ( !empty($category['parent']) ) {
							$parent_cat = get_category_by_slug($category['parent']);
							if ( $parent_cat ) {
								// Hook before editing the category
								$cat = apply_filters('fgp2wc_pre_edit_category', $cat, $parent_cat);
								wp_update_term($cat->term_id, 'category', array('parent' => $parent_cat->term_id));
								// Hook after editing the category
								do_action('fgp2wc_post_edit_category', $cat);
							}
						}
					}
				}
				
				// Hook after importing all the categories
				do_action('fgp2wc_post_import_categories', $categories);
				
				// Update cache
				if ( !empty($terms) ) {
					wp_update_term_count_now($terms, 'category');
					$this->clean_cache($terms);
				}
			}
			$this->display_admin_notice(sprintf(_n('%d category imported', '%d categories imported', $cat_count, 'fgp2wc'), $cat_count));
			return $cat_count;
		}
		
		/**
		 * Clean the cache
		 * 
		 */
		public function clean_cache($terms = array()) {
			delete_option("category_children");
			clean_term_cache($terms, 'category');
		}

		/**
		 * Import CMS articles
		 *
		 * @return array:
		 * 		int posts_count: Number of posts imported
		 */
		private function import_cms_articles() {
			$posts_count = 0;
			$imported_tags = array();
			$step = 1000; // to limit the results
			
			$tab_categories = $this->tab_categories(); // Get the categories list
			
			// Set the WordPress post ID to start the deletion (used when we want to remove only the new imported posts)
			$start_id = intval(get_option('fgp2wc_cms_start_id'));
			if ( $start_id == 0) {
				$start_id = $this->get_next_post_autoincrement();
				update_option('fgp2wc_cms_start_id', $start_id);
			}
			
			// Hook for doing other actions before the import
			do_action('fgp2wc_pre_import_posts');
			
			do {
				$posts = $this->get_cms_articles($step); // Get the CMS articles
				
				if ( is_array($posts) ) {
					foreach ( $posts as $post ) {
						
						// Hook for modifying the CMS post before processing
						$post = apply_filters('fgp2wc_pre_process_post', $post);
						
						// Date
						$post_date = $post['date'];
						
						// Content
						$content = $post['content'];
						
						// Medias
						if ( !$this->plugin_options['skip_media'] ) {
							// Extra featured image
							$featured_image = '';
							list($featured_image, $post) = apply_filters('fgp2wc_pre_import_media', array($featured_image, $post));
							// Import media
							$result = $this->import_media_from_content($featured_image . $content, $post_date);
							$post_media = $result['media'];
							$this->media_count += $result['media_count'];
						} else {
							// Skip media
							$post_media = array();
						}
						
						// Categories IDs
						$categories = array($post['category']);
						// Hook for modifying the post categories
						$categories = apply_filters('fgp2wc_post_categories', $categories, $post);
						$categories_ids = array();
						foreach ( $categories as $category_name ) {
							$category = sanitize_title($category_name);
							if ( array_key_exists($category, $tab_categories) ) {
								$categories_ids[] = $tab_categories[$category];
							}
						}
						if ( count($categories_ids) == 0 ) {
							$categories_ids[] = 1; // default category
						}
						
						// Process content
						$content = $this->process_content($content, $post_media);
						
						// Status
						$status = ($post['active'] == 1)? 'publish' : 'draft';
						
						// Tags
						$tags = array();
						if ( $this->plugin_options['meta_keywords_in_tags'] && !empty($post['meta_keywords']) ) {
							$tags = explode(',', $post['meta_keywords']);
							$imported_tags = array_merge($imported_tags, $tags);
						}
						
						// Insert the post
						$new_post = array(
							'post_category'		=> $categories_ids,
							'post_content'		=> $content,
							'post_date'			=> $post_date,
							'post_status'		=> $status,
							'post_title'		=> $post['meta_title'],
							'post_name'			=> $post['slug'],
							'post_type'			=> $this->post_type,
							'tags_input'		=> $tags,
							'menu_order'        => $post['position'],
						);
						
						// Hook for modifying the WordPress post just before the insert
						$new_post = apply_filters('fgp2wc_pre_insert_post', $new_post, $post);
						
						$new_post_id = wp_insert_post($new_post);
						
						if ( $new_post_id ) {
							// Add links between the post and its medias
							$this->add_post_media($new_post_id, $this->get_attachment_ids($post_media), $post_date, $this->plugin_options['first_image'] != 'as_is');
							
							// Add the CMS ID as a post meta in order to modify links after
							add_post_meta($new_post_id, '_fgp2wc_old_cms_id', $post['id_cms'], true);
							
							// Increment the CMS last imported post ID
							update_option('fgp2wc_last_prestashop_cms_id', $post['id_cms']);

							$posts_count++;
							
							// Hook for doing other actions after inserting the post
							do_action('fgp2wc_post_insert_post', $new_post_id, $post);
						}
					}
				}
			} while ( ($posts != null) && (count($posts) > 0) );
			
			// Hook for doing other actions after the import
			do_action('fgp2wc_post_import_posts');
			
			$tags_count = count(array_unique($imported_tags));
			$this->display_admin_notice(sprintf(_n('%d article imported', '%d articles imported', $posts_count, 'fgp2wc'), $posts_count));
			$this->display_admin_notice(sprintf(_n('%d tag imported', '%d tags imported', $tags_count, 'fgp2wc'), $tags_count));
			return array(
				'posts_count'	=> $posts_count,
				'tags_count'	=> $tags_count,
			);
		}
		
		/**
		 * Import product categories
		 *
		 * @return int Number of product categories imported
		 */
		private function import_product_categories() {
			$cat_count = 0;
			$terms = array();
			$taxonomy = 'product_cat';
			
			// Set the list of previously imported categories
			$this->imported_categories = $this->get_all_term_taxonomy_meta($this->product_cat_prefix);
			
			$categories = $this->get_all_product_categories();
			foreach ( $categories as $category ) {
				
				// Check if the category is already imported
				if ( array_key_exists($category['id_category'], $this->imported_categories) ) {
					continue; // Do not import already imported category
				}
				
				// Date
				$date = $category['date'];
				
				// Insert the category
				$new_category = array(
					'description'	=> $category['description'],
					'slug'			=> $category['slug'], // slug
				);
				
				// Hook before inserting the category
				$new_category = apply_filters('fgp2wc_pre_insert_category', $new_category, $category);
				
				$new_term = wp_insert_term($category['name'], $taxonomy, $new_category);
				if ( !is_wp_error($new_term) ) {
					$cat_count++;
					$terms[] = $new_term['term_id'];
					
					// Store the catogory mapping as a custom post type
					$this->add_term_taxonomy_meta($this->product_cat_prefix . $category['id_category'], $new_term['term_taxonomy_id']);
					
					// Category ordering
					if ( function_exists('wc_set_term_order') ) {
						wc_set_term_order($new_term['term_id'], $category['position'], $taxonomy);
					}
					
					// Category thumbnails
					if ( !$this->plugin_options['skip_media'] && function_exists('update_woocommerce_term_meta') ) {
						if ( ($category['id_parent'] != 0) && ($category['is_root_category'] != 1) ) { // Don't try to import root categories thumbnails
							$category_thumbnails = $this->build_image_filenames('category', $category['id_category']); // Get the potential filenames
							foreach ( $category_thumbnails as $category_thumbnail ) {
								if ( !empty($category_thumbnail) ) {
									$thumbnail_id = $this->import_media($category['name'], $category_thumbnail, $date);
									if ( !empty($thumbnail_id) ) {
										$this->media_count++;
										update_woocommerce_term_meta($new_term['term_id'], 'thumbnail_id', $thumbnail_id);
										break; // the media has been imported, we don't continue with the other potential filenames
									}
								}
							}
						}
					}
					
					// Hook after inserting the category
					do_action('fgp2wc_post_insert_category', $new_term['term_id'], $category);
				}
			}
			
			// Set the list of imported categories
			$this->imported_categories = $this->get_all_term_taxonomy_meta($this->product_cat_prefix);
			
			// Update the categories with their parent ids
			foreach ( $categories as $category ) {
				if ( array_key_exists($category['id_category'], $this->imported_categories) && array_key_exists($category['id_parent'], $this->imported_categories) ) {
					$cat_id = $this->imported_categories[$category['id_category']];
					$parent_cat_id = $this->imported_categories[$category['id_parent']];
					$cat = get_term_by('term_taxonomy_id', $cat_id, $taxonomy);
					$parent_cat = get_term_by('term_taxonomy_id', $parent_cat_id, $taxonomy);
					if ( $cat && $parent_cat ) {
						// Hook before editing the category
						$cat = apply_filters('fgp2wc_pre_edit_category', $cat, $parent_cat);
						wp_update_term($cat->term_id, $taxonomy, array('parent' => $parent_cat->term_id));
						// Hook after editing the category
						do_action('fgp2wc_post_edit_category', $cat);
					}
				}
			}
			
			// Hook after importing all the categories
			do_action('fgp2wc_post_import_categories', $categories);
			
			// Update cache
			if ( !empty($terms) ) {
				clean_term_cache($terms, $taxonomy);
			}
			$this->display_admin_notice(sprintf(_n('%d product category imported', '%d product categories imported', $cat_count, 'fgp2wc'), $cat_count));
		}
		
		/**
		 * Import products
		 *
		 * @return int Number of products imported
		 */
		private function import_products() {
			$products_count = 0;
			$step = 1000; // to limit the results
			
			if ( !$this->test_woocommerce_activation() ) {
				return 0;
			}
			
			$image_filename_key = false; // Optimization to get the right image filename
			do {
				$products = $this->get_products($step);
				foreach ( $products as $product ) {
					$product_medias = array();
					$post_media = array();
					
					// Date
					$date = $product['date'];
					
					// Product images
					if ( !$this->plugin_options['skip_media'] ) {
						
						$images = $this->get_product_images($product['id_product']);
						foreach ( $images as $image ) {
							$image_name = !empty($image['legend'])? $image['legend'] : $product['name'] . '-' . $image['id_image'];
							$image_filenames = $this->build_image_filenames('product', $image['id_image'], $product['id_product']); // Get the potential filenames
							
							// Optimization to get the right image filename
							$media_id = false;
							if ( $image_filename_key !== false ) {
								$media_id = $this->import_media($image_name, $image_filenames[$image_filename_key], $date);
								if ( $media_id !== false ) {
									$product_medias[] = $media_id;
								}
							}
							if ( $media_id === false ) {
								foreach ( $image_filenames as $key => $image_filename ) {
									if ( $key !== $image_filename_key ) {
										$media_id = $this->import_media($image_name, $image_filename, $date);
										if ( $media_id !== false ) {
											$product_medias[] = $media_id;
											$image_filename_key = $key;
											break; // the media has been imported, we don't continue with the other potential filenames
										}
									}
								}
							}
						}
						$this->media_count += count($product_medias);

						// Import content media
						$result = $this->import_media_from_content($product['description'], $date);
						$post_media = $result['media'];
						$this->media_count += $result['media_count'];
					}
					
					// Product categories
					$categories_ids = array();
					$product_categories = $this->get_product_categories($product['id_product']);
					foreach ( $product_categories as $cat ) {
						if ( array_key_exists($cat, $this->imported_categories) ) {
							$categories_ids[] = $this->imported_categories[$cat];
						}
					}
					
					// Tags
					$tags = $this->get_product_tags($product['id_product']);
					if ( $this->plugin_options['meta_keywords_in_tags'] && !empty($product['meta_keywords']) ) {
						$tags = array_merge($tags, explode(',', $product['meta_keywords']));
					}
					
					// Process content
					$content = $this->process_content($product['description'], $post_media);
					
					// Insert the post
					$new_post = array(
						'post_content'		=> $content,
						'post_date'			=> $date,
						'post_excerpt'		=> $product['description_short'],
						'post_status'		=> ($product['active'] == 1)? 'publish': 'draft',
						'post_title'		=> $product['name'],
						'post_name'			=> $product['slug'],
						'post_type'			=> 'product',
						'tax_input'			=> array(
							'product_cat'	=> $categories_ids,
							'product_tag'	=> $tags,
						),
					);
					
					$new_post_id = wp_insert_post($new_post);
					
					if ( $new_post_id ) {
						$products_count++;
						
						// Product type (simple or variable)
						$product_type = $this->product_types['simple'];
						wp_set_object_terms($new_post_id, intval($product_type), 'product_type', true);
						
						// Product galleries
						$medias_id = array();
						foreach ($product_medias as $media) {
							$medias_id[] = $media;
						}
						if ( $this->plugin_options['first_image_not_in_gallery'] ) {
							// Don't include the first image into the product gallery
							array_shift($medias_id);
						}
						$gallery = implode(',', $medias_id);
						
						// Price
						$price = $product['price'];
						if ( $this->plugin_options['price'] == 'with_tax' ) {
							$price *= $this->global_tax_rate;
						}
						
						// SKU = Stock Keeping Unit
						$sku = $product['reference'];
						if ( empty($sku) ) {
							$sku = $product['supplier_reference'];
							if ( empty($sku) ) {
								$sku = $this->get_product_supplier_reference($product['id_product']);
							}
						}
						
						// Stock
						$manage_stock = 'yes';
						$stock_status = ($product['quantity'] > 0)? 'instock': 'outofstock';
						
						// Backorders
						$backorders = $this->allow_backorders($product['out_of_stock']);
						
						// Add the meta data
						add_post_meta($new_post_id, '_visibility', 'visible', true);
						add_post_meta($new_post_id, '_stock_status', $stock_status, true);
						add_post_meta($new_post_id, '_regular_price', floatval($price), true);
						add_post_meta($new_post_id, '_price', floatval($price), true);
						add_post_meta($new_post_id, '_featured', 'no', true);
						add_post_meta($new_post_id, '_weight', floatval($product['weight']), true);
						add_post_meta($new_post_id, '_length', floatval($product['depth']), true);
						add_post_meta($new_post_id, '_width', floatval($product['width']), true);
						add_post_meta($new_post_id, '_height', floatval($product['height']), true);
						add_post_meta($new_post_id, '_sku', $sku, true);
						add_post_meta($new_post_id, '_stock', $product['quantity'], true);
						add_post_meta($new_post_id, '_manage_stock', $manage_stock, true);
						add_post_meta($new_post_id, '_backorders', $backorders, true);
						add_post_meta($new_post_id, '_product_image_gallery', $gallery, true);
						
						// Add links between the post and its medias
						$this->add_post_media($new_post_id, $product_medias, $date, true);
						$this->add_post_media($new_post_id, $this->get_attachment_ids($post_media), $date, false);
						
						// Add the PrestaShop ID as a post meta
						add_post_meta($new_post_id, '_fgp2wc_old_ps_product_id', $product['id_product'], true);
						
						// Increment the PrestaShop last imported product ID
						update_option('fgp2wc_last_prestashop_product_id', $product['id_product']);
						
						// Hook for doing other actions after inserting the post
						do_action('fgp2wc_post_insert_product', $new_post_id, $product);
					}
				}
			} while ( ($products != null) && (count($products) > 0) );
			
			$this->display_admin_notice(sprintf(_n('%d product imported', '%d products imported', $products_count, 'fgp2wc'), $products_count));
		}
		
		/**
		 * Get PrestaShop configuration
		 *
		 * @return array of keys/values
		 */
		private function get_configuration() {
			$config = array();

			$prefix = $this->plugin_options['prefix'];
			$sql = "
				SELECT name, value
				FROM ${prefix}configuration
			";
			$sql = apply_filters('fgp2wc_get_categories_sql', $sql, $prefix);

			$result = $this->prestashop_query($sql);
			foreach ( $result as $row ) {
				$config[$row['name']] = $row['value'];
			}
			return $config;
		}
		
		/**
		 * Get CMS categories
		 *
		 * @return array of Categories
		 */
		private function get_cms_categories() {
			$categories = array();
			
			if ( $this->table_exists('cms_category') ) {
				$prefix = $this->plugin_options['prefix'];
				$lang = $this->default_language;
				$sql = "
					SELECT c.id_cms_category, cl.name, cl.link_rewrite AS slug, cl.description, cp.link_rewrite AS parent
					FROM ${prefix}cms_category c
					INNER JOIN ${prefix}cms_category_lang AS cl ON cl.id_cms_category = c.id_cms_category AND cl.id_lang = '$lang'
					LEFT JOIN ${prefix}cms_category_lang AS cp ON cp.id_cms_category = c.id_parent AND cp.id_lang = '$lang'
					WHERE c.active = 1
					ORDER BY c.position
				";
				$sql = apply_filters('fgp2wc_get_cms_categories_sql', $sql, $prefix);

				$categories = $this->prestashop_query($sql);
				$categories = apply_filters('fgp2wc_get_cms_categories', $categories);
			}
			return $categories;
		}
		
		/**
		 * Get CMS articles
		 *
		 * @param int limit Number of articles max
		 * @return array of Posts
		 */
		protected function get_cms_articles($limit=1000) {
			$articles = array();
			
			$last_prestashop_cms_id = (int)get_option('fgp2wc_last_prestashop_cms_id'); // to restore the import where it left

			$prefix = $this->plugin_options['prefix'];
			$lang = $this->default_language;

			// Hooks for adding extra cols and extra joins
			$extra_cols = apply_filters('fgp2wc_get_posts_add_extra_cols', '');
			$extra_joins = apply_filters('fgp2wc_get_posts_add_extra_joins', '');

			// Index or no index
			if ( $this->column_exists('cms', 'indexation') ) {
				$indexation_field = 'a.indexation';
			} else {
				$indexation_field = ' 1 AS indexation';
			}

			if ( version_compare($this->prestashop_version, '1.4', '<') ) {
				// PrestaShop 1.3
				$sql = "
					SELECT a.id_cms, l.meta_title, l.meta_description, l.meta_keywords, l.content, l.link_rewrite AS slug, '' AS category, 0 AS position, 1 AS active, $indexation_field, '' AS date
					$extra_cols
					FROM ${prefix}cms a
					INNER JOIN ${prefix}cms_lang AS l ON l.id_cms = a.id_cms AND l.id_lang = '$lang'
					WHERE a.id_cms > '$last_prestashop_cms_id'
					$extra_joins
					ORDER BY a.id_cms
					LIMIT $limit
				";
			} else {
				// PrestaShop 1.4+
				$sql = "
					SELECT a.id_cms, l.meta_title, l.meta_description, l.meta_keywords, l.content, l.link_rewrite AS slug, cl.link_rewrite AS category, a.position, a.active, $indexation_field, c.date_add AS date
					$extra_cols
					FROM ${prefix}cms a
					INNER JOIN ${prefix}cms_lang AS l ON l.id_cms = a.id_cms AND l.id_lang = '$lang'
					LEFT JOIN ${prefix}cms_category AS c ON c.id_cms_category = a.id_cms_category
					LEFT JOIN ${prefix}cms_category_lang AS cl ON cl.id_cms_category = a.id_cms_category AND cl.id_lang = '$lang'
					WHERE a.id_cms > '$last_prestashop_cms_id'
					$extra_joins
					ORDER BY a.id_cms
					LIMIT $limit
				";
			}
			$sql = apply_filters('fgp2wc_get_posts_sql', $sql, $prefix, $extra_cols, $extra_joins, $last_prestashop_cms_id, $limit);
			$articles = $this->prestashop_query($sql);
			
			return $articles;
		}
		
		/**
		 * Get product categories
		 *
		 * @return array of Categories
		 */
		private function get_all_product_categories() {
			$categories = array();

			$prefix = $this->plugin_options['prefix'];
			$lang = $this->default_language;
			$root_category_field = version_compare($this->prestashop_version, '1.5', '<')? '0 AS is_root_category' : 'c.is_root_category';
			if ( version_compare($this->prestashop_version, '1.4', '<') ) {
				// PrestaShop 1.3
				$position_field = '0 AS position';
				$order = 'c.id_category';
			} else {
				$position_field = 'c.position';
				$order = 'c.position';
			}
			$sql = "
				SELECT c.id_category, c.date_add AS date, $position_field, c.id_parent, $root_category_field, cl.name, cl.description, cl.link_rewrite AS slug
				FROM ${prefix}category c
				INNER JOIN ${prefix}category_lang AS cl ON cl.id_category = c.id_category AND cl.id_lang = '$lang'
				WHERE c.active = 1
				ORDER BY $order
			";
			$sql = apply_filters('fgp2wc_get_categories_sql', $sql, $prefix);
			$categories = $this->prestashop_query($sql);
			
			$categories = apply_filters('fgp2wc_get_categories', $categories);
			
			return $categories;
		}
		
		/**
		 * Return an array with all the categories sorted by name
		 *
		 * @return array categoryname => id
		 */
		public function tab_categories() {
			$tab_categories = array();
			$categories = get_categories(array('hide_empty' => '0'));
			if ( is_array($categories) ) {
				foreach ( $categories as $category ) {
					$tab_categories[$category->slug] = $category->term_id;
				}
			}
			return $tab_categories;
		}
		
		/**
		 * Get the products
		 * 
		 * @param int limit Number of products max
		 * @return array of products
		 */
		private function get_products($limit=1000) {
			$products = array();

			$last_prestashop_product_id = (int)get_option('fgp2wc_last_prestashop_product_id'); // to restore the import where it left
			
			$prefix = $this->plugin_options['prefix'];
			$lang = $this->default_language;
			if ( version_compare($this->prestashop_version, '1.5', '<') ) {
				if ( version_compare($this->prestashop_version, '1.4', '<') ) {
					// PrestaShop 1.3
					$width_field = '0 AS width';
					$height_field = '0 AS height';
					$depth_field = '0 AS depth';
				} else {
					// PrestaShop 1.4
					$width_field = 'p.width';
					$height_field = 'p.height';
					$depth_field = 'p.depth';
				}
				$sql = "
					SELECT p.id_product, p.id_supplier, p.id_manufacturer, p.on_sale, p.quantity, p.price, p.reference, p.supplier_reference, $width_field, $height_field, $depth_field, p.weight, p.out_of_stock, p.active, p.date_add AS date, pl.name, pl.link_rewrite AS slug, pl.description, pl.description_short, pl.meta_description, pl.meta_keywords, pl.meta_title
					FROM ${prefix}product p
					INNER JOIN ${prefix}product_lang AS pl ON pl.id_product = p.id_product AND pl.id_lang = '$lang'
					WHERE p.id_product > '$last_prestashop_product_id'
					ORDER BY p.id_product
					LIMIT $limit
				";
			} else {
				// PrestaShop 1.5+
				$sql = "
					SELECT DISTINCT p.id_product, p.id_supplier, p.id_manufacturer, p.on_sale, s.quantity, p.price, p.reference, p.supplier_reference, p.width, p.height, p.depth, p.weight, s.out_of_stock, p.active, p.date_add AS date, pl.name, pl.link_rewrite AS slug, pl.description, pl.description_short, pl.meta_description, pl.meta_keywords, pl.meta_title
					FROM ${prefix}product p
					INNER JOIN ${prefix}product_lang AS pl ON pl.id_product = p.id_product AND pl.id_lang = '$lang' AND pl.id_shop = p.id_shop_default
					LEFT JOIN ${prefix}stock_available AS s ON s.id_product = p.id_product AND s.id_product_attribute = 0
					WHERE p.id_product > '$last_prestashop_product_id'
					ORDER BY p.id_product
					LIMIT $limit
				";
			}
			$products = $this->prestashop_query($sql);
			
			return $products;
		}
		
		/**
		 * Get the product images
		 *
		 * @param int $product_id Product ID
		 * @return array of images
		 */
		private function get_product_images($product_id) {
			$images = array();

			$prefix = $this->plugin_options['prefix'];
			$lang = $this->default_language;
			$sql = "
				SELECT i.id_image, i.position, i.cover, il.legend
				FROM ${prefix}image i
				LEFT JOIN ${prefix}image_lang il ON il.id_image = i.id_image AND il.id_lang = '$lang'
				WHERE i.id_product = '$product_id'
				ORDER BY i.cover DESC, i.position
			";
			$images = $this->prestashop_query($sql);
			
			return $images;
		}
		
		/**
		 * Get the categories from a product
		 *
		 * @param int $product_id PrestaShop product ID
		 * @return array of categories IDs
		 */
		private function get_product_categories($product_id) {
			$categories = array();

			$prefix = $this->plugin_options['prefix'];
			$sql = "
				SELECT cp.id_category
				FROM ${prefix}category_product cp
				WHERE cp.id_product = $product_id
			";
			$result = $this->prestashop_query($sql);
			foreach ( $result as $row ) {
				$categories[] = $row['id_category'];
			}
			return $categories;
		}
		
		/**
		 * Get the tags from a product
		 *
		 * @param int $product_id PrestaShop product ID
		 * @return array of tags
		 */
		private function get_product_tags($product_id) {
			$tags = array();

			$prefix = $this->plugin_options['prefix'];
			$lang = $this->default_language;
			$sql = "
				SELECT t.name
				FROM ${prefix}tag t
				INNER JOIN ${prefix}product_tag pt ON pt.id_tag = t.id_tag
				WHERE pt.id_product = $product_id
				AND t.id_lang = '$lang'
			";
			$result = $this->prestashop_query($sql);
			foreach ( $result as $row ) {
				$tags[] = $row['name'];
			}
			
			return $tags;
		}
		
		/**
		 * Get the product supplier reference (PrestaShop 1.5+)
		 *
		 * @param int $product_id PrestaShop product ID
		 * @return string Supplier reference
		 */
		private function get_product_supplier_reference($product_id) {
			$supplier_reference = '';
			
			if ( version_compare($this->prestashop_version, '1.5', '>=') ) {
				// PrestaShop 1.5+
				$prefix = $this->plugin_options['prefix'];
				$sql = "
					SELECT ps.product_supplier_reference
					FROM ${prefix}product_supplier ps
					WHERE ps.id_product = '$product_id'
					LIMIT 1
				";
				$supplier_references = $this->prestashop_query($sql);
				if ( isset($supplier_references[0]['product_supplier_reference']) ) {
					$supplier_reference = $supplier_references[0]['product_supplier_reference'];
				}
			}
			return $supplier_reference;
		}
		
		/**
		 * Get the WooCommerce default tax rate
		 *
		 * @return float Tax rate
		 */
		private function get_default_tax_rate() {
			global $wpdb;
			$tax = 1;
			
			try {
				$sql = "
					SELECT tax_rate
					FROM {$wpdb->prefix}woocommerce_tax_rates
					WHERE tax_rate_priority = 1
					LIMIT 1
				";
				$tax_rate = $wpdb->get_var($sql);
				if ( !empty($tax_rate) ) {
					$tax = 1 + ($tax_rate / 100);
				}
			} catch ( PDOException $e ) {
				$this->plugin->display_admin_error(__('Error:', 'fgp2wc') . $e->getMessage());
			}
			return $tax;
		}
		
		/**
		 * Determine potential filenames for the image
		 *
		 * @param string $type Image type (category, product)
		 * @param int $id_image Image ID
		 * @param int $id_product Product ID
		 * @return string Image file name
		 */
		private function build_image_filenames($type, $id_image, $id_product='') {
			$filenames = array();
			switch ( $type ) {
				case 'category':
					$filenames[] = untrailingslashit($this->plugin_options['url']) . '/img/c/' . $id_image . '.jpg';
					$filenames[] = untrailingslashit($this->plugin_options['url']) . '/img/c/' . $id_image . '-category.jpg';
					break;
				
				case 'product':
					$subdirs = str_split(strval($id_image));
					$subdir = implode('/', $subdirs);
					$filenames[] = untrailingslashit($this->plugin_options['url']) . '/img/p/' . $subdir . '/' . $id_image . '.jpg';
					$filenames[] = untrailingslashit($this->plugin_options['url']) . '/img/p/' . $id_product . '-' . $id_image . '.jpg';
					break;
			}
			return $filenames;
		}
		
		/**
		 * Import post medias from content
		 *
		 * @param string $content post content
		 * @param date $post_date Post date (for storing media)
		 * @param array $options Options
		 * @return array:
		 * 		array media: Medias imported
		 * 		int media_count:   Medias count
		 */
		public function import_media_from_content($content, $post_date, $options=array()) {
			$media = array();
			$media_count = 0;
			$matches = array();
			$alt_matches = array();
			
			if ( preg_match_all('#<(img|a)(.*?)(src|href)="(.*?)"(.*?)>#', $content, $matches, PREG_SET_ORDER) > 0 ) {
				if ( is_array($matches) ) {
					foreach ($matches as $match ) {
						$filename = $match[4];
						$other_attributes = $match[2] . $match[5];
						// Image Alt
						$image_alt = '';
						if (preg_match('#alt="(.*?)"#', $other_attributes, $alt_matches) ) {
							$image_alt = wp_strip_all_tags(stripslashes($alt_matches[1]), true);
						}
						$attach_id = $this->import_media($image_alt, $filename, $post_date, $options);
						if ( $attach_id !== false ) {
							$media_count++;
							$attachment = get_post($attach_id);
							if ( !is_null($attachment) ) {
								$media[$filename] = array(
									'id'	=> $attach_id,
									'name'	=> $attachment->post_name,
								);
							}
						}
					}
				}
			}
			return array(
				'media'			=> $media,
				'media_count'	=> $media_count
			);
		}
		
		/**
		 * Import a media
		 *
		 * @param string $name Image name
		 * @param string $filename Image URL
		 * @param date $date Date
		 * @param array $options Options
		 * @return int attachment ID or false
		 */
		public function import_media($name, $filename, $date, $options=array()) {
			if ( $date == '0000-00-00 00:00:00' ) {
				$date = date('Y-m-d H:i:s');
			}
			$import_external = ($this->plugin_options['import_external'] == 1) || (isset($options['force_external']) && $options['force_external'] );
			
			$filename = str_replace("%20", " ", $filename); // for filenames with spaces
			
			$filetype = wp_check_filetype($filename);
			if ( empty($filetype['type']) || ($filetype['type'] == 'text/html') ) { // Unrecognized file type
				return false;
			}

			// Upload the file from the PrestaShop web site to WordPress upload dir
			if ( preg_match('/^http/', $filename) ) {
				if ( $import_external || // External file 
					preg_match('#^' . $this->plugin_options['url'] . '#', $filename) // Local file
				) {
					$old_filename = $filename;
				} else {
					return false;
				}
			} elseif ( preg_match('#^/img#', $filename) ) {
				$old_filename = untrailingslashit($this->plugin_options['url']) . $filename;
			} else {
				$old_filename = untrailingslashit($this->plugin_options['url']) . '/img/' . $filename;
			}
			$old_filename = str_replace(" ", "%20", $old_filename); // for filenames with spaces
			$img_dir = strftime('%Y/%m', strtotime($date));
			$uploads = wp_upload_dir($img_dir);
			$new_upload_dir = $uploads['path'];

			$new_filename = $filename;
			if ( $this->plugin_options['import_duplicates'] == 1 ) {
				// Images with duplicate names
				$new_filename = preg_replace('#.*img/#', '', $new_filename);
				$new_filename = str_replace('http://', '', $new_filename);
				$new_filename = str_replace('/', '_', $new_filename);
			}

			$basename = basename($new_filename);
			$new_full_filename = $new_upload_dir . '/' . $basename;

//			print "Copy \"$old_filename\" => $new_full_filename<br />";
			if ( ! @$this->remote_copy($old_filename, $new_full_filename) ) {
//				$error = error_get_last();
//				$error_message = $error['message'];
//				$this->display_admin_error("Can't copy $old_filename to $new_full_filename : $error_message");
				return false;
			}
			
			$post_name = !empty($name)? $name : preg_replace('/\.[^.]+$/', '', $basename);
			
			// If the attachment does not exist yet, insert it in the database
			$attach_id = 0;
			$attachment = $this->get_attachment_from_name($post_name);
			if ( $attachment ) {
				$attached_file = basename(get_attached_file($attachment->ID));
				if ( $attached_file == $basename ) { // Check if the filename is the same (in case of the legend is not unique)
					$attach_id = $attachment->ID;
				}
			}
			if ( $attach_id == 0 ) {
				$attachment_data = array(
					'guid'				=> $uploads['url'] . '/' . $basename, 
					'post_date'			=> $date,
					'post_mime_type'	=> $filetype['type'],
					'post_name'			=> $post_name,
					'post_title'		=> $post_name,
					'post_status'		=> 'inherit',
					'post_content'		=> '',
				);
				$attach_id = wp_insert_attachment($attachment_data, $new_full_filename);
			}
			
			if ( !empty($attach_id) ) {
				if ( preg_match('/image/', $filetype['type']) ) { // Images
					// you must first include the image.php file
					// for the function wp_generate_attachment_metadata() to work
					require_once(ABSPATH . 'wp-admin/includes/image.php');
					$attach_data = wp_generate_attachment_metadata( $attach_id, $new_full_filename );
					wp_update_attachment_metadata($attach_id, $attach_data);

					// Image Alt
					if ( !empty($name) ) {
						$image_alt = wp_strip_all_tags(stripslashes($name), true);
						update_post_meta($attach_id, '_wp_attachment_image_alt', addslashes($image_alt)); // update_meta expects slashed
					}
				}
				return $attach_id;
			} else {
				return false;
			}
		}
		
		/**
		 * Check if the attachment exists in the database
		 *
		 * @param string $name
		 * @return object Post
		 */
		private function get_attachment_from_name($name) {
			$name = preg_replace('/\.[^.]+$/', '', basename($name));
			$r = array(
				'name'			=> $name,
				'post_type'		=> 'attachment',
				'numberposts'	=> 1,
			);
			$posts_array = get_posts($r);
			if ( is_array($posts_array) && (count($posts_array) > 0) ) {
				return $posts_array[0];
			}
			else {
				return false;
			}
		}
		
		/**
		 * Process the post content
		 *
		 * @param string $content Post content
		 * @param array $post_media Post medias
		 * @return string Processed post content
		 */
		public function process_content($content, $post_media) {
			
			if ( !empty($content) ) {
				$content = str_replace(array("\r", "\n"), array('', ' '), $content);
				
				// Replace page breaks
				$content = preg_replace("#<hr([^>]*?)class=\"system-pagebreak\"(.*?)/>#", "<!--nextpage-->", $content);
				
				// Replace media URLs with the new URLs
				$content = $this->process_content_media_links($content, $post_media);
			}

			return $content;
		}

		/**
		 * Replace media URLs with the new URLs
		 *
		 * @param string $content Post content
		 * @param array $post_media Post medias
		 * @return string Processed post content
		 */
		private function process_content_media_links($content, $post_media) {
			$matches = array();
			$matches_caption = array();
			
			if ( is_array($post_media) ) {
				
				// Get the attachments attributes
				$attachments_found = false;
				foreach ( $post_media as $old_filename => &$media_var ) {
					$post_media_name = $media_var['name'];
					$attachment = $this->get_attachment_from_name($post_media_name);
					if ( $attachment ) {
						$media_var['attachment_id'] = $attachment->ID;
						$media_var['old_filename_without_spaces'] = str_replace(" ", "%20", $old_filename); // for filenames with spaces
						if ( preg_match('/image/', $attachment->post_mime_type) ) {
							// Image
							$image_src = wp_get_attachment_image_src($attachment->ID, 'full');
							$media_var['new_url'] = $image_src[0];
							$media_var['width'] = $image_src[1];
							$media_var['height'] = $image_src[2];
						} else {
							// Other media
							$media_var['new_url'] = wp_get_attachment_url($attachment->ID);
						}
						$attachments_found = true;
					}
				}
				if ( $attachments_found ) {
				
					// Remove the links from the content
					$this->post_link_count = 0;
					$this->post_link = array();
					$content = preg_replace_callback('#<(a) (.*?)(href)=(.*?)</a>#i', array($this, 'remove_links'), $content);
					$content = preg_replace_callback('#<(img) (.*?)(src)=(.*?)>#i', array($this, 'remove_links'), $content);
					
					// Process the stored medias links
					$first_image_removed = false;
					foreach ($this->post_link as &$link) {
						
						// Remove the first image from the content
						if ( ($this->plugin_options['first_image'] == 'as_featured') && !$first_image_removed && preg_match('#^<img#', $link['old_link']) ) {
							$link['new_link'] = '';
							$first_image_removed = true;
							continue;
						}
						$new_link = $link['old_link'];
						$alignment = '';
						if ( preg_match('/(align="|float: )(left|right)/', $new_link, $matches) ) {
							$alignment = 'align' . $matches[2];
						}
						if ( preg_match_all('#(src|href)="(.*?)"#i', $new_link, $matches, PREG_SET_ORDER) ) {
							$caption = '';
							foreach ( $matches as $match ) {
								$old_filename = str_replace('%20', ' ', $match[2]); // For filenames with %20
								$link_type = ($match[1] == 'src')? 'img': 'a';
								if ( array_key_exists($old_filename, $post_media) ) {
									$media = $post_media[$old_filename];
									if ( array_key_exists('new_url', $media) ) {
										if ( (strpos($new_link, $old_filename) > 0) || (strpos($new_link, $media['old_filename_without_spaces']) > 0) ) {
											$new_link = preg_replace('#('.$old_filename.'|'.$media['old_filename_without_spaces'].')#', $media['new_url'], $new_link, 1);
											
											if ( $link_type == 'img' ) { // images only
												// Define the width and the height of the image if it isn't defined yet
												if ((strpos($new_link, 'width=') === false) && (strpos($new_link, 'height=') === false)) {
													$width_assertion = isset($media['width'])? ' width="' . $media['width'] . '"' : '';
													$height_assertion = isset($media['height'])? ' height="' . $media['height'] . '"' : '';
												} else {
													$width_assertion = '';
													$height_assertion = '';
												}
												
												// Caption shortcode
												if ( preg_match('/class=".*caption.*?"/', $link['old_link']) ) {
													if ( preg_match('/title="(.*?)"/', $link['old_link'], $matches_caption) ) {
														$caption_value = str_replace('%', '%%', $matches_caption[1]);
														$align_value = ($alignment != '')? $alignment : 'alignnone';
														$caption = '[caption id="attachment_' . $media['attachment_id'] . '" align="' . $align_value . '"' . $width_assertion . ']%s' . $caption_value . '[/caption]';
													}
												}
												
												$align_class = ($alignment != '')? $alignment . ' ' : '';
												$new_link = preg_replace('#<img(.*?)( class="(.*?)")?(.*) />#', "<img$1 class=\"$3 " . $align_class . 'size-full wp-image-' . $media['attachment_id'] . "\"$4" . $width_assertion . $height_assertion . ' />', $new_link);
											}
										}
									}
								}
							}
							
							// Add the caption
							if ( $caption != '' ) {
								$new_link = sprintf($caption, $new_link);
							}
						}
						$link['new_link'] = $new_link;
					}
					
					// Reinsert the converted medias links
					$content = preg_replace_callback('#__fg_link_(\d+)__#', array($this, 'restore_links'), $content);
				}
			}
			return $content;
		}
		
		/**
		 * Remove all the links from the content and replace them with a specific tag
		 * 
		 * @param array $matches Result of the preg_match
		 * @return string Replacement
		 */
		private function remove_links($matches) {
			$this->post_link[] = array('old_link' => $matches[0]);
			return '__fg_link_' . $this->post_link_count++ . '__';
		}
		
		/**
		 * Restore the links in the content and replace them with the new calculated link
		 * 
		 * @param array $matches Result of the preg_match
		 * @return string Replacement
		 */
		private function restore_links($matches) {
			$link = $this->post_link[$matches[1]];
			$new_link = array_key_exists('new_link', $link)? $link['new_link'] : $link['old_link'];
			return $new_link;
		}
		
		/**
		 * Add a link between a media and a post (parent id + thumbnail)
		 *
		 * @param int $post_id Post ID
		 * @param array $post_media Post medias
		 * @param array $date Date
		 * @param boolean $set_featured_image Set the featured image?
		 */
		public function add_post_media($post_id, $post_media, $date, $set_featured_image=true) {
			$thumbnail_is_set = false;
			if ( is_array($post_media) ) {
				foreach ( $post_media as $media ) {
					$attachment = get_post($media);
					if ( !empty($attachment) && ($attachment->post_type == 'attachment') ) {
						$attachment->post_parent = $post_id; // Attach the post to the media
						$attachment->post_date = $date ;// Define the media's date
						wp_update_post($attachment);

						// Set the featured image. If not defined, it is the first image of the content.
						if ( $set_featured_image && !$thumbnail_is_set ) {
							set_post_thumbnail($post_id, $attachment->ID);
							$thumbnail_is_set = true;
						}
					}
				}
			}
		}

		/**
		 * Get the IDs of the medias
		 *
		 * @param array $post_media Post medias
		 * @return array Array of attachment IDs
		 */
		public function get_attachment_ids($post_media) {
			$attachments_ids = array();
			if ( is_array($post_media) ) {
				foreach ( $post_media as $media ) {
					$attachment = $this->get_attachment_from_name($media['name']);
					if ( !empty($attachment) ) {
						$attachments_ids[] = $attachment->ID;
					}
				}
			}
			return $attachments_ids;
		}
		
		/**
		 * Copy a remote file
		 * in replacement of the copy function
		 * 
		 * @param string $url URL of the source file
		 * @param string $path destination file
		 * @return boolean
		 */
		public function remote_copy($url, $path) {
			
			// Don't copy the file if already copied
			if ( !$this->plugin_options['force_media_import'] && file_exists($path) && (filesize($path) > 0) ) {
				return true;
			}
			
			$response = wp_remote_get($url, array(
				'timeout'		=> $this->plugin_options['timeout'],
				'redirection'	=> 0,
			)); // Uses WordPress HTTP API
			
			if ( is_wp_error($response) ) {
				trigger_error($response->get_error_message(), E_USER_WARNING);
				return false;
			} elseif ( $response['response']['code'] != 200 ) {
				trigger_error($response['response']['message'], E_USER_WARNING);
				return false;
			} else {
				file_put_contents($path, wp_remote_retrieve_body($response));
				return true;
			}
		}
		
		/**
		 * Allow the backorders or not
		 * 
		 * @param int $out_of_stock_value Out of stock value 0|1|2
		 * @return string yes|no
		 */
		private function allow_backorders($out_of_stock_value) {
			switch ( $out_of_stock_value ) {
				case 0: $backorders = 'no'; break;
				case 1: $backorders = 'yes'; break;
				default: $backorders = $this->default_backorders;
			}
			return $backorders;
		}
		
		/**
		 * Recount the items for a taxonomy
		 * 
		 * @return boolean
		 */
		private function terms_tax_count($taxonomy) {
			$terms = get_terms(array($taxonomy));
			// Get the term taxonomies
			$terms_taxonomies = array();
			foreach ( $terms as $term ) {
				$terms_taxonomies[] = $term->term_taxonomy_id;
			}
			if ( !empty($terms_taxonomies) ) {
				return wp_update_term_count_now($terms_taxonomies, $taxonomy);
			} else {
				return true;
			}
		}
		
		/**
		 * Recount the items for each category and tag
		 * 
		 * @return boolean
		 */
		private function terms_count() {
			$result = $this->terms_tax_count('category');
			$result |= $this->terms_tax_count('post_tag');
		}
		
		/**
		 * Get the next post autoincrement
		 * 
		 * @return int post ID
		 */
		private function get_next_post_autoincrement() {
			global $wpdb;
			
			$sql = "SHOW TABLE STATUS LIKE '$wpdb->posts'";
			$row = $wpdb->get_row($sql);
			if ( $row ) {
				return $row->Auto_increment;
			} else {
				return 0;
			}
		}
		
		/**
		 * Display the number of imported media
		 * 
		 */
		public function display_media_count() {
			$this->display_admin_notice(sprintf(_n('%d media imported', '%d medias imported', $this->media_count, 'fgp2wc'), $this->media_count));
		}

		/**
		 * Test if a column exists
		 *
		 * @param string $table Table name
		 * @param string $column Column name
		 * @return bool
		 */
		public function column_exists($table, $column) {
			global $prestashop_db;
			
			try {
				$prefix = $this->plugin_options['prefix'];
				
				$sql = "SHOW COLUMNS FROM ${prefix}${table} LIKE '$column'";
				$query = $prestashop_db->query($sql, PDO::FETCH_ASSOC);
				$result = $query->fetch();
				return !empty($result);
			} catch ( PDOException $e ) {}
			return false;
		}
		
		/**
		 * Test if a table exists
		 *
		 * @param string $table Table name
		 * @return bool
		 */
		public function table_exists($table) {
			global $prestashop_db;
			
			try {
				$prefix = $this->plugin_options['prefix'];
				
				$sql = "SHOW TABLES LIKE '${prefix}${table}'";
				$query = $prestashop_db->query($sql, PDO::FETCH_ASSOC);
				$result = $query->fetch();
				return !empty($result);
			} catch ( PDOException $e ) {}
			return false;
		}
		
		/**
		 * Store a term taxonomy meta as a custom post type
		 * 
		 * @param string $key Meta key
		 * @param mixed $value Meta value
		 * @return int New post ID
		 */
		protected function add_term_taxonomy_meta($key, $value) {
			$new_post = array(
				'post_title'		=> $key,
				'post_type'			=> 'fg_term_meta',
				'post_content'		=> $value,
				'post_status'		=> 'publish',
			);
			$new_post_id = wp_insert_post($new_post);
			return $new_post_id;
		}
		
		/**
		 * Get the term taxonomy meta
		 * Used to map the PrestaShop categories with the WordPress categories
		 * 
		 * @param string $key Meta key
		 * @return mixed Meta value
		 */
		protected function get_term_taxonomy_meta($key) {
			$post = array(
				'name'			=> $key,
				'post_type'		=> 'fg_term_meta',
				'numberposts'	=> 1,
			);
			$posts = get_posts($post);
			if ( is_array($posts) && (count($posts) > 0) ) {
				return $posts[0]->content;
			}
			return false;
		}
		
		/**
		 * Get all the term taxonomies meta that begin with a specific prefix
		 * 
		 * @param string $prefix Prefix
		 * @return array List of mapped categories: PrestaShop_Cat_ID => WP_CAT_ID
		 */
		protected function get_all_term_taxonomy_meta($prefix) {
			$metas = array();
			$matches = array();
			
			$post = array(
				'post_type'			=> 'fg_term_meta',
				'posts_per_page'	=> -1,
				'orderby'          => 'post_name',
				'order'            => 'ASC',
			);
			$posts = get_posts($post);
			if ( is_array($posts) ) {
				foreach ( $posts as $post ) {
					if ( preg_match("/^$prefix(.*)/", $post->post_title, $matches) ) {
						$key = $matches[1];
						$metas[$key] = $post->post_content;
					}
				}
			}
			return $metas;
		}
		
	}
}
