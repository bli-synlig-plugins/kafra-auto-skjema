<?php
/**
 * Plugin Name: Kafra Auto Skjema
 * Description: Fyller automatisk ut Gravity Forms-felter basert på WooCommerce-produktet man er inne på.
 * Version: 1.0.0
 * Author: Benjamin
 * Text Domain: kafra-auto-skjema
 * Requires Plugins: woocommerce, gravityforms
 */

if (!defined('ABSPATH')) exit;

define('KAS_VERSION', '1.0.0');
define('KAS_PLUGIN_FILE', __FILE__);
define('KAS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KAS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KAS_OPTION_KEY', 'kas_settings');

require_once KAS_PLUGIN_DIR . 'includes/class-kas-plugin.php';
require_once KAS_PLUGIN_DIR . 'includes/class-kas-settings.php';
require_once KAS_PLUGIN_DIR . 'includes/class-kas-populate.php';
require_once KAS_PLUGIN_DIR . 'includes/class-kas-frontend.php';

add_action('plugins_loaded', array('KAS_Plugin', 'init'));
