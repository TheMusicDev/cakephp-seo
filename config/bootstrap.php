<?php
declare(strict_types=1);

use Cake\Core\Configure;

/**
 * Plugin config bootstrap (auto-required by BasePlugin::bootstrap()).
 *
 * Hosts define their own 'Seo' block in config/app.php — write the defaults
 * under any keys the host hasn't set, never overwrite host values (shallow
 * per-key `+` merge, IdentityBridge convention).
 */
$existing = (array)Configure::read('Seo', []);
$defaults = (require __DIR__ . '/app_default.php')['Seo'];
Configure::write('Seo', $existing + $defaults);
