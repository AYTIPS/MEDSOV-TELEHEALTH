<?php

/**
 * Provider-scoped JSON queue of telehealth patients waiting for admission.
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
use OpenEMR\Modules\MedsovTelehealth\Bootstrap;
use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;

function medsov_provider_queue_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function medsov_provider_queue_datetime(array $session): string
{
    $date = (string)($session['pc_eventDate'] ?? '');
    $start = substr((string)($session['pc_startTime'] ?? ''), 0, 5);
    $timestamp = strtotime(trim($date . ' ' . $start));

    return $timestamp ? date('D, M j, Y g:i A', $timestamp) : trim($date . ' ' . $start);
}

if (!AclMain::aclCheckCore('admin', 'super') && !AclMain::aclCheckCore('patients', 'appt')) {
    medsov_provider_queue_json(['ok' => false, 'error' => xl('Not authorized.')], 403);
}

$moduleService = new ModuleService();
if (!$moduleService->isEnabled()) {
    medsov_provider_queue_json(['ok' => true, 'count' => 0, 'items' => []]);
}

$meetingService = new MeetingRoomService();
$config = $meetingService->getJitsiConfig();
if (empty($config['waiting_room_enabled'])) {
    medsov_provider_queue_json(['ok' => true, 'count' => 0, 'items' => []]);
}

$providerId = (int)($_SESSION['authUserID'] ?? 0);
if (empty($providerId)) {
    medsov_provider_queue_json(['ok' => true, 'count' => 0, 'items' => []]);
}

$siteId = $_GET['site'] ?? $_SESSION['site_id'] ?? 'default';
$siteQuery = 'site=' . urlencode((string)$siteId);
$includeAllProviders = $meetingService->currentUserCanAdministerTelehealth();
$sessions = $meetingService->getWaitingSessionsForProvider($providerId, 10, $includeAllProviders);
$items = [];

foreach ($sessions as $session) {
    $patientName = trim((string)($session['patient_fname'] ?? '') . ' ' . (string)($session['patient_lname'] ?? '')) ?: xl('Patient');
    $providerName = trim((string)($session['provider_fname'] ?? '') . ' ' . (string)($session['provider_lname'] ?? '')) ?: xl('Provider');
    $waitingSince = strtotime((string)($session['patient_waiting_at'] ?? ''));
    $waitingMinutes = $waitingSince ? max(0, (int)floor((time() - $waitingSince) / 60)) : 0;
    $launchUrl = $GLOBALS['webroot']
        . '/interface/modules/custom_modules/' . Bootstrap::MODULE_DIRECTORY . '/templates/launch.php?'
        . $siteQuery
        . '&eid=' . urlencode((string)$session['pc_eid'])
        . '&sid=' . urlencode((string)$session['id'])
        . '&room=' . urlencode((string)$session['meeting_room'])
        . '&role=provider';

    $items[] = [
        'session_id' => (int)$session['id'],
        'appointment_id' => (int)$session['pc_eid'],
        'patient_name' => $patientName,
        'provider_name' => $providerName,
        'title' => (string)($session['pc_title'] ?? xl('Telehealth Visit')),
        'appointment_time' => medsov_provider_queue_datetime($session),
        'waiting_minutes' => $waitingMinutes,
        'waiting_label' => $waitingMinutes <= 0 ? xl('Just arrived') : sprintf(xl('%s min waiting'), $waitingMinutes),
        'launch_url' => $launchUrl,
    ];
}

medsov_provider_queue_json([
    'ok' => true,
    'count' => count($items),
    'csrf_token' => CsrfUtils::collectCsrfToken(),
    'items' => $items,
]);
