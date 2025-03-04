<?php
/**
 * Plugin Name: Grid Aware WP
 * Description: A simple WordPress plugin to check grid intensity using Electricity Maps API with geolocation support.
 * Version: 0.2
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add settings menu
function grid_aware_add_menu() {
    add_options_page('Grid Aware Settings', 'Grid Aware', 'manage_options', 'grid-aware-settings', 'grid_aware_settings_page');
}
add_action('admin_menu', 'grid_aware_add_menu');

// Register settings
function grid_aware_register_settings() {
    register_setting('grid_aware_options', 'grid_aware_api_key');
    register_setting('grid_aware_options', 'grid_aware_zone');
}
add_action('admin_init', 'grid_aware_register_settings');

// Settings page
function grid_aware_settings_page() {
    ?>
    <div class="wrap">
        <h1>Grid Aware Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('grid_aware_options'); ?>
            <?php do_settings_sections('grid_aware_options'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td><input type="text" name="grid_aware_api_key" value="<?php echo esc_attr(get_option('grid_aware_api_key')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Zone (Fallback Country Code)</th>
                    <td><input type="text" name="grid_aware_zone" value="<?php echo esc_attr(get_option('grid_aware_zone', 'DE')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Fetch grid intensity data
function grid_aware_get_data($lat = null, $lon = null) {
    $api_key = get_option('grid_aware_api_key');
    $zone = get_option('grid_aware_zone', 'SE');

    if (!$api_key) {
        return ['error' => 'API key is missing'];
    }

    $transient_key = 'grid_aware_data_' . ($lat && $lon ? "{$lat}_{$lon}" : $zone);
    $cached_data = get_transient($transient_key);
    if ($cached_data) {
        return $cached_data;
    }

    $url = $lat && $lon ?
    add_query_arg(
      array(
          'lat' => $lat,
          'lon' => $lon
      ),
          'https://api.electricitymap.org/v3/carbon-intensity/latest'
      ) :
      add_query_arg(
          array(
              'zone' => $zone
          ),
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

    set_transient($transient_key, $data, 10 * MINUTE_IN_SECONDS);
    return $data;
}

// Shortcode to display grid-aware message with geolocation
function grid_aware_shortcode() {
    ob_start();
    ?>
    <div class="grid-status" class="eco-mode">
        <span id="grid-aware-loading" style="display: none;">Loading grid data...</span>
        <span id="grid-aware-status">Eco Mode 🌱</span>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const statusElement = document.getElementById("grid-aware-status");
        const loadingElement = document.getElementById("grid-aware-loading");
        const parentElements = document.getElementsByClassName("grid-status");

        function fetchGridData(lat, lon) {
            console.log('Fetching grid data:', lat, lon);
            fetch("<?php echo esc_url(admin_url('admin-ajax.php?action=grid_aware_ajax')); ?>&lat=" + lat + "&lon=" + lon)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        statusElement.innerHTML = "Error: " + data.error;
                    } else {
                        const intensity = data.carbonIntensity;
                        const message = intensity < 200 ? 'Eco Mode 🌱' : 'High Carbon Mode ⚡';
                        statusElement.innerHTML = `${intensity} gCO2/kWh - ${message}`;
                        className = intensity < 200 ? 'eco-mode' : 'high-carbon-mode'
                        Array.from(parentElements).forEach(element => {
                            element.classList.add(className);
                        });
                        localStorage.setItem('gridAwareData', JSON.stringify(data));
                    }
                    loadingElement.style.display = "none"; // Hide loading message
                    statusElement.style.display = "block"; // Show the status element with the fetched data
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        // Check if data is cached
        const cachedData = localStorage.getItem('gridAwareData');
        if (cachedData) {
            console.table(cachedData);
            const data = JSON.parse(cachedData);
            const intensity = data.carbonIntensity;
            const message = intensity < 200 ? 'Eco Mode 🌱' : 'High Carbon Mode ⚡';
            className = intensity < 200 ? 'eco-mode' : 'high-carbon-mode'
            Array.from(parentElements).forEach(element => {
                element.classList.add(className);
            });
            statusElement.innerHTML = `${message}`;

            statusElement.style.display = "block";
        } else {
            console.log('Fetching new data');
            // Show loading message if data is not cached
            let loadingTimeout = setTimeout(() => {
                statusElement.style.display = "block";
            }, 500); // Show loading message after 500ms

            fetch('https://ipapi.co/json/')
                .then(response => response.json())
                .then(data => {
                    fetchGridData(data.latitude, data.longitude);
                })
                .catch(() => {
                    // Fallback to zone-based lookup if IP geolocation fails
                    fetchGridData(null, null);
                })
                .finally(() => {
                    clearTimeout(loadingTimeout); // Clear the timeout if data is fetched before 500ms
                });
        }
    });
</script>
    <?php
    return ob_get_clean();
}
add_shortcode('grid_aware', 'grid_aware_shortcode');

// AJAX handler for geolocation-based fetching
function grid_aware_ajax() {
    $lat = isset($_GET['lat']) ? sanitize_text_field($_GET['lat']) : null;
    $lon = isset($_GET['lon']) ? sanitize_text_field($_GET['lon']) : null;

    wp_send_json(grid_aware_get_data($lat, $lon));
}
add_action('wp_ajax_nopriv_grid_aware_ajax', 'grid_aware_ajax');
add_action('wp_ajax_grid_aware_ajax', 'grid_aware_ajax');
