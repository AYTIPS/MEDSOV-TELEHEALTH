<?php

/**
 * Meeting room/session helpers for Medsov Telehealth.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\MedsovTelehealth\Services;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\MedsovTelehealth\MedsovTelehealthGlobalConfig;

class MeetingRoomService
{
    public const TELEHEALTH_CATEGORY_CONSTANT = 'medsov_telehealth';
    public const SESSION_STATUS_CANCELLED = 'cancelled';
    public const CANCELLED_APPOINTMENT_STATUSES = ['x', '%'];
    private const PARTICIPANT_STALE_MINUTES = 20;

    public function createAdHocSession(): array
    {
        $uuid = $this->uuidV4();
        $room = 'medsov-' . str_replace('-', '', $uuid);

        $sessionId = sqlInsert(
            "INSERT INTO `medsov_telehealth_sessions` (`uuid`, `meeting_room`, `status`, `provider_id`) VALUES (?, ?, ?, ?)",
            [$uuid, $room, 'created', $_SESSION['authUserID'] ?? null]
        );

        $this->audit($sessionId, 'meeting_created', 'user', $_SESSION['authUserID'] ?? null, [
            'meeting_room' => $room,
            'source' => 'ad_hoc_test_room',
        ]);

        return [
            'id' => $sessionId,
            'uuid' => $uuid,
            'meeting_room' => $room,
        ];
    }

    public function createOrGetSessionForAppointment(
        int $appointmentId,
        ?array $appointment = null,
        string $actorType = 'user',
        mixed $actorId = null
    ): array
    {
        $existing = $this->getSessionForAppointment($appointmentId);
        if ($existing) {
            if ($appointment) {
                $pid = !empty($appointment['pc_pid']) ? (int)$appointment['pc_pid'] : null;
                $providerId = !empty($appointment['pc_aid']) ? (int)$appointment['pc_aid'] : null;
                $reactivateCancelledSession = !$this->isAppointmentCancelled($appointment)
                    && (($existing['status'] ?? '') === self::SESSION_STATUS_CANCELLED);

                sqlQuery(
                    "UPDATE `medsov_telehealth_sessions`
                        SET `pid` = ?,
                            `provider_id` = ?"
                            . ($reactivateCancelledSession ? ", `status` = ?, `ended_at` = NULL" : "")
                            . "
                        WHERE `id` = ?",
                    $reactivateCancelledSession
                        ? [$pid, $providerId, 'created', (int)$existing['id']]
                        : [$pid, $providerId, (int)$existing['id']]
                );
                $existing['pid'] = $pid;
                $existing['provider_id'] = $providerId;
                if ($reactivateCancelledSession) {
                    $existing['status'] = 'created';
                    $existing['ended_at'] = null;
                    $this->audit((int)$existing['id'], 'appointment_reactivated', $actorType, $actorId ?? ($_SESSION['authUserID'] ?? null));
                }
            }

            return $existing;
        }

        $appointment ??= $this->getAppointmentById($appointmentId);
        if (!$appointment) {
            throw new \RuntimeException('Appointment not found for telehealth session.');
        }

        $uuid = $this->uuidV4();
        $room = 'medsov-' . str_replace('-', '', $uuid);
        $pid = !empty($appointment['pc_pid']) ? (int)$appointment['pc_pid'] : null;
        $providerId = !empty($appointment['pc_aid']) ? (int)$appointment['pc_aid'] : null;

        $sessionId = sqlInsert(
            "INSERT INTO `medsov_telehealth_sessions` (`uuid`, `pc_eid`, `pid`, `encounter`, `provider_id`, `meeting_room`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$uuid, $appointmentId, $pid, null, $providerId, $room, 'created']
        );

        $this->audit($sessionId, 'meeting_created', $actorType, $actorId ?? ($_SESSION['authUserID'] ?? null), [
            'meeting_room' => $room,
            'source' => 'appointment',
            'pc_eid' => $appointmentId,
            'pid' => $pid,
            'provider_id' => $providerId,
        ]);

        return [
            'id' => $sessionId,
            'uuid' => $uuid,
            'pc_eid' => $appointmentId,
            'pid' => $pid,
            'provider_id' => $providerId,
            'meeting_room' => $room,
            'status' => 'created',
        ];
    }

    public function getUpcomingTelehealthAppointmentsForPatient(int $pid): array
    {
        $appointments = [];
        $statement = sqlStatement(
            "SELECT
                e.`pc_eid`,
                e.`pc_pid`,
                e.`pc_aid`,
                e.`pc_catid`,
                e.`pc_title`,
                e.`pc_eventDate`,
                e.`pc_startTime`,
                e.`pc_endTime`,
                e.`pc_apptstatus`,
                c.`pc_catname`,
                u.`fname` AS `provider_fname`,
                u.`lname` AS `provider_lname`
            FROM `openemr_postcalendar_events` e
            JOIN `openemr_postcalendar_categories` c ON c.`pc_catid` = e.`pc_catid`
            LEFT JOIN `users` u ON u.`id` = e.`pc_aid`
            WHERE e.`pc_pid` = ?
                AND e.`pc_eventstatus` = 1
                AND e.`pc_eventDate` >= CURDATE()
                AND e.`pc_apptstatus` NOT IN ('x', '%')
                AND c.`pc_constant_id` = ?
            ORDER BY e.`pc_eventDate`, e.`pc_startTime`, e.`pc_eid`",
            [$pid, self::TELEHEALTH_CATEGORY_CONSTANT]
        );

        while ($appointment = sqlFetchArray($statement)) {
            $session = $this->createOrGetSessionForAppointment((int)$appointment['pc_eid'], $appointment, 'patient', $pid);
            $appointment['session_id'] = $session['id'];
            $appointment['session_uuid'] = $session['uuid'];
            $appointment['meeting_room'] = $session['meeting_room'];
            $appointment['session_status'] = $session['status'] ?? 'created';
            if (($appointment['session_status'] ?? '') !== self::SESSION_STATUS_CANCELLED) {
                $appointments[] = $appointment;
            }
        }

        return $appointments;
    }

    public function getUpcomingTelehealthAppointmentsForProvider(?int $providerId, bool $includeAllProviders = false, int $limit = 100): array
    {
        if (empty($providerId) && !$includeAllProviders) {
            return [];
        }

        $appointments = [];
        $providerFilter = $includeAllProviders ? '' : 'AND e.`pc_aid` = ?';
        $params = $includeAllProviders
            ? [self::TELEHEALTH_CATEGORY_CONSTANT]
            : [$providerId, self::TELEHEALTH_CATEGORY_CONSTANT];

        $statement = sqlStatement(
            "SELECT
                e.`pc_eid`,
                e.`pc_pid`,
                e.`pc_aid`,
                e.`pc_catid`,
                e.`pc_title`,
                e.`pc_eventDate`,
                e.`pc_startTime`,
                e.`pc_endTime`,
                e.`pc_apptstatus`,
                c.`pc_catname`,
                p.`fname` AS `patient_fname`,
                p.`lname` AS `patient_lname`,
                u.`fname` AS `provider_fname`,
                u.`lname` AS `provider_lname`
            FROM `openemr_postcalendar_events` e
            JOIN `openemr_postcalendar_categories` c ON c.`pc_catid` = e.`pc_catid`
            LEFT JOIN `patient_data` p ON p.`pid` = e.`pc_pid`
            LEFT JOIN `users` u ON u.`id` = e.`pc_aid`
            WHERE e.`pc_eventstatus` = 1
                AND e.`pc_eventDate` >= CURDATE()
                AND e.`pc_apptstatus` NOT IN ('x', '%')
                $providerFilter
                AND c.`pc_constant_id` = ?
            ORDER BY e.`pc_eventDate`, e.`pc_startTime`, e.`pc_eid`
            LIMIT " . escape_limit($limit),
            $params
        );

        while ($appointment = sqlFetchArray($statement)) {
            $session = $this->createOrGetSessionForAppointment((int)$appointment['pc_eid'], $appointment);
            $appointment['session_id'] = $session['id'];
            $appointment['session_uuid'] = $session['uuid'];
            $appointment['meeting_room'] = $session['meeting_room'];
            $appointment['session_status'] = $session['status'] ?? 'created';
            $appointment['patient_waiting_at'] = $session['patient_waiting_at'] ?? null;
            $appointment['provider_joined_at'] = $session['provider_joined_at'] ?? null;
            $appointment['admitted_at'] = $session['admitted_at'] ?? null;
            $appointment['ended_at'] = $session['ended_at'] ?? null;
            if (($appointment['session_status'] ?? '') !== self::SESSION_STATUS_CANCELLED) {
                $appointments[] = $appointment;
            }
        }

        return $appointments;
    }

    public function getPortalSessionForPatient(int $appointmentId, string $token, int $pid, bool $includeCancelled = false): ?array
    {
        if (empty($appointmentId) || empty($token) || empty($pid)) {
            return null;
        }

        $availabilityFilter = $includeCancelled
            ? ''
            : "AND e.`pc_eventstatus` = 1
                AND e.`pc_eventDate` >= CURDATE()
                AND e.`pc_apptstatus` NOT IN ('x', '%')
                AND s.`status` <> ?";
        $params = $includeCancelled
            ? [$appointmentId, $token, $pid, self::TELEHEALTH_CATEGORY_CONSTANT]
            : [$appointmentId, $token, $pid, self::SESSION_STATUS_CANCELLED, self::TELEHEALTH_CATEGORY_CONSTANT];

        $row = sqlQuery(
            "SELECT
                s.*,
                e.`pc_eid`,
                e.`pc_pid`,
                e.`pc_aid`,
                e.`pc_catid`,
                e.`pc_title`,
                e.`pc_eventDate`,
                e.`pc_startTime`,
                e.`pc_endTime`,
                e.`pc_apptstatus`,
                c.`pc_catname`,
                u.`fname` AS `provider_fname`,
                u.`lname` AS `provider_lname`
            FROM `medsov_telehealth_sessions` s
            JOIN `openemr_postcalendar_events` e ON e.`pc_eid` = s.`pc_eid`
            JOIN `openemr_postcalendar_categories` c ON c.`pc_catid` = e.`pc_catid`
            LEFT JOIN `users` u ON u.`id` = e.`pc_aid`
            WHERE s.`pc_eid` = ?
                AND s.`uuid` = ?
                AND e.`pc_pid` = ?
                $availabilityFilter
                AND c.`pc_constant_id` = ?
            LIMIT 1",
            $params
        );

        return $row ?: null;
    }

    public function getSessionForAppointment(int $appointmentId): ?array
    {
        $row = sqlQuery(
            "SELECT * FROM `medsov_telehealth_sessions` WHERE `pc_eid` = ? ORDER BY `id` DESC LIMIT 1",
            [$appointmentId]
        );

        return $row ?: null;
    }

    public function getSessionByIdForAppointment(int $sessionId, int $appointmentId): ?array
    {
        if (empty($sessionId) || empty($appointmentId)) {
            return null;
        }

        $row = sqlQuery(
            "SELECT * FROM `medsov_telehealth_sessions` WHERE `id` = ? AND `pc_eid` = ? LIMIT 1",
            [$sessionId, $appointmentId]
        );

        return $row ?: null;
    }

    public function getWaitingSessionsForProvider(?int $providerId, int $limit = 10, bool $includeAllProviders = false): array
    {
        if (empty($providerId) && !$includeAllProviders) {
            return [];
        }

        $sessions = [];
        $providerFilter = $includeAllProviders ? '' : 'AND s.`provider_id` = ?';
        $params = $includeAllProviders ? [self::TELEHEALTH_CATEGORY_CONSTANT] : [$providerId, self::TELEHEALTH_CATEGORY_CONSTANT];
        $statement = sqlStatement(
            "SELECT
                s.`id`,
                s.`uuid`,
                s.`pc_eid`,
                s.`pid`,
                s.`provider_id`,
                s.`meeting_room`,
                s.`status`,
                s.`patient_waiting_at`,
                s.`provider_joined_at`,
                s.`admitted_at`,
                e.`pc_title`,
                e.`pc_eventDate`,
                e.`pc_startTime`,
                e.`pc_endTime`,
                e.`pc_apptstatus`,
                p.`fname` AS `patient_fname`,
                p.`lname` AS `patient_lname`,
                u.`fname` AS `provider_fname`,
                u.`lname` AS `provider_lname`
            FROM `medsov_telehealth_sessions` s
            JOIN `openemr_postcalendar_events` e ON e.`pc_eid` = s.`pc_eid`
            JOIN `openemr_postcalendar_categories` c ON c.`pc_catid` = e.`pc_catid`
            LEFT JOIN `patient_data` p ON p.`pid` = s.`pid`
            LEFT JOIN `users` u ON u.`id` = s.`provider_id`
            WHERE 1 = 1
                $providerFilter
                AND s.`patient_waiting_at` IS NOT NULL
                AND s.`admitted_at` IS NULL
                AND s.`status` <> 'cancelled'
                AND e.`pc_eventstatus` = 1
                AND e.`pc_eventDate` >= CURDATE()
                AND e.`pc_apptstatus` NOT IN ('x', '%')
                AND c.`pc_constant_id` = ?
            ORDER BY s.`patient_waiting_at` ASC
            LIMIT " . escape_limit($limit),
            $params
        );

        while ($row = sqlFetchArray($statement)) {
            $sessions[] = $row;
        }

        return $sessions;
    }

    public function getAppointmentById(int $appointmentId): ?array
    {
        $row = sqlQuery(
            "SELECT `pc_eid`, `pc_catid`, `pc_pid`, `pc_aid`, `pc_title`, `pc_eventDate`, `pc_startTime`, `pc_endTime`, `pc_apptstatus`, `pc_eventstatus`, `pc_facility`, `pc_billing_location` FROM `openemr_postcalendar_events` WHERE `pc_eid` = ?",
            [$appointmentId]
        );

        return $row ?: null;
    }

    public function createOrGetEncounterForSession(
        int $sessionId,
        ?array $appointment = null,
        string $actorType = 'user',
        mixed $actorId = null
    ): ?int {
        if (empty($sessionId)) {
            return null;
        }

        $session = sqlQuery(
            "SELECT `id`, `pc_eid`, `pid`, `encounter`, `provider_id`, `status`
                FROM `medsov_telehealth_sessions`
                WHERE `id` = ?
                LIMIT 1",
            [$sessionId]
        );
        if (!$session || ($session['status'] ?? '') === self::SESSION_STATUS_CANCELLED) {
            return null;
        }

        $pid = (int)($session['pid'] ?? 0);
        if (empty($pid)) {
            return null;
        }

        $existingEncounter = (int)($session['encounter'] ?? 0);
        if ($existingEncounter > 0 && $this->encounterExistsForPatient($pid, $existingEncounter)) {
            return $existingEncounter;
        }

        $appointmentId = (int)($session['pc_eid'] ?? 0);
        if (!$appointment && $appointmentId > 0) {
            $appointment = $this->getAppointmentById($appointmentId);
        }

        $providerId = (int)($appointment['pc_aid'] ?? ($session['provider_id'] ?? 0));
        $categoryId = (int)($appointment['pc_catid'] ?? 0);
        $encounterDate = $this->appointmentDateTime($appointment);
        $reason = trim((string)($appointment['pc_title'] ?? ''));
        $reason = $reason !== ''
            ? xl('Medsov Telehealth') . ' - ' . $reason
            : xl('Medsov Telehealth appointment');

        $currentUser = sqlQuery(
            "SELECT `username`, `facility`, `facility_id` FROM `users` WHERE `id` = ? LIMIT 1",
            [(int)($_SESSION['authUserID'] ?? 0)]
        ) ?: [];
        $facilityId = (int)($appointment['pc_facility'] ?? 0);
        if ($facilityId <= 0) {
            $facilityId = (int)($currentUser['facility_id'] ?? 0);
        }
        $billingFacilityId = (int)($appointment['pc_billing_location'] ?? 0);
        if ($billingFacilityId <= 0) {
            $billingFacilityId = $facilityId;
        }

        $facility = $facilityId > 0
            ? sqlQuery("SELECT `name`, `pos_code` FROM `facility` WHERE `id` = ? LIMIT 1", [$facilityId])
            : null;
        $facilityName = (string)($facility['name'] ?? ($currentUser['facility'] ?? ''));
        $posCode = $facility['pos_code'] ?? null;
        $username = (string)($currentUser['username'] ?? ($_SESSION['authUser'] ?? ''));

        require_once dirname(__DIR__, 6) . '/library/forms.inc.php';

        $encounter = QueryUtils::generateId();
        $formEncounterId = sqlInsert(
            "INSERT INTO `form_encounter` SET
                `date` = ?,
                `onset_date` = ?,
                `reason` = ?,
                `facility` = ?,
                `facility_id` = ?,
                `billing_facility` = ?,
                `provider_id` = ?,
                `pid` = ?,
                `encounter` = ?,
                `pc_catid` = ?,
                `pos_code` = ?",
            [
                $encounterDate,
                $encounterDate,
                $reason,
                $facilityName,
                $facilityId,
                $billingFacilityId,
                $providerId,
                $pid,
                $encounter,
                $categoryId,
                $posCode,
            ]
        );

        \addForm(
            $encounter,
            'Medsov Telehealth Encounter',
            $formEncounterId,
            'newpatient',
            $pid,
            '1',
            'NOW()',
            $username
        );

        sqlQuery(
            "UPDATE `medsov_telehealth_sessions`
                SET `encounter` = ?
                WHERE `id` = ?",
            [$encounter, $sessionId]
        );

        $this->audit($sessionId, 'encounter_linked', $actorType, $actorId ?? ($_SESSION['authUserID'] ?? null), [
            'encounter' => $encounter,
            'form_encounter_id' => $formEncounterId,
            'pid' => $pid,
            'provider_id' => $providerId,
            'pc_eid' => $appointmentId ?: null,
        ]);

        return (int)$encounter;
    }

    public function isAppointmentCancelled(array $appointment): bool
    {
        $status = (string)($appointment['pc_apptstatus'] ?? '');
        $eventStatus = isset($appointment['pc_eventstatus']) ? (int)$appointment['pc_eventstatus'] : 1;

        return $eventStatus === 0 || in_array($status, self::CANCELLED_APPOINTMENT_STATUSES, true);
    }

    public function cancelAppointmentSession(
        int $appointmentId,
        ?array $appointment = null,
        string $actorType = 'user',
        mixed $actorId = null
    ): ?array {
        $appointment ??= $this->getAppointmentById($appointmentId);
        if (!$appointment) {
            return null;
        }

        $session = $this->createOrGetSessionForAppointment($appointmentId, $appointment, $actorType, $actorId);
        $wasCancelled = (($session['status'] ?? '') === self::SESSION_STATUS_CANCELLED);

        sqlQuery(
            "UPDATE `medsov_telehealth_sessions`
                SET `status` = ?,
                    `patient_waiting_at` = NULL,
                    `admitted_at` = NULL,
                    `ended_at` = COALESCE(`ended_at`, NOW())
                WHERE `id` = ?",
            [self::SESSION_STATUS_CANCELLED, (int)$session['id']]
        );

        if (!$wasCancelled) {
            $this->audit((int)$session['id'], 'appointment_cancelled', $actorType, $actorId ?? ($_SESSION['authUserID'] ?? null), [
                'pc_eid' => $appointmentId,
                'pc_apptstatus' => (string)($appointment['pc_apptstatus'] ?? ''),
            ]);
        }

        return $this->getSessionByIdForAppointment((int)$session['id'], $appointmentId);
    }

    public function currentUserCanAdministerTelehealth(): bool
    {
        return AclMain::aclCheckCore('admin', 'super');
    }

    public function currentUserCanManageAppointment(array $appointment): bool
    {
        if ($this->currentUserCanAdministerTelehealth()) {
            return true;
        }

        $currentUserId = (int)($_SESSION['authUserID'] ?? 0);
        $appointmentProviderId = (int)($appointment['pc_aid'] ?? 0);

        return $currentUserId > 0
            && $appointmentProviderId > 0
            && $currentUserId === $appointmentProviderId;
    }

    public function currentUserCanManageSession(array $session, ?array $appointment = null): bool
    {
        if ($this->currentUserCanAdministerTelehealth()) {
            return true;
        }

        $currentUserId = (int)($_SESSION['authUserID'] ?? 0);
        $sessionProviderId = (int)($session['provider_id'] ?? 0);
        $appointmentProviderId = (int)($appointment['pc_aid'] ?? 0);

        return $currentUserId > 0
            && (
                ($sessionProviderId > 0 && $currentUserId === $sessionProviderId)
                || ($appointmentProviderId > 0 && $currentUserId === $appointmentProviderId)
            );
    }

    public function isTelehealthCategory(int $categoryId): bool
    {
        if (empty($categoryId)) {
            return false;
        }

        $row = sqlQuery(
            "SELECT `pc_catid` FROM `openemr_postcalendar_categories` WHERE `pc_catid` = ? AND `pc_constant_id` = ?",
            [$categoryId, self::TELEHEALTH_CATEGORY_CONSTANT]
        );

        return !empty($row);
    }

    public function markPatientWaiting(?int $sessionId, string $actorType = 'user', mixed $actorId = null): void
    {
        if (empty($sessionId)) {
            return;
        }

        $session = sqlQuery("SELECT `status` FROM `medsov_telehealth_sessions` WHERE `id` = ? LIMIT 1", [$sessionId]);
        if (($session['status'] ?? '') === self::SESSION_STATUS_CANCELLED) {
            return;
        }

        sqlQuery(
            "UPDATE `medsov_telehealth_sessions`
                SET `status` = CASE WHEN `admitted_at` IS NULL THEN ? ELSE `status` END,
                    `patient_waiting_at` = COALESCE(`patient_waiting_at`, NOW())
                WHERE `id` = ?",
            ['patient_waiting', $sessionId]
        );
        $this->audit($sessionId, 'patient_joined_waiting_room', $actorType, $actorId ?? ($_SESSION['authUserID'] ?? null));
        (new NotificationService())->notifyProviderPatientWaiting((int)$sessionId);
    }

    public function admitPatient(?int $sessionId, string $actorType = 'user', mixed $actorId = null): bool
    {
        if (empty($sessionId)) {
            return false;
        }

        $session = sqlQuery(
            "SELECT `status`, `patient_waiting_at`, `admitted_at` FROM `medsov_telehealth_sessions` WHERE `id` = ? LIMIT 1",
            [$sessionId]
        );
        if (!$session || ($session['status'] ?? '') === self::SESSION_STATUS_CANCELLED || empty($session['patient_waiting_at'])) {
            return false;
        }

        sqlQuery(
            "UPDATE `medsov_telehealth_sessions`
                SET `status` = ?,
                    `admitted_at` = COALESCE(`admitted_at`, NOW())
                WHERE `id` = ?",
            ['admitted', $sessionId]
        );

        if (empty($session['admitted_at'])) {
            $this->audit($sessionId, 'patient_admitted', $actorType, $actorId ?? ($_SESSION['authUserID'] ?? null));
        }

        return true;
    }

    public function markProviderJoined(?int $sessionId): void
    {
        if (empty($sessionId)) {
            return;
        }

        $session = sqlQuery(
            "SELECT `pc_eid`, `pid`, `status`, `provider_joined_at`, `patient_waiting_at`, `admitted_at`
                FROM `medsov_telehealth_sessions`
                WHERE `id` = ?
                LIMIT 1",
            [$sessionId]
        );
        if (!$session || ($session['status'] ?? '') === self::SESSION_STATUS_CANCELLED) {
            return;
        }

        $providerStartedFirst = $session
            && !empty($session['pc_eid'])
            && !empty($session['pid'])
            && empty($session['provider_joined_at'])
            && empty($session['patient_waiting_at'])
            && empty($session['admitted_at']);

        sqlQuery(
            "UPDATE `medsov_telehealth_sessions`
                SET `status` = CASE WHEN `admitted_at` IS NULL THEN ? ELSE ? END,
                    `provider_joined_at` = COALESCE(`provider_joined_at`, NOW())
                WHERE `id` = ?",
            ['provider_joined', 'in_session', $sessionId]
        );
        $this->audit($sessionId, 'provider_joined_session', 'user', $_SESSION['authUserID'] ?? null);

        if ($providerStartedFirst) {
            (new NotificationService())->notifyPatientProviderStarted((int)$sessionId);
        }
    }

    public function markPatientJoined(?int $sessionId, string $actorType = 'patient', mixed $actorId = null): void
    {
        if (empty($sessionId)) {
            return;
        }

        $session = sqlQuery("SELECT `status` FROM `medsov_telehealth_sessions` WHERE `id` = ? LIMIT 1", [$sessionId]);
        if (($session['status'] ?? '') === self::SESSION_STATUS_CANCELLED) {
            return;
        }

        sqlQuery(
            "UPDATE `medsov_telehealth_sessions` SET `status` = ? WHERE `id` = ?",
            ['in_session', $sessionId]
        );
        $this->audit($sessionId, 'patient_joined_session', $actorType, $actorId);
    }

    public function registerParticipantEntry(
        ?int $sessionId,
        string $participantType,
        mixed $participantId,
        string $displayName,
        int $maxParticipants
    ): array {
        if (empty($sessionId)) {
            return [
                'allowed' => true,
                'active_count' => 0,
                'max_participants' => max(1, $maxParticipants),
            ];
        }

        $session = sqlQuery(
            "SELECT `id`, `status` FROM `medsov_telehealth_sessions` WHERE `id` = ? LIMIT 1",
            [$sessionId]
        );
        if (!$session || ($session['status'] ?? '') === self::SESSION_STATUS_CANCELLED) {
            return [
                'allowed' => false,
                'reason' => 'session_unavailable',
                'active_count' => 0,
                'max_participants' => max(1, $maxParticipants),
            ];
        }

        $participantType = $this->normalizeParticipantType($participantType);
        $participantId = (int)$participantId;
        $maxParticipants = max(1, $maxParticipants);
        if ($participantId <= 0) {
            $this->audit($sessionId, 'participant_limit_rejected', $participantType, null, [
                'reason' => 'missing_participant_id',
                'max_participants' => $maxParticipants,
            ]);

            return [
                'allowed' => false,
                'reason' => 'missing_participant_id',
                'active_count' => $this->getActiveParticipantCount($sessionId),
                'max_participants' => $maxParticipants,
            ];
        }

        $this->expireStaleParticipants($sessionId);
        $existing = $this->getParticipant($sessionId, $participantType, $participantId);
        if ($existing && empty($existing['left_at'])) {
            sqlQuery(
                "UPDATE `medsov_telehealth_participants`
                    SET `display_name` = ?,
                        `last_seen_at` = NOW()
                    WHERE `id` = ?",
                [substr($displayName, 0, 190), (int)$existing['id']]
            );

            return [
                'allowed' => true,
                'participant_id' => (int)$existing['id'],
                'active_count' => $this->getActiveParticipantCount($sessionId),
                'max_participants' => $maxParticipants,
            ];
        }

        $activeCount = $this->getActiveParticipantCount($sessionId);
        if ($activeCount >= $maxParticipants) {
            $this->audit($sessionId, 'participant_limit_rejected', $participantType, $participantId, [
                'active_count' => $activeCount,
                'max_participants' => $maxParticipants,
            ]);

            return [
                'allowed' => false,
                'reason' => 'participant_limit_reached',
                'active_count' => $activeCount,
                'max_participants' => $maxParticipants,
            ];
        }

        if ($existing) {
            $participantRowId = (int)$existing['id'];
            sqlQuery(
                "UPDATE `medsov_telehealth_participants`
                    SET `display_name` = ?,
                        `joined_at` = NOW(),
                        `last_seen_at` = NOW(),
                        `left_at` = NULL
                    WHERE `id` = ?",
                [substr($displayName, 0, 190), $participantRowId]
            );
        } else {
            $participantRowId = sqlInsert(
                "INSERT INTO `medsov_telehealth_participants`
                    (`session_id`, `participant_type`, `participant_id`, `display_name`, `joined_at`, `last_seen_at`)
                    VALUES (?, ?, ?, ?, NOW(), NOW())",
                [$sessionId, $participantType, $participantId, substr($displayName, 0, 190)]
            );
        }
        $this->audit($sessionId, 'participant_joined', $participantType, $participantId, [
            'display_name' => $displayName,
            'active_count' => $activeCount + 1,
            'max_participants' => $maxParticipants,
        ]);

        return [
            'allowed' => true,
            'participant_id' => (int)$participantRowId,
            'active_count' => $activeCount + 1,
            'max_participants' => $maxParticipants,
        ];
    }

    public function markParticipantHeartbeat(?int $sessionId, string $participantType, mixed $participantId): void
    {
        if (empty($sessionId) || empty($participantId)) {
            return;
        }

        sqlQuery(
            "UPDATE `medsov_telehealth_participants`
                SET `last_seen_at` = NOW()
                WHERE `session_id` = ?
                    AND `participant_type` = ?
                    AND `participant_id` = ?
                    AND `left_at` IS NULL",
            [$sessionId, $this->normalizeParticipantType($participantType), (int)$participantId]
        );
    }

    public function markParticipantLeft(?int $sessionId, string $participantType, mixed $participantId): void
    {
        if (empty($sessionId) || empty($participantId)) {
            return;
        }

        $participantType = $this->normalizeParticipantType($participantType);
        sqlQuery(
            "UPDATE `medsov_telehealth_participants`
                SET `last_seen_at` = NOW(),
                    `left_at` = COALESCE(`left_at`, NOW())
                WHERE `session_id` = ?
                    AND `participant_type` = ?
                    AND `participant_id` = ?
                    AND `left_at` IS NULL",
            [$sessionId, $participantType, (int)$participantId]
        );
        $this->audit($sessionId, 'participant_left', $participantType, (int)$participantId);
    }

    public function getActiveParticipantCount(?int $sessionId): int
    {
        if (empty($sessionId)) {
            return 0;
        }

        $this->expireStaleParticipants($sessionId);
        $row = sqlQuery(
            "SELECT COUNT(*) AS `active_count`
                FROM `medsov_telehealth_participants`
                WHERE `session_id` = ?
                    AND `left_at` IS NULL",
            [$sessionId]
        );

        return (int)($row['active_count'] ?? 0);
    }

    public function audit(?int $sessionId, string $eventType, string $actorType, mixed $actorId = null, array $metadata = []): void
    {
        sqlQuery(
            "INSERT INTO `medsov_telehealth_audit` (`session_id`, `event_type`, `actor_type`, `actor_id`, `ip_address`, `user_agent`, `metadata_json`) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $sessionId,
                $eventType,
                $actorType,
                $actorId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                $metadata ? json_encode($metadata) : null,
            ]
        );
    }

    public function sanitizeRoomName(string $room): string
    {
        $room = preg_replace('/[^A-Za-z0-9_-]/', '', $room);
        return $room ?: 'medsov-' . bin2hex(random_bytes(12));
    }

    public function getJitsiConfig(): array
    {
        $settings = (new ModuleService())->getSettings();
        $domain = $settings[MedsovTelehealthGlobalConfig::JITSI_DOMAIN] ?: 'meet.jit.si';
        $baseUrl = $settings[MedsovTelehealthGlobalConfig::JITSI_BASE_URL] ?: 'https://' . $domain;
        $externalApi = $settings[MedsovTelehealthGlobalConfig::JITSI_EXTERNAL_API] ?: $baseUrl . '/external_api.js';

        return [
            'domain' => $domain,
            'base_url' => $baseUrl,
            'external_api' => $externalApi,
            'audio_enabled' => !empty($settings[MedsovTelehealthGlobalConfig::AUDIO_ENABLED]),
            'video_enabled' => !empty($settings[MedsovTelehealthGlobalConfig::VIDEO_ENABLED]),
            'waiting_room_enabled' => !empty($settings[MedsovTelehealthGlobalConfig::WAITING_ROOM_ENABLED]),
            'max_participants' => (int)$settings[MedsovTelehealthGlobalConfig::MAX_PARTICIPANTS],
        ];
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function encounterExistsForPatient(int $pid, int $encounter): bool
    {
        $row = sqlQuery(
            "SELECT `id` FROM `form_encounter` WHERE `pid` = ? AND `encounter` = ? LIMIT 1",
            [$pid, $encounter]
        );

        return !empty($row);
    }

    private function appointmentDateTime(?array $appointment): string
    {
        $date = trim((string)($appointment['pc_eventDate'] ?? ''));
        $time = trim((string)($appointment['pc_startTime'] ?? ''));
        if ($date === '') {
            return date('Y-m-d H:i:s');
        }
        if ($time === '') {
            $time = '00:00:00';
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time .= ':00';
        }

        return $date . ' ' . $time;
    }

    private function getParticipant(int $sessionId, string $participantType, int $participantId): ?array
    {
        $row = sqlQuery(
            "SELECT `id`, `left_at`
                FROM `medsov_telehealth_participants`
                WHERE `session_id` = ?
                    AND `participant_type` = ?
                    AND `participant_id` = ?
                LIMIT 1",
            [$sessionId, $participantType, $participantId]
        );

        return $row ?: null;
    }

    private function expireStaleParticipants(int $sessionId): void
    {
        sqlQuery(
            "UPDATE `medsov_telehealth_participants`
                SET `left_at` = `last_seen_at`
                WHERE `session_id` = ?
                    AND `left_at` IS NULL
                    AND `last_seen_at` < DATE_SUB(NOW(), INTERVAL " . self::PARTICIPANT_STALE_MINUTES . " MINUTE)",
            [$sessionId]
        );
    }

    private function normalizeParticipantType(string $participantType): string
    {
        $participantType = strtolower(trim($participantType));
        return in_array($participantType, ['provider', 'patient'], true) ? $participantType : 'user';
    }
}
