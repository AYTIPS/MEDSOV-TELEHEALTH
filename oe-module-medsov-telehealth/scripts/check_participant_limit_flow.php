<?php

/**
 * Verifies Medsov Telehealth participant limit enforcement.
 *
 * This is a development/test helper and is not required for production runtime.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ignoreAuth = true;
$_GET['site'] = getenv('OPENEMR_SITE') ?: 'default';

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
$sessionId = (int)$session['id'];
$providerId = (int)($appointment['pc_aid'] ?? 1);
$patientId = (int)($appointment['pc_pid'] ?? 1);
$observerId = 999999;
$maxParticipants = 2;

sqlQuery("DELETE FROM `medsov_telehealth_participants` WHERE `session_id` = ?", [$sessionId]);
sqlQuery(
    "DELETE FROM `medsov_telehealth_audit`
        WHERE `session_id` = ?
            AND `event_type` IN ('participant_joined', 'participant_left', 'participant_limit_rejected')",
    [$sessionId]
);

$providerEntry = $service->registerParticipantEntry($sessionId, 'provider', $providerId, 'Assigned Provider', $maxParticipants);
$patientEntry = $service->registerParticipantEntry($sessionId, 'patient', $patientId, 'Portal Patient', $maxParticipants);
$duplicateProviderEntry = $service->registerParticipantEntry($sessionId, 'provider', $providerId, 'Assigned Provider', $maxParticipants);
$blockedEntry = $service->registerParticipantEntry($sessionId, 'provider', $observerId, 'Extra Observer', $maxParticipants);
$countAtLimit = $service->getActiveParticipantCount($sessionId);

$service->markParticipantLeft($sessionId, 'patient', $patientId);
$allowedAfterLeave = $service->registerParticipantEntry($sessionId, 'provider', $observerId, 'Extra Observer', $maxParticipants);
$countAfterLeave = $service->getActiveParticipantCount($sessionId);

$auditRejected = sqlQuery(
    "SELECT `id`
        FROM `medsov_telehealth_audit`
        WHERE `session_id` = ?
            AND `event_type` = ?
        ORDER BY `id` DESC
        LIMIT 1",
    [$sessionId, 'participant_limit_rejected']
);

$checks = [
    'provider_entry_allowed' => !empty($providerEntry['allowed']),
    'patient_entry_allowed' => !empty($patientEntry['allowed']),
    'duplicate_provider_does_not_increase_capacity' => !empty($duplicateProviderEntry['allowed']) && $service->getActiveParticipantCount($sessionId) === 2,
    'third_participant_blocked_at_limit' => empty($blockedEntry['allowed']) && (($blockedEntry['reason'] ?? '') === 'participant_limit_reached'),
    'active_count_stays_at_limit' => $countAtLimit === 2,
    'leave_frees_capacity' => !empty($allowedAfterLeave['allowed']) && $countAfterLeave === 2,
    'rejection_audited' => !empty($auditRejected),
];

$ok = !in_array(false, $checks, true);

echo json_encode([
    'appointment_id' => $appointmentId,
    'session_id' => $sessionId,
    'max_participants' => $maxParticipants,
    'blocked_result' => $blockedEntry,
    'allowed_after_leave' => $allowedAfterLeave,
    'checks' => $checks,
    'status' => $ok ? 'pass' : 'fail',
], JSON_PRETTY_PRINT) . "\n";

exit($ok ? 0 : 1);
