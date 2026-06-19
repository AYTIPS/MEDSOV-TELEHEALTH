<?php

/**
 * Local Docker development installer for the Medsov Telehealth module.
 *
 * This is not used by production installs. The OpenEMR Module Manager should
 * run table.sql during a normal install.
 */

if (PHP_SAPI === 'cli') {
    $ignoreAuth = true;
    $_GET['site'] = getenv('OPENEMR_SITE') ?: 'default';
}

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../version.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';

use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;
use OpenEMR\Services\Utils\SQLUpgradeService;

$moduleDir = 'oe-module-medsov-telehealth';
$modulePath = dirname(__DIR__);

$service = new SQLUpgradeService();
$service->setThrowExceptionOnError(true);
$service->setRenderOutputToScreen(false);
$service->upgradeFromSqlFile('table.sql', $modulePath);

(new ModuleService())->saveSettings((new ModuleService())->getDefaults());

$portalDevSettings = [
    'portal_onsite_two_enable' => '1',
    'allow_portal_appointments' => '1',
    'portal_onsite_two_address' => 'http://localhost:8080/portal',
];

$mailpitDevSettings = [
    'SMTP_HOST' => 'mailpit',
    'SMTP_PORT' => '1025',
    'SMTP_USER' => '',
    'SMTP_PASS' => '',
    'SMTP_SECURE' => '',
    'SMTP_AUTH' => '0',
    'practice_return_email_path' => 'telehealth@medsov.local',
    'Patient Reminder Sender Name' => 'Medsov Telehealth',
    \OpenEMR\Modules\MedsovTelehealth\MedsovTelehealthGlobalConfig::EMAIL_ENABLED => '1',
    \OpenEMR\Modules\MedsovTelehealth\MedsovTelehealthGlobalConfig::SMS_ENABLED => '',
];

foreach (array_merge($portalDevSettings, $mailpitDevSettings) as $key => $value) {
    sqlQuery(
        "INSERT INTO `globals` (`gl_name`, `gl_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `gl_name` = ?, `gl_value` = ?",
        [$key, $value, $key, $value]
    );
}

sqlQuery(
    "UPDATE `users` SET `email` = COALESCE(NULLIF(`email`, ''), ?), `fname` = COALESCE(NULLIF(`fname`, ''), ?) WHERE `username` = ?",
    ['doctor.demo@medsov.local', 'Administrator', 'admin']
);

$version = implode('.', [$v_major, $v_minor, $v_patch]);
if (!empty($v_tag)) {
    $version .= '-' . $v_tag;
}

sqlQuery(
    "UPDATE `modules` SET `sql_run` = 1, `sql_version` = ?, `date` = NOW() WHERE `mod_directory` = ?",
    [$version, $moduleDir]
);

echo "Medsov Telehealth dev install complete\n";
