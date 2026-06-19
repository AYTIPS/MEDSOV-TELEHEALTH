<?php

/**
 * Embedded Jitsi launch page for Medsov Telehealth.
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
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;

if (!AclMain::aclCheckCore('admin', 'super') && !AclMain::aclCheckCore('patients', 'appt')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl('Not Authorized')]);
    exit;
}

$moduleService = new ModuleService();
if (!$moduleService->isEnabled()) {
    die(xlt('Medsov Telehealth is disabled.'));
}

$meetingService = new MeetingRoomService();
$sessionId = isset($_GET['sid']) ? (int)$_GET['sid'] : null;
$appointmentId = isset($_GET['eid']) ? (int)$_GET['eid'] : null;

if (!empty($appointmentId)) {
    $appointment = $meetingService->getAppointmentById($appointmentId);
    if (!$appointment || !$meetingService->isTelehealthCategory((int)($appointment['pc_catid'] ?? 0))) {
        die(xlt('This appointment is not configured for Medsov Telehealth.'));
    }
    if (!$meetingService->currentUserCanManageAppointment($appointment)) {
        http_response_code(403);
        echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl('Not Authorized')]);
        exit;
    }
    if ($meetingService->isAppointmentCancelled($appointment)) {
        http_response_code(409);
        die(xlt('This Medsov Telehealth appointment is canceled.'));
    }
    $session = $meetingService->createOrGetSessionForAppointment($appointmentId, $appointment);
    $sessionId = (int)$session['id'];
    $meetingService->createOrGetEncounterForSession($sessionId, $appointment, 'user', $_SESSION['authUserID'] ?? null);
    $room = $session['meeting_room'];
} else {
    $room = $meetingService->sanitizeRoomName((string)($_GET['room'] ?? ''));
}

if (empty($room)) {
    $session = $meetingService->createAdHocSession();
    $sessionId = (int)$session['id'];
    $room = $session['meeting_room'];
}

$config = $meetingService->getJitsiConfig();
$displayName = $_SESSION['authUser'] ?? 'OpenEMR User';
$capacity = $meetingService->registerParticipantEntry(
    $sessionId,
    'provider',
    $_SESSION['authUserID'] ?? 0,
    (string)$displayName,
    (int)$config['max_participants']
);
if (empty($capacity['allowed'])) {
    http_response_code(409);
    die(sprintf(
        xlt('This telehealth session has reached its participant limit of %s. Please wait for another participant to leave.'),
        text((string)($capacity['max_participants'] ?? $config['max_participants']))
    ));
}
$meetingService->markProviderJoined($sessionId);
$shortRoom = strlen($room) > 28 ? substr($room, 0, 28) . '...' : $room;
$siteId = $_GET['site'] ?? $_SESSION['site_id'] ?? 'default';
$siteQuery = 'site=' . urlencode((string)$siteId);
$newRoomUrl = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/waiting_room.php?' . $siteQuery;
$presenceUrl = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/participant_presence.php?' . $siteQuery;
$presenceCsrfToken = CsrfUtils::collectCsrfToken();
$fullScreenLabel = xla('Full Screen');
$exitFullScreenLabel = xla('Exit Full Screen');
$canManageAdmission = !empty($config['waiting_room_enabled']) && !empty($appointmentId) && !empty($sessionId);
$statusUrl = $canManageAdmission
    ? $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/session_status.php'
        . '?' . $siteQuery
        . '&eid=' . urlencode((string)$appointmentId)
        . '&sid=' . urlencode((string)$sessionId)
    : '';
$admitUrl = $canManageAdmission
    ? $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/admit_patient.php?' . $siteQuery
    : '';
$csrfToken = $canManageAdmission ? CsrfUtils::collectCsrfToken() : '';
$currentSession = $canManageAdmission ? $meetingService->getSessionByIdForAppointment((int)$sessionId, (int)$appointmentId) : null;
$patientWaiting = !empty($currentSession['patient_waiting_at']);
$patientAdmitted = !empty($currentSession['admitted_at']);
$waitingState = $patientAdmitted ? 'admitted' : ($patientWaiting ? 'waiting' : 'pending');
$waitingLabel = $patientAdmitted ? xlt('Patient admitted') : ($patientWaiting ? xlt('Patient waiting') : xlt('Waiting for patient'));
$admitDisabled = (!$patientWaiting || $patientAdmitted) ? ' disabled' : '';

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Medsov Telehealth Meeting'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        html,
        body {
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0 !important;
            overflow: hidden;
            background: #f4f6f8;
        }
        main.medsov-meeting-page {
            width: 100%;
            max-width: none !important;
            height: 100vh;
            margin: 0 !important;
            display: grid;
            grid-template-rows: auto minmax(0, 1fr);
            gap: .625rem;
            padding: .625rem;
            box-sizing: border-box;
        }
        .medsov-meeting-page:fullscreen {
            background: #f4f6f8;
        }
        .medsov-meeting-page * {
            box-sizing: border-box;
        }
        .medsov-meeting-toolbar {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            flex-wrap: wrap;
            min-height: 4rem;
            padding: .625rem .75rem;
            background: #fff;
            border: 1px solid #d7dde4;
            border-radius: .375rem;
        }
        .medsov-meeting-title {
            min-width: 0;
        }
        .medsov-meeting-title h1 {
            font-size: 1.125rem;
            line-height: 1.35;
        }
        .medsov-room-line {
            color: #566579;
            font-size: .875rem;
            overflow-wrap: anywhere;
        }
        .medsov-room-label {
            font-weight: 600;
            color: #2d3748;
        }
        #jitsiContainer {
            min-height: 0;
            height: 100%;
            border: 1px solid #d7dde4;
            border-radius: .375rem;
            background: #111;
            overflow: hidden;
        }
        #jitsiContainer iframe {
            display: block;
            width: 100% !important;
            height: 100% !important;
            border: 0;
        }
        .medsov-meeting-actions {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .medsov-meeting-fullscreen,
        .medsov-meeting-fullscreen:hover,
        .medsov-meeting-fullscreen:focus {
            border-color: #f4212e;
            background: #f4212e;
            color: #fff;
            font-weight: 700;
        }
        .medsov-admit-pill {
            display: inline-flex;
            align-items: center;
            gap: .375rem;
            min-height: 2rem;
            padding: .3125rem .625rem;
            border-radius: 999px;
            font-size: .8125rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .medsov-admit-pill[data-state='pending'] {
            border: 1px solid #d7dde4;
            background: #fff;
            color: #566579;
        }
        .medsov-admit-pill[data-state='waiting'] {
            border: 1px solid #f7c3c8;
            background: #fff1f2;
            color: #a70d18;
        }
        .medsov-admit-pill[data-state='admitted'] {
            border: 1px solid #b8e2c0;
            background: #eefaf0;
            color: #1f7a3a;
        }
        .medsov-admit-button,
        .medsov-admit-button:hover,
        .medsov-admit-button:focus {
            border-color: #f4212e;
            background: #f4212e;
            color: #fff;
            font-weight: 700;
        }
        .medsov-admit-button:disabled {
            opacity: .55;
            cursor: not-allowed;
        }
    </style>
    <script src="<?php echo attr($config['external_api']); ?>"></script>
</head>
<body class="body_top">
<main class="medsov-meeting-page">
    <div class="medsov-meeting-toolbar">
        <div class="medsov-meeting-title">
            <h1 class="h4 m-0"><?php echo xlt('Medsov Telehealth Meeting'); ?></h1>
            <div class="medsov-room-line">
                <span class="medsov-room-label"><?php echo xlt('Room'); ?>:</span>
                <span title="<?php echo attr($room); ?>"><?php echo text($shortRoom); ?></span>
            </div>
        </div>
        <div class="medsov-meeting-actions">
            <?php if ($canManageAdmission) { ?>
                <span id="medsovAdmissionStatus" class="medsov-admit-pill" data-state="<?php echo attr($waitingState); ?>">
                    <i class="fa fa-circle" aria-hidden="true"></i>
                    <span><?php echo $waitingLabel; ?></span>
                </span>
                <button id="medsovAdmitPatient" class="btn btn-sm medsov-admit-button" type="button"<?php echo $admitDisabled; ?>>
                    <i class="fa fa-user-plus" aria-hidden="true"></i>
                    <span><?php echo xlt('Admit Patient'); ?></span>
                </button>
            <?php } ?>
            <button id="medsovFullscreen" class="btn btn-sm medsov-meeting-fullscreen" type="button">
                <i class="fa fa-expand" aria-hidden="true"></i>
                <span><?php echo xlt('Full Screen'); ?></span>
            </button>
            <a class="btn btn-secondary btn-sm" href="<?php echo attr($newRoomUrl); ?>"><?php echo xlt('New Test Room'); ?></a>
        </div>
    </div>

    <div id="jitsiContainer"></div>
</main>

<script>
(function () {
    const parentNode = document.getElementById('jitsiContainer');
    const domain = <?php echo js_escape($config['domain']); ?>;
    const options = {
        roomName: <?php echo js_escape($room); ?>,
        parentNode,
        width: '100%',
        height: '100%',
        userInfo: {
            displayName: <?php echo js_escape($displayName); ?>
        },
        configOverwrite: {
            prejoinConfig: {
                enabled: false
            },
            prejoinPageEnabled: false,
            disableDeepLinking: true,
            requireDisplayName: false,
            startWithAudioMuted: <?php echo $config['audio_enabled'] ? 'false' : 'true'; ?>,
            startWithVideoMuted: <?php echo $config['video_enabled'] ? 'false' : 'true'; ?>
        },
        interfaceConfigOverwrite: {
            SHOW_JITSI_WATERMARK: false,
            SHOW_BRAND_WATERMARK: false,
            SHOW_POWERED_BY: false,
            MOBILE_APP_PROMO: false
        }
    };

    if (typeof JitsiMeetExternalAPI === 'undefined') {
        parentNode.innerHTML = '<div class="alert alert-danger m-3"><?php echo xla('Unable to load Jitsi iframe API. Check the configured Jitsi External API Script.'); ?></div>';
        return;
    }

    window.medsovJitsiApi = new JitsiMeetExternalAPI(domain, options);

    const fullscreenButton = document.getElementById('medsovFullscreen');
    const fullscreenLabel = fullscreenButton ? fullscreenButton.querySelector('span') : null;
    const fullscreenIcon = fullscreenButton ? fullscreenButton.querySelector('i') : null;
    const fullScreenLabel = <?php echo js_escape($fullScreenLabel); ?>;
    const exitFullScreenLabel = <?php echo js_escape($exitFullScreenLabel); ?>;
    const canManageAdmission = <?php echo $canManageAdmission ? 'true' : 'false'; ?>;
    const statusUrl = <?php echo js_escape($statusUrl); ?>;
    const admitUrl = <?php echo js_escape($admitUrl); ?>;
    const appointmentId = <?php echo js_escape((string)($appointmentId ?? '')); ?>;
    const sessionId = <?php echo js_escape((string)($sessionId ?? '')); ?>;
    const csrfToken = <?php echo js_escape($csrfToken); ?>;
    const presenceUrl = <?php echo js_escape($presenceUrl); ?>;
    const presenceCsrfToken = <?php echo js_escape($presenceCsrfToken); ?>;
    const participantType = 'provider';
    const admissionStatus = document.getElementById('medsovAdmissionStatus');
    const admissionStatusText = admissionStatus ? admissionStatus.querySelector('span') : null;
    const admitPatientButton = document.getElementById('medsovAdmitPatient');
    const admitPatientLabel = admitPatientButton ? admitPatientButton.querySelector('span') : null;

    function applyAdmissionStatus(data) {
        if (!data || !data.ok || !admissionStatus) {
            return;
        }
        const state = data.admitted ? 'admitted' : (data.patient_waiting ? 'waiting' : 'pending');
        admissionStatus.setAttribute('data-state', state);
        if (admissionStatusText) {
            admissionStatusText.textContent = data.label || '';
        }
        if (admitPatientButton) {
            admitPatientButton.disabled = !data.requires_admission || data.admitted || !data.patient_waiting;
        }
        if (admitPatientLabel) {
            admitPatientLabel.textContent = data.admitted ? <?php echo js_escape(xl('Patient Admitted')); ?> : <?php echo js_escape(xl('Admit Patient')); ?>;
        }
    }

    function refreshAdmissionStatus() {
        if (!canManageAdmission || !window.fetch || !statusUrl) {
            return;
        }
        fetch(statusUrl, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(applyAdmissionStatus)
            .catch(function () {});
    }

    if (admitPatientButton) {
        admitPatientButton.addEventListener('click', function () {
            if (!window.fetch || admitPatientButton.disabled) {
                return;
            }
            admitPatientButton.disabled = true;
            const body = new URLSearchParams();
            body.set('eid', appointmentId);
            body.set('sid', sessionId);
            body.set('csrf_token_form', csrfToken);
            fetch(admitUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            })
                .then(function (response) { return response.json(); })
                .then(applyAdmissionStatus)
                .catch(refreshAdmissionStatus);
        });
    }

    function getTopDocument() {
        try {
            return window.top && window.top.document ? window.top.document : null;
        } catch (error) {
            return null;
        }
    }

    function getFullscreenDocument() {
        const topDocument = getTopDocument();
        if (document.fullscreenElement) {
            return document;
        }
        if (topDocument && topDocument.fullscreenElement) {
            return topDocument;
        }
        return null;
    }

    function updateFullscreenButton() {
        if (!fullscreenButton || !fullscreenLabel || !fullscreenIcon) {
            return;
        }
        const isFullscreen = !!getFullscreenDocument();
        fullscreenLabel.textContent = isFullscreen ? exitFullScreenLabel : fullScreenLabel;
        fullscreenIcon.classList.toggle('fa-expand', !isFullscreen);
        fullscreenIcon.classList.toggle('fa-compress', isFullscreen);
    }

    async function requestMeetingFullscreen() {
        const topDocument = getTopDocument();
        const targets = [document.querySelector('.medsov-meeting-page'), document.documentElement].filter(Boolean);
        if (topDocument && topDocument !== document) {
            targets.push(topDocument.documentElement);
        }

        for (const target of targets) {
            if (!target || !target.requestFullscreen) {
                continue;
            }
            try {
                await target.requestFullscreen();
                updateFullscreenButton();
                return;
            } catch (error) {
                // Try the next available target before falling back.
            }
        }

        window.open(window.location.href, '_blank', 'noopener');
    }

    async function exitMeetingFullscreen() {
        const fullscreenDocument = getFullscreenDocument();
        if (fullscreenDocument && fullscreenDocument.exitFullscreen) {
            await fullscreenDocument.exitFullscreen();
        }
        updateFullscreenButton();
    }

    if (fullscreenButton) {
        fullscreenButton.addEventListener('click', function () {
            if (getFullscreenDocument()) {
                exitMeetingFullscreen();
                return;
            }
            requestMeetingFullscreen();
        });
        document.addEventListener('fullscreenchange', updateFullscreenButton);
        const topDocument = getTopDocument();
        if (topDocument && topDocument !== document) {
            topDocument.addEventListener('fullscreenchange', updateFullscreenButton);
        }
        updateFullscreenButton();
    }

    function buildPresenceBody(action) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('sid', sessionId);
        body.set('eid', appointmentId);
        body.set('participant_type', participantType);
        body.set('csrf_token_form', presenceCsrfToken);
        return body;
    }

    function sendPresence(action) {
        if (!presenceUrl || !sessionId) {
            return;
        }
        const body = buildPresenceBody(action);
        if (action === 'leave' && navigator.sendBeacon) {
            const formData = new FormData();
            body.forEach(function (value, key) {
                formData.set(key, value);
            });
            navigator.sendBeacon(presenceUrl, formData);
            return;
        }
        if (window.fetch) {
            fetch(presenceUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
                keepalive: action === 'leave'
            }).catch(function () {});
        }
    }

    window.setInterval(function () {
        sendPresence('heartbeat');
    }, 60000);
    window.addEventListener('pagehide', function () {
        sendPresence('leave');
    });
    window.addEventListener('beforeunload', function () {
        sendPresence('leave');
    });

    refreshAdmissionStatus();
    window.setInterval(refreshAdmissionStatus, 5000);
}());
</script>
</body>
</html>
