<?php
/**
 * Plugin Name:       Community Race Calendar
 * Description:       Private community race calendar with registrations, carpooling, results and a map, restricted to your members.
 * Version:           0.5.25
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            Ludovic Lardies
 * Author URI:        https://lardies.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       community-race-calendar
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace CommunityRaceCalendar;

if (!defined('ABSPATH')) {
    exit;
}

define('CRCAL_VERSION', '0.5.25');
define('CRCAL_SCHEMA_VERSION', '1.7.0');
define('CRCAL_PLUGIN_FILE', __FILE__);
define('CRCAL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CRCAL_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CRCAL_PLUGIN_DIR . 'src/Access/AccessProviderInterface.php';
require_once CRCAL_PLUGIN_DIR . 'src/Access/WooCommerceMembershipsAccessProvider.php';
require_once CRCAL_PLUGIN_DIR . 'src/Access/AccessGate.php';
require_once CRCAL_PLUGIN_DIR . 'src/Geocoding/GeocodingProviderInterface.php';
require_once CRCAL_PLUGIN_DIR . 'src/Geocoding/GeoapifyGeocodingProvider.php';
require_once CRCAL_PLUGIN_DIR . 'src/Domain/CalendarPeriod.php';
require_once CRCAL_PLUGIN_DIR . 'src/Domain/MemberName.php';
require_once CRCAL_PLUGIN_DIR . 'src/Media/CourseImageManager.php';
require_once CRCAL_PLUGIN_DIR . 'src/Database/Migrator.php';
require_once CRCAL_PLUGIN_DIR . 'src/Repository/CalendarRepository.php';
require_once CRCAL_PLUGIN_DIR . 'src/Rest/RoutesController.php';
require_once CRCAL_PLUGIN_DIR . 'src/Admin/SettingsPage.php';
require_once CRCAL_PLUGIN_DIR . 'src/Plugin.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
add_action('plugins_loaded', [Plugin::class, 'boot']);
