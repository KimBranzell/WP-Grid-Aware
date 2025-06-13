<?php
/**
 * Plugin Name: WP Grid Aware
 * Description: Optimizes your website based on the carbon intensity of the electricity grid.
 * Version: 1.0.0
 * Author: Kim Branzell
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

if (!session_id() && !headers_sent()) {
    session_start();
}

// Define constants
define('GRID_AWARE_VERSION', '1.0.0');
define('GRID_AWARE_PATH', plugin_dir_path(__FILE__));
define('GRID_AWARE_URL', plugin_dir_url(__FILE__));

require_once GRID_AWARE_PATH . 'includes/class-grid-aware-base.php';
require_once GRID_AWARE_PATH . 'includes/class-grid-aware-analytics.php';
require_once GRID_AWARE_PATH . 'includes/class-grid-aware-api.php';
require_once GRID_AWARE_PATH . 'includes/class-grid-aware-admin.php';
require_once GRID_AWARE_PATH . 'includes/class-grid-aware-server.php';

// Autoloader function
function grid_aware_autoloader($class_name) {
    // Only handle our own classes
    if (strpos($class_name, 'Grid_Aware_') !== 0) {
        return;
    }    // Skip already loaded core classes
    if (in_array($class_name, array(
        'Grid_Aware_Base',
        'Grid_Aware_API',
        'Grid_Aware_Admin',
        'Grid_Aware_Server',
        'Grid_Aware_Analytics'
    ))) {
        return;
    }

    // Convert class name to filename format
    $class_file = strtolower(str_replace('_', '-', substr($class_name, 11)));

    // Look in modules folder
    $module_path = GRID_AWARE_PATH . 'modules/class-' . $class_file . '.php';
    if (file_exists($module_path)) {
        require_once $module_path;
    }
}
spl_autoload_register('grid_aware_autoloader');

function grid_aware_activate() {
    // Default options setup
    add_option('grid_aware_optimize_images', 'yes');
    add_option('grid_aware_lazy_load', 'yes');
    add_option('grid_aware_defer_non_essential', 'yes');
    add_option('grid_aware_essential_scripts', 'jquery');
    add_option('grid_aware_essential_styles', '');
    add_option('grid_aware_enable_super_eco', 'yes');
    add_option('grid_aware_text_only_mode', 'no');
    add_option('grid_aware_tiny_placeholders', 'yes');
    add_option('grid_aware_tiny_placeholders_mode', 'super-eco-only');
    add_option('grid_aware_optimize_video', 'yes');

    // Create analytics tables
    if (class_exists('Grid_Aware_Analytics')) {
        $analytics = Grid_Aware_Analytics::get_instance();
        $analytics->create_tables();
    }

    // Flush rewrite rules
    flush_rewrite_rules();
}

// Activation hook
register_activation_hook(__FILE__, 'grid_aware_activate');

// Deactivation hook
function grid_aware_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'grid_aware_deactivate');

// Initialize the plugin
function grid_aware_init() {
    try {

        // Initialize the API (always needed)
        $api = Grid_Aware_API::get_instance();

        // Initialize analytics (always needed for tracking)
        $analytics = Grid_Aware_Analytics::get_instance();

        // Initialize the admin features if in admin area
        if (is_admin()) {
            $admin = Grid_Aware_Admin::get_instance();
        }

        // Always initialize the front-end features
        $server = Grid_Aware_Server::get_instance();;
    } catch (Exception $e) {
        // Show admin notice if appropriate
        if (is_admin() && current_user_can('manage_options')) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p><strong>Grid Aware Error:</strong> ' .
                     esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
}
add_action('plugins_loaded', 'grid_aware_init');

/**
 * Enqueue frontend scripts and styles
 */
function grid_aware_enqueue_frontend_assets() {
    // Only enqueue on the frontend
    if (is_admin()) {
        return;
    }

    // Enqueue frontend CSS
    wp_enqueue_style(
        'grid-aware-frontend',
        GRID_AWARE_URL . 'assets/css/frontend.css',
        array(),
        GRID_AWARE_VERSION
    );

    // Enqueue frontend JS
    wp_enqueue_script(
        'grid-aware-frontend',
        GRID_AWARE_URL . 'assets/js/frontend.js',
        array(),
        GRID_AWARE_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'grid_aware_enqueue_frontend_assets');

/**
 * Add admin bar menu to switch modes (for testing)
 */
function grid_aware_admin_bar_menu($wp_admin_bar) {
    // Only show for administrators
    if (!current_user_can('manage_options')) {
        return;
    }

    // Get current grid data
    $server = Grid_Aware_Server::get_instance();
    $mode = $server->get_current_mode();
    $intensity = $server->get_current_intensity();

    // Get current settings
    $text_only = get_option('grid_aware_text_only_mode', 'no');

    // Main node
    $wp_admin_bar->add_node(array(
        'id'    => 'grid-aware',
        'title' => 'Grid: ' . esc_html(ucfirst($mode)) . ' (' . esc_html($intensity) . ' gCO2/kWh)',
        'href'  => admin_url('admin.php?page=grid-aware-settings'),
    ));

    // Mode nodes
    $wp_admin_bar->add_node(array(
        'id'     => 'grid-aware-standard',
        'title'  => 'Test Standard Mode',
        'parent' => 'grid-aware',
        'href'   => add_query_arg('grid_preview', 'standard'),
    ));

    $wp_admin_bar->add_node(array(
        'id'     => 'grid-aware-eco',
        'title'  => 'Test Eco Mode',
        'parent' => 'grid-aware',
        'href'   => add_query_arg('grid_preview', 'eco'),
    ));

    $wp_admin_bar->add_node(array(
        'id'     => 'grid-aware-super-eco',
        'title'  => 'Test Super-Eco Mode',
        'parent' => 'grid-aware',
        'href'   => add_query_arg('grid_preview', 'super-eco'),
    ));

    // Text-only toggle
    $wp_admin_bar->add_node(array(
        'id'     => 'grid-aware-text-only',
        'title'  => 'Text-Only Mode: ' . ($text_only === 'yes' ? 'On' : 'Off'),
        'parent' => 'grid-aware',
        'href'   => add_query_arg('grid_text_only', $text_only === 'yes' ? '0' : '1'),
    ));

    // Return to auto mode
    $wp_admin_bar->add_node(array(
        'id'     => 'grid-aware-auto',
        'title'  => 'Return to Auto Mode',
        'parent' => 'grid-aware',
        'href'   => remove_query_arg('grid_preview'),
    ));
}
add_action('admin_bar_menu', 'grid_aware_admin_bar_menu', 999);

/**
 * Handle test mode parameter
 */
function grid_aware_handle_test_mode() {
    // Only for admin users
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_GET['grid_preview']) && in_array($_GET['grid_preview'], array('standard', 'eco', 'super-eco'))) {
        // Set cookie for preview mode
        setcookie('grid_aware_test_mode', $_GET['grid_preview'], time() + 3600, '/');

        // Add a session flag to indicate this was forced
        $_SESSION['grid_aware_forced'] = true;

        // Redirect to remove query string if not in admin
        if (!is_admin()) {
            wp_redirect(remove_query_arg('grid_preview'));
            exit;
        }
    }

    // Handle text-only mode toggle
    if (isset($_GET['grid_text_only']) && current_user_can('manage_options')) {
        $value = $_GET['grid_text_only'] === '0' ? 'no' : 'yes';
        update_option('grid_aware_text_only_mode', $value);

        // Redirect to remove query string if not in admin
        if (!is_admin()) {
            wp_redirect(remove_query_arg('grid_text_only'));
            exit;
        }
    }
}
add_action('admin_init', 'grid_aware_handle_test_mode');