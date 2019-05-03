<?php
namespace Eusonlito\LaravelGettext;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Gettext\GettextTranslator;
use Gettext\Extractors;
use Gettext\Generators;
use Gettext\Translations;
use Gettext\Translator;

class Gettext
{
    private $locale;
    private $config = array();
    private $formats = array('php', 'mo', 'po');
    private $translator;

    public function __construct(array $config)
    {
        $this->setConfig($config);
    }

    public function setConfig(array $config)
    {
        if (!isset($config['native'])) {
            $config['native'] = false;
        }

        if (!isset($config['functions'])) {
            $config['functions'] = true;
        }

        if (!isset($config['formats'])) {
            $config['formats'] = $this->formats;
        }

        $this->config = $config;
    }

    public function getFile($locale)
    {
        return sprintf('%s/%s/LC_MESSAGES/%s.', $this->config['storage'], $locale, $this->config['domain']);
    }

    private function getCache($locale)
    {
        if (is_file($file = $this->getFile($locale).'po')) {
            return Extractors\Po::fromFile($file);
        }

        return false;
    }

    private function scan()
    {
        Extractors\PhpCode::$functions += [
            '__' => '__',
            '_' => '__',
            'ht__' => '__',
        ];

        $entries = new Translations();

        foreach ($this->config['directories'] as $dir) {
            if (!is_dir($dir)) {
                throw new Exception(__('Folder %s not exists. Gettext scan aborted.', $dir));
            }

            try {
                foreach ($this->scanDir($dir) as $file) {
                    if (strstr($file, '.blade.php')) {
                        $entries->mergeWith(Extractors\Blade::fromFile($file));
                    } elseif (strstr($file, '.php')) {
                        $entries->mergeWith(Extractors\PhpCode::fromFile($file));
                    } elseif (strstr($file, '.js')) {
                        $entries->mergeWith(Extractors\JsCode::fromFile($file));
                    } elseif (strstr($file, '.vue')) {
                        $entries->mergeWith(Extractors\JsCode::fromFile($file));
                    }
                }
            } catch (Exception $e) {
                // Ignore.
            }
        }

        return $entries;
    }

    private function scanDir($dir)
    {
        $directory = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);

        $files = array();

        foreach ($iterator as $fileinfo) {
            $name = $fileinfo->getPathname();

            if (!strpos($name, '/.')) {
                $files[] = $name;
            }
        }

        return $files;
    }

    public function getEntries($locale, $scan = false)
    {
        $cache = $this->getCache($locale);
        if (!$scan) {
            return $cache;
        }

        $entries = clone $this->scan();

        if (is_file($file = $this->getFile($locale).'po')) {
            $entries->mergeWith($cache);
        }

        return $entries;
    }

    public function putEntries($locale, $entries)
    {
        $file = $this->getFile($locale);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        Generators\Po::toFile($entries, $file.'po');
        Generators\JsonDictionary::toFile($entries, $file.'json');

        return $entries;
    }

    public function load()
    {
        $locale = $this->locale.'.UTF-8';

        # IMPORTANT: locale must be installed in server!
        # sudo locale-gen es_ES.UTF-8
        # sudo update-locale

        putenv('LANG='.$locale);
        putenv('LANGUAGE='.$locale);
        putenv('LC_MESSAGES='.$locale);
        putenv('LC_PAPER='.$locale);
        putenv('LC_TIME='.$locale);
        putenv('LC_MONETARY='.$locale);

        if (defined('LC_MESSAGES')) {
            setlocale(LC_MESSAGES, $locale);
        }

        if (defined('LC_COLLATE')) {
            setlocale(LC_COLLATE, $locale);
        }

        if (defined('LC_TIME')) {
            setlocale(LC_TIME, $locale);
        }

        if (defined('LC_MONETARY')) {
            setlocale(LC_MONETARY, $locale);
        }

        if (!defined('LC_MESSAGES') && !defined('LC_COLLATE') && !defined('LC_TIME') && !defined('LC_MONETARY')) {
            setlocale(LC_ALL, $locale);
        }

        if ($this->config['native']) {
            $this->loadNative($locale);
        } else {
            $this->loadParsed($locale);
        }
    }

    private function loadNative($locale)
    {
        $translator = new GettextTranslator();
        $translator->setLanguage($locale);
        $translator->loadDomain($this->config['domain'], $this->config['storage']);

        bind_textdomain_codeset($this->config['domain'], 'UTF-8');

        if ($this->config['functions']) {
            $translator->register();
        }

        $this->translator = $translator;
    }

    private function loadParsed($locale)
    {
        $file = dirname($this->getFile($this->locale)).'/'.$this->config['domain'];

        $translations = null;

        foreach ($this->config['formats'] as $format) {
            if ($translations = $this->loadFormat($format, $file)) {
                break;
            }
        }

        if ($translations === null) {
            $translations = new Translations();
        }

        $this->translator = (new Translator())->loadTranslations($translations);

        if ($this->config['functions']) {
            Translator::initGettextFunctions($this->translator);
        }
    }

    private function loadFormat($format, $file)
    {
        switch ($format) {
            case 'mo':
                return $this->loadFormatMo($file);

            case 'po':
                return $this->loadFormatPo($file);

            case 'php':
                return $this->loadFormatPHP($file);
        }

        throw new Exception(sprintf('Format %s is not available', $format));
    }

    private function loadFormatMo($file)
    {
        return is_file($file.'.mo') ? Translations::fromMoFile($file.'.mo') : null;
    }

    private function loadFormatPo($file)
    {
        return is_file($file.'.po') ? Translations::fromPoFile($file.'.po') : null;
    }

    private function loadFormatPHP($file)
    {
        return is_file($file.'.php') ? ($file.'.php') : null;
    }

    public function setLocale($current, $new)
    {
        if (empty($current) || !in_array($current, $this->config['locales'])) {
            $current = $this->config['locales'][0];
        }

        if ($new && ($new !== $current) && in_array($new, $this->config['locales'])) {
            $current = $new;
        }

        $this->locale = $current;
    }

    public function getLocale()
    {
        return $this->locale;
    }

    public function getTranslator()
    {
        return $this->translator;
    }
}
