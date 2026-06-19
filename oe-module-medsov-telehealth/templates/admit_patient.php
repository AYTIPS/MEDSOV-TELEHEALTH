<?php

/**
 * Provider-side action to admit a patient into a telehealth session.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';
require_once __DIR__ . '/../src/Services/MeetingRoomService.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;

function medsov_admit_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    medsov_admit_json_response(['ok' => false, 'error' => xl('Invalid request method.')], 405);
}

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    medsov_admit_json_response(['ok' => false, 'error' => xl('Security token is invalid.')], 403);
}

if (!AclMain::aclCheckCore('admin', 'super') && !AclMain::aclCheckCore('patients', 'appt')) {
    medsov_admit_json_response(['ok' => false, 'error' => xl('Not authorized.')], 403);
}

$moduleService = new ModuleService();
if (!$moduleService->isEnabled()) {
    medsov_admit_json_response(['ok' => false, 'error' => xl('Medsov Telehealth is disabled.')], 409);
}

$appointmentId = isset($_POST['eid']) ? (int)$_POST['eid'] : 0;
$sessionId = isset($_POST['sid']) ? (int)$_POST['sid'] : 0;
$meetingService = new MeetingRoomService();
$config = $meetingService->getJitsiConfig();
if (empty($config['waiting_room_enabled'])) {
    medsov_admit_json_response(['ok' => false, 'error' => xl('Waiting room is disabled for Medsov Telehealth.')], 409);
}
$appointment = $meetingService->getAppointmentById($appointmentId);

if (!$appointment || !$meetingService->isTelehealthCategory((int)($appointment['pc_catid'] ?? 0))) {
    medsov_admit_json_response(['ok' => false, 'error' => xl('This appointment is not configured for Medsov Telehealth.')], 404);
}
if (!$meetingService->currentUserCanManageAppointment($appointment)) {
    medsov_admit_json_response(['ok' => false, 'error' => xl('Not authorized for this appointment.')], 403);
}

$session = $meetingService->getSessionByIdForAppointment($sessionId, $appointmentId);
if (!$session) {
    medsov_admit_json_response(['ok' => false, 'error' => xl('Telehealth session not found.')], 404);
}
if (!$meetingService->currentUserCanManageSession($session, $appointment)) {
    medsov_admit_json_response(['ok' => false, 'error' => xl('Not authorized for this telehealth session.')], 403);
}

if (!$meetingService->admitPatient($sessionId, 'user', $_SESSION['authUserID'] ?? null)) {
    medsov_admit_json_response(['ok' => false, 'error' => xl('Patient has not entered the waiting room yet.')], 409);
}

$session = $meetingService->getSessionByIdForAppointment($sessionId, $appointmentId);
$patientWaiting = !empty($session['patient_waiting_at']);
$admitted = !empty($session['admitted_at']) || empty($config['waiting_room_enabled']);

medsov_admit_json_response([
    'ok' => true,
    'session_id' => $sessionId,
    'status' => (string)($session['status'] ?? 'admitted'),
    'label' => xl('Patient admitted'),
    'patient_waiting' => $patientWaiting,
    'requires_admission' => !empty($config['waiting_room_enabled']),
    'admitted' => $admitted,
    'patient_waiting_at' => $session['patient_waiting_at'] ?? null,
    'admitted_at' => $session['admitted_at'] ?? null,
]);
