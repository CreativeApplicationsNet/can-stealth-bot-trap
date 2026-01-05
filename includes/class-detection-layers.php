<?php
if (!defined('ABSPATH')) exit;

class SBT_Detection_Layers {
    private $core;

    // Webhook paths that should bypass protection entirely
    private $webhook_paths = [
        '/wp-json/wc-paypal/',          // WooCommerce PayPal
        '/wp-json/paypal/',              // Generic PayPal webhooks
        '/wp-json/wc-stripe/',           // Stripe webhooks
        '/wp-json/wc/v3/webhooks',       // WC Webhooks API
        '/?wc-api=paypal',               // Legacy WooCommerce PayPal
        '/?wc-api=stripe',               // Legacy WooCommerce Stripe
        '/paypal-webhook',               // Custom PayPal endpoints
        '/stripe-webhook',               // Custom Stripe endpoints
    ];

    public function __construct() {
        global $sbt_core;
        if (!$sbt_core) {
            $sbt_core = new SBT_Stealth_Bot_Trap();
        }
        $this->core = $sbt_core;

        // 1. Process the quiz submission FIRST (Priority 1)
        add_action('init', [$this->core, 'handle_quiz_submission'], 1);

        // 2. Run detection layers in order (Priority 5+)
        add_action('init', [$this, 'check_ban'], 5);
        add_action('init', [$this, 'trap_hidden_url'], 6);
        add_action('init', [$this, 'rate_limit'], 7);
        add_action('init', [$this, 'block_without_js'], 8);

        // 3. Geo check runs LAST (Priority 9)
        add_action('init', [$this, 'geo_based_quiz_check'], 9);

        // JS cookie injection
        add_action('wp_footer', [$this, 'inject_js_cookie']);

        // Honeypot link injection
        add_action('wp_footer', [$this, 'inject_honeypot_link']);
    }

    /* ---------------------------
     * WEBHOOK DETECTION
     * ------------------------- */

    /**
     * Check if current request is a webhook that should bypass protection
     */
    private function is_webhook_request() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        // Webhooks are almost always POST requests
        if ($method !== 'POST') {
            return false;
        }

        // Check against known webhook paths
        foreach ($this->webhook_paths as $path) {
            if (strpos($uri, $path) !== false) {
                error_log('[SBT] Webhook detected and whitelisted: ' . $uri);
                return true;
            }
        }

        // Allow custom webhook paths via filter
        if (apply_filters('sbt_is_webhook_request', false, $uri)) {
            error_log('[SBT] Webhook whitelisted via filter: ' . $uri);
            return true;
        }

        return false;
    }

    /**
     * Check if IP is in the whitelist
     */
    private function is_whitelisted_ip() {
        $ip = $this->core->get_client_ip();
        $settings = $this->core->get_settings();

        $whitelist_str = isset($settings['ip_whitelist']) ? $settings['ip_whitelist'] : '';

        if (empty($whitelist_str)) {
            return false;
        }

        // Split by newlines and parse
        $lines = array_filter(array_map('trim', explode("\n", $whitelist_str)));

        foreach ($lines as $line) {
            // Skip empty lines and comments
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Remove inline comments
            if (strpos($line, '#') !== false) {
                $line = trim(substr($line, 0, strpos($line, '#')));
            }

            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Check for CIDR range
            if (strpos($line, '/') !== false) {
                if ($this->ip_in_cidr($ip, $line)) {
                    error_log('[SBT] IP whitelisted (CIDR): ' . $ip . ' matched ' . $line);
                    return true;
                }
            } else {
                // Exact match
                if ($ip === $line) {
                    error_log('[SBT] IP whitelisted (exact): ' . $ip);
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Simple CIDR IP range checker
     */
     private function ip_in_cidr($ip, $cidr) {
         if (strpos($cidr, '/') === false) {
             return $ip === $cidr;
         }

         list($subnet, $bits) = explode('/', $cidr);
         $bits = (int)$bits;  // ← CAST TO INT

         // Validate inputs
         $ip_long = ip2long($ip);
         $subnet_long = ip2long($subnet);

         if ($ip_long === false || $subnet_long === false) {  // ← VALIDATE
             error_log('[SBT] Invalid IP or CIDR range: ' . $ip . ' / ' . $cidr);
             return false;
         }

         // Calculate the mask
         $mask = -1 << (32 - $bits);
         $subnet_long &= $mask;

         return ($ip_long & $mask) === $subnet_long;
     }

    /* ---------------------------
     * DETECTION LAYERS
     * ------------------------- */

    public function check_ban() {
        if (!$this->core->should_protect()) return;
        if ($this->core->is_whitelisted_bot()) return;

        // SKIP PROTECTION FOR WEBHOOKS
        if ($this->is_webhook_request()) return;
        if ($this->is_whitelisted_ip()) return;

        $ip = $this->core->get_client_ip();

        if (get_transient('sbt_quiz_solved_' . md5($ip))) {
            error_log('[SBT] Allowing page load - quiz just solved for IP: ' . $ip);
            delete_transient('sbt_quiz_solved_' . md5($ip));
            return;
        }

        $settings = $this->core->get_settings();

        if (!empty($settings['enable_outdated_browser_check']) && $this->is_outdated_browser()) {
            $browser = $this->get_browser_details();
            $this->core->ban_ip("Outdated browser detected ($browser)");
            $this->block('blocked');
        }

        if ($this->core->is_banned()) {
            $this->block('blocked');
        }
    }

    public function trap_hidden_url() {
        if (!$this->core->should_protect()) return;
        if ($this->core->is_whitelisted_bot()) return;

        // SKIP PROTECTION FOR WEBHOOKS
        if ($this->is_webhook_request()) return;
        if ($this->is_whitelisted_ip()) return;

        $settings = $this->core->get_settings();
        if (empty($settings['enable_trap'])) return;

        $honeypot_url = isset($settings['honeypot_url']) ? $settings['honeypot_url'] : 'bot-trap';
        $honeypot_url = sanitize_title($honeypot_url);

        if (strpos($_SERVER['REQUEST_URI'], '/.' . $honeypot_url) !== false) {
            $this->core->ban_ip('Hidden trap URL accessed (' . $honeypot_url . ')');
            $this->block('blocked');
        }
    }

    public function inject_honeypot_link() {
        if (!$this->core->should_protect()) return;
        if ($this->core->is_whitelisted_bot()) return;
        if (is_admin()) return;

        $settings = $this->core->get_settings();
        if (empty($settings['enable_trap'])) return;

        $honeypot_url = isset($settings['honeypot_url']) ? $settings['honeypot_url'] : 'bot-trap';
        $honeypot_url = sanitize_title($honeypot_url);

        echo '<!-- Honeypot --><a href="/.' . esc_attr($honeypot_url) . '" style="display:none;"></a>';
    }

    public function rate_limit() {
        if (!$this->core->should_protect()) return;
        if ($this->core->is_whitelisted_bot()) return;

        // SKIP PROTECTION FOR WEBHOOKS
        if ($this->is_webhook_request()) return;
        if ($this->is_whitelisted_ip()) return;

        $settings = $this->core->get_settings();
        if (empty($settings['enable_rate_limit'])) return;

        $ip  = $this->core->get_client_ip();
        $key = 'sbt_rate_' . md5($ip);

        $count = get_transient($key);
        $count = $count ? $count + 1 : 1;

        set_transient($key, $count, 60);

        if ($count > (int)$settings['rate_limit']) {
            $this->core->ban_ip('Rate limit exceeded (' . $count . '/min)');
            $this->block('rate_limit');
        }

        $this->core->record_fingerprint($count);
    }

    public function inject_js_cookie() {
        if (!$this->core->should_protect()) return;
        if ($this->core->is_whitelisted_bot()) return;

        $settings = $this->core->get_settings();
        if (empty($settings['enable_js_check'])) return;
        if (is_admin()) return;

        $cookie_value = wp_hash(gmdate('Y-m-d H'));
        echo '<script>document.cookie="sbt_verified=' . esc_js($cookie_value) . '; path=/; max-age=3600";</script>';
    }

    public function block_without_js() {
        if (!$this->core->should_protect()) return;
        if ($this->core->is_whitelisted_bot()) return;

        $settings = $this->core->get_settings();
        if (empty($settings['enable_js_check'])) return;

        // SKIP PROTECTION FOR WEBHOOKS
        if ($this->is_webhook_request()) return;
        if ($this->is_whitelisted_ip()) return;

        // Skip JS check for whitelisted browsers
        if ($this->is_whitelisted_browser()) {
            return;
        }

        $uri = $_SERVER['REQUEST_URI'];

        if (strpos($uri, '/wp-json/') !== false && strpos($uri, '/wp/v2/') === false) {
            if (!isset($_COOKIE['sbt_verified']) || $_COOKIE['sbt_verified'] !== wp_hash(gmdate('Y-m-d H'))) {
                $browser = $this->get_browser_details();
                $this->core->ban_ip("No valid JS verification ($browser)");
                $this->block('blocked');
            }
        }
    }

    public function geo_based_quiz_check() {
        if (!$this->core->should_protect()) return;
        if ($this->core->is_whitelisted_bot()) return;

        // SKIP PROTECTION FOR WEBHOOKS
        if ($this->is_webhook_request()) return;
        if ($this->is_whitelisted_ip()) return;

        $settings = $this->core->get_settings();

        if (empty($settings['enable_geo_quiz'])) return;

        $block_mode = isset($settings['block_mode']) ? $settings['block_mode'] : 'standard';
        if ($block_mode !== 'quiz') return;

        $suspicious_countries = isset($settings['geo_quiz_countries']) ? $settings['geo_quiz_countries'] : '';
        if (empty($suspicious_countries)) return;

        $ip = $this->core->get_client_ip();

        $quiz_passed_key = 'sbt_geo_quiz_passed_' . md5($ip);
        if (get_transient($quiz_passed_key)) {
            return;
        }

        if ($this->core->is_banned()) {
            return;
        }

        $country = $this->get_country_from_ip($ip);

        if ($country === null) {
            return;
        }

        $countries_list = array_map('trim', explode(',', strtoupper($suspicious_countries)));
        $country_upper = strtoupper($country);

        if (in_array($country_upper, $countries_list)) {
            $this->core->ban_ip("Geo-based quiz required (Country: {$country})");
            $this->block('geo_quiz');
        }

        if ($country === 'UNKNOWN') {
            $this->core->ban_ip("Geo-based quiz required (Country: UNKNOWN)");
            $this->block('geo_unknown');
        }
    }

    private function get_country_from_ip($ip) {
        $cache_key = 'sbt_geoip_' . md5($ip);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $country = $this->query_ipapi_co($ip);
        if ($country) {
            set_transient($cache_key, $country, 24 * HOUR_IN_SECONDS);
            return $country;
        }

        $country = $this->query_ip2location($ip);
        if ($country) {
            set_transient($cache_key, $country, 24 * HOUR_IN_SECONDS);
            return $country;
        }

        set_transient($cache_key, 'UNKNOWN', 30 * MINUTE_IN_SECONDS);
        return 'UNKNOWN';
    }

    private function query_ipapi_co($ip) {
        $url = "https://ipapi.co/{$ip}/json/";

        $response = wp_remote_get($url, [
            'timeout'   => 5,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'WordPress-SBT/2.3']
        ]);

        if (is_wp_error($response)) {
            error_log("[SBT GEO] ipapi.co failed: " . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['country_code'])) {
            return $data['country_code'];
        }

        return null;
    }

    private function query_ip2location($ip) {
        $url = "https://ip2location.io/?ip={$ip}&key=demo";

        $response = wp_remote_get($url, [
            'timeout'   => 5,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'WordPress-SBT/2.3']
        ]);

        if (is_wp_error($response)) {
            error_log("[SBT GEO] ip2location.io failed: " . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['country_code'])) {
            return $data['country_code'];
        }

        return null;
    }

    /* ---------------------------
     * BLOCK HANDLER
     * ------------------------- */

    private function block($reason = 'blocked') {
        global $sbt_core;
        $settings = $this->core->get_settings();

        if (!empty($settings['enable_block_page'])) {
            $template = dirname(plugin_dir_path(__FILE__)) . '/templates/blocked.php';

            if (file_exists($template)) {
                if (isset($settings['block_mode']) && $settings['block_mode'] === 'quiz') {
                    $this->core->get_or_create_quiz();
                }

                $_GET['reason'] = $reason;
                include $template;
                exit;
            }
        }

        wp_die("Access Denied. Reason: " . esc_html($reason));
    }

    /* ---------------------------
     * HELPERS
     * ------------------------- */

    private function is_outdated_browser() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (preg_match('/Chrome\/(\d+)/', $ua, $m) && (int)$m[1] < 120) return true;
        if (preg_match('/Firefox\/(\d+)/', $ua, $m) && (int)$m[1] < 115) return true;
        if (preg_match('/Version\/(\d+)/', $ua, $m) && (int)$m[1] < 15) return true;

        return false;
    }

    private function get_browser_details() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown UA';
        $bname = 'Unknown';
        $version = 'N/A';

        $client_hints = $_SERVER['HTTP_SEC_CH_UA'] ?? '';

        if (!empty($client_hints) && strpos($client_hints, 'Brave') !== false) {
            $bname = 'Brave';
            if (preg_match('/"Brave";v="([^"]+)"/', $client_hints, $matches)) {
                $version = $matches[1];
            }
        } elseif (preg_match('/Edge\/([\d\.]+)/i', $ua, $matches)) {
            $bname = 'Edge';
            $version = $matches[1];
        } elseif (preg_match('/Chrome\/([\d\.]+)/i', $ua, $matches)) {
            $bname = 'Chrome';
            $version = $matches[1];
        } elseif (preg_match('/Firefox\/([\d\.]+)/i', $ua, $matches)) {
            $bname = 'Firefox';
            $version = $matches[1];
        } elseif (preg_match('/Version\/([\d\.]+).*Safari/i', $ua, $matches)) {
            $bname = 'Safari';
            $version = $matches[1];
        } elseif ($ua !== 'Unknown UA') {
            $parts = explode(' ', $ua);
            $bname = $parts[0];
        }

        return trim("$bname $version");
    }

    private function is_whitelisted_browser() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ua_lower = strtolower($ua);

        $settings = $this->core->get_settings();
        $whitelist_str = isset($settings['whitelisted_browsers']) ? $settings['whitelisted_browsers'] : 'brave,firefox';

        $whitelisted = array_map('trim', explode(',', $whitelist_str));

        foreach ($whitelisted as $browser) {
            if (!empty($browser) && strpos($ua_lower, strtolower($browser)) !== false) {
                return true;
            }
        }

        return false;
    }
}
