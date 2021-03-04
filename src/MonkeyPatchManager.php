<?php

declare(strict_types=1);

/*
 * Copyright (c) 2021 Kenji Suzuki
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/kenjis/monkey-patch
 */

namespace Kenjis\MonkeyPatch;

use Kenjis\MonkeyPatch\Patcher\FunctionPatcher;
use LogicException;
use PhpParser\ParserFactory;

use function constant;
use function date;
use function explode;
use function file;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fwrite;
use function in_array;
use function is_readable;
use function microtime;
use function rewind;
use function substr;
use function trim;

use const FILE_APPEND;

class MonkeyPatchManager
{
    /** @var bool */
    public static $debug = false;

    /** @var int */
    private static $php_parser = ParserFactory::PREFER_PHP5;

    /**
     * The path to the log file if `$debug` is true.
     * Will be set in {@link MonkeyPatchManager::setDebug}.
     *
     * @var string|null */
    public static $log_file = null;

    /** @var bool */
    private static $load_patchers = false;

    /** @var class-string */
    private static $exit_exception_classname =
        'Kenjis\MonkeyPatch\Exception\ExitException';

    /** @var string[] list of patcher classname */
    private static $patcher_list = [
        'ExitPatcher',
        'FunctionPatcher',
        'MethodPatcher',
        'ConstantPatcher',
    ];

    public static function log(string $message): void
    {
        if (! self::$debug) {
            return;
        }

        $time = date('Y-m-d H:i:s');
        [$usec, $sec] = explode(' ', microtime());
        $usec = substr($usec, 1);
        $log = "[$time $usec] $message\n";
        file_put_contents(self::$log_file, $log, FILE_APPEND);
    }

    /**
     * @param class-string $name
     */
    public static function setExitExceptionClassname(string $name): void
    {
        self::$exit_exception_classname = $name;
    }

    public static function getExitExceptionClassname(): string
    {
        return self::$exit_exception_classname;
    }

    public static function getPhpParser(): int
    {
        return self::$php_parser;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected static function setDebug(array $config): void
    {
        if (isset($config['debug'])) {
            self::$debug = $config['debug'];
        }

        if (isset($config['log_file'])) {
            self::$log_file = $config['log_file'];
        }

        if (self::$log_file === null) {
            self::$log_file = __DIR__ . '/debug.log';
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    protected static function setDir(array $config): void
    {
        if (! isset($config['root_dir'])) {
            throw new LogicException('You have to set "root_dir"');
        }

        Cache::setProjectRootDir($config['root_dir']);

        if (! isset($config['cache_dir'])) {
            throw new LogicException('You have to set "cache_dir"');
        }

        self::setCacheDir($config['cache_dir']);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected static function setPaths(array $config): void
    {
        if (! isset($config['include_paths'])) {
            throw new LogicException('You have to set "include_paths"');
        }

        self::setIncludePaths($config['include_paths']);

        if (isset($config['exclude_paths'])) {
            self::setExcludePaths($config['exclude_paths']);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function init(array $config): void
    {
        self::setDebug($config);

        if (isset($config['php_parser'])) {
            self::$php_parser = constant('PhpParser\ParserFactory::' . $config['php_parser']);
        }

        self::setDir($config);
        self::setPaths($config);

        Cache::createTmpListDir();

        if (isset($config['patcher_list'])) {
            self::setPatcherList($config['patcher_list']);
        }

        self::checkPatcherListUpdate();
        self::checkPathsUpdate();

        self::loadPatchers();

        self::addTmpFunctionBlacklist();

        if (isset($config['functions_to_patch'])) {
            FunctionPatcher::addWhitelists($config['functions_to_patch']);
        }

        self::checkFunctionWhitelistUpdate();
        FunctionPatcher::lockFunctionList();

        if (isset($config['exit_exception_classname'])) {
            self::setExitExceptionClassname($config['exit_exception_classname']);
        }

        // Register include stream wrapper for monkey patching
        self::wrap();
    }

    protected static function checkPathsUpdate(): void
    {
        $cached = Cache::getTmpIncludePaths();
        $current = PathChecker::getIncludePaths();

        // Updated?
        if ($cached !== $current) {
            self::log('clear_src_cache: from ' . __METHOD__);
            Cache::clearSrcCache();
            Cache::writeTmpIncludePaths($current);
        }

        $cached = Cache::getTmpExcludePaths();
        $current = PathChecker::getExcludePaths();

        // Updated?
        if ($cached !== $current) {
            self::log('clear_src_cache: from ' . __METHOD__);
            Cache::clearSrcCache();
            Cache::writeTmpExcludePaths($current);
        }
    }

    protected static function checkPatcherListUpdate(): void
    {
        $cached = Cache::getTmpPatcherList();

        // Updated?
        if ($cached !== self::$patcher_list) {
            self::log('clear_src_cache: from ' . __METHOD__);
            Cache::clearSrcCache();
            Cache::writeTmpPatcherList(self::$patcher_list);
        }
    }

    protected static function checkFunctionWhitelistUpdate(): void
    {
        $cached = Cache::getTmpFunctionWhitelist();
        $current = FunctionPatcher::getFunctionWhitelist();

        // Updated?
        if ($cached !== $current) {
            self::log('clear_src_cache: from ' . __METHOD__);
            Cache::clearSrcCache();
            Cache::writeTmpFunctionWhitelist($current);
        }
    }

    protected static function addTmpFunctionBlacklist(): void
    {
        $list = file(Cache::getTmpFunctionBlacklistFile());
        foreach ($list as $function) {
            FunctionPatcher::addBlacklist(trim($function));
        }
    }

    public static function isEnabled(string $patcher): bool
    {
        return in_array($patcher, self::$patcher_list);
    }

    /**
     * @param string[] $list
     */
    public static function setPatcherList(array $list): void
    {
        if (self::$load_patchers) {
            throw new LogicException("Can't change patcher list after initialisation");
        }

        self::$patcher_list = $list;
    }

    public static function setCacheDir(string $dir): void
    {
        Cache::setCacheDir($dir);
    }

    /**
     * @param string[] $dir_list
     */
    public static function setIncludePaths(array $dir_list): void
    {
        PathChecker::setIncludePaths($dir_list);
    }

    /**
     * @param string[] $dir_list
     */
    public static function setExcludePaths(array $dir_list): void
    {
        PathChecker::setExcludePaths($dir_list);
    }

    public static function wrap(): void
    {
        IncludeStream::wrap();
    }

    public static function unwrap(): void
    {
        IncludeStream::unwrap();
    }

    /**
     * @param string $path original source file path
     *
     * @return resource
     *
     * @throws LogicException
     */
    public static function patch(string $path)
    {
        if (! is_readable($path)) {
            throw new LogicException("Can't read '$path'");
        }

        // Check cache file
        $cache_file = Cache::getValidSrcCachePath($path);
        if ($cache_file) {
            self::log('cache_hit: ' . $path);

            return fopen($cache_file, 'r');
        }

        self::log('cache_miss: ' . $path);
        $source = file_get_contents($path);

        [$new_source, $patched] = self::execPatchers($source);

        // Write to cache file
        self::log('write_cache: ' . $path);
        Cache::writeSrcCacheFile($path, $new_source);

        $resource = fopen('php://memory', 'rb+');
        fwrite($resource, $new_source);
        rewind($resource);

        return $resource;
    }

    protected static function loadPatchers(): void
    {
        if (self::$load_patchers) {
            return;
        }

        require __DIR__ . '/Patcher/AbstractPatcher.php';
        require __DIR__ . '/Patcher/Backtrace.php';

        foreach (self::$patcher_list as $classname) {
            require __DIR__ . '/Patcher/' . $classname . '.php';
        }

        self::$load_patchers = true;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    protected static function execPatchers(string $source): array
    {
        $patched = false;

        foreach (self::$patcher_list as $classname) {
            $classname = 'Kenjis\MonkeyPatch\Patcher\\' . $classname;
            $patcher = new $classname();
            [$source, $patched_this] = $patcher->patch($source);
            $patched = $patched || $patched_this;
        }

        return [
            $source,
            $patched,
        ];
    }
}
