<?php

/**
 * Presence endpoint used to enforce participant capacity.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

function medsov_presence_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    medsov_presence_json(['ok' => false, 'error' => 'Invalid request method.'], 405);
}

$participantType = strtolower(trim((string)($_POST['participant_type'] ?? '')));
$action = strtolower(trim((string)($_POST['action'] ?? 'heartbeat')));
$sessionId = (int)($_POST['sid'] ?? 0);
$appointmentId = (int)($_POST['eid'] ?? 0);

if (!in_array($action, ['heartbeat', 'leave'], true) || empty($sessionId)) {
    medsov_presence_json(['ok' => false, 'error' => 'Invalid presence request.'], 400);
}

if ($participantType === 'patient') {
    $portalContext = require __DIR__ . '/portal_bootstrap.php';

    $meetingService = new \OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService();
    $moduleService = new \OpenEMR\Modules\MedsovTelehealth\Services\ModuleService();
    if (!$moduleService->isEnabled()) {
        medsov_presence_json(['ok' => false, 'error' => xl('Medsov Telehealth is disabled.')], 409);
    }

    $token = (string)($_POST['token'] ?? '');
    $session = $meetingService->getPortalSessionForPatient($appointmentId, $token, (int)$portalContext['pid'], true);
    if (!$session || (int)$session['id'] !== $sessionId) {
        medsov_presence_json(['ok' => false, 'error' => xl('Not authorized for this telehealth session.')], 403);
    }

    if ($action === 'leave') {
        $meetingService->markParticipantLeft($sessionId, 'patient', (int)$portalContext['pid']);
    } else {
        $meetingService->markParticipantHeartbeat($sessionId, 'patient', (int)$portalContext['pid']);
    }

    medsov_presence_json(['ok' => true]);
}

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';
require_once __DIR__ . '/../src/Services/MeetingRoomService.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    medsov_presence_json(['ok' => false, 'error' => xl('Security token is invalid.')], 403);
}

if (!AclMain::aclCheckCore('admin', 'super') && !AclMain::aclCheckCore('patients', 'appt')) {
    medsov_presence_json(['ok' => false, 'error' => xl('Not authorized.')], 403);
}

$moduleService = new ModuleService();
if (!$moduleService->isEnabled()) {
    medsov_presence_json(['ok' => false, 'error' => xl('Medsov Telehealth is disabled.')], 409);
}

$meetingService = new MeetingRoomService();
$session = $appointmentId > 0
    ? $meetingService->getSessionByIdForAppointment($sessionId, $appointmentId)
    : sqlQuery("SELECT * FROM `medsov_telehealth_sessions` WHERE `id` = ? LIMIT 1", [$sessionId]);
if (!$session) {
    medsov_presence_json(['ok' => false, 'error' => xl('Telehealth session not found.')], 404);
}

$appointment = $appointmentId > 0 ? $meetingService->getAppointmentById($appointmentId) : null;
if ($appointmentId > 0 && (!$appointment || !$meetingService->currentUserCanManageAppointment($appointment))) {
    medsov_presence_json(['ok' => false, 'error' => xl('Not authorized for this appointment.')], 403);
}
if (!$meetingService->currentUserCanManageSession($session, $appointment)) {
    medsov_presence_json(['ok' => false, 'error' => xl('Not authorized for this telehealth session.')], 403);
}

$participantId = (int)($_SESSION['authUserID'] ?? 0);
if ($action === 'leave') {
    $meetingService->markParticipantLeft($sessionId, 'provider', $participantId);
} else {
    $meetingService->markParticipantHeartbeat($sessionId, 'provider', $participantId);
}

medsov_presence_json(['ok' => true]);
