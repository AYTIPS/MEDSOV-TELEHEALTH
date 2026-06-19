<?php

/**
 * Module configuration entrypoint called by the OpenEMR Module Manager.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\ModulesClassLoader;

require_once dirname(__FILE__, 4) . '/globals.php';

$classLoader = new ModulesClassLoader($GLOBALS['fileroot']);
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\MedsovTelehealth\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$module_config = 1;
require_once __DIR__ . '/templates/setup.php';
exit;

