<?php

/**
 * Admin audit log page for Medsov Telehealth.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl('Must be an Admin')]);
    exit;
}

function medsov_audit_filter(string $key): string
{
    return trim((string)($_GET[$key] ?? ''));
}

function medsov_audit_date_filter(string $key): string
{
    $value = medsov_audit_filter($key);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function medsov_audit_fetch_distinct(string $column, string $table): array
{
    $values = [];
    $statement = sqlStatement(
        "SELECT DISTINCT `$column` AS `value` FROM `$table` WHERE `$column` IS NOT NULL AND `$column` <> '' ORDER BY `$column`"
    );

    while ($row = sqlFetchArray($statement)) {
        $values[] = (string)$row['value'];
    }

    return $values;
}

function medsov_audit_event_label(string $eventType): string
{
    return ucwords(str_replace('_', ' ', $eventType));
}

function medsov_audit_badge_class(string $eventType): string
{
    if (strpos($eventType, 'failed') !== false) {
        return ' is-error';
    }

    if (strpos($eventType, 'cancel') !== false) {
        return ' is-warning';
    }

    if (strpos($eventType, 'admitted') !== false || strpos($eventType, 'joined') !== false) {
        return ' is-success';
    }

    if (strpos($eventType, 'email') !== false || strpos($eventType, 'notified') !== false) {
        return ' is-info';
    }

    return '';
}

function medsov_audit_name(?string $first, ?string $last, string $fallback): string
{
    $name = trim((string)$first . ' ' . (string)$last);

    return $name !== '' ? $name : $fallback;
}

function medsov_audit_actor(array $row): string
{
    $actorType = (string)($row['actor_type'] ?? '');
    $actorId = (string)($row['actor_id'] ?? '');

    if ($actorType === 'system') {
        return xl('System');
    }

    if ($actorType === 'user') {
        $name = medsov_audit_name($row['actor_fname'] ?? null, $row['actor_lname'] ?? null, (string)($row['actor_username'] ?? ''));
        return trim($name . ($actorId !== '' ? ' #' . $actorId : '')) ?: xl('User');
    }

    if ($actorType === 'patient') {
        $name = medsov_audit_name($row['actor_patient_fname'] ?? null, $row['actor_patient_lname'] ?? null, xl('Patient'));
        return trim($name . ($actorId !== '' ? ' #' . $actorId : ''));
    }

    return trim($actorType . ($actorId !== '' ? ' #' . $actorId : '')) ?: xl('Unknown');
}

function medsov_audit_metadata(?string $metadataJson): string
{
    $metadataJson = trim((string)$metadataJson);
    if ($metadataJson === '') {
        return '';
    }

    $decoded = json_decode($metadataJson, true);
    if (!is_array($decoded)) {
        return $metadataJson;
    }

    $lines = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        }

        $lines[] = $key . ': ' . (string)$value;
    }

    return implode("\n", $lines);
}

function medsov_audit_datetime(?string $dateTime): string
{
    $timestamp = strtotime((string)$dateTime);

    return $timestamp ? date('M j, Y g:i A', $timestamp) : (string)$dateTime;
}

function medsov_audit_appointment_time(?string $date, ?string $time): string
{
    $timestamp = strtotime(trim((string)$date . ' ' . substr((string)$time, 0, 5)));

    return $timestamp ? date('M j, Y g:i A', $timestamp) : trim((string)$date . ' ' . (string)$time);
}

$siteId = $_GET['site'] ?? $_SESSION['site_id'] ?? 'default';
$eventType = medsov_audit_filter('event_type');
$actorType = medsov_audit_filter('actor_type');
$status = medsov_audit_filter('status');
$fromDate = medsov_audit_date_filter('from_date');
$toDate = medsov_audit_date_filter('to_date');
$patient = medsov_audit_filter('patient');
$provider = medsov_audit_filter('provider');
$sessionId = medsov_audit_filter('session_id');
$appointmentId = medsov_audit_filter('appointment_id');
$limit = min(500, max(25, (int)($_GET['limit'] ?? 100)));

$where = ['1 = 1'];
$params = [];

if ($eventType !== '') {
    $where[] = 'a.`event_type` = ?';
    $params[] = $eventType;
}

if ($actorType !== '') {
    $where[] = 'a.`actor_type` = ?';
    $params[] = $actorType;
}

if ($status !== '') {
    $where[] = 's.`status` = ?';
    $params[] = $status;
}

if ($fromDate !== '') {
    $where[] = 'a.`created_at` >= ?';
    $params[] = $fromDate . ' 00:00:00';
}

if ($toDate !== '') {
    $where[] = 'a.`created_at` <= ?';
    $params[] = $toDate . ' 23:59:59';
}

if ($patient !== '') {
    $where[] = "(p.`fname` LIKE ? OR p.`lname` LIKE ? OR CAST(p.`pid` AS CHAR) = ?)";
    $params[] = '%' . $patient . '%';
    $params[] = '%' . $patient . '%';
    $params[] = $patient;
}

if ($provider !== '') {
    $where[] = "(u.`fname` LIKE ? OR u.`lname` LIKE ? OR CAST(u.`id` AS CHAR) = ?)";
    $params[] = '%' . $provider . '%';
    $params[] = '%' . $provider . '%';
    $params[] = $provider;
}

if ($sessionId !== '' && ctype_digit($sessionId)) {
    $where[] = 'a.`session_id` = ?';
    $params[] = (int)$sessionId;
}

if ($appointmentId !== '' && ctype_digit($appointmentId)) {
    $where[] = 's.`pc_eid` = ?';
    $params[] = (int)$appointmentId;
}

$eventTypes = medsov_audit_fetch_distinct('event_type', 'medsov_telehealth_audit');
$actorTypes = medsov_audit_fetch_distinct('actor_type', 'medsov_telehealth_audit');
$statuses = medsov_audit_fetch_distinct('status', 'medsov_telehealth_sessions');
$rows = [];

$statement = sqlStatement(
    "SELECT
        a.`id` AS `audit_id`,
        a.`session_id`,
        a.`event_type`,
        a.`actor_type`,
        a.`actor_id`,
        a.`ip_address`,
        a.`user_agent`,
        a.`metadata_json`,
        a.`created_at` AS `audit_created_at`,
        s.`meeting_room`,
        s.`status` AS `session_status`,
        s.`pc_eid`,
        s.`pid`,
        s.`provider_id`,
        e.`pc_title`,
        e.`pc_eventDate`,
        e.`pc_startTime`,
        e.`pc_apptstatus`,
        p.`fname` AS `patient_fname`,
        p.`lname` AS `patient_lname`,
        u.`fname` AS `provider_fname`,
        u.`lname` AS `provider_lname`,
        au.`username` AS `actor_username`,
        au.`fname` AS `actor_fname`,
        au.`lname` AS `actor_lname`,
        ap.`fname` AS `actor_patient_fname`,
        ap.`lname` AS `actor_patient_lname`
    FROM `medsov_telehealth_audit` a
    LEFT JOIN `medsov_telehealth_sessions` s ON s.`id` = a.`session_id`
    LEFT JOIN `openemr_postcalendar_events` e ON e.`pc_eid` = s.`pc_eid`
    LEFT JOIN `patient_data` p ON p.`pid` = s.`pid`
    LEFT JOIN `users` u ON u.`id` = s.`provider_id`
    LEFT JOIN `users` au ON au.`id` = a.`actor_id` AND a.`actor_type` = 'user'
    LEFT JOIN `patient_data` ap ON ap.`pid` = a.`actor_id` AND a.`actor_type` = 'patient'
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.`created_at` DESC, a.`id` DESC
    LIMIT " . escape_limit($limit),
    $params
);

while ($row = sqlFetchArray($statement)) {
    $rows[] = $row;
}

$clearUrl = 'audit_log.php?site=' . urlencode((string)$siteId);

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Medsov Telehealth Audit Log'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        body {
            background: #f7f7f8;
            color: #231f20;
        }
        .medsov-audit {
            max-width: 1280px;
            margin: 1rem auto 2rem;
            padding: 0 1rem;
        }
        .medsov-audit-hero,
        .medsov-audit-panel {
            border: 1px solid #ead7d9;
            border-radius: .5rem;
            background: #fff;
            box-shadow: 0 .5rem 1.25rem rgba(35, 31, 32, .06);
        }
        .medsov-audit-hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1.25rem;
        }
        .medsov-audit-brand {
            display: flex;
            align-items: center;
            gap: .75rem;
            min-width: 0;
        }
        .medsov-audit-mark {
            width: 2rem;
            height: 2rem;
            border-radius: .375rem;
            background: linear-gradient(90deg, #f4212e 0 45%, transparent 45% 55%, #f4212e 55% 100%);
            flex: 0 0 auto;
        }
        .medsov-audit-eyebrow {
            color: #f4212e;
            font-size: .75rem;
            font-weight: 800;
            line-height: 1;
            text-transform: uppercase;
        }
        .medsov-audit-title {
            margin: .25rem 0 0;
            color: #231f20;
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.2;
        }
        .medsov-audit-subtitle {
            margin: .375rem 0 0;
            color: #5d5557;
        }
        .medsov-audit-count {
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
        .medsov-audit-panel {
            overflow: hidden;
            margin-bottom: 1rem;
        }
        .medsov-audit-panel-head {
            padding: 1rem 1.25rem;
            border-left: .375rem solid #f4212e;
            border-bottom: 1px solid #ead7d9;
            background: #fffafa;
        }
        .medsov-audit-panel-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
        }
        .medsov-audit-panel-body {
            padding: 1.25rem;
        }
        .medsov-audit-filters {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .875rem;
        }
        .medsov-audit-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            margin-top: 1rem;
        }
        .medsov-audit-primary,
        .medsov-audit-primary:hover,
        .medsov-audit-primary:focus {
            border-color: #f4212e;
            background: #f4212e;
            color: #fff;
            font-weight: 800;
        }
        .medsov-audit-secondary,
        .medsov-audit-secondary:hover,
        .medsov-audit-secondary:focus {
            border-color: #231f20;
            background: #fff;
            color: #231f20;
            font-weight: 800;
        }
        .medsov-audit-table-wrap {
            overflow: auto;
        }
        .medsov-audit-table {
            min-width: 1180px;
            margin: 0;
        }
        .medsov-audit-table th {
            background: #fffafa;
            border-top: 0;
            color: #231f20;
            font-size: .8125rem;
            text-transform: uppercase;
        }
        .medsov-audit-table td {
            vertical-align: top;
            font-size: .875rem;
        }
        .medsov-audit-muted {
            color: #6b6264;
            font-size: .8rem;
        }
        .medsov-audit-event {
            display: inline-flex;
            align-items: center;
            max-width: 16rem;
            padding: .25rem .5rem;
            border: 1px solid #ead7d9;
            border-radius: 999px;
            background: #fbfafb;
            color: #231f20;
            font-size: .8rem;
            font-weight: 800;
            line-height: 1.2;
            white-space: normal;
        }
        .medsov-audit-event.is-error {
            border-color: #f7c3c8;
            background: #fff1f2;
            color: #a70d18;
        }
        .medsov-audit-event.is-warning {
            border-color: #f3d2a8;
            background: #fff8ed;
            color: #855400;
        }
        .medsov-audit-event.is-success {
            border-color: #b8e2c0;
            background: #eefaf0;
            color: #1f7a3a;
        }
        .medsov-audit-event.is-info {
            border-color: #b8d8f0;
            background: #f0f7ff;
            color: #245c8f;
        }
        .medsov-audit-metadata summary {
            color: #f4212e;
            cursor: pointer;
            font-weight: 800;
        }
        .medsov-audit-metadata pre {
            max-width: 22rem;
            max-height: 12rem;
            margin: .5rem 0 0;
            padding: .75rem;
            overflow: auto;
            border: 1px solid #ece4e5;
            border-radius: .375rem;
            background: #fbfafb;
            color: #231f20;
            white-space: pre-wrap;
        }
        @media (max-width: 1000px) {
            .medsov-audit-filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 640px) {
            .medsov-audit-hero {
                align-items: flex-start;
                flex-direction: column;
            }
            .medsov-audit-filters {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="body_top">
<main class="medsov-audit">
    <section class="medsov-audit-hero">
        <div class="medsov-audit-brand">
            <span class="medsov-audit-mark" aria-hidden="true"></span>
            <div>
                <div class="medsov-audit-eyebrow"><?php echo xlt('Medsov Telehealth'); ?></div>
                <h1 class="medsov-audit-title"><?php echo xlt('Audit Log'); ?></h1>
                <p class="medsov-audit-subtitle"><?php echo xlt('Review session activity, waiting-room actions, access events, and notification delivery history.'); ?></p>
            </div>
        </div>
        <div class="medsov-audit-count">
            <i class="fa fa-list-alt" aria-hidden="true"></i>
            <?php echo sprintf(xlt('%s records shown'), text((string)count($rows))); ?>
        </div>
    </section>

    <section class="medsov-audit-panel">
        <div class="medsov-audit-panel-head">
            <h2 class="medsov-audit-panel-title"><?php echo xlt('Filters'); ?></h2>
        </div>
        <div class="medsov-audit-panel-body">
            <form method="get" action="">
                <input type="hidden" name="site" value="<?php echo attr((string)$siteId); ?>">
                <div class="medsov-audit-filters">
                    <div class="form-group">
                        <label for="event_type"><?php echo xlt('Event Type'); ?></label>
                        <select class="form-control" id="event_type" name="event_type">
                            <option value=""><?php echo xlt('All Events'); ?></option>
                            <?php foreach ($eventTypes as $option) { ?>
                                <option value="<?php echo attr($option); ?>"<?php echo $eventType === $option ? ' selected' : ''; ?>>
                                    <?php echo text(medsov_audit_event_label($option)); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="actor_type"><?php echo xlt('Actor Type'); ?></label>
                        <select class="form-control" id="actor_type" name="actor_type">
                            <option value=""><?php echo xlt('All Actors'); ?></option>
                            <?php foreach ($actorTypes as $option) { ?>
                                <option value="<?php echo attr($option); ?>"<?php echo $actorType === $option ? ' selected' : ''; ?>>
                                    <?php echo text(ucwords($option)); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status"><?php echo xlt('Session Status'); ?></label>
                        <select class="form-control" id="status" name="status">
                            <option value=""><?php echo xlt('All Statuses'); ?></option>
                            <?php foreach ($statuses as $option) { ?>
                                <option value="<?php echo attr($option); ?>"<?php echo $status === $option ? ' selected' : ''; ?>>
                                    <?php echo text(ucwords(str_replace('_', ' ', $option))); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="limit"><?php echo xlt('Limit'); ?></label>
                        <input class="form-control" id="limit" type="number" min="25" max="500" name="limit" value="<?php echo attr((string)$limit); ?>">
                    </div>
                    <div class="form-group">
                        <label for="from_date"><?php echo xlt('From Date'); ?></label>
                        <input class="form-control" id="from_date" type="date" name="from_date" value="<?php echo attr($fromDate); ?>">
                    </div>
                    <div class="form-group">
                        <label for="to_date"><?php echo xlt('To Date'); ?></label>
                        <input class="form-control" id="to_date" type="date" name="to_date" value="<?php echo attr($toDate); ?>">
                    </div>
                    <div class="form-group">
                        <label for="patient"><?php echo xlt('Patient'); ?></label>
                        <input class="form-control" id="patient" name="patient" value="<?php echo attr($patient); ?>" placeholder="<?php echo xla('Name or PID'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="provider"><?php echo xlt('Provider'); ?></label>
                        <input class="form-control" id="provider" name="provider" value="<?php echo attr($provider); ?>" placeholder="<?php echo xla('Name or user ID'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="session_id"><?php echo xlt('Session ID'); ?></label>
                        <input class="form-control" id="session_id" name="session_id" value="<?php echo attr($sessionId); ?>">
                    </div>
                    <div class="form-group">
                        <label for="appointment_id"><?php echo xlt('Appointment ID'); ?></label>
                        <input class="form-control" id="appointment_id" name="appointment_id" value="<?php echo attr($appointmentId); ?>">
                    </div>
                </div>
                <div class="medsov-audit-actions">
                    <button class="btn medsov-audit-primary" type="submit">
                        <i class="fa fa-filter" aria-hidden="true"></i>
                        <?php echo xlt('Apply Filters'); ?>
                    </button>
                    <a class="btn medsov-audit-secondary" href="<?php echo attr($clearUrl); ?>">
                        <i class="fa fa-times" aria-hidden="true"></i>
                        <?php echo xlt('Clear Filters'); ?>
                    </a>
                </div>
            </form>
        </div>
    </section>

    <section class="medsov-audit-panel">
        <div class="medsov-audit-panel-head">
            <h2 class="medsov-audit-panel-title"><?php echo xlt('Audit Events'); ?></h2>
        </div>
        <div class="medsov-audit-table-wrap">
            <?php if (empty($rows)) { ?>
                <div class="alert alert-info m-3"><?php echo xlt('No Medsov Telehealth audit events matched the current filters.'); ?></div>
            <?php } else { ?>
                <table class="table table-sm table-striped medsov-audit-table">
                    <thead>
                    <tr>
                        <th><?php echo xlt('Date / Time'); ?></th>
                        <th><?php echo xlt('Event'); ?></th>
                        <th><?php echo xlt('Session'); ?></th>
                        <th><?php echo xlt('Appointment'); ?></th>
                        <th><?php echo xlt('Patient'); ?></th>
                        <th><?php echo xlt('Provider'); ?></th>
                        <th><?php echo xlt('Actor'); ?></th>
                        <th><?php echo xlt('IP Address'); ?></th>
                        <th><?php echo xlt('Metadata'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) {
                        $metadata = medsov_audit_metadata($row['metadata_json'] ?? null);
                        $patientName = medsov_audit_name($row['patient_fname'] ?? null, $row['patient_lname'] ?? null, '-');
                        $providerName = medsov_audit_name($row['provider_fname'] ?? null, $row['provider_lname'] ?? null, '-');
                        ?>
                        <tr>
                            <td>
                                <?php echo text(medsov_audit_datetime($row['audit_created_at'] ?? '')); ?>
                                <div class="medsov-audit-muted">#<?php echo text((string)$row['audit_id']); ?></div>
                            </td>
                            <td>
                                <span class="medsov-audit-event<?php echo attr(medsov_audit_badge_class((string)$row['event_type'])); ?>">
                                    <?php echo text(medsov_audit_event_label((string)$row['event_type'])); ?>
                                </span>
                            </td>
                            <td>
                                #<?php echo text((string)($row['session_id'] ?? '-')); ?>
                                <div class="medsov-audit-muted"><?php echo text((string)($row['session_status'] ?? '-')); ?></div>
                                <div class="medsov-audit-muted"><?php echo text((string)($row['meeting_room'] ?? '-')); ?></div>
                            </td>
                            <td>
                                #<?php echo text((string)($row['pc_eid'] ?? '-')); ?>
                                <div><?php echo text((string)($row['pc_title'] ?? '-')); ?></div>
                                <div class="medsov-audit-muted"><?php echo text(medsov_audit_appointment_time($row['pc_eventDate'] ?? null, $row['pc_startTime'] ?? null)); ?></div>
                            </td>
                            <td>
                                <?php echo text($patientName); ?>
                                <div class="medsov-audit-muted">PID <?php echo text((string)($row['pid'] ?? '-')); ?></div>
                            </td>
                            <td>
                                <?php echo text($providerName); ?>
                                <div class="medsov-audit-muted">#<?php echo text((string)($row['provider_id'] ?? '-')); ?></div>
                            </td>
                            <td><?php echo text(medsov_audit_actor($row)); ?></td>
                            <td><?php echo text((string)($row['ip_address'] ?? '-')); ?></td>
                            <td>
                                <?php if ($metadata !== '') { ?>
                                    <details class="medsov-audit-metadata">
                                        <summary><?php echo xlt('View'); ?></summary>
                                        <pre><?php echo text($metadata); ?></pre>
                                    </details>
                                <?php } else { ?>
                                    <span class="medsov-audit-muted">-</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
    </section>
</main>
</body>
</html>
