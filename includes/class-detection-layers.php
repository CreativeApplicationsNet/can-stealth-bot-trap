<?php
if (!defined('ABSPATH')) exit;

class SBT_Detection_Layers {
    private $core;

    // In includes/class-detection-layers.php
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

        // 3. Geo check runs LAST (Priority 9) - only affects visitors who pass all other checks
        add_action('init', [$this, 'geo_based_quiz_check'], 9);

        // JS cookie injection
        add_action('wp_footer', [$this, 'inject_js_cookie']);

        // Honeypot link injection
        add_action('wp_footer', [$this, 'inject_honeypot_link']);
    }

    /* ---------------------------
     * DETECTION LAYERS
     * ------------------------- */

     public function check_ban() {
         if (!$this->core->should_protect()) return;
         if ($this->core->is_whitelisted_bot()) return;

         $ip = $this->core->get_client_ip();

         // If quiz was just solved, allow this one page load to go through
         // Then the transient will expire and normal bans will be enforced
         if (get_transient('sbt_quiz_solved_' . md5($ip))) {
             error_log('[SBT] Allowing page load - quiz just solved for IP: ' . $ip);
             // Delete the flag so future requests will be checked normally
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

         $settings = $this->core->get_settings();
         if (empty($settings['enable_trap'])) return;

         // Get the custom honeypot URL from settings
         $honeypot_url = isset($settings['honeypot_url']) ? $settings['honeypot_url'] : 'bot-trap';

         // Sanitize to allow only lowercase letters, numbers, and hyphens
         $honeypot_url = sanitize_title($honeypot_url);

         if (strpos($_SERVER['REQUEST_URI'], '/' . $honeypot_url) !== false) {
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

         // Get the custom honeypot URL from settings
         $honeypot_url = isset($settings['honeypot_url']) ? $settings['honeypot_url'] : 'bot-trap';

         // Sanitize to allow only lowercase letters, numbers, and hyphens
         $honeypot_url = sanitize_title($honeypot_url);

         // Inject hidden link into footer - only visible to bots/scanners
         echo '<!-- Honeypot --><a href="/' . esc_attr($honeypot_url) . '" style="display:none;"></a>';
     }


    public function rate_limit() {
        if (!$this->core->should_protect()) return;
        if ($this->core->is_whitelisted_bot()) return;

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

        // Skip JS check for whitelisted browsers (Brave, etc)
        if ($this->is_whitelisted_browser()) {
            //error_log('[SBT] Skipping JS check for whitelisted browser');
            return;
        }

        $uri = $_SERVER['REQUEST_URI'];

        if (strpos($uri, '/wp-json/') !== false && strpos($uri, '/wp/v2/') === false) {
            if (!isset($_COOKIE['sbt_verified']) || $_COOKIE['sbt_verified'] !== wp_hash(gmdate('Y-m-d H'))) {

                // Get the browser details
                $browser = $this->get_browser_details();

                // Log it with the specific browser info
                $this->core->ban_ip("No valid JS verification ($browser)");

                $this->block('blocked');
            }
        }
    }


    public function geo_based_quiz_check() {
        if (!$this->core->should_protect()) return;
        if ($this->core->is_whitelisted_bot()) return;

        $settings = $this->core->get_settings();

        // Early exit: Feature disabled
        if (empty($settings['enable_geo_quiz'])) return;

        // Early exit: Quiz mode not enabled
        $block_mode = isset($settings['block_mode']) ? $settings['block_mode'] : 'standard';
        if ($block_mode !== 'quiz') return;

        // Early exit: No countries configured
        $suspicious_countries = isset($settings['geo_quiz_countries']) ? $settings['geo_quiz_countries'] : '';
        if (empty($suspicious_countries)) return;

        $ip = $this->core->get_client_ip();

        // Check if already passed quiz in this session
        $quiz_passed_key = 'sbt_geo_quiz_passed_' . md5($ip);
        if (get_transient($quiz_passed_key)) {
            error_log("[SBT GEO] Skipped: Already passed quiz");
            return;
        }

        // Early exit: Already banned (don't check country again)
        if ($this->core->is_banned()) {
            //error_log("[SBT GEO] Skipped: IP already banned");
            return;
        }

        // NOW do the expensive API call
        $country = $this->get_country_from_ip($ip);
        if (empty($country)) {
            //error_log("[SBT GEO] Skipped: Could not determine country");
            return;
        }

        // Parse comma-separated list and normalize
        $countries_list = array_map('trim', explode(',', strtoupper($suspicious_countries)));
        $country_upper = strtoupper($country);

        if (in_array($country_upper, $countries_list)) {
            //error_log("[SBT GEO] MATCH! Forcing quiz for IP {$ip} from country {$country}");
            $this->core->ban_ip("Geo-based quiz required (Country: {$country})");
            $this->block('geo_quiz');
        }
    }

    private function get_country_from_ip($ip) {
        // Try multiple GeoIP sources for reliability

        $cache_key = 'sbt_geoip_' . md5($ip);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            //error_log("[SBT GEO] Using cached country for {$ip}: {$cached}");
            return $cached;
        }

        //error_log("[SBT GEO] No cache found, querying GeoIP API for {$ip}");

        // Try ipapi.co first (most reliable free option, no key needed)
        $country = $this->query_ipapi_co($ip);
        if ($country) {
            set_transient($cache_key, $country, 24 * HOUR_IN_SECONDS);
            //error_log("[SBT GEO] Successfully got country from ipapi.co: {$country}");
            return $country;
        }

        // Fallback to ip2location.io with better error handling
        $country = $this->query_ip2location($ip);
        if ($country) {
            set_transient($cache_key, $country, 24 * HOUR_IN_SECONDS);
            //error_log("[SBT GEO] Successfully got country from ip2location: {$country}");
            return $country;
        }

        //error_log("[SBT GEO] All GeoIP APIs failed for {$ip}");
        return null;
    }

    private function query_ipapi_co($ip) {
        // Free, no key required, 30k requests/month
        // Returns clean JSON with country_code

        $url = "https://ipapi.co/{$ip}/json/";

        $response = wp_remote_get($url, [
            'timeout'   => 3,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'WordPress-SBT/2.3']
        ]);

        if (is_wp_error($response)) {
            error_log("[SBT GEO] ipapi.co failed: " . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        //error_log("[SBT GEO] ipapi.co response code: {$status_code}");

        if ($status_code !== 200) {
            //error_log("[SBT GEO] ipapi.co returned non-200 status: {$status_code}");
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        //error_log("[SBT GEO] ipapi.co raw response: " . substr($body, 0, 200));

        $data = json_decode($body, true);

        if (isset($data['country_code'])) {
            //error_log("[SBT GEO] ipapi.co success! Country: " . $data['country_code']);
            return $data['country_code'];
        }

        //error_log("[SBT GEO] ipapi.co no country_code in response. Full data: " . json_encode($data));
        return null;
    }

    private function query_ip2location($ip) {
        // Fallback to ip2location with demo key
        // Note: Demo key has limitations, but worth trying

        $url = "https://ip2location.io/?ip={$ip}&key=demo";

        $response = wp_remote_get($url, [
            'timeout'   => 3,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'WordPress-SBT/2.3']
        ]);

        if (is_wp_error($response)) {
            error_log("[SBT GEO] ip2location.io failed: " . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        //error_log("[SBT GEO] ip2location.io response code: {$status_code}");

        if ($status_code !== 200) {
            //error_log("[SBT GEO] ip2location.io returned non-200 status: {$status_code}");
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        //error_log("[SBT GEO] ip2location.io raw response: " . substr($body, 0, 200));

        $data = json_decode($body, true);

        if (isset($data['country_code'])) {
            //error_log("[SBT GEO] ip2location.io success! Country: " . $data['country_code']);
            return $data['country_code'];
        }

        //error_log("[SBT GEO] ip2location.io no country_code in response. Full data: " . json_encode($data));
        return null;
    }

    /* ---------------------------
     * BLOCK HANDLER
     * ------------------------- */

     private function block($reason = 'blocked') {
         global $sbt_core;  // ADD THIS LINE
         $settings = $this->core->get_settings();

         if (!empty($settings['enable_block_page'])) {
             $template = dirname(plugin_dir_path(__FILE__)) . '/templates/blocked.php';

             if (file_exists($template)) {
                 // Generate quiz data if in quiz mode
                 if (isset($settings['block_mode']) && $settings['block_mode'] === 'quiz') {
                     $this->core->get_or_create_quiz();
                 }

                 $_GET['reason'] = $reason;
                 include $template;
                 exit;
             }
         }

         // Fallback if template missing
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
        // 1. Check for standard UA
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown UA';
        $bname = 'Unknown';
        $version = 'N/A';

        // 2. Check Client Hints for Brave/Modern Chrome
        // Note: Use the correct index for Sec-CH-UA
        $client_hints = $_SERVER['HTTP_SEC_CH_UA'] ?? '';

        if (!empty($client_hints) && strpos($client_hints, 'Brave') !== false) {
            $bname = 'Brave';
            if (preg_match('/"Brave";v="([^"]+)"/', $client_hints, $matches)) {
                $version = $matches[1];
            }
        }
        // 3. Regex Fallbacks
        elseif (preg_match('/Edge\/([\d\.]+)/i', $ua, $matches)) {
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
            // Capture the first word of any custom UA if no match found
            $parts = explode(' ', $ua);
            $bname = $parts[0];
        }

        return trim("$bname $version");
    }

    /* ---------------------------
     * BROWSER WHITELIST
     * ------------------------- */

    private function is_whitelisted_browser() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ua_lower = strtolower($ua);

        // Get whitelisted browsers from settings
        $settings = $this->core->get_settings();
        $whitelist_str = isset($settings['whitelisted_browsers']) ? $settings['whitelisted_browsers'] : 'brave,firefox';

        // Parse comma-separated list and trim whitespace
        $whitelisted = array_map('trim', explode(',', $whitelist_str));

        foreach ($whitelisted as $browser) {
            if (!empty($browser) && strpos($ua_lower, strtolower($browser)) !== false) {
                return true;
            }
        }

        return false;
    }

}
