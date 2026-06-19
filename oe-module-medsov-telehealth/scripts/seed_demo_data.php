<?php

/**
 * Local Docker development seed data for Medsov Telehealth demos.
 *
 * Creates dummy patients, Medsov Telehealth appointments, and session rows.
 * This script is for local development only.
 */

if (PHP_SAPI === 'cli') {
    $ignoreAuth = true;
    $_GET['site'] = getenv('OPENEMR_SITE') ?: 'default';
}

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';
require_once __DIR__ . '/../src/Services/MeetingRoomService.php';

use OpenEMR\Common\Auth\AuthHash;
use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;

$providerId = 1;
$facilityId = 3;
$category = sqlQuery(
    "SELECT `pc_catid` FROM `openemr_postcalendar_categories` WHERE `pc_constant_id` = ?",
    [MeetingRoomService::TELEHEALTH_CATEGORY_CONSTANT]
);

if (empty($category['pc_catid'])) {
    throw new RuntimeException('Medsov Telehealth appointment category not found. Run dev_install.php first.');
}

$categoryId = (int)$category['pc_catid'];
$patients = [
    [
        'pubpid' => 'MEDSOV-DEMO-001',
        'fname' => 'Amina',
        'lname' => 'Johnson',
        'dob' => '1988-04-12',
        'sex' => 'Female',
        'email' => 'amina.demo@example.com',
        'phone' => '555-0101',
        'start' => '15:00:00',
        'portal_login' => 'amina.demo',
        'portal_password' => 'MedsovDemo!1',
    ],
    [
        'pubpid' => 'MEDSOV-DEMO-002',
        'fname' => 'Marcus',
        'lname' => 'Williams',
        'dob' => '1976-09-23',
        'sex' => 'Male',
        'email' => 'marcus.demo@example.com',
        'phone' => '555-0102',
        'start' => '15:30:00',
        'portal_login' => 'marcus.demo',
        'portal_password' => 'MedsovDemo!1',
    ],
    [
        'pubpid' => 'MEDSOV-DEMO-003',
        'fname' => 'Grace',
        'lname' => 'Mensah',
        'dob' => '1992-01-30',
        'sex' => 'Female',
        'email' => 'grace.demo@example.com',
        'phone' => '555-0103',
        'start' => '16:00:00',
        'portal_login' => 'grace.demo',
        'portal_password' => 'MedsovDemo!1',
    ],
];

$meetingService = new MeetingRoomService();
$created = [];
$eventDate = getenv('MEDSOV_DEMO_APPOINTMENT_DATE') ?: date('Y-m-d');

foreach ($patients as $patient) {
    $pid = ensurePatient($patient, $providerId);
    ensurePortalAccess($patient, $pid);
    $appointmentId = ensureAppointment($patient, $pid, $providerId, $categoryId, $eventDate, $facilityId);
    $session = $meetingService->createOrGetSessionForAppointment($appointmentId);

    $created[] = [
        'pid' => $pid,
        'name' => $patient['fname'] . ' ' . $patient['lname'],
        'appointment_id' => $appointmentId,
        'appointment_time' => $eventDate . ' ' . $patient['start'],
        'portal_login' => $patient['portal_login'],
        'portal_password' => $patient['portal_password'],
        'session_id' => $session['id'],
        'meeting_room' => $session['meeting_room'],
    ];
}

function ensurePortalAccess(array $patient, int $pid): void
{
    $hash = (new AuthHash('auth'))->passwordHash($patient['portal_password']);
    if (empty($hash)) {
        throw new RuntimeException('Unable to hash demo patient portal password.');
    }

    sqlStatementNoLog(
        "INSERT INTO `patient_access_onsite` (
            `pid`, `portal_username`, `portal_login_username`, `portal_pwd`, `portal_pwd_status`, `portal_onetime`
        ) VALUES (
            ?, ?, ?, ?, 1, NULL
        ) ON DUPLICATE KEY UPDATE
            `portal_username` = VALUES(`portal_username`),
            `portal_login_username` = VALUES(`portal_login_username`),
            `portal_pwd` = VALUES(`portal_pwd`),
            `portal_pwd_status` = 1,
            `portal_onetime` = NULL",
        [
            $pid,
            $patient['portal_login'],
            $patient['portal_login'],
            $hash,
        ]
    );
}

echo json_encode($created, JSON_PRETTY_PRINT) . PHP_EOL;

function ensurePatient(array $patient, int $providerId): int
{
    $existing = sqlQuery(
        "SELECT `pid` FROM `patient_data` WHERE `pubpid` = ?",
        [$patient['pubpid']]
    );
    if (!empty($existing['pid'])) {
        return (int)$existing['pid'];
    }

    $row = sqlQuery("SELECT COALESCE(MAX(`pid`), 0) + 1 AS `next_pid` FROM `patient_data`");
    $pid = (int)$row['next_pid'];

    sqlInsert(
        "INSERT INTO `patient_data` (
            `uuid`, `pid`, `pubpid`, `fname`, `mname`, `lname`, `DOB`, `sex`,
            `date`, `regdate`, `providerID`, `email`, `phone_cell`, `street`,
            `city`, `state`, `postal_code`, `country_code`, `status`,
            `hipaa_mail`, `hipaa_voice`, `hipaa_notice`, `hipaa_message`,
            `hipaa_allowsms`, `hipaa_allowemail`, `allow_patient_portal`,
            `created_by`, `updated_by`
        ) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')), ?, ?, ?, '', ?, ?, ?,
            NOW(), NOW(), ?, ?, ?, '100 Demo Street',
            'Demo City', 'NY', '10001', 'USA', 'active',
            'YES', 'YES', 'YES', 'YES',
            'YES', 'YES', 'YES',
            ?, ?
        )",
        [
            $pid,
            $patient['pubpid'],
            $patient['fname'],
            $patient['lname'],
            $patient['dob'],
            $patient['sex'],
            $providerId,
            $patient['email'],
            $patient['phone'],
            $providerId,
            $providerId,
        ]
    );

    return $pid;
}

function ensureAppointment(array $patient, int $pid, int $providerId, int $categoryId, string $eventDate, int $facilityId): int
{
    $title = 'Medsov Demo Telehealth - ' . $patient['fname'] . ' ' . $patient['lname'];
    $start = $patient['start'];
    $end = date('H:i:s', strtotime($start . ' +30 minutes'));
    $recurrspec = serialize([
        'event_repeat_freq' => '0',
        'event_repeat_freq_type' => '0',
        'event_repeat_on_num' => '1',
        'event_repeat_on_day' => '0',
        'event_repeat_on_freq' => '0',
    ]);

    $existing = sqlQuery(
        "SELECT `pc_eid` FROM `openemr_postcalendar_events` WHERE `pc_pid` = ? AND `pc_title` = ? ORDER BY `pc_eid` DESC LIMIT 1",
        [$pid, $title]
    );
    if (!empty($existing['pc_eid'])) {
        $appointmentId = (int)$existing['pc_eid'];
        sqlStatement(
            "UPDATE `openemr_postcalendar_events` SET
                `pc_catid` = ?,
                `pc_multiple` = 0,
                `pc_aid` = ?,
                `pc_pid` = ?,
                `pc_title` = ?,
                `pc_hometext` = 'Demo telehealth appointment created by Medsov seed script.',
                `pc_informant` = ?,
                `pc_eventDate` = ?,
                `pc_endDate` = '0000-00-00',
                `pc_duration` = 1800,
                `pc_recurrtype` = 0,
                `pc_recurrspec` = ?,
                `pc_recurrfreq` = 0,
                `pc_startTime` = ?,
                `pc_endTime` = ?,
                `pc_alldayevent` = 0,
                `pc_apptstatus` = '-',
                `pc_eventstatus` = 1,
                `pc_sharing` = 1,
                `pc_prefcatid` = 0,
                `pc_facility` = ?,
                `pc_billing_location` = ?,
                `pc_room` = ''
            WHERE `pc_eid` = ?",
            [
                $categoryId,
                $providerId,
                $pid,
                $title,
                $providerId,
                $eventDate,
                $recurrspec,
                $start,
                $end,
                $facilityId,
                $facilityId,
                $appointmentId,
            ]
        );

        return $appointmentId;
    }

    return (int)sqlInsert(
        "INSERT INTO `openemr_postcalendar_events` (
            `uuid`, `pc_catid`, `pc_multiple`, `pc_aid`, `pc_pid`, `pc_title`,
            `pc_time`, `pc_hometext`, `pc_informant`, `pc_eventDate`, `pc_endDate`,
            `pc_duration`, `pc_recurrtype`, `pc_recurrspec`, `pc_recurrfreq`,
            `pc_startTime`, `pc_endTime`, `pc_alldayevent`, `pc_apptstatus`,
            `pc_eventstatus`, `pc_sharing`, `pc_prefcatid`, `pc_facility`,
            `pc_billing_location`, `pc_room`
        ) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')), ?, 0, ?, ?, ?,
            NOW(), 'Demo telehealth appointment created by Medsov seed script.', ?,
            ?, '0000-00-00', 1800, 0, ?, 0,
            ?, ?, 0, '-', 1, 1, 0, ?, ?, ''
        )",
        [
            $categoryId,
            $providerId,
            $pid,
            $title,
            $providerId,
            $eventDate,
            $recurrspec,
            $start,
            $end,
            $facilityId,
            $facilityId,
        ]
    );
}
