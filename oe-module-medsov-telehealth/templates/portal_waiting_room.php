<?php

/**
 * Patient portal waiting room for a Medsov Telehealth appointment.
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
$siteQuery = 'site=' . urlencode($portalContext['site_id']);
$launchUrl = $GLOBALS['webroot']
    . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/portal_launch.php?'
    . $siteQuery
    . '&eid=' . urlencode((string)$appointmentId)
    . '&token=' . urlencode($token);

if (empty($config['waiting_room_enabled'])) {
    header('Location: ' . $launchUrl);
    exit;
}

$meetingService->markPatientWaiting((int)$session['id'], 'patient', (int)$portalContext['pid']);
$statusUrl = $GLOBALS['webroot']
    . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/portal_session_status.php?'
    . $siteQuery
    . '&eid=' . urlencode((string)$appointmentId)
    . '&token=' . urlencode($token);

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Medsov Telehealth Waiting Room'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        body {
            background: #f7f7f8;
            color: #231f20;
        }
        .medsov-waiting {
            max-width: 980px;
            margin: 1.25rem auto;
            padding: 0 1rem;
        }
        .medsov-panel {
            border: 1px solid #ead7d9;
            border-radius: .5rem;
            background: #fff;
            overflow: hidden;
            box-shadow: 0 .5rem 1.25rem rgba(35, 31, 32, .06);
        }
        .medsov-panel-head {
            padding: 1.25rem;
            border-left: .375rem solid #f4212e;
            border-bottom: 1px solid #ead7d9;
        }
        .medsov-eyebrow {
            color: #f4212e;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .medsov-title {
            margin: .25rem 0 0;
            font-size: 1.35rem;
            font-weight: 700;
        }
        .medsov-panel-body {
            padding: 1.25rem;
        }
        .medsov-status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: .75rem;
            margin-bottom: 1rem;
        }
        .medsov-status-item {
            min-height: 5rem;
            padding: .75rem;
            border: 1px solid #ead7d9;
            border-radius: .375rem;
            background: #fbfafb;
        }
        .medsov-status-label {
            display: block;
            color: #584f51;
            font-size: .75rem;
            font-weight: 700;
            margin-bottom: .25rem;
        }
        .medsov-status-value.is-ready {
            color: #1f7a3a;
            font-weight: 700;
        }
        .medsov-status-value.is-checking {
            color: #725b00;
            font-weight: 700;
        }
        .medsov-status-value.is-error {
            color: #a12828;
            font-weight: 700;
        }
        .medsov-actions {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }
        .medsov-primary,
        .medsov-primary:hover,
        .medsov-primary:focus {
            border: 1px solid #f4212e;
            border-radius: 999px;
            background: #f4212e;
            color: #fff;
            font-weight: 700;
            text-decoration: none;
        }
        .medsov-secondary,
        .medsov-secondary:hover,
        .medsov-secondary:focus {
            border: 1px solid #231f20;
            border-radius: 999px;
            background: #fff;
            color: #231f20;
            font-weight: 700;
            text-decoration: none;
        }
        #joinMeeting.disabled {
            pointer-events: none;
        }
    </style>
</head>
<body class="body_top">
<main class="medsov-waiting">
    <section class="medsov-panel">
        <div class="medsov-panel-head">
            <div class="medsov-eyebrow"><?php echo xlt('MedSov Telehealth'); ?></div>
            <h1 class="medsov-title"><?php echo xlt('Waiting Room'); ?></h1>
            <div class="text-muted"><?php echo text((string)$session['pc_title']); ?></div>
        </div>
        <div class="medsov-panel-body">
            <div class="alert alert-info">
                <?php echo xlt('Check your microphone and camera before joining the visit. Your care team can see that you are waiting.'); ?>
            </div>
            <div class="medsov-status-grid">
                <div class="medsov-status-item">
                    <strong class="medsov-status-label"><?php echo xlt('Audio'); ?></strong>
                    <div id="audioStatus" class="medsov-status-value"><?php echo xlt('Pending'); ?></div>
                </div>
                <div class="medsov-status-item">
                    <strong class="medsov-status-label"><?php echo xlt('Video'); ?></strong>
                    <div id="videoStatus" class="medsov-status-value"><?php echo xlt('Pending'); ?></div>
                </div>
                <div class="medsov-status-item">
                    <strong class="medsov-status-label"><?php echo xlt('Visit Status'); ?></strong>
                    <div id="visitStatus" class="medsov-status-value is-checking"><?php echo xlt('Waiting for provider'); ?></div>
                </div>
            </div>
            <div class="medsov-actions">
                <button id="checkDevices" class="btn medsov-secondary" type="button"><?php echo xlt('Check Devices'); ?></button>
                <a id="joinMeeting" class="btn medsov-primary disabled" aria-disabled="true" href="<?php echo attr($launchUrl); ?>"><?php echo xlt('Join Visit'); ?></a>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    const audioEnabled = <?php echo $config['audio_enabled'] ? 'true' : 'false'; ?>;
    const videoEnabled = <?php echo $config['video_enabled'] ? 'true' : 'false'; ?>;
    const statusUrl = <?php echo js_escape($statusUrl); ?>;
    const launchUrl = <?php echo js_escape($launchUrl); ?>;
    const requiresAdmission = <?php echo !empty($config['waiting_room_enabled']) ? 'true' : 'false'; ?>;
    const audioStatus = document.getElementById('audioStatus');
    const videoStatus = document.getElementById('videoStatus');
    const visitStatus = document.getElementById('visitStatus');
    const joinMeeting = document.getElementById('joinMeeting');
    const checkDevices = document.getElementById('checkDevices');
    let devicesReady = false;
    let admitted = !requiresAdmission;
    let cancelled = false;
    let redirected = false;

    function setStatus(element, message, state) {
        element.textContent = message;
        element.classList.remove('is-ready', 'is-checking', 'is-error');
        if (state) {
            element.classList.add(state);
        }
    }

    function setJoinReady() {
        joinMeeting.classList.remove('disabled');
        joinMeeting.removeAttribute('aria-disabled');
        joinMeeting.textContent = <?php echo js_escape(xl('Join Visit')); ?>;
    }

    function setBlocked() {
        joinMeeting.classList.add('disabled');
        joinMeeting.setAttribute('aria-disabled', 'true');
    }

    function updateJoinState() {
        if (cancelled) {
            setBlocked();
            joinMeeting.textContent = <?php echo js_escape(xl('Visit Canceled')); ?>;
            setStatus(visitStatus, <?php echo js_escape(xl('This appointment has been canceled.')); ?>, 'is-error');
            return;
        }

        if (admitted && devicesReady) {
            setStatus(visitStatus, <?php echo js_escape(xl('Provider admitted you. Joining visit...')); ?>, 'is-ready');
            setJoinReady();
            if (!redirected) {
                redirected = true;
                window.setTimeout(function () {
                    window.location.assign(launchUrl);
                }, 700);
            }
            return;
        }

        setBlocked();
        if (!devicesReady) {
            joinMeeting.textContent = <?php echo js_escape(xl('Check Devices First')); ?>;
            return;
        }

        if (requiresAdmission && !admitted) {
            joinMeeting.textContent = <?php echo js_escape(xl('Waiting for Provider')); ?>;
            setStatus(visitStatus, <?php echo js_escape(xl('Waiting for provider to admit you.')); ?>, 'is-checking');
            return;
        }

        setJoinReady();
    }

    function pollAdmissionStatus() {
        if (!window.fetch) {
            return;
        }
        fetch(statusUrl, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    return;
                }
                cancelled = !!data.cancelled;
                admitted = !!data.admitted;
                if (cancelled) {
                    setStatus(visitStatus, data.label || <?php echo js_escape(xl('Appointment canceled')); ?>, 'is-error');
                } else if (!admitted && data.label) {
                    setStatus(visitStatus, data.label, data.patient_waiting ? 'is-checking' : 'is-ready');
                }
                updateJoinState();
            })
            .catch(function () {});
    }

    checkDevices.addEventListener('click', async function () {
        setBlocked();
        devicesReady = false;
        checkDevices.disabled = true;
        setStatus(audioStatus, <?php echo js_escape(xl('Checking microphone...')); ?>, 'is-checking');
        setStatus(videoStatus, <?php echo js_escape(xl('Checking camera...')); ?>, 'is-checking');

        if (!audioEnabled && !videoEnabled) {
            setStatus(audioStatus, <?php echo js_escape(xl('Audio disabled by configuration.')); ?>, 'is-ready');
            setStatus(videoStatus, <?php echo js_escape(xl('Video disabled by configuration.')); ?>, 'is-ready');
            devicesReady = true;
            updateJoinState();
            checkDevices.disabled = false;
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus(audioStatus, <?php echo js_escape(xl('Browser does not support media device checks.')); ?>, 'is-error');
            setStatus(videoStatus, <?php echo js_escape(xl('Browser does not support media device checks.')); ?>, 'is-error');
            devicesReady = false;
            updateJoinState();
            checkDevices.disabled = false;
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: audioEnabled,
                video: videoEnabled
            });
            const hasAudioTrack = stream.getAudioTracks().length > 0;
            const hasVideoTrack = stream.getVideoTracks().length > 0;

            stream.getTracks().forEach(track => track.stop());

            if (audioEnabled && !hasAudioTrack) {
                setStatus(audioStatus, <?php echo js_escape(xl('Microphone unavailable.')); ?>, 'is-error');
                devicesReady = false;
                updateJoinState();
                return;
            }
            if (videoEnabled && !hasVideoTrack) {
                setStatus(videoStatus, <?php echo js_escape(xl('Camera unavailable.')); ?>, 'is-error');
                devicesReady = false;
                updateJoinState();
                return;
            }

            setStatus(audioStatus, audioEnabled ? <?php echo js_escape(xl('Microphone ready.')); ?> : <?php echo js_escape(xl('Audio disabled by configuration.')); ?>, 'is-ready');
            setStatus(videoStatus, videoEnabled ? <?php echo js_escape(xl('Camera ready.')); ?> : <?php echo js_escape(xl('Video disabled by configuration.')); ?>, 'is-ready');
            devicesReady = true;
            updateJoinState();
        } catch (error) {
            const message = error && error.name === 'NotAllowedError'
                ? <?php echo js_escape(xl('Permission denied. Allow access in the browser and try again.')); ?>
                : <?php echo js_escape(xl('Device unavailable. Check your microphone/camera and try again.')); ?>;
            setStatus(audioStatus, audioEnabled ? message : <?php echo js_escape(xl('Audio disabled by configuration.')); ?>, audioEnabled ? 'is-error' : 'is-ready');
            setStatus(videoStatus, videoEnabled ? message : <?php echo js_escape(xl('Video disabled by configuration.')); ?>, videoEnabled ? 'is-error' : 'is-ready');
            devicesReady = false;
            updateJoinState();
        } finally {
            checkDevices.disabled = false;
        }
    });

    joinMeeting.addEventListener('click', function (event) {
        if (!devicesReady || !admitted) {
            event.preventDefault();
            updateJoinState();
        }
    });

    updateJoinState();
    pollAdmissionStatus();
    window.setInterval(pollAdmissionStatus, 3000);
}());
</script>
</body>
</html>
