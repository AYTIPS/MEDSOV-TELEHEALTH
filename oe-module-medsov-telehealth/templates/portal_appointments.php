<?php

/**
 * Patient portal appointment list for Medsov Telehealth.
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
$appointments = $meetingService->getUpcomingTelehealthAppointmentsForPatient((int)$portalContext['pid']);
$config = $meetingService->getJitsiConfig();
$waitingRoomEnabled = !empty($config['waiting_room_enabled']);
$siteQuery = 'site=' . urlencode($portalContext['site_id']);
$portalHomeUrl = $portalContext['portal_home_url'];

function medsov_portal_datetime(array $appointment): string
{
    $date = (string)($appointment['pc_eventDate'] ?? '');
    $start = substr((string)($appointment['pc_startTime'] ?? ''), 0, 5);
    $end = substr((string)($appointment['pc_endTime'] ?? ''), 0, 5);
    $timestamp = strtotime(trim($date . ' ' . $start));
    $label = $timestamp ? date('D, M j, Y g:i A', $timestamp) : trim($date . ' ' . $start);

    return $end ? $label . ' - ' . $end : $label;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Medsov Telehealth Appointments'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        body {
            background: #f7f7f8;
            color: #231f20;
        }
        .medsov-portal-shell {
            max-width: 1080px;
            margin: 1.25rem auto;
            padding: 0 1rem;
        }
        .medsov-portal-hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1.25rem;
            border: 1px solid #ead7d9;
            border-radius: .5rem;
            background: #fff;
        }
        .medsov-brand-row {
            display: flex;
            align-items: center;
            gap: .75rem;
        }
        .medsov-mark {
            width: 1.75rem;
            height: 1.75rem;
            border-radius: .375rem;
            background: linear-gradient(90deg, #f4212e 0 45%, transparent 45% 55%, #f4212e 55% 100%);
        }
        .medsov-eyebrow {
            color: #f4212e;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .medsov-title {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
        }
        .medsov-appt-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: .875rem;
        }
        .medsov-appt-card {
            border: 1px solid #ead7d9;
            border-radius: .5rem;
            background: #fff;
            overflow: hidden;
            box-shadow: 0 .5rem 1.25rem rgba(35, 31, 32, .06);
        }
        .medsov-appt-card-top {
            padding: 1rem;
            border-left: .375rem solid #f4212e;
        }
        .medsov-appt-type {
            color: #f4212e;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .medsov-appt-heading {
            margin: .25rem 0 .5rem;
            font-size: 1rem;
            font-weight: 700;
        }
        .medsov-meta {
            margin: 0;
            color: #584f51;
            line-height: 1.5;
        }
        .medsov-appt-actions {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            padding: 0 1rem 1rem;
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
    </style>
</head>
<body class="body_top">
<main class="medsov-portal-shell">
    <section class="medsov-portal-hero">
        <div class="medsov-brand-row">
            <span class="medsov-mark" aria-hidden="true"></span>
            <div>
                <div class="medsov-eyebrow"><?php echo xlt('MedSov Telehealth'); ?></div>
                <h1 class="medsov-title"><?php echo xlt('Upcoming Virtual Care Visits'); ?></h1>
            </div>
        </div>
        <a class="btn medsov-secondary" href="<?php echo attr($portalHomeUrl); ?>"><?php echo xlt('Back to Portal'); ?></a>
    </section>

    <?php if (empty($appointments)) { ?>
        <div class="alert alert-info"><?php echo xlt('No upcoming telehealth appointments are available for your portal account.'); ?></div>
    <?php } else { ?>
        <div class="medsov-appt-grid">
            <?php foreach ($appointments as $appointment) {
                $providerName = trim((string)($appointment['provider_fname'] ?? '') . ' ' . (string)($appointment['provider_lname'] ?? '')) ?: xl('Provider');
                $joinTemplate = $waitingRoomEnabled ? 'portal_waiting_room.php' : 'portal_launch.php';
                $joinUrl = $GLOBALS['webroot']
                    . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/' . $joinTemplate . '?'
                    . $siteQuery
                    . '&eid=' . urlencode((string)$appointment['pc_eid'])
                    . '&token=' . urlencode((string)$appointment['session_uuid']);
            ?>
                <article class="medsov-appt-card">
                    <div class="medsov-appt-card-top">
                        <div class="medsov-appt-type"><?php echo text((string)$appointment['pc_catname']); ?></div>
                        <h2 class="medsov-appt-heading"><?php echo text((string)$appointment['pc_title']); ?></h2>
                        <p class="medsov-meta">
                            <strong><?php echo xlt('When'); ?>:</strong> <?php echo text(medsov_portal_datetime($appointment)); ?><br>
                            <strong><?php echo xlt('Provider'); ?>:</strong> <?php echo text($providerName); ?><br>
                            <strong><?php echo xlt('Status'); ?>:</strong> <?php echo text(ucwords(str_replace('_', ' ', (string)$appointment['session_status']))); ?>
                        </p>
                    </div>
                    <div class="medsov-appt-actions">
                        <a class="btn medsov-primary" href="<?php echo attr($joinUrl); ?>">
                            <i class="fa fa-video-camera" aria-hidden="true"></i>
                            <?php echo $waitingRoomEnabled ? xlt('Enter Waiting Room') : xlt('Join Visit'); ?>
                        </a>
                    </div>
                </article>
            <?php } ?>
        </div>
    <?php } ?>
</main>
</body>
</html>
