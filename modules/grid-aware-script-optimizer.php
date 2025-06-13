<?php
/**
 * Script optimization module
 */
class Grid_Aware_Script_Optimizer extends Grid_Aware_Base {
    // Current mode and intensity
    private $mode;
    private $intensity;

    /**
     * Constructor
     */
    protected function __construct() {
        // No parent constructor needed for modules
    }

    /**
     * Initialize the module with current mode and intensity
     */
    public function initialize($mode, $intensity) {
        $this->mode = $mode;
        $this->intensity = $intensity;

        // Setup hooks
        add_action('wp_enqueue_scripts', array($this, 'defer_non_essential_assets'), 999);
    }

    /**
     * Defer non-essential assets
     */
    public function defer_non_essential_assets() {
        global $wp_scripts, $wp_styles;

        // Get list of essential scripts
        $essential_scripts = explode(',', get_option('grid_aware_essential_scripts', 'jquery'));
        $essential_scripts = array_map('trim', $essential_scripts);

        // Add defer attribute to non-essential scripts
        foreach ($wp_scripts->registered as $handle => $script) {
            if (!in_array($handle, $essential_scripts)) {
                if (isset($script->extra['type']) && $script->extra['type'] === 'module') {
                    // Already using type="module", don't add defer
                    continue;
                }

                // Add defer attribute
                $wp_scripts->add_data($handle, 'defer', true);
            }
        }

        // Get list of essential styles
        $essential_styles = explode(',', get_option('grid_aware_essential_styles', ''));
        $essential_styles = array_map('trim', $essential_styles);

        // Add media="print" onload technique for non-essential styles
        foreach ($wp_styles->registered as $handle => $style) {
            if (!in_array($handle, $essential_styles)) {
                // Store original media
                $original_media = isset($style->args) ? $style->args : 'all';

                // Use preload + onload technique
                $wp_styles->add_data($handle, 'media', 'print');
                $wp_styles->add_data($handle, 'onload', "this.media='" . $original_media . "'");
            }
        }
    }

    /**
     * Check if a script is essential
     */
    private function is_essential_script($handle) {
        $essential_scripts = explode(',', get_option('grid_aware_essential_scripts', 'jquery'));
        $essential_scripts = array_map('trim', $essential_scripts);
        return in_array($handle, $essential_scripts);
    }

    /**
     * Check if a style is essential
     */
    private function is_essential_style($handle) {
        $essential_styles = explode(',', get_option('grid_aware_essential_styles', ''));
        $essential_styles = array_map('trim', $essential_styles);
        return in_array($handle, $essential_styles);
    }
}