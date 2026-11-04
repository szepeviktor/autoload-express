# Viktor's Autoload Express

**We cut 200 ms from WordPress requests. Just by optimizing autoloading.**

Viktor's Autoload Express reduces PHP autoloading overhead by loading classes
directly from a shared classmap. It combines discoverable Composer mappings,
classes observed during a request, and explicitly configured directories for
custom autoloaders. Existing autoloaders remain available for unmapped classes.

The performance benefit depends on your plugin stack; measure on staging before
deploying.

PHP 8.1+ WordPress MU-plugin. No settings screen or remote requests.

## Installation

Install production dependencies with an optimized autoloader from the repository root:

```bash
composer install --no-dev -o
```

Copy this directory, including `vendor`, to `wp-content/mu-plugins/viktors-autoload-express/`.
Copy [`examples/viktors-autoload-express.php`](examples/viktors-autoload-express.php)
to `wp-content/mu-plugins/viktors-autoload-express.php`. This MU bootstrap loads
the plugin from the `viktors-autoload-express` subdirectory.

The constructor defines the paths:

- `WP_CONTENT_DIR/cache/runtime-autoload/classmap.php`
- `WP_CONTENT_DIR/cache/runtime-autoload/state.json`

The PHP worker needs write access to that cache directory. Untrusted users must not be able to modify executable PHP map files.

## Three classmap sources

1. Edit `scan-directories.php` for plugins with custom autoloaders. The default list includes the YITH Ajax Product Filter Premium `includes` directory. These are plain recursive Composer classmap scans, independent of filenames and PSR rules. Only list trusted directories intended to participate in loading; listed directories are scanned even if their plugin is inactive. The `rv_autoload_classmap_scan_directories` filter can extend/replace the list at build time.
2. Every registered SPL callback is inventoried. Standard and scoped Composer loaders expose explicit classmaps, PSR-4/PSR-0 prefixes and fallback directories. Composer resolves scanned candidates using the original directory precedence. Arbitrary callbacks do not expose discoverable rules; use the manual list for them.
3. Reflection captures already declared user classes, interfaces, traits and enums with real source files. Internal/anonymous/eval declarations and aliases are excluded. An already loaded declaration wins a collision in the global map.

Composer's official `composer/class-map-generator` finds declarations without executing scanned files. Runtime uses one case-insensitive lookup into a shared map, followed by `require_once`. Existing loaders remain registered as fallbacks. Map misses do not stop the SPL chain; this is not globally authoritative and cannot suppress repeated missing-class lookups such as the YITH singleton bug.

Loaded files can have side effects, conditional declarations or activation requirements. A declaration found by a scanner is not proof it is safe to load early. Limit manual directories accordingly and validate on staging before deployment.

Eligible Composer instances also receive their matching optimized maps and become authoritative. Loaders using include_path remain non-authoritative. The `rv_autoload_classmap_authoritative` filter can disable this for a particular loader. Existing explicit mappings take precedence within that loader. Originally authoritative loaders retain their original class set.

## First position

The global callback is registered immediately with prepend enabled. At the end of `muplugins_loaded`, `plugin_loaded`, `network_plugin_loaded`, `plugins_loaded`, `after_setup_theme`, `init`, `wp_loaded`, `wp`, `template_redirect`, `admin_init` and `rest_api_init`, it checks its position and re-registers at the front only when displaced.

PHP has no exclusive first-position reservation or registration notification. A later prepend operation, including one inside a hook or at the same final priority, can temporarily displace this callback until the next checkpoint. No absolute always-first guarantee is claimed. Cache-hit requests do not add a shutdown checkpoint.

## Cache lifecycle

A cache hit requires schema version 4 state and a readable global PHP map returning an array. When present at registration, **no shutdown callback is registered**. Even an explicit call to `shutdown()` immediately returns: no inventory, Reflection, scans, stat calls, locks or writes. New route-specific classes/loaders do not augment the cache on such requests. Existing fallback loaders handle unmapped classes, subject to any individual Composer loader's authoritative setting.

Without cache, shutdown captures late loaders and builds from all three sources. A nonblocking generation lock prevents simultaneous builders. The builder rechecks whether another request has published a cache before scanning. Exceptions are logged and normal fallback loading continues.

`upgrader_pre_install` invalidates state before WordPress replaces files, clears the current global map, and prevents that request from generating a replacement. The next request rebuilds. Plugin activation/deactivation and theme switches also invalidate state and clear the in-memory global map. The `activate_plugin`, `deactivate_plugin` and `switch_theme` actions are supplemented by add/update hooks for the `active_plugins` option and the network `active_sitewide_plugins` option, covering silent changes and first-time option creation. Invalidation is idempotent and the changing request never rebuilds; the next ordinary cache-miss request generates the replacement. For manual deployments or scan-list changes, delete `state.json` before serving the new configuration. No age-based expiry or source-change probing occurs.

## Cache files and OPcache

- `classmap.php`: sorted Composer-compatible `return array('Class' => '/absolute/file.php');` union for inspection/export.
- `classmap.php.<sha256>.php`: immutable maps for individual Composer loaders and the global runtime map. State selects the runtime file; immutable names avoid stale PHP opcodes.
- `classmap.php.learned.php`: snapshot of observed declarations from the build request.
- `state.json`: schema, signatures, callback inventory, collision fingerprints and immutable map paths. Read directly as JSON, bypassing OPcache.
- `classmap.php.lock`: generation lock.

Writes use temporary files plus atomic rename, publish state last, and invalidate changed PHP output paths in the current OPcache. Old immutable files remain for concurrent requests; remove obsolete ones only during controlled maintenance.

## Collisions

Names are compared case-insensitively. Different plugin owners declaring one name produce a warning when file hashes differ, or an informational log when identical. Logs include source paths; declarations are never silently discarded from collision diagnostics. Loaded declarations win; otherwise current Composer callback order wins, followed by manual scan order. Custom loaders' hidden precedence cannot be inferred and must be reviewed when conflicts occur.

## Verification

```bash
php8.5 tests/integration.php
php8.5 tests/integration.php --restricted-cwd
```

Tests cover manual scans (classes/interfaces/traits/enums), global loading, case-insensitive lookup, loaded collision winners, Composer directory precedence, scoped loaders, PSR-0/fallback support, authoritative application, first-position recovery, no shutdown hook on cache hits, zero writes/hashes on cache-hit shutdown, suppression of incremental learning and late-loader builds, cache regeneration, update invalidation, plugin activation/deactivation, theme switches and silent/network option changes. These are local fixture tests, not a full WordPress staging validation.
