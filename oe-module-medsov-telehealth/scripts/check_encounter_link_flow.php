<?php

/**
 * Verifies Medsov Telehealth sessions link to real OpenEMR encounters.
 *
 * This is a development/test helper and is not required for production runtime.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$_GET['site'] ??= 'default';
$_SESSION['site_id'] ??= 'default';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ignoreAuth = true;

require_once dirname(__DIR__, 4) . '/globals.php';
require_once dirname(__DIR__) . '/src/Services/MeetingRoomService.php';

use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;

$_SESSION['authUserID'] ??= 1;
$_SESSION['authUser'] ??= 'admin';

$appointmentId = isset($argv[1]) ? (int)$argv[1] : 7;
$service = new MeetingRoomService();
$appointment = $service->getAppointmentById($appointmentId);

if (!$appointment) {
    fwrite(STDERR, "Appointment {$appointmentId} was not found.\n");
    exit(1);
}

$session = $service->createOrGetSessionForAppointment($appointmentId, $appointment, 'test', $_SESSION['authUserID']);
$encounter = $service->createOrGetEncounterForSession((int)$session['id'], $appointment, 'test', $_SESSION['authUserID']);

if (empty($encounter)) {
    fwrite(STDERR, "Encounter was not created or linked.\n");
    exit(1);
}

$sessionAfter = $service->getSessionByIdForAppointment((int)$session['id'], $appointmentId);
$encounterRow = sqlQuery(
    "SELECT `id`, `pid`, `encounter`, `provider_id`, `pc_catid`, `reason`, `date`
        FROM `form_encounter`
        WHERE `pid` = ? AND `encounter` = ?
        LIMIT 1",
    [(int)$appointment['pc_pid'], $encounter]
);
$formsRow = sqlQuery(
    "SELECT `id`, `form_name`, `formdir`, `pid`, `encounter`
        FROM `forms`
        WHERE `pid` = ? AND `encounter` = ? AND `formdir` = ?
        LIMIT 1",
    [(int)$appointment['pc_pid'], $encounter, 'newpatient']
);
$auditRow = sqlQuery(
    "SELECT `id`, `event_type`, `metadata_json`
        FROM `medsov_telehealth_audit`
        WHERE `session_id` = ? AND `event_type` = ?
        ORDER BY `id` DESC
        LIMIT 1",
    [(int)$session['id'], 'encounter_linked']
);

$checks = [
    'session_has_encounter' => (int)($sessionAfter['encounter'] ?? 0) === (int)$encounter,
    'form_encounter_exists' => !empty($encounterRow),
    'encounter_patient_matches' => (int)($encounterRow['pid'] ?? 0) === (int)$appointment['pc_pid'],
    'encounter_provider_matches' => (int)($encounterRow['provider_id'] ?? 0) === (int)$appointment['pc_aid'],
    'forms_row_exists' => !empty($formsRow),
    'audit_event_exists' => !empty($auditRow),
];

$ok = !in_array(false, $checks, true);

echo json_encode([
    'appointment_id' => $appointmentId,
    'session_id' => (int)$session['id'],
    'encounter' => (int)$encounter,
    'form_encounter_id' => isset($encounterRow['id']) ? (int)$encounterRow['id'] : null,
    'forms_id' => isset($formsRow['id']) ? (int)$formsRow['id'] : null,
    'checks' => $checks,
    'status' => $ok ? 'pass' : 'fail',
], JSON_PRETTY_PRINT) . "\n";

exit($ok ? 0 : 1);
