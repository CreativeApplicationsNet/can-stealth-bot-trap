<?php

if (!defined('ABSPATH')) exit;

class SBT_Stealth_Bot_Trap {

    private $option_key = 'sbt_settings';
    private $log_table = 'sbt_blocked_ips';
    private $fingerprint_table = 'sbt_fingerprints';

    // Legitimate bot User-Agents
    private $whitelisted_bots = [
        'googlebot', 'bingbot', 'yandexbot', 'slurp', 'duckduckbot',
        'baiduspider', 'sogou', 'exabot', 'facebookexternalhit',
        'twitterbot', 'linkedinbot', 'whatsapp', 'slotovod',
    ];

    public function __construct() {
        // Cleanup hooks
        add_action('sbt_cleanup_expired', [$this, 'cleanup_old_entries']);
        add_action('sbt_cleanup_transients', [$this, 'cleanup_rate_limit_transients']);

        add_action('admin_init', [$this, 'preview_block_page']);
    }

    /* ---------------------------
     * ACTIVATION / DEACTIVATION
     * ------------------------- */

     public function create_tables() {
             global $wpdb;
             $charset_collate = $wpdb->get_charset_collate();

             $table_ips = $wpdb->prefix . $this->log_table;
             $table_finger = $wpdb->prefix . $this->fingerprint_table;

             require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

             // Blocked IPs table
             $sql = "CREATE TABLE $table_ips (
                 id BIGINT(20) NOT NULL AUTO_INCREMENT,
                 ip VARCHAR(45) NOT NULL,
                 reason VARCHAR(255) NOT NULL,
                 banned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                 expires_at DATETIME NOT NULL,
                 PRIMARY KEY  (id),
                 KEY idx_ip (ip),
                 KEY idx_expires (expires_at)
             ) $charset_collate;";
             dbDelta($sql);

             // Fingerprints table - MD5 is only 32 chars, so 100 is plenty and safe for all DBs
             $sql2 = "CREATE TABLE $table_finger (
                 id BIGINT(20) NOT NULL AUTO_INCREMENT,
                 ip VARCHAR(45) NOT NULL,
                 fingerprint VARCHAR(100) NOT NULL,
                 user_agent VARCHAR(500),
                 request_count INT DEFAULT 1,
                 last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                 PRIMARY KEY  (id),
                 KEY idx_ip (ip),
                 KEY idx_fingerprint (fingerprint)
             ) $charset_collate;";
             dbDelta($sql2);

             // Update an option so we don't keep running this every hit
             update_option('sbt_tables_created', time());
         }

    public function cleanup_old_entries() {
        global $wpdb;
        $current_time = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}{$this->log_table} WHERE expires_at < %s",
                $current_time
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}{$this->fingerprint_table}
                 WHERE last_seen < DATE_SUB(%s, INTERVAL 1 DAY)",
                $current_time
            )
        );
    }

    public function activate_plugin() {
        $this->create_tables();

        if (!wp_next_scheduled('sbt_cleanup_expired')) {
            wp_schedule_event(time(), 'twicedaily', 'sbt_cleanup_expired');
        }

        if (!wp_next_scheduled('sbt_cleanup_transients')) {
            wp_schedule_event(time(), 'hourly', 'sbt_cleanup_transients');
        }
    }

    public function deactivate_plugin() {
        wp_clear_scheduled_hook('sbt_cleanup_expired');
        wp_clear_scheduled_hook('sbt_cleanup_transients');
    }

    /* ---------------------------
     * UTILITY METHODS
     * ------------------------- */

     public function get_settings() {
         $defaults = [
             'ban_hours' => 6,
             'rate_limit' => 80,
             'test_mode' => 0,
             'enable_js_check' => 1,
             'enable_rate_limit' => 1,
             'enable_trap' => 1,
             'enable_outdated_browser_check' => 1,
             'block_mode' => 'standard',
             'whitelisted_browsers' => 'brave,firefox',
             'enable_geo_quiz' => 0,
             'geo_quiz_countries' => '',
             'honeypot_url' => 'bot-trap',
         ];

         $settings = get_option($this->option_key, $defaults);

         // If ip_whitelist doesn't exist, populate with defaults
         if (!isset($settings['ip_whitelist'])) {
             $settings['ip_whitelist'] = "# PayPal Webhook & IPN IPs\n" .
                                         "64.4.240.0/21\n" .
                                         "64.4.248.0/22\n" .
                                         "66.211.168.0/22\n" .
                                         "91.243.72.0/23\n" .
                                         "173.0.80.0/20\n" .
                                         "185.177.52.0/22\n" .
                                         "192.160.215.0/24\n" .
                                         "198.54.216.0/23\n\n" .
                                         "# Stripe Webhook IPs\n" .
                                         "54.187.174.169\n" .
                                         "54.187.205.235\n" .
                                         "54.187.216.72\n" .
                                         "# Note: Stripe IPs can change; signature verification is recommended.";
         }

         return $settings;
     }

    public function should_protect() {
        return !is_user_logged_in();
    }

    public function get_client_ip() {
        if (function_exists('wp_get_remote_address')) {
            return wp_get_remote_address();
        }

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } else {
            $ip = '0.0.0.0';
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function is_whitelisted_bot() {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';

        foreach ($this->whitelisted_bots as $bot) {
            if (strpos($user_agent, $bot) !== false) {
                return true;
            }
        }

        return false;
    }

    public function ban_ip($reason) {
        $settings = $this->get_settings();
        $ip = $this->get_client_ip();

        if ($this->is_banned()) {
            return;
        }

        if (!empty($settings['test_mode'])) {
            error_log("[SBT TEST] Would ban {$ip} for: {$reason}");
            return;
        }

        global $wpdb;
        $current_time = current_time('mysql');
        $expires_time = date('Y-m-d H:i:s', current_time('timestamp') + ($settings['ban_hours'] * HOUR_IN_SECONDS));

        $wpdb->insert(
            $wpdb->prefix . $this->log_table,
            [
                'ip' => $ip,
                'reason' => $reason,
                'banned_at' => $current_time,
                'expires_at' => $expires_time
            ],
            ['%s', '%s', '%s', '%s']
        );

        set_transient('sbt_ban_' . md5($ip), true, $settings['ban_hours'] * HOUR_IN_SECONDS);

        // CREATE THE QUIZ IMMEDIATELY if in quiz mode
        if (isset($settings['block_mode']) && $settings['block_mode'] === 'quiz') {
            //error_log('[SBT] ban_ip() creating quiz for IP: ' . $ip);
            $quiz = $this->get_or_create_quiz();
            //error_log('[SBT] ban_ip() quiz created: ' . $quiz['question']);

            // Verify it was actually set
            $verify = get_transient('sbt_quiz_data_' . md5($ip));
            //error_log('[SBT] ban_ip() verify transient exists: ' . ($verify ? 'YES' : 'NO'));
        }
    }

    public function is_banned() {
        global $wpdb;
        $ip = $this->get_client_ip();

        if (get_transient('sbt_ban_' . md5($ip))) {
            return true;
        }

        $current_time = current_time('mysql');
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}{$this->log_table}
                 WHERE ip = %s AND expires_at > %s LIMIT 1",
                $ip,
                $current_time
            )
        );

        return $result !== null;
    }

    public function record_fingerprint($extra_data = '') {
            global $wpdb;
            $table_name = $wpdb->prefix . $this->fingerprint_table;

            // Only check if table exists if we haven't confirmed it recently
            if (!get_option('sbt_tables_created')) {
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                    $this->create_tables();
                    return;
                }
            }

            $ip = $this->get_client_ip();
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown';
            $fingerprint = md5($ip . $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $extra_data);

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, request_count FROM $table_name WHERE ip = %s AND fingerprint = %s",
                $ip, $fingerprint
            ));

            if ($existing) {
                $wpdb->update($table_name,
                    ['request_count' => $existing->request_count + 1, 'last_seen' => current_time('mysql')],
                    ['id' => $existing->id]
                );
            } else {
                $wpdb->insert($table_name,
                    ['ip' => $ip, 'fingerprint' => $fingerprint, 'user_agent' => $user_agent, 'last_seen' => current_time('mysql')]
                );
            }
        }

    public function get_active_logs($limit = 20, $offset = 0) {
        global $wpdb;
        $current_time = current_time('mysql');

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}{$this->log_table}
                 WHERE expires_at > %s
                 ORDER BY banned_at DESC
                 LIMIT %d OFFSET %d",
                $current_time,
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    public function get_total_active_logs() {
        global $wpdb;
        $current_time = current_time('mysql');

        return (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}{$this->log_table}
                 WHERE expires_at > %s",
                $current_time
            )
        );
    }

    public function cleanup_rate_limit_transients() {
        global $wpdb;

        // Delete expired ban transients
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}options
             WHERE option_name LIKE '_transient_sbt_ban_%'
             OR option_name LIKE '_transient_timeout_sbt_ban_%'"
        );

        // Delete expired rate limit transients
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}options
             WHERE option_name LIKE '_transient_sbt_rate_%'
             OR option_name LIKE '_transient_timeout_sbt_rate_%'"
        );

        // Delete expired quiz data transients
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}options
             WHERE option_name LIKE '_transient_sbt_quiz_data_%'
             OR option_name LIKE '_transient_timeout_sbt_quiz_data_%'"
        );

        // Delete expired quiz solved flag transients
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}options
             WHERE option_name LIKE '_transient_sbt_quiz_solved_%'
             OR option_name LIKE '_transient_timeout_sbt_quiz_solved_%'"
        );

        // Delete expired geo quiz passed transients
        // $wpdb->query(
        //     "DELETE FROM {$wpdb->prefix}options
        //      WHERE option_name LIKE '_transient_sbt_geo_quiz_passed_%'
        //      OR option_name LIKE '_transient_timeout_sbt_geo_quiz_passed_%'"
        // );

        // Delete expired GeoIP cache transients
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}options
             WHERE option_name LIKE '_transient_sbt_geoip_%'
             OR option_name LIKE '_transient_timeout_sbt_geoip_%'"
        );

        error_log('[SBT] Cleaned up all expired transients (bans, rate limits, quiz data, geo flags, and geoip cache)');
    }

    public function preview_block_page() {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (!isset($_GET['sbt_preview_block'])) return;

        $template = dirname(plugin_dir_path(__FILE__)) . '/templates/blocked.php';

        if (!file_exists($template)) {
            wp_die('Block template not found.');
        }

        // Set a flag so blocked.php knows this is a preview
        define('SBT_PREVIEW_MODE', true);

        $opts = $this->get_settings();
        $is_quiz_mode = (isset($opts['block_mode']) && $opts['block_mode'] === 'quiz') || isset($_GET['preview_quiz']);

        if ($is_quiz_mode) {
            $num1 = rand(1, 10);
            $num2 = rand(1, 10);
            $op = (rand(0, 1) === 1) ? '+' : 'x';
        }

        $_GET['reason'] = isset($_GET['reason']) ? $_GET['reason'] : 'blocked';

        // Don't let blocked.php redirect in preview mode
        include $template;
        exit;
    }

    // Add these to SBT_Stealth_Bot_Trap class

    public function get_or_create_quiz() {
        $ip = $this->get_client_ip();
        $key = 'sbt_quiz_data_' . md5($ip);

        // Check for existing question FIRST
        $quiz = get_transient($key);

        if ($quiz === false) {
            // Only create new quiz if one doesn't exist
            $n1 = rand(1, 10);
            $n2 = rand(1, 10);
            $op = (rand(0, 1) === 1) ? '+' : 'x';
            $ans = ($op === '+') ? ($n1 + $n2) : ($n1 * $n2);

            $quiz = [
                'question' => "$n1 $op $n2 = ?",
                'expected' => $ans
            ];

            //error_log('[SBT] Creating NEW quiz for IP: ' . $ip . ', Question: ' . $quiz['question'] . ', Answer: ' . $quiz['expected']);

            // Set transient to 5 minutes
            set_transient($key, $quiz, 300);
        } else {
            //error_log('[SBT] Using EXISTING quiz for IP: ' . $ip . ', Question: ' . $quiz['question'] . ', Answer: ' . $quiz['expected']);
        }

        return $quiz;
    }


    public function handle_quiz_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sbt_quiz_answer'])) {
            return;
        }

        if (!isset($_POST['sbt_quiz_nonce']) || !wp_verify_nonce($_POST['sbt_quiz_nonce'], 'sbt_solve_quiz')) {
            return;
        }

        $ip = $this->get_client_ip();
        $quiz_key = 'sbt_quiz_data_' . md5($ip);
        $quiz = get_transient($quiz_key);

        if (!$quiz) {
            return;
        }

        $submitted_answer = (int)$_POST['sbt_quiz_answer'];
        $expected_answer = (int)$quiz['expected'];

        if ($submitted_answer === $expected_answer) {
            // CORRECT ANSWER
            error_log('[SBT] Correct answer! Unblocking IP: ' . $ip);
            $this->unblock_current_ip();
            delete_transient($quiz_key);

            // Get ban duration from settings and use it for quiz pass duration
            $settings = $this->get_settings();
            $ban_hours = isset($settings['ban_hours']) ? (int)$settings['ban_hours'] : 6;
            $duration_seconds = $ban_hours * HOUR_IN_SECONDS;

            // Set flag with same duration as ban
            set_transient('sbt_geo_quiz_passed_' . md5($ip), true, $duration_seconds);

            // Use wp_redirect and die to ensure the redirect happens
            wp_redirect($_SERVER['REQUEST_URI']);
            die();
        } else {
            // WRONG ANSWER
            delete_transient($quiz_key);
            wp_redirect($_SERVER['REQUEST_URI']);
            die();
        }
    }

    public function unblock_current_ip() {
        global $wpdb;
        $ip = $this->get_client_ip();

        // Delete from database
        $wpdb->delete(
            $wpdb->prefix . $this->log_table,
            ['ip' => $ip],
            ['%s']
        );

        // Clear the transient immediately
        $transient_deleted = delete_transient('sbt_ban_' . md5($ip));
        //error_log("[SBT] Transient delete result: " . ($transient_deleted ? 'SUCCESS' : 'FAILED'));

        // Double-check it's really gone
        $still_banned = get_transient('sbt_ban_' . md5($ip));
        //error_log("[SBT] Transient still exists after delete: " . ($still_banned ? 'YES' : 'NO'));

        error_log("[SBT] IP {$ip} unblocked via quiz.");
    }

    // Add this to class-stealth-bot-trap.php:
    public function unban_ip($ip) {
        global $wpdb;

        // Delete from database
        $wpdb->delete(
            $wpdb->prefix . $this->log_table,
            ['ip' => $ip],
            ['%s']
        );

        // Clear the fast-access transient
        delete_transient('sbt_ban_' . md5($ip));

        // Optional: Log the unblock
        error_log("[SBT] IP {$ip} successfully solved quiz and was unblocked.");
    }
}
