<?php

/**
 * Local development check for Medsov Telehealth cancellation behavior.
 *
 * This is a test helper, not part of the release package.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ignoreAuth = true;
$_GET['site'] = getenv('OPENEMR_SITE') ?: 'default';
$_SESSION['authUserID'] = $_SESSION['authUserID'] ?? 1;

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';
require_once __DIR__ . '/../src/Services/MeetingRoomService.php';
require_once __DIR__ . '/../src/Services/NotificationService.php';

use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\NotificationService;

$appointmentId = (int)(getenv('OPENEMR_CANCEL_APPOINTMENT_ID') ?: 8);
$meetingService = new MeetingRoomService();
$notificationService = new NotificationService();

$appointment = $meetingService->getAppointmentById($appointmentId);
if (!$appointment) {
    throw new RuntimeException('Appointment not found: ' . $appointmentId);
}

$session = $meetingService->createOrGetSessionForAppointment($appointmentId, $appointment);
$sessionId = (int)$session['id'];

sqlQuery(
    "UPDATE `medsov_telehealth_sessions`
        SET `status` = 'created',
            `patient_waiting_at` = NULL,
            `provider_joined_at` = NULL,
            `admitted_at` = NULL,
            `ended_at` = NULL
        WHERE `id` = ?",
    [$sessionId]
);
sqlQuery(
    "DELETE FROM `medsov_telehealth_audit`
        WHERE `session_id` = ?
            AND `event_type` IN (
                'appointment_cancelled',
                'patient_cancellation_email_sent',
                'patient_cancellation_email_failed'
            )",
    [$sessionId]
);
sqlQuery(
    "UPDATE `openemr_postcalendar_events`
        SET `pc_apptstatus` = 'x',
            `pc_eventstatus` = 1
        WHERE `pc_eid` = ?",
    [$appointmentId]
);

$cancelledAppointment = $meetingService->getAppointmentById($appointmentId);
$cancelledSession = $meetingService->cancelAppointmentSession($appointmentId, $cancelledAppointment, 'user', 1);
$notificationService->notifyPatientAppointmentCancelled((int)$cancelledSession['id']);

$latestSession = $meetingService->getSessionByIdForAppointment($sessionId, $appointmentId);
$portalAppointments = $meetingService->getUpcomingTelehealthAppointmentsForPatient((int)$appointment['pc_pid']);
$portalStillJoinable = array_values(array_filter($portalAppointments, static function (array $row) use ($appointmentId): bool {
    return (int)($row['pc_eid'] ?? 0) === $appointmentId;
}));

$auditRows = [];
$auditStatement = sqlStatement(
    "SELECT `event_type`, `actor_type`, `metadata_json`
        FROM `medsov_telehealth_audit`
        WHERE `session_id` = ?
            AND `event_type` IN (
                'appointment_cancelled',
                'patient_cancellation_email_sent',
                'patient_cancellation_email_failed'
            )
        ORDER BY `id`",
    [$sessionId]
);
while ($row = sqlFetchArray($auditStatement)) {
    $auditRows[] = [
        'event_type' => $row['event_type'],
        'actor_type' => $row['actor_type'],
        'metadata' => json_decode((string)($row['metadata_json'] ?? ''), true),
    ];
}

$result = [
    'appointment_id' => $appointmentId,
    'session_id' => $sessionId,
    'patient_id' => (int)$appointment['pc_pid'],
    'session_status' => (string)($latestSession['status'] ?? ''),
    'ended_at_set' => !empty($latestSession['ended_at']),
    'portal_still_joinable' => !empty($portalStillJoinable),
    'audit_events' => array_column($auditRows, 'event_type'),
    'audit_rows' => $auditRows,
];

if ($result['session_status'] !== MeetingRoomService::SESSION_STATUS_CANCELLED) {
    throw new RuntimeException('Session was not cancelled: ' . json_encode($result));
}
if (!$result['ended_at_set']) {
    throw new RuntimeException('Cancelled session ended_at was not set: ' . json_encode($result));
}
if ($result['portal_still_joinable']) {
    throw new RuntimeException('Cancelled appointment is still joinable in portal: ' . json_encode($result));
}
if (!in_array('appointment_cancelled', $result['audit_events'], true)) {
    throw new RuntimeException('Missing appointment_cancelled audit event: ' . json_encode($result));
}
if (!in_array('patient_cancellation_email_sent', $result['audit_events'], true)) {
    throw new RuntimeException('Missing patient_cancellation_email_sent audit event: ' . json_encode($result));
}

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
