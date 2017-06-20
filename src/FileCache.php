<?php
/**
 * SimpleComplex PHP Cache
 * @link      https://github.com/simplecomplex/php-cache
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-cache/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Cache;

use SimpleComplex\Utils\Explorable;
use SimpleComplex\Utils\Utils;
use SimpleComplex\Cache\Exception\CacheInvalidArgumentException;
use SimpleComplex\Cache\Exception\InvalidArgumentException;
use SimpleComplex\Cache\Exception\OutOfBoundsException;
use SimpleComplex\Cache\Exception\RuntimeException;

/**
 * PSR-16 Simple Cache file-based.
 *
 * Not compatible with 32-bit PHP, because 32-bit integer too small to handle
 * (mock) eternal time-to-live.
 *
 * @property-read string $name
 * @property-read string $type
 * @property-read string $path
 * @property-read string $fileMode
 * @property-read int $ttlDefault
 * @property-read bool $ttlIgnore
 *
 * @package SimpleComplex\Cache
 */
class FileCache extends Explorable implements ManagableCacheInterface
{
    // \Psr\SimpleCache\CacheInterface members.---------------------------------

    /**
     * @param string $key
     * @param mixed|null $default
     *
     * @return mixed|null
     *
     * @throws CacheInvalidArgumentException
     *      Arg key invalid.
     * @throws RuntimeException
     *      If this store's ttlDefault isn't zero, and checking file's modified
     *      time fails.
     *      If failing to read file.
     */
    public function get($key, $default = null)
    {
        if (!CacheKey::validate($key)) {
            throw new CacheInvalidArgumentException('Arg key is not valid, key[' . $key . '].');
        }

        $file = $this->pathReal . '/stores/' . $this->name . '/' . $key;
        if (!file_exists($file)) {
            return $default;
        }

        // Unless time-to-live is to be ignored by all methods/procedures.
        if ($this->ttlDefault || !$this->ttlIgnore) {
            $end_of_life = filemtime($file);
            if (!$end_of_life) {
                throw new RuntimeException('Failed to get modified time of file[' . $file . '].');
            }
            $time = time();
            if ($end_of_life < $time) {
                if (
                    $end_of_life + ($this->ttlDefault ? ($this->ttlDefault * 0.5) : static::GARBAGE_COLLECTION_GRACE)
                    < $time
                ) {
                    // Suppress PHP notice/warning; file_exists()+unlink() is not atomic.
                    @unlink($file);
                }

                return $default;
            }
        }

        // Suppress PHP notice/warning;
        // file_exists()+file_get_contents() is not atomic.
        $serialized = @file_get_contents($file);

        // Any serialized variable is truthy; like null: 'N;'.
        if (!$serialized) {
            if (!file_exists($file)) {
                // Apparantly the file was deleted between first call to
                // file_exists() and the call to file_get_contents().
                return $default;
            }
            throw new RuntimeException('Failed to read file[' . $file . '].');
        }

        return unserialize($serialized);
    }

    /**
     * @param string $key
     * @param mixed $value
     *      \Serializable
     * @param int|\DateInterval|null $ttl
     *      Null: uses the instance' default ttl.
     *      Zero: forever, no end of life.
     *
     * @return bool
     *
     * @throws CacheInvalidArgumentException
     *      Arg key invalid.
     * @throws RuntimeException
     *      Failing to serialize.
     *      Failing to write to file.
     *      Failing to set modified time of file.
     */
    public function set($key, $value, $ttl = null)
    {
        if (!CacheKey::validate($key)) {
            throw new CacheInvalidArgumentException('Arg key is not valid, key[' . $key . '].');
        }

        $serialized = serialize($value);
        if (!$serialized) {
            throw new RuntimeException('Failed to serialize value.');
        }

        $file = $this->pathReal . '/stores/' . $this->name . '/' . $key;
        // Uses rename() because that's atomic in *nix systems.
        // @ is PSR-16 illegal in key, so usable as mock directory separator.
        if (!($tmp_file = tempnam($this->pathReal . '/tmp', $this->name . '@' . $key))) {
            throw new RuntimeException('Failed to reserve temp file.');
        }
        if (!($handle = fopen($tmp_file, 'w'))) {
            throw new RuntimeException('Failed to open temp file.');
        }
        if (!($write = fwrite($handle, $serialized))) {
            throw new RuntimeException('Failed to write to temp file.');
        }
        if (!($close = fclose($handle))) {
            throw new RuntimeException('Failed to close temp file.');
        }
        if (!rename($tmp_file, $file)) {
            throw new RuntimeException('Failed to rename temp to final cache file.');
        }
        // tempnam() writes using file mode 0600, and rename doesn't alter mode.
        if (($this->fileMode != 'user' || static::FILE_MODE['file_user'] != 0600)
            && !chmod($file, static::FILE_MODE['file_' . $this->fileMode])
        ) {
            throw new RuntimeException('Failed to chmod cache file[' . $file . '].');
        }

        // Unless time-to-live is to be ignored by all methods/procedures.
        if ($this->ttlDefault || !$this->ttlIgnore) {
            if (!$ttl) {
                $time_to_live = $ttl === null ? $this->ttlDefault : 0;
            } else {
                $time_to_live = $this->timeToLive($ttl);
            }
            if (!$time_to_live) {
                $time_to_live = static::TTL_FOREVER;
            }
            if (
                $time_to_live
                && !touch($file, time() + $time_to_live)
            ) {
                throw new RuntimeException('Failed to set future modified time of[' . $file . '].');
            }
        }

        return true;
    }

    /**
     * @param string $key
     *
     * @return bool
     *      Always true; no effective means of detecting error.
     *
     * @throws CacheInvalidArgumentException
     *      Arg key invalid.
     */
    public function delete($key)
    {
        if (!CacheKey::validate($key)) {
            throw new CacheInvalidArgumentException('Arg key is not valid, key[' . $key . '].');
        }
        // Suppress PHP notice/warning; file_exists()+unlink() is not atomic.
        @unlink($this->pathReal . '/stores/' . $this->name . '/' . $key);
        return true;
    }

    /**
     * Clear all caches of this store.
     *
     * @return true
     *
     * @throws \UnexpectedValueException
     *      If this store's cache dir cannot be opened.
     * @throws \RuntimeException
     *      On other failures.
     */
    public function clear()
    {
        $cache_dir = $this->pathReal . '/stores/' . $this->name;
        $dir_iterator = new \DirectoryIterator($cache_dir);
        foreach ($dir_iterator as $item) {
            if (!$item->isDot()) {
                @unlink($cache_dir . '/' . $item->getFilename());
            }
        }
        return true;
    }

    /**
     * @param iterable $keys
     * @param mixed|null $default
     *
     * @return array
     *
     * @throws \TypeError
     */
    public function getMultiple($keys, $default = null)
    {
        if (!Utils::getInstance()->isIterable($keys)) {
            throw new \TypeError(
                'Arg keys type[' . (!is_object($keys) ? gettype($keys) : get_class($keys)) . '] is not iterable.'
            );
        }
        $list = [];
        foreach ($keys as $key) {
            $list[$key] = $this->get($key, $default);
        }
        return $list;
    }

    /**
     * @param iterable $values
     * @param int|\DateInterval|null $ttl
     *      Null: uses the instance' default ttl.
     *      Zero: forever, no end of life.
     *
     * @return bool
     *
     * @throws \TypeError
     */
    public function setMultiple($values, $ttl = null)
    {
        if (!Utils::getInstance()->isIterable($values)) {
            throw new \TypeError(
                'Arg values type[' . (!is_object($values) ? gettype($values) : get_class($values))
                . '] is not iterable.'
            );
        }
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param iterable $keys
     *
     * @return bool
     *
     * @throws \TypeError
     */
    public function deleteMultiple($keys)
    {
        if (!Utils::getInstance()->isIterable($keys)) {
            throw new \TypeError(
                'Arg keys type[' . (!is_object($keys) ? gettype($keys) : get_class($keys)) . '] is not iterable.'
            );
        }
        foreach ($keys as $key) {
            // Suppress PHP notice/warning; file_exists()+unlink() is not atomic.
            @unlink($this->pathReal . '/stores/' . $this->name . '/' . $key);
        }
        return true;
    }

    /**
     * Does not consider time-to-live, even if this instance has a default ttl.
     *
     * @param string $key
     *
     * @return bool
     *
     * @throws CacheInvalidArgumentException
     */
    public function has($key)
    {
        if (!CacheKey::validate($key)) {
            throw new CacheInvalidArgumentException('Arg key is not valid, key[' . $key . '].');
        }
        return file_exists($this->pathReal . '/stores/' . $this->name . '/' . $key);
    }


    // Explorable.--------------------------------------------------------------

    /**
     * @var array
     */
    protected $explorableIndex = [
        'name',
        'type',
        'path',
        'fileMode',
        'ttlDefault',
        'ttlIgnore',
    ];

    /**
     * @param string $name
     *
     * @return mixed
     *
     * @throws OutOfBoundsException
     *      If no such instance property.
     */
    public function __get($name)
    {
        if (in_array($name, $this->explorableIndex, true)) {
            return $this->{$name};
        }
        throw new OutOfBoundsException(get_class($this) . ' instance exposes no property[' . $name . '].');
    }

    /**
     * @param string $name
     * @param mixed|null $value
     *
     * @return void
     *
     * @throws OutOfBoundsException
     *      If no such instance property.
     * @throws RuntimeException
     *      If that instance property is read-only.
     */
    public function __set($name, $value) /*: void*/
    {
        if (in_array($name, $this->explorableIndex, true)) {
            throw new RuntimeException(get_class($this) . ' instance property[' . $name . '] is read-only.');
        }
        throw new OutOfBoundsException(get_class($this) . ' instance exposes no property[' . $name . '].');
    }

    /**
     * @see \Iterator::current()
     * @see Explorable::current()
     *
     * @return mixed
     */
    public function current()
    {
        // Override to facilitate direct call to __get(); cheaper.
        return $this->__get(current($this->explorableIndex));
    }


    // ManagableCacheInterface.-------------------------------------------------

    /**
     * Check if the cache store has any items at all.
     *
     * @return bool
     */
    public function isEmpty() : bool
    {
        $cache_dir = $this->pathReal . '/stores/' . $this->name;
        $dir_iterator = new \DirectoryIterator($cache_dir);
        foreach ($dir_iterator as $item) {
            if (!$item->isDot()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Set the cache store's default time-to-live.
     *
     * @param int|\DateInterval $ttl
     *
     * @return void
     *
     * @throws \TypeError
     *      Propagated.
     * @throws RuntimeException
     *      Propagated.
     */
    public function setTtlDefault($ttl)
    {
        $time_to_live = $this->timeToLive($ttl);
        // Save to settings .ini file, if different.
        if ($time_to_live != $this->ttlDefault) {
            $this->ttlDefault = $time_to_live;
            $this->saveSettings([
                'fileMode' => $this->fileMode,
                'ttlDefault' => $this->ttlDefault,
                'ttlIgnore' => $this->ttlIgnore,
            ]);
        }
    }

    /**
     * Control whether the cache store should ignore $ttl argument
     * of setters and getters.
     *
     * @param bool $ignore
     *
     * @return void
     */
    public function setTtlIgnore(bool $ignore)
    {
        // Save to settings .ini file, if different.
        if ($ignore != $this->ttlIgnore) {
            $this->ttlIgnore = $ignore;
            $this->saveSettings([
                'fileMode' => $this->fileMode,
                'ttlDefault' => $this->ttlDefault,
                'ttlIgnore' => $this->ttlIgnore,
            ]);
        }
    }

    /**
     * Deletes all cache items that have reached end of life.
     *
     * @return int
     *      Number of items cleared.
     */
    public function clearExpired() : int
    {
        $deleted = 0;
        // Unless time-to-live is to be ignored by all methods/procedures.
        if ($this->ttlDefault || !$this->ttlIgnore) {
            $grace = $this->ttlDefault ? ($this->ttlDefault * 0.5) : static::GARBAGE_COLLECTION_GRACE;
            $cache_dir = $this->pathReal . '/stores/' . $this->name;
            $dir_iterator = new \DirectoryIterator($cache_dir);
            foreach ($dir_iterator as $item) {
                if (
                    !$item->isDot()
                    && ($end_of_life = @$item->getMTime())
                    && $end_of_life < time() + $grace
                ) {
                    @unlink($cache_dir . '/' . $item->getFilename());
                    ++$deleted;
                }
            }
        }
        return $deleted;
    }

    // Custom.------------------------------------------------------------------

    /**
     * File path where an instance' settings and cache files should be stored.
     *
     * Relative path is considered relative to document root.
     *
     * Will eventually contain:
     * - /stores/[some store name]/[...cache files]
     * - /tmp
     * - [some store name].ini
     *
     * @var string
     */
    const PATH_DEFAULT = '../private/lib/simplecomplex/file-cache';

    /**
     * File modes for directory/file writing, using respectively user-only,
     * group read/write/execute and group plus set group id.
     *
     * Must be octals integers, with leading zero, nothing else seem to work;
     * even if decoct/octdec() juggling.
     *
     * @var int[]
     */
    const FILE_MODE = [
        'dir_user' => 0700,
        'file_user' => 0600,
        'dir_group' => 0770,
        'file_group' => 0660,
        'dir_group_setgid' => 02770,
        'file_group_setgid' => 02660,
    ];

    /**
     * Values: user|group|group_setgid.
     *
     * @var string
     */
    const FILE_MODE_DEFAULT = 'user';

    /**
     * There's no such thing as eternity.
     *
     * Incompatible with 32-bit PHP.
     *
     * @var int
     */
    const TTL_FOREVER = 1000 * 365 * 24 * 60 * 60;

    /**
     * Default time-to-live.
     *
     * Values:
     * - zero: forever.
     * - positive: seconds.
     *
     * @var int
     */
    const TTL_DEFAULT = 0;

    /**
     * Ignore $ttl argument of item setters and getters.
     *
     * Ignore time-to-live completely, if ignore AND ttl default none (forever).
     *
     * @var int
     */
    const TTL_IGNORE = false;

    /**
     * Grace period in which a ttl expired item should not be deleted.
     * May reduce the risk of current saving/deleting procedures.
     * Avoid values that are a multiple of common ttl values; for this
     * particular cache store.
     *
     * This default value is only used if the store has no (zero) default
     * time-to-live.
     * Whenever the default time-to-live is set (to non-zero), the grace
     * period will be 0.5 times the default time-to-live.
     *
     * @var int
     */
    const GARBAGE_COLLECTION_GRACE = 15 * 60;

    /**
     * Cache store name.
     *
     * @var string
     */
    protected $name = '';

    /**
     * Cache store type.
     *
     * @var string
     */
    protected $type = 'file';

    /**
     * Path given by argument or class default; absolute
     * or relative to document root.
     *
     * @var string
     */
    protected $path = '';

    /**
     * Final absolute path.
     *
     * @var string
     */
    protected $pathReal = '';

    /**
     * Values: user|group|group_setgid.
     *
     * @var string
     */
    protected $fileMode = '';

    /**
     * Default time-to-live.
     *
     * Values:
     * - zero: forever, used when set method ttl arg null.
     * - positive: used when set method ttl arg null.
     *
     * @var integer
     */
    protected $ttlDefault = 0;

    /**
     * Ignore $ttl argument of item setters and getters.
     *
     * Ignore time-to-live completely, if ignore AND ttl default none (forever).
     *
     * @var integer
     */
    protected $ttlIgnore = false;

    /**
     * Create or load cache store.
     *
     * @param string $name
     * @param array $options {
     *      @var string $path = ''
     *          Empty: class default (PATH_DEFAULT) rules.
     *      @var string $fileMode = ''
     *          Empty: preexisting setting or class default (FILE_MODE_DEFAULT)
     *          rules.
     *          File mode will only apply fully to all aspects of dir/file
     *          writing the first time this cache store gets created. Later it
     *          will only apply to new cache files.
     *      @var int|\DateInterval|null $ttlDefault = null
     *          Null: preexisting setting or class default (TTL_DEFAULT) rules.
     *          Zero: forever.
     *      @var bool|null $ttlIgnore = null
     *          Null: preexisting setting or class default (TTL_IGNORE) rules.
     *          True: item setters and getters' ttl argument gets ignored.
     * }
     * @throws \LogicException
     *      If PHP is 32-bit.
     * @throws InvalidArgumentException
     *      Invalid arg name.
     * @throws \TypeError
     *      Wrong type of arg options bucket.
     * @throws \Throwable
     *      Propagated.
     */
    public function __construct(string $name, array $options = [])
    {
        if (PHP_INT_SIZE < 8) {
            throw new \LogicException(
                get_class($this) . ' is not compatible with 32-bit PHP.'
            );
        }
        if (!CacheKey::validate($name)) {
            throw new InvalidArgumentException('Arg name is empty or contains illegal char(s), name['
                . $name . '].');
        }
        $this->name = $name;

        if (!empty($options['path'])) {
            if (!is_string($options['path'])) {
                throw new \TypeError('Arg options[path] type['
                    . (!is_object($options['path']) ? gettype($options['path']) :
                        get_class($options['path'])) . '] is not string.');
            }
            $this->path = $options['path'];
        } else {
            $this->path = static::PATH_DEFAULT;
        }

        // Resolve path, and load preexisting settings if they exist.
        $settings = $this->resolvePath() ? $this->loadSettings() : [];
        // Resolve options and final instance var values, and figure out if we
        // need to update filed settings.
        $save_settings = $this->resolveSettings($settings, $options);
        // Create path, cache dir and tmp dir, if they don't exist.
        $this->ensureDirectories();
        // Save/update settings.
        if ($save_settings) {
            $this->saveSettings($settings);
        }
    }

    /**
     * Compares preexiting settings with passed options, and set instance vars
     * accordingly.
     *
     * Separated from from constructor to accommodate class extension.
     *
     * @param array &$settings
     *      By reference.
     * @param array $options
     *
     * @return bool
     *      True: preexisting setting don't exist or differ from options passed.
     *
     * @throws \TypeError
     */
    protected function resolveSettings(array &$settings, array $options) /*: void*/
    {
        $diff = !$settings;
        $settings_exist = !$diff;

        // fileMode.
        if (!empty($options['fileMode'])) {
            // empty() also handles null; that existing setting must rule.
            if (!is_string($options['fileMode'])) {
                throw new \TypeError('Arg options[fileMode] type['
                    . (!is_object($options['fileMode']) ? gettype($options['fileMode']) :
                        get_class($options['fileMode'])) . '] is not string.');
            }
            switch ($options['fileMode']) {
                case 'user':
                case 'group':
                case 'group_setgid':
                    $this->fileMode = $options['fileMode'];
                    break;
                default:
                    throw new InvalidArgumentException(
                        'Arg fileMode must be user|group|group_setgid or empty, fileMode[' . $options['fileMode'] . '].'
                    );
            }
            if (!$settings_exist || $options['fileMode'] != $settings['fileMode']) {
                $diff = true;
                $settings['fileMode'] = $options['fileMode'];
            }
        } elseif ($settings_exist) {
            $this->fileMode = $settings['fileMode'];
        } else {
            $settings['fileMode'] = $this->fileMode = static::FILE_MODE_DEFAULT;
            // And no settings means diff is already true.
        }

        // ttlDefault.
        if (isset($options['ttlDefault'])) {
            // isset() also handles null; that existing setting must rule.
            if (!$options['ttlDefault']) {
                $this->ttlDefault = 0;
            } else {
                $this->ttlDefault = $this->timeToLive($options['ttlDefault']);
            }
            if (!$settings_exist || $options['ttlDefault'] != $settings['ttlDefault']) {
                $diff = 1;
                $settings['ttlDefault'] = $options['ttlDefault'];
            }
        } elseif ($settings_exist) {
            $this->ttlDefault = $settings['ttlDefault'];
        } else {
            $settings['ttlDefault'] = $this->ttlDefault = static::TTL_DEFAULT;
            // And no settings means diff is already true.
        }
        // ttlIgnore.
        if (isset($options['ttlIgnore'])) {
            // isset() also handles null; that existing setting must rule.
            $this->ttlIgnore = !!$options['ttlIgnore'];

        } elseif ($settings_exist) {
            $this->ttlIgnore = $settings['ttlIgnore'];
        } else {
            $settings['ttlIgnore'] = $this->ttlIgnore = static::TTL_IGNORE;
            // And no settings means diff is already true.
        }

        return $diff;
    }

    /**
     * Resolve path and check if exists.
     *
     * @return bool
     *      Whether the path exists already.
     *
     * @throws RuntimeException
     * @throws \Throwable
     *      Propagated.
     */
    protected function resolvePath() : bool
    {
        $this->pathReal = Utils::getInstance()->resolvePath($this->path);

        if (file_exists($this->pathReal)) {
            if (!is_dir($this->pathReal)) {
                throw new RuntimeException('Path exists but is not directory, path[' . $this->pathReal . ']');
            }
            return true;
        }
        return false;
    }

    /**
     * Ensures this class' (writable) path, tmp dir and stores dir.
     *
     * @see FileCache::__construct()
     * @see FileCache::resolvePath()
     * @see Utils::ensurePath()
     *
     * @return void
     *
     * @throws \Throwable
     *      Propagated, various types from Utils::ensurePath().
     */
    protected function ensureDirectories() /*: void*/
    {
        $utils = Utils::getInstance();
        // Ensure cache dir.
        $cache_dir = $this->pathReal . '/stores/' . $this->name;
        if (!file_exists($cache_dir)) {
            $utils->ensurePath($cache_dir, static::FILE_MODE['dir_' . $this->fileMode]);
        }
        // Ensure tmp dir.
        $tmp_dir = $this->pathReal . '/tmp';
        if (!file_exists($tmp_dir)) {
            $utils->ensurePath($tmp_dir, static::FILE_MODE['dir_' . $this->fileMode]);
        }
    }

    /**
     * Load previously created settings of this store, if it already exists.
     *
     * @see FileCache::__construct()
     *
     * @return array
     */
    protected function loadSettings()
    {
        $file = $this->pathReal . '/' . $this->name . '.ini';
        if (file_exists($file)) {
            $settings = Utils::getInstance()->parseIniFile($file, false, true);
            if (!$settings && $settings === false) {
                throw new RuntimeException('Failed to read store settings, file[' . $file . '].');
            }
            return $settings;
        }
        return [];
    }

    /**
     * @param array $settings
     *
     * @return void
     *
     * @throws RuntimeException
     *      Failing to write settings to file.
     */
    protected function saveSettings(array $settings) /*: void*/
    {
        $file = $this->pathReal . '/' . $this->name . '.ini';
        $content = Utils::getInstance()->iterableToIniString($settings);
        $set_mode = $this->fileMode != 'user' && !file_exists($file);
        if (!file_put_contents($file, $content)) {
            throw new RuntimeException('Failed to write store settings to file[' . $file . '].');
        }
        if ($set_mode) {
            if (!chmod($file, static::FILE_MODE['file_' . $this->fileMode])) {
                throw new RuntimeException('Failed to chmod settings file[' . $file . '].');
            }
        }
    }

    /**
     * Average, and not exact; doesn't consider the year-divisible-by-400 rule.
     *
     * @var float
     */
    const DAYS_OF_YEAR = 365.25;

    /**
     * Flawed leap year implementation; uses an average, doesn't check if any
     * of current year(s) is leap year.
     *
     * @param int|\DateInterval|null $ttl
     *      Non-empty must be non-negative.
     *
     * @return int
     *      Seconds.
     *
     * @throws \TypeError
     * @throws RuntimeException
     *      Arg ttl resolves to negative integer.
     */
    protected function timeToLive($ttl) : int
    {
        if ($ttl) {
            if (is_int($ttl)) {
                if ($ttl < 0) {
                    throw new RuntimeException('Time-to-live cannot be negative, saw int[' . $ttl . '].');
                }
                return $ttl;
            }
            if (is_a($ttl, \DateInterval::class)) {
                $secs = (int) floor(
                        + ($ttl->y * static::DAYS_OF_YEAR * 24 * 60 * 60)
                        + ($ttl->m * (static::DAYS_OF_YEAR / 12) * 24 * 60 * 60)
                        + ($ttl->d * 24 * 60 * 60)
                        + ($ttl->h * 60 * 60)
                        + ($ttl->i * 60)
                        + $ttl->s
                    ) * (!$ttl->invert ? 1 : -1);
                if ($secs < 0) {
                    throw new RuntimeException('Time-to-live cannot be negative, saw DateInterval['
                        . join(', ', array(
                            'y' => $ttl->y,
                            'm' => $ttl->m,
                            'd' => $ttl->d,
                            'h' => $ttl->h,
                            'i' => $ttl->i,
                            's' => $ttl->s,
                            'invert' => $ttl->invert,
                        ))
                        . '].'
                    );
                }
                return $secs;
            }
            throw new \TypeError('Time-to-live must be integer, DateInterval or null.');
        }
        return 0;
    }

    // @todo: create cli script that uses getInstances() to clearExpired(); for cron.

    /**
     * Finds all stores that has been created using that path,
     * instantiates them, and returns a list of them.
     *
     * Looks for [store name].ini files in the path.
     *
     * @see FileCache::PATH_DEFAULT
     *
     * @param string $path
     *      Defaults to class var PATH_DEFAULT.
     *
     * @return array
     */
    public static function getInstances($path = '')
    {
        $absolute_path = Utils::getInstance()->resolvePath($path ? $path : static::PATH_DEFAULT);
        if (file_exists($absolute_path)) {
            if (!is_dir($absolute_path)) {
                throw new RuntimeException('Path exists but is not directory, path[' . $absolute_path . ']');
            }
            $instances = [];
            $dir_iterator = new \DirectoryIterator($absolute_path);
            foreach ($dir_iterator as $item) {
                if (!$item->isDot() && $item->getExtension() == 'ini') {
                    $name = $item->getBasename('.ini');
                    $instances[] = static::__construct($name);
                }
            }
            return $instances;
        }
        return [];
    }
}
