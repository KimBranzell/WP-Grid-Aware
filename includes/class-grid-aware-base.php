<?php
/**
 * Base abstract class for Grid Aware plugin
 */
abstract class Grid_Aware_Base {
    // Constants
    const ECO_THRESHOLD = 200;
    const SUPER_ECO_THRESHOLD = 350;

    // Singleton instance
    protected static $instance = null;

    // Common properties
    protected $current_mode = 'standard';
    protected $current_intensity = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === static::$instance) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * Constructor
     */
    protected function __construct() {
        // Initialize session
        add_action('init', array($this, 'init_session'), 1);

        // Determine current mode
        add_action('init', array($this, 'determine_current_mode'), 2);
    }

    /**
     * Initialize session
     */
    public function init_session() {
        if (!session_id() && !headers_sent()) {
            session_start([
                'cookie_lifetime' => 86400, // 24 hours
                'read_and_close'  => false,  // We need write access
            ]);
        }
    }

    /**
     * Plugin activation
     */
    public static function activate() {
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

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Cleanup tasks
        flush_rewrite_rules();
    }

    /**
     * Log a message to the error log
     *
     * @param string $message The message to log
     * @param string $level The log level (error, warning, info)
     */
    public static function log($message, $level = 'info') {
        // Only log in WP_DEBUG mode
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $prefix = 'Grid-Aware (' . $level . '): ';
        error_log($prefix . $message);
    }

    /**
     * Determine current mode based on session data or API
     */
    public function determine_current_mode() {
        if (current_user_can('manage_options') && isset($_COOKIE['grid_aware_test_mode'])) {
            $test_mode = $_COOKIE['grid_aware_test_mode'];
            if (in_array($test_mode, array('standard', 'eco', 'super-eco'))) {
                $this->current_mode = $test_mode;

                // Set a fake intensity value based on the mode
                switch ($test_mode) {
                    case 'standard':
                        $this->current_intensity = 150;
                        break;
                    case 'eco':
                        $this->current_intensity = 250;
                        break;
                    case 'super-eco':
                        $this->current_intensity = 400;
                        break;
                }

                Grid_Aware_Base::log("Using test mode: {$this->current_mode}");
                return; // Skip normal mode determination
            }
        }
        // Check for admin preview/force mode
        $forced_mode = $this->check_forced_mode();
        if ($forced_mode !== false) {
            $this->current_mode = $forced_mode;

            // Set a placeholder intensity value for the forced mode
            switch ($forced_mode) {
                case 'eco':
                    $this->current_intensity = self::ECO_THRESHOLD + 50; // Middle of eco range
                    break;
                case 'super-eco':
                    $this->current_intensity = self::SUPER_ECO_THRESHOLD + 50; // High carbon
                    break;
                default: // standard
                    $this->current_intensity = self::ECO_THRESHOLD - 50; // Low carbon
            }

            // Store in session
            $_SESSION['grid_aware_mode'] = $this->current_mode;
            $_SESSION['grid_aware_intensity'] = $this->current_intensity;
            $_SESSION['grid_aware_forced'] = true;

            return;
        }

        // Check if we have valid data in session
        if (isset($_SESSION['grid_aware_intensity']) &&
            isset($_SESSION['grid_aware_expires']) &&
            time() < $_SESSION['grid_aware_expires']) {

            $this->current_intensity = $_SESSION['grid_aware_intensity'];
        } else {
            // Fetch new data from API
            $api = Grid_Aware_API::get_instance();
            $data = $api->get_carbon_intensity();

            if (!isset($data['error']) && isset($data['carbonIntensity'])) {
                $this->current_intensity = $data['carbonIntensity'];

                // Update session
                $_SESSION['grid_aware_intensity'] = $this->current_intensity;
                $_SESSION['grid_aware_expires'] = time() +
                    (isset($data['expiresIn']) ? $data['expiresIn'] : 600);
            }
        }

        // Determine mode based on intensity
        if ($this->current_intensity !== null) {
            if ($this->current_intensity >= self::SUPER_ECO_THRESHOLD &&
                get_option('grid_aware_enable_super_eco', 'yes') === 'yes') {
                $this->current_mode = 'super-eco';
            } else if ($this->current_intensity >= self::ECO_THRESHOLD) {
                $this->current_mode = 'eco';
            } else {
                $this->current_mode = 'standard';
            }

            // Store mode in session
            $_SESSION['grid_aware_mode'] = $this->current_mode;

            // Remove forced flag if it exists
            if (isset($_SESSION['grid_aware_forced'])) {
                unset($_SESSION['grid_aware_forced']);
            }
        }
    }

    /**
     * Check if a mode is being forced for preview/testing
     *
     * @return string|false The forced mode or false if no forcing
     */
    protected function check_forced_mode() {
        // Check for query parameter first (temporary preview)
        if (isset($_GET['grid_preview']) && current_user_can('manage_options')) {
            $mode = sanitize_key($_GET['grid_preview']);
            if (in_array($mode, array('standard', 'eco', 'super-eco'))) {
                return $mode;
            }
        }

        // Check for admin setting (persistent force)
        $force_mode = get_option('grid_aware_force_mode', 'auto');
        if ($force_mode !== 'auto' && current_user_can('manage_options')) {
            return $force_mode;
        }

        return false;
    }

    /**
     * Add body class based on current mode
     */
    public function add_body_class($classes) {
        $classes[] = 'grid-aware-' . $this->current_mode;
        return $classes;
    }

    /**
     * Load a template file
     */
    protected function get_template($template_name, $args = array()) {
        $template_path = GRID_AWARE_PATH . 'templates/' . $template_name . '.php';

        if (file_exists($template_path)) {
            extract($args);
            include $template_path;
        }
    }

    /**
     * Check if running in a DDEV environment
     */
    protected function is_ddev() {
        return  (defined('IS_DDEV_PROJECT') && IS_DDEV_PROJECT) ||
                (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '.ddev.site') !== false) ||
                (isset($_ENV['DDEV_PROJECT']) && !empty($_ENV['DDEV_PROJECT']));
    }
}