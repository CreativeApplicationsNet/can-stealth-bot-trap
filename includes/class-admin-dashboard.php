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
     * Get count of IPs that unlocked via quiz in the last X hours
     */
    private function get_quiz_unlocks_last_x_hours() {
        $settings = $this->core->get_settings();
        $ban_hours = isset($settings['ban_hours']) ? (int)$settings['ban_hours'] : 6;

        global $wpdb;

        // Count transients that match the geo_quiz_passed pattern
        // These are stored as option_name = '_transient_sbt_geo_quiz_passed_*'
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}options
             WHERE option_name LIKE '_transient_sbt_geo_quiz_passed_%'"
        );

        return intval($count);
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

        $features[] = (!empty($settings['enable_rate_limit']) ? "ðŸŸ©" : "â¬œï¸") . " Rate limit (" . $settings['rate_limit'] . "/min)";
        $features[] = (!empty($settings['enable_js_check']) ? "ðŸŸ©" : "â¬œï¸") . " JS check";
        $features[] = (!empty($settings['enable_trap']) ? "ðŸŸ©" : "â¬œï¸") . " Honeypot";
        $features[] = (!empty($settings['enable_outdated_browser_check']) ? "ðŸŸ©" : "â¬œï¸") . " Outdated browser";

        if (!empty($settings['enable_geo_quiz'])) {
            $geo_countries = isset($settings['geo_quiz_countries']) ? $settings['geo_quiz_countries'] : '';
            $features[] = "ðŸŸ© Geo-quiz (" . strtoupper(str_replace(', ', ',', $geo_countries)) . ")";
        } else {
            $features[] = "â¬œï¸ Geo-quiz";
        }

        $features_str = implode("   ", $features);

        // Build summary
        $summary = $features_str . "\n\n";
        $summary .= "Active bans: " . number_format($active_bans) . " | ";
        $summary .= implode(" â€¢ ", array_slice($ban_breakdown, 0, 3)) . "\n";
        $summary .= "Last 24h: " . $last_24h_unique . " unique IPs (" . $last_24h_total . " total blocks)\n";
        $summary .= "\n";
        $summary .= "Next Cleanup Schedules:\n";
        $summary .= "Expired bans: " . $this->get_next_scheduled_time('sbt_cleanup_expired') . "\n";
        $summary .= "Transients: " . $this->get_next_scheduled_time('sbt_cleanup_transients');

        return $summary;
    }

    /**
     * Render the dashboard summary widget with modern design
     */
    public function render() {
        $settings = $this->core->get_settings();
        $active_bans = $this->core->get_total_active_logs();
        $ban_breakdown = $this->get_ban_breakdown_grouped();
        $last_24h_total = $this->get_bans_last_24h();
        $last_24h_unique = $this->get_unique_bans_last_24h();
        $quiz_unlocks = $this->get_quiz_unlocks_last_x_hours();
        $ban_hours = isset($settings['ban_hours']) ? (int)$settings['ban_hours'] : 6;
        $quiz_enabled = isset($settings['block_mode']) && $settings['block_mode'] === 'quiz';
        ?>
        <style>
            .sbt-dashboard {
                background: #fff;
                border-radius: 0px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                overflow: hidden;
                margin: 20px 0;
            }

            .sbt-dashboard-header {
                background: #FFFFFF;
                color: white;
                padding: 10px 10px;
            }

            .sbt-dashboard-header h3 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
            }

            .sbt-dashboard-content {
                padding: 10px 10px;
            }

            .sbt-stats-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 24px;
                margin-bottom: 20px;
            }

            .sbt-stat-card {
                border-left: 4px solid #667eea;
                padding: 0;
                padding-left: 10px;
            }

            .sbt-stat-card.danger {
                border-left-color: #ff6b6b;
            }

            .sbt-stat-card.success {
                border-left-color: #51cf66;
            }

            .sbt-stat-card.disabled {
                opacity: 0.4;
            }

            .sbt-stat-card.info {
                border-left-color: #4c6ef5;
            }

            .sbt-stat-label {
                font-size: 12px;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                font-weight: 500;
                margin-bottom: 8px;
            }

            .sbt-stat-value {
                font-size: 32px;
                font-weight: 700;
                color: #222;
                line-height: 1;
            }

            .sbt-stat-subtext {
                font-size: 12px;
                color: #999;
                margin-top: 8px;
            }

            .sbt-features-section {
                margin-bottom: 20px;
            }

            .sbt-section-title {
                font-size: 11px;
                color: #999;
                margin-bottom: 16px;
            }

            .sbt-features-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 12px;
            }

            .sbt-feature-item {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 10px 12px;
                border-radius: 4px;
                background: #f9f9f9;
                font-size: 13px;
                color: #555;
            }

            .sbt-feature-item.active {
                background: #F1F1F1;
            }

            .sbt-feature-item.inactive {
                opacity: 0.6;
            }

            .sbt-feature-indicator {
                width: 12px;
                height: 12px;
                border-radius: 2px;
                flex-shrink: 0;
                background: #ddd;
            }

            .sbt-feature-indicator.on {
                background: #51cf66;
            }

            .sbt-feature-label {
                flex: 1;
                font-size: 13px;
                font-weight: 500;
            }

            .sbt-feature-detail {
                font-size: 11px;
                color: #999;
            }

            .sbt-cleanup-section {
                padding-top: 0px;
            }

            .sbt-cleanup-items {
                display: grid;
                gap: 0px;
            }

            .sbt-cleanup-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0px 0;
                font-size: 13px;
            }

            .sbt-cleanup-label {
                color: #666;
            }

            .sbt-cleanup-time {
                color: #999;
                font-size: 12px;
                font-weight: 500;
            }

            @media (max-width: 600px) {
                .sbt-stats-grid {
                    grid-template-columns: 1fr;
                    gap: 16px;
                }

                .sbt-stat-value {
                    font-size: 28px;
                }

                .sbt-features-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>

        <div class="sbt-dashboard">
            <div class="sbt-dashboard-header">
                <h3>Protection Status</h3>
            </div>

            <div class="sbt-dashboard-content">
                <!-- Stats Section -->
                <div class="sbt-stats-grid">
                    <div class="sbt-stat-card success">
                        <div class="sbt-stat-label">Active Bans</div>
                        <div class="sbt-stat-value"><?php echo esc_html(number_format($active_bans)); ?></div>
                        <div class="sbt-stat-subtext">
                            <?php
                            $breakdown_items = array_slice($ban_breakdown, 0, 3);
                            echo esc_html(implode(', ', $breakdown_items));
                            ?>
                        </div>
                    </div>

                    <div class="sbt-stat-card danger">
                        <div class="sbt-stat-label">Last 24 Hours</div>
                        <div class="sbt-stat-value"><?php echo esc_html(number_format($last_24h_total)); ?></div>
                        <div class="sbt-stat-subtext"><?php echo esc_html(number_format($last_24h_unique)); ?> unique IPs blocked</div>
                    </div>

                    <div class="sbt-stat-card info <?php echo !$quiz_enabled ? 'disabled' : ''; ?>">
                        <div class="sbt-stat-label">Quiz Unlocks</div>
                        <div class="sbt-stat-value"><?php echo esc_html(number_format($quiz_unlocks)); ?></div>
                        <div class="sbt-stat-subtext">Last <?php echo esc_html($ban_hours); ?> hours</div>
                    </div>
                </div>

                <!-- Features Section -->
                <div class="sbt-features-section">
                    <div class="sbt-features-grid">
                        <!-- Rate Limit -->
                        <div class="sbt-feature-item <?php echo !empty($settings['enable_rate_limit']) ? 'active' : 'inactive'; ?>">
                            <div class="sbt-feature-indicator <?php echo !empty($settings['enable_rate_limit']) ? 'on' : ''; ?>"></div>
                            <div>
                                <div class="sbt-feature-label">Rate Limit</div>
                            </div>
                        </div>

                        <!-- JS Check -->
                        <div class="sbt-feature-item <?php echo !empty($settings['enable_js_check']) ? 'active' : 'inactive'; ?>">
                            <div class="sbt-feature-indicator <?php echo !empty($settings['enable_js_check']) ? 'on' : ''; ?>"></div>
                            <div>
                                <div class="sbt-feature-label">JS Check</div>
                            </div>
                        </div>

                        <!-- Honeypot -->
                        <div class="sbt-feature-item <?php echo !empty($settings['enable_trap']) ? 'active' : 'inactive'; ?>">
                            <div class="sbt-feature-indicator <?php echo !empty($settings['enable_trap']) ? 'on' : ''; ?>"></div>
                            <div>
                                <div class="sbt-feature-label">Honeypot</div>
                            </div>
                        </div>

                        <!-- Browser Check -->
                        <div class="sbt-feature-item <?php echo !empty($settings['enable_outdated_browser_check']) ? 'active' : 'inactive'; ?>">
                            <div class="sbt-feature-indicator <?php echo !empty($settings['enable_outdated_browser_check']) ? 'on' : ''; ?>"></div>
                            <div>
                                <div class="sbt-feature-label">Browser Check</div>
                            </div>
                        </div>

                        <!-- Geo-Quiz -->
                        <div class="sbt-feature-item <?php echo !empty($settings['enable_geo_quiz']) ? 'active' : 'inactive'; ?>">
                            <div class="sbt-feature-indicator <?php echo !empty($settings['enable_geo_quiz']) ? 'on' : ''; ?>"></div>
                            <div>
                                <div class="sbt-feature-label">Geo-Quiz</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cleanup Schedule -->
                <div class="sbt-cleanup-section">
                    <div class="sbt-cleanup-items">
                        <div class="sbt-cleanup-item">
                            <span class="sbt-cleanup-label">Clean Expired Bans</span>
                            <span class="sbt-cleanup-time"><?php echo esc_html($this->get_next_scheduled_time('sbt_cleanup_expired')); ?></span>
                        </div>
                        <div class="sbt-cleanup-item">
                            <span class="sbt-cleanup-label">Clean Transients</span>
                            <span class="sbt-cleanup-time"><?php echo esc_html($this->get_next_scheduled_time('sbt_cleanup_transients')); ?></span>
                        </div>
                    </div>
                </div>
            </div>
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
