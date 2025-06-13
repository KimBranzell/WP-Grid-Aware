<?php
/**
 * Grid Aware Analytics Class
 * Handles carbon footprint tracking and reporting
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class Grid_Aware_Analytics extends Grid_Aware_Base {

    private $table_analytics;
    private $table_daily;

    /**
     * Constructor
     */
    protected function __construct() {
        parent::__construct();

        global $wpdb;
        $this->table_analytics = $wpdb->prefix . 'grid_aware_analytics';
        $this->table_daily = $wpdb->prefix . 'grid_aware_daily_summary';

        // Create tables on activation
        add_action('grid_aware_analytics_create_tables', array($this, 'create_tables'));

        // Track page views
        add_action('wp', array($this, 'track_page_view'));

        // Track optimizations
        add_action('grid_aware_optimization_applied', array($this, 'track_optimization'), 10, 3);

        // Schedule daily summary
        add_action('wp_loaded', array($this, 'schedule_daily_summary'));
        add_action('grid_aware_daily_summary_cron', array($this, 'generate_daily_summary'));

        // AJAX handlers
        add_action('wp_ajax_grid_aware_analytics_data', array($this, 'ajax_get_analytics_data'));
        add_action('wp_ajax_grid_aware_export_analytics', array($this, 'ajax_export_analytics'));
    }

    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Analytics table
        $sql_analytics = "CREATE TABLE {$this->table_analytics} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            carbon_intensity decimal(8,4) NOT NULL,
            optimization_level enum('off', 'standard', 'eco', 'super-eco') NOT NULL DEFAULT 'standard',
            page_views int(11) DEFAULT 0,
            data_transferred_kb decimal(12,2) DEFAULT 0,
            estimated_carbon_g decimal(10,6) DEFAULT 0,
            savings_carbon_g decimal(10,6) DEFAULT 0,
            optimization_actions longtext,
            region varchar(10) DEFAULT 'GB',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_timestamp (timestamp),
            KEY idx_region (region),
            KEY idx_optimization_level (optimization_level)
        ) $charset_collate;";

        // Daily summary table
        $sql_daily = "CREATE TABLE {$this->table_daily} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            date date NOT NULL UNIQUE,
            total_page_views int(11) DEFAULT 0,
            total_data_kb decimal(15,2) DEFAULT 0,
            total_carbon_g decimal(12,6) DEFAULT 0,
            total_savings_g decimal(12,6) DEFAULT 0,
            avg_carbon_intensity decimal(8,4) DEFAULT 0,
            peak_optimization_level enum('off', 'standard', 'eco', 'super-eco') DEFAULT 'standard',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date (date)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_analytics);
        dbDelta($sql_daily);

        // Mark tables as created
        update_option('grid_aware_analytics_tables_created', true);
    }

    /**
     * Track individual page view with carbon metrics
     */
    public function track_page_view() {
        // Skip admin, AJAX, and REST API requests
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // Skip if tables not created yet
        if (!get_option('grid_aware_analytics_tables_created', false)) {
            return;
        }

        global $wpdb;

        $grid_data = $this->get_current_grid_data();
        $optimization_level = $this->get_current_optimization_level();

        // Calculate estimated data transfer and carbon footprint
        $metrics = $this->calculate_page_metrics();

        $wpdb->insert(
            $this->table_analytics,
            array(
                'timestamp' => current_time('mysql'),
                'carbon_intensity' => $grid_data['intensity'],
                'optimization_level' => $optimization_level,
                'page_views' => 1,
                'data_transferred_kb' => $metrics['data_kb'],
                'estimated_carbon_g' => $metrics['carbon_g'],
                'savings_carbon_g' => $metrics['savings_g'],
                'optimization_actions' => wp_json_encode($metrics['actions']),
                'region' => get_option('grid_aware_zone', 'GB')
            ),
            array('%s', '%f', '%s', '%d', '%f', '%f', '%f', '%s', '%s')
        );
    }

    /**
     * Track optimization application
     */
    public function track_optimization($optimization_type, $context, $data = array()) {
        // Skip if tables not created yet
        if (!get_option('grid_aware_analytics_tables_created', false)) {
            return;
        }

        global $wpdb;

        $grid_data = $this->get_current_grid_data();
        $optimization_level = $this->get_current_optimization_level();

        // Calculate metrics based on optimization type
        $metrics = $this->calculate_optimization_metrics($optimization_type, $context, $data);

        $wpdb->insert(
            $this->table_analytics,
            array(
                'timestamp' => current_time('mysql'),
                'carbon_intensity' => $grid_data['intensity'],
                'optimization_level' => $optimization_level,
                'page_views' => 0, // This is an optimization event, not a page view
                'data_transferred_kb' => $metrics['data_saved_kb'],
                'estimated_carbon_g' => 0, // No carbon emitted for optimization
                'savings_carbon_g' => $metrics['carbon_saved_g'],
                'optimization_actions' => wp_json_encode(array(
                    'type' => $optimization_type,
                    'context' => $context,
                    'data' => $data
                )),
                'region' => get_option('grid_aware_zone', 'GB')
            ),
            array('%s', '%f', '%s', '%d', '%f', '%f', '%f', '%s', '%s')
        );
    }

    /**
     * Get current grid data
     */
    private function get_current_grid_data() {
        $api = Grid_Aware_API::get_instance();
        $data = $api->get_carbon_intensity();

        return array(
            'intensity' => isset($data['carbonIntensity']) ? $data['carbonIntensity'] : 250,
            'zone' => get_option('grid_aware_zone', 'GB')
        );
    }

    /**
     * Get current optimization level
     */
    private function get_current_optimization_level() {
        if (class_exists('Grid_Aware_Server')) {
            $server = Grid_Aware_Server::get_instance();
            return $server->get_current_mode();
        }

        return $this->current_mode;
    }

    /**
     * Calculate carbon metrics for current page
     */
    private function calculate_page_metrics() {
        $baseline_kb = $this->estimate_baseline_page_size();
        $optimized_kb = $this->estimate_optimized_page_size();
        $carbon_intensity = $this->get_current_grid_data()['intensity'];

        // Carbon calculation: kWh per KB * KB * carbon intensity (gCO2/kWh)
        // Using industry standard: ~0.000006 kWh per KB transferred
        $kwh_per_kb = 0.000006;

        $baseline_carbon = $baseline_kb * $kwh_per_kb * $carbon_intensity;
        $optimized_carbon = $optimized_kb * $kwh_per_kb * $carbon_intensity;
        $savings = max(0, $baseline_carbon - $optimized_carbon);

        return array(
            'data_kb' => $optimized_kb,
            'carbon_g' => $optimized_carbon,
            'savings_g' => $savings,
            'actions' => $this->get_applied_optimizations()
        );
    }

    /**
     * Calculate metrics for specific optimization
     */
    private function calculate_optimization_metrics($optimization_type, $context, $data) {
        $data_saved_kb = 0;
        $carbon_saved_g = 0;
        $carbon_intensity = $this->get_current_grid_data()['intensity'];
        $kwh_per_kb = 0.000006;

        switch ($optimization_type) {
            case 'image_compression':
                $data_saved_kb = isset($data['size_reduction_kb']) ? $data['size_reduction_kb'] : 0;
                break;

            case 'script_deferring':
                $data_saved_kb = isset($data['deferred_size_kb']) ? $data['deferred_size_kb'] : 50; // Estimate
                break;

            case 'lazy_loading':
                $data_saved_kb = isset($data['lazy_content_kb']) ? $data['lazy_content_kb'] : 100; // Estimate
                break;

            case 'video_optimization':
                $data_saved_kb = isset($data['quality_reduction_kb']) ? $data['quality_reduction_kb'] : 500; // Estimate
                break;

            case 'tiny_placeholders':
                $data_saved_kb = isset($data['placeholder_savings_kb']) ? $data['placeholder_savings_kb'] : 75; // Estimate
                break;

            default:
                $data_saved_kb = 0;
        }

        $carbon_saved_g = $data_saved_kb * $kwh_per_kb * $carbon_intensity;

        return array(
            'data_saved_kb' => $data_saved_kb,
            'carbon_saved_g' => $carbon_saved_g
        );
    }

    /**
     * Estimate baseline page size (without optimizations)
     */
    private function estimate_baseline_page_size() {
        // Start with a baseline estimate
        $base_size = 500; // Base HTML + CSS in KB

        // Add estimates for common page elements
        if (is_front_page()) {
            $base_size += 800; // Front page typically has more content
        } elseif (is_single()) {
            $base_size += 300; // Single post/page
        }

        // Estimate based on content
        global $post;
        if ($post && !empty($post->post_content)) {
            $content_size = strlen($post->post_content) / 1024; // Convert to KB
            $base_size += $content_size;

            // Estimate images in content
            $image_count = substr_count($post->post_content, '<img');
            $base_size += $image_count * 150; // Average 150KB per image
        }

        return $base_size;
    }

    /**
     * Estimate optimized page size (with current optimizations)
     */
    private function estimate_optimized_page_size() {
        $baseline = $this->estimate_baseline_page_size();
        $reduction_factor = 1.0;

        $current_mode = $this->get_current_optimization_level();

        switch ($current_mode) {
            case 'eco':
                $reduction_factor = 0.75; // 25% reduction
                break;
            case 'super-eco':
                $reduction_factor = 0.50; // 50% reduction
                break;
            default:
                $reduction_factor = 1.0; // No reduction
        }

        return $baseline * $reduction_factor;
    }

    /**
     * Get applied optimizations for current request
     */
    private function get_applied_optimizations() {
        $optimizations = array();
        $current_mode = $this->get_current_optimization_level();

        if ($current_mode === 'eco' || $current_mode === 'super-eco') {
            if (get_option('grid_aware_optimize_images', 'yes') === 'yes') {
                $optimizations[] = 'image_optimization';
            }

            if (get_option('grid_aware_lazy_load', 'yes') === 'yes') {
                $optimizations[] = 'lazy_loading';
            }

            if (get_option('grid_aware_defer_non_essential', 'yes') === 'yes') {
                $optimizations[] = 'script_deferring';
            }
        }

        if ($current_mode === 'super-eco') {
            if (get_option('grid_aware_tiny_placeholders', 'yes') === 'yes') {
                $optimizations[] = 'tiny_placeholders';
            }

            if (get_option('grid_aware_optimize_video', 'yes') === 'yes') {
                $optimizations[] = 'video_optimization';
            }
        }

        return $optimizations;
    }

    /**
     * Schedule daily summary generation
     */
    public function schedule_daily_summary() {
        if (!wp_next_scheduled('grid_aware_daily_summary_cron')) {
            wp_schedule_event(time(), 'daily', 'grid_aware_daily_summary_cron');
        }
    }

    /**
     * Generate daily summary
     */
    public function generate_daily_summary() {
        global $wpdb;

        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Check if summary already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_daily} WHERE date = %s",
            $yesterday
        ));

        if ($exists) {
            return; // Already generated
        }

        // Calculate daily metrics
        $daily_data = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(page_views) as total_page_views,
                SUM(data_transferred_kb) as total_data_kb,
                SUM(estimated_carbon_g) as total_carbon_g,
                SUM(savings_carbon_g) as total_savings_g,
                AVG(carbon_intensity) as avg_carbon_intensity
            FROM {$this->table_analytics}
            WHERE DATE(timestamp) = %s",
            $yesterday
        ));

        // Get peak optimization level
        $peak_level = $wpdb->get_var($wpdb->prepare(
            "SELECT optimization_level
            FROM {$this->table_analytics}
            WHERE DATE(timestamp) = %s
            ORDER BY
                CASE optimization_level
                    WHEN 'super-eco' THEN 4
                    WHEN 'eco' THEN 3
                    WHEN 'standard' THEN 2
                    ELSE 1
                END DESC
            LIMIT 1",
            $yesterday
        ));

        if ($daily_data && $daily_data->total_page_views > 0) {
            $wpdb->insert(
                $this->table_daily,
                array(
                    'date' => $yesterday,
                    'total_page_views' => $daily_data->total_page_views,
                    'total_data_kb' => $daily_data->total_data_kb,
                    'total_carbon_g' => $daily_data->total_carbon_g,
                    'total_savings_g' => $daily_data->total_savings_g,
                    'avg_carbon_intensity' => $daily_data->avg_carbon_intensity,
                    'peak_optimization_level' => $peak_level ?: 'standard'
                ),
                array('%s', '%d', '%f', '%f', '%f', '%f', '%s')
            );
        }
    }





    /**
     * AJAX handler for analytics data
     */
    public function ajax_get_analytics_data() {
        check_ajax_referer('grid_aware_analytics', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $period = sanitize_text_field($_POST['period'] ?? '7days');
        $report = $this->generate_report($period, 'array');

        wp_send_json_success($report);
    }

    /**
     * AJAX handler for exporting analytics
     */
    public function ajax_export_analytics() {
        check_ajax_referer('grid_aware_analytics', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $period = sanitize_text_field($_POST['period'] ?? '30days');
        $format = sanitize_text_field($_POST['format'] ?? 'csv');

        $report = $this->generate_report($period, $format);

        $filename = "carbon-footprint-report-{$period}-" . date('Y-m-d');

        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            echo $report;
            exit;
        } elseif ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
            echo $report;
            exit;
        }

        wp_send_json_success(array('report' => $report));
    }
}
