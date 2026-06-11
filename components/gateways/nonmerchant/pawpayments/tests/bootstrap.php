<?php
/**
 * Standalone test bootstrap.
 *
 * The gateway extends Blesta's framework classes, none of which are available
 * outside a Blesta install. We stub the minimal primitives the gateway touches
 * so the pure logic (invoice serialization, status mapping, success(), and the
 * vendored webhook signature check) can be exercised in isolation.
 */

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('ROOTWEBDIR')) {
    define('ROOTWEBDIR', dirname(__DIR__) . DS);
}

// Minimal Input stub so setErrors()/getCommonError() never fatal.
class StubInput
{
    public $errors = [];

    public function setRules($rules)
    {
    }

    public function validates(&$meta)
    {
        return true;
    }

    public function setErrors($errors)
    {
        $this->errors = $errors;
    }
}

// Minimal NonmerchantGateway base.
class NonmerchantGateway
{
    public $currency;
    public $Input;
    public $view;

    public function loadConfig($file)
    {
    }

    public function getVersion()
    {
        return '2.0.1';
    }

    public function log($url, $data, $direction, $success)
    {
    }

    public function makeView($view, $type, $path)
    {
        return new StubView();
    }

    public function getCommonError($type)
    {
        return ['transaction' => ['response' => $type]];
    }
}

class StubView
{
    /** @var array Captures values passed to set() so harnesses can assert on them. */
    public $vars = [];

    public function set($k, $v)
    {
        $this->vars[$k] = $v;
    }

    public function fetch()
    {
        return '';
    }
}

class Loader
{
    public static function loadComponents($obj, $components)
    {
        foreach ($components as $c) {
            if ($c === 'Input') {
                $obj->Input = new StubInput();
            }
        }
    }

    public static function loadModels($obj, $models)
    {
    }

    public static function loadHelpers($obj, $helpers)
    {
    }

    public static function load($file)
    {
        require_once $file;
    }
}

class Language
{
    public static function loadLang($lang, $a = null, $b = null)
    {
    }

    public static function _($key, $return = false)
    {
        return $return ? $key : null;
    }
}

class Configure
{
    private static $data = [
        'Blesta.gw_callback_url' => 'https://example.com/callback/gw/',
        'Blesta.company_id' => '1',
    ];

    public static function get($key)
    {
        return self::$data[$key] ?? null;
    }
}

require_once dirname(__DIR__) . DS . 'pawpayments.php';
