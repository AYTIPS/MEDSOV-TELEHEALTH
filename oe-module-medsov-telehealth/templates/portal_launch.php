<?php

/**
 * Patient portal Jitsi launch page for Medsov Telehealth.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$portalContext = require __DIR__ . '/portal_bootstrap.php';

use OpenEMR\Core\Header;
use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;

$moduleService = new ModuleService();
if (!$moduleService->isEnabled()) {
    die(xlt('Medsov Telehealth is disabled.'));
}

$meetingService = new MeetingRoomService();
$appointmentId = isset($_GET['eid']) ? (int)$_GET['eid'] : 0;
$token = (string)($_GET['token'] ?? '');
$session = $meetingService->getPortalSessionForPatient($appointmentId, $token, (int)$portalContext['pid']);

if (!$session) {
    http_response_code(403);
    die(xlt('This telehealth appointment is not available for your portal account.'));
}

$config = $meetingService->getJitsiConfig();
if (!empty($config['waiting_room_enabled']) && empty($session['admitted_at'])) {
    http_response_code(403);
    die(xlt('Your provider has not admitted you to this visit yet. Please return to the waiting room.'));
}

$patient = sqlQuery(
    "SELECT `fname`, `lname` FROM `patient_data` WHERE `pid` = ?",
    [(int)$portalContext['pid']]
);
$displayName = trim((string)($patient['fname'] ?? '') . ' ' . (string)($patient['lname'] ?? '')) ?: xl('Patient');

$capacity = $meetingService->registerParticipantEntry(
    (int)$session['id'],
    'patient',
    (int)$portalContext['pid'],
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

$meetingService->markPatientJoined((int)$session['id'], 'patient', (int)$portalContext['pid']);

$room = (string)$session['meeting_room'];
$shortRoom = strlen($room) > 28 ? substr($room, 0, 28) . '...' : $room;
$fullScreenLabel = xla('Full Screen');
$exitFullScreenLabel = xla('Exit Full Screen');
$siteQuery = 'site=' . urlencode((string)$portalContext['site_id']);
$presenceUrl = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/participant_presence.php?' . $siteQuery;

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Medsov Telehealth Visit'); ?></title>
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
        .medsov-meeting-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            flex-wrap: wrap;
            min-height: 4rem;
            padding: .625rem .75rem;
            background: #fff;
            border: 1px solid #ead7d9;
            border-radius: .375rem;
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
        #jitsiContainer {
            min-height: 0;
            height: 100%;
            border: 1px solid #ead7d9;
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
        .medsov-meeting-fullscreen,
        .medsov-meeting-fullscreen:hover,
        .medsov-meeting-fullscreen:focus {
            border-color: #f4212e;
            background: #f4212e;
            color: #fff;
            font-weight: 700;
        }
    </style>
    <script src="<?php echo attr($config['external_api']); ?>"></script>
</head>
<body class="body_top">
<main class="medsov-meeting-page">
    <div class="medsov-meeting-toolbar">
        <div class="medsov-meeting-title">
            <h1 class="h4 m-0"><?php echo xlt('Medsov Telehealth Visit'); ?></h1>
            <div class="medsov-room-line">
                <strong><?php echo xlt('Room'); ?>:</strong>
                <span title="<?php echo attr($room); ?>"><?php echo text($shortRoom); ?></span>
            </div>
        </div>
        <button id="medsovFullscreen" class="btn btn-sm medsov-meeting-fullscreen" type="button">
            <i class="fa fa-expand" aria-hidden="true"></i>
            <span><?php echo xlt('Full Screen'); ?></span>
        </button>
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
    const presenceUrl = <?php echo js_escape($presenceUrl); ?>;
    const sessionId = <?php echo js_escape((string)$session['id']); ?>;
    const appointmentId = <?php echo js_escape((string)$appointmentId); ?>;
    const token = <?php echo js_escape($token); ?>;

    function updateFullscreenButton() {
        if (!fullscreenButton || !fullscreenLabel || !fullscreenIcon) {
            return;
        }
        const isFullscreen = !!document.fullscreenElement;
        fullscreenLabel.textContent = isFullscreen ? exitFullScreenLabel : fullScreenLabel;
        fullscreenIcon.classList.toggle('fa-expand', !isFullscreen);
        fullscreenIcon.classList.toggle('fa-compress', isFullscreen);
    }

    fullscreenButton.addEventListener('click', async function () {
        if (document.fullscreenElement && document.exitFullscreen) {
            await document.exitFullscreen();
            updateFullscreenButton();
            return;
        }
        const target = document.querySelector('.medsov-meeting-page') || document.documentElement;
        if (target.requestFullscreen) {
            await target.requestFullscreen();
            updateFullscreenButton();
            return;
        }
        window.open(window.location.href, '_blank', 'noopener');
    });
    document.addEventListener('fullscreenchange', updateFullscreenButton);
    updateFullscreenButton();

    function buildPresenceBody(action) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('sid', sessionId);
        body.set('eid', appointmentId);
        body.set('participant_type', 'patient');
        body.set('token', token);
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
}());
</script>
</body>
</html>
