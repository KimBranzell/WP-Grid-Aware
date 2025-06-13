<?php
/**
 * Grid-Aware Admin Class
 * Handles all the admin functionality of the plugin
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class Grid_Aware_Admin {
    // Singleton instance
    private static $instance = null;

    /**
     * Get the singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename(GRID_AWARE_PATH . 'wp-grid-aware.php'),
            array($this, 'add_settings_link')
        );

        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Add debug notice for DDEV
        if ($this->is_ddev()) {
            add_action('admin_notices', array($this, 'show_ddev_debug_notice'));
        }

        // Add admin notice if mode is forced
        add_action('admin_notices', array($this, 'maybe_show_forced_mode_notice'));

        // Add admin bar menu item
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 999);

        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));        // Add AJAX handler for tiny image testing
        add_action('wp_ajax_grid_aware_test_tiny_image', array($this, 'ajax_test_tiny_image'));

        // Add AJAX handler for dashboard widget updates
        add_action('wp_ajax_grid_aware_dashboard_update', array($this, 'ajax_dashboard_update'));

        // Add AJAX handler for analytics data refresh
        add_action('wp_ajax_grid_aware_analytics_data', array($this, 'ajax_analytics_data'));

        // Add AJAX handler for analytics export
        add_action('wp_ajax_grid_aware_export_analytics', array($this, 'ajax_export_analytics'));
    }

    /**
     * Add admin menu
     * This creates the menu item in the WordPress dashboard
     */
    public function add_admin_menu() {
        // This line adds the top-level menu item
        add_menu_page(
            'Grid-Aware Settings',         // Page title
            'Grid-Aware',                  // Menu title
            'manage_options',              // Capability required
            'grid-aware-settings',         // Menu slug
            array($this, 'render_settings_page'), // Callback function
            'dashicons-admin-generic',     // Icon (you can change this)
            100                            // Position
        );

        // Add analytics submenu
        add_submenu_page(
            'grid-aware-settings',       // Parent slug
            'Carbon Analytics',          // Page title
            'Analytics',                 // Menu title
            'manage_options',            // Capability
            'grid-aware-analytics',      // Menu slug
            array($this, 'render_analytics_page') // Callback
        );
    }

    /**
     * Add admin notice if mode is being forced
     */
    public function maybe_show_forced_mode_notice() {
        // Only show to admins and only if a mode is forced
        if (!current_user_can('manage_options')) {
            return;
        }

        $force_mode = get_option('grid_aware_force_mode', 'auto');
        if ($force_mode !== 'auto') {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>Grid-Aware Mode Forced:</strong> The Grid-Aware plugin is currently forced to
                    <strong><?php echo esc_html(ucfirst($force_mode)); ?> Mode</strong> for testing purposes.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=grid-aware-settings#debug-settings')); ?>">
                        Click here to change this setting
                    </a>.
                </p>
            </div>
            <?php
        }

        // Also check for temporary preview mode via query param
        if (isset($_GET['grid_preview'])) {
            $preview_mode = sanitize_key($_GET['grid_preview']);
            if (in_array($preview_mode, array('standard', 'eco', 'super-eco'))) {
                ?>
                <div class="notice notice-info is-dismissible">
                    <p>
                        <strong>Grid-Aware Preview Active:</strong> You are currently previewing
                        <strong><?php echo esc_html(ucfirst($preview_mode)); ?> Mode</strong>.
                        <a href="<?php echo esc_url(remove_query_arg('grid_preview')); ?>">
                            Click here to exit preview mode
                        </a>.
                    </p>
                </div>
                <?php
            }
        }
    }

    /**
     * Add admin bar menu item for quick access to preview modes
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options') || is_admin()) {
            return;
        }

        // Get the current mode and intensity
        $server = Grid_Aware_Server::get_instance();
        $mode = $server->get_current_mode();
        $intensity = $server->get_current_intensity();
        $forced = isset($_SESSION['grid_aware_forced']) && $_SESSION['grid_aware_forced'];

        // Colors for different modes
        $colors = array(
            'standard' => '#0f834d',  // Green
            'eco' => '#ff9e01',       // Orange
            'super-eco' => '#b32d2e', // Red
            'unknown' => '#666'       // Gray
        );

        // Add main node
        $wp_admin_bar->add_node(array(
            'id'    => 'grid-aware-indicator',
            'title' => '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' .
                      esc_attr($colors[$mode]) . ';margin-right:5px;"></span> Grid-Aware: ' .
                      esc_html(ucfirst($mode)) .
                      ($forced ? ' (Forced)' : ''),
            'href'  => admin_url('admin.php?page=grid-aware-settings')
        ));

        // Add submenu items for quick preview
        $wp_admin_bar->add_node(array(
            'id'     => 'grid-aware-preview-standard',
            'parent' => 'grid-aware-indicator',
            'title'  => 'Preview Standard Mode',
            'href'   => add_query_arg('grid_preview', 'standard')
        ));

        $wp_admin_bar->add_node(array(
            'id'     => 'grid-aware-preview-eco',
            'parent' => 'grid-aware-indicator',
            'title'  => 'Preview Eco Mode',
            'href'   => add_query_arg('grid_preview', 'eco')
        ));

        $wp_admin_bar->add_node(array(
            'id'     => 'grid-aware-preview-super-eco',
            'parent' => 'grid-aware-indicator',
            'title'  => 'Preview Super-Eco Mode',
            'href'   => add_query_arg('grid_preview', 'super-eco')
        ));

        if ($forced) {
            $wp_admin_bar->add_node(array(
                'id'     => 'grid-aware-exit-preview',
                'parent' => 'grid-aware-indicator',
                'title'  => 'Exit Preview Mode',
                'href'   => remove_query_arg('grid_preview')
            ));
        }

        // Show intensity if available
        if ($intensity !== null) {
            $wp_admin_bar->add_node(array(
                'id'     => 'grid-aware-intensity',
                'parent' => 'grid-aware-indicator',
                'title'  => 'Current Intensity: ' . esc_html($intensity) . ' gCO2/kWh',
                'href'   => admin_url('admin.php?page=grid-aware-settings')
            ));
        }
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'grid_aware_dashboard_widget',
            '<span class="dashicons dashicons-admin-site-alt3" style="color: #0f834d;"></span> Carbon Footprint Monitor',
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        // Get current grid data
        if (class_exists('Grid_Aware_Server')) {
            $server = Grid_Aware_Server::get_instance();
            $mode = $server->get_current_mode();
            $intensity = $server->get_current_intensity();
        } else {
            $mode = 'unknown';
            $intensity = 0;
        }

        // Get analytics summary if available
        $analytics_summary = array();
        if (class_exists('Grid_Aware_Analytics')) {
            $analytics = Grid_Aware_Analytics::get_instance();

            // Check if the generate_report method exists before calling it
            if (method_exists($analytics, 'generate_report')) {
                $report = $analytics->generate_report('24hours', 'array');
                $analytics_summary = $report['summary'];
            }
        }

        ?>
        <div class="grid-aware-dashboard-widget">
            <div class="current-status">
                <div class="status-item">
                    <div class="status-label">Current Mode</div>
                    <div class="status-value mode-<?php echo esc_attr($mode); ?>">
                        <?php echo esc_html(ucfirst($mode)); ?>
                    </div>
                </div>

                <?php if ($intensity > 0): ?>
                <div class="status-item">
                    <div class="status-label">Carbon Intensity</div>
                    <div class="status-value intensity-<?php echo $intensity > 400 ? 'high' : ($intensity > 200 ? 'medium' : 'low'); ?>">
                        <?php echo esc_html(number_format($intensity, 1)); ?> g/kWh
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($analytics_summary) && $analytics_summary['total_page_views'] > 0): ?>
            <div class="today-summary">
                <h4>Last 24 Hours</h4>
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-number"><?php echo number_format($analytics_summary['total_page_views']); ?></div>
                        <div class="summary-label">Page Views</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-number"><?php echo number_format($analytics_summary['total_carbon_g'], 3); ?>g</div>
                        <div class="summary-label">CO₂ Footprint</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-number"><?php echo number_format($analytics_summary['total_savings_g'], 3); ?>g</div>
                        <div class="summary-label">CO₂ Saved</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-number"><?php echo $analytics_summary['savings_percentage']; ?>%</div>
                        <div class="summary-label">Reduction</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="widget-actions">
                <a href="<?php echo admin_url('admin.php?page=grid-aware-analytics'); ?>" class="button button-primary">
                    View Full Analytics
                </a>
                <a href="<?php echo admin_url('admin.php?page=grid-aware-settings'); ?>" class="button">
                    Settings
                </a>
            </div>
        </div>

        <style>
        .grid-aware-dashboard-widget {
            font-size: 13px;
        }

        .current-status {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f1f1f1;
        }

        .status-item {
            flex: 1;
        }

        .status-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .status-value {
            font-size: 16px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 4px;
            text-align: center;
        }

        .mode-standard {
            background: rgba(102, 102, 102, 0.1);
            color: #666;
        }

        .mode-eco {
            background: rgba(255, 158, 1, 0.1);
            color: #ff9e01;
        }

        .mode-super-eco {
            background: rgba(179, 45, 46, 0.1);
            color: #b32d2e;
        }

        .intensity-low {
            background: rgba(15, 131, 77, 0.1);
            color: #0f834d;
        }

        .intensity-medium {
            background: rgba(255, 158, 1, 0.1);
            color: #ff9e01;
        }

        .intensity-high {
            background: rgba(179, 45, 46, 0.1);
            color: #b32d2e;
        }

        .today-summary h4 {
            margin: 0 0 10px 0;
            font-size: 12px;
            color: #333;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        .summary-item {
            text-align: center;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
        }

        .summary-number {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }

        .summary-label {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        .widget-actions {
            display: flex;
            gap: 10px;
        }

        .widget-actions .button {
            flex: 1;
            justify-content: center;
            text-align: center;
        }
        </style>
        <?php
    }

    /**
     * AJAX handler for testing tiny image generation
     */
    public function ajax_test_tiny_image() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'grid_aware_test_tiny')) {
            wp_send_json_error('Invalid nonce');
        }

        // Get image URL
        $image_url = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
        if (empty($image_url)) {
            wp_send_json_error('No image URL provided');
        }

        // Get size of original image
        $original_size = 0;
        $response = wp_remote_head($image_url);
        if (!is_wp_error($response)) {
            $original_size = isset($response['headers']['content-length']) ? intval($response['headers']['content-length']) : 0;
        }

        // Try to get the image optimizer
        $tiny_url = $this->get_tiny_data_uri($image_url);

        // Calculate approximate size of tiny version (data URI length minus header)
        $tiny_size = strlen($tiny_url) - 30;

        // Calculate size reduction
        $size_reduction = 0;
        if ($original_size > 0 && $tiny_size > 0) {
            $size_reduction = round(100 - (($tiny_size / $original_size) * 100), 2);
        }

        wp_send_json_success(array(
            'tiny_url' => $tiny_url,
            'original_size' => $original_size,
            'tiny_size' => $tiny_size,
            'size_reduction' => $size_reduction
        ));
    }    /**
     * AJAX handler for dashboard widget updates
     */
    public function ajax_dashboard_update() {
        check_ajax_referer('grid_aware_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Get updated widget content
        ob_start();
        $this->render_dashboard_widget();
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX handler for analytics data refresh
     */
    public function ajax_analytics_data() {
        check_ajax_referer('grid_aware_analytics', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        if (!class_exists('Grid_Aware_Analytics')) {
            wp_send_json_error('Analytics module not available');
        }

        $analytics = Grid_Aware_Analytics::get_instance();
        $period = sanitize_text_field($_POST['period'] ?? '7days');
        $report = $analytics->generate_report($period, 'array');

        // Get formatted timeline data for charts
        $timeline_data = $this->format_timeline_data_for_charts($report['timeline'], $period);

        // Include both summary data and timeline data for charts
        $response_data = array(
            'summary' => $report['summary'],
            'timeline' => $timeline_data,
            'insights' => $report['insights'],
            'recommendations' => $report['recommendations']
        );

        wp_send_json_success($response_data);
    }

    /**
     * AJAX handler for analytics export
     */
    public function ajax_export_analytics() {
        check_ajax_referer('grid_aware_analytics', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        if (!class_exists('Grid_Aware_Analytics')) {
            wp_send_json_error('Analytics module not available');
        }

        $analytics = Grid_Aware_Analytics::get_instance();
        $period = sanitize_text_field($_POST['period'] ?? '30days');
        $format = sanitize_text_field($_POST['format'] ?? 'csv');

        $report = $analytics->generate_report($period, $format);

        $filename = "carbon-footprint-report-{$period}-" . date('Y-m-d');

        if ($format === 'csv') {
            // Create temporary file for download
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['path'] . '/' . $filename . '.csv';
            file_put_contents($file_path, $report);

            $download_url = $upload_dir['url'] . '/' . $filename . '.csv';

            wp_send_json_success(array(
                'download_url' => $download_url,
                'filename' => $filename . '.csv'
            ));
        } elseif ($format === 'json') {
            // Create temporary file for download
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['path'] . '/' . $filename . '.json';
            file_put_contents($file_path, $report);

            $download_url = $upload_dir['url'] . '/' . $filename . '.json';

            wp_send_json_success(array(
                'download_url' => $download_url,
                'filename' => $filename . '.json'
            ));
        }

        wp_send_json_error('Invalid format');
    }

    /**
     * Format timeline data for Chart.js (used by AJAX handler)
     */
    private function format_timeline_data_for_charts($timeline_data, $period) {
        if (empty($timeline_data)) {
            return array();
        }

        $labels = array();
        $carbon_intensity = array();
        $carbon_footprint = array();
        $carbon_saved = array();
        $page_views = array();

        foreach ($timeline_data as $data_point) {
            // Format timestamp for chart labels
            $timestamp = $data_point['timestamp'];
            if ($period === '24hours') {
                $labels[] = date('H:i', strtotime($timestamp));
            } else if ($period === '7days') {
                $labels[] = date('M j', strtotime($timestamp));
            } else {
                $labels[] = date('M j', strtotime($timestamp));
            }

            $carbon_intensity[] = floatval($data_point['avg_carbon_intensity']);
            $carbon_footprint[] = floatval($data_point['estimated_carbon_g']);
            $carbon_saved[] = floatval($data_point['savings_carbon_g']);
            $page_views[] = intval($data_point['page_views']);
        }

        return array(
            'labels' => $labels,
            'datasets' => array(
                'carbon_intensity' => $carbon_intensity,
                'carbon_footprint' => $carbon_footprint,
                'carbon_saved' => $carbon_saved,
                'page_views' => $page_views
            )
        );
    }

    /**
     * Generate a tiny data URI for an image
     */
    private function get_tiny_data_uri($src) {
        // Default gray placeholder
        $default = $this->get_default_placeholder();

        // Skip external URLs and SVGs
        if (strpos($src, '.svg') !== false) {
            return $default;
        }

        // Try to use the image optimizer module if available
        if (class_exists('Grid_Aware_Image_Optimizer')) {
            try {
                $optimizer = Grid_Aware_Image_Optimizer::get_instance();
                if (method_exists($optimizer, 'get_tiny_data_uri')) {
                    return $optimizer->get_tiny_data_uri($src);
                }
            } catch (Exception $e) {
                // Fall through to direct implementation
            }
        }

        // Direct implementation for admin testing
        try {
            // Get image content
            $img_data = $this->get_remote_file_contents($src);

            if ($img_data) {
                // Create image from string
                $image = @imagecreatefromstring($img_data);

                if ($image) {
                    // Determine image dimensions
                    $width = imagesx($image);
                    $height = imagesy($image);
                    $ratio = $width / $height;

                    $new_width = 30;
                    $new_height = round($new_width / $ratio);

                    $tiny = imagecreatetruecolor($new_width, $new_height);

                    // Resample
                    imagecopyresampled($tiny, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

                    // Output as data URI
                    ob_start();
                    imagejpeg($tiny, null, 50);
                    $base64 = base64_encode(ob_get_clean());

                    // Free memory
                    imagedestroy($image);
                    imagedestroy($tiny);

                    return 'data:image/jpeg;base64,' . $base64;
                }
            }
        } catch (Exception $e) {
            // Fallback to default
        }

        return $default;
    }

    /**
     * Helper function to get file contents from local or remote URL
     */
    private function get_remote_file_contents($url) {
        // Try WP HTTP API first
        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($url);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                return wp_remote_retrieve_body($response);
            }
        }

        // Fallback to file_get_contents
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create(array(
                'http' => array(
                    'timeout' => 5,
                    'user_agent' => 'WordPress/Grid-Aware-Plugin'
                )
            ));
            $contents = @file_get_contents($url, false, $context);
            if ($contents !== false) {
                return $contents;
            }
        }

        return false;
    }

    /**
     * Get default placeholder
     */
    private function get_default_placeholder() {
        return 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 10 10\'%3E%3Crect width=\'10\' height=\'10\' fill=\'%23eee\'/%3E%3C/svg%3E';
    }

    /**
     * Show DDEV debug notice when running in DDEV
     */
    public function show_ddev_debug_notice() {
        if (!$this->is_ddev()) {
            return;
        }

        if (!isset($_GET['grid_aware_ddev_debug'])) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>Grid-Aware:</strong> Running in DDEV environment. <a href="' . add_query_arg('grid_aware_ddev_debug', '1') . '">Show DDEV debug info</a></p>';
            echo '</div>';
            return;
        }

        echo '<div class="notice notice-info">';
        echo '<h3>Grid-Aware DDEV Debug Information</h3>';

        // Show site URL
        echo '<p><strong>Site URL:</strong> ' . site_url() . '</p>';
        echo '<p><strong>Home URL:</strong> ' . home_url() . '</p>';

        // Show upload paths
        $upload_dir = wp_upload_dir();
        echo '<p><strong>Upload Base URL:</strong> ' . $upload_dir['baseurl'] . '</p>';
        echo '<p><strong>Upload Base Dir:</strong> ' . $upload_dir['basedir'] . '</p>';

        // Show a test image URL and path
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'post_mime_type' => 'image',
        ));

        if (!empty($attachments)) {
            $test_img = wp_get_attachment_url($attachments[0]->ID);
            $test_img_path = get_attached_file($attachments[0]->ID);

            echo '<p><strong>Test Image URL:</strong> ' . $test_img . '</p>';
            echo '<p><strong>Test Image Path:</strong> ' . $test_img_path . '</p>';
            echo '<p><strong>File exists?</strong> ' . (file_exists($test_img_path) ? 'Yes' : 'No') . '</p>';

            // Try to generate a tiny version
            echo '<p><strong>Test Tiny Generation:</strong> ';
            $tiny_data = $this->get_tiny_data_uri($test_img);
            if ($tiny_data && $tiny_data !== $this->get_default_placeholder()) {
                echo 'Success!</p>';
                echo '<p><strong>Original Image:</strong><br><img src="' . esc_url($test_img) . '" style="max-width:200px; max-height:200px;"></p>';
                echo '<p><strong>Tiny Version:</strong><br><img src="' . esc_attr($tiny_data) . '" style="max-width:200px; max-height:200px;"></p>';
            } else {
                echo 'Failed!</p>';
                echo '<p>Check PHP error log for details.</p>';
            }
        }

        echo '</div>';
    }

    /**
     * Check if running in a DDEV environment
     */
    private function is_ddev() {
        return (defined('IS_DDEV_PROJECT') && IS_DDEV_PROJECT) ||
               (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '.ddev.site') !== false) ||
               (isset($_ENV['DDEV_PROJECT']) && !empty($_ENV['DDEV_PROJECT']));
    }

    /**
     * Add settings link to plugin listing
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=grid-aware-settings">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        // Load on our settings page and analytics page
        if ($hook != 'toplevel_page_grid-aware-settings' && $hook != 'grid-aware_page_grid-aware-analytics') {
            return;
        }

        // Add admin CSS
        wp_enqueue_style(
            'grid-aware-admin',
            GRID_AWARE_URL . 'assets/css/admin.css',
            array(),
            GRID_AWARE_VERSION
        );

        // Add admin JS
        wp_enqueue_script(
            'grid-aware-admin',
            GRID_AWARE_URL . 'assets/js/admin.js',
            array('jquery'),
            GRID_AWARE_VERSION,
            true
        );

        // Load Chart.js library for analytics page
        if ($hook == 'grid-aware_page_grid-aware-analytics') {
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js',
                array(),
                '4.4.0',
                true
            );

            // Get timeline data for charting
            $timeline_data = $this->get_timeline_data_for_charts();

            // Pass data to JavaScript
            wp_localize_script('grid-aware-admin', 'gridAwareChartData', array(
                'timeline' => $timeline_data,
                'nonce' => wp_create_nonce('grid_aware_analytics')
            ));
        }
    }

    /**
     * Get timeline data formatted for Chart.js
     */
    private function get_timeline_data_for_charts() {

                // Debug: Check what's happening
        error_log('Grid_Aware_Admin: Checking for Grid_Aware_Analytics class');

        if (!class_exists('Grid_Aware_Analytics')) {
            error_log('Grid_Aware_Admin: Grid_Aware_Analytics class does not exist');
            return array();
        }

        error_log('Grid_Aware_Admin: Grid_Aware_Analytics class exists, getting instance');

        try {
            $analytics = Grid_Aware_Analytics::get_instance();
            error_log('Grid_Aware_Admin: Got analytics instance: ' . get_class($analytics));
        } catch (Exception $e) {
            error_log('Grid_Aware_Admin: Error getting analytics instance: ' . $e->getMessage());
            return array();
        }

        $current_period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '7days';

        if (!method_exists($analytics, 'generate_report')) {
            error_log('Grid_Aware_Admin: generate_report method does not exist on ' . get_class($analytics));
            return array();
        }

        error_log('Grid_Aware_Admin: generate_report method exists, calling it');

        try {
            $report = $analytics->generate_report($current_period, 'array');
            error_log('Grid_Aware_Admin: Successfully generated report');
        } catch (Exception $e) {
            error_log('Grid_Aware_Admin: Error generating report: ' . $e->getMessage());
            return array();
        }

        if (empty($report['timeline'])) {
            error_log('Grid_Aware_Admin: Report timeline is empty');
            return array();
        }

        if (!class_exists('Grid_Aware_Analytics')) {
            return array();
        }

        $analytics = Grid_Aware_Analytics::get_instance();
        $current_period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '7days';

        if (!method_exists($analytics, 'generate_report')) {
            return array();
        }

        $report = $analytics->generate_report($current_period, 'array');

        if (empty($report['timeline'])) {
            return array();
        }

        $labels = array();
        $carbon_intensity = array();
        $carbon_footprint = array();
        $carbon_saved = array();
        $page_views = array();

        foreach ($report['timeline'] as $data_point) {
            // Format timestamp for chart labels
            $timestamp = $data_point['timestamp'];
            if ($current_period === '24hours') {
                $labels[] = date('H:i', strtotime($timestamp));
            } else if ($current_period === '7days') {
                $labels[] = date('M j', strtotime($timestamp));
            } else {
                $labels[] = date('M j', strtotime($timestamp));
            }

            $carbon_intensity[] = floatval($data_point['avg_carbon_intensity']);
            $carbon_footprint[] = floatval($data_point['estimated_carbon_g']);
            $carbon_saved[] = floatval($data_point['savings_carbon_g']);
            $page_views[] = intval($data_point['page_views']);
        }

        return array(
            'labels' => $labels,
            'datasets' => array(
                'carbon_intensity' => $carbon_intensity,
                'carbon_footprint' => $carbon_footprint,
                'carbon_saved' => $carbon_saved,
                'page_views' => $page_views
            )
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // API Settings
        register_setting('grid_aware_api_options', 'grid_aware_api_key');
        register_setting('grid_aware_api_options', 'grid_aware_zone');
        register_setting('grid_aware_debug_options', 'grid_aware_force_mode');

        // Basic Optimization Settings
        register_setting('grid_aware_options', 'grid_aware_optimize_images');
        register_setting('grid_aware_options', 'grid_aware_lazy_load');
        register_setting('grid_aware_options', 'grid_aware_defer_non_essential');
        register_setting('grid_aware_options', 'grid_aware_essential_scripts');
        register_setting('grid_aware_options', 'grid_aware_essential_styles');

        // Advanced Settings
        register_setting('grid_aware_advanced_options', 'grid_aware_enable_super_eco');
        register_setting('grid_aware_advanced_options', 'grid_aware_text_only_mode');
        register_setting('grid_aware_advanced_options', 'grid_aware_tiny_placeholders');
        register_setting('grid_aware_advanced_options', 'grid_aware_optimize_video');
        register_setting('grid_aware_advanced_options', 'grid_aware_tiny_placeholders_mode');

        // Add settings sections
        add_settings_section(
            'grid_aware_api_section',
            'API Settings',
            array($this, 'render_api_section'),
            'grid_aware_api_options'
        );

        add_settings_section(
            'grid_aware_basic_section',
            'Basic Optimization Settings',
            array($this, 'render_basic_section'),
            'grid_aware_options'
        );

        add_settings_section(
            'grid_aware_advanced_section',
            'Advanced Optimization Settings',
            array($this, 'render_advanced_section'),
            'grid_aware_advanced_options'
        );

        // API fields
        add_settings_field(
            'grid_aware_api_key',
            'Electricity Map API Key',
            array($this, 'render_api_key_field'),
            'grid_aware_api_options',
            'grid_aware_api_section'
        );

        add_settings_field(
            'grid_aware_zone',
            'Default Zone',
            array($this, 'render_zone_field'),
            'grid_aware_api_options',
            'grid_aware_api_section'
        );

        // Basic optimization fields
        add_settings_field(
            'grid_aware_optimize_images',
            'Optimize Images',
            array($this, 'render_optimize_images_field'),
            'grid_aware_options',
            'grid_aware_basic_section'
        );

        add_settings_field(
            'grid_aware_lazy_load',
            'Lazy Load Images',
            array($this, 'render_lazy_load_field'),
            'grid_aware_options',
            'grid_aware_basic_section'
        );

        add_settings_field(
            'grid_aware_defer_non_essential',
            'Defer Non-Essential Resources',
            array($this, 'render_defer_field'),
            'grid_aware_options',
            'grid_aware_basic_section'
        );

        add_settings_field(
            'grid_aware_essential_scripts',
            'Essential Scripts',
            array($this, 'render_essential_scripts_field'),
            'grid_aware_options',
            'grid_aware_basic_section'
        );

        add_settings_field(
            'grid_aware_essential_styles',
            'Essential Styles',
            array($this, 'render_essential_styles_field'),
            'grid_aware_options',
            'grid_aware_basic_section'
        );

        // Advanced fields
        add_settings_field(
            'grid_aware_enable_super_eco',
            'Enable Super-Eco Mode',
            array($this, 'render_super_eco_field'),
            'grid_aware_advanced_options',
            'grid_aware_advanced_section'
        );

        add_settings_field(
            'grid_aware_text_only_mode',
            'Text-Only Mode in Super-Eco',
            array($this, 'render_text_only_field'),
            'grid_aware_advanced_options',
            'grid_aware_advanced_section'
        );

        add_settings_field(
            'grid_aware_tiny_placeholders',
            'Use Tiny Image Placeholders',
            array($this, 'render_tiny_placeholders_field'),
            'grid_aware_advanced_options',
            'grid_aware_advanced_section'
        );

        add_settings_field(
            'grid_aware_tiny_placeholders_mode',
            'Tiny Placeholders Mode',
            array($this, 'render_tiny_placeholders_mode_field'),
            'grid_aware_advanced_options',
            'grid_aware_advanced_section'
        );

        add_settings_field(
            'grid_aware_optimize_video',
            'Optimize Video Embeds',
            array($this, 'render_optimize_video_field'),
            'grid_aware_advanced_options',
            'grid_aware_advanced_section'
        );

        // Add debug settings section
        add_settings_section(
            'grid_aware_debug_section',
            'Debug & Preview Settings',
            array($this, 'render_debug_section'),
            'grid_aware_debug_options'
        );

        // Add force mode field
        add_settings_field(
            'grid_aware_force_mode',
            'Force Mode',
            array($this, 'render_force_mode_field'),
            'grid_aware_debug_options',
            'grid_aware_debug_section'
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Get current grid intensity if available
        $intensity = null;
        $mode = 'unknown';

        // Try to get data from API
        $api = Grid_Aware_API::get_instance();
        $grid_data = $api->get_cached_data();
        if ($grid_data && isset($grid_data['carbonIntensity'])) {
            $intensity = $grid_data['carbonIntensity'];

            // Determine mode based on intensity
            if ($intensity >= 350 && get_option('grid_aware_enable_super_eco', 'yes') === 'yes') {
                $mode = 'super-eco';
            } else if ($intensity >= 200) {
                $mode = 'eco';
            } else {
                $mode = 'standard';
            }
        }

        // Check for forced mode
        $forced = isset($_SESSION['grid_aware_forced']) && $_SESSION['grid_aware_forced'];

        ?>
        <div class="wrap grid-aware-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if ($intensity !== null): ?>
            <div class="notice <?php echo $forced ? 'notice-warning' : 'notice-info'; ?>">
                <p>
                    <strong>Current Grid Intensity:</strong> <?php echo esc_html($intensity); ?> gCO2/kWh
                    <br>
                    <strong>Current Mode:</strong> <?php echo esc_html(ucfirst($mode)); ?>
                    <?php if ($forced): ?>
                    <br><em>Note: Mode is currently being forced for preview/testing</em>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="#api-settings" class="nav-tab nav-tab-active">API Settings</a>
                <a href="#basic-settings" class="nav-tab">Basic Settings</a>
                <a href="#advanced-settings" class="nav-tab">Advanced Settings</a>
                <a href="#analytics" class="nav-tab">Carbon Analytics</a>
                <a href="#debug-settings" class="nav-tab">Debug & Preview</a>
            </h2>

            <div id="api-settings" class="tab-content">
                <form action="options.php" method="post">
                    <?php
                    settings_fields('grid_aware_api_options');
                    do_settings_sections('grid_aware_api_options');
                    submit_button('Save API Settings');
                    ?>
                </form>
            </div>

            <div id="basic-settings" class="tab-content" style="display: none;">
                <form action="options.php" method="post">
                    <?php
                    settings_fields('grid_aware_options');
                    do_settings_sections('grid_aware_options');
                    submit_button('Save Basic Settings');
                    ?>
                </form>
            </div>

            <div id="advanced-settings" class="tab-content" style="display: none;">
                <form action="options.php" method="post">
                    <?php
                    settings_fields('grid_aware_advanced_options');
                    do_settings_sections('grid_aware_advanced_options');
                    submit_button('Save Advanced Settings');
                    ?>
                </form>
            </div>

            <div id="analytics" class="tab-content" style="display: none;">
                <?php $this->render_analytics_dashboard(); ?>
            </div>

            <div id="debug-settings" class="tab-content" style="display: none;">
                <form action="options.php" method="post">
                    <?php
                    settings_fields('grid_aware_debug_options');
                    do_settings_sections('grid_aware_debug_options');
                    submit_button('Save Debug Settings');
                    ?>
                </form>

                <h3>Quick Preview Links</h3>
                <p>Use these links to temporarily preview different modes:</p>
                <p>
                    <a href="<?php echo esc_url(add_query_arg('grid_preview', 'standard')); ?>" class="button">Preview Standard Mode</a>
                    <a href="<?php echo esc_url(add_query_arg('grid_preview', 'eco')); ?>" class="button">Preview Eco Mode</a>
                    <a href="<?php echo esc_url(add_query_arg('grid_preview', 'super-eco')); ?>" class="button">Preview Super-Eco Mode</a>
                    <a href="<?php echo esc_url(remove_query_arg('grid_preview')); ?>" class="button button-primary">Return to Auto Mode</a>
                </p>

                <h3>Tiny Image Test Tool</h3>
                <p>Test the tiny image generation functionality:</p>
                <div class="tiny-image-test-tool">
                    <input type="text" id="test-image-url" placeholder="Enter an image URL from your site" class="regular-text">
                    <button type="button" id="test-tiny-button" class="button">Generate Tiny Preview</button>
                    <div id="tiny-image-result" style="margin-top: 15px;"></div>
                </div>

                <script>
                    jQuery(document).ready(function($) {
                        $("#test-tiny-button").on("click", function() {
                            var imageUrl = $("#test-image-url").val();
                            if (!imageUrl) return;

                            $("#tiny-image-result").html("<p>Loading...</p>");

                            $.ajax({
                                url: ajaxurl,
                                type: "POST",
                                data: {
                                    action: "grid_aware_test_tiny_image",
                                    image_url: imageUrl,
                                    nonce: "<?php echo wp_create_nonce('grid_aware_test_tiny'); ?>"
                                },
                                success: function(response) {
                                    if (response.success) {
                                        var html = "<div style='display:flex; gap:20px; margin-top:10px;'>";
                                        html += "<div><h4>Original</h4>";
                                        html += "<img src=\"" + imageUrl + "\" style=\"max-width: 300px; max-height: 300px;\"><br>";
                                        html += "<p>Size: " + (response.data.original_size / 1024).toFixed(2) + " KB</p></div>";

                                        html += "<div><h4>Tiny version</h4>";
                                        html += "<img src=\"" + response.data.tiny_url + "\" style=\"max-width: 300px; max-height: 300px;\"><br>";
                                        html += "<p>Size: " + (response.data.tiny_size / 1024).toFixed(2) + " KB</p></div>";
                                        html += "</div>";

                                        html += "<p><strong>Size reduction: " + response.data.size_reduction + "%</strong></p>";
                                        $("#tiny-image-result").html(html);
                                    } else {
                                        $("#tiny-image-result").html("<p>Error: " + response.data + "</p>");
                                    }
                                },
                                error: function() {
                                    $("#tiny-image-result").html("<p>Ajax error occurred</p>");
                                }
                            });
                        });
                    });
                </script>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Simple tab navigation
                $('.nav-tab').on('click', function(e) {
                    e.preventDefault();

                    // Update active tab
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');

                    // Show active content
                    $('.tab-content').hide();
                    $($(this).attr('href')).show();

                    // Update URL hash
                    window.location.hash = $(this).attr('href');
                });

                // Check if hash exists and activate corresponding tab
                if (window.location.hash) {
                    var hash = window.location.hash;
                    $('.nav-tab[href="' + hash + '"]').trigger('click');
                }
            });
        </script>
        <?php
    }

    /**
     * Render sections
     */
    public function render_api_section() {
        echo '<p>Enter your Electricity Map API key and default zone settings.</p>';
    }

    public function render_basic_section() {
        echo '<p>Configure basic optimizations that will be applied in Eco and Super-Eco modes.</p>';
    }

    public function render_advanced_section() {
        echo '<p>Configure advanced optimizations for Super-Eco mode during high carbon intensity periods.</p>';
    }

    /**
     * Render fields
     */
    public function render_api_key_field() {
        $api_key = get_option('grid_aware_api_key', '');
        echo '<input type="text" name="grid_aware_api_key" value="' . esc_attr($api_key) . '" class="regular-text">';
        echo '<p class="description">Get your API key from <a href="https://www.electricitymap.org/api" target="_blank">Electricity Map</a></p>';
    }

    public function render_zone_field() {
        $zone = get_option('grid_aware_zone', 'SE');
        echo '<input type="text" name="grid_aware_zone" value="' . esc_attr($zone) . '" class="regular-text">';
        echo '<p class="description">Default zone code (e.g., SE for Sweden)</p>';
    }

    public function render_optimize_images_field() {
        $optimize_images = get_option('grid_aware_optimize_images', 'yes');
        echo '<select name="grid_aware_optimize_images">';
        echo '<option value="yes" ' . selected($optimize_images, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($optimize_images, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Enable server-side image optimizations</p>';
    }

    public function render_lazy_load_field() {
        $lazy_load = get_option('grid_aware_lazy_load', 'yes');
        echo '<select name="grid_aware_lazy_load">';
        echo '<option value="yes" ' . selected($lazy_load, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($lazy_load, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Add loading="lazy" attribute to images</p>';
    }

    public function render_defer_field() {
        $defer = get_option('grid_aware_defer_non_essential', 'yes');
        echo '<select name="grid_aware_defer_non_essential">';
        echo '<option value="yes" ' . selected($defer, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($defer, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Defer loading of non-essential JavaScript and CSS</p>';
    }

    public function render_essential_scripts_field() {
        $scripts = get_option('grid_aware_essential_scripts', 'jquery');
        echo '<input type="text" name="grid_aware_essential_scripts" value="' . esc_attr($scripts) . '" class="regular-text">';
        echo '<p class="description">Comma-separated list of essential script handles that should not be deferred</p>';
    }

    public function render_essential_styles_field() {
        $styles = get_option('grid_aware_essential_styles', '');
        echo '<input type="text" name="grid_aware_essential_styles" value="' . esc_attr($styles) . '" class="regular-text">';
        echo '<p class="description">Comma-separated list of essential style handles that should not be deferred</p>';
    }

    public function render_super_eco_field() {
        $super_eco = get_option('grid_aware_enable_super_eco', 'yes');
        echo '<select name="grid_aware_enable_super_eco">';
        echo '<option value="yes" ' . selected($super_eco, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($super_eco, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Enable super-eco mode during very high carbon intensity periods</p>';
    }

    public function render_text_only_field() {
        $text_only = get_option('grid_aware_text_only_mode', 'no');
        echo '<select name="grid_aware_text_only_mode">';
        echo '<option value="yes" ' . selected($text_only, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($text_only, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Replace images with their alt text in super-eco mode to maximize energy savings</p>';
    }

    public function render_tiny_placeholders_field() {
        $tiny_placeholders = get_option('grid_aware_tiny_placeholders', 'yes');
        echo '<select name="grid_aware_tiny_placeholders">';
        echo '<option value="yes" ' . selected($tiny_placeholders, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($tiny_placeholders, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Use tiny placeholder images to reduce initial page weight</p>';
    }

    public function render_tiny_placeholders_mode_field() {
        $mode = get_option('grid_aware_tiny_placeholders_mode', 'super-eco-only');
        echo '<select name="grid_aware_tiny_placeholders_mode">';
        echo '<option value="super-eco-only" ' . selected($mode, 'super-eco-only', false) . '>Super-Eco Mode Only</option>';
        echo '<option value="all-eco-modes" ' . selected($mode, 'all-eco-modes', false) . '>All Eco Modes</option>';
        echo '</select>';
        echo '<p class="description">When to use tiny placeholder images</p>';
    }

    public function render_optimize_video_field() {
        $optimize_video = get_option('grid_aware_optimize_video', 'yes');
        echo '<select name="grid_aware_optimize_video">';
        echo '<option value="yes" ' . selected($optimize_video, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($optimize_video, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Optimize video embeds with click-to-load placeholders</p>';
    }

    /**
     * Render debug section
     */
    public function render_debug_section() {
        echo '<p>These settings allow you to test and preview different optimization modes.</p>';
    }

    /**
     * Render force mode field
     */
    public function render_force_mode_field() {
        $force_mode = get_option('grid_aware_force_mode', 'auto');
        ?>
        <select name="grid_aware_force_mode">
            <option value="auto" <?php selected($force_mode, 'auto'); ?>>Auto (Use Live Grid Data)</option>
            <option value="standard" <?php selected($force_mode, 'standard'); ?>>Force Standard Mode</option>
            <option value="eco" <?php selected($force_mode, 'eco'); ?>>Force Eco Mode</option>
            <option value="super-eco" <?php selected($force_mode, 'super-eco'); ?>>Force Super-Eco Mode</option>
        </select>
        <p class="description">Temporarily force a specific mode for testing. Only affects admin users.</p>
        <?php
    }

    /**
     * Render the analytics dashboard page
     */
    public function render_analytics_page() {
        // Check if analytics class exists
        if (!class_exists('Grid_Aware_Analytics')) {
            echo '<div class="notice notice-error"><p>Analytics module not available.</p></div>';
            return;
        }

        $analytics = Grid_Aware_Analytics::get_instance();

        // Check if the generate_report method exists
        if (!method_exists($analytics, 'generate_report')) {
            echo '<div class="notice notice-error"><p>Analytics generate_report method not available.</p></div>';
            return;
        }

        // Get current period from URL or default to 7 days
        $current_period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '7days';

        // Get analytics data
        $report = $analytics->generate_report($current_period, 'array');

        ?>
        <div class="wrap grid-aware-analytics">
            <h1>
                <span class="dashicons dashicons-chart-area" style="color: #0f834d;"></span>
                Carbon Footprint Analytics
            </h1>

            <div class="grid-aware-analytics-header">
                <div class="period-selector">
                    <label for="analytics-period">Time Period:</label>
                    <select id="analytics-period" onchange="window.location.href='<?php echo admin_url('admin.php?page=grid-aware-analytics&period='); ?>' + this.value">
                        <option value="24hours" <?php selected($current_period, '24hours'); ?>>Last 24 Hours</option>
                        <option value="7days" <?php selected($current_period, '7days'); ?>>Last 7 Days</option>
                        <option value="30days" <?php selected($current_period, '30days'); ?>>Last 30 Days</option>
                        <option value="12months" <?php selected($current_period, '12months'); ?>>Last 12 Months</option>
                    </select>
                </div>

                <div class="export-buttons">
                    <button type="button" class="button" onclick="exportAnalytics('csv')">
                        <span class="dashicons dashicons-download"></span> Export CSV
                    </button>
                    <button type="button" class="button" onclick="exportAnalytics('json')">
                        <span class="dashicons dashicons-download"></span> Export JSON
                    </button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="analytics-summary-cards">
                <div class="summary-card carbon-footprint">
                    <div class="card-icon">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                    </div>
                    <div class="card-content">
                        <h3>Total Carbon Footprint</h3>
                        <div class="metric-value"><?php echo number_format($report['summary']['total_carbon_g'], 3); ?>g CO₂</div>
                        <div class="metric-subtitle"><?php echo number_format($report['summary']['total_carbon_kg'], 6); ?>kg CO₂</div>
                    </div>
                </div>

                <div class="summary-card carbon-saved">
                    <div class="card-icon">
                        <span class="dashicons dashicons-heart"></span>
                    </div>
                    <div class="card-content">
                        <h3>Carbon Saved</h3>
                        <div class="metric-value"><?php echo number_format($report['summary']['total_savings_g'], 3); ?>g CO₂</div>
                        <div class="metric-subtitle"><?php echo $report['summary']['savings_percentage']; ?>% reduction</div>
                    </div>
                </div>

                <div class="summary-card page-views">
                    <div class="card-icon">
                        <span class="dashicons dashicons-visibility"></span>
                    </div>
                    <div class="card-content">
                        <h3>Page Views</h3>
                        <div class="metric-value"><?php echo number_format($report['summary']['total_page_views']); ?></div>
                        <div class="metric-subtitle"><?php echo number_format($report['summary']['carbon_per_view_g'], 6); ?>g CO₂/view</div>
                    </div>
                </div>

                <div class="summary-card data-transfer">
                    <div class="card-icon">
                        <span class="dashicons dashicons-networking"></span>
                    </div>
                    <div class="card-content">
                        <h3>Data Transfer</h3>
                        <div class="metric-value"><?php echo number_format($report['summary']['total_data_mb'], 1); ?> MB</div>
                        <div class="metric-subtitle"><?php echo number_format($report['summary']['total_data_kb']); ?> KB</div>
                    </div>
                </div>
            </div>

            <!-- Environmental Impact -->
            <?php if (!empty($report['summary']['equivalent_metrics'])): ?>
            <div class="analytics-section environmental-impact">
                <h2>Environmental Impact Equivalent</h2>
                <div class="equivalent-metrics">
                    <div class="equivalent-item">
                        <span class="dashicons dashicons-palmtree"></span>
                        <strong><?php echo $report['summary']['equivalent_metrics']['trees_planted']; ?> trees</strong>
                        <span>planted (carbon absorbed yearly)</span>
                    </div>
                    <div class="equivalent-item">
                        <span class="dashicons dashicons-car"></span>
                        <strong><?php echo number_format($report['summary']['equivalent_metrics']['km_driven'], 1); ?> km</strong>
                        <span>driven in an average car</span>
                    </div>
                    <div class="equivalent-item">
                        <span class="dashicons dashicons-smartphone"></span>
                        <strong><?php echo number_format($report['summary']['equivalent_metrics']['phone_charges']); ?> phone charges</strong>
                        <span>from the grid</span>
                    </div>
                    <div class="equivalent-item">
                        <span class="dashicons dashicons-lightbulb"></span>
                        <strong><?php echo number_format($report['summary']['equivalent_metrics']['led_bulb_hours']); ?> hours</strong>
                        <span>of LED bulb usage</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Insights -->
            <?php if (!empty($report['insights'])): ?>
            <div class="analytics-section insights">
                <h2>Insights & Opportunities</h2>
                <div class="insights-list">
                    <?php foreach ($report['insights'] as $insight): ?>
                    <div class="insight-item impact-<?php echo esc_attr($insight['impact']); ?>">
                        <div class="insight-icon">
                            <?php if ($insight['type'] === 'peak_carbon'): ?>
                                <span class="dashicons dashicons-warning"></span>
                            <?php elseif ($insight['type'] === 'optimization_opportunity'): ?>
                                <span class="dashicons dashicons-performance"></span>
                            <?php else: ?>
                                <span class="dashicons dashicons-info"></span>
                            <?php endif; ?>
                        </div>
                        <div class="insight-content">
                            <h4><?php echo esc_html($insight['title']); ?></h4>
                            <p><?php echo esc_html($insight['description']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recommendations -->
            <?php if (!empty($report['recommendations'])): ?>
            <div class="analytics-section recommendations">
                <h2>Recommendations</h2>
                <div class="recommendations-list">
                    <?php foreach ($report['recommendations'] as $recommendation): ?>
                    <div class="recommendation-item priority-<?php echo esc_attr($recommendation['priority']); ?>">
                        <div class="recommendation-header">
                            <h4><?php echo esc_html($recommendation['title']); ?></h4>
                            <span class="priority-badge"><?php echo esc_html(ucfirst($recommendation['priority'])); ?> Priority</span>
                        </div>
                        <p><?php echo esc_html($recommendation['description']); ?></p>
                        <?php if (isset($recommendation['estimated_savings'])): ?>
                        <div class="estimated-savings">
                            <strong>Estimated additional savings: <?php echo esc_html($recommendation['estimated_savings']); ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Timeline Chart Placeholder -->
            <div class="analytics-section timeline-chart">
                <h2>Carbon Intensity Timeline</h2>
                <div id="carbon-timeline-chart" style="height: 300px; background: #f9f9f9; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center;">
                    <p>Chart will be implemented with JavaScript charting library</p>
                </div>
            </div>

            <!-- Raw Data Table -->
            <div class="analytics-section data-table">
                <h2>Detailed Data</h2>
                <div class="table-responsive">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Carbon Intensity</th>
                                <th>Optimization Level</th>
                                <th>Page Views</th>
                                <th>Data Transfer (KB)</th>
                                <th>Carbon Footprint (g)</th>
                                <th>Carbon Saved (g)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($report['timeline'])): ?>
                                <?php foreach (array_slice($report['timeline'], 0, 20) as $row): ?>
                                <tr>
                                    <td><?php echo esc_html($row['timestamp']); ?></td>
                                    <td><?php echo esc_html(number_format($row['avg_carbon_intensity'], 1)); ?> g/kWh</td>
                                    <td>
                                        <span class="optimization-badge <?php echo esc_attr($row['optimization_level']); ?>">
                                            <?php echo esc_html(ucfirst($row['optimization_level'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html(number_format($row['page_views'])); ?></td>
                                    <td><?php echo esc_html(number_format($row['data_transferred_kb'], 2)); ?></td>
                                    <td><?php echo esc_html(number_format($row['estimated_carbon_g'], 6)); ?></td>
                                    <td><?php echo esc_html(number_format($row['savings_carbon_g'], 6)); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center;">No data available for the selected period.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        function exportAnalytics(format) {
            const period = document.getElementById('analytics-period').value;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = ajaxurl;

            const fields = {
                action: 'grid_aware_export_analytics',
                nonce: '<?php echo wp_create_nonce('grid_aware_analytics'); ?>',
                period: period,
                format: format
            };

            for (const key in fields) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        </script>
        <?php
    }
}
?>