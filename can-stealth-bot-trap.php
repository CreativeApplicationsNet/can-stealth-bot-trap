<?php
/**
 * Plugin Name: CAN Stealth Bot Trap
 * Description: Silently blocks aggressive crawlers using behavior-based detection for logged-out users only.
 * Version: 2.5.8
  * Author: Creative Applications Network
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-stealth-bot-trap.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-detection-layers.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-dashboard.php';

// This global variable is the "Brain" of your plugin
global $sbt_core;

add_action('plugins_loaded', function() {
    global $sbt_core;
    $sbt_core = new SBT_Stealth_Bot_Trap();

    // Pass the core to the other classes so they share settings/database
    new SBT_Detection_Layers($sbt_core);
    new SBT_Admin($sbt_core);
});

// Activation logic (remains the same)
register_activation_hook(__FILE__, 'sbt_activate_plugin_logic');
function sbt_activate_plugin_logic() {
    if (!class_exists('SBT_Stealth_Bot_Trap')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-stealth-bot-trap.php';
    }
    $installer = new SBT_Stealth_Bot_Trap();
    $installer->activate_plugin();
}
