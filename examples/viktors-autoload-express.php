<?php
/**
 * Plugin Name: Viktor's Autoload Express
 * Version: 1.0.1
 * Description: Loads the plugin from the viktors-autoload-express subdirectory.
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/viktors-autoload-express/viktors-autoload-express.php';
