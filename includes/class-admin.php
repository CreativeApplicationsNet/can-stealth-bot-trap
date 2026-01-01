<?php
if (!defined('ABSPATH')) exit;

class SBT_Admin {
    private $core;
    private $option_key = 'sbt_settings';

    public function __construct($core_instance) {
            $this->core = $core_instance;

            add_action('admin_menu', [$this, 'admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);

            // This forces the table check every time you view the admin settings
            add_action('admin_init', [$this->core, 'create_tables']);

            add_action('admin_post_sbt_clear_logs', [$this, 'clear_logs']);
            add_action('admin_post_sbt_remove_ip', [$this, 'remove_ip']);
            add_action('admin_post_sbt_unblock_all', [$this, 'unblock_all']);
        }

    /* ---------------------------
     * ADMIN MENU
     * ------------------------- */

    public function admin_menu() {
        add_options_page(
            'CAN Stealth Bot Trap',
            'CAN Stealth Bot Trap',
            'manage_options',
            'stealth-bot-trap',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('sbt_settings_group', $this->option_key);
    }

    public function settings_page() {
        $opts = $this->core->get_settings();

        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $offset = ($current_page - 1) * $per_page;

        $logs = $this->core->get_active_logs($per_page, $offset);
        $total_logs = $this->core->get_total_active_logs();
        $total_pages = ceil($total_logs / $per_page);

        $current_mode = isset($opts['block_mode']) ? $opts['block_mode'] : 'standard';

        ?>
        <div class="wrap">
            <h1>CAN Stealth Bot Trap</h1>

            <p>This plugin is provided free of charge and is open-source. If you are finding it useful, <a href="https://buy.stripe.com/3cscNx6469g00Sc288" target="_blank">please donate</a>. Every little helps!</p>

            <p>CAN Stealth Bot Trap implements a multi-layered defense system that identifies and blocks suspicious visitors while allowing legitimate traffic through. Rather than relying on a single detection method, the plugin uses a sophisticated combination of techniques to catch bots, scrapers, and automated attacks before they can damage your site.</p>

            <?php $this->render_notices(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('sbt_settings_group'); ?>

                <table class="form-table">
                    <tr>
                        <th>Ban duration (hours)</th>
                        <td>
                            <input type="number" name="<?= $this->option_key ?>[ban_hours]"
                                   value="<?= esc_attr($opts['ban_hours'] ?? 6) ?>" min="1">
                            <p class="description">For how long do you want to ban suspicious visitors.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Requests per minute limit</th>
                        <td>
                            <input type="number" name="<?= $this->option_key ?>[rate_limit]"
                                   value="<?= esc_attr($opts['rate_limit'] ?? 80) ?>" min="10">
                            <p class="description">A 60-second limit on user actions where 1–60 RPM is "strict" protection, 60–100 is "optimal" for most sites, and 100+ is "relaxed".</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Test Mode (log only, don't block)</th>
                        <td>
                            <input type="checkbox" name="<?= $this->option_key ?>[test_mode]"
                                   value="1" <?= checked(!empty($opts['test_mode']), 1) ?>>
                            <p class="description">Enable to see what would be blocked without actually blocking.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Enable JavaScript Check</th>
                        <td>
                            <input type="checkbox" name="<?= $this->option_key ?>[enable_js_check]"
                                   value="1" <?= checked(!empty($opts['enable_js_check']), 1) ?>>
                            <p class="description">Require JavaScript execution to access REST API endpoints. Blocks headless browsers and basic scrapers.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Whitelisted Browsers (JS Check)</th>
                        <td>
                            <textarea name="<?= $this->option_key ?>[whitelisted_browsers]"
                                   style="width: 100%;"
                                   placeholder="brave, firefox, safari" rows="1"><?= esc_attr($opts['whitelisted_browsers'] ?? 'brave,firefox') ?></textarea>
                            <p class="description">
                                Comma-separated list of browsers to skip JavaScript verification check.
                                Enter browser names as they appear in the User-Agent string (e.g., brave, firefox, safari, edge).
                                These browsers won't be banned for missing the JS cookie.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th>Enable Rate Limiting</th>
                        <td>
                            <input type="checkbox" name="<?= $this->option_key ?>[enable_rate_limit]"
                                   value="1" <?= checked(!empty($opts['enable_rate_limit']), 1) ?>>
                                   <p class="description">Block IPs that exceed the request limit per minute. Catches fast scrapers and automated attacks.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Enable Hidden Trap</th>
                        <td>
                            <input type="checkbox" name="<?= $this->option_key ?>[enable_trap]"
                                   value="1" <?= checked(!empty($opts['enable_trap']), 1) ?>>
                            <p class="description">Ban IPs that access the hidden /.bot-trap URL. Detects crawlers scanning for vulnerabilities.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Custom Honeypot URL</th>
                        <td>
                            <input type="text" name="<?= $this->option_key ?>[honeypot_url]"
                                   value="<?= esc_attr($opts['honeypot_url'] ?? 'bot-trap') ?>"
                                   placeholder="bot-trap"
                                   pattern="[a-z0-9\-]+"
                                   style="width: 300px;">
                            <p class="description">
                                A hidden URL path that only automated scanners will access. Use lowercase letters, numbers, and hyphens only.
                                <br><strong>Default:</strong> bot-trap
                                <br><strong>Example:</strong> security-check, system-verify, admin-panel
                                <br>The hidden URL will be: <code>/.<?= esc_html($opts['honeypot_url'] ?? 'bot-trap') ?></code>
                                <br><strong>Injected into footer:</strong> Only for logged-out visitors
                            </p>
                        </td>
                    </tr>


                    <tr>
                        <th>Block Outdated Browsers</th>
                        <td>
                            <input type="checkbox" name="<?= $this->option_key ?>[enable_outdated_browser_check]"
                                   value="1" <?= checked(!empty($opts['enable_outdated_browser_check']), 1) ?>>
                            <p class="description">Block Chrome <120, Firefox <115, Safari <15 (catches Puppeteer/Selenium scrapers).</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Use Custom Block Page</th>
                        <td>
                            <input type="checkbox"
                                   name="<?= $this->option_key ?>[enable_block_page]"
                                   value="1"
                                   <?= checked(!empty($opts['enable_block_page']), 1) ?>>
                            <p class="description">
                                Redirect blocked visitors to a minimal block page instead of a blank response.
                            </p>

                            <?php if (!empty($opts['enable_block_page'])): ?>
                                <p>
                                    <a href="<?= esc_url(admin_url('?sbt_preview_block=1')) ?>"
                                       class="button"
                                       target="_blank">
                                        Preview Block Page
                                    </a>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Block Mode</th>
                        <td>
                            <select name="sbt_settings[block_mode]" id="sbt_block_mode">
                                <option value="standard" <?php selected($current_mode, 'standard'); ?>>Standard (Static Block Page)</option>
                                <option value="quiz" <?php selected($current_mode, 'quiz'); ?>>Interactive (Math Quiz)</option>
                            </select>
                            <p class="description">Standard simply blocks the user. Quiz allows them to solve a math problem to unblock themselves.</p>
                        </td>
                    </tr>

                    <!-- Geo-Locking Section (Only visible if Quiz mode is selected) -->
                    <tr id="geo_quiz_enable_row" style="<?php echo $current_mode !== 'quiz' ? 'display: none;' : ''; ?>">
                        <th>Force Quiz by Country</th>
                        <td>
                            <input type="checkbox" name="<?= $this->option_key ?>[enable_geo_quiz]"
                                   value="1" id="sbt_enable_geo_quiz" <?= checked(!empty($opts['enable_geo_quiz']), 1) ?>>
                            <p class="description">When enabled, force all visitors from specified countries to solve the quiz first.</p>
                        </td>
                    </tr>

                    <!-- Country Codes to Block (Only visible if geo-quiz is enabled AND quiz mode is selected) -->
                    <tr id="geo_countries_row" style="<?php echo $current_mode !== 'quiz' || empty($opts['enable_geo_quiz']) ? 'display: none;' : ''; ?>">
                        <th>Countries to Block (ISO 2-letter)</th>
                        <td>
                            <textarea name="<?= $this->option_key ?>[geo_quiz_countries]"
                                   style="width: 100%;"
                                   placeholder="CN, RU, VN" rows="3"><?= esc_attr($opts['geo_quiz_countries'] ?? '') ?></textarea>
                            <p class="description">
                                Comma-separated ISO country codes to force quiz on. Visitors from these countries must solve the math quiz.
                                <br><strong>Example:</strong> CN, RU, VN, KP
                                <br><a href="https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2" target="_blank">View ISO country codes</a>
                            </p>
                        </td>
                    </tr>

                    <!-- GeoIP Service Info (Only visible if geo-quiz is enabled AND quiz mode is selected) -->
                    <tr id="geo_service_row" style="<?php echo $current_mode !== 'quiz' || empty($opts['enable_geo_quiz']) ? 'display: none;' : ''; ?>">
                        <th>GeoIP Lookup Service</th>
                        <td>
                            <p class="description" style="margin: 0;">
                                Currently using: <strong>ipapi.co</strong> (Primary) with <strong>IP2Location.io</strong> (Fallback)
                                <br><strong>ipapi.co:</strong> Free tier: 30,000 requests/month, no API key required
                                <br><strong>IP2Location.io:</strong> Free demo key as backup, 10,000 requests/day
                                <br>Results are cached for 24 hours to minimize API calls.
                                <br>If you need higher limits, upgrade at
                                <a href="https://ipapi.co" target="_blank">ipapi.co</a> or
                                <a href="https://ip2location.io" target="_blank">ip2location.io</a>
                            </p>
                        </td>
                    </tr>

                </table>

                <?php submit_button(); ?>
            </form>

            <h2>Blocked IPs Log (Active Only) — <?= $total_logs ?> total records</h2>
            <?php $this->render_logs_table($logs, $current_page, $total_pages); ?>
        </div>

        <script>
            // Show/hide geo-locking options based on block mode and checkbox
            document.addEventListener('DOMContentLoaded', function() {
                const blockModeSelect = document.getElementById('sbt_block_mode');
                const geoQuizCheckbox = document.getElementById('sbt_enable_geo_quiz');
                const geoQuizRow = document.getElementById('geo_quiz_enable_row');
                const geoCountriesRow = document.getElementById('geo_countries_row');
                const geoServiceRow = document.getElementById('geo_service_row');

                function updateGeoVisibility() {
                    const isQuizMode = blockModeSelect.value === 'quiz';
                    const isGeoEnabled = geoQuizCheckbox && geoQuizCheckbox.checked;

                    // Show/hide geo-quiz checkbox based on quiz mode
                    geoQuizRow.style.display = isQuizMode ? '' : 'none';

                    // Show/hide country and service rows based on both quiz mode AND geo-quiz being enabled
                    geoCountriesRow.style.display = (isQuizMode && isGeoEnabled) ? '' : 'none';
                    geoServiceRow.style.display = (isQuizMode && isGeoEnabled) ? '' : 'none';
                }

                blockModeSelect.addEventListener('change', updateGeoVisibility);
                if (geoQuizCheckbox) {
                    geoQuizCheckbox.addEventListener('change', updateGeoVisibility);
                }
                updateGeoVisibility();
            });
        </script>
        <?php
    }

    private function render_notices() {
        if (isset($_GET['unblocked'])) {
            echo '<div class="notice notice-success is-dismissible">
                <p><strong>IP ' . esc_html($_GET['unblocked']) . ' has been unblocked.</strong></p>
            </div>';
        }

        if (isset($_GET['cleared'])) {
            echo '<div class="notice notice-success is-dismissible">
                <p><strong>All logs have been cleared.</strong></p>
            </div>';
        }

        if (isset($_GET['unblocked_all'])) {
            echo '<div class="notice notice-success is-dismissible">
                <p><strong>All IPs have been unblocked.</strong></p>
            </div>';
        }
    }

    private function render_logs_table($logs, $current_page, $total_pages) {
        if (empty($logs)) {
            echo '<p>No active bans.</p>';
            return;
        }

        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>IP</th>
                    <th>Reason</th>
                    <th>Banned At</th>
                    <th>Expires</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $entry) : ?>
                    <tr>
                        <td><?= esc_html($entry['ip']) ?></td>
                        <td><?= esc_html($entry['reason']) ?></td>
                        <td><?= esc_html($entry['banned_at']) ?></td>
                        <td><?= esc_html($entry['expires_at']) ?></td>
                        <td>
                            <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline;">
                                <input type="hidden" name="action" value="sbt_remove_ip">
                                <input type="hidden" name="ip" value="<?= esc_attr($entry['ip']) ?>">
                                <?php wp_nonce_field('sbt_remove_ip_nonce'); ?>
                                <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Unblock this IP?');">Unblock</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                $page_links = paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $current_page,
                    'type' => 'array'
                ]);

                if ($page_links) {
                    echo implode("\n", $page_links);
                }
                ?>
            </div>
        </div>

        <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline;">
            <input type="hidden" name="action" value="sbt_unblock_all">
            <?php wp_nonce_field('sbt_unblock_all_nonce'); ?>
            <button type="submit" class="button button-primary" onclick="return confirm('Unblock all currently banned IPs?');">Unblock All</button>
        </form>

        <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline; margin-left: 10px;">
            <input type="hidden" name="action" value="sbt_clear_logs">
            <?php wp_nonce_field('sbt_clear_logs_nonce'); ?>
            <button type="submit" class="button button-secondary" onclick="return confirm('Clear all logs? Active blocks will remain until they expire.');">Clear All Logs</button>
        </form>
        <?php
    }

    /* ---------------------------
     * LOG MANAGEMENT
     * ------------------------- */

    public function remove_ip() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'sbt_remove_ip_nonce')) {
            wp_die('Security check failed');
        }

        $ip = isset($_POST['ip']) ? sanitize_text_field($_POST['ip']) : '';

        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_die('Invalid IP address');
        }

        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . 'sbt_blocked_ips',
            ['ip' => $ip],
            ['%s']
        );

        delete_transient('sbt_ban_' . md5($ip));

        wp_redirect(admin_url('options-general.php?page=stealth-bot-trap&unblocked=' . urlencode($ip)));
        exit;
    }

    public function clear_logs() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'sbt_clear_logs_nonce')) {
            wp_die('Security check failed');
        }

        global $wpdb;
        $current_time = current_time('mysql');

        $banned_ips = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT ip FROM {$wpdb->prefix}sbt_blocked_ips
                 WHERE expires_at > %s",
                $current_time
            )
        );

        foreach ($banned_ips as $ip) {
            delete_transient('sbt_ban_' . md5($ip));
        }

        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sbt_blocked_ips");

        wp_redirect(admin_url('options-general.php?page=stealth-bot-trap&cleared=1'));
        exit;
    }

    public function unblock_all() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'sbt_unblock_all_nonce')) {
            wp_die('Security check failed');
        }

        global $wpdb;
        $current_time = current_time('mysql');

        $banned_ips = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT ip FROM {$wpdb->prefix}sbt_blocked_ips
                 WHERE expires_at > %s",
                $current_time
            )
        );

        foreach ($banned_ips as $ip) {
            delete_transient('sbt_ban_' . md5($ip));
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}sbt_blocked_ips
                 WHERE expires_at > %s",
                $current_time
            )
        );

        wp_redirect(admin_url('options-general.php?page=stealth-bot-trap&unblocked_all=1'));
        exit;
    }
}
