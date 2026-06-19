<?php

/**
 * Waiting room and device readiness page for Medsov Telehealth.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';
require_once __DIR__ . '/../src/Services/MeetingRoomService.php';

use OpenEMR\Common\Acl\AclMain;
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
$session = $meetingService->createAdHocSession();
$meetingService->markPatientWaiting((int)$session['id']);
$config = $meetingService->getJitsiConfig();
$siteId = $_GET['site'] ?? $_SESSION['site_id'] ?? 'default';
$siteQuery = 'site=' . urlencode((string)$siteId);
$launchUrl = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/launch.php'
    . '?' . $siteQuery
    . '&room=' . urlencode($session['meeting_room'])
    . '&sid=' . urlencode((string)$session['id']);

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Medsov Telehealth Waiting Room'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        body {
            background: #f4f6f8;
        }
        .medsov-waiting {
            max-width: 1040px;
            margin: 1rem auto;
            padding: 0 .75rem;
        }
        .medsov-waiting-shell {
            background: #fff;
            border: 1px solid #d7dde4;
            border-radius: .375rem;
            overflow: hidden;
        }
        .medsov-waiting-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            flex-wrap: wrap;
            padding: 1rem;
            border-bottom: 1px solid #e4e8ee;
        }
        .medsov-waiting-header h1 {
            font-size: 1.25rem;
            line-height: 1.35;
        }
        .medsov-waiting-body {
            padding: 1rem;
        }
        .medsov-status {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: .75rem;
        }
        .medsov-status-item {
            border: 1px solid #d7dde4;
            border-radius: .375rem;
            padding: .75rem;
            min-height: 5rem;
            background: #fbfcfd;
        }
        .medsov-status-label {
            color: #2d3748;
            display: block;
            font-size: .875rem;
            margin-bottom: .25rem;
        }
        .medsov-status-value {
            color: #566579;
            overflow-wrap: anywhere;
        }
        .medsov-status-value.is-ready {
            color: #1f7a3a;
            font-weight: 600;
        }
        .medsov-status-value.is-checking {
            color: #725b00;
            font-weight: 600;
        }
        .medsov-status-value.is-error {
            color: #a12828;
            font-weight: 600;
        }
        .medsov-actions {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }
        #joinMeeting.disabled {
            pointer-events: none;
        }
    </style>
</head>
<body class="body_top">
<main class="container-fluid medsov-waiting">
    <div class="medsov-waiting-shell">
        <div class="medsov-waiting-header">
            <div>
                <h1 class="h3 m-0"><?php echo xlt('Telehealth Waiting Room'); ?></h1>
                <div class="text-muted"><?php echo xlt('Medsov Telehealth'); ?></div>
            </div>
            <span class="badge badge-info"><?php echo xlt('Test Room'); ?></span>
        </div>
        <div class="medsov-waiting-body">
            <div class="alert alert-info">
                <?php echo xlt('Device checks run in the browser before the embedded Jitsi meeting opens.'); ?>
            </div>

            <div class="medsov-status mb-3">
                <div class="medsov-status-item">
                    <strong class="medsov-status-label"><?php echo xlt('Audio'); ?></strong>
                    <div id="audioStatus" class="medsov-status-value"><?php echo xlt('Pending'); ?></div>
                </div>
                <div class="medsov-status-item">
                    <strong class="medsov-status-label"><?php echo xlt('Video'); ?></strong>
                    <div id="videoStatus" class="medsov-status-value"><?php echo xlt('Pending'); ?></div>
                </div>
                <div class="medsov-status-item">
                    <strong class="medsov-status-label"><?php echo xlt('Meeting Room'); ?></strong>
                    <div class="medsov-status-value"><?php echo text($session['meeting_room']); ?></div>
                </div>
            </div>

            <div class="medsov-actions">
                <button id="checkDevices" class="btn btn-secondary" type="button"><?php echo xlt('Check Devices'); ?></button>
                <a id="joinMeeting" class="btn btn-primary disabled" aria-disabled="true" href="<?php echo attr($launchUrl); ?>"><?php echo xlt('Join Embedded Meeting'); ?></a>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    const audioEnabled = <?php echo $config['audio_enabled'] ? 'true' : 'false'; ?>;
    const videoEnabled = <?php echo $config['video_enabled'] ? 'true' : 'false'; ?>;
    const audioStatus = document.getElementById('audioStatus');
    const videoStatus = document.getElementById('videoStatus');
    const joinMeeting = document.getElementById('joinMeeting');
    const checkDevices = document.getElementById('checkDevices');

    function setStatus(element, message, state) {
        element.textContent = message;
        element.classList.remove('is-ready', 'is-checking', 'is-error');
        if (state) {
            element.classList.add(state);
        }
    }

    function setReady() {
        joinMeeting.classList.remove('disabled');
        joinMeeting.removeAttribute('aria-disabled');
    }

    function setBlocked() {
        joinMeeting.classList.add('disabled');
        joinMeeting.setAttribute('aria-disabled', 'true');
    }

    checkDevices.addEventListener('click', async function () {
        setBlocked();
        checkDevices.disabled = true;
        setStatus(audioStatus, <?php echo js_escape(xl('Checking microphone...')); ?>, 'is-checking');
        setStatus(videoStatus, <?php echo js_escape(xl('Checking camera...')); ?>, 'is-checking');

        if (!audioEnabled && !videoEnabled) {
            setStatus(audioStatus, <?php echo js_escape(xl('Audio disabled by configuration.')); ?>, 'is-ready');
            setStatus(videoStatus, <?php echo js_escape(xl('Video disabled by configuration.')); ?>, 'is-ready');
            setReady();
            checkDevices.disabled = false;
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus(audioStatus, <?php echo js_escape(xl('Browser does not support media device checks.')); ?>, 'is-error');
            setStatus(videoStatus, <?php echo js_escape(xl('Browser does not support media device checks.')); ?>, 'is-error');
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
                setBlocked();
                return;
            }
            if (videoEnabled && !hasVideoTrack) {
                setStatus(videoStatus, <?php echo js_escape(xl('Camera unavailable.')); ?>, 'is-error');
                setBlocked();
                return;
            }

            setStatus(
                audioStatus,
                audioEnabled ? <?php echo js_escape(xl('Microphone ready.')); ?> : <?php echo js_escape(xl('Audio disabled by configuration.')); ?>,
                'is-ready'
            );
            setStatus(
                videoStatus,
                videoEnabled ? <?php echo js_escape(xl('Camera ready.')); ?> : <?php echo js_escape(xl('Video disabled by configuration.')); ?>,
                'is-ready'
            );
            setReady();
        } catch (error) {
            const message = error && error.name === 'NotAllowedError'
                ? <?php echo js_escape(xl('Permission denied. Allow access in the browser and try again.')); ?>
                : <?php echo js_escape(xl('Device unavailable. Check your microphone/camera and try again.')); ?>;
            setStatus(audioStatus, audioEnabled ? message : <?php echo js_escape(xl('Audio disabled by configuration.')); ?>, audioEnabled ? 'is-error' : 'is-ready');
            setStatus(videoStatus, videoEnabled ? message : <?php echo js_escape(xl('Video disabled by configuration.')); ?>, videoEnabled ? 'is-error' : 'is-ready');
            setBlocked();
        } finally {
            checkDevices.disabled = false;
        }
    });
}());
</script>
</body>
</html>
