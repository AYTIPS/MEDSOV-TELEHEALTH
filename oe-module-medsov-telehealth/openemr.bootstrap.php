<?php

/**
 * Bootstrap custom module for the Medsov Telehealth module.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\MedsovTelehealth;

/**
 * @global \OpenEMR\Core\ModulesClassLoader $classLoader
 */
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\MedsovTelehealth\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

/**
 * @global \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
 */
$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();

