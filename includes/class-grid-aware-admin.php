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

        // Add AJAX handler for tiny image testing
        add_action('wp_ajax_grid_aware_test_tiny_image', array($this, 'ajax_test_tiny_image'));
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

        // You could also add sub-menu items like this if needed
        /*
        add_submenu_page(
            'grid-aware-settings',       // Parent slug
            'Grid-Aware Advanced',       // Page title
            'Advanced Settings',         // Menu title
            'manage_options',            // Capability
            'grid-aware-advanced',       // Menu slug
            array($this, 'render_advanced_page') // Callback
        );
        */
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
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our settings page
        if ($hook != 'toplevel_page_grid-aware-settings') {
            return;
        }

        // Add admin CSS if needed
        wp_enqueue_style(
            'grid-aware-admin',
            GRID_AWARE_URL . 'assets/css/admin.css',
            array(),
            GRID_AWARE_VERSION
        );

        // Add admin JS if needed
        wp_enqueue_script(
            'grid-aware-admin',
            GRID_AWARE_URL . 'assets/js/admin.js',
            array('jquery'),
            GRID_AWARE_VERSION,
            true
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
}