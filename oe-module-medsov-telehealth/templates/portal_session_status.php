<?php

/**
 * Patient portal JSON status for a telehealth appointment.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$portalContext = require __DIR__ . '/portal_bootstrap.php';

use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;

function medsov_portal_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$moduleService = new ModuleService();
if (!$moduleService->isEnabled()) {
    medsov_portal_json_response(['ok' => false, 'error' => xl('Medsov Telehealth is disabled.')], 409);
}

$meetingService = new MeetingRoomService();
$appointmentId = isset($_GET['eid']) ? (int)$_GET['eid'] : 0;
$token = (string)($_GET['token'] ?? '');
$session = $meetingService->getPortalSessionForPatient($appointmentId, $token, (int)$portalContext['pid'], true);

if (!$session) {
    medsov_portal_json_response(['ok' => false, 'error' => xl('This telehealth appointment is not available for your portal account.')], 403);
}

$config = $meetingService->getJitsiConfig();
$waitingRoomEnabled = !empty($config['waiting_room_enabled']);
$cancelled = (($session['status'] ?? '') === MeetingRoomService::SESSION_STATUS_CANCELLED);
$patientWaiting = !empty($session['patient_waiting_at']);
$admitted = !$cancelled && (!$waitingRoomEnabled || !empty($session['admitted_at']));

if ($cancelled) {
    $label = xl('Appointment canceled');
} elseif ($admitted) {
    $label = xl('Provider admitted you');
} elseif ($patientWaiting) {
    $label = xl('Waiting for provider');
} else {
    $label = xl('Preparing waiting room');
}

$siteQuery = 'site=' . urlencode($portalContext['site_id']);
$joinUrl = $GLOBALS['webroot']
    . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/portal_launch.php?'
    . $siteQuery
    . '&eid=' . urlencode((string)$appointmentId)
    . '&token=' . urlencode($token);

medsov_portal_json_response([
    'ok' => true,
    'session_id' => (int)$session['id'],
    'status' => (string)($session['status'] ?? 'created'),
    'label' => $label,
    'cancelled' => $cancelled,
    'patient_waiting' => $patientWaiting,
    'requires_admission' => $waitingRoomEnabled,
    'admitted' => $admitted,
    'patient_waiting_at' => $session['patient_waiting_at'] ?? null,
    'admitted_at' => $session['admitted_at'] ?? null,
    'join_url' => $joinUrl,
]);
