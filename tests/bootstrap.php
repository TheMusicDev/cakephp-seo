<?php
declare(strict_types=1);

/**
 * Test bootstrap for the plugin: a tiny CakePHP app (tests/test_app) with just this plugin loaded and a MariaDB
 * test database (docker-compose.yml; override with DATABASE_TEST_URL).
 */

use Cake\Cache\Cache;
use Cake\Chronos\Chronos;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\ConnectionHelper;
use Migrations\TestSuite\Migrator;
use TestApp\Application;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
define('ROOT', dirname(__DIR__));
define('CAKE_CORE_INCLUDE_PATH', ROOT . DS . 'vendor' . DS . 'cakephp' . DS . 'cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);
define('TESTS', ROOT . DS . 'tests' . DS);
define('APP', ROOT . DS . 'tests' . DS . 'test_app' . DS);
define('APP_DIR', 'src');
define('WEBROOT_DIR', 'webroot');
define('WWW_ROOT', APP . 'webroot' . DS);
define('TMP', sys_get_temp_dir() . DS . 'seo-tests' . DS);
define('CONFIG', APP . 'config' . DS);
define('CACHE', TMP . 'cache' . DS);
define('LOGS', TMP . 'logs' . DS);

foreach ([TMP, CACHE, LOGS] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

require ROOT . '/vendor/autoload.php';
require CORE_PATH . 'config' . DS . 'bootstrap.php';
require CAKE . 'functions.php';

Configure::write('App', [
    'namespace' => 'TestApp',
    'encoding' => 'UTF-8',
    'base' => false,
    'baseUrl' => false,
    'dir' => 'src',
    'webroot' => 'webroot',
    'wwwRoot' => WWW_ROOT,
    'fullBaseUrl' => 'http://localhost',
    'paths' => [
        'templates' => [APP . 'templates' . DS],
    ],
]);
Configure::write('debug', true);
Configure::write('Security.salt', 'seo-tests-only-salt-00000000000000000000000000000000000');

Cache::setConfig([
    '_cake_core_' => ['engine' => 'Array', 'prefix' => 'seo_cake_core_'],
    '_cake_model_' => ['engine' => 'Array', 'prefix' => 'seo_cake_model_'],
]);

ConnectionManager::setConfig('test', [
    'className' => 'Cake\Database\Connection',
    'driver' => 'Cake\Database\Driver\Mysql',
    'url' => getenv('DATABASE_TEST_URL') ?: 'mysql://seo:seo@127.0.0.1:3308/seo_test',
    'timezone' => 'UTC',
    'encoding' => 'utf8mb4',
    'quoteIdentifiers' => false,
]);

Chronos::setTestNow(Chronos::now());
session_id('cli');

// Migrations must run against the test connection, so alias it first.
ConnectionHelper::addTestAliases();

// Load the plugin the way a host does, then build the schema from its own migrations.
$app = new Application(CONFIG);
$app->bootstrap();
$app->pluginBootstrap();
(new Migrator())->run(['plugin' => 'TheMusicDev/Seo']);
