<?php
/**
 * API integration for Grid Aware plugin
 */
class Grid_Aware_API extends Grid_Aware_Base {
    private $table_daily;

    /**
     * Constructor
     */
    protected function __construct() {
        parent::__construct();
        global $wpdb;

        $this->table_daily = $wpdb->prefix . 'grid_aware_daily_summary';
        add_action('grid_aware_analytics_create_tables', array($this, 'create_tables'));

    }

    /**
     * Get carbon intensity data from API or cache
     */
    public function get_carbon_intensity() {
        $api_key = get_option('grid_aware_api_key');
        $zone = get_option('grid_aware_zone', 'SE');

        if (!$api_key) {
            return ['error' => 'API key is missing'];
        }

        $transient_key = 'grid_aware_data_' . $zone;
        $cached_data = get_transient($transient_key);

        if ($cached_data) {
            return $cached_data;
        }

        // API call
        $url = add_query_arg(
            array('zone' => $zone),
            'https://api.electricitymap.org/v3/carbon-intensity/latest'
        );

        $response = wp_remote_get($url, ['headers' => ['auth-token' => $api_key]]);

        if (is_wp_error($response)) {
            return ['error' => 'API request failed: ' . $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['carbonIntensity'])) {
            return ['error' => 'Invalid API response'];
        }

        // Calculate expiration (10 minutes default)
        $expiration = 600;
        if (isset($data['datetime'])) {
            try {
                $datetime = new DateTime($data['datetime']);
                $now = new DateTime();
                if ($datetime > $now) {
                    $expiration = $datetime->getTimestamp() - $now->getTimestamp() + 30;
                }
            } catch (Exception $e) {
                // Use default
            }
        }

        // Add expiration info to data
        $data['expiresIn'] = $expiration;
        $data['localTimestamp'] = time();

        // Cache the result
        set_transient($transient_key, $data, $expiration);

        return $data;
    }

    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

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
        dbDelta($sql_daily);
    }

    /**
     * Clear cached data
     */
    public function clear_cache() {
        $zone = get_option('grid_aware_zone', 'SE');
        delete_transient('grid_aware_data_' . $zone);
    }


    /**
     * Get cached data without making an API request
     */
    public function get_cached_data() {
        $zone = get_option('grid_aware_zone', 'SE');
        $cached_data = get_transient('grid_aware_data_' . $zone);

        if ($cached_data === false) {
            // No cached data, try to get fresh data
            return $this->get_carbon_intensity();
        }

        return $cached_data;
    }

    /**
     * Generate analytics report
     *
     * @param string $period The time period (24hours, 7days, 30days, 12months)
     * @param string $format The output format (array, csv, json)
     * @return array|string Report data
     */
    public function generate_report($period = '7days', $format = 'array') {
        // Default empty report structure
        $report = array(
            'summary' => array(
                'total_page_views' => 0,
                'total_carbon_g' => 0,
                'total_carbon_kg' => 0,
                'total_savings_g' => 0,
                'savings_percentage' => 0,
                'carbon_per_view_g' => 0,
                'total_data_mb' => 0,
                'total_data_kb' => 0,
                'equivalent_metrics' => array(
                    'trees_planted' => 0,
                    'km_driven' => 0,
                    'phone_charges' => 0,
                    'led_bulb_hours' => 0
                )
            ),
            'timeline' => array(),
            'insights' => array(),
            'recommendations' => array()
        );

        // Calculate date range based on period
        $end_date = current_time('mysql');

        switch ($period) {
            case '24hours':
                $start_date = date('Y-m-d H:i:s', strtotime('-24 hours'));
                break;
            case '7days':
                $start_date = date('Y-m-d H:i:s', strtotime('-7 days'));
                break;
            case '30days':
                $start_date = date('Y-m-d H:i:s', strtotime('-30 days'));
                break;
            case '12months':
                $start_date = date('Y-m-d H:i:s', strtotime('-12 months'));
                break;
            default:
                $start_date = date('Y-m-d H:i:s', strtotime('-7 days'));
        }

        // Get data from database (you'll need to implement this based on your data structure)
        $timeline_data = $this->get_timeline_data_from_db($period);

        if (!empty($timeline_data)) {
            $report['timeline'] = $timeline_data;
            $report['summary'] = $this->calculate_summary_metrics($timeline_data);
            $report['insights'] = $this->generate_insights($timeline_data, $period);
            $report['recommendations'] = $this->generate_recommendations($report['summary']);
        }

        // Return in requested format
        switch ($format) {
            case 'csv':
                return $this->format_as_csv($report);
            case 'json':
                return json_encode($report, JSON_PRETTY_PRINT);
            default:
                return $report;
        }
    }

    /**
     * Get timeline data from database based on period
     */
    private function get_timeline_data_from_db($period) {
        switch ($period) {
            case '24hours':
                return $this->get_hourly_data(1);
            case '7days':
                return $this->get_daily_data(7);
            case '30days':
                return $this->get_daily_data(30);
            case '12months':
                return $this->get_monthly_data(12);
            default:
                return $this->get_daily_data(7);
        }
    }

    /**
     * Get timeline data from database
     */
    private function get_timeline_data($start_date, $end_date, $period) {
        global $wpdb;

        // This is a placeholder - you'll need to implement based on your actual data structure
        // For now, return sample data to prevent errors

        $sample_data = array();
        $current = strtotime($start_date);
        $end = strtotime($end_date);

        // Generate sample timeline data
        while ($current < $end) {
            $sample_data[] = array(
                'timestamp' => date('Y-m-d H:i:s', $current),
                'avg_carbon_intensity' => rand(150, 450),
                'optimization_level' => rand(0, 2) == 0 ? 'standard' : (rand(0, 1) ? 'eco' : 'super-eco'),
                'page_views' => rand(5, 50),
                'data_transferred_kb' => rand(100, 1000),
                'estimated_carbon_g' => rand(10, 100) / 1000,
                'savings_carbon_g' => rand(5, 50) / 1000
            );

            // Increment based on period
            switch ($period) {
                case '24hours':
                    $current += 3600; // 1 hour
                    break;
                case '7days':
                    $current += 86400; // 1 day
                    break;
                default:
                    $current += 86400; // 1 day
            }
        }

        return $sample_data;
    }

    /**
     * Calculate summary metrics from timeline data
     */
    private function calculate_summary_metrics($timeline_data) {
        $total_page_views = 0;
        $total_carbon_g = 0;
        $total_savings_g = 0;
        $total_data_kb = 0;

        foreach ($timeline_data as $row) {
            $total_page_views += $row['page_views'];
            $total_carbon_g += $row['estimated_carbon_g'];
            $total_savings_g += $row['savings_carbon_g'];
            $total_data_kb += $row['data_transferred_kb'];
        }

        $carbon_per_view_g = $total_page_views > 0 ? $total_carbon_g / $total_page_views : 0;
        $total_carbon_with_savings = $total_carbon_g + $total_savings_g;
        $savings_percentage = $total_carbon_with_savings > 0 ? round(($total_savings_g / $total_carbon_with_savings) * 100, 1) : 0;


        return array(
            'total_page_views' => $total_page_views,
            'total_carbon_g' => round($total_carbon_g, 6),
            'total_carbon_kg' => round($total_carbon_g / 1000, 6),
            'total_savings_g' => round($total_savings_g, 6),
            'savings_percentage' => $savings_percentage,
            'carbon_per_view_g' => round($carbon_per_view_g, 6),
            'total_data_mb' => round($total_data_kb / 1024, 2),
            'total_data_kb' => round($total_data_kb, 2),
            'equivalent_metrics' => array(
                'trees_planted' => round($total_carbon_g / 21000, 3), // 21kg CO2 per tree per year
                'km_driven' => round($total_carbon_g / 120, 2), // ~120g CO2 per km average car
                'phone_charges' => round($total_carbon_g / 8.22, 0), // ~8.22g CO2 per phone charge
                'led_bulb_hours' => round($total_carbon_g / 6, 0) // ~6g CO2 per hour LED bulb
            )
        );
    }

    /**
     * Generate insights from data
     */
    private function generate_insights($timeline_data, $period) {
        $insights = array();

        if (empty($timeline_data)) {
            $insights[] = array(
                'type' => 'no_data',
                'impact' => 'info',
                'title' => 'No Data Available',
                'description' => 'Start browsing your site to collect carbon footprint data.'
            );
            return $insights;
        }

        // Real insights based on actual data
        $carbon_intensities = array_column($timeline_data, 'avg_carbon_intensity');
        $avg_intensity = array_sum($carbon_intensities) / count($carbon_intensities);

        if ($avg_intensity > 300) {
            $insights[] = array(
                'type' => 'high_carbon_period',
                'impact' => 'high',
                'title' => 'High Carbon Intensity Period',
                'description' => 'The grid had high carbon intensity (avg: ' . round($avg_intensity, 0) . ' g/kWh). Consider more aggressive optimizations.'
            );
        }

        return $insights;
    }

    /**
     * Format report as CSV
     */
    private function format_as_csv($report) {
        $csv = "Date/Time,Carbon Intensity,Optimization Level,Page Views,Data Transfer (KB),Carbon Footprint (g),Carbon Saved (g)\n";

        foreach ($report['timeline'] as $row) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $row['timestamp'],
                $row['avg_carbon_intensity'],
                $row['optimization_level'],
                $row['page_views'],
                $row['data_transferred_kb'],
                $row['estimated_carbon_g'],
                $row['savings_carbon_g']
            );
        }

        return $csv;
    }

        /**
     * Generate recommendations
     */
    private function generate_recommendations($data) {
        $recommendations = array();

        if (empty($data)) {
            return $recommendations;
        }

        $summary = $this->calculate_summary($data);

        // High carbon intensity recommendation
        if ($summary['avg_carbon_intensity'] > 300) {
            $recommendations[] = array(
                'type' => 'high_carbon_region',
                'priority' => 'high',
                'title' => 'Enable Aggressive Optimization',
                'description' => 'Your region has high carbon intensity. Enable "Super-Eco" mode more frequently for maximum impact.',
                'estimated_savings' => round($summary['total_carbon_g'] * 0.3, 2) . 'g CO2'
            );
        }

        // Data transfer recommendations
        if ($summary['total_data_mb'] > 1000) {
            $recommendations[] = array(
                'type' => 'data_optimization',
                'priority' => 'medium',
                'title' => 'Optimize Large Assets',
                'description' => 'High data transfer detected. Consider enabling WebP images and video compression.',
                'estimated_savings' => round($summary['total_carbon_g'] * 0.15, 2) . 'g CO2'
            );
        }

        return $recommendations;
    }

    /**
     * Export report to CSV
     */
    private function export_to_csv($report) {
        $csv = "Carbon Footprint Report - " . ucfirst($report['period']) . "\n";
        $csv .= "Generated: " . $report['generated_at'] . "\n\n";

        // Summary
        $csv .= "Summary Metrics\n";
        foreach ($report['summary'] as $key => $value) {
            $csv .= ucwords(str_replace('_', ' ', $key)) . "," . $value . "\n";
        }

        $csv .= "\nTimeline Data\n";
        $csv .= "Date/Time,Carbon Intensity,Optimization Level,Page Views,Data Transfer (KB),Carbon Footprint (g),Carbon Saved (g)\n";

        foreach ($report['timeline'] as $row) {
            $csv .= implode(',', array(
                $row['timestamp'],
                $row['avg_carbon_intensity'],
                $row['optimization_level'],
                $row['page_views'],
                $row['data_transferred_kb'],
                $row['estimated_carbon_g'],
                $row['savings_carbon_g']
            )) . "\n";
        }

        return $csv;
    }

    /**
     * Calculate summary metrics
     */
    private function calculate_summary($data) {
        if (empty($data)) {
            return array(
                'total_page_views' => 0,
                'total_data_kb' => 0,
                'total_carbon_g' => 0,
                'total_savings_g' => 0,
                'avg_carbon_intensity' => 0,
                'carbon_per_view_g' => 0,
                'savings_percentage' => 0,
                'equivalent_metrics' => array()
            );
        }

        $total_views = array_sum(array_column($data, 'page_views'));
        $total_data = array_sum(array_column($data, 'data_transferred_kb'));
        $total_carbon = array_sum(array_column($data, 'estimated_carbon_g'));
        $total_savings = array_sum(array_column($data, 'savings_carbon_g'));

        $avg_intensity = array_sum(array_column($data, 'avg_carbon_intensity')) / count($data);

        return array(
            'total_page_views' => $total_views,
            'total_data_kb' => round($total_data, 2),
            'total_data_mb' => round($total_data / 1024, 2),
            'total_carbon_g' => round($total_carbon, 6),
            'total_carbon_kg' => round($total_carbon / 1000, 6),
            'total_savings_g' => round($total_savings, 6),
            'total_savings_kg' => round($total_savings / 1000, 6),
            'avg_carbon_intensity' => round($avg_intensity, 2),
            'carbon_per_view_g' => $total_views > 0 ? round($total_carbon / $total_views, 6) : 0,
            'savings_percentage' => $total_carbon > 0 ? round(($total_savings / ($total_carbon + $total_savings)) * 100, 2) : 0,
            'equivalent_metrics' => $this->calculate_equivalent_metrics($total_savings)
        );
    }

        /**
     * Calculate equivalent metrics for better understanding
     */
    private function calculate_equivalent_metrics($carbon_savings_g) {
        $carbon_kg = $carbon_savings_g / 1000;

        return array(
            'trees_planted' => round($carbon_kg / 21.77, 4), // Average tree absorbs 21.77kg CO2/year
            'km_driven' => round($carbon_kg / 0.12, 2), // Average car emits 120g CO2/km
            'phone_charges' => round($carbon_kg / 0.0084, 0), // Phone charge emits ~8.4g CO2
            'led_bulb_hours' => round($carbon_kg / 0.009, 0) // LED bulb emits ~9g CO2/hour
        );
    }

    /**
     * Get daily data for specified number of days
     */
    private function get_daily_data($days) {
        global $wpdb;

        // Check if table exists, create if not
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_daily}'");
        if (!$table_exists) {
            $this->create_tables();
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                date as timestamp,
                total_page_views as page_views,
                total_data_kb as data_transferred_kb,
                total_carbon_g as estimated_carbon_g,
                total_savings_g as savings_carbon_g,
                avg_carbon_intensity,
                peak_optimization_level as optimization_level
            FROM {$this->table_daily}
            WHERE date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            ORDER BY date DESC",
            $days
        ), ARRAY_A);

        return $results;
    }

    /**
     * Get hourly data for specified number of days
     */
    private function get_hourly_data($days) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE_FORMAT(timestamp, '%%Y-%%m-%%d %%H:00:00') as timestamp,
                SUM(page_views) as page_views,
                SUM(data_transferred_kb) as data_transferred_kb,
                SUM(estimated_carbon_g) as estimated_carbon_g,
                SUM(savings_carbon_g) as savings_carbon_g,
                AVG(carbon_intensity) as avg_carbon_intensity,
                optimization_level
            FROM {$this->table_analytics}
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE_FORMAT(timestamp, '%%Y-%%m-%%d %%H'), optimization_level
            ORDER BY timestamp DESC",
            $days
        ), ARRAY_A);
    }

    /**
     * Get monthly data for specified number of months
     */
    private function get_monthly_data($months) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE_FORMAT(date, '%%Y-%%m-01') as timestamp,
                SUM(total_page_views) as page_views,
                SUM(total_data_kb) as data_transferred_kb,
                SUM(total_carbon_g) as estimated_carbon_g,
                SUM(total_savings_g) as savings_carbon_g,
                AVG(avg_carbon_intensity) as avg_carbon_intensity,
                peak_optimization_level as optimization_level
            FROM {$this->table_daily}
            WHERE date >= DATE_SUB(CURDATE(), INTERVAL %d MONTH)
            GROUP BY DATE_FORMAT(date, '%%Y-%%m')
            ORDER BY timestamp DESC",
            $months
        ), ARRAY_A);
    }

}