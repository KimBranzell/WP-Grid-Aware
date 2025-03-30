<?php
/**
 * API integration for Grid Aware plugin
 */
class Grid_Aware_API extends Grid_Aware_Base {
    /**
     * Constructor
     */
    protected function __construct() {
        parent::__construct();
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
}