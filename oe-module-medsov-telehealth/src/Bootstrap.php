<?php

/**
 * Event bootstrap for the Medsov Telehealth module.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\MedsovTelehealth;

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use OpenEMR\Events\Appointments\AppointmentSetEvent;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Events\Main\Tabs\RenderEvent as MainTabsRenderEvent;
use OpenEMR\Events\PatientPortal\RenderEvent as PatientPortalRenderEvent;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Modules\MedsovTelehealth\Services\MeetingRoomService;
use OpenEMR\Modules\MedsovTelehealth\Services\NotificationService;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_DIRECTORY = 'oe-module-medsov-telehealth';
    public const MODULE_MENU_NAME = 'Medsov Telehealth';

    private MedsovTelehealthGlobalConfig $globalsConfig;

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
        $this->globalsConfig = new MedsovTelehealthGlobalConfig();
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(GlobalsInitializedEvent::EVENT_HANDLE, $this->addGlobalSettings(...));
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addCustomMenuItem(...));
        $this->eventDispatcher->addListener(AppointmentSetEvent::EVENT_HANDLE, $this->createAppointmentSession(...));
        $this->eventDispatcher->addListener(AppointmentRenderEvent::RENDER_BELOW_PATIENT, $this->renderStartTelehealthButton(...));
        $this->eventDispatcher->addListener(PatientPortalRenderEvent::EVENT_DASHBOARD_INJECT_CARD, $this->renderPatientPortalTile(...));
        $this->eventDispatcher->addListener(MainTabsRenderEvent::EVENT_BODY_RENDER_POST, $this->renderProviderWaitingNotifier(...));
    }

    public function addGlobalSettings(GlobalsInitializedEvent $event): void
    {
        $this->globalsConfig->setupConfiguration($event->getGlobalsService());
    }

    public function addCustomMenuItem(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();
        $siteQuery = $this->getSiteQuery();

        $topMenu = new \stdClass();
        $topMenu->requirement = 0;
        $topMenu->target = 'adm0';
        $topMenu->menu_id = 'adm';
        $topMenu->label = xlt(self::MODULE_MENU_NAME);
        $topMenu->icon = 'fa-video-camera';
        $topMenu->children = [];
        $topMenu->acl_req = ['admin', 'super'];
        $topMenu->global_req = [MedsovTelehealthGlobalConfig::ENABLED];

        $setupMenu = new \stdClass();
        $setupMenu->requirement = 0;
        $setupMenu->target = 'adm0';
        $setupMenu->menu_id = 'adm';
        $setupMenu->label = xlt('Telehealth Setup');
        $setupMenu->url = '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/setup.php?' . $siteQuery;
        $setupMenu->children = [];
        $setupMenu->acl_req = ['admin', 'super'];
        $setupMenu->global_req = [MedsovTelehealthGlobalConfig::ENABLED];

        $auditMenu = new \stdClass();
        $auditMenu->requirement = 0;
        $auditMenu->target = 'adm0';
        $auditMenu->menu_id = 'adm';
        $auditMenu->label = xlt('Audit Log');
        $auditMenu->url = '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/audit_log.php?' . $siteQuery;
        $auditMenu->children = [];
        $auditMenu->acl_req = ['admin', 'super'];
        $auditMenu->global_req = [MedsovTelehealthGlobalConfig::ENABLED];

        $upcomingMenu = new \stdClass();
        $upcomingMenu->requirement = 0;
        $upcomingMenu->target = 'adm0';
        $upcomingMenu->menu_id = 'adm';
        $upcomingMenu->label = xlt('Upcoming Appointments');
        $upcomingMenu->url = '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/provider_appointments.php?' . $siteQuery;
        $upcomingMenu->children = [];
        $upcomingMenu->acl_req = ['patients', 'appt'];
        $upcomingMenu->global_req = [MedsovTelehealthGlobalConfig::ENABLED];

        $testMenu = new \stdClass();
        $testMenu->requirement = 0;
        $testMenu->target = 'adm0';
        $testMenu->menu_id = 'adm';
        $testMenu->label = xlt('Telehealth Test Room');
        $testMenu->url = '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/waiting_room.php?' . $siteQuery;
        $testMenu->children = [];
        $testMenu->acl_req = ['admin', 'super'];
        $testMenu->global_req = [MedsovTelehealthGlobalConfig::ENABLED];

        foreach ($menu as $item) {
            if (($item->menu_id ?? null) !== 'admimg') {
                continue;
            }

            $item->children[] = $topMenu;
            foreach ($item->children as $child) {
                if (($child->label ?? '') === xlt(self::MODULE_MENU_NAME)) {
                    $child->children[] = $setupMenu;
                    $child->children[] = $upcomingMenu;
                    $child->children[] = $auditMenu;
                    $child->children[] = $testMenu;
                    break;
                }
            }
        }

        $providerUpcomingMenu = clone $upcomingMenu;
        $providerUpcomingMenu->target = 'cal0';
        $providerUpcomingMenu->menu_id = 'cal';
        foreach ($menu as $item) {
            if (($item->menu_id ?? null) === 'calimg') {
                $item->children[] = $providerUpcomingMenu;
                break;
            }
        }

        $event->setMenu($menu);

        return $event;
    }

    public function createAppointmentSession(AppointmentSetEvent $event): void
    {
        if (empty($event->eid)) {
            return;
        }

        $meetingService = new MeetingRoomService();
        $appointment = $meetingService->getAppointmentById((int)$event->eid);
        if (!$appointment || !$meetingService->isTelehealthCategory((int)($appointment['pc_catid'] ?? 0))) {
            return;
        }

        if ($meetingService->isAppointmentCancelled($appointment)) {
            $session = $meetingService->cancelAppointmentSession((int)$event->eid, $appointment);
            if ($session) {
                (new NotificationService())->notifyPatientAppointmentCancelled((int)$session['id']);
            }
            return;
        }

        $session = $meetingService->createOrGetSessionForAppointment((int)$event->eid, $appointment);
        (new NotificationService())->notifyPatientAppointmentInvitation((int)$session['id']);
    }

    public function renderStartTelehealthButton(AppointmentRenderEvent $event): void
    {
        $appointment = $event->getAppt();
        $eid = (int)($appointment['pc_eid'] ?? 0);
        $categoryId = (int)($appointment['pc_catid'] ?? 0);
        $pid = (int)($appointment['pc_pid'] ?? 0);

        if (empty($eid) || empty($categoryId)) {
            return;
        }

        $meetingService = new MeetingRoomService();
        if (!$meetingService->isTelehealthCategory($categoryId)) {
            return;
        }

        if (!$meetingService->currentUserCanManageAppointment($appointment)) {
            return;
        }

        if ($meetingService->isAppointmentCancelled($appointment)) {
            echo "<div class='alert alert-warning mt-2 mb-2'>"
                . xlt("This Medsov Telehealth appointment is canceled.")
                . "</div>";
            return;
        }

        if (empty($pid)) {
            echo "<div class='alert alert-warning mt-2 mb-2'>"
                . xlt("Select a patient before starting a Medsov Telehealth session.")
                . "</div>";
            return;
        }

        $session = $meetingService->createOrGetSessionForAppointment($eid, $appointment);
        $config = $meetingService->getJitsiConfig();
        $waitingRoomEnabled = !empty($config['waiting_room_enabled']);
        $launchUrl = $GLOBALS['webroot']
            . '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/launch.php'
            . '?' . $this->getSiteQuery()
            . '&eid=' . urlencode((string)$eid)
            . '&sid=' . urlencode((string)$session['id'])
            . '&room=' . urlencode((string)$session['meeting_room'])
            . '&role=provider';
        $statusUrl = $GLOBALS['webroot']
            . '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/session_status.php'
            . '?' . $this->getSiteQuery()
            . '&eid=' . urlencode((string)$eid)
            . '&sid=' . urlencode((string)$session['id']);
        $admitUrl = $GLOBALS['webroot']
            . '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/admit_patient.php'
            . '?' . $this->getSiteQuery();
        $panelId = 'medsov-telehealth-' . $eid;
        $copyLabel = xla('Copy Room');
        $copiedLabel = xla('Copied');
        $csrfToken = CsrfUtils::collectCsrfToken();
        $patientWaiting = !empty($session['patient_waiting_at']);
        $patientAdmitted = !empty($session['admitted_at']);
        $waitingState = $patientAdmitted ? 'admitted' : ($patientWaiting ? 'waiting' : 'pending');
        $waitingLabel = $patientAdmitted ? xlt('Patient admitted') : ($patientWaiting ? xlt('Patient waiting') : xlt('Waiting for patient'));
        $admitDisabled = (!$patientWaiting || $patientAdmitted) ? ' disabled' : '';

        echo "<style>
            .medsov-telehealth-card {
                position: relative;
                overflow: hidden;
                margin: .75rem 0;
                padding: 1rem;
                border: 1px solid #ead7d9;
                border-radius: .5rem;
                background: #fff;
                color: #231f20;
                box-shadow: 0 .5rem 1.25rem rgba(35, 31, 32, .08);
            }
            .medsov-telehealth-card:before {
                content: '';
                position: absolute;
                inset: 0 auto 0 0;
                width: .375rem;
                background: #f4212e;
            }
            .medsov-telehealth-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: .75rem;
                margin-left: .25rem;
            }
            .medsov-telehealth-brand {
                display: flex;
                align-items: center;
                gap: .625rem;
                min-width: 0;
            }
            .medsov-telehealth-mark {
                width: 1.5rem;
                height: 1.5rem;
                border-radius: .375rem;
                background: linear-gradient(90deg, #f4212e 0 45%, transparent 45% 55%, #f4212e 55% 100%);
                box-shadow: inset 0 0 0 1px rgba(244, 33, 46, .12);
                flex: 0 0 auto;
            }
            .medsov-telehealth-eyebrow {
                font-size: .75rem;
                color: #f4212e;
                font-weight: 700;
                text-transform: uppercase;
            }
            .medsov-telehealth-title {
                font-size: 1rem;
                line-height: 1.25;
                font-weight: 700;
                color: #231f20;
            }
            .medsov-telehealth-status {
                display: inline-flex;
                align-items: center;
                gap: .375rem;
                padding: .3125rem .625rem;
                border: 1px solid #f7c3c8;
                border-radius: 999px;
                background: #fff1f2;
                color: #a70d18;
                font-size: .8125rem;
                font-weight: 700;
                white-space: nowrap;
            }
            .medsov-telehealth-body {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .625rem;
                margin: .875rem 0 1rem .25rem;
            }
            .medsov-telehealth-meta {
                min-width: 0;
                padding: .625rem .75rem;
                border: 1px solid #ece4e5;
                border-radius: .375rem;
                background: #fbfafb;
            }
            .medsov-telehealth-meta-label {
                display: block;
                margin-bottom: .125rem;
                color: #6b5f61;
                font-size: .75rem;
                font-weight: 700;
            }
            .medsov-telehealth-meta-value {
                color: #231f20;
                font-size: .875rem;
                overflow-wrap: anywhere;
            }
            .medsov-telehealth-actions {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: .5rem;
                margin-left: .25rem;
            }
            .medsov-telehealth-waiting {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .75rem;
                flex-wrap: wrap;
                margin: 0 0 1rem .25rem;
                padding: .75rem;
                border: 1px solid #ece4e5;
                border-radius: .375rem;
                background: #fbfafb;
            }
            .medsov-telehealth-waiting-title {
                color: #584f51;
                font-size: .75rem;
                font-weight: 700;
                text-transform: uppercase;
            }
            .medsov-telehealth-waiting-label {
                color: #231f20;
                font-size: .9375rem;
                font-weight: 700;
            }
            .medsov-telehealth-waiting-pill {
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
            .medsov-telehealth-waiting-pill[data-state='pending'] {
                border: 1px solid #d7dde4;
                background: #fff;
                color: #566579;
            }
            .medsov-telehealth-waiting-pill[data-state='waiting'] {
                border: 1px solid #f7c3c8;
                background: #fff1f2;
                color: #a70d18;
            }
            .medsov-telehealth-waiting-pill[data-state='admitted'] {
                border: 1px solid #b8e2c0;
                background: #eefaf0;
                color: #1f7a3a;
            }
            .medsov-telehealth-start,
            .medsov-telehealth-start:hover,
            .medsov-telehealth-start:focus {
                display: inline-flex;
                align-items: center;
                gap: .5rem;
                min-height: 2.375rem;
                padding: .5rem 1rem;
                border: 1px solid #f4212e;
                border-radius: 999px;
                background: #f4212e;
                color: #fff;
                font-weight: 700;
                text-decoration: none;
            }
            .medsov-telehealth-copy,
            .medsov-telehealth-copy:hover,
            .medsov-telehealth-copy:focus,
            .medsov-telehealth-admit,
            .medsov-telehealth-admit:hover,
            .medsov-telehealth-admit:focus,
            .medsov-telehealth-fullscreen,
            .medsov-telehealth-fullscreen:hover,
            .medsov-telehealth-fullscreen:focus {
                display: inline-flex;
                align-items: center;
                gap: .5rem;
                min-height: 2.375rem;
                padding: .5rem .875rem;
                border: 1px solid #231f20;
                border-radius: 999px;
                background: #fff;
                color: #231f20;
                font-weight: 700;
                text-decoration: none;
            }
            .medsov-telehealth-admit:not(:disabled),
            .medsov-telehealth-admit:not(:disabled):hover,
            .medsov-telehealth-admit:not(:disabled):focus {
                border-color: #f4212e;
                background: #fff1f2;
                color: #a70d18;
            }
            .medsov-telehealth-admit:disabled {
                opacity: .55;
                cursor: not-allowed;
            }
            .medsov-telehealth-feedback {
                color: #584f51;
                font-size: .8125rem;
            }
            @media (max-width: 640px) {
                .medsov-telehealth-head,
                .medsov-telehealth-body {
                    grid-template-columns: 1fr;
                }
                .medsov-telehealth-body {
                    display: grid;
                }
            }
        </style>";
        echo "<div id='" . attr($panelId) . "' class='medsov-telehealth-card'>";
        echo "<div class='medsov-telehealth-head'>";
        echo "<div class='medsov-telehealth-brand'>";
        echo "<span class='medsov-telehealth-mark' aria-hidden='true'></span>";
        echo "<div>";
        echo "<div class='medsov-telehealth-eyebrow'>" . xlt("MedSov") . "</div>";
        echo "<div class='medsov-telehealth-title'>" . xlt("Virtual Care Session") . "</div>";
        echo "</div>";
        echo "</div>";
        echo "<span class='medsov-telehealth-status'><i class='fa fa-circle' aria-hidden='true'></i>" . xlt("Ready") . "</span>";
        echo "</div>";
        echo "<div class='medsov-telehealth-body'>";
        echo "<div class='medsov-telehealth-meta'>";
        echo "<span class='medsov-telehealth-meta-label'>" . xlt("Room") . "</span>";
        echo "<span class='medsov-telehealth-meta-value'>" . text((string)$session['meeting_room']) . "</span>";
        echo "</div>";
        echo "<div class='medsov-telehealth-meta'>";
        echo "<span class='medsov-telehealth-meta-label'>" . xlt("Session") . "</span>";
        echo "<span class='medsov-telehealth-meta-value'>#" . text((string)$session['id']) . " · " . xlt("Embedded Jitsi") . "</span>";
        echo "</div>";
        echo "</div>";
        if ($waitingRoomEnabled) {
            echo "<div class='medsov-telehealth-waiting' data-medsov-session-state='" . attr($waitingState) . "'>";
            echo "<div>";
            echo "<div class='medsov-telehealth-waiting-title'>" . xlt("Waiting Room") . "</div>";
            echo "<div class='medsov-telehealth-waiting-label' data-medsov-waiting-label>" . $waitingLabel . "</div>";
            echo "</div>";
            echo "<span class='medsov-telehealth-waiting-pill' data-medsov-waiting-pill data-state='" . attr($waitingState) . "'>";
            echo "<i class='fa fa-circle' aria-hidden='true'></i><span data-medsov-waiting-pill-text>" . $waitingLabel . "</span>";
            echo "</span>";
            echo "</div>";
        }
        echo "<div class='medsov-telehealth-actions'>";
        echo "<a class='medsov-telehealth-start' href='" . attr($launchUrl) . "' target='_blank' rel='noopener' onclick='top.restoreSession && top.restoreSession();'>";
        echo "<i class='fa fa-video-camera' aria-hidden='true'></i><span>" . xlt("Start Telehealth") . "</span>";
        echo "</a>";
        echo "<a class='medsov-telehealth-fullscreen' href='" . attr($launchUrl) . "' target='_blank' rel='noopener' onclick='top.restoreSession && top.restoreSession();'>";
        echo "<i class='fa fa-expand' aria-hidden='true'></i><span>" . xlt("Open Full Screen") . "</span>";
        echo "</a>";
        if ($waitingRoomEnabled) {
            echo "<button class='medsov-telehealth-admit' type='button' data-medsov-admit-patient" . $admitDisabled . ">";
            echo "<i class='fa fa-user-plus' aria-hidden='true'></i><span data-medsov-admit-label>" . xlt("Admit Patient") . "</span>";
            echo "</button>";
        }
        echo "<button class='medsov-telehealth-copy' type='button' data-medsov-copy-room='" . attr((string)$session['meeting_room']) . "'>";
        echo "<i class='fa fa-copy' aria-hidden='true'></i><span class='medsov-copy-label'>" . xlt("Copy Room") . "</span>";
        echo "</button>";
        echo "<span class='medsov-telehealth-feedback' data-medsov-telehealth-feedback></span>";
        echo "</div>";
        echo "</div>";
        echo "<script>
            (function () {
                var panel = document.getElementById('" . attr($panelId) . "');
                if (!panel) {
                    return;
                }
                var copyButton = panel.querySelector('[data-medsov-copy-room]');
                var copyLabel = panel.querySelector('.medsov-copy-label');
                if (!copyButton || !copyLabel) {
                    return;
                }
                var defaultLabel = " . js_escape($copyLabel) . ";
                var copiedLabel = " . js_escape($copiedLabel) . ";
                var statusUrl = " . js_escape($statusUrl) . ";
                var admitUrl = " . js_escape($admitUrl) . ";
                var appointmentId = " . js_escape((string)$eid) . ";
                var sessionId = " . js_escape((string)$session['id']) . ";
                var csrfToken = " . js_escape($csrfToken) . ";
                var waitingLabel = panel.querySelector('[data-medsov-waiting-label]');
                var waitingPill = panel.querySelector('[data-medsov-waiting-pill]');
                var waitingPillText = panel.querySelector('[data-medsov-waiting-pill-text]');
                var admitButton = panel.querySelector('[data-medsov-admit-patient]');
                var admitLabel = panel.querySelector('[data-medsov-admit-label]');
                var feedback = panel.querySelector('[data-medsov-telehealth-feedback]');

                function applyStatus(data) {
                    if (!data || !data.ok) {
                        return;
                    }
                    var state = data.admitted ? 'admitted' : (data.patient_waiting ? 'waiting' : 'pending');
                    var label = data.label || '';
                    if (waitingLabel) {
                        waitingLabel.textContent = label;
                    }
                    if (waitingPill) {
                        waitingPill.setAttribute('data-state', state);
                    }
                    if (waitingPillText) {
                        waitingPillText.textContent = label;
                    }
                    if (admitButton) {
                        admitButton.disabled = !data.requires_admission || data.admitted || !data.patient_waiting;
                    }
                    if (admitLabel) {
                        admitLabel.textContent = data.admitted ? " . js_escape(xl('Patient Admitted')) . " : " . js_escape(xl('Admit Patient')) . ";
                    }
                    if (feedback && data.admitted) {
                        feedback.textContent = " . js_escape(xl('Patient can now join the meeting.')) . ";
                    }
                }

                function refreshStatus() {
                    if (!window.fetch) {
                        return;
                    }
                    fetch(statusUrl, { credentials: 'same-origin' })
                        .then(function (response) { return response.json(); })
                        .then(applyStatus)
                        .catch(function () {});
                }

                if (admitButton) {
                    admitButton.addEventListener('click', function () {
                        if (!window.fetch || admitButton.disabled) {
                            return;
                        }
                        admitButton.disabled = true;
                        if (feedback) {
                            feedback.textContent = " . js_escape(xl('Admitting patient...')) . ";
                        }
                        var body = new URLSearchParams();
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
                            .then(function (data) {
                                if (!data || !data.ok) {
                                    if (feedback) {
                                        feedback.textContent = data && data.error ? data.error : " . js_escape(xl('Unable to admit patient.')) . ";
                                    }
                                    refreshStatus();
                                    return;
                                }
                                applyStatus(data);
                            })
                            .catch(function () {
                                if (feedback) {
                                    feedback.textContent = " . js_escape(xl('Unable to admit patient.')) . ";
                                }
                                refreshStatus();
                            });
                    });
                }

                copyButton.addEventListener('click', function () {
                    var room = copyButton.getAttribute('data-medsov-copy-room') || '';
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(room).then(function () {
                            copyLabel.textContent = copiedLabel;
                            window.setTimeout(function () {
                                copyLabel.textContent = defaultLabel;
                            }, 1400);
                        });
                        return;
                    }
                    copyLabel.textContent = room;
                    window.setTimeout(function () {
                        copyLabel.textContent = defaultLabel;
                    }, 2200);
                });
                refreshStatus();
                window.setInterval(refreshStatus, 5000);
            }());
        </script>";
    }

    public function renderPatientPortalTile(GenericEvent $event): void
    {
        $siteId = $_GET['site'] ?? $_SESSION['site_id'] ?? 'default';
        $portalUrl = ($GLOBALS['webroot'] ?? '')
            . '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/portal_appointments.php'
            . '?site=' . urlencode((string)$siteId);

        echo "<a id='medsov-telehealth-go' class='col-lg-2 col-md-4 col-sm-6 col-6 card bg-light pl-sm-2 pr-sm-2 pl-0 pr-0 pt-2 pb-2 text-center text-decoration-none' href='" . attr($portalUrl) . "'>";
        echo "<h1 class='card-image'><i class='fa fa-2x fa-video-camera text-dark'></i></h1>";
        echo "<div class='card-body pl-1 pr-1 pl-sm-3 pr-sm-3'>";
        echo "<button class='btn d-block w-100 text-light' style='background:#f4212e;border-color:#f4212e;font-weight:700;' type='button'>";
        echo xlt('Telehealth');
        echo "</button>";
        echo "</div>";
        echo "</a>";
    }

    public function renderProviderWaitingNotifier(MainTabsRenderEvent $event): void
    {
        $siteQuery = $this->getSiteQuery();
        $queueUrl = ($GLOBALS['webroot'] ?? '')
            . '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/provider_waiting_queue.php?'
            . $siteQuery;
        $admitUrl = ($GLOBALS['webroot'] ?? '')
            . '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY . '/templates/admit_patient.php?'
            . $siteQuery;

        echo "<style>
            #medsovProviderWaitingNotifier {
                position: fixed;
                right: 1rem;
                bottom: 1rem;
                z-index: 20000;
                width: min(24rem, calc(100vw - 2rem));
                color: #231f20;
                font-family: inherit;
            }
            #medsovProviderWaitingNotifier.is-hidden {
                display: none;
            }
            .medsov-provider-alert {
                overflow: hidden;
                border: 1px solid #ead7d9;
                border-radius: .5rem;
                background: #fff;
                box-shadow: 0 .75rem 2rem rgba(35, 31, 32, .18);
            }
            .medsov-provider-alert__head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .75rem;
                padding: .875rem 1rem;
                border-left: .375rem solid #f4212e;
                border-bottom: 1px solid #f2e6e8;
                background: #fffafa;
            }
            .medsov-provider-alert__brand {
                display: flex;
                align-items: center;
                gap: .625rem;
                min-width: 0;
            }
            .medsov-provider-alert__mark {
                width: 1.5rem;
                height: 1.5rem;
                border-radius: .375rem;
                background: linear-gradient(90deg, #f4212e 0 45%, transparent 45% 55%, #f4212e 55% 100%);
                flex: 0 0 auto;
            }
            .medsov-provider-alert__eyebrow {
                color: #f4212e;
                font-size: .7rem;
                font-weight: 800;
                line-height: 1;
                text-transform: uppercase;
            }
            .medsov-provider-alert__title {
                color: #231f20;
                font-size: .95rem;
                font-weight: 800;
                line-height: 1.2;
            }
            .medsov-provider-alert__count {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 1.75rem;
                min-height: 1.75rem;
                padding: .25rem .5rem;
                border-radius: 999px;
                background: #f4212e;
                color: #fff;
                font-size: .85rem;
                font-weight: 800;
            }
            .medsov-provider-alert__list {
                display: grid;
                gap: .625rem;
                max-height: 19rem;
                overflow: auto;
                padding: .75rem;
            }
            .medsov-provider-alert__item {
                border: 1px solid #ece4e5;
                border-radius: .375rem;
                background: #fff;
                padding: .75rem;
            }
            .medsov-provider-alert__patient {
                margin: 0 0 .25rem;
                color: #231f20;
                font-size: .95rem;
                font-weight: 800;
            }
            .medsov-provider-alert__meta {
                margin: 0;
                color: #61585a;
                font-size: .8rem;
                line-height: 1.35;
            }
            .medsov-provider-alert__wait {
                display: inline-flex;
                align-items: center;
                gap: .35rem;
                margin-top: .5rem;
                padding: .25rem .5rem;
                border: 1px solid #f7c3c8;
                border-radius: 999px;
                background: #fff1f2;
                color: #a70d18;
                font-size: .78rem;
                font-weight: 800;
            }
            .medsov-provider-alert__actions {
                display: flex;
                flex-wrap: wrap;
                gap: .5rem;
                margin-top: .75rem;
            }
            .medsov-provider-alert__open,
            .medsov-provider-alert__open:hover,
            .medsov-provider-alert__open:focus,
            .medsov-provider-alert__admit,
            .medsov-provider-alert__admit:hover,
            .medsov-provider-alert__admit:focus {
                display: inline-flex;
                align-items: center;
                gap: .4rem;
                min-height: 2.125rem;
                padding: .375rem .75rem;
                border-radius: 999px;
                font-size: .82rem;
                font-weight: 800;
                text-decoration: none;
            }
            .medsov-provider-alert__open {
                border: 1px solid #f4212e;
                background: #f4212e;
                color: #fff;
            }
            .medsov-provider-alert__admit {
                border: 1px solid #231f20;
                background: #fff;
                color: #231f20;
            }
            .medsov-provider-alert__admit:disabled {
                opacity: .6;
                cursor: wait;
            }
        </style>";

        echo "<div id='medsovProviderWaitingNotifier' class='is-hidden' data-queue-url='" . attr($queueUrl) . "' data-admit-url='" . attr($admitUrl) . "'>";
        echo "<section class='medsov-provider-alert' aria-live='polite'>";
        echo "<div class='medsov-provider-alert__head'>";
        echo "<div class='medsov-provider-alert__brand'>";
        echo "<span class='medsov-provider-alert__mark' aria-hidden='true'></span>";
        echo "<div><div class='medsov-provider-alert__eyebrow'>" . xlt('MedSov Telehealth') . "</div>";
        echo "<div class='medsov-provider-alert__title'>" . xlt('Patient Waiting') . "</div></div>";
        echo "</div>";
        echo "<span class='medsov-provider-alert__count' data-medsov-waiting-count>0</span>";
        echo "</div>";
        echo "<div class='medsov-provider-alert__list' data-medsov-waiting-list></div>";
        echo "</section>";
        echo "</div>";

        echo "<script>
            (function () {
                var root = document.getElementById('medsovProviderWaitingNotifier');
                if (!root || root.dataset.ready === '1') {
                    return;
                }
                root.dataset.ready = '1';
                var queueUrl = root.getAttribute('data-queue-url');
                var admitUrl = root.getAttribute('data-admit-url');
                var countNode = root.querySelector('[data-medsov-waiting-count]');
                var listNode = root.querySelector('[data-medsov-waiting-list]');
                var lastCsrfToken = '';

                function escapeHtml(value) {
                    return String(value == null ? '' : value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/\"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                function openVisit(url) {
                    if (!url) {
                        return;
                    }
                    try {
                        if (top.restoreSession) {
                            top.restoreSession();
                        }
                    } catch (error) {}
                    window.open(url, '_blank', 'noopener');
                }

                function renderQueue(data) {
                    if (!data || !data.ok || !data.count) {
                        root.classList.add('is-hidden');
                        listNode.innerHTML = '';
                        countNode.textContent = '0';
                        return;
                    }

                    lastCsrfToken = data.csrf_token || lastCsrfToken;
                    root.classList.remove('is-hidden');
                    countNode.textContent = String(data.count);
                    listNode.innerHTML = data.items.map(function (item) {
                        return '<article class=\"medsov-provider-alert__item\">'
                            + '<h3 class=\"medsov-provider-alert__patient\">' + escapeHtml(item.patient_name) + '</h3>'
                            + '<p class=\"medsov-provider-alert__meta\">' + escapeHtml(item.title) + '<br>' + escapeHtml(item.appointment_time) + '<br>' + escapeHtml(item.provider_name) + '</p>'
                            + '<span class=\"medsov-provider-alert__wait\"><i class=\"fa fa-circle\" aria-hidden=\"true\"></i>' + escapeHtml(item.waiting_label) + '</span>'
                            + '<div class=\"medsov-provider-alert__actions\">'
                            + '<button class=\"medsov-provider-alert__open\" type=\"button\" data-open-url=\"' + escapeHtml(item.launch_url) + '\"><i class=\"fa fa-video-camera\" aria-hidden=\"true\"></i>" . xla('Open Visit') . "</button>'
                            + '<button class=\"medsov-provider-alert__admit\" type=\"button\" data-admit-session=\"' + escapeHtml(item.session_id) + '\" data-admit-eid=\"' + escapeHtml(item.appointment_id) + '\" data-open-url=\"' + escapeHtml(item.launch_url) + '\"><i class=\"fa fa-user-plus\" aria-hidden=\"true\"></i>" . xla('Admit') . "</button>'
                            + '</div>'
                            + '</article>';
                    }).join('');
                }

                function refreshQueue() {
                    if (!window.fetch) {
                        return;
                    }
                    fetch(queueUrl, { credentials: 'same-origin' })
                        .then(function (response) { return response.json(); })
                        .then(renderQueue)
                        .catch(function () {});
                }

                listNode.addEventListener('click', function (event) {
                    var openButton = event.target.closest('[data-open-url]');
                    if (!openButton) {
                        return;
                    }

                    if (openButton.matches('[data-admit-session]')) {
                        openButton.disabled = true;
                        var body = new URLSearchParams();
                        body.set('sid', openButton.getAttribute('data-admit-session') || '');
                        body.set('eid', openButton.getAttribute('data-admit-eid') || '');
                        body.set('csrf_token_form', lastCsrfToken);
                        fetch(admitUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: body.toString()
                        })
                            .then(function (response) { return response.json(); })
                            .then(function (data) {
                                if (data && data.ok) {
                                    openVisit(openButton.getAttribute('data-open-url'));
                                }
                                refreshQueue();
                            })
                            .catch(refreshQueue);
                        return;
                    }

                    openVisit(openButton.getAttribute('data-open-url'));
                });

                refreshQueue();
                window.setInterval(refreshQueue, 8000);
            }());
        </script>";
    }

    private function getSiteQuery(): string
    {
        $siteId = $_SESSION['site_id'] ?? $_GET['site'] ?? 'default';

        return 'site=' . urlencode((string)$siteId);
    }
}
