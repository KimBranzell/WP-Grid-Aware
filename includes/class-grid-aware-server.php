<?php
/**
 * Grid-Aware Server Class
 * Handles server-side optimizations based on current grid intensity
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class Grid_Aware_Server extends Grid_Aware_Base {
    // Singleton instance
    protected static $instance = null;

    // Carbon intensity threshold for eco mode (in gCO2/kWh)
    const ECO_THRESHOLD = 200;

    // Carbon intensity threshold for super eco mode (in gCO2/kWh)
    const SUPER_ECO_THRESHOLD = 350;

    // Current grid intensity
    protected $current_intensity = null;

    // Current mode (standard, eco, super-eco)
    protected $current_mode = 'standard';

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
     * Get current mode
     *
     * @return string The current mode (standard, eco, super-eco)
     */
    public function get_current_mode() {
        return $this->current_mode;
    }

    /**
     * Get current intensity
     *
     * @return float|null The current grid intensity or null if not available
     */
    public function get_current_intensity() {
        return $this->current_intensity;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Initialize and determine current mode
        $this->determine_current_mode();

        // Setup hooks based on current intensity
        $this->setup_optimization_hooks();

        // Debug information
        add_action('wp_footer', array($this, 'add_debug_info'));
    }

    /**
     * Setup optimization hooks based on current mode
     */
    private function setup_optimization_hooks() {
        // Always add the body class for CSS targeting
        add_filter('body_class', array($this, 'add_body_class'));

        // Always add grid mode information to the page
        add_action('wp_head', array($this, 'add_grid_mode_meta'), 1);

        // Skip optimizations if we're in standard mode
        if ($this->current_mode === 'standard') {
            Grid_Aware_Base::log("Standard mode active - no optimizations applied");
            return;
        }

        Grid_Aware_Base::log("{$this->current_mode} mode active - applying optimizations");

        // Common optimizations for both eco and super-eco modes
        if (get_option('grid_aware_optimize_images', 'yes') === 'yes') {
            // Image optimizations
            add_filter('wp_get_attachment_image_attributes', array($this, 'modify_image_attributes'), 10, 3);
            add_filter('the_content', array($this, 'process_content_images'), 999);
            add_filter('post_thumbnail_html', array($this, 'process_thumbnail_html'), 10, 5);
        }

        if (get_option('grid_aware_defer_non_essential', 'yes') === 'yes') {
            // Script optimizations
            add_action('wp_enqueue_scripts', array($this, 'defer_non_essential_assets'), 999);
        }

        // Super-eco specific optimizations
        if ($this->current_mode === 'super-eco') {
            if (get_option('grid_aware_text_only_mode', 'no') === 'yes') {
                // Replace images with alt text in super-eco mode
                add_filter('the_content', array($this, 'text_only_mode'), 1000);
            }

            if (get_option('grid_aware_optimize_video', 'yes') === 'yes') {
                // Video optimizations
                add_filter('embed_oembed_html', array($this, 'modify_video_embeds'), 10, 4);
                add_filter('video_embed_html', array($this, 'modify_video_embeds_simple'), 10);
            }
        }
    }

    /**
     * Add grid-aware mode body class
     */
    public function add_body_class($classes) {
        $classes[] = 'grid-aware-mode-' . $this->current_mode;
        return $classes;
    }

    /**
     * Add grid mode meta data to page head
     */
    public function add_grid_mode_meta() {
        echo "<!-- Grid-Aware Mode: {$this->current_mode} ({$this->current_intensity} gCO2/kWh) -->\n";
        echo "<meta name=\"grid-aware-mode\" content=\"{$this->current_mode}\">\n";
        echo "<meta name=\"grid-aware-intensity\" content=\"{$this->current_intensity}\">\n";

        // Add a script that sets a cookie with the current grid mode for client-side awareness
        ?>
        <script>
            // Set grid mode cookie for client-side awareness
            document.cookie = "gridAwareMode=<?php echo esc_js($this->current_mode); ?>;path=/;max-age=3600";
            document.cookie = "gridAwareIntensity=<?php echo esc_js($this->current_intensity); ?>;path=/;max-age=3600";
        </script>
        <?php
    }

    /**
     * Modify image attributes to use lazy loading and tiny placeholders
     */
    public function modify_image_attributes($attr, $attachment, $size) {
        // Skip small images
        $metadata = wp_get_attachment_metadata($attachment->ID);
        if (isset($metadata['width']) && $metadata['width'] < 100) {
            return $attr;
        }

        // Check if the current mode should use tiny placeholders
        $use_tiny_placeholders = $this->should_use_tiny_placeholders();

        // Add lazy loading if enabled
        if (get_option('grid_aware_lazy_load', 'yes') === 'yes') {
            $attr['loading'] = 'lazy';
        }

        // Use tiny placeholder in appropriate mode
        if ($use_tiny_placeholders) {
            // Store original in data attribute for optional JS enhancement
            $attr['data-full-src'] = $attr['src'];

            // Generate tiny placeholder
            $tiny_url = $this->get_tiny_image_url($attachment->ID, $attr['src']);
            $attr['src'] = $tiny_url;

            // Add special class
            $attr['class'] = isset($attr['class']) ? $attr['class'] . ' grid-aware-tiny-image' : 'grid-aware-tiny-image';
        }

        return $attr;
    }

    /**
     * Process images in content
     */
    public function process_content_images($content) {
        // Skip processing if no images or in standard mode
        if (strpos($content, '<img') === false) {
            return $content;
        }

        // In super-eco text-only mode, replace images with alt text
        if ($this->current_mode === 'super-eco' && get_option('grid_aware_text_only_mode', 'no') === 'yes') {
            $intensity = $this->current_intensity;

            $content = preg_replace_callback('/<img[^>]+>/', function($matches) use ($intensity) {
                $img_tag = $matches[0];

                // Extract alt text
                preg_match('/alt=[\'"]([^\'"]*)[\'"]/', $img_tag, $alt_matches);
                $alt_text = !empty($alt_matches[1]) ? $alt_matches[1] : '[Image]';

                // Extract classes
                preg_match('/class=[\'"]([^\'"]*)[\'"]/', $img_tag, $class_matches);
                $classes = !empty($class_matches[1]) ? $class_matches[1] : '';

                // Get original src for optional JS enhancement
                preg_match('/src=[\'"]([^\'"]*)[\'"]/', $img_tag, $src_matches);
                $src = !empty($src_matches[1]) ? $src_matches[1] : '';

                // Determine image type from the src if possible
                $image_type = '[Image]';
                if (strpos($src, '.jpg') !== false || strpos($src, '.jpeg') !== false) {
                    $image_type = '[Photo]';
                } elseif (strpos($src, '.png') !== false) {
                    $image_type = '[Graphic]';
                } elseif (strpos($src, '.svg') !== false) {
                    $image_type = '[Vector graphic]';
                } elseif (strpos($src, '.gif') !== false) {
                    $image_type = '[Animated image]';
                }

                // Build an enhanced alt text box
                $box = '<div class="grid-aware-alt-text-box ' . esc_attr($classes) . '" data-full-src="' . esc_attr($src) . '" tabindex="0" role="img" aria-label="' . esc_attr($alt_text) . '">';

                // Image icon
                $box .= '<div class="grid-aware-image-icon" aria-hidden="true">📷</div>';

                // Alt text description
                $box .= '<span class="grid-aware-image-type">' . esc_html($image_type) . '</span>';
                $box .= '<span class="grid-aware-alt-text">' . esc_html($alt_text) . '</span>';

                // Eco information
                $box .= '<span class="grid-aware-eco-info" aria-hidden="true">Image not loaded to reduce carbon impact</span>';
                $box .= '<span class="grid-aware-intensity-value" aria-hidden="true">Current grid intensity: ' . esc_html($intensity) . ' gCO2/kWh</span>';

                // Show image button (optional)
                $box .= '<button class="grid-aware-show-image" data-src="' . esc_attr($src) . '">Show Image</button>';

                // Mode label
                $box .= '<span class="grid-aware-mode-label">Super-Eco Mode</span>';

                $box .= '</div>';

                return $box;
            }, $content);

            return $content;
        }

        // Check if we should use tiny placeholders
        $use_tiny_placeholders = $this->should_use_tiny_placeholders();

        // Add loading=lazy to all content images if enabled
        if (get_option('grid_aware_lazy_load', 'yes') === 'yes') {
            $content = preg_replace('/(<img[^>]+)(?!loading=)>/i', '$1 loading="lazy">', $content);
        }

        // Use tiny placeholders if appropriate
        if ($use_tiny_placeholders) {
            $content = preg_replace_callback('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', function($matches) {
                $img_tag = $matches[0];
                $src = $matches[1];

                // Skip if already processed
                if (strpos($img_tag, 'data-full-src') !== false) {
                    return $img_tag;
                }

                // Store original source
                $img_tag = str_replace('src="' . $src . '"', 'src="' . $src . '" data-full-src="' . $src . '"', $img_tag);

                // Generate or use tiny version
                $tiny_src = $this->get_tiny_data_uri($src);
                $img_tag = str_replace('src="' . $src . '"', 'src="' . $tiny_src . '"', $img_tag);

                // Add class
                if (strpos($img_tag, 'class="') !== false) {
                    $img_tag = preg_replace('/class="([^"]+)"/', 'class="$1 grid-aware-tiny-image"', $img_tag);
                } else {
                    $img_tag = str_replace('<img ', '<img class="grid-aware-tiny-image" ', $img_tag);
                }

                return $img_tag;
            }, $content);
        }

        return $content;
    }

    /**
     * Process post thumbnail HTML
     */
    public function process_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if (empty($html)) {
            return $html;
        }

        // In super-eco text-only mode, replace images with alt text
        if ($this->current_mode === 'super-eco' && get_option('grid_aware_text_only_mode', 'no') === 'yes') {
            // Get alt text from attachment
            $alt_text = get_post_meta($post_thumbnail_id, '_wp_attachment_image_alt', true);
            if (empty($alt_text)) {
                $alt_text = get_the_title($post_thumbnail_id);
            }
            if (empty($alt_text)) {
                $alt_text = '[Featured Image]';
            }

            // Get original src
            $src = wp_get_attachment_url($post_thumbnail_id);
            $intensity = $this->current_intensity;

            // Build enhanced alt text box for featured image
            $box = '<div class="grid-aware-alt-text-box grid-aware-thumbnail-placeholder" data-full-src="' . esc_attr($src) . '" tabindex="0" role="img" aria-label="' . esc_attr($alt_text) . '">';

            // Alt text description
            $box .= '<span class="grid-aware-image-type">[Featured Image]</span>';
            $box .= '<span class="grid-aware-alt-text">' . esc_html($alt_text) . '</span>';

            // Eco information
            $box .= '<span class="grid-aware-eco-info" aria-hidden="true">Image not loaded to reduce carbon impact</span>';
            $box .= '<span class="grid-aware-intensity-value" aria-hidden="true">Current grid intensity: ' . esc_html($intensity) . ' gCO2/kWh</span>';

            // Show image button
            $box .= '<button class="grid-aware-show-image" data-src="' . esc_attr($src) . '">Show Image</button>';

            // Mode label
            $box .= '<span class="grid-aware-mode-label">Super-Eco Mode</span>';

            $box .= '</div>';

            return $box;
        }

        // For other cases, process the HTML like content images
        return $this->process_content_images($html);
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
        if (isset($wp_scripts) && $wp_scripts instanceof WP_Scripts) {
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
        }

        // Get list of essential styles
        $essential_styles = explode(',', get_option('grid_aware_essential_styles', ''));
        $essential_styles = array_map('trim', $essential_styles);

        // Add media="print" onload technique for non-essential styles
        if (isset($wp_styles) && $wp_styles instanceof WP_Styles) {
            foreach ($wp_styles->registered as $handle => $style) {
                if (!in_array($handle, $essential_styles)) {
                    // Store original media
                    $original_media = isset($style->args) ? $style->args : 'all';

                    // Use print media and add onload handler
                    $wp_styles->add_data($handle, 'media', 'print');
                    add_filter('style_loader_tag', function($tag, $handle_check, $href, $media) use ($handle, $original_media) {
                        if ($handle === $handle_check) {
                            return str_replace(
                                "media='print'",
                                "media='print' onload=\"this.media='" . esc_attr($original_media) . "'\"",
                                $tag
                            );
                        }
                        return $tag;
                    }, 10, 4);
                }
            }
        }
    }

    /**
     * Text-only mode for super-eco
     */
    public function text_only_mode($content) {
        // Replace iframes with placeholders
        $content = preg_replace_callback('/<iframe[^>]*><\/iframe>/', function($matches) {
            return '<div class="grid-aware-iframe-placeholder">[Embedded content hidden to save energy. Click to load.]</div>';
        }, $content);

        return $content;
    }

    /**
     * Modify video embeds
     */
    public function modify_video_embeds($html, $url, $attr, $post_id) {
        // Simply add a click-to-load wrapper
        return $this->create_video_placeholder($html, $url);
    }

    /**
     * Modify simple video embeds
     */
    public function modify_video_embeds_simple($html) {
        return $this->create_video_placeholder($html, '');
    }

    /**
     * Create a video placeholder
     */
    private function create_video_placeholder($html, $url) {
        // Extract video title or use generic message
        $title = '';
        if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
            $title = 'YouTube Video';
        } elseif (strpos($url, 'vimeo.com') !== false) {
            $title = 'Vimeo Video';
        } else {
            $title = 'Video';
        }

        // Store the original embed code
        $encoded_html = esc_attr($html);

        // Create placeholder
        return '<div class="grid-aware-video-placeholder" data-video-embed="' . $encoded_html . '">
            <div class="grid-aware-video-message">
                <p>' . esc_html($title) . '</p>
                <p>Video loading delayed to reduce carbon impact (current intensity: ' .
                    esc_html($this->current_intensity) . ' gCO2/kWh)</p>
                <button class="grid-aware-load-video">Load Video</button>
            </div>
        </div>';
    }

    /**
     * Get a tiny version of an image
     */
    private function get_tiny_image_url($attachment_id, $fallback) {
        // Try to get a very small thumbnail
        $tiny_image = wp_get_attachment_image_src($attachment_id, array(20, 20));

        if ($tiny_image) {
            return $tiny_image[0];
        }

        return $fallback;
    }

    /**
     * Generate a tiny data URI for an image
     */
    private function get_tiny_data_uri($src) {
        // Default gray placeholder
        $default = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 10 10\'%3E%3Crect width=\'10\' height=\'10\' fill=\'%23eee\'/%3E%3C/svg%3E';

        // For local URLs, try to create a tiny version
        if (strpos($src, site_url()) === 0) {
            // Convert URL to path
            $path = str_replace(site_url('/'), ABSPATH, $src);

            if (file_exists($path)) {
                try {
                    // Check if we can use the GD library
                    if (extension_loaded('gd') && function_exists('imagecreatetruecolor')) {
                        $image = null;

                        // Create image based on file type
                        if (preg_match('/\.jpe?g$/i', $path)) {
                            $image = @imagecreatefromjpeg($path);
                        } elseif (preg_match('/\.png$/i', $path)) {
                            $image = @imagecreatefrompng($path);
                        } elseif (preg_match('/\.gif$/i', $path)) {
                            $image = @imagecreatefromgif($path);
                        }

                        if ($image) {
                            // Create tiny version
                            $width = imagesx($image);
                            $height = imagesy($image);
                            $ratio = $width / $height;

                            $new_width = 10;
                            $new_height = round($new_width / $ratio);

                            $tiny = imagecreatetruecolor($new_width, $new_height);
                            imagecopyresampled($tiny, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

                            // Output as data URI
                            ob_start();
                            imagejpeg($tiny, null, 20);
                            $base64 = base64_encode(ob_get_clean());

                            // Free memory
                            imagedestroy($image);
                            imagedestroy($tiny);

                            return 'data:image/jpeg;base64,' . $base64;
                        }
                    }
                } catch (Exception $e) {
                    // Fallback to default
                    Grid_Aware_Base::log('Error creating tiny image: ' . $e->getMessage(), 'error');
                }
            }
        }

        return $default;
    }

    /**
     * Determine if tiny placeholders should be used based on settings and current mode
     */
    private function should_use_tiny_placeholders() {
        // Check if tiny placeholders are enabled
        if (get_option('grid_aware_tiny_placeholders', 'yes') !== 'yes') {
            return false;
        }

        // Check the mode setting
        $mode_setting = get_option('grid_aware_tiny_placeholders_mode', 'super-eco-only');

        if ($mode_setting === 'super-eco-only') {
            return $this->current_mode === 'super-eco';
        } else { // all-eco-modes
            return $this->current_mode === 'eco' || $this->current_mode === 'super-eco';
        }
    }

    /**
     * Add debug info to footer
     */
    public function add_debug_info() {
        if (current_user_can('manage_options') || (defined('WP_DEBUG') && WP_DEBUG)) {
            echo '<!-- Grid-Aware Debug Info -->' . "\n";
            echo '<!-- Current Mode: ' . esc_html($this->current_mode) . ' -->' . "\n";
            echo '<!-- Current Intensity: ' . esc_html($this->current_intensity) . ' gCO2/kWh -->' . "\n";
            echo '<!-- Text-Only Mode Setting: ' . esc_html(get_option('grid_aware_text_only_mode', 'no')) . ' -->' . "\n";
            echo '<!-- Should Use Tiny Placeholders: ' . ($this->should_use_tiny_placeholders() ? 'Yes' : 'No') . ' -->' . "\n";

            // Add visible indicator for admins
            if (current_user_can('manage_options')) {
                echo '<div id="grid-aware-debug" style="position:fixed; bottom:10px; right:10px; background:rgba(0,0,0,0.7); color:#fff; padding:10px; font-size:12px; z-index:9999; border-radius:3px;">';
                echo 'Grid: <strong>' . esc_html(ucfirst($this->current_mode)) . '</strong> (' . esc_html($this->current_intensity) . ' gCO2/kWh)<br>';
                echo 'Text-Only: <strong>' . (get_option('grid_aware_text_only_mode', 'no') === 'yes' ? 'Enabled' : 'Disabled') . '</strong>';
                echo '</div>';
            }
        }
    }
}

// Do not instantiate directly - it will be called from the main plugin file