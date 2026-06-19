<?php

/**
 * Local development verifier for patient portal telehealth appointment lookup.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ignoreAuth = true;
$_GET['site'] = getenv('OPENEMR_SITE') ?: 'default';

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';
require_once __DIR__ . '/../src/Services/MeetingRoomService.php';

use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;

$pid = (int)($argv[1] ?? 1);
$service = new MeetingRoomService();

echo json_encode($service->getUpcomingTelehealthAppointmentsForPatient($pid), JSON_PRETTY_PRINT) . PHP_EOL;
