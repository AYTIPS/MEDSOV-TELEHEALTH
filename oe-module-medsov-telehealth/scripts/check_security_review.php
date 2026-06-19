<?php

/**
 * Local development security verifier for Medsov Telehealth ownership checks.
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

$appointmentIds = [
    'amina' => (int)(getenv('MEDSOV_SECURITY_AMINA_EID') ?: 7),
    'marcus' => (int)(getenv('MEDSOV_SECURITY_MARCUS_EID') ?: 8),
];

$service = new MeetingRoomService();

$loadSession = static function (int $appointmentId): array {
    $session = sqlQuery(
        "SELECT s.`id`, s.`uuid`, s.`pc_eid`, s.`pid`, s.`status`, e.`pc_pid`
            FROM `medsov_telehealth_sessions` s
            JOIN `openemr_postcalendar_events` e ON e.`pc_eid` = s.`pc_eid`
            WHERE s.`pc_eid` = ?
            ORDER BY s.`id` DESC
            LIMIT 1",
        [$appointmentId]
    );

    if (!$session) {
        throw new RuntimeException("Missing telehealth session for appointment {$appointmentId}");
    }

    return $session;
};

$amina = $loadSession($appointmentIds['amina']);
$marcus = $loadSession($appointmentIds['marcus']);
$aminaPid = (int)$amina['pc_pid'];
$marcusPid = (int)$marcus['pc_pid'];

$ownPortalLookup = $service->getPortalSessionForPatient(
    (int)$amina['pc_eid'],
    (string)$amina['uuid'],
    $aminaPid,
    true
);

$wrongPatientLookup = $service->getPortalSessionForPatient(
    (int)$marcus['pc_eid'],
    (string)$marcus['uuid'],
    $aminaPid,
    true
);

$wrongTokenLookup = $service->getPortalSessionForPatient(
    (int)$amina['pc_eid'],
    'not-a-valid-medsov-token',
    $aminaPid,
    true
);

$emptyTokenLookup = $service->getPortalSessionForPatient(
    (int)$amina['pc_eid'],
    '',
    $aminaPid,
    true
);

$results = [
    'amina_appointment_id' => (int)$amina['pc_eid'],
    'marcus_appointment_id' => (int)$marcus['pc_eid'],
    'patient_ownership_checks' => [
        'own_patient_valid_token_allowed' => !empty($ownPortalLookup),
        'amina_cannot_use_marcus_token' => empty($wrongPatientLookup),
        'amina_wrong_token_rejected' => empty($wrongTokenLookup),
        'empty_token_rejected' => empty($emptyTokenLookup),
    ],
    'raw_patient_ids' => [
        'amina_pid' => $aminaPid,
        'marcus_pid' => $marcusPid,
    ],
];

$failed = array_filter(
    $results['patient_ownership_checks'],
    static fn (bool $passed): bool => !$passed
);

$results['status'] = empty($failed) ? 'pass' : 'fail';

echo json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;

exit(empty($failed) ? 0 : 1);
