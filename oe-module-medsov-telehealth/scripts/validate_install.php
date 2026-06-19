<?php

/**
 * Validates an installed Medsov Telehealth module package.
 *
 * Run from the OpenEMR container:
 * php interface/modules/custom_modules/oe-module-medsov-telehealth/scripts/validate_install.php --rerun-install-sql
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ignoreAuth = true;
$_GET['site'] = getenv('OPENEMR_SITE') ?: ($_GET['site'] ?? 'default');

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';
require_once __DIR__ . '/../src/Services/MeetingRoomService.php';

use OpenEMR\Modules\MedsovTelehealth\MedsovTelehealthGlobalConfig;
use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;
use OpenEMR\Services\Utils\SQLUpgradeService;

$modulePath = dirname(__DIR__);
$errors = [];
$warnings = [];
$checks = [];

function medsov_validate_pass(array &$checks, string $message): void
{
    $checks[] = '[PASS] ' . $message;
}

function medsov_validate_fail(array &$errors, string $message): void
{
    $errors[] = '[FAIL] ' . $message;
}

function medsov_validate_warn(array &$warnings, string $message): void
{
    $warnings[] = '[WARN] ' . $message;
}

function medsov_validate_table_exists(string $table): bool
{
    $row = sqlQuery(
        "SELECT `TABLE_NAME` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? LIMIT 1",
        [$table]
    );

    return !empty($row);
}

function medsov_validate_column_exists(string $table, string $column): bool
{
    $row = sqlQuery(
        "SELECT `COLUMN_NAME` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ? LIMIT 1",
        [$table, $column]
    );

    return !empty($row);
}

if (in_array('--rerun-install-sql', $argv, true)) {
    try {
        $service = new SQLUpgradeService();
        $service->setThrowExceptionOnError(true);
        $service->setRenderOutputToScreen(false);
        $service->upgradeFromSqlFile('table.sql', $modulePath);
        medsov_validate_pass($checks, 'table.sql reran successfully using OpenEMR SQLUpgradeService.');
    } catch (Throwable $throwable) {
        medsov_validate_fail($errors, 'table.sql rerun failed: ' . $throwable->getMessage());
    }
}

$requiredFiles = [
    'composer.json',
    'cleanup.sql',
    'info.txt',
    'moduleConfig.php',
    'openemr.bootstrap.php',
    'README.md',
    'table.sql',
    'version.php',
    'src/Bootstrap.php',
    'src/MedsovTelehealthGlobalConfig.php',
    'src/Services/MeetingRoomService.php',
    'src/Services/ModuleService.php',
    'src/Services/NotificationService.php',
    'templates/setup.php',
    'templates/launch.php',
    'templates/portal_appointments.php',
    'templates/portal_waiting_room.php',
    'templates/portal_launch.php',
    'templates/participant_presence.php',
    'templates/provider_appointments.php',
    'templates/audit_log.php',
];

foreach ($requiredFiles as $relativePath) {
    if (is_file($modulePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))) {
        medsov_validate_pass($checks, 'Required file exists: ' . $relativePath);
    } else {
        medsov_validate_fail($errors, 'Required file missing: ' . $relativePath);
    }
}

$sessionColumns = [
    'id',
    'uuid',
    'pc_eid',
    'pid',
    'encounter',
    'provider_id',
    'meeting_room',
    'status',
    'patient_waiting_at',
    'provider_joined_at',
    'admitted_at',
    'ended_at',
    'created_at',
    'updated_at',
];

$auditColumns = [
    'id',
    'session_id',
    'event_type',
    'actor_type',
    'actor_id',
    'ip_address',
    'user_agent',
    'metadata_json',
    'created_at',
];

$participantColumns = [
    'id',
    'session_id',
    'participant_type',
    'participant_id',
    'display_name',
    'joined_at',
    'last_seen_at',
    'left_at',
    'created_at',
    'updated_at',
];

if (medsov_validate_table_exists('medsov_telehealth_sessions')) {
    medsov_validate_pass($checks, 'Database table exists: medsov_telehealth_sessions');
    foreach ($sessionColumns as $column) {
        if (medsov_validate_column_exists('medsov_telehealth_sessions', $column)) {
            medsov_validate_pass($checks, 'Session column exists: ' . $column);
        } else {
            medsov_validate_fail($errors, 'Session column missing: ' . $column);
        }
    }
} else {
    medsov_validate_fail($errors, 'Database table missing: medsov_telehealth_sessions');
}

if (medsov_validate_table_exists('medsov_telehealth_audit')) {
    medsov_validate_pass($checks, 'Database table exists: medsov_telehealth_audit');
    foreach ($auditColumns as $column) {
        if (medsov_validate_column_exists('medsov_telehealth_audit', $column)) {
            medsov_validate_pass($checks, 'Audit column exists: ' . $column);
        } else {
            medsov_validate_fail($errors, 'Audit column missing: ' . $column);
        }
    }
} else {
    medsov_validate_fail($errors, 'Database table missing: medsov_telehealth_audit');
}

if (medsov_validate_table_exists('medsov_telehealth_participants')) {
    medsov_validate_pass($checks, 'Database table exists: medsov_telehealth_participants');
    foreach ($participantColumns as $column) {
        if (medsov_validate_column_exists('medsov_telehealth_participants', $column)) {
            medsov_validate_pass($checks, 'Participant column exists: ' . $column);
        } else {
            medsov_validate_fail($errors, 'Participant column missing: ' . $column);
        }
    }
} else {
    medsov_validate_fail($errors, 'Database table missing: medsov_telehealth_participants');
}

$category = sqlQuery(
    "SELECT `pc_catid`, `pc_catname`, `pc_active` FROM `openemr_postcalendar_categories` WHERE `pc_constant_id` = ? LIMIT 1",
    [MeetingRoomService::TELEHEALTH_CATEGORY_CONSTANT]
);
if ($category) {
    medsov_validate_pass($checks, 'Medsov Telehealth appointment category exists.');
} else {
    medsov_validate_fail($errors, 'Medsov Telehealth appointment category missing.');
}

$settings = (new ModuleService())->getSettings();
$requiredSettings = [
    MedsovTelehealthGlobalConfig::ENABLED,
    MedsovTelehealthGlobalConfig::WAITING_ROOM_ENABLED,
    MedsovTelehealthGlobalConfig::VIDEO_ENABLED,
    MedsovTelehealthGlobalConfig::AUDIO_ENABLED,
    MedsovTelehealthGlobalConfig::EMAIL_ENABLED,
    MedsovTelehealthGlobalConfig::SMS_ENABLED,
    MedsovTelehealthGlobalConfig::JITSI_DOMAIN,
    MedsovTelehealthGlobalConfig::JITSI_BASE_URL,
    MedsovTelehealthGlobalConfig::JITSI_EXTERNAL_API,
    MedsovTelehealthGlobalConfig::MAX_PARTICIPANTS,
];

foreach ($requiredSettings as $key) {
    if (array_key_exists($key, $settings)) {
        medsov_validate_pass($checks, 'Configuration setting available: ' . $key);
    } else {
        medsov_validate_fail($errors, 'Configuration setting missing: ' . $key);
    }
}

$cleanupSql = file_get_contents($modulePath . DIRECTORY_SEPARATOR . 'cleanup.sql');
foreach ([
    'medsov_telehealth_sessions',
    'medsov_telehealth_audit',
    'medsov_telehealth_participants',
    'medsov_telehealth_%',
    'medsov_telehealth',
] as $needle) {
    if (strpos((string)$cleanupSql, $needle) !== false) {
        medsov_validate_pass($checks, 'cleanup.sql references expected object: ' . $needle);
    } else {
        medsov_validate_fail($errors, 'cleanup.sql missing expected object: ' . $needle);
    }
}

if (!in_array('--rerun-install-sql', $argv, true)) {
    medsov_validate_warn($warnings, 'Install SQL was not rerun. Add --rerun-install-sql to validate idempotent install/upgrade behavior.');
}
medsov_validate_warn($warnings, 'cleanup.sql is validated by inspection only. Do not run cleanup against a database that contains demo or test data you want to keep.');

foreach ($checks as $line) {
    echo $line . PHP_EOL;
}
foreach ($warnings as $line) {
    echo $line . PHP_EOL;
}
foreach ($errors as $line) {
    fwrite(STDERR, $line . PHP_EOL);
}

if ($errors) {
    exit(1);
}

echo 'Medsov Telehealth install validation passed.' . PHP_EOL;
exit(0);
