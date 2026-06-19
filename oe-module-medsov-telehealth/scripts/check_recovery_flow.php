<?php

/**
 * Local development verifier for telehealth state persistence/recovery.
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

$appointmentId = (int)(getenv('MEDSOV_RECOVERY_APPOINTMENT_ID') ?: 7);
$service = new MeetingRoomService();
$appointment = $service->getAppointmentById($appointmentId);

if (!$appointment) {
    throw new RuntimeException("Appointment {$appointmentId} was not found.");
}

$session = $service->createOrGetSessionForAppointment($appointmentId, $appointment, 'system', null);
$sessionId = (int)$session['id'];
$pid = (int)$appointment['pc_pid'];
$token = (string)$session['uuid'];

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

$service = new MeetingRoomService();
$service->markPatientWaiting($sessionId, 'patient', $pid);

$afterWaiting = (new MeetingRoomService())->getPortalSessionForPatient($appointmentId, $token, $pid);
$waitingPersisted = !empty($afterWaiting['patient_waiting_at']) && (($afterWaiting['status'] ?? '') === 'patient_waiting');

$admitted = (new MeetingRoomService())->admitPatient($sessionId, 'user', $appointment['pc_aid'] ?? null);
$afterAdmit = (new MeetingRoomService())->getPortalSessionForPatient($appointmentId, $token, $pid);
$admitPersisted = $admitted && !empty($afterAdmit['admitted_at']) && (($afterAdmit['status'] ?? '') === 'admitted');

(new MeetingRoomService())->markPatientJoined($sessionId, 'patient', $pid);
$afterJoin = (new MeetingRoomService())->getPortalSessionForPatient($appointmentId, $token, $pid, true);
$joinPersisted = (($afterJoin['status'] ?? '') === 'in_session');

$result = [
    'appointment_id' => $appointmentId,
    'session_id' => $sessionId,
    'patient_id' => $pid,
    'checks' => [
        'waiting_state_survives_new_service_instance' => $waitingPersisted,
        'admitted_state_survives_new_service_instance' => $admitPersisted,
        'in_session_state_survives_new_service_instance' => $joinPersisted,
    ],
];

$failed = array_filter($result['checks'], static fn (bool $passed): bool => !$passed);
$result['status'] = empty($failed) ? 'pass' : 'fail';

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;

exit(empty($failed) ? 0 : 1);
