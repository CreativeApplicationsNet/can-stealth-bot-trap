<?php
if (!defined('ABSPATH')) exit;

class SBT_Admin_Dashboard {
    private $core;

    public function __construct($core_instance) {
        $this->core = $core_instance;
    }

    /**
     * Get the next scheduled cleanup time for a specific hook
     */
    private function get_next_scheduled_time($hook) {
        $timestamp = wp_next_scheduled($hook);

        if ($timestamp === false) {
            return 'Not scheduled';
        }

        $current_time = current_time('timestamp');
        $diff = $timestamp - $current_time;

        if ($diff < 0) {
            return 'Due now';
        } elseif ($diff < 60) {
            return 'in ' . $diff . ' seconds';
        } elseif ($diff < 3600) {
            $minutes = ceil($diff / 60);
            return 'in ' . $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
        } else {
            $hours = ceil($diff / 3600);
            return 'in ' . $hours . ' hour' . ($hours !== 1 ? 's' : '');
        }
    }

    /**
     * Generate a 500 character summary of current protection status
     */
    public function get_summary() {
        $settings = $this->core->get_settings();
        $active_bans = $this->core->get_total_active_logs();
        $ban_breakdown = $this->get_ban_breakdown_grouped();
        $last_24h_total = $this->get_bans_last_24h();
        $last_24h_unique = $this->get_unique_bans_last_24h();

        // Build enabled features string with status icons
        $features = [];

        $features[] = (!empty($settings['enable_rate_limit']) ? "🟩" : "⬜️") . " Rate limit (" . $settings['rate_limit'] . "/min)";
        $features[] = (!empty($settings['enable_js_check']) ? "🟩" : "⬜️") . " JS check";
        $features[] = (!empty($settings['enable_trap']) ? "🟩" : "⬜️") . " Honeypot";
        $features[] = (!empty($settings['enable_outdated_browser_check']) ? "🟩" : "⬜️") . " Outdated browser";

        if (!empty($settings['enable_geo_quiz'])) {
            $geo_countries = isset($settings['geo_quiz_countries']) ? $settings['geo_quiz_countries'] : '';
            $features[] = "🟩 Geo-quiz (" . strtoupper(str_replace(', ', ',', $geo_countries)) . ")";
        } else {
            $features[] = "⬜️ Geo-quiz";
        }

        $features_str = implode("   ", $features);

        // Build summary
        $summary = $features_str . "\n\n";
        $summary .= "Active bans: " . number_format($active_bans) . " | ";
        $summary .= implode(" • ", array_slice($ban_breakdown, 0, 3)) . "\n";
        $summary .= "Last 24h: " . $last_24h_unique . " unique IPs (" . $last_24h_total . " total blocks)\n";
        $summary .= "\n";
        $summary .= "Next Cleanup Schedules:\n";
        $summary .= "Expired bans: " . $this->get_next_scheduled_time('sbt_cleanup_expired') . "\n";
        $summary .= "Transients: " . $this->get_next_scheduled_time('sbt_cleanup_transients');

        return $summary;
    }

    /**
     * Render the dashboard summary widget
     */
    public function render() {
        ?>
        <div style="margin: 20px 0; line-height: 1.6;">
            <p style="font-size:14px"><strong>Status</strong></p>
            <p style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($this->get_summary()); ?></p>
        </div>
        <?php
    }

    /**
     * Get count of bans in last 24 hours
     */
    private function get_bans_last_24h() {
        global $wpdb;
        $time_24h_ago = date('Y-m-d H:i:s', current_time('timestamp') - 86400);

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sbt_blocked_ips
                 WHERE banned_at > %s",
                $time_24h_ago
            )
        );

        return intval($count);
    }

    /**
     * Get count of unique IPs banned in last 24 hours
     */
    private function get_unique_bans_last_24h() {
        global $wpdb;
        $time_24h_ago = date('Y-m-d H:i:s', current_time('timestamp') - 86400);

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT ip) FROM {$wpdb->prefix}sbt_blocked_ips
                 WHERE banned_at > %s",
                $time_24h_ago
            )
        );

        return intval($count);
    }

    /**
     * Get percentage of a reason in last 24 hours
     */
    private function get_last_24h_percentage($reason) {
        global $wpdb;
        $time_24h_ago = date('Y-m-d H:i:s', current_time('timestamp') - 86400);

        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sbt_blocked_ips
                 WHERE banned_at > %s",
                $time_24h_ago
            )
        );

        if ($total == 0) {
            return 0;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sbt_blocked_ips
                 WHERE banned_at > %s AND reason = %s",
                $time_24h_ago,
                $reason
            )
        );

        return round(($count / $total) * 100);
    }

    /**
     * Convert database reason to human-readable label
     */
    private function get_reason_label($reason) {
        if (strpos($reason, 'Rate limit') !== false) {
            return 'Rate limit';
        } elseif (strpos($reason, 'Hidden trap') !== false) {
            return 'Trap';
        } elseif (strpos($reason, 'No valid JS') !== false) {
            return 'JS';
        } elseif (strpos($reason, 'Outdated browser') !== false) {
            return 'Browser';
        } elseif (strpos($reason, 'Geo-based') !== false) {
            return 'Geo';
        } else {
            return 'Other';
        }
    }

    /**
     * Group ban reasons by category and count them
     */
    private function get_ban_breakdown_grouped() {
        global $wpdb;
        $current_time = current_time('mysql');

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT reason, COUNT(*) as count FROM {$wpdb->prefix}sbt_blocked_ips
                 WHERE expires_at > %s
                 GROUP BY reason",
                $current_time
            ),
            ARRAY_A
        );

        $grouped = [];
        foreach ($results as $row) {
            $label = $this->get_reason_label($row['reason']);
            if (!isset($grouped[$label])) {
                $grouped[$label] = 0;
            }
            $grouped[$label] += $row['count'];
        }

        // Sort by count descending
        arsort($grouped);

        $breakdown = [];
        foreach ($grouped as $label => $count) {
            $breakdown[] = $label . " " . $count;
        }

        return $breakdown;
    }
}
