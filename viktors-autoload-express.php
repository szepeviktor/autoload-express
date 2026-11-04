<?php
/**
 * Plugin Name: Viktor's Autoload Express
 * Text Domain: viktors-autoload-express
 * Description: Builds Composer classmaps at shutdown and applies them on subsequent requests.
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

(new \RoboViktor\AutoloadClassmap\Plugin())->register();
