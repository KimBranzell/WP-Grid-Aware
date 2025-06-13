<?php
/**
 * Video optimization module
 */
class Grid_Aware_Video_Optimizer extends Grid_Aware_Base {
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
        add_filter('embed_oembed_html', array($this, 'modify_video_embeds'), 10, 4);
        add_filter('video_embed_html', array($this, 'modify_video_embeds_basic'), 10);
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
                    esc_html($this->intensity) . ' gCO2/kWh)</p>
                <button class="grid-aware-load-video">Load Video</button>
            </div>
        </div>';
    }
}