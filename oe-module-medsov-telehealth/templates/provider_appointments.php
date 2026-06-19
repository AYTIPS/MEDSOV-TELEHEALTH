<?php

/**
 * Provider-facing upcoming Medsov Telehealth appointment list.
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
use OpenEMR\Modules\MedsovTelehealth\Bootstrap;
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
$includeAllProviders = $meetingService->currentUserCanAdministerTelehealth();
$providerId = (int)($_SESSION['authUserID'] ?? 0);
$appointments = $meetingService->getUpcomingTelehealthAppointmentsForProvider($providerId, $includeAllProviders, 200);
$siteId = $_GET['site'] ?? $_SESSION['site_id'] ?? 'default';
$siteQuery = 'site=' . urlencode((string)$siteId);

function medsov_provider_appt_datetime(array $appointment): string
{
    $date = (string)($appointment['pc_eventDate'] ?? '');
    $start = substr((string)($appointment['pc_startTime'] ?? ''), 0, 5);
    $end = substr((string)($appointment['pc_endTime'] ?? ''), 0, 5);
    $timestamp = strtotime(trim($date . ' ' . $start));
    $label = $timestamp ? date('D, M j, Y g:i A', $timestamp) : trim($date . ' ' . $start);

    return $end ? $label . ' - ' . $end : $label;
}

function medsov_provider_appt_status(array $appointment): array
{
    if (!empty($appointment['admitted_at'])) {
        return ['label' => xl('Patient admitted'), 'class' => ' is-success'];
    }

    if (!empty($appointment['patient_waiting_at'])) {
        return ['label' => xl('Patient waiting'), 'class' => ' is-warning'];
    }

    if (!empty($appointment['provider_joined_at'])) {
        return ['label' => xl('Provider started'), 'class' => ' is-info'];
    }

    return ['label' => xl('Ready'), 'class' => ''];
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
        .medsov-provider-appts {
            max-width: 1280px;
            margin: 1rem auto 2rem;
            padding: 0 1rem;
        }
        .medsov-provider-hero,
        .medsov-provider-panel {
            border: 1px solid #ead7d9;
            border-radius: .5rem;
            background: #fff;
            box-shadow: 0 .5rem 1.25rem rgba(35, 31, 32, .06);
        }
        .medsov-provider-hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1.25rem;
        }
        .medsov-provider-brand {
            display: flex;
            align-items: center;
            gap: .75rem;
            min-width: 0;
        }
        .medsov-provider-mark {
            width: 2rem;
            height: 2rem;
            border-radius: .375rem;
            background: linear-gradient(90deg, #f4212e 0 45%, transparent 45% 55%, #f4212e 55% 100%);
            flex: 0 0 auto;
        }
        .medsov-provider-eyebrow {
            color: #f4212e;
            font-size: .75rem;
            font-weight: 800;
            line-height: 1;
            text-transform: uppercase;
        }
        .medsov-provider-title {
            margin: .25rem 0 0;
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.2;
        }
        .medsov-provider-subtitle {
            margin: .375rem 0 0;
            color: #5d5557;
        }
        .medsov-provider-count {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            min-height: 2.25rem;
            padding: .375rem .75rem;
            border: 1px solid #f7c3c8;
            border-radius: 999px;
            background: #fff1f2;
            color: #a70d18;
            font-weight: 800;
            white-space: nowrap;
        }
        .medsov-provider-panel {
            overflow: hidden;
        }
        .medsov-provider-table-wrap {
            overflow: auto;
        }
        .medsov-provider-table {
            min-width: 1040px;
            margin: 0;
        }
        .medsov-provider-table th {
            background: #fffafa;
            border-top: 0;
            color: #231f20;
            font-size: .8125rem;
            text-transform: uppercase;
        }
        .medsov-provider-table td {
            vertical-align: middle;
            font-size: .875rem;
        }
        .medsov-muted {
            color: #6b6264;
            font-size: .8rem;
        }
        .medsov-status-pill {
            display: inline-flex;
            align-items: center;
            gap: .375rem;
            min-height: 2rem;
            padding: .3125rem .625rem;
            border: 1px solid #d7dde4;
            border-radius: 999px;
            background: #fff;
            color: #566579;
            font-size: .8125rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .medsov-status-pill.is-warning {
            border-color: #f7c3c8;
            background: #fff1f2;
            color: #a70d18;
        }
        .medsov-status-pill.is-success {
            border-color: #b8e2c0;
            background: #eefaf0;
            color: #1f7a3a;
        }
        .medsov-status-pill.is-info {
            border-color: #b8d8f0;
            background: #f0f7ff;
            color: #245c8f;
        }
        .medsov-action-row {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }
        .medsov-primary,
        .medsov-primary:hover,
        .medsov-primary:focus {
            border-color: #f4212e;
            background: #f4212e;
            color: #fff;
            font-weight: 800;
        }
        .medsov-secondary,
        .medsov-secondary:hover,
        .medsov-secondary:focus {
            border-color: #231f20;
            background: #fff;
            color: #231f20;
            font-weight: 800;
        }
        @media (max-width: 720px) {
            .medsov-provider-hero {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body class="body_top">
<main class="medsov-provider-appts">
    <section class="medsov-provider-hero">
        <div class="medsov-provider-brand">
            <span class="medsov-provider-mark" aria-hidden="true"></span>
            <div>
                <div class="medsov-provider-eyebrow"><?php echo xlt('Medsov Telehealth'); ?></div>
                <h1 class="medsov-provider-title"><?php echo xlt('Upcoming Telehealth Appointments'); ?></h1>
                <p class="medsov-provider-subtitle">
                    <?php echo $includeAllProviders
                        ? xlt('Administrator view across all providers.')
                        : xlt('Provider view for assigned telehealth appointments.'); ?>
                </p>
            </div>
        </div>
        <div class="medsov-provider-count">
            <i class="fa fa-calendar-check-o" aria-hidden="true"></i>
            <?php echo sprintf(xlt('%s appointments'), text((string)count($appointments))); ?>
        </div>
    </section>

    <section class="medsov-provider-panel">
        <?php if (empty($appointments)) { ?>
            <div class="alert alert-info m-3"><?php echo xlt('No upcoming Medsov Telehealth appointments are available.'); ?></div>
        <?php } else { ?>
            <div class="medsov-provider-table-wrap">
                <table class="table table-sm table-striped medsov-provider-table">
                    <thead>
                    <tr>
                        <th><?php echo xlt('Date / Time'); ?></th>
                        <th><?php echo xlt('Patient'); ?></th>
                        <th><?php echo xlt('Provider'); ?></th>
                        <th><?php echo xlt('Visit'); ?></th>
                        <th><?php echo xlt('Status'); ?></th>
                        <th><?php echo xlt('Actions'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($appointments as $appointment) {
                        $patientName = trim((string)($appointment['patient_fname'] ?? '') . ' ' . (string)($appointment['patient_lname'] ?? '')) ?: xl('Patient');
                        $providerName = trim((string)($appointment['provider_fname'] ?? '') . ' ' . (string)($appointment['provider_lname'] ?? '')) ?: xl('Provider');
                        $status = medsov_provider_appt_status($appointment);
                        $launchUrl = $GLOBALS['webroot']
                            . '/interface/modules/custom_modules/' . Bootstrap::MODULE_DIRECTORY . '/templates/launch.php?'
                            . $siteQuery
                            . '&eid=' . urlencode((string)$appointment['pc_eid'])
                            . '&sid=' . urlencode((string)$appointment['session_id'])
                            . '&room=' . urlencode((string)$appointment['meeting_room'])
                            . '&role=provider';
                        $editUrl = $GLOBALS['webroot']
                            . '/interface/main/calendar/add_edit_event.php?'
                            . 'eid=' . urlencode((string)$appointment['pc_eid'])
                            . '&' . $siteQuery;
                    ?>
                        <tr>
                            <td>
                                <?php echo text(medsov_provider_appt_datetime($appointment)); ?>
                                <div class="medsov-muted">#<?php echo text((string)$appointment['pc_eid']); ?></div>
                            </td>
                            <td>
                                <?php echo text($patientName); ?>
                                <div class="medsov-muted">PID <?php echo text((string)($appointment['pc_pid'] ?? '-')); ?></div>
                            </td>
                            <td><?php echo text($providerName); ?></td>
                            <td>
                                <?php echo text((string)$appointment['pc_title']); ?>
                                <div class="medsov-muted"><?php echo text((string)$appointment['pc_catname']); ?></div>
                            </td>
                            <td>
                                <span class="medsov-status-pill<?php echo attr($status['class']); ?>">
                                    <i class="fa fa-circle" aria-hidden="true"></i>
                                    <?php echo text($status['label']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="medsov-action-row">
                                    <a class="btn btn-sm medsov-primary" href="<?php echo attr($launchUrl); ?>" target="_blank" rel="noopener">
                                        <i class="fa fa-video-camera" aria-hidden="true"></i>
                                        <?php echo xlt('Start'); ?>
                                    </a>
                                    <a class="btn btn-sm medsov-secondary" href="<?php echo attr($editUrl); ?>">
                                        <i class="fa fa-pencil" aria-hidden="true"></i>
                                        <?php echo xlt('Open Appointment'); ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </section>
</main>
</body>
</html>
