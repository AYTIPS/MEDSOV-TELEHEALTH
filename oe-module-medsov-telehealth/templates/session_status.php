<?php

/**
 * Provider-side JSON status for an appointment telehealth session.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';
require_once __DIR__ . '/../src/Services/MeetingRoomService.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;

function medsov_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function medsov_session_payload(array $session, bool $waitingRoomEnabled): array
{
    $cancelled = (($session['status'] ?? '') === MeetingRoomService::SESSION_STATUS_CANCELLED);
    $patientWaiting = !empty($session['patient_waiting_at']);
    $admitted = !$cancelled && (!$waitingRoomEnabled || !empty($session['admitted_at']));

    if ($cancelled) {
        $label = xl('Appointment canceled');
    } elseif ($admitted) {
        $label = xl('Patient admitted');
    } elseif ($patientWaiting) {
        $label = xl('Patient waiting');
    } else {
        $label = xl('Waiting for patient');
    }

    return [
        'ok' => true,
        'session_id' => (int)$session['id'],
        'status' => (string)($session['status'] ?? 'created'),
        'label' => $label,
        'cancelled' => $cancelled,
        'patient_waiting' => $patientWaiting,
        'requires_admission' => $waitingRoomEnabled,
        'admitted' => $admitted,
        'patient_waiting_at' => $session['patient_waiting_at'] ?? null,
        'provider_joined_at' => $session['provider_joined_at'] ?? null,
        'admitted_at' => $session['admitted_at'] ?? null,
    ];
}

if (!AclMain::aclCheckCore('admin', 'super') && !AclMain::aclCheckCore('patients', 'appt')) {
    medsov_json_response(['ok' => false, 'error' => xl('Not authorized.')], 403);
}

$moduleService = new ModuleService();
if (!$moduleService->isEnabled()) {
    medsov_json_response(['ok' => false, 'error' => xl('Medsov Telehealth is disabled.')], 409);
}

$appointmentId = isset($_GET['eid']) ? (int)$_GET['eid'] : 0;
$sessionId = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
$meetingService = new MeetingRoomService();
$appointment = $meetingService->getAppointmentById($appointmentId);

if (!$appointment || !$meetingService->isTelehealthCategory((int)($appointment['pc_catid'] ?? 0))) {
    medsov_json_response(['ok' => false, 'error' => xl('This appointment is not configured for Medsov Telehealth.')], 404);
}
if (!$meetingService->currentUserCanManageAppointment($appointment)) {
    medsov_json_response(['ok' => false, 'error' => xl('Not authorized for this appointment.')], 403);
}
if ($meetingService->isAppointmentCancelled($appointment)) {
    medsov_json_response([
        'ok' => true,
        'cancelled' => true,
        'status' => MeetingRoomService::SESSION_STATUS_CANCELLED,
        'label' => xl('Appointment canceled'),
        'patient_waiting' => false,
        'requires_admission' => false,
        'admitted' => false,
    ]);
}

$session = $sessionId
    ? $meetingService->getSessionByIdForAppointment($sessionId, $appointmentId)
    : $meetingService->getSessionForAppointment($appointmentId);

if (!$session) {
    $session = $meetingService->createOrGetSessionForAppointment($appointmentId, $appointment);
}
if (!$meetingService->currentUserCanManageSession($session, $appointment)) {
    medsov_json_response(['ok' => false, 'error' => xl('Not authorized for this telehealth session.')], 403);
}

$config = $meetingService->getJitsiConfig();
medsov_json_response(medsov_session_payload($session, !empty($config['waiting_room_enabled'])));
