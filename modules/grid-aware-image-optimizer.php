<?php
/**
 * Image optimization module
 */
class Grid_Aware_Image_Optimizer extends Grid_Aware_Base {
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

        // Setup hooks based on settings and mode
        $this->setup_hooks();
    }

    /**
     * Setup module hooks
     */
    private function setup_hooks() {
        // Check which features are enabled
        $alt_text_mode = get_option('grid_aware_alt_text_mode', 'disabled');
        $use_alt_text = false;

        if ($alt_text_mode === 'eco-and-super-eco' ||
            ($alt_text_mode === 'super-eco-only' && $this->mode === 'super-eco')) {
            $use_alt_text = true;
            add_filter('the_content', array($this, 'replace_images_with_alt_text'), 999);
            add_filter('post_thumbnail_html', array($this, 'replace_images_with_alt_text'), 999);
            add_filter('woocommerce_product_get_image', array($this, 'replace_images_with_alt_text'), 999);

            // ACF fields
            if (function_exists('acf_add_filter')) {
                acf_add_filter('acf/format_value/type=image', array($this, 'process_acf_image'), 20, 3);
            }
        } else {
            $tiny_placeholders = get_option('grid_aware_tiny_placeholders', 'yes');
            $tiny_placeholders_mode = get_option('grid_aware_tiny_placeholders_mode', 'super-eco-only');

            $use_tiny_placeholders = false;

            if ($tiny_placeholders === 'yes') {
                if ($tiny_placeholders_mode === 'eco-and-super-eco' ||
                    ($tiny_placeholders_mode === 'super-eco-only' && $this->mode === 'super-eco')) {
                    $use_tiny_placeholders = true;
                }
            }

            if ($use_tiny_placeholders) {
                // Apply tiny placeholders
                add_filter('wp_get_attachment_image_attributes', array($this, 'modify_image_attributes'), 10, 3);
                add_filter('the_content', array($this, 'process_content_images'), 999);
                add_action('wp_footer', array($this, 'add_tiny_image_script'), 20);
                add_action('wp_head', array($this, 'add_tiny_image_styles'), 20);
            } else if (get_option('grid_aware_lazy_load', 'yes') === 'yes') {
                // Just add lazy loading
                add_filter('wp_get_attachment_image_attributes', array($this, 'add_lazy_loading'), 10, 3);
                add_filter('the_content', array($this, 'add_lazy_loading_to_content_images'), 999);
            }
        }
    }

    // Add the rest of your image optimization methods here...
    // Function stubs for the remaining methods:

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

        // Skip SVGs
        if (strpos($src, '.svg') !== false) {
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
            return $default;
        }

        // WordPress attachments can be processed more efficiently
        $attachment_id = $this->get_attachment_id_from_url($src);
        if ($attachment_id) {

            // Try to get the thumbnail directly first
            $tiny_img = wp_get_attachment_image_src($attachment_id, array(20, 20));
            if ($tiny_img && !empty($tiny_img[0])) {

                // Get the image data and convert to data URI
                $img_data = $this->get_remote_file_contents($tiny_img[0]);
                if ($img_data) {
                    $img_type = wp_check_filetype($tiny_img[0])['type'];
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

                    return 'data:image/jpeg;base64,' . $base64;
                } else {
                }
            } catch (Exception $e) {
                error_log('Grid-Aware: Error generating tiny image: ' . $e->getMessage());
            }
        } else {
            error_log('Grid-Aware: Failed to get image data from URL: ' . $src);
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
    public function add_lazy_loading($attr, $attachment, $size) { /* Implementation */ }

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
    public function add_tiny_image_styles() { /* Implementation */ }
}