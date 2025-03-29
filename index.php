<?php
/**
 * Grid-Aware Server-Side Optimizations
 */

class Grid_Aware_Server {
    // Carbon intensity threshold for eco mode (in gCO2/kWh)
    const ECO_THRESHOLD = 200;

    // Carbon intensity threshold for super eco mode (in gCO2/kWh)
    const SUPER_ECO_THRESHOLD = 350;

    // Current grid intensity
    private $current_intensity = null;

    // Current mode (standard, eco, super-eco)
    private $current_mode = 'standard';

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
        // Initialize session
        add_action('init', array($this, 'init_session'), 1);

        // Setup hooks based on current intensity
        add_action('init', array($this, 'setup_optimization_hooks'), 2);

        // Register admin settings
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Initialize session and determine current mode
     */
    public function init_session() {
        if (!session_id() && !headers_sent()) {
            session_start([
                'cookie_lifetime' => 86400, // 24 hours
                'read_and_close'  => false,  // We need write access
            ]);
        }

        // Determine current intensity and mode
        $this->determine_current_mode();
    }

    /**
     * Determine current mode based on session data or API
     */
    private function determine_current_mode() {
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
            // Fetch new data - using transient not session
            $data = $this->get_grid_data();
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
    private function check_forced_mode() {
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
     * Get grid data from API or cache
     */
    private function get_grid_data() {
        // Reusing your existing function
        if (function_exists('grid_aware_get_data')) {
            return grid_aware_get_data();
        }

        // Fallback implementation
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

        // API call logic similar to your existing function
        $url = add_query_arg(
            array('zone' => $zone),
            'https://api.electricitymap.org/v3/carbon-intensity/latest'
        );

        $response = wp_remote_get($url, ['headers' => ['auth-token' => $api_key]]);

        if (is_wp_error($response)) {
            return ['error' => 'API request failed'];
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

    /**
     * Setup optimization hooks based on current mode
     */
    public function setup_optimization_hooks() {
        // Always add the body class for CSS targeting
        add_filter('body_class', array($this, 'add_body_class'));

        // Always register the cookie for JavaScript awareness
        add_action('wp_head', array($this, 'add_grid_mode_cookie_script'), 1);

        // Skip optimizations if we're in standard mode
        if ($this->current_mode === 'standard') {
            return;
        }

        // Common optimizations for both eco and super-eco modes
        if (get_option('grid_aware_optimize_images', 'yes') === 'yes') {
            // 1. First, determine if we should use alt text boxes
            $alt_text_mode = get_option('grid_aware_alt_text_mode', 'disabled');
            $use_alt_text = false;

            if ($alt_text_mode === 'eco-and-super-eco' ||
                ($alt_text_mode === 'super-eco-only' && $this->current_mode === 'super-eco')) {
                $use_alt_text = true;
                add_filter('the_content', array($this, 'replace_images_with_alt_text'), 999);
                add_filter('post_thumbnail_html', array($this, 'replace_images_with_alt_text'), 999);
                add_filter('woocommerce_product_get_image', array($this, 'replace_images_with_alt_text'), 999);

                // ACF fields
                if (function_exists('acf_add_filter')) {
                    acf_add_filter('acf/format_value/type=image', array($this, 'process_acf_image'), 20, 3);
                }
            }
            // 2. If not using alt text boxes, check if we should use tiny placeholders
            else {
                $tiny_placeholders = get_option('grid_aware_tiny_placeholders', 'yes');
                $tiny_placeholders_mode = get_option('grid_aware_tiny_placeholders_mode', 'super-eco-only');

                $use_tiny_placeholders = false;

                if ($tiny_placeholders === 'yes') {
                    if ($tiny_placeholders_mode === 'eco-and-super-eco' ||
                        ($tiny_placeholders_mode === 'super-eco-only' && $this->current_mode === 'super-eco')) {
                        $use_tiny_placeholders = true;
                    }
                }

                // 3. Only apply tiny placeholders if enabled
                if ($use_tiny_placeholders) {
                    // This filter handles the WP image attributes for media library images
                    add_filter('wp_get_attachment_image_attributes', array($this, 'modify_image_attributes'), 10, 3);

                    // This filter handles inline content images
                    add_filter('the_content', array($this, 'process_content_images'), 999);

                    // Add the JS for progressive loading
                    add_action('wp_footer', array($this, 'add_tiny_image_script'), 20);
                }
                // 4. Otherwise just add lazy loading
                else {
                    if (get_option('grid_aware_lazy_load', 'yes') === 'yes') {
                        add_filter('wp_get_attachment_image_attributes', array($this, 'add_lazy_loading'), 10, 3);
                        add_filter('the_content', array($this, 'add_lazy_loading_to_content_images'), 999);
                    }
                }
            }
        }

        if (get_option('grid_aware_defer_non_essential', 'yes') === 'yes') {
            add_action('wp_enqueue_scripts', array($this, 'defer_non_essential_assets'), 999);
        }

        // Super-eco specific optimizations
        if ($this->current_mode === 'super-eco') {
            if (get_option('grid_aware_text_only_mode', 'no') === 'yes') {
                add_filter('the_content', array($this, 'text_only_mode'), 1000);
            }

            if (get_option('grid_aware_optimize_video', 'yes') === 'yes') {
                add_filter('embed_oembed_html', array($this, 'modify_video_embeds'), 10, 4);
                add_filter('video_embed_html', array($this, 'modify_video_embeds_basic'), 10);
            }
        }
    }

    /**
     * Add JavaScript for tiny image progressive loading
     */
    public function add_tiny_image_script() {
        ?>
        <script>
        (function() {
            // Track processed images to avoid duplicating the work
            var processedImages = [];

            // Progressive image loading
            function progressiveImageLoading() {
                var lazyImages = document.querySelectorAll('.grid-aware-tiny-image:not(.grid-aware-processed)');
                console.log('Grid-Aware: Found ' + lazyImages.length + ' tiny images to progressively load');

                if (lazyImages.length === 0) return;

                if ('IntersectionObserver' in window) {
                    var imageObserver = new IntersectionObserver(function(entries, observer) {
                        entries.forEach(function(entry) {
                            if (entry.isIntersecting) {
                                var img = entry.target;

                                // Skip already processed images
                                if (processedImages.includes(img) || img.classList.contains('grid-aware-processed')) {
                                    observer.unobserve(img);
                                    return;
                                }

                                // Mark as processed to avoid duplicate work
                                img.classList.add('grid-aware-processed');
                                processedImages.push(img);

                                var fullSrc = img.getAttribute('data-full-src');

                                if (fullSrc) {
                                    console.log('Grid-Aware: Loading full image: ' + fullSrc);

                                    // Load the full image
                                    var tempImg = new Image();
                                    tempImg.onload = function() {
                                        img.src = fullSrc;
                                        img.classList.add('grid-aware-loaded');

                                        // Restore srcset and sizes if they exist
                                        if (img.getAttribute('data-full-srcset')) {
                                            img.setAttribute('srcset', img.getAttribute('data-full-srcset'));
                                        }
                                        if (img.getAttribute('data-full-sizes')) {
                                            img.setAttribute('sizes', img.getAttribute('data-full-sizes'));
                                        }
                                    };
                                    tempImg.onerror = function() {
                                        console.error('Grid-Aware: Failed to load image: ' + fullSrc);
                                        // If loading fails, still add the class to prevent repeated attempts
                                        img.classList.add('grid-aware-loaded');
                                    };
                                    tempImg.src = fullSrc;
                                }

                                observer.unobserve(img);
                            }
                        });
                    }, {
                        rootMargin: '200px 0px' // Start loading when image is 200px from viewport
                    });

                    lazyImages.forEach(function(lazyImage) {
                        imageObserver.observe(lazyImage);
                    });
                } else {
                    // Fallback for browsers without IntersectionObserver
                    lazyImages.forEach(function(img) {
                        img.classList.add('grid-aware-processed');
                        processedImages.push(img);

                        var fullSrc = img.getAttribute('data-full-src');
                        if (fullSrc) {
                            setTimeout(function() {
                                img.src = fullSrc;
                                img.classList.add('grid-aware-loaded');

                                // Restore srcset and sizes if they exist
                                if (img.getAttribute('data-full-srcset')) {
                                    img.setAttribute('srcset', img.getAttribute('data-full-srcset'));
                                }
                                if (img.getAttribute('data-full-sizes')) {
                                    img.setAttribute('sizes', img.getAttribute('data-full-sizes'));
                                }
                            }, 1000);
                        }
                    });
                }
            }

            // Initialize on page load
            if (document.readyState === 'complete') {
                progressiveImageLoading();
            } else {
                window.addEventListener('load', progressiveImageLoading);
            }

            // Also initialize on DOMContentLoaded
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(progressiveImageLoading, 300);
            });

            // Check periodically for new images (e.g., added by JavaScript)
            setInterval(progressiveImageLoading, 2000);
        })();
        </script>
        <?php
    }

    /**
     * Process ACF image fields
     */
    public function process_acf_image($value, $post_id, $field) {
        if (is_array($value) && isset($value['url']) && isset($value['alt'])) {
            // Store the original value
            $value['original'] = $value;

            // Flag this as processed by our plugin
            $value['grid_aware_processed'] = true;

            // Add information about the current mode
            $value['grid_aware_mode'] = $this->current_mode;
            $value['grid_aware_intensity'] = $this->current_intensity;
        }

        return $value;
    }

        /**
     * Replace images with their alt text in styled boxes
     */
    public function replace_images_with_alt_text($content) {
        // Skip if no images
        if (strpos($content, '<img') === false) {
            return $content;
        }

        // Special handling for Gutenberg blocks
        if (function_exists('parse_blocks') && has_blocks($content)) {
            $blocks = parse_blocks($content);
            $modified_blocks = $this->process_blocks_recursively($blocks);
            $new_content = '';

            foreach ($modified_blocks as $block) {
                $new_content .= render_block($block);
            }

            return $new_content;
        }

        // Replace all image tags with alt text boxes for non-Gutenberg content
        $content = preg_replace_callback('/<img[^>]+>/', function($matches) {
            return $this->create_alt_text_box_from_image($matches[0]);
        }, $content);

        return $content;
    }

        /**
     * Process blocks recursively to handle nested blocks
     */
    private function process_blocks_recursively($blocks) {
        foreach ($blocks as &$block) {
            // Process image blocks
            if ($block['blockName'] === 'core/image') {
                $block = $this->process_image_block($block);
            }

            // Process gallery blocks
            else if ($block['blockName'] === 'core/gallery') {
                $block = $this->process_gallery_block($block);
            }

            // Process media-text blocks
            else if ($block['blockName'] === 'core/media-text') {
                $block = $this->process_media_text_block($block);
            }

            // Process HTML in all blocks to catch manual <img> tags
            if (!empty($block['innerHTML']) && strpos($block['innerHTML'], '<img') !== false) {
                $block['innerHTML'] = preg_replace_callback('/<img[^>]+>/', function($matches) {
                    return $this->create_alt_text_box_from_image($matches[0]);
                }, $block['innerHTML']);
            }

            // Process innerBlocks recursively
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->process_blocks_recursively($block['innerBlocks']);
            }
        }

        return $blocks;
    }

        /**
     * Process a core/image block
     */
    private function process_image_block($block) {
        // Extract the img tag from the block
        if (preg_match('/<img[^>]+>/', $block['innerHTML'], $img_matches)) {
            $img_tag = $img_matches[0];
            $alt_text_box = $this->create_alt_text_box_from_image($img_tag);

            // Replace just the img tag, preserving the block structure
            $block['innerHTML'] = str_replace($img_tag, $alt_text_box, $block['innerHTML']);
        }

        return $block;
    }

    /**
     * Process a core/gallery block
     */
    private function process_gallery_block($block) {
        // Process all images in gallery
        $block['innerHTML'] = preg_replace_callback('/<img[^>]+>/', function($matches) {
            return $this->create_alt_text_box_from_image($matches[0]);
        }, $block['innerHTML']);

        return $block;
    }

    /**
     * Process a core/media-text block
     */
    private function process_media_text_block($block) {
        // Extract the img tag from the media side
        if (preg_match('/<figure[^>]*>.*?<img[^>]+>.*?<\/figure>/s', $block['innerHTML'], $figure_matches)) {
            $figure_tag = $figure_matches[0];

            if (preg_match('/<img[^>]+>/', $figure_tag, $img_matches)) {
                $img_tag = $img_matches[0];
                $alt_text_box = $this->create_alt_text_box_from_image($img_tag);

                // Replace just the img tag, preserving the figure structure
                $new_figure = str_replace($img_tag, $alt_text_box, $figure_tag);
                $block['innerHTML'] = str_replace($figure_tag, $new_figure, $block['innerHTML']);
            }
        }

        return $block;
    }


    /**
     * Create an alt text box from an image tag
     */
    private function create_alt_text_box_from_image($img_tag) {
        // Extract alt text
        preg_match('/alt=[\'"]([^\'"]*)[\'"]/', $img_tag, $alt_matches);
        $alt_text = !empty($alt_matches[1]) ? $alt_matches[1] : 'Image';

        // Extract classes
        preg_match('/class=[\'"]([^\'"]*)[\'"]/', $img_tag, $class_matches);
        $classes = !empty($class_matches[1]) ? $class_matches[1] : '';

        // Get original src for optional JS enhancement
        preg_match('/src=[\'"]([^\'"]*)[\'"]/', $img_tag, $src_matches);
        $src = !empty($src_matches[1]) ? $src_matches[1] : '';

        // Build box with the alt text
        $box = '<div class="grid-aware-alt-text-box ' . $classes . '" data-full-src="' . esc_attr($src) . '">';
        $box .= '<span class="grid-aware-alt-text">' . esc_html($alt_text) . '</span>';
        $box .= '<span class="grid-aware-mode-label">' . esc_html($this->current_mode) . ' mode</span>';
        $box .= '</div>';

        // Make sure there's also a style for alt text boxes
        add_action('wp_head', function() {
            ?>
            <style>
                .grid-aware-alt-text-box {
                    background-color: #f5f5f5;
                    border: 1px solid #ddd;
                    padding: 15px;
                    margin: 10px 0;
                    text-align: center;
                    position: relative;
                    min-height: 100px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-direction: column;
                }

                .grid-aware-alt-text {
                    font-size: 14px;
                    line-height: 1.5;
                    color: #333;
                    max-width: 600px;
                }

                .grid-aware-mode-label {
                    position: absolute;
                    top: 5px;
                    right: 5px;
                    font-size: 10px;
                    background: <?php echo $this->current_mode === 'eco' ? '#ff9e01' : '#b32d2e'; ?>;
                    color: white;
                    padding: 2px 5px;
                    border-radius: 2px;
                }
            </style>
            <?php
        }, 10);

        return $box;
    }

    /**
     * Add grid-aware mode body class
     */
    public function add_body_class($classes) {
        $classes[] = 'grid-aware-' . $this->current_mode;
        return $classes;
    }

    /**
     * Add a script that sets a cookie with the current grid mode
     * This allows client-side scripts to be aware of the mode
     */
    public function add_grid_mode_cookie_script() {
        ?>
        <script>
            // Set grid mode cookie for client-side awareness
            document.cookie = "gridAwareMode=<?php echo esc_js($this->current_mode); ?>;path=/;max-age=3600";
            document.cookie = "gridAwareIntensity=<?php echo esc_js($this->current_intensity); ?>;path=/;max-age=3600";
        </script>

        <!-- Add styles for tiny images -->
        <style>
            /* Tiny image styling */
            .grid-aware-tiny-image {
                filter: blur(6px);
                transition: filter 0.5s ease-in-out;
                position: relative;
                /* Make sure the tiny image fills its container */
                width: 100%;
                height: 100%;
                object-fit: cover; /* This is key to ensuring the image fills the area */
                display: block;
            }

            /* Remove the min-height setting that can cause layout issues */
            .grid-aware-tiny-image:not(img) {
                min-height: 100px;
            }

            .grid-aware-tiny-image.grid-aware-loaded {
                filter: blur(0);
            }

            /* Better positioning for the badge */
            .grid-aware-eco .grid-aware-tiny-image::before,
            .grid-aware-super-eco .grid-aware-tiny-image::before {
                content: "Low-res image • <?php echo esc_js($this->current_mode); ?> mode";
                position: absolute;
                top: 5px;
                right: 5px;
                background: <?php echo $this->current_mode === 'eco' ? '#ff9e01' : '#b32d2e'; ?>;
                color: white;
                font-size: 10px;
                padding: 2px 5px;
                z-index: 10;
                opacity: 0.9;
                border-radius: 2px;
                pointer-events: none; /* Prevents the badge from blocking clicks */
            }

            /* Hide alt text during loading by setting text color to transparent */
            .grid-aware-tiny-image:not(.grid-aware-loaded) {
                color: transparent;
                font-size: 0; /* Extra measure to hide alt text */
            }

            /* Container styles for proper positioning */
            figure.wp-block-image,
            .wp-block-image figure {
                position: relative;
                margin: 0;
                padding: 0;
            }

            /* Loading animation */
            @keyframes gridAwarePulse {
                0% { opacity: 0.6; }
                50% { opacity: 0.8; }
                100% { opacity: 0.6; }
            }

            .grid-aware-tiny-image:not(.grid-aware-loaded) {
                animation: gridAwarePulse 1.5s infinite;
            }

            /* Handle figure captions properly */
            figcaption {
                color: inherit !important; /* Reset any color changes from parent */
                font-size: inherit !important; /* Reset any font size changes from parent */
            }
        </style>
        <?php
    }

    /**
     * Modify image attributes to use low-quality or placeholder versions
     */
    public function modify_image_attributes($attr, $attachment, $size) {
        // Skip small images
        $metadata = wp_get_attachment_metadata($attachment->ID);
        if (isset($metadata['width']) && $metadata['width'] < 100) {
            return $attr;
        }

        // Always add lazy loading if enabled
        if (get_option('grid_aware_lazy_load', 'yes') === 'yes') {
            $attr['loading'] = 'lazy';
        }

        // Check if tiny placeholders should be applied for the current mode
        $tiny_placeholders = get_option('grid_aware_tiny_placeholders', 'yes');
        $tiny_placeholders_mode = get_option('grid_aware_tiny_placeholders_mode', 'super-eco-only');

        $use_tiny_placeholders = false;

        if ($tiny_placeholders === 'yes') {
            if ($tiny_placeholders_mode === 'eco-and-super-eco' ||
                ($tiny_placeholders_mode === 'super-eco-only' && $this->current_mode === 'super-eco')) {
                $use_tiny_placeholders = true;
            }
        }

        // Apply tiny placeholders if enabled for the current mode
        if ($use_tiny_placeholders) {
            // Store original attributes for JS enhancement
            $attr['data-full-src'] = $attr['src'];

            // Move srcset and sizes to data attributes
            if (isset($attr['srcset'])) {
                $attr['data-full-srcset'] = $attr['srcset'];
                unset($attr['srcset']);
            }

            if (isset($attr['sizes'])) {
                $attr['data-full-sizes'] = $attr['sizes'];
                unset($attr['sizes']);
            }

            // Generate tiny image
            $tiny_data = $this->get_tiny_data_uri($attr['src']);
            $attr['src'] = $tiny_data;

            // Add special class for JS enhancement
            $attr['class'] = isset($attr['class']) ? $attr['class'] . ' grid-aware-tiny-image' : 'grid-aware-tiny-image';
        }

        return $attr;
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
     * Process images in content
     */
    public function process_content_images($content) {
        // Skip processing if no images
        if (strpos($content, '<img') === false) {
            return $content;
        }

        // Apply lazy loading if enabled
        if (get_option('grid_aware_lazy_load', 'yes') === 'yes') {
            $content = preg_replace('/(<img[^>]+)>/i', '$1 loading="lazy">', $content);
        }

        // Check if tiny placeholders should be applied for the current mode
        $tiny_placeholders = get_option('grid_aware_tiny_placeholders', 'yes');
        $tiny_placeholders_mode = get_option('grid_aware_tiny_placeholders_mode', 'super-eco-only');

        $use_tiny_placeholders = false;

        if ($tiny_placeholders === 'yes') {
            if ($tiny_placeholders_mode === 'eco-and-super-eco' ||
                ($tiny_placeholders_mode === 'super-eco-only' && $this->current_mode === 'super-eco')) {
                $use_tiny_placeholders = true;
            }
        }

        // Apply tiny placeholders if enabled for the current mode
        if ($use_tiny_placeholders) {
            $content = preg_replace_callback('/<img([^>]+)>/i', function($matches) {
                $img_attributes = $matches[1];

                // Skip if already processed
                if (strpos($img_attributes, 'data-full-src') !== false) {
                    return $matches[0];
                }

                // Extract the src attribute
                preg_match('/src=[\'"]([^\'"]+)[\'"]/', $img_attributes, $src_matches);
                if (empty($src_matches)) {
                    return $matches[0]; // No src attribute, return unchanged
                }

                $src = $src_matches[1];

                // Store original src as data-full-src
                $img_attributes = preg_replace('/src=[\'"]([^\'"]+)[\'"]/', 'data-full-src="$1"', $img_attributes);

                // Store srcset if it exists
                if (preg_match('/srcset=[\'"]([^\'"]+)[\'"]/', $img_attributes, $srcset_matches)) {
                    $srcset = $srcset_matches[1];
                    $img_attributes = preg_replace('/srcset=[\'"]([^\'"]+)[\'"]/', 'data-full-srcset="$1"', $img_attributes);
                }

                // Store sizes if it exists
                if (preg_match('/sizes=[\'"]([^\'"]+)[\'"]/', $img_attributes, $sizes_matches)) {
                    $sizes = $sizes_matches[1];
                    $img_attributes = preg_replace('/sizes=[\'"]([^\'"]+)[\'"]/', 'data-full-sizes="$1"', $img_attributes);
                }

                // Generate tiny placeholder
                $tiny_src = $this->get_tiny_data_uri($src);

                // Add grid-aware-tiny-image class
                if (strpos($img_attributes, 'class=') !== false) {
                    $img_attributes = preg_replace('/class=[\'"]([^\'"]+)[\'"]/', 'class="$1 grid-aware-tiny-image"', $img_attributes);
                } else {
                    $img_attributes .= ' class="grid-aware-tiny-image"';
                }

                // Build the new img tag
                return '<img' . $img_attributes . ' src="' . $tiny_src . '">';
            }, $content);
        }

        return $content;
    }

   /**
     * Generate a tiny data URI for an image
     */
    private function get_tiny_data_uri($src) {
        // Default gray placeholder
        $default = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 10 10\'%3E%3Crect width=\'10\' height=\'10\' fill=\'%23eee\'/%3E%3C/svg%3E';

        // Debug info
        error_log('Grid-Aware: Attempting to generate tiny image from: ' . $src);

        // Skip SVGs
        if (strpos($src, '.svg') !== false) {
            error_log('Grid-Aware: Skipping SVG file');
            return $default;
        }

        // Better external URL detection
        $is_external = true;
        $site_url = site_url();
        $parsed_site = parse_url($site_url);
        $parsed_src = parse_url($src);

        // Check if hostname part is within our domain OR a local development domain
        if (isset($parsed_src['host']) && isset($parsed_site['host'])) {
            $host = $parsed_src['host'];
            $site_host = $parsed_site['host'];

            // Check for exact match
            if ($host === $site_host) {
                $is_external = false;
            }
            // Check for .ddev.site domains (DDEV)
            else if (strpos($host, '.ddev.site') !== false && strpos($site_host, '.ddev.site') !== false) {
                $is_external = false;
            }
            // Check for .local domains (Local by Flywheel)
            else if (strpos($host, '.local') !== false && strpos($site_host, '.local') !== false) {
                $is_external = false;
            }
            // Check for localhost
            else if (($host === 'localhost' || $host === '127.0.0.1') &&
                     ($site_host === 'localhost' || $site_host === '127.0.0.1')) {
                $is_external = false;
            }
        }

        if ($is_external) {
            error_log('Grid-Aware: URL is external: ' . $src);
            error_log('Grid-Aware: Site URL: ' . $site_url);
            return $default;
        }

        // WordPress attachments can be processed more efficiently
        $attachment_id = $this->get_attachment_id_from_url($src);
        if ($attachment_id) {
            error_log('Grid-Aware: Found attachment ID: ' . $attachment_id);

            // Try to get the thumbnail directly first
            $tiny_img = wp_get_attachment_image_src($attachment_id, array(20, 20));
            if ($tiny_img && !empty($tiny_img[0])) {
                error_log('Grid-Aware: Using WordPress thumbnail: ' . $tiny_img[0]);

                // Get the image data and convert to data URI
                $img_data = $this->get_remote_file_contents($tiny_img[0]);
                if ($img_data) {
                    $img_type = wp_check_filetype($tiny_img[0])['type'];
                    error_log('Grid-Aware: Generated data URI from thumbnail: ' . print_r($img_data, true));
                    return 'data:' . $img_type . ';base64,' . base64_encode($img_data);
                }
            }
        }

        // Try to get the image directly
        $img_data = $this->get_remote_file_contents($src);
        if ($img_data) {
            try {
                // Create image from string
                $image = @imagecreatefromstring($img_data);
                if ($image) {
                    error_log('Grid-Aware: Successfully created image from string');

                    // Determine image dimensions
                    $width = imagesx($image);
                    $height = imagesy($image);
                    $ratio = $width / $height;

                    $new_width = 30; // Slightly larger for better visual preview
                    $new_height = round($new_width / $ratio);

                    $tiny = imagecreatetruecolor($new_width, $new_height);

                    // Resample
                    imagecopyresampled($tiny, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

                    // Output as data URI
                    ob_start();
                    imagejpeg($tiny, null, 50); // Medium quality for better preview
                    $base64 = base64_encode(ob_get_clean());

                    // Free memory
                    imagedestroy($image);
                    imagedestroy($tiny);

                    error_log('Grid-Aware: Successfully generated tiny data URI');
                    return 'data:image/jpeg;base64,' . $base64;
                } else {
                    error_log('Grid-Aware: Failed to create image from string');
                }
            } catch (Exception $e) {
                error_log('Grid-Aware: Error generating tiny image: ' . $e->getMessage());
            }
        } else {
            error_log('Grid-Aware: Failed to get remote file contents from: ' . $src);
        }

        error_log('Grid-Aware: Falling back to default placeholder');
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

        // Fallback to cURL
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress/Grid-Aware-Plugin');
            $contents = curl_exec($ch);
            curl_close($ch);
            if ($contents) {
                return $contents;
            }
        }

        return false;
    }

    /**
     * Get WordPress attachment ID from URL
     */
    private function get_attachment_id_from_url($url) {
        // Remove any image size from the URL
        $url = preg_replace('/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url);

        // Remove query string
        $url = strtok($url, '?');

        // Try to get the attachment ID
        global $wpdb;
        $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $url));

        // If not found directly, try to match based on the file name
        if (empty($attachment)) {
            $upload_dir = wp_upload_dir();
            $file_path = str_replace($upload_dir['baseurl'] . '/', '', $url);

            $attachment = $wpdb->get_col($wpdb->prepare("
                SELECT post_id FROM $wpdb->postmeta
                WHERE meta_key='_wp_attached_file' AND meta_value='%s'
            ", $file_path));
        }

        return !empty($attachment[0]) ? $attachment[0] : 0;
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
     * Modify content for text-only mode
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
     * Modify basic video embeds
     */
    public function modify_video_embeds_basic($html) {
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
     * Add lazy loading to content images (only)
     */
    public function add_lazy_loading_to_content_images($content) {
        // Skip if no images
        if (strpos($content, '<img') === false) {
            return $content;
        }

        // Add loading=lazy to all content images
        $content = preg_replace('/(<img[^>]+)>/i', '$1 loading="lazy">', $content);

        return $content;
    }

    /**
     * Register admin settings
     */
    public function register_settings() {
        register_setting('grid_aware_options', 'grid_aware_optimize_images');
        register_setting('grid_aware_options', 'grid_aware_lazy_load');
        register_setting('grid_aware_options', 'grid_aware_defer_non_essential');
        register_setting('grid_aware_options', 'grid_aware_essential_scripts');
        register_setting('grid_aware_options', 'grid_aware_essential_styles');
        register_setting('grid_aware_options', 'grid_aware_enable_super_eco');
        register_setting('grid_aware_options', 'grid_aware_text_only_mode');
        register_setting('grid_aware_options', 'grid_aware_tiny_placeholders');
        register_setting('grid_aware_options', 'grid_aware_optimize_video');
    }
}

// Initialize
Grid_Aware_Server::get_instance();

/**
 * Grid-Aware Admin Settings
 */

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

        // Add admin notice if mode is forced
        add_action('admin_notices', array($this, 'maybe_show_forced_mode_notice'));

        // Add admin bar menu item
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 999);

        // Add AJAX handler for tiny image testing
        add_action('wp_ajax_grid_aware_test_tiny_image', array($this, 'ajax_test_tiny_image'));

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

        // Generate tiny version
        $server = Grid_Aware_Server::get_instance();
        $tiny_url = $server->get_tiny_data_uri($image_url);

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
     * Add admin bar menu item
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options') || is_admin()) {
            return;
        }

        $mode = isset($_SESSION['grid_aware_mode']) ? $_SESSION['grid_aware_mode'] : 'unknown';
        $intensity = isset($_SESSION['grid_aware_intensity']) ? $_SESSION['grid_aware_intensity'] : null;
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
            'href'  => admin_url('options-general.php?page=grid-aware-settings')
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
                'href'   => admin_url('options-general.php?page=grid-aware-settings')
            ));
        }
    }
        /**
     * Display admin notice if mode is being forced
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
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=grid-aware-settings#debug-settings')); ?>">
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
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Grid-Aware Settings',
            'Grid-Aware',
            'manage_options',
            'grid-aware-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // API Settings
        register_setting('grid_aware_api_options', 'grid_aware_api_key');
        register_setting('grid_aware_api_options', 'grid_aware_zone');

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
        register_setting('grid_aware_advanced_options', 'grid_aware_tiny_placeholders_mode');
        register_setting('grid_aware_advanced_options', 'grid_aware_optimize_video');

        // Debug/Preview Settings
        register_setting('grid_aware_debug_options', 'grid_aware_force_mode');

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
            'grid_aware_optimize_video',
            'Optimize Video Embeds',
            array($this, 'render_optimize_video_field'),
            'grid_aware_advanced_options',
            'grid_aware_advanced_section'
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Get current grid intensity if available
        $intensity = isset($_SESSION['grid_aware_intensity']) ? $_SESSION['grid_aware_intensity'] : null;
        $mode = isset($_SESSION['grid_aware_mode']) ? $_SESSION['grid_aware_mode'] : 'unknown';
        $forced = isset($_SESSION['grid_aware_forced']) && $_SESSION['grid_aware_forced'];

        ?>
        <div class="wrap">
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
        echo '<p class="description">Default zone code (e.g., SE for Sweden) if geolocation fails</p>';
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
        echo '<p class="description">Comma-separated list of script handles that should not be deferred</p>';
    }

    public function render_essential_styles_field() {
        $styles = get_option('grid_aware_essential_styles', '');
        echo '<input type="text" name="grid_aware_essential_styles" value="' . esc_attr($styles) . '" class="regular-text">';
        echo '<p class="description">Comma-separated list of style handles that should not be deferred</p>';
    }

    public function render_super_eco_field() {
        $super_eco = get_option('grid_aware_enable_super_eco', 'yes');
        echo '<select name="grid_aware_enable_super_eco">';
        echo '<option value="yes" ' . selected($super_eco, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($super_eco, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Enable additional optimizations during high carbon intensity periods</p>';
    }

    public function render_text_only_field() {
        $text_only = get_option('grid_aware_text_only_mode', 'no');
        echo '<select name="grid_aware_text_only_mode">';
        echo '<option value="yes" ' . selected($text_only, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($text_only, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Replace images with alt text during Super-Eco mode (extreme)</p>';
    }

    public function render_tiny_placeholders_field() {
        $tiny_placeholders = get_option('grid_aware_tiny_placeholders', 'yes');
        $tiny_placeholders_mode = get_option('grid_aware_tiny_placeholders_mode', 'super-eco-only');

        echo '<div class="grid-aware-field-group">';

        // Enable/disable option
        echo '<div class="grid-aware-option">';
        echo '<select name="grid_aware_tiny_placeholders">';
        echo '<option value="yes" ' . selected($tiny_placeholders, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($tiny_placeholders, 'no', false) . '>No</option>';
        echo '</select>';
        echo '</div>';

        // When to apply
        echo '<div class="grid-aware-option">';
        echo '<select name="grid_aware_tiny_placeholders_mode">';
        echo '<option value="super-eco-only" ' . selected($tiny_placeholders_mode, 'super-eco-only', false) . '>Super-Eco Mode Only</option>';
        echo '<option value="eco-and-super-eco" ' . selected($tiny_placeholders_mode, 'eco-and-super-eco', false) . '>Eco and Super-Eco Modes</option>';
        echo '</select>';
        echo '</div>';

        echo '</div>';

        echo '<p class="description">Use extremely small (30px wide) versions of images to save bandwidth. Images appear blurry but maintain their visual content.</p>';

        echo '<div class="grid-aware-info-box">';
        echo '<p><strong>How it works:</strong> This feature creates tiny versions of your images and applies a blur effect. This dramatically reduces data transfer (up to 99%) while still providing a visual preview of the content.</p>';
        echo '<p>For example, a 500KB image might be reduced to just 1-2KB, saving significant energy across your site.</p>';

        // Add debug test button - only show in debug mode
        if (isset($_GET['debug'])) {
            echo '<div class="test-tiny-image" style="margin-top: 15px;">';
            echo '<p><strong>Test Image Generation:</strong></p>';
            echo '<input type="text" id="test-image-url" placeholder="Enter an image URL from your site" style="width: 300px;">';
            echo '<button type="button" id="test-tiny-button" class="button">Generate Tiny Preview</button>';
            echo '<div id="tiny-image-result" style="margin-top: 10px;"></div>';

            echo '<script>
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
                                nonce: "' . wp_create_nonce('grid_aware_test_tiny') . '"
                            },
                            success: function(response) {
                                if (response.success) {
                                    var html = "<p>Original:</p>";
                                    html += "<img src=\"" + imageUrl + "\" style=\"max-width: 200px; max-height: 200px;\">";
                                    html += "<p>Tiny version:</p>";
                                    html += "<img src=\"" + response.data.tiny_url + "\" style=\"max-width: 200px; max-height: 200px;\">";
                                    html += "<p>Size reduction: " + response.data.size_reduction + "%</p>";
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
            </script>';
            echo '</div>';
        }

        echo '</div>';

        // Add some simple styles for the info box and option layout
        echo '<style>
            .grid-aware-info-box {
                background: #f8f8f8;
                border-left: 4px solid #00a0d2;
                padding: 10px 15px;
                margin: 10px 0;
                max-width: 600px;
            }
            .grid-aware-field-group {
                display: flex;
                gap: 10px;
                align-items: center;
                margin-bottom: 5px;
            }
            .grid-aware-option {
                margin-right: 10px;
            }
        </style>';
    }

    public function render_optimize_video_field() {
        $optimize_video = get_option('grid_aware_optimize_video', 'yes');
        echo '<select name="grid_aware_optimize_video">';
        echo '<option value="yes" ' . selected($optimize_video, 'yes', false) . '>Yes</option>';
        echo '<option value="no" ' . selected($optimize_video, 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Replace video embeds with click-to-load placeholders</p>';
    }

    /**
     * Show DDEV debug notice
     */
    public function show_ddev_debug_notice() {
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
     * Get default placeholder
     */
    private function get_default_placeholder() {
        return 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 10 10\'%3E%3Crect width=\'10\' height=\'10\' fill=\'%23eee\'/%3E%3C/svg%3E';
    }
}

// Initialize
Grid_Aware_Admin::get_instance();