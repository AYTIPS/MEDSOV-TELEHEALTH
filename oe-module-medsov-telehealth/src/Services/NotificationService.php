<?php

/**
 * Notification helpers for Medsov Telehealth.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\MedsovTelehealth\Services;

use OpenEMR\Modules\MedsovTelehealth\Bootstrap;
use OpenEMR\Modules\MedsovTelehealth\MedsovTelehealthGlobalConfig;

class NotificationService
{
    public const EVENT_PROVIDER_NOTIFIED_PATIENT_WAITING = 'provider_notified_patient_waiting';
    public const EVENT_PROVIDER_NOTIFICATION_EMAIL_FAILED = 'provider_notification_email_failed';
    public const EVENT_PROVIDER_NATIVE_MESSAGE_PATIENT_WAITING = 'provider_native_message_patient_waiting';
    public const EVENT_PROVIDER_NATIVE_MESSAGE_FAILED = 'provider_native_message_failed';
    public const EVENT_PATIENT_INVITATION_EMAIL_SENT = 'patient_invitation_email_sent';
    public const EVENT_PATIENT_INVITATION_EMAIL_FAILED = 'patient_invitation_email_failed';
    public const EVENT_PATIENT_CANCELLATION_EMAIL_SENT = 'patient_cancellation_email_sent';
    public const EVENT_PATIENT_CANCELLATION_EMAIL_FAILED = 'patient_cancellation_email_failed';
    public const EVENT_PATIENT_NOTIFIED_PROVIDER_STARTED = 'patient_notified_provider_started';
    public const EVENT_PATIENT_NOTIFICATION_EMAIL_FAILED = 'patient_notification_email_failed';

    public function notifyProviderPatientWaiting(int $sessionId): void
    {
        if (empty($sessionId)) {
            return;
        }

        $context = $this->getWaitingNotificationContext($sessionId);
        if (!$context) {
            return;
        }

        $this->createProviderWaitingNativeMessage($sessionId, $context);

        if ($this->hasAuditEvent($sessionId, self::EVENT_PROVIDER_NOTIFIED_PATIENT_WAITING)) {
            return;
        }

        $settings = (new ModuleService())->getSettings();
        if (empty($settings[MedsovTelehealthGlobalConfig::EMAIL_ENABLED]) || empty($context['provider_email'])) {
            return;
        }

        try {
            $this->sendProviderWaitingEmail($context);
            $this->audit($sessionId, self::EVENT_PROVIDER_NOTIFIED_PATIENT_WAITING, 'system', null, [
                'channel' => 'email',
                'provider_email' => $context['provider_email'],
            ]);
        } catch (\Throwable $throwable) {
            $this->audit($sessionId, self::EVENT_PROVIDER_NOTIFICATION_EMAIL_FAILED, 'system', null, [
                'channel' => 'email',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function createProviderWaitingNativeMessage(int $sessionId, array $context): void
    {
        if ($this->hasAuditEvent($sessionId, self::EVENT_PROVIDER_NATIVE_MESSAGE_PATIENT_WAITING)) {
            return;
        }

        $providerUsername = trim((string)($context['provider_username'] ?? ''));
        if ($providerUsername === '') {
            return;
        }

        try {
            $patientName = trim((string)$context['patient_fname'] . ' ' . (string)$context['patient_lname']) ?: xl('Patient');
            $providerName = trim((string)$context['provider_fname'] . ' ' . (string)$context['provider_lname']) ?: xl('Provider');
            $startLabel = $this->formatAppointmentTime((string)$context['pc_eventDate'], (string)$context['pc_startTime']);
            $launchUrl = $this->getProviderLaunchUrl($context);
            $body = implode("\n", [
                xl('A patient is waiting for a Medsov Telehealth visit.'),
                '',
                xl('Patient') . ': ' . $patientName,
                xl('Provider') . ': ' . $providerName,
                xl('Appointment') . ': ' . $startLabel,
                xl('Visit') . ': ' . (string)$context['pc_title'],
                '',
                xl('Open visit') . ': ' . $launchUrl,
            ]);
            $noteId = sqlInsert(
                "INSERT INTO `pnotes`
                    (`date`, `body`, `pid`, `user`, `groupname`, `authorized`, `activity`, `title`, `assigned_to`, `message_status`, `update_date`)
                    VALUES (NOW(), ?, ?, ?, ?, 0, 1, ?, ?, 'New', NOW())",
                [
                    $body,
                    (int)($context['pid'] ?? 0),
                    'Medsov Telehealth',
                    'Default',
                    xl('Medsov Telehealth'),
                    $providerUsername,
                ]
            );

            $this->audit($sessionId, self::EVENT_PROVIDER_NATIVE_MESSAGE_PATIENT_WAITING, 'system', null, [
                'channel' => 'openemr_message',
                'pnote_id' => $noteId,
                'assigned_to' => $providerUsername,
            ]);
        } catch (\Throwable $throwable) {
            $this->audit($sessionId, self::EVENT_PROVIDER_NATIVE_MESSAGE_FAILED, 'system', null, [
                'channel' => 'openemr_message',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function notifyPatientProviderStarted(int $sessionId): void
    {
        if (empty($sessionId) || $this->hasAuditEvent($sessionId, self::EVENT_PATIENT_NOTIFIED_PROVIDER_STARTED)) {
            return;
        }

        $settings = (new ModuleService())->getSettings();
        if (empty($settings[MedsovTelehealthGlobalConfig::EMAIL_ENABLED])) {
            return;
        }

        $context = $this->getPatientNotificationContext($sessionId);
        if (!$context || empty($context['patient_email'])) {
            return;
        }

        try {
            $this->sendPatientProviderStartedEmail($context);
            $this->audit($sessionId, self::EVENT_PATIENT_NOTIFIED_PROVIDER_STARTED, 'system', null, [
                'channel' => 'email',
                'patient_email' => $context['patient_email'],
            ]);
        } catch (\Throwable $throwable) {
            $this->audit($sessionId, self::EVENT_PATIENT_NOTIFICATION_EMAIL_FAILED, 'system', null, [
                'channel' => 'email',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function notifyPatientAppointmentInvitation(int $sessionId): void
    {
        if (empty($sessionId)) {
            return;
        }

        $context = $this->getPatientNotificationContext($sessionId);
        if (!$context || empty($context['patient_email'])) {
            return;
        }
        if ($this->isCancelledContext($context)) {
            return;
        }

        $appointmentSignature = $this->getAppointmentInvitationSignature($context);
        if ($this->hasPatientInvitationForSignature($sessionId, $appointmentSignature)) {
            return;
        }

        $settings = (new ModuleService())->getSettings();
        if (empty($settings[MedsovTelehealthGlobalConfig::EMAIL_ENABLED])) {
            return;
        }

        try {
            $isUpdate = $this->hasAuditEvent($sessionId, self::EVENT_PATIENT_INVITATION_EMAIL_SENT);
            $this->sendPatientAppointmentInvitationEmail($context, $isUpdate);
            $this->audit($sessionId, self::EVENT_PATIENT_INVITATION_EMAIL_SENT, 'system', null, [
                'channel' => 'email',
                'patient_email' => $context['patient_email'],
                'appointment_signature' => $appointmentSignature,
                'pc_eid' => (int)($context['pc_eid'] ?? 0),
                'pc_aid' => (int)($context['pc_aid'] ?? 0),
                'pc_eventDate' => (string)($context['pc_eventDate'] ?? ''),
                'pc_startTime' => (string)($context['pc_startTime'] ?? ''),
                'pc_endTime' => (string)($context['pc_endTime'] ?? ''),
                'updated' => $isUpdate,
            ]);
        } catch (\Throwable $throwable) {
            $this->audit($sessionId, self::EVENT_PATIENT_INVITATION_EMAIL_FAILED, 'system', null, [
                'channel' => 'email',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function notifyPatientAppointmentCancelled(int $sessionId): void
    {
        if (empty($sessionId) || $this->hasAuditEvent($sessionId, self::EVENT_PATIENT_CANCELLATION_EMAIL_SENT)) {
            return;
        }

        $settings = (new ModuleService())->getSettings();
        if (empty($settings[MedsovTelehealthGlobalConfig::EMAIL_ENABLED])) {
            return;
        }

        $context = $this->getPatientNotificationContext($sessionId);
        if (!$context || empty($context['patient_email'])) {
            return;
        }

        try {
            $this->sendPatientAppointmentCancellationEmail($context);
            $this->audit($sessionId, self::EVENT_PATIENT_CANCELLATION_EMAIL_SENT, 'system', null, [
                'channel' => 'email',
                'patient_email' => $context['patient_email'],
                'pc_eid' => (int)($context['pc_eid'] ?? 0),
                'pc_aid' => (int)($context['pc_aid'] ?? 0),
                'pc_eventDate' => (string)($context['pc_eventDate'] ?? ''),
                'pc_startTime' => (string)($context['pc_startTime'] ?? ''),
                'pc_endTime' => (string)($context['pc_endTime'] ?? ''),
                'pc_apptstatus' => (string)($context['pc_apptstatus'] ?? ''),
            ]);
        } catch (\Throwable $throwable) {
            $this->audit($sessionId, self::EVENT_PATIENT_CANCELLATION_EMAIL_FAILED, 'system', null, [
                'channel' => 'email',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function sendProviderWaitingEmail(array $context): void
    {
        if (!class_exists(\MyMailer::class)) {
            throw new \RuntimeException('OpenEMR mailer is not available.');
        }

        $patientName = trim((string)$context['patient_fname'] . ' ' . (string)$context['patient_lname']) ?: xl('Patient');
        $providerName = trim((string)$context['provider_fname'] . ' ' . (string)$context['provider_lname']) ?: xl('Provider');
        $startLabel = $this->formatAppointmentTime((string)$context['pc_eventDate'], (string)$context['pc_startTime']);
        $launchUrl = $this->getProviderLaunchUrl($context);
        $fromEmail = trim((string)($GLOBALS['practice_return_email_path'] ?? '')) ?: 'telehealth@medsov.local';
        $fromName = trim((string)($GLOBALS['Patient Reminder Sender Name'] ?? '')) ?: 'Medsov Telehealth';
        $subject = xl('Telehealth patient waiting') . ': ' . $patientName;
        $plainBody = implode("\n", [
            xl('A patient is waiting for a Medsov Telehealth visit.'),
            '',
            xl('Patient') . ': ' . $patientName,
            xl('Appointment') . ': ' . $startLabel,
            xl('Visit') . ': ' . (string)$context['pc_title'],
            '',
            xl('Open visit') . ': ' . $launchUrl,
        ]);
        $htmlBody = '<div style="font-family:Arial,sans-serif;color:#231f20;line-height:1.45">'
            . '<h2 style="margin:0 0 12px;color:#f4212e;">' . $this->h(xl('Patient waiting for telehealth')) . '</h2>'
            . '<p>' . $this->h(xl('A patient has entered the Medsov Telehealth waiting room.')) . '</p>'
            . '<table style="border-collapse:collapse;margin:12px 0;">'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Patient')) . '</td><td>' . $this->h($patientName) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Provider')) . '</td><td>' . $this->h($providerName) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Appointment')) . '</td><td>' . $this->h($startLabel) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Visit')) . '</td><td>' . $this->h((string)$context['pc_title']) . '</td></tr>'
            . '</table>'
            . '<p><a href="' . $this->h($launchUrl) . '" style="display:inline-block;background:#f4212e;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:bold;">'
            . $this->h(xl('Open Telehealth Visit')) . '</a></p>'
            . '</div>';

        $mail = new \MyMailer(true);
        $mail->AddReplyTo($fromEmail, $fromName);
        $mail->SetFrom($fromEmail, $fromName);
        $mail->AddAddress((string)$context['provider_email'], $providerName);
        $mail->Subject = $subject;
        $mail->AltBody = $plainBody;
        $mail->MsgHTML($htmlBody);
        $mail->IsHTML(true);
        $mail->Send();
    }

    private function sendPatientProviderStartedEmail(array $context): void
    {
        if (!class_exists(\MyMailer::class)) {
            throw new \RuntimeException('OpenEMR mailer is not available.');
        }

        $patientName = trim((string)$context['patient_fname'] . ' ' . (string)$context['patient_lname']) ?: xl('Patient');
        $providerName = trim((string)$context['provider_fname'] . ' ' . (string)$context['provider_lname']) ?: xl('Provider');
        $startLabel = $this->formatAppointmentTime((string)$context['pc_eventDate'], (string)$context['pc_startTime']);
        $portalUrl = $this->getPatientPortalUrl();
        $fromEmail = trim((string)($GLOBALS['practice_return_email_path'] ?? '')) ?: 'telehealth@medsov.local';
        $fromName = trim((string)($GLOBALS['Patient Reminder Sender Name'] ?? '')) ?: 'Medsov Telehealth';
        $subject = xl('Your provider started your telehealth visit') . ': ' . (string)$context['pc_title'];
        $plainBody = implode("\n", [
            xl('Your provider has started your Medsov Telehealth visit.'),
            '',
            xl('Patient') . ': ' . $patientName,
            xl('Provider') . ': ' . $providerName,
            xl('Appointment') . ': ' . $startLabel,
            xl('Visit') . ': ' . (string)$context['pc_title'],
            '',
            xl('Log in to the Patient Portal and open Telehealth to join your visit.'),
            xl('Patient Portal') . ': ' . $portalUrl,
        ]);
        $htmlBody = '<div style="font-family:Arial,sans-serif;color:#231f20;line-height:1.45">'
            . '<h2 style="margin:0 0 12px;color:#f4212e;">' . $this->h(xl('Your provider is ready')) . '</h2>'
            . '<p>' . $this->h(xl('Your provider has started your Medsov Telehealth visit.')) . '</p>'
            . '<table style="border-collapse:collapse;margin:12px 0;">'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Patient')) . '</td><td>' . $this->h($patientName) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Provider')) . '</td><td>' . $this->h($providerName) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Appointment')) . '</td><td>' . $this->h($startLabel) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Visit')) . '</td><td>' . $this->h((string)$context['pc_title']) . '</td></tr>'
            . '</table>'
            . '<p>' . $this->h(xl('Log in to the Patient Portal, open Telehealth, and click Join Visit.')) . '</p>'
            . '<p><a href="' . $this->h($portalUrl) . '" style="display:inline-block;background:#f4212e;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:bold;">'
            . $this->h(xl('Open Patient Portal')) . '</a></p>'
            . '</div>';

        $mail = new \MyMailer(true);
        $mail->AddReplyTo($fromEmail, $fromName);
        $mail->SetFrom($fromEmail, $fromName);
        $mail->AddAddress((string)$context['patient_email'], $patientName);
        $mail->Subject = $subject;
        $mail->AltBody = $plainBody;
        $mail->MsgHTML($htmlBody);
        $mail->IsHTML(true);
        $mail->Send();
    }

    private function sendPatientAppointmentInvitationEmail(array $context, bool $isUpdate = false): void
    {
        if (!class_exists(\MyMailer::class)) {
            throw new \RuntimeException('OpenEMR mailer is not available.');
        }

        $patientName = trim((string)$context['patient_fname'] . ' ' . (string)$context['patient_lname']) ?: xl('Patient');
        $providerName = trim((string)$context['provider_fname'] . ' ' . (string)$context['provider_lname']) ?: xl('Provider');
        $startLabel = $this->formatAppointmentTime((string)$context['pc_eventDate'], (string)$context['pc_startTime']);
        $portalUrl = $this->getPatientPortalUrl();
        $fromEmail = trim((string)($GLOBALS['practice_return_email_path'] ?? '')) ?: 'telehealth@medsov.local';
        $fromName = trim((string)($GLOBALS['Patient Reminder Sender Name'] ?? '')) ?: 'Medsov Telehealth';
        $subject = ($isUpdate ? xl('Your updated Medsov Telehealth appointment') : xl('Your Medsov Telehealth appointment')) . ': ' . $startLabel;
        $intro = $isUpdate
            ? xl('Your Medsov Telehealth appointment has been updated.')
            : xl('You have a Medsov Telehealth appointment scheduled.');
        $heading = $isUpdate
            ? xl('Your virtual care visit was updated')
            : xl('Your virtual care visit is scheduled');
        $plainBody = implode("\n", [
            $intro,
            '',
            xl('Patient') . ': ' . $patientName,
            xl('Provider') . ': ' . $providerName,
            xl('Appointment') . ': ' . $startLabel,
            xl('Visit') . ': ' . (string)$context['pc_title'],
            '',
            xl('To join, log in to the OpenEMR Patient Portal and open Telehealth.'),
            xl('Patient Portal') . ': ' . $portalUrl,
        ]);
        $htmlBody = '<div style="font-family:Arial,sans-serif;color:#231f20;line-height:1.45">'
            . '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">'
            . '<span style="display:inline-block;width:24px;height:24px;border-radius:6px;background:linear-gradient(90deg,#f4212e 0 45%,transparent 45% 55%,#f4212e 55% 100%);"></span>'
            . '<div style="font-size:12px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;color:#f4212e;">' . $this->h(xl('Medsov Telehealth')) . '</div>'
            . '</div>'
            . '<h2 style="margin:0 0 12px;color:#231f20;">' . $this->h($heading) . '</h2>'
            . '<p>' . $this->h($intro) . '</p>'
            . '<table style="border-collapse:collapse;margin:12px 0;">'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Patient')) . '</td><td>' . $this->h($patientName) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Provider')) . '</td><td>' . $this->h($providerName) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Appointment')) . '</td><td>' . $this->h($startLabel) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Visit')) . '</td><td>' . $this->h((string)$context['pc_title']) . '</td></tr>'
            . '</table>'
            . '<p>' . $this->h(xl('To join, log in to the OpenEMR Patient Portal, open Telehealth, then select Join Visit.')) . '</p>'
            . '<p><a href="' . $this->h($portalUrl) . '" style="display:inline-block;background:#f4212e;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:bold;">'
            . $this->h(xl('Open Patient Portal')) . '</a></p>'
            . '</div>';

        $mail = new \MyMailer(true);
        $mail->AddReplyTo($fromEmail, $fromName);
        $mail->SetFrom($fromEmail, $fromName);
        $mail->AddAddress((string)$context['patient_email'], $patientName);
        $mail->Subject = $subject;
        $mail->AltBody = $plainBody;
        $mail->MsgHTML($htmlBody);
        $mail->IsHTML(true);
        $mail->Send();
    }

    private function sendPatientAppointmentCancellationEmail(array $context): void
    {
        if (!class_exists(\MyMailer::class)) {
            throw new \RuntimeException('OpenEMR mailer is not available.');
        }

        $patientName = trim((string)$context['patient_fname'] . ' ' . (string)$context['patient_lname']) ?: xl('Patient');
        $providerName = trim((string)$context['provider_fname'] . ' ' . (string)$context['provider_lname']) ?: xl('Provider');
        $startLabel = $this->formatAppointmentTime((string)$context['pc_eventDate'], (string)$context['pc_startTime']);
        $portalUrl = $this->getPatientPortalUrl();
        $fromEmail = trim((string)($GLOBALS['practice_return_email_path'] ?? '')) ?: 'telehealth@medsov.local';
        $fromName = trim((string)($GLOBALS['Patient Reminder Sender Name'] ?? '')) ?: 'Medsov Telehealth';
        $subject = xl('Your Medsov Telehealth appointment was canceled') . ': ' . $startLabel;
        $plainBody = implode("\n", [
            xl('Your Medsov Telehealth appointment has been canceled.'),
            '',
            xl('Patient') . ': ' . $patientName,
            xl('Provider') . ': ' . $providerName,
            xl('Appointment') . ': ' . $startLabel,
            xl('Visit') . ': ' . (string)$context['pc_title'],
            '',
            xl('This visit is no longer joinable from the Patient Portal.'),
            xl('Patient Portal') . ': ' . $portalUrl,
        ]);
        $htmlBody = '<div style="font-family:Arial,sans-serif;color:#231f20;line-height:1.45">'
            . '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">'
            . '<span style="display:inline-block;width:24px;height:24px;border-radius:6px;background:linear-gradient(90deg,#f4212e 0 45%,transparent 45% 55%,#f4212e 55% 100%);"></span>'
            . '<div style="font-size:12px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;color:#f4212e;">' . $this->h(xl('Medsov Telehealth')) . '</div>'
            . '</div>'
            . '<h2 style="margin:0 0 12px;color:#231f20;">' . $this->h(xl('Your virtual care visit was canceled')) . '</h2>'
            . '<p>' . $this->h(xl('Your Medsov Telehealth appointment has been canceled.')) . '</p>'
            . '<table style="border-collapse:collapse;margin:12px 0;">'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Patient')) . '</td><td>' . $this->h($patientName) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Provider')) . '</td><td>' . $this->h($providerName) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Appointment')) . '</td><td>' . $this->h($startLabel) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;font-weight:bold;">' . $this->h(xl('Visit')) . '</td><td>' . $this->h((string)$context['pc_title']) . '</td></tr>'
            . '</table>'
            . '<p>' . $this->h(xl('This visit is no longer joinable from the Patient Portal.')) . '</p>'
            . '<p><a href="' . $this->h($portalUrl) . '" style="display:inline-block;border:1px solid #231f20;color:#231f20;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:bold;">'
            . $this->h(xl('Open Patient Portal')) . '</a></p>'
            . '</div>';

        $mail = new \MyMailer(true);
        $mail->AddReplyTo($fromEmail, $fromName);
        $mail->SetFrom($fromEmail, $fromName);
        $mail->AddAddress((string)$context['patient_email'], $patientName);
        $mail->Subject = $subject;
        $mail->AltBody = $plainBody;
        $mail->MsgHTML($htmlBody);
        $mail->IsHTML(true);
        $mail->Send();
    }

    private function getWaitingNotificationContext(int $sessionId): ?array
    {
        $row = sqlQuery(
            "SELECT
                s.`id`,
                s.`pc_eid`,
                s.`pid`,
                s.`provider_id`,
                s.`meeting_room`,
                e.`pc_title`,
                e.`pc_eventDate`,
                e.`pc_startTime`,
                p.`fname` AS `patient_fname`,
                p.`lname` AS `patient_lname`,
                u.`username` AS `provider_username`,
                u.`fname` AS `provider_fname`,
                u.`lname` AS `provider_lname`,
                u.`email` AS `provider_email`
            FROM `medsov_telehealth_sessions` s
            JOIN `openemr_postcalendar_events` e ON e.`pc_eid` = s.`pc_eid`
            LEFT JOIN `patient_data` p ON p.`pid` = s.`pid`
            LEFT JOIN `users` u ON u.`id` = s.`provider_id`
            WHERE s.`id` = ?
            LIMIT 1",
            [$sessionId]
        );

        return $row ?: null;
    }

    private function getPatientNotificationContext(int $sessionId): ?array
    {
        $row = sqlQuery(
            "SELECT
                s.`id`,
                s.`pc_eid`,
                s.`status` AS `session_status`,
                s.`meeting_room`,
                s.`provider_id`,
                e.`pc_aid`,
                e.`pc_title`,
                e.`pc_eventDate`,
                e.`pc_startTime`,
                e.`pc_endTime`,
                e.`pc_apptstatus`,
                p.`fname` AS `patient_fname`,
                p.`lname` AS `patient_lname`,
                p.`email` AS `patient_email`,
                u.`fname` AS `provider_fname`,
                u.`lname` AS `provider_lname`,
                u.`email` AS `provider_email`
            FROM `medsov_telehealth_sessions` s
            JOIN `openemr_postcalendar_events` e ON e.`pc_eid` = s.`pc_eid`
            LEFT JOIN `patient_data` p ON p.`pid` = s.`pid`
            LEFT JOIN `users` u ON u.`id` = s.`provider_id`
            WHERE s.`id` = ?
            LIMIT 1",
            [$sessionId]
        );

        return $row ?: null;
    }

    private function hasAuditEvent(int $sessionId, string $eventType): bool
    {
        $row = sqlQuery(
            "SELECT `id` FROM `medsov_telehealth_audit` WHERE `session_id` = ? AND `event_type` = ? LIMIT 1",
            [$sessionId, $eventType]
        );

        return !empty($row);
    }

    private function hasPatientInvitationForSignature(int $sessionId, string $appointmentSignature): bool
    {
        $statement = sqlStatement(
            "SELECT `metadata_json`
                FROM `medsov_telehealth_audit`
                WHERE `session_id` = ?
                    AND `event_type` = ?
                ORDER BY `id` DESC
                LIMIT 20",
            [$sessionId, self::EVENT_PATIENT_INVITATION_EMAIL_SENT]
        );

        while ($row = sqlFetchArray($statement)) {
            $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
            if (is_array($metadata) && ($metadata['appointment_signature'] ?? null) === $appointmentSignature) {
                return true;
            }
        }

        return false;
    }

    private function getAppointmentInvitationSignature(array $context): string
    {
        return hash('sha256', implode('|', [
            (string)($context['pc_eid'] ?? ''),
            (string)($context['pc_aid'] ?? ''),
            (string)($context['pc_eventDate'] ?? ''),
            (string)($context['pc_startTime'] ?? ''),
            (string)($context['pc_endTime'] ?? ''),
            (string)($context['pc_title'] ?? ''),
        ]));
    }

    private function isCancelledContext(array $context): bool
    {
        return (($context['session_status'] ?? '') === MeetingRoomService::SESSION_STATUS_CANCELLED)
            || in_array((string)($context['pc_apptstatus'] ?? ''), MeetingRoomService::CANCELLED_APPOINTMENT_STATUSES, true);
    }

    private function audit(?int $sessionId, string $eventType, string $actorType, mixed $actorId = null, array $metadata = []): void
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

    private function getProviderLaunchUrl(array $context): string
    {
        return $this->getBaseUrl()
            . '/interface/modules/custom_modules/' . Bootstrap::MODULE_DIRECTORY . '/templates/launch.php'
            . '?site=' . urlencode((string)($_GET['site'] ?? $_SESSION['site_id'] ?? 'default'))
            . '&eid=' . urlencode((string)$context['pc_eid'])
            . '&sid=' . urlencode((string)$context['id'])
            . '&room=' . urlencode((string)$context['meeting_room'])
            . '&role=provider';
    }

    private function getPatientPortalUrl(): string
    {
        return $this->getBaseUrl()
            . '/portal/index.php'
            . '?site=' . urlencode((string)($_GET['site'] ?? $_SESSION['site_id'] ?? 'default'));
    }

    private function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';

        return $scheme . '://' . $host . ($GLOBALS['webroot'] ?? '');
    }

    private function formatAppointmentTime(string $date, string $time): string
    {
        $timestamp = strtotime(trim($date . ' ' . $time));

        return $timestamp ? date('D, M j, Y g:i A', $timestamp) : trim($date . ' ' . $time);
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
