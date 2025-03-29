<?php
// Add this near the top of your plugin file
function grid_aware_init_session() {
    if (!session_id() && !headers_sent()) {
        session_start([
            'cookie_lifetime' => 86400, // 24 hours
            'read_and_close'  => true,  // Read-only mode
        ]);
    }
}
add_action('init', 'grid_aware_init_session', 1);

// Fetch grid intensity data with smarter caching
function grid_aware_get_data($lat = null, $lon = null) {
    $api_key = get_option('grid_aware_api_key');
    $zone = get_option('grid_aware_zone', 'SE');

    if (!$api_key) {
        return ['error' => 'API key is missing'];
    }

    $transient_key = 'grid_aware_data_' . ($lat && $lon ? "{$lat}_{$lon}" : $zone);
    $cached_data = get_transient($transient_key);

    // Check if we have valid cached data
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

    // Calculate expiration time based on API timestamps
    $expiration_time = grid_aware_calculate_expiration($data);

    // Add local timestamp for client-side validation
    $data['localTimestamp'] = time();

    // Store in transient with dynamic expiration
    set_transient($transient_key, $data, $expiration_time);

    return $data;
}

// Calculate optimal cache expiration based on API data
function grid_aware_calculate_expiration($data) {
    // Default fallback - 10 minutes
    $default_expiration = 10 * MINUTE_IN_SECONDS;

    // If we have datetime and updatedAt fields, use them
    if (isset($data['datetime']) && isset($data['updatedAt'])) {
        try {
            // Parse the datetime (when the data is valid until)
            $datetime = new DateTime($data['datetime']);
            $now = new DateTime();

            if ($datetime > $now) {
                // Data is for future period - calculate seconds until it expires
                $seconds_until_expiry = $datetime->getTimestamp() - $now->getTimestamp();

                // Add a small buffer (30 seconds)
                return $seconds_until_expiry + 30;
            }
        } catch (Exception $e) {
            // If date parsing fails, use default
            return $default_expiration;
        }
    }

    return $default_expiration;
}

// AJAX handler for geolocation-based fetching
function grid_aware_ajax() {
    // Start session for writing
    if (!session_id()) {
        session_start();
    }

    $lat = isset($_GET['lat']) ? sanitize_text_field($_GET['lat']) : null;
    $lon = isset($_GET['lon']) ? sanitize_text_field($_GET['lon']) : null;

    $data = grid_aware_get_data($lat, $lon);

    // Store in session with expiration timestamp
    if (!isset($data['error'])) {
        $_SESSION['grid_aware_data'] = $data;
        $_SESSION['grid_aware_intensity'] = $data['carbonIntensity'];
        $_SESSION['grid_aware_mode'] = $data['carbonIntensity'] < 200 ? 'eco' : 'high';
        $_SESSION['grid_aware_updated'] = time();
        $_SESSION['grid_aware_expires'] = time() + grid_aware_calculate_expiration($data);
    }

    session_write_close(); // Close the session immediately after writing

    wp_send_json($data);
}
add_action('wp_ajax_nopriv_grid_aware_ajax', 'grid_aware_ajax');
add_action('wp_ajax_grid_aware_ajax', 'grid_aware_ajax');

// Shortcode to display grid-aware message with geolocation
function grid_aware_shortcode() {
    grid_aware_init_session(); // Ensure session is started (read-only)

    // Get session data if available
    $session_data = isset($_SESSION['grid_aware_data']) ? $_SESSION['grid_aware_data'] : null;
    $intensity = isset($_SESSION['grid_aware_intensity']) ? $_SESSION['grid_aware_intensity'] : null;
    $mode = isset($_SESSION['grid_aware_mode']) ? $_SESSION['grid_aware_mode'] : null;
    $expires = isset($_SESSION['grid_aware_expires']) ? $_SESSION['grid_aware_expires'] : 0;

    // Check if session data is valid (not expired)
    $session_valid = $mode && (time() < $expires);

    // Default class and message
    $class = $session_valid && $mode == 'eco' ? 'eco-mode' : ($session_valid ? 'high-carbon-mode' : '');
    $message = $session_valid ? ($mode == 'eco' ? 'Eco Mode 🌱' : 'High Carbon Mode ⚡') : 'Eco Mode 🌱';

    ob_start();
    ?>
    <div class="grid-status <?php echo esc_attr($class); ?>">
        <span id="grid-aware-loading" style="display: none;">Loading grid data...</span>
        <span id="grid-aware-status" data-session-valid="<?php echo $session_valid ? 'true' : 'false'; ?>">
            <?php echo $session_valid && $intensity ? "$intensity gCO2/kWh - $message" : $message; ?>
        </span>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const statusElement = document.getElementById("grid-aware-status");
        const loadingElement = document.getElementById("grid-aware-loading");
        const parentElements = document.getElementsByClassName("grid-status");
        const sessionValid = statusElement.dataset.sessionValid === 'true';

        // Debug session validity
        console.log('Session valid:', sessionValid);

        // Improved data validation function
        function isDataValid(data) {
            if (!data || typeof data !== 'object') {
                console.log('Cache invalid: No data or wrong type');
                return false;
            }

            if (!data.carbonIntensity) {
                console.log('Cache invalid: No carbonIntensity');
                return false;
            }

            try {
                // First check: API datetime (when data is valid until)
                if (data.datetime) {
                    const dataDatetime = new Date(data.datetime);
                    const now = new Date();

                    if (dataDatetime > now) {
                        console.log('Cache valid: API datetime is in the future');
                        return true;
                    } else {
                        console.log('Cache invalid: API datetime is in the past', dataDatetime, now);
                    }
                }

                // Second check: localTimestamp from server
                if (data.localTimestamp) {
                    const timestamp = data.localTimestamp * 1000; // Convert to milliseconds
                    const now = Date.now();
                    // Use a slightly more conservative expiration (30 minutes)
                    const thirtyMinutes = 30 * 60 * 1000;

                    if ((now - timestamp) < thirtyMinutes) {
                        console.log('Cache valid: localTimestamp is recent');
                        return true;
                    } else {
                        console.log('Cache invalid: localTimestamp too old', new Date(timestamp), new Date(now));
                    }
                }

                // Third check: clientTimestamp (backup)
                if (data.clientTimestamp) {
                    const timestamp = data.clientTimestamp; // Already in milliseconds
                    const now = Date.now();
                    const thirtyMinutes = 30 * 60 * 1000;

                    if ((now - timestamp) < thirtyMinutes) {
                        console.log('Cache valid: clientTimestamp is recent');
                        return true;
                    } else {
                        console.log('Cache invalid: clientTimestamp too old');
                    }
                }

                console.log('Cache invalid: No valid timestamp found');
                return false;
            } catch (e) {
                console.error('Error validating data:', e);
                return false;
            }
        }

        function fetchGridData(lat, lon) {
            loadingElement.style.display = "block";
            statusElement.style.display = "none";

            fetch("<?php echo esc_url(admin_url('admin-ajax.php?action=grid_aware_ajax')); ?>&lat=" + lat + "&lon=" + lon)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        statusElement.innerHTML = "Error: " + data.error;
                    } else {
                        const intensity = data.carbonIntensity;
                        const message = intensity < 200 ? 'Eco Mode 🌱' : 'High Carbon Mode ⚡';
                        statusElement.innerHTML = `${intensity} gCO2/kWh - ${message}`;

                        // Add client timestamp
                        data.clientTimestamp = Date.now();

                        // Update classes
                        Array.from(parentElements).forEach(element => {
                            element.classList.remove('eco-mode', 'high-carbon-mode');
                            element.classList.add(intensity < 200 ? 'eco-mode' : 'high-carbon-mode');
                        });

                        // Store in localStorage
                        try {
                            localStorage.setItem('gridAwareData', JSON.stringify(data));
                            console.log('Cached new data in localStorage');
                        } catch (e) {
                            console.error('Error storing data in localStorage:', e);
                        }
                    }
                    loadingElement.style.display = "none";
                    statusElement.style.display = "block";
                })
                .catch(error => {
                    console.error('Error fetching data:', error);
                    loadingElement.style.display = "none";
                    statusElement.style.display = "block";
                });
        }

        // Decision tree for data source
        if (sessionValid) {
            // Session data is valid, no need to do anything
            console.log('Using server-side session data');
        } else {
            // Try localStorage
            try {
                const storedData = localStorage.getItem('gridAwareData');

                // Debug localStorage
                console.log('Found localStorage data:', !!storedData);

                if (storedData) {
                    try {
                        const data = JSON.parse(storedData);

                        if (isDataValid(data)) {
                            console.log('Using localStorage data');
                            const intensity = data.carbonIntensity;
                            const message = intensity < 200 ? 'Eco Mode 🌱' : 'High Carbon Mode ⚡';
                            const className = intensity < 200 ? 'eco-mode' : 'high-carbon-mode';

                            statusElement.innerHTML = `${message}`;
                            Array.from(parentElements).forEach(element => {
                                element.classList.remove('eco-mode', 'high-carbon-mode');
                                element.classList.add(className);
                            });
                        } else {
                            fetchFreshData();
                        }
                    } catch (e) {
                        console.error('Error parsing localStorage data:', e);
                        fetchFreshData();
                    }
                } else {
                    fetchFreshData();
                }
            } catch (e) {
                console.error('Error accessing localStorage:', e);
                fetchFreshData();
            }
        }

        function fetchFreshData() {
            console.log('Fetching new data');
            loadingElement.style.display = "block";

            fetch('https://ipapi.co/json/')
                .then(response => {
                    if (!response.ok) throw new Error('Geolocation API failed');
                    return response.json();
                })
                .then(data => {
                    if (data && data.latitude && data.longitude) {
                        fetchGridData(data.latitude, data.longitude);
                    } else {
                        throw new Error('No location data');
                    }
                })
                .catch(() => {
                    // Fallback to zone-based lookup
                    console.log('Falling back to zone-based lookup');
                    fetchGridData(null, null);
                });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('grid_aware', 'grid_aware_shortcode');