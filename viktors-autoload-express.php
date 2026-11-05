<?php
/**
 * Plugin Name: Viktor's Autoload Express
 * Version: 1.0.1
 * Text Domain: viktors-autoload-express
 * Description: Builds Composer classmaps at shutdown and applies them on subsequent requests.
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('WP_INSTALLING') && WP_INSTALLING) {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

(new \RoboViktor\AutoloadClassmap\Plugin())->register();
