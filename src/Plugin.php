<?php

declare(strict_types=1);

namespace RoboViktor\AutoloadClassmap;

use Composer\ClassMapGenerator\ClassMapGenerator;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;
use WeakMap;

final class Plugin
{
    private WeakMap $seen;
    private bool $cacheAvailable = false;
    private \Closure $callback;
    private array $globalMap = [];
    private array $scanDirectories;

    private array $state = [];
    private array $inventory = [];
    private array $callbackLabels = [];
    private array $maps = [];
    private bool $invalidated = false;

    private string $classmapFile;
    private string $stateFile;

    public function __construct()
    {
        $this->classmapFile = WP_CONTENT_DIR . '/cache/runtime-autoload/classmap.php';
        $this->stateFile = WP_CONTENT_DIR . '/cache/runtime-autoload/state.json';
        $this->seen = new WeakMap();
        $this->scanDirectories = require dirname(__DIR__) . '/scan-directories.php';
        $this->callback = function (string $class): void {
            $path = $this->globalMap[strtolower($class)] ?? null;
            if ($path !== null) {
                require_once $path;
            }
        };
    }

    public function register(): void
    {
        $this->state = $this->readState();
        $map = isset($this->state['global_map']) ? $this->readMap($this->state['global_map']) : null;
        $this->cacheAvailable = $map !== null;
        if ($this->cacheAvailable) {
            foreach ($map as $class => $path) {
                $this->globalMap[strtolower($class)] = $path;
            }
        } else {
            $this->state = [];
        }
        foreach (['muplugins_loaded', 'plugin_loaded', 'network_plugin_loaded', 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'wp', 'template_redirect', 'admin_init', 'rest_api_init'] as $hook) {
            add_action($hook, [$this, 'apply'], PHP_INT_MAX, 0);
        }
        // Invalidate before WordPress replaces plugin/theme files.
        add_filter('upgrader_pre_install', [$this, 'invalidate'], 10, 1);
        foreach (['activate_plugin', 'deactivate_plugin', 'switch_theme', 'add_option_active_plugins', 'update_option_active_plugins', 'add_site_option_active_sitewide_plugins', 'update_site_option_active_sitewide_plugins'] as $hook) {
            // Option hooks also cover silent activation/deactivation and network changes.
            add_action($hook, [$this, 'invalidateCache'], PHP_INT_MIN, 0);
        }
        if (!$this->cacheAvailable) {
            add_action('shutdown', [$this, 'shutdown'], PHP_INT_MAX);
        }
        $this->apply();
    }

    public function invalidate(mixed $response): mixed
    {
        $this->invalidated = true;
        $this->state = [];
        $this->globalMap = [];
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
        return $response;
    }

    public function invalidateCache(): void
    {
        $this->invalidate(null);
    }

    /** Inspect every callback, including non-Composer and prefixed Composer loaders. */
    public function apply(): void
    {
        $this->prepend();
        foreach (spl_autoload_functions() ?: [] as $position => $callback) {
            if ($callback === $this->callback) {
                continue;
            }
            try {
                $key = $this->callbackKey($callback);
                $label = $this->callbackLabels[$key] ??= $this->label($callback);
                $this->inventory[$label] = $position;
                if (!$this->isComposer($callback)) {
                    continue;
                }
                $loader = $callback[0];
                if (isset($this->seen[$loader])) {
                    continue;
                }
                $descriptor = $this->describe($loader);
                $this->seen[$loader] = $descriptor;
                $saved = $this->state['loaders'][$descriptor['id']] ?? null;
                if (!$saved || $saved['signature'] !== $descriptor['signature'] || $this->invalidated) {
                    continue;
                }
                $map = $this->readMap($saved['file']);
                if ($map === null) {
                    continue;
                }
                $this->maps[$descriptor['id']] = $map;
                // Preserve mappings installed by the plugin itself.
                $loader->addClassMap($loader->getClassMap() + $map);
                if (($saved['complete'] ?? false) && apply_filters('rv_autoload_classmap_authoritative', true, $loader, $descriptor)) {
                    $loader->setClassMapAuthoritative(true);
                }
            } catch (Throwable $error) {
                $this->log('WARNING', 'Loader inspection failed: ' . $error->getMessage());
            }
        }
    }

    private function isComposer(mixed $callback): bool
    {
        if (!is_array($callback) || !is_object($callback[0]) || $callback[1] !== 'loadClass') {
            return false;
        }
        // instanceof of the unprefixed class would miss Strauss/PHP-Scoper loaders.
        $class = get_class($callback[0]);
        if (!str_ends_with($class, 'Composer\\Autoload\\ClassLoader')) {
            return false;
        }
        foreach (['getClassMap', 'getPrefixesPsr4', 'getPrefixes', 'getFallbackDirsPsr4', 'getFallbackDirs', 'getUseIncludePath', 'addClassMap', 'setClassMapAuthoritative', 'isClassMapAuthoritative', 'findFile'] as $method) {
            if (!method_exists($callback[0], $method)) {
                return false;
            }
        }
        return true;
    }

    private function describe(object $loader): array
    {
        $rules = [
            'psr4' => $loader->getPrefixesPsr4(),
            'psr0' => $loader->getPrefixes(),
            'fallback4' => $loader->getFallbackDirsPsr4(),
            'fallback0' => $loader->getFallbackDirs(),
            'include_path' => $loader->getUseIncludePath(),
            'classmap' => $loader->getClassMap(),
            'original_authoritative' => $loader->isClassMapAuthoritative(),
        ];
        $id = hash('sha256', serialize([get_class($loader), $rules]));
        // Rules are in memory already. Source changes require update-hook/manual
        // invalidation, not thousands of filesystem probes on every request.
        return ['id' => $id, 'signature' => $id, 'rules' => $rules];
    }

    /** Reclaim first place after plugins prepend their own callbacks. */
    public function prepend(): void
    {
        if ((spl_autoload_functions()[0] ?? null) === $this->callback) {
            return;
        }
        spl_autoload_unregister($this->callback);
        spl_autoload_register($this->callback, true, true);
    }

    public function shutdown(): void
    {
        // Cache availability is decided at registration. No inspection, Reflection,
        // filesystem probes, locks or writes at shutdown on a cache-hit request.
        if ($this->cacheAvailable || $this->invalidated) {
            return;
        }
        $this->apply();
        $directory = dirname($this->classmapFile);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            $this->log('WARNING', 'Cannot create ' . $directory);
            return;
        }
        $lock = fopen($this->classmapFile . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }
        try {
            // Another request may have published the cache while we were running.
            $published = $this->readState();
            if (!isset($published['global_map']) || !is_file($published['global_map'])) {
                $this->buildInWorkingDirectory();
            }
        } catch (Throwable $error) {
            $this->log('WARNING', 'Generation failed; previous maps retained: ' . $error->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function buildInWorkingDirectory(): void
    {
        // FPM can start in / even when open_basedir prohibits realpath('/').
        // Composer resolves getcwd() internally, including for absolute inputs.
        $previous = getcwd();
        if (!chdir(WP_CONTENT_DIR)) {
            throw new \RuntimeException('Cannot enter the WordPress content directory');
        }
        try {
            $this->build();
        } finally {
            // Restoring an already-forbidden FPM cwd would itself emit a warning.
            // Keep the allowed cwd in that case; otherwise restore even on errors.
            if ($previous !== false && $this->canRestoreDirectory($previous)) {
                chdir($previous);
            }
        }
    }

    private function canRestoreDirectory(string $directory): bool
    {
        $restriction = ini_get('open_basedir');
        if (!$restriction) {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $restriction) as $allowed) {
            if ($allowed === '/') {
                return true;
            }
            $allowed = rtrim($allowed, '/');
            if ($allowed !== '' && ($directory === $allowed || str_starts_with($directory, $allowed . '/'))) {
                return true;
            }
        }
        return false;
    }

    private function build(): void
    {
        $previous = $this->readState();
        // Keep maps learned on other routes; only missing/changed loaders scan.
        $state = $previous ?: ['version' => 4, 'loaders' => [], 'collisions' => []];
        $state['inventory'] = $this->inventory;
        $union = [];
        $occurrences = [];
        // Use the current SPL order, including loaders prepended late in startup.
        foreach (spl_autoload_functions() ?: [] as $callback) {
            if (!$this->isComposer($callback)) {
                continue;
            }
            $loader = $callback[0];
            $descriptor = $this->seen[$loader];
            $saved = $previous['loaders'][$descriptor['id']] ?? null;
            $map = $saved && $saved['signature'] === $descriptor['signature']
                ? ($this->maps[$descriptor['id']] ?? $this->readMap($saved['file'])) : null;
            if ($map !== null) {
                $entry = $saved;
                foreach ($saved['occurrences'] ?? [] as $class => $paths) {
                    foreach ($paths as $path => $name) {
                        $occurrences[$class][$path] = $name;
                    }
                }
            } else {
                $localOccurrences = [];
                [$map, $complete] = $this->scan($loader, $descriptor['rules'], $localOccurrences);
                foreach ($localOccurrences as $class => $paths) {
                    foreach ($paths as $path => $name) {
                        $occurrences[$class][$path] = $name;
                    }
                }
                ksort($map);
                $path = $this->classmapFile . '.' . hash('sha256', serialize($map)) . '.php';
                if (!is_file($path)) {
                    $this->write($path, $map);
                }
                $entry = ['signature' => $descriptor['signature'], 'file' => $path, 'complete' => $complete, 'generated' => time(), 'occurrences' => $localOccurrences];
            }
            $state['loaders'][$descriptor['id']] = $entry;
            $this->maps[$descriptor['id']] = $map;
            foreach ($map as $class => $path) {
                $occurrences[strtolower($class)][$path] = $class;
                $union[$class] ??= $path; // Preserve current Composer callback precedence.
            }
        }

        // Manual classmap directories cover custom loaders whose rules cannot
        // be extracted through Composer's API. Composer choices take precedence.
        $generator = new ClassMapGenerator();
        $generator->avoidDuplicateScans();
        foreach (apply_filters('rv_autoload_classmap_scan_directories', $this->scanDirectories) as $directory) {
            if (is_dir($directory)) {
                $generator->scanPaths($directory);
            }
        }
        foreach ($generator->getClassMap()->getMap() as $class => $path) {
            $union[$class] ??= $path;
            $occurrences[strtolower($class)][$path] = $class;
        }
        foreach ($generator->getClassMap()->getAmbiguousClasses() as $class => $paths) {
            foreach ($paths as $path) {
                $occurrences[strtolower($class)][$path] = $class;
            }
        }

        // Reuse route-specific maps without scanning their directories again.
        foreach ($state['loaders'] as $id => $entry) {
            if (isset($this->maps[$id])) {
                continue;
            }
            foreach ($this->read($entry['file']) as $class => $path) {
                $union[$class] ??= $path;
            }
            foreach ($entry['occurrences'] ?? [] as $class => $paths) {
                foreach ($paths as $path => $name) {
                    $occurrences[$class][$path] = $name;
                }
            }
        }
        $canonical = [];
        $union = $this->withoutInternalTypes($union);
        foreach (array_keys($union) as $class) {
            $normalized = strtolower($class);
            if (isset($canonical[$normalized])) {
                unset($union[$class]);
            } else {
                $canonical[$normalized] = $class;
            }
        }
        $learned = $this->observeClasses();
        foreach ($learned as $class => $path) {
            if (!is_file($path)) {
                unset($learned[$class]);
                continue;
            }
            // The class already selected by PHP is the authoritative collision winner.
            $normalized = strtolower($class);
            if (isset($canonical[$normalized])) {
                unset($union[$canonical[$normalized]]);
            }
            $canonical[$normalized] = $class;
            $union[$class] = $path;
            $occurrences[strtolower($class)][$path] = $class;
        }
        foreach ($occurrences as $normalized => $paths) {
            if ($this->isInternalType($normalized)) {
                continue;
            }
            // Composer generates plugin-specific InstalledVersions copies, so differing contents are expected.
            if ($normalized === 'composer\\installedversions') {
                continue;
            }
            $owners = [];
            foreach ($paths as $path => $class) {
                $owner = $this->owner($path);
                // FIXME: Yoast bundles Composer installers that are irrelevant to runtime collisions.
                if ($owner === 'wordpress-seo' && str_starts_with($normalized, 'composer\\installers\\')) {
                    unset($paths[$path]);
                    continue;
                }
                if ($owner !== null) {
                    $owners[$owner][] = $path;
                }
            }
            if (count($owners) < 2) {
                continue;
            }
            $hashes = [];
            foreach ($paths as $path => $_) {
                $hashes[$path] = is_file($path) ? hash_file('sha256', $path) : false;
            }
            $fingerprint = hash('sha256', serialize($hashes));
            $state['collisions'][$normalized] = $fingerprint;
            if (($previous['collisions'][$normalized] ?? null) !== $fingerprint
                && count(array_unique(array_values($hashes))) > 1) {
                $this->log('WARNING', 'Duplicate ' . reset($paths) . ': ' . json_encode($owners, JSON_UNESCAPED_SLASHES));
            }
        }
        ksort($union);
        ksort($learned);
        $globalFile = $this->classmapFile . '.' . hash('sha256', serialize($union)) . '.php';
        if (!is_file($globalFile)) {
            $this->write($globalFile, $union);
        }
        $state['global_map'] = $globalFile;
        $this->write($this->classmapFile, $union);
        $this->write($this->classmapFile . '.learned.php', $learned);
        // JSON is read directly, independent of CLI/FPM OPcache settings.
        $this->writeContents($this->stateFile, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->state = $state;
    }

    private function observeClasses(): array
    {
        $new = [];
        foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
            $reflection = new ReflectionClass($class);
            $path = $reflection->getFileName();
            if ($reflection->isUserDefined() && !$reflection->isAnonymous() && $path
                && strcasecmp($reflection->getName(), $class) === 0 && is_file($path)) {
                $new[$class] = $path;
            }
        }
        return $new;
    }

    private function scan(object $loader, array $rules, array &$occurrences): array
    {
        // A fresh resolver avoids the live loader's negative cache and our previously
        // applied authoritative map. Construction registers no autoload callback.
        $resolverClass = get_class($loader);
        $resolver = new $resolverClass();
        $resolver->addClassMap($rules['classmap']);
        foreach ($rules['psr4'] as $prefix => $directories) {
            $resolver->setPsr4($prefix, $directories);
        }
        foreach ($rules['psr0'] as $prefix => $directories) {
            $resolver->set($prefix, $directories);
        }
        $resolver->setPsr4('', $rules['fallback4']);
        $resolver->set('', $rules['fallback0']);
        $resolver->setUseIncludePath($rules['include_path']);
        $resolver->setClassMapAuthoritative($rules['original_authoritative']);
        $generator = new ClassMapGenerator();
        $generator->avoidDuplicateScans();
        $complete = !$rules['include_path'];
        if (!$rules['original_authoritative']) {
            foreach (['psr4' => 'psr-4', 'psr0' => 'psr-0'] as $kind => $type) {
                $prefixes = $rules[$kind];
                uksort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
                foreach ($prefixes as $prefix => $directories) {
                    foreach ($directories as $directory) {
                        if (is_dir($directory)) {
                            $generator->scanPaths(rtrim($directory, '/'), null, $type, $prefix);
                        }
                    }
                }
            }
            foreach (['fallback4' => 'psr-4', 'fallback0' => 'psr-0'] as $kind => $type) {
                foreach ($rules[$kind] as $directory) {
                    if (is_dir($directory)) {
                        $generator->scanPaths(rtrim($directory, '/'), null, $type, '');
                    }
                }
            }
        }
        $candidates = $generator->getClassMap()->getMap();
        foreach ($generator->getClassMap()->getAmbiguousClasses() as $class => $paths) {
            foreach ($paths as $path) {
                $occurrences[strtolower($class)][$path] = $class;
            }
        }
        $map = $rules['classmap'];
        foreach ($candidates as $class => $path) {
            $occurrences[strtolower($class)][$path] = $class;
            // Resolve using the original rule order, never the scanner's traversal order.
            if (!isset($map[$class])) {
                $resolved = $resolver->findFile($class);
                if ($resolved !== false) {
                    $map[$class] = $resolved;
                } else {
                    $complete = false;
                }
            }
        }
        $occurrences = array_filter($occurrences, fn (string $class): bool => !$this->isInternalType($class), ARRAY_FILTER_USE_KEY);
        return [$this->withoutInternalTypes($map), $complete];
    }

    private function withoutInternalTypes(array $map): array
    {
        return array_filter($map, fn (string $class): bool => !$this->isInternalType($class), ARRAY_FILTER_USE_KEY);
    }

    private function isInternalType(string $class): bool
    {
        // Inspect only existing types; probing polyfills must never trigger autoload.
        return (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false))
            && (new ReflectionClass($class))->isInternal();
    }

    private function owner(string $path): ?string
    {
        $path = realpath($path) ?: $path;
        $root = realpath(WP_PLUGIN_DIR) ?: WP_PLUGIN_DIR;
        return str_starts_with($path, $root . '/') ? explode('/', substr($path, strlen($root) + 1))[0] : null;
    }

    private function label(mixed $callback): string
    {
        if (is_array($callback)) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            $reflection = new ReflectionMethod($callback[0], $callback[1]);
            return $class . '::' . $callback[1] . '@' . $reflection->getFileName()
                . (is_object($callback[0]) ? '#' . spl_object_id($callback[0]) : '');
        }
        if ($callback instanceof \Closure || is_string($callback)) {
            $reflection = new ReflectionFunction($callback);
            return ($callback instanceof \Closure ? 'Closure' : $callback) . '@' . $reflection->getFileName() . ':' . $reflection->getStartLine();
        }
        return get_class($callback) . '::__invoke';
    }

    private function callbackKey(mixed $callback): string
    {
        if (is_array($callback)) {
            return (is_object($callback[0]) ? get_class($callback[0]) . '#' . spl_object_id($callback[0]) : $callback[0])
                . '::' . $callback[1];
        }
        return is_object($callback) ? get_class($callback) . '#' . spl_object_id($callback) : $callback;
    }

    private function read(string $path): array
    {
        return $this->readMap($path) ?? [];
    }

    private function readMap(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $result = require $path;
            return is_array($result) ? $result : null;
        } catch (Throwable $error) {
            $this->log('WARNING', 'Cannot read ' . $path . ': ' . $error->getMessage());
            return null;
        }
    }

    private function write(string $path, array $data): void
    {
        $contents = "<?php\n\n// Generated by Viktor's Autoload Express.\n\nreturn " . var_export($data, true) . ";\n";
        $this->writeContents($path, $contents);
    }

    private function readState(): array
    {
        $path = $this->stateFile;
        if (!is_file($path)) {
            return [];
        }
        try {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('Cannot read state');
            }
            $state = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            return is_array($state) && ($state['version'] ?? null) === 4 ? $state : [];
        } catch (Throwable $error) {
            $this->log('WARNING', 'Cannot read ' . $path . ': ' . $error->getMessage());
            return [];
        }
    }

    private function writeContents(string $path, string $contents): void
    {
        if (is_file($path) && file_get_contents($path) === $contents) {
            return;
        }
        $temporary = tempnam(dirname($path), '.classmap-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create temporary file');
        }
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Incomplete classmap write');
            }
            chmod($temporary, 0640);
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Cannot publish ' . $path);
            }
            if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function log(string $level, string $message): void
    {
        error_log('[runtime-autoload][' . $level . '] ' . $message);
    }
}
