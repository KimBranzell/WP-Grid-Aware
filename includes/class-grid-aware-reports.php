<?php
/**
 * Grid Aware Reports Class
 * Advanced reporting and export functionality
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class Grid_Aware_Reports extends Grid_Aware_Base {

    /**
     * Constructor
     */
    protected function __construct() {
        parent::__construct();

        // Add admin menu for reports
        add_action('admin_menu', array($this, 'add_reports_submenu'), 20);

        // AJAX handlers for advanced reports
        add_action('wp_ajax_grid_aware_generate_custom_report', array($this, 'ajax_generate_custom_report'));
        add_action('wp_ajax_grid_aware_schedule_report', array($this, 'ajax_schedule_report'));

        // Schedule automated reports
        add_action('wp_loaded', array($this, 'schedule_automated_reports'));
        add_action('grid_aware_weekly_report', array($this, 'send_weekly_report'));
        add_action('grid_aware_monthly_report', array($this, 'send_monthly_report'));
    }

    /**
     * Add reports submenu
     */
    public function add_reports_submenu() {
        add_submenu_page(
            'grid-aware-settings',
            'Advanced Reports',
            'Reports',
            'manage_options',
            'grid-aware-reports',
            array($this, 'render_reports_page')
        );
    }

    /**
     * Render advanced reports page
     */
    public function render_reports_page() {
        if (!class_exists('Grid_Aware_Analytics')) {
            echo '<div class="notice notice-error"><p>Analytics module required for reports.</p></div>';
            return;
        }

        ?>
        <div class="wrap grid-aware-reports">
            <h1>
                <span class="dashicons dashicons-chart-bar" style="color: #0f834d;"></span>
                Advanced Carbon Reports
            </h1>

            <div class="reports-container">
                <!-- Custom Report Generator -->
                <div class="report-section">
                    <h2>Custom Report Generator</h2>
                    <form id="custom-report-form">
                        <table class="form-table">
                            <tr>
                                <th><label for="report-type">Report Type</label></th>
                                <td>
                                    <select id="report-type" name="report_type">
                                        <option value="carbon_summary">Carbon Footprint Summary</option>
                                        <option value="optimization_effectiveness">Optimization Effectiveness</option>
                                        <option value="peak_usage">Peak Usage Analysis</option>
                                        <option value="comparative">Period Comparison</option>
                                        <option value="sustainability">Sustainability Metrics</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="date-range">Date Range</label></th>
                                <td>
                                    <input type="date" id="start-date" name="start_date" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                                    to
                                    <input type="date" id="end-date" name="end_date" value="<?php echo date('Y-m-d'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="report-format">Format</label></th>
                                <td>
                                    <select id="report-format" name="format">
                                        <option value="html">HTML Report</option>
                                        <option value="csv">CSV Export</option>
                                        <option value="json">JSON Data</option>
                                        <option value="pdf">PDF Report (Pro)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="include-charts">Include Visualizations</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="include-charts" name="include_charts" checked>
                                        Include charts and graphs
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="button" id="generate-report" class="button button-primary">
                                Generate Report
                            </button>
                        </p>
                    </form>
                </div>

                <!-- Scheduled Reports -->
                <div class="report-section">
                    <h2>Scheduled Reports</h2>
                    <p>Automatically generate and email reports on a schedule.</p>

                    <form id="scheduled-reports-form">
                        <table class="form-table">
                            <tr>
                                <th><label for="email-reports">Email Address</label></th>
                                <td>
                                    <input type="email" id="email-reports" name="email"
                                           value="<?php echo get_option('admin_email'); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="weekly-reports">Weekly Reports</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="weekly-reports" name="weekly_reports"
                                               <?php checked(get_option('grid_aware_weekly_reports', 'no'), 'yes'); ?>>
                                        Send weekly carbon footprint summary
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="monthly-reports">Monthly Reports</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="monthly-reports" name="monthly_reports"
                                               <?php checked(get_option('grid_aware_monthly_reports', 'no'), 'yes'); ?>>
                                        Send monthly sustainability report
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="button" id="save-schedule" class="button">
                                Save Schedule Settings
                            </button>
                        </p>
                    </form>
                </div>

                <!-- Quick Reports -->
                <div class="report-section">
                    <h2>Quick Reports</h2>
                    <div class="quick-reports-grid">
                        <div class="quick-report-card">
                            <h3>This Week vs Last Week</h3>
                            <p>Compare carbon footprint between current and previous week.</p>
                            <button class="button" onclick="generateQuickReport('week_comparison')">
                                Generate
                            </button>
                        </div>

                        <div class="quick-report-card">
                            <h3>Monthly Trends</h3>
                            <p>View monthly carbon intensity and optimization trends.</p>
                            <button class="button" onclick="generateQuickReport('monthly_trends')">
                                Generate
                            </button>
                        </div>

                        <div class="quick-report-card">
                            <h3>Peak Hours Analysis</h3>
                            <p>Identify peak carbon intensity hours and optimization opportunities.</p>
                            <button class="button" onclick="generateQuickReport('peak_analysis')">
                                Generate
                            </button>
                        </div>

                        <div class="quick-report-card">
                            <h3>Sustainability Goals</h3>
                            <p>Track progress towards carbon reduction goals.</p>
                            <button class="button" onclick="generateQuickReport('sustainability_goals')">
                                Generate
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Report Output -->
                <div id="report-output" class="report-section" style="display: none;">
                    <h2>Generated Report</h2>
                    <div id="report-content"></div>
                </div>
            </div>
        </div>

        <style>
        .grid-aware-reports .reports-container {
            max-width: 1000px;
        }

        .report-section {
            background: #fff;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .report-section h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #f1f1f1;
            padding-bottom: 10px;
        }

        .quick-reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .quick-report-card {
            background: #f8f9fa;
            border: 1px solid #e1e5e9;
            border-radius: 5px;
            padding: 15px;
            text-align: center;
        }

        .quick-report-card h3 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 16px;
        }

        .quick-report-card p {
            margin: 0 0 15px 0;
            color: #666;
            font-size: 13px;
            line-height: 1.4;
        }

        #report-output {
            border-left: 4px solid #0f834d;
        }

        #report-content {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            max-height: 400px;
            overflow-y: auto;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#generate-report').on('click', function() {
                generateCustomReport();
            });

            $('#save-schedule').on('click', function() {
                saveScheduleSettings();
            });
        });

        function generateCustomReport() {
            const formData = {
                action: 'grid_aware_generate_custom_report',
                nonce: '<?php echo wp_create_nonce('grid_aware_reports'); ?>',
                report_type: jQuery('#report-type').val(),
                start_date: jQuery('#start-date').val(),
                end_date: jQuery('#end-date').val(),
                format: jQuery('#report-format').val(),
                include_charts: jQuery('#include-charts').is(':checked')
            };

            jQuery('#generate-report').prop('disabled', true).text('Generating...');

            jQuery.post(ajaxurl, formData, function(response) {
                if (response.success) {
                    if (formData.format === 'html') {
                        jQuery('#report-content').html(response.data.content);
                        jQuery('#report-output').show();
                    } else {
                        // Trigger download for other formats
                        downloadReport(response.data.download_url);
                    }
                } else {
                    alert('Error generating report: ' + response.data);
                }
            }).always(function() {
                jQuery('#generate-report').prop('disabled', false).text('Generate Report');
            });
        }

        function generateQuickReport(type) {
            const formData = {
                action: 'grid_aware_generate_custom_report',
                nonce: '<?php echo wp_create_nonce('grid_aware_reports'); ?>',
                quick_report: type,
                format: 'html'
            };

            jQuery.post(ajaxurl, formData, function(response) {
                if (response.success) {
                    jQuery('#report-content').html(response.data.content);
                    jQuery('#report-output').show();
                    jQuery('html, body').animate({
                        scrollTop: jQuery('#report-output').offset().top - 50
                    }, 500);
                } else {
                    alert('Error generating report: ' + response.data);
                }
            });
        }

        function saveScheduleSettings() {
            const formData = {
                action: 'grid_aware_schedule_report',
                nonce: '<?php echo wp_create_nonce('grid_aware_reports'); ?>',
                email: jQuery('#email-reports').val(),
                weekly_reports: jQuery('#weekly-reports').is(':checked'),
                monthly_reports: jQuery('#monthly-reports').is(':checked')
            };

            jQuery.post(ajaxurl, formData, function(response) {
                if (response.success) {
                    alert('Schedule settings saved successfully!');
                } else {
                    alert('Error saving settings: ' + response.data);
                }
            });
        }

        function downloadReport(url) {
            const link = document.createElement('a');
            link.href = url;
            link.download = '';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        </script>
        <?php
    }

    /**
     * AJAX handler for custom report generation
     */
    public function ajax_generate_custom_report() {
        check_ajax_referer('grid_aware_reports', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $analytics = Grid_Aware_Analytics::get_instance();

        // Handle quick reports
        if (isset($_POST['quick_report'])) {
            $content = $this->generate_quick_report($_POST['quick_report'], $analytics);
            wp_send_json_success(array('content' => $content));
        }

        // Handle custom reports
        $report_type = sanitize_text_field($_POST['report_type']);
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        $format = sanitize_text_field($_POST['format']);

        $content = $this->generate_custom_report($report_type, $start_date, $end_date, $format, $analytics);

        wp_send_json_success(array('content' => $content));
    }

    /**
     * Generate quick report content
     */
    private function generate_quick_report($type, $analytics) {
        switch ($type) {
            case 'week_comparison':
                return $this->generate_week_comparison($analytics);
            case 'monthly_trends':
                return $this->generate_monthly_trends($analytics);
            case 'peak_analysis':
                return $this->generate_peak_analysis($analytics);
            case 'sustainability_goals':
                return $this->generate_sustainability_goals($analytics);
            default:
                return '<p>Report type not found.</p>';
        }
    }

    /**
     * Generate week comparison report
     */
    private function generate_week_comparison($analytics) {
        $current_week = $analytics->generate_report('7days', 'array');

        // Get previous week data (simplified)
        global $wpdb;
        $table = $wpdb->prefix . 'grid_aware_daily_summary';

        $prev_week_data = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
             AND date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             ORDER BY date DESC",
        ), ARRAY_A);

        $prev_summary = array(
            'total_carbon_g' => array_sum(array_column($prev_week_data, 'total_carbon_g')),
            'total_savings_g' => array_sum(array_column($prev_week_data, 'total_savings_g')),
            'total_page_views' => array_sum(array_column($prev_week_data, 'total_page_views'))
        );

        $current = $current_week['summary'];

        $carbon_change = $current['total_carbon_g'] - $prev_summary['total_carbon_g'];
        $savings_change = $current['total_savings_g'] - $prev_summary['total_savings_g'];
        $views_change = $current['total_page_views'] - $prev_summary['total_page_views'];

        return "
        <h3>Week-over-Week Comparison</h3>
        <div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;'>
            <div style='padding: 15px; background: #f8f9fa; border-radius: 5px; text-align: center;'>
                <h4>Carbon Footprint</h4>
                <div style='font-size: 18px; font-weight: bold; color: " . ($carbon_change > 0 ? '#b32d2e' : '#0f834d') . ";'>
                    " . ($carbon_change > 0 ? '+' : '') . number_format($carbon_change, 3) . "g CO₂
                </div>
                <small>" . ($carbon_change > 0 ? 'Increase' : 'Decrease') . " vs last week</small>
            </div>
            <div style='padding: 15px; background: #f8f9fa; border-radius: 5px; text-align: center;'>
                <h4>Carbon Saved</h4>
                <div style='font-size: 18px; font-weight: bold; color: " . ($savings_change > 0 ? '#0f834d' : '#b32d2e') . ";'>
                    " . ($savings_change > 0 ? '+' : '') . number_format($savings_change, 3) . "g CO₂
                </div>
                <small>" . ($savings_change > 0 ? 'Increase' : 'Decrease') . " vs last week</small>
            </div>
            <div style='padding: 15px; background: #f8f9fa; border-radius: 5px; text-align: center;'>
                <h4>Page Views</h4>
                <div style='font-size: 18px; font-weight: bold; color: #333;'>
                    " . ($views_change > 0 ? '+' : '') . number_format($views_change) . "
                </div>
                <small>" . ($views_change > 0 ? 'Increase' : 'Decrease') . " vs last week</small>
            </div>
        </div>
        <p><strong>Analysis:</strong> " . $this->generate_week_analysis($carbon_change, $savings_change, $views_change) . "</p>
        ";
    }

    /**
     * Generate analysis text for week comparison
     */
    private function generate_week_analysis($carbon_change, $savings_change, $views_change) {
        if ($carbon_change < 0 && $savings_change > 0) {
            return "Excellent progress! Your website's carbon footprint decreased while carbon savings increased, indicating effective optimization strategies.";
        } elseif ($carbon_change > 0 && $views_change > 0) {
            return "Carbon footprint increased, but this correlates with increased page views. Consider enabling more aggressive optimization during peak traffic.";
        } elseif ($savings_change > 0) {
            return "Good improvement in carbon savings. Your optimization strategies are becoming more effective.";
        } else {
            return "Carbon footprint and savings need attention. Consider reviewing your optimization settings and enabling more eco-friendly features.";
        }
    }

    /**
     * Generate other report types (simplified for now)
     */
    private function generate_monthly_trends($analytics) {
        return "<h3>Monthly Trends</h3><p>Monthly trends analysis would be implemented here with historical data and trend charts.</p>";
    }

    private function generate_peak_analysis($analytics) {
        return "<h3>Peak Hours Analysis</h3><p>Peak usage analysis would identify high carbon intensity periods and optimization opportunities.</p>";
    }

    private function generate_sustainability_goals($analytics) {
        $current_month = $analytics->generate_report('30days', 'array');
        $total_saved = $current_month['summary']['total_savings_g'];

        // Sample sustainability goals
        $monthly_goal = 1000; // 1000g CO2 savings goal
        $progress = min(100, ($total_saved / $monthly_goal) * 100);

        return "
        <h3>Sustainability Goals Progress</h3>
        <div style='margin: 20px 0;'>
            <h4>Monthly Carbon Savings Goal: {$monthly_goal}g CO₂</h4>
            <div style='background: #f1f1f1; border-radius: 10px; overflow: hidden; margin: 10px 0;'>
                <div style='background: #0f834d; height: 20px; width: {$progress}%; border-radius: 10px;'></div>
            </div>
            <p>Progress: <strong>" . number_format($total_saved, 2) . "g CO₂</strong> saved this month (" . number_format($progress, 1) . "% of goal)</p>
        </div>
        <div style='padding: 15px; background: #f8f9fa; border-radius: 5px; margin: 15px 0;'>
            <p><strong>Equivalent Impact:</strong></p>
            <ul>
                <li>🌱 " . number_format($total_saved / 21770, 4) . " trees worth of yearly CO₂ absorption</li>
                <li>🚗 " . number_format($total_saved / 120, 1) . " km of car driving avoided</li>
                <li>📱 " . number_format($total_saved / 8.4, 0) . " phone charges from renewable energy</li>
            </ul>
        </div>
        ";
    }

    /**
     * Generate custom report
     */
    private function generate_custom_report($type, $start_date, $end_date, $format, $analytics) {
        // This would implement custom report generation based on parameters
        return "<h3>Custom Report: " . ucfirst(str_replace('_', ' ', $type)) . "</h3><p>Custom report generation from {$start_date} to {$end_date} would be implemented here.</p>";
    }

    /**
     * AJAX handler for scheduling reports
     */
    public function ajax_schedule_report() {
        check_ajax_referer('grid_aware_reports', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $email = sanitize_email($_POST['email']);
        $weekly = isset($_POST['weekly_reports']) && $_POST['weekly_reports'] === 'true';
        $monthly = isset($_POST['monthly_reports']) && $_POST['monthly_reports'] === 'true';

        update_option('grid_aware_report_email', $email);
        update_option('grid_aware_weekly_reports', $weekly ? 'yes' : 'no');
        update_option('grid_aware_monthly_reports', $monthly ? 'yes' : 'no');

        wp_send_json_success('Settings saved successfully');
    }

    /**
     * Schedule automated reports
     */
    public function schedule_automated_reports() {
        if (!wp_next_scheduled('grid_aware_weekly_report')) {
            wp_schedule_event(time(), 'weekly', 'grid_aware_weekly_report');
        }

        if (!wp_next_scheduled('grid_aware_monthly_report')) {
            wp_schedule_event(time(), 'monthly', 'grid_aware_monthly_report');
        }
    }

    /**
     * Send weekly report
     */
    public function send_weekly_report() {
        if (get_option('grid_aware_weekly_reports', 'no') !== 'yes') {
            return;
        }

        $email = get_option('grid_aware_report_email', get_option('admin_email'));

        if (class_exists('Grid_Aware_Analytics')) {
            $analytics = Grid_Aware_Analytics::get_instance();
            $report = $analytics->generate_report('7days', 'array');

            $subject = 'Weekly Carbon Footprint Report - ' . get_bloginfo('name');
            $message = $this->format_email_report($report, 'weekly');

            wp_mail($email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
        }
    }

    /**
     * Send monthly report
     */
    public function send_monthly_report() {
        if (get_option('grid_aware_monthly_reports', 'no') !== 'yes') {
            return;
        }

        $email = get_option('grid_aware_report_email', get_option('admin_email'));

        if (class_exists('Grid_Aware_Analytics')) {
            $analytics = Grid_Aware_Analytics::get_instance();
            $report = $analytics->generate_report('30days', 'array');

            $subject = 'Monthly Sustainability Report - ' . get_bloginfo('name');
            $message = $this->format_email_report($report, 'monthly');

            wp_mail($email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
        }
    }

    /**
     * Format email report
     */
    private function format_email_report($report, $period) {
        $summary = $report['summary'];

        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #0f834d;'>🌱 " . ucfirst($period) . " Carbon Footprint Report</h2>
            <h3>Summary</h3>
            <ul>
                <li><strong>Total Page Views:</strong> " . number_format($summary['total_page_views']) . "</li>
                <li><strong>Carbon Footprint:</strong> " . number_format($summary['total_carbon_g'], 3) . "g CO₂</li>
                <li><strong>Carbon Saved:</strong> " . number_format($summary['total_savings_g'], 3) . "g CO₂</li>
                <li><strong>Reduction:</strong> " . $summary['savings_percentage'] . "%</li>
            </ul>

            <h3>Environmental Impact Equivalent</h3>
            <ul>
                <li>🌳 " . $summary['equivalent_metrics']['trees_planted'] . " trees planted</li>
                <li>🚗 " . number_format($summary['equivalent_metrics']['km_driven'], 1) . " km driving avoided</li>
                <li>📱 " . number_format($summary['equivalent_metrics']['phone_charges']) . " phone charges</li>
            </ul>

            <p style='margin-top: 30px;'>
                <a href='" . admin_url('admin.php?page=grid-aware-analytics') . "'
                   style='background: #0f834d; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>
                   View Full Analytics Dashboard
                </a>
            </p>

            <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
            <p style='font-size: 12px; color: #666;'>
                This report was automatically generated by the Grid-Aware WordPress plugin.
                <br>Site: " . get_bloginfo('name') . " (" . home_url() . ")
            </p>
        </body>
        </html>
        ";
    }
}
