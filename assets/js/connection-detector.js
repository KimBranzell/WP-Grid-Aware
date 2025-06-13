(function() {
    'use strict';

    /**
     * Detect and store connection information
     */
    function detectConnection() {
        var connectionInfo = {
            effective_type: '4g',
            downlink: 10,
            rtt: 100,
            save_data: false,
            timestamp: Date.now()
        };

        // Use Network Information API if available
        if ('connection' in navigator) {
            var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

            if (conn) {
                connectionInfo.effective_type = conn.effectiveType || connectionInfo.effective_type;
                connectionInfo.downlink = conn.downlink || connectionInfo.downlink;
                connectionInfo.rtt = conn.rtt || connectionInfo.rtt;
                connectionInfo.save_data = conn.saveData || connectionInfo.save_data;
            }
        }

        // Store in cookie for server-side access (expires in 1 day)
        setCookie('grid_aware_connection', JSON.stringify(connectionInfo), 1);

        // Also store in localStorage for faster client-side access
        try {
            localStorage.setItem('grid_aware_connection', JSON.stringify(connectionInfo));
        } catch (e) {
            // Ignore if localStorage is not available
        }

        // Update on connection change
        if ('connection' in navigator && navigator.connection) {
            navigator.connection.addEventListener('change', function() {
                setTimeout(detectConnection, 1000); // Debounce
            });
        }
    }

    /**
     * Set cookie helper
     */
    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + (value || '') + expires + '; path=/; SameSite=Lax';
    }

    /**
     * Get current connection info
     */
    function getConnectionInfo() {
        try {
            var stored = localStorage.getItem('grid_aware_connection');
            if (stored) {
                return JSON.parse(stored);
            }
        } catch (e) {
            // Fallback to defaults
        }

        return {
            effective_type: '4g',
            downlink: 10,
            rtt: 100,
            save_data: false
        };
    }

    // Make connection info available globally
    window.GridAwareConnection = {
        get: getConnectionInfo,
        detect: detectConnection
    };

    // Run detection when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', detectConnection);
    } else {
        detectConnection();
    }
})();
