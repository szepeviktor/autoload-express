<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/probes.php';
$GLOBALS['classmap_test_hashes'] = 0;
$GLOBALS['classmap_test_writes'] = 0;

use Composer\Autoload\ClassLoader;
use RoboViktor\AutoloadClassmap\Plugin;

function add_action(...$arguments): void {
    $GLOBALS['test_hooks'][$arguments[0]][] = $arguments;
}
function add_filter(...$arguments): void {}
function do_action(string $hook, ...$arguments): void
{
    $callbacks = $GLOBALS['test_hooks'][$hook] ?? [];
    usort($callbacks, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
    foreach ($callbacks as $callback) {
        ($callback[1])(...array_slice($arguments, 0, $callback[3] ?? 1));
    }
}
function apply_filters(string $hook, mixed $value, ...$arguments): mixed
{
    if ($hook === 'rv_autoload_classmap_scan_directories') {
        $value[] = WP_PLUGIN_DIR . '/manual';
    }
    return $value;
}
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/runtime-autoload-test-' . bin2hex(random_bytes(6));
mkdir($root . '/plugins/a/src', 0750, true);
mkdir($root . '/plugins/a/second', 0750, true);
mkdir($root . '/plugins/b/src', 0750, true);
mkdir($root . '/plugins/legacy/Legacy', 0750, true);
define('WP_PLUGIN_DIR', $root . '/plugins');
define('WP_CONTENT_DIR', $root);
if (in_array('--restricted-cwd', $argv, true)) {
    chdir('/');
    ini_set('open_basedir', dirname(__DIR__) . PATH_SEPARATOR . $root);
    set_error_handler(static function (int $severity, string $message): bool {
        if (str_contains($message, 'open_basedir')) {
            throw new ErrorException($message, 0, $severity);
        }
        return false;
    });
}
ini_set('error_log', $root . '/errors.log');
file_put_contents($root . '/plugins/a/a.php', '<?php // Plugin A');
file_put_contents($root . '/plugins/b/b.php', '<?php // Plugin B');
file_put_contents($root . '/plugins/a/src/Widget.php', '<?php namespace Fixture; class Widget {}');
file_put_contents($root . '/plugins/a/second/Widget.php', '<?php namespace Fixture; class Widget { public const SECOND = true; }');
file_put_contents($root . '/plugins/b/src/Widget.php', '<?php namespace Fixture; class Widget { public const B = true; }');
file_put_contents($root . '/plugins/a/src/Contract.php', '<?php namespace Fixture; interface Contract {}');
file_put_contents($root . '/plugins/a/src/Feature.php', '<?php namespace Fixture; trait Feature {}');
file_put_contents($root . '/plugins/a/learned.php', '<?php class LearnedOutsidePsr {}');
file_put_contents($root . '/plugins/legacy/Legacy/Thing.php', '<?php class Legacy_Thing {}');
file_put_contents($root . '/plugins/b/src/Fallback.php', '<?php class Fallback {}');
require $root . '/plugins/a/learned.php';
mkdir($root . '/plugins/manual');
file_put_contents($root . '/plugins/manual/odd-name.php', '<?php class ManualScannedOnly {} interface ManualContract {} trait ManualTrait {} enum ManualEnum { case One; }');
file_put_contents($root . '/plugins/a/loaded-winner.php', '<?php namespace Fixture; class Widget {}');
require $root . '/plugins/a/loaded-winner.php';

// Test a separately namespaced Composer ClassLoader too.
$source = file_get_contents(dirname(__DIR__) . '/vendor/composer/ClassLoader.php');
$source = str_replace('namespace Composer\\Autoload;', 'namespace Scoped\\Composer\\Autoload;', $source);
file_put_contents($root . '/ScopedLoader.php', $source);
require $root . '/ScopedLoader.php';

$create = static function () use ($root): array {
    $a = new ClassLoader();
    $a->setPsr4('Fixture\\', [$root . '/plugins/a/second', $root . '/plugins/a/src']);
    $a->register();
    $b = new Scoped\Composer\Autoload\ClassLoader();
    $b->setPsr4('Fixture\\', [$root . '/plugins/b/src']);
    $b->setPsr4('', [$root . '/plugins/b/src']);
    $b->register();
    return [$a, $b];
};
$legacy = new ClassLoader();
$legacy->set('Legacy_', [$root . '/plugins/legacy']);
$legacy->setUseIncludePath(true);
$legacy->register();
$file = $root . '/cache/runtime-autoload/classmap.php';
[$a, $b] = $create();
// Simulate a non-Composer loader with a narrow prefix guard.
$custom = static function (string $class): void {};
spl_autoload_register($custom);
$first = new Plugin();
$first->register();
$previousDirectory = getcwd();
$first->shutdown();
check(getcwd() === ($previousDirectory === '/' ? $root : $previousDirectory), 'Generator did not restore/retain an allowed working directory');
check(is_file($file), 'Classmap was not created');
$map = require $file;
check($map['Fixture\\Widget'] === $root . '/plugins/a/loaded-winner.php', 'Loaded class must win a global collision');
check(isset($map['ManualScannedOnly'], $map['ManualContract'], $map['ManualTrait'], $map['ManualEnum']), 'Manual scan lost declarations');
check(isset($map['Fixture\\Contract'], $map['Fixture\\Feature']), 'Interface or trait not scanned');
check(isset($map['Legacy_Thing'], $map['Fallback']), 'PSR-0 or fallback mapping missing');
$learned = require $file . '.learned.php';
check(isset($learned['LearnedOutsidePsr']), 'Learned class was not persisted');
$log = file_get_contents($root . '/errors.log');
check(str_contains($log, '[WARNING] Duplicate Fixture\\Widget'), 'Cross-plugin collision warning missing');
$state = json_decode(file_get_contents(dirname($file) . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
check(count(array_filter(array_keys($state['inventory']), static fn ($label) => str_starts_with($label, 'Closure@'))) >= 1, 'Custom loader not inventoried');
$a->unregister();
$b->unregister();
$legacy->unregister();
$legacy = new ClassLoader();
$legacy->set('Legacy_', [$root . '/plugins/legacy']);
$legacy->setUseIncludePath(true);
$legacy->register();
[$a, $b] = $create();
$GLOBALS['test_hooks'] = [];
$second = new Plugin();
$second->register();
check(!isset($GLOBALS['test_hooks']['shutdown']), 'Cache hit registered a shutdown callback');
$firstCallback = spl_autoload_functions()[0];
check($firstCallback instanceof Closure, 'Global loader was not prepended');
$intruder = static function (string $class): void {};
spl_autoload_register($intruder, true, true);
$second->apply();
check(spl_autoload_functions()[0] === $firstCallback, 'Global loader did not reclaim first place');
spl_autoload_unregister($intruder);
check(class_exists('manualscannedonly'), 'Global map did not load manual class');
check(interface_exists('ManualContract'), 'Global map did not load manual interface');
check(trait_exists('ManualTrait'), 'Global map did not load manual trait');
check(enum_exists('ManualEnum'), 'Global map did not load manual enum');
check($a->isClassMapAuthoritative(), 'Regular Composer loader did not become authoritative');
check($b->isClassMapAuthoritative(), 'Prefixed Composer loader did not become authoritative');
check(!$legacy->isClassMapAuthoritative(), 'include_path loader was unsafely made authoritative');
check(isset($legacy->getClassMap()['Legacy_Thing']), 'Partial map optimization was not applied');
check($a->findFile('Fixture\\Widget') === $root . '/plugins/a/second/Widget.php', 'Applied map resolved wrong file');
check($a->findFile('Fixture\\Missing') === false, 'Missing class unexpectedly resolved');
check(!isset($a->getClassMap()['LearnedOutsidePsr']), 'Observed class was unsafely injected');
file_put_contents($root . '/plugins/a/src/Added.php', '<?php namespace Fixture; class Added {}');
$cached = json_decode(file_get_contents(dirname($file) . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($cached['loaders'] as &$entry) {
    $entry['generated'] = 1; // Old age alone must never trigger a scan.
}
unset($entry);
file_put_contents(dirname($file) . '/state.json', json_encode($cached, JSON_THROW_ON_ERROR));
$before = file_get_contents(dirname($file) . '/state.json');
$GLOBALS['classmap_test_hashes'] = 0;
$GLOBALS['classmap_test_writes'] = 0;
$second->shutdown();
check($GLOBALS['classmap_test_hashes'] === 0, 'Cache-hit shutdown rehashed source files');
check($GLOBALS['classmap_test_writes'] === 0, 'Cache-hit shutdown wrote cache files');
check(file_get_contents(dirname($file) . '/state.json') === $before, 'Cache-hit shutdown rebuilt state');
$unchanged = require $file;
check(!isset($unchanged['Fixture\\Added']), 'Cached map expired based on time');
file_put_contents($root . '/plugins/a/observed.php', '<?php class NewlyObservedOnly {}');
require $root . '/plugins/a/observed.php';
$second->shutdown();
$learned = require $file . '.learned.php';
check(!isset($learned['NewlyObservedOnly']), 'Cache-hit shutdown observed new classes');
check(file_get_contents(dirname($file) . '/state.json') === $before, 'Observation unnecessarily rebuilt Composer maps');
check($GLOBALS['classmap_test_hashes'] === 0, 'Observation triggered collision rehashing');

mkdir($root . '/plugins/late/src', 0750, true);
file_put_contents($root . '/plugins/late/src/Example.php', '<?php namespace Late; class Example {}');
$late = new ClassLoader();
$late->setPsr4('Late\\', [$root . '/plugins/late/src']);
$late->register();
$second->shutdown();
$incremental = require $file;
check(!isset($incremental['Late\\Example']), 'Cache-hit shutdown generated a late loader');
check(!isset($incremental['Fixture\\Added']), 'Late loader triggered a rescan of existing loaders');
$late->unregister();
unlink(dirname($file) . '/state.json'); // Explicit manual-deploy invalidation.
$a->unregister();
$b->unregister();
$legacy->unregister();
$legacy = new ClassLoader();
$legacy->set('Legacy_', [$root . '/plugins/legacy']);
$legacy->setUseIncludePath(true);
$legacy->register();
[$a, $b] = $create();
$third = new Plugin();
$third->register();
$third->shutdown(); // A new cache-miss request rebuilds from original rules.
$updated = require $file;
check(isset($updated['Fixture\\Added']), 'Rebuild could not discover new class behind authoritative loader');
check(substr_count(file_get_contents($root . '/errors.log'), '[WARNING] Duplicate Fixture\\Widget') === 2, 'Expected one warning per state lifecycle');
$validState = file_get_contents(dirname($file) . '/state.json');
$third->invalidate(true);
check(!is_file(dirname($file) . '/state.json'), 'Upgrade did not invalidate state');
$third->shutdown();
check(!is_file(dirname($file) . '/state.json'), 'Upgrade request republished stale state');

foreach (['activate_plugin', 'deactivate_plugin', 'switch_theme', 'add_option_active_plugins', 'update_option_active_plugins', 'add_site_option_active_sitewide_plugins', 'update_site_option_active_sitewide_plugins'] as $hook) {
    file_put_contents(dirname($file) . '/state.json', $validState);
    $GLOBALS['test_hooks'] = [];
    $lifecycle = new Plugin();
    $lifecycle->register();
    check(!isset($GLOBALS['test_hooks']['shutdown']), 'Lifecycle cache hit registered shutdown');
    $globalMap = new ReflectionProperty(Plugin::class, 'globalMap');
    check($globalMap->getValue($lifecycle) !== [], 'Lifecycle test did not start with a global map');
    do_action($hook, 'example/plugin.php', true, ['old'], ['new']);
    check(!is_file(dirname($file) . '/state.json'), $hook . ' did not invalidate state');
    check($globalMap->getValue($lifecycle) === [], $hook . ' retained the in-memory global map');
    $GLOBALS['classmap_test_writes'] = 0;
    $lifecycle->shutdown();
    check(!is_file(dirname($file) . '/state.json'), $hook . ' republished stale state');
    check($GLOBALS['classmap_test_writes'] === 0, $hook . ' wrote at shutdown');
    do_action($hook); // Repeated events are harmless and accept no arguments.
}
$GLOBALS['test_hooks'] = [];
$uncached = new Plugin();
$uncached->register();
check(isset($GLOBALS['test_hooks']['shutdown']), 'Cache miss did not register shutdown');
do_action('activate_plugin', 'example/new.php', false);
do_action('shutdown');
check(!is_file(dirname($file) . '/state.json'), 'Cache-miss activation request built an obsolete snapshot');
$a->unregister();
$b->unregister();
$legacy->unregister();
spl_autoload_unregister($custom);
echo "PASS: generation, priority, interfaces/traits, learned classes, collisions, prefixed loaders, authoritative reload, zero-write/zero-hash cache hits, manual classmap scanning, global loading, first-position recovery, cache-hit observation/build suppression, regeneration, upgrade/plugin/theme/network invalidation.\nArtifacts: $root\n";
