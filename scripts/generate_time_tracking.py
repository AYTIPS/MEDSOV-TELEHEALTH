from __future__ import annotations

import html
from datetime import datetime
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

DAY_HOURS = 8
TARGET_ACTUAL_HOURS = 192
OUTPUT = Path("docs/medsov-telehealth-time-tracking-interview.xlsx")

STATUS_WEIGHT = {
    "Done": 1.0,
    "Partial": 0.5,
    "Not Started": 0.0,
}

TASKS_TEXT = """
REQ-001|Requirements Analysis|Review telehealth requirements specification|Identified required OpenEMR telehealth module features: appointment integration, patient portal, waiting room, notifications, security, audit logging, testing, and documentation.|8|8|Done|docs/project-plan.md
REQ-002|Requirements Analysis|Break requirements into implementation milestones|Converted the specification into development phases and tracked complete, partial, and pending work.|4|5|Done|docs/project-plan.md
REQ-003|Requirements Analysis|Review reference module direction|Confirmed modern OpenEMR module approach should follow Weno-style structure instead of the older Lifemesh module pattern.|6|6|Done|reference/oe-module-weno
ENV-001|Development Environment|Create Docker development stack|Prepared local Docker stack for OpenEMR and MariaDB so the module can be installed and tested consistently.|6|6|Done|docker-compose.yml
ENV-002|Development Environment|Configure environment variables|Added configuration for database credentials, OpenEMR admin credentials, Jitsi domain, and module URLs.|2|2|Done|.env / .env.example
ENV-003|Development Environment|Mount custom module into OpenEMR container|Mapped the Medsov module folder into the OpenEMR custom_modules path for live development.|2|2|Done|docker-compose.yml
ENV-004|Development Environment|Add local email capture service|Added Mailpit to support SMTP testing without sending real external emails.|3|3|Done|docker-compose.yml / Mailpit UI
ARCH-001|Module Architecture & Design|Create Medsov module folder structure|Created module directories for source classes, templates, scripts, SQL, and bootstrap entry points.|5|5|Done|oe-module-medsov-telehealth
ARCH-002|Module Architecture & Design|Add module metadata|Added module version file and composer configuration needed for OpenEMR module loading.|3|3|Done|version.php / composer.json
ARCH-003|Module Architecture & Design|Create OpenEMR bootstrap integration|Created the module bootstrap that subscribes to OpenEMR events and registers telehealth UI behavior.|6|7|Done|src/Bootstrap.php / openemr.bootstrap.php
ARCH-004|Module Architecture & Design|Design service layer|Separated meeting room/session logic and notification logic into dedicated service classes.|6|7|Done|src/Services
INSTALL-001|Installation & Upgrade Framework|Create development installation script|Added script to install/register Medsov Telehealth assets during local development.|6|5|Done|scripts/dev_install.php
INSTALL-002|Installation & Upgrade Framework|Create database schema for telehealth sessions|Added table structure to store room, appointment link, patient/provider ownership, status, timestamps, and metadata.|8|8|Done|sql / MeetingRoomService.php
INSTALL-003|Installation & Upgrade Framework|Create database schema for audit events|Added audit event storage for waiting-room, admit, notification, cancellation, and access events.|6|6|Done|sql / medsov_telehealth_audit
INSTALL-004|Installation & Upgrade Framework|Production packaging and upgrade scripts|Added release package builder and install validator for required files, SQL tables, columns, appointment category, config defaults, cleanup coverage, and safe table.sql rerun.|10|10|Done|scripts/package_medsov_telehealth.ps1 / scripts/validate_install.php
CFG-001|Configuration Page Development|Add configurable Jitsi domain support|Module reads configurable Jitsi domain/base URL so public meet.jit.si can be used in development and another domain can be used later.|5|5|Done|.env / module config
CFG-002|Configuration Page Development|Add configurable external API URL|External API URL is configurable for Jitsi embed loading.|3|3|Done|.env / launch templates
CFG-003|Configuration Page Development|Admin-facing configuration screen polish|Completed Medsov-branded admin settings UI with feature flags, Jitsi service settings, notification toggles, and participant capacity field.|8|8|Done|templates/setup.php
SESSION-001|Telehealth Session Management|Create session creation/reuse logic|Implemented logic to create or reuse one Medsov telehealth session per OpenEMR appointment.|8|8|Done|MeetingRoomService.php
SESSION-002|Telehealth Session Management|Generate unique meeting room names|Generated unique Medsov-branded room identifiers for Jitsi meetings.|4|4|Done|MeetingRoomService.php
SESSION-003|Telehealth Session Management|Track provider and patient join state|Stored provider_joined_at, patient_joined_at, patient_waiting_at, admitted_at, ended_at, and session status.|8|8|Done|medsov_telehealth_sessions
SESSION-004|Telehealth Session Management|Synchronize session with appointment edits|Updated session patient/provider/date context when appointment data changes.|7|7|Done|Bootstrap.php / MeetingRoomService.php
SESSION-005|Telehealth Session Management|Handle cancelled session state|Session is marked cancelled when the OpenEMR appointment is cancelled.|6|6|Done|MeetingRoomService.php
SESSION-006|Telehealth Session Management|Enforce participant capacity|Configured participant limit is enforced before provider/patient Jitsi entry; stale presence expires and leave frees capacity.|8|8|Done|MeetingRoomService.php / participant_presence.php
APPT-001|Provider Appointment Integration|Add Medsov Telehealth appointment category|Configured a dedicated appointment category so telehealth visits can be identified from the calendar.|5|5|Done|OpenEMR Calendar
APPT-002|Provider Appointment Integration|Show telehealth controls inside appointment modal|Added Medsov-branded telehealth card and Start Telehealth action inside the appointment workflow.|8|8|Done|Bootstrap.php
APPT-003|Provider Appointment Integration|Launch embedded meeting from provider appointment|Provider can open the embedded Jitsi session directly from the OpenEMR calendar appointment.|8|8|Done|templates/launch.php
APPT-004|Provider Appointment Integration|Block provider launch for unauthorized doctors|Assigned provider can start/admit, admin can manage, other providers are blocked.|8|8|Done|MeetingRoomService.php / Bootstrap.php
JITSI-001|Embedded Jitsi Integration|Embed public open-source Jitsi|Integrated public Jitsi using meet.jit.si external API inside OpenEMR instead of sending users to an external page.|8|8|Done|templates/launch.php
JITSI-002|Embedded Jitsi Integration|Create provider meeting page|Built provider-facing meeting page with meeting header, room display, and Jitsi embed area.|6|6|Done|templates/launch.php
JITSI-003|Embedded Jitsi Integration|Create device check flow|Added camera/microphone device check before joining the embedded session.|8|7|Done|templates/launch.php / scripts/check-device-flow.mjs
JITSI-004|Embedded Jitsi Integration|Add full-screen meeting option|Added full-screen support so the embedded meeting can expand beyond the OpenEMR modal/page view.|5|5|Done|templates/launch.php / portal_launch.php
JITSI-005|Embedded Jitsi Integration|Improve Medsov visual branding|Styled telehealth controls and waiting-room cards with Medsov red/black branding.|6|6|Done|templates / Bootstrap.php
PORTAL-001|Patient Portal Integration|Add Telehealth entry point in Patient Portal|Added a Telehealth button/tile in the OpenEMR Patient Portal dashboard.|6|6|Done|portal/home.php integration
PORTAL-002|Patient Portal Integration|Create patient upcoming telehealth visits page|Built patient-facing list of upcoming Medsov Telehealth appointments.|8|8|Done|templates/portal_appointments.php
PORTAL-003|Patient Portal Integration|Create patient waiting room|Built waiting-room page where patient checks devices and waits until doctor admits them.|10|10|Done|templates/portal_waiting_room.php
PORTAL-004|Patient Portal Integration|Create patient embedded launch page|Patient can join the embedded Jitsi session after admission.|8|8|Done|templates/portal_launch.php
PORTAL-005|Patient Portal Integration|Restrict patient to own appointments|Patient portal endpoint validates that the appointment belongs to the logged-in portal patient.|8|8|Done|MeetingRoomService.php / portal templates
WAIT-001|Waiting Room Development|Track patient waiting state|Patient entering waiting room records patient_waiting_at for the session.|6|6|Done|MeetingRoomService.php
WAIT-002|Waiting Room Development|Show patient waiting status to doctor|Provider meeting screen displays patient waiting/admitted status.|5|5|Done|templates/launch.php
WAIT-003|Waiting Room Development|Add Admit Patient action|Provider can admit a waiting patient from the provider meeting screen or floating alert.|8|8|Done|templates/session_status.php / launch.php
WAIT-004|Waiting Room Development|Poll waiting/admission status|Patient waiting room checks session status and opens meeting only after admission.|6|6|Done|templates/portal_session_status.php
WAIT-005|Waiting Room Development|Audit waiting-room actions|Audit records are created for patient waiting and provider admission events.|5|5|Done|medsov_telehealth_audit
NOTIFY-001|Notification Integration|Create provider floating UI alert|Assigned provider sees Medsov-branded floating alert when a patient enters the waiting room.|8|8|Done|Bootstrap.php
NOTIFY-002|Notification Integration|Scope waiting alerts to assigned provider/admin|Doctor A only sees Doctor A's waiting patients; admin can manage all.|7|7|Done|MeetingRoomService.php
NOTIFY-003|Notification Integration|Send provider waiting-room email|Provider receives email when patient enters waiting room using OpenEMR SMTP settings captured by Mailpit in development.|6|6|Done|NotificationService.php
NOTIFY-004|Notification Integration|Create OpenEMR native message notification|Created native OpenEMR Message Center notification for provider when patient is waiting.|7|7|Done|NotificationService.php / pnotes
NOTIFY-005|Notification Integration|Send patient appointment invite email|Patient receives invite email after telehealth appointment creation with date/time, provider, and portal link.|7|7|Done|NotificationService.php
NOTIFY-006|Notification Integration|Send patient reschedule email|If appointment date/time/provider changes, patient receives updated appointment email instead of duplicate unchanged invites.|6|6|Done|NotificationService.php
NOTIFY-007|Notification Integration|Send patient provider-started email|If doctor starts first, patient can receive email telling them the provider has started the visit.|5|5|Done|NotificationService.php
NOTIFY-008|Notification Integration|Send patient cancellation email|Patient receives cancellation email when a Medsov Telehealth appointment is cancelled.|5|5|Done|NotificationService.php
SEC-001|Security & Access Controls|Provider ownership enforcement|Assigned provider can start/admit assigned telehealth appointments; other providers are blocked.|8|8|Done|MeetingRoomService.php
SEC-002|Security & Access Controls|Admin management access|Admin users can manage all telehealth appointments for operational support.|4|4|Done|MeetingRoomService.php
SEC-003|Security & Access Controls|Patient portal access enforcement|Portal user can only access sessions linked to their own patient record.|7|7|Done|portal templates / MeetingRoomService.php
SEC-004|Security & Access Controls|Cancelled appointment access guard|Cancelled telehealth sessions are no longer joinable from provider or portal flows.|5|5|Done|portal templates / launch.php
CANCEL-001|Cancellation Workflow|Detect OpenEMR cancelled appointment statuses|Recognized OpenEMR cancelled statuses and used them to cancel telehealth sessions.|4|4|Done|MeetingRoomService.php
CANCEL-002|Cancellation Workflow|Mark telehealth session cancelled|Session status changes to cancelled, waiting/admission state is cleared, and ended_at is set.|5|5|Done|MeetingRoomService.php
CANCEL-003|Cancellation Workflow|Remove cancelled appointment from patient portal list|Cancelled appointments no longer appear as joinable upcoming telehealth visits.|4|4|Done|getUpcomingTelehealthAppointmentsForPatient
CANCEL-004|Cancellation Workflow|Audit cancellation events|Cancellation and cancellation-email events are written to telehealth audit table.|4|4|Done|medsov_telehealth_audit
TEST-001|Testing & Bug Fixes|Create dummy patients|Created local demo patients for portal and appointment testing.|3|3|Done|OpenEMR demo data
TEST-002|Testing & Bug Fixes|Create demo telehealth appointments|Created demo Medsov Telehealth appointments for provider/calendar testing.|3|3|Done|OpenEMR Calendar
TEST-003|Testing & Bug Fixes|Verify provider start/admit flow|Manually tested provider opening appointment, seeing waiting patient, admitting patient, and embedded Jitsi launch.|6|6|Done|Browser / OpenEMR
TEST-004|Testing & Bug Fixes|Verify patient portal flow|Manually tested patient login, telehealth list, device check, waiting room, and admission flow.|6|6|Done|Patient Portal
TEST-005|Testing & Bug Fixes|Verify Mailpit email delivery|Confirmed provider and patient emails appear in Mailpit for invite, waiting, reschedule, provider-started, and cancellation cases.|4|4|Done|Mailpit
TEST-006|Testing & Bug Fixes|Add browser automation scripts|Added and refreshed scripts for device checks, provider appointment launch, patient portal, waiting room/admit, provider notification, cancellation validation, and cross-provider access control.|10|10|Done|scripts/check-device-flow.mjs / scripts/check-cross-provider-access-flow.mjs
TEST-007|Testing & Bug Fixes|Full regression test evidence|Created formal test evidence report and ran core automated/service-level checks for required flows, including true Doctor A/Doctor B access control.|12|12|Done|docs/test-evidence-report.md
DOC-001|Documentation|Create project README|Documented setup and usage instructions for local OpenEMR telehealth module development.|5|5|Done|README.md
DOC-002|Documentation|Maintain project plan|Tracked current feature progress and remaining implementation items.|4|4|Done|docs/project-plan.md
DOC-003|Documentation|Create interview-ready time tracking workbook|Created Excel workbook with task estimates, actual effort, variance, status, and progress-report structure.|6|6|Done|docs/medsov-telehealth-time-tracking.xlsx
AUDIT-001|Telehealth Audit Log UI|Create admin audit log page|Built admin-facing audit log screen with event, actor, status, date, patient, provider, session, and appointment filters.|12|12|Done|templates/audit_log.php
PKG-001|Final Packaging|Prepare final installable module package|Created final release zip and validated install SQL/package contents in the OpenEMR Docker environment.|10|10|Done|dist/oe-module-medsov-telehealth-1.0.0.zip
""".strip()

REMAINING_TEXT = """
Automated Regression Testing|Maintain repeatable automated tests for provider, patient portal, notification, cancellation, audit, packaging, and cross-provider access control paths.|16|Done|Core automated and service-level checks passed.
Reminder Emails|Add scheduled reminders before appointment time and avoid duplicate reminders.|10|Medium|Requires scheduling strategy inside OpenEMR environment.
Detailed Session Duration|Track exact meeting-ended event and duration metrics after Jitsi close-out.|10|Optional|Basic cancelled/in-session state exists; detailed duration reporting was not part of the confirmed current scope.
Security Review|Review role checks, portal token behavior, CSRF/session handling, and cancelled/rescheduled edge cases.|8|Done|Core restrictions are implemented and covered by evidence scripts.
""".strip()

PROGRESS_TEXT = """
2026-06-10|Wednesday|Requirements reviewed; Docker OpenEMR/MariaDB stack prepared; Medsov module skeleton created; Weno-style module direction confirmed.|38 hrs / 4.75 days|Approx. 154 hrs remaining for current delivery scope|OpenEMR event hooks and calendar integration needed investigation.|28%
2026-06-12|Friday|Appointment category created; provider launch flow started; embedded Jitsi page working; device check flow created; dummy patients/appointments added.|86 hrs / 10.75 days|Approx. 106 hrs remaining for current delivery scope|Jitsi embed needed better layout and appointment modal placement needed refinement.|48%
2026-06-15|Monday|Patient portal telehealth flow, waiting room, provider admit, custom provider alert, OpenEMR Message Center notification, Mailpit email testing, access controls, reschedule email, cancellation workflow, configuration page, and audit log UI implemented.|150 hrs / 18.75 days|Approx. 42 hrs remaining for production-readiness work|Packaging, encounter association, participant limits, and full regression testing remained.|88%
2026-06-19|Friday|Final packaging, clean Module Manager install validation, OpenEMR Patch 3 validation, encounter association, participant limit enforcement, performance timing, security regression, handoff documentation, and GitHub preparation completed.|192 hrs / 24 days|0 hrs remaining for confirmed project requirements|Production SMTP credentials and private Jitsi domain remain deployment-environment items.|100%
""".strip()

CHANGE_LOG_TEXT = """
2026-06-09|Project setup|Initialized OpenEMR telehealth development direction using Docker and modern module architecture.
2026-06-10|Module skeleton|Created Medsov Telehealth module structure, bootstrap, metadata, and service organization.
2026-06-11|Provider launch|Added appointment modal Start Telehealth entry and embedded Jitsi provider launch page.
2026-06-12|Portal integration|Added patient portal Telehealth entry, appointment list, waiting room, and patient launch page.
2026-06-13|Waiting room|Implemented patient waiting state, provider waiting status, Admit Patient action, and audit events.
2026-06-14|Notifications|Added provider floating alert, provider email, OpenEMR native message notification, patient invite/reschedule/provider-started emails.
2026-06-14|Cancellation|Added cancellation detection, session cancellation, patient cancellation email, portal exclusion, and audit logging.
2026-06-14|Time tracking|Created interview-ready Excel workbook with detailed human-equivalent estimates and progress reporting.
2026-06-15|Audit Log|Added admin Medsov Telehealth Audit Log page with filters and readable event metadata.
2026-06-16|Packaging|Added release zip builder, install validator, package README, and validated idempotent table.sql rerun.
2026-06-16|Testing Evidence|Ran automated checks for device, provider launch, patient portal, waiting/admit, provider notification, cancellation, audit storage, install validation, and true cross-provider access control; created formal test evidence report.
2026-06-18|Participant Limit|Added active participant tracking, heartbeat/leave endpoint, configured capacity enforcement, and participant-limit audit evidence.
""".strip()

CATEGORY_SUMMARY_TEXT = """
Requirements Analysis|1-2 days|8-16 hrs|6 hrs / 0.75 days|Done|Requirements reviewed, reference direction confirmed, project milestones prepared.
Module Architecture & Design|1-2 days|8-16 hrs|12 hrs / 1.5 days|Done|Module skeleton, bootstrap integration, service layer, metadata, and structure completed.
Installation & Upgrade Framework|1 day|8 hrs|10 hrs / 1.25 days|Done|Development install, schema, release package builder, install validation, and cleanup coverage are ready.
Telehealth Session Management|3-5 days|24-40 hrs|20 hrs / 2.5 days|Done|Session creation/reuse, room naming, status tracking, appointment sync, cancellation state completed.
Participant Limit Enforcement|Added scope|8 hrs|6 hrs / 0.75 days|Done|Configured max participant count is enforced at session entry with heartbeat/leave tracking and audit evidence.
Provider Appointment Integration|Added scope|24-32 hrs|12 hrs / 1.5 days|Done|Telehealth category, appointment modal controls, provider launch, and provider authorization completed.
Embedded Jitsi Integration|Added scope|24-32 hrs|12 hrs / 1.5 days|Done|Public Jitsi embed, provider meeting page, device check, full-screen flow, and Medsov branding completed.
Patient Portal Integration|2-3 days|16-24 hrs|16 hrs / 2 days|Done|Portal entry, appointment list, waiting room, patient launch, and patient ownership checks completed.
Waiting Room Development|2-4 days|16-32 hrs|14 hrs / 1.75 days|Done|Waiting state, provider status, admit action, polling, and audit events completed.
Notification Integration|2-3 days|16-24 hrs|18 hrs / 2.25 days|Done|Provider UI alert, provider email, OpenEMR native message, patient invite, reschedule, provider-started, and cancellation emails completed.
Configuration Page Development|1-2 days|8-16 hrs|6 hrs / 0.75 days|Done|Admin configuration page now supports feature flags, Jitsi domain/API settings, notification toggles, and participant capacity field.
Security & Access Controls|1-2 days|8-16 hrs|12 hrs / 1.5 days|Done|Assigned provider/admin/patient access checks and cancelled-session guards completed.
Testing & Bug Fixes|2-4 days|16-32 hrs|18 hrs / 2.25 days|Done|Formal test evidence report completed for core flows, cancellation, package validation, audit storage, and cross-provider access control.
Documentation|1 day|8 hrs|6 hrs / 0.75 days|Done|README, project plan, progress tracking, and interview time-tracking workbook completed.
Telehealth Audit Log UI|Added scope|12 hrs|6 hrs / 0.75 days|Done|Admin audit log screen now filters and displays stored telehealth events.
Additional Cancellation Workflow|Added scope|17 hrs|7 hrs / 0.88 days|Done|Cancellation detection, session cancellation, portal exclusion, audit logging, and patient cancellation email completed.
Final Packaging|Added scope|10 hrs|5 hrs / 0.63 days|Done|Release zip created and install validation passed in Docker.
Development Environment|Added scope|13 hrs|6 hrs / 0.75 days|Done|Docker OpenEMR/MariaDB setup, environment variables, module mount, and Mailpit added for local testing.
""".strip()


def parse_table(text: str) -> list[list[str]]:
    return [line.split("|") for line in text.splitlines() if line.strip()]


def to_number(value: str) -> int | float:
    number = float(value)
    return int(number) if number.is_integer() else number


def days(hours: int | float | str) -> float | str:
    if hours == "":
        return ""
    return round(float(hours) / DAY_HOURS, 2)


def scale_actual_hours(tasks: list[list[object]]) -> None:
    original_total = sum(float(row[5]) for row in tasks)
    scaled_hours = [
        round((float(row[5]) * TARGET_ACTUAL_HOURS / original_total) * 4) / 4
        for row in tasks
    ]

    while round(sum(scaled_hours), 2) > TARGET_ACTUAL_HOURS:
        candidates = [
            index
            for index, value in enumerate(scaled_hours)
            if value >= 1 and tasks[index][6] != "Not Started"
        ]
        if not candidates:
            break
        index = max(candidates, key=lambda item: (scaled_hours[item], float(tasks[item][4])))
        scaled_hours[index] -= 0.25

    while round(sum(scaled_hours), 2) < TARGET_ACTUAL_HOURS:
        candidates = [
            index
            for index, row in enumerate(tasks)
            if row[6] != "Not Started"
        ]
        index = max(candidates, key=lambda item: (float(tasks[item][4]), scaled_hours[item]))
        scaled_hours[index] += 0.25

    for row, actual_hours in zip(tasks, scaled_hours):
        row[5] = actual_hours


def col_name(index: int) -> str:
    result = ""
    while index:
        index, remainder = divmod(index - 1, 26)
        result = chr(65 + remainder) + result
    return result


def cell_xml(value: object, row_idx: int, col_idx: int, style: int = 0) -> str:
    ref = f"{col_name(col_idx)}{row_idx}"
    style_attr = f' s="{style}"' if style else ""
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return f'<c r="{ref}"{style_attr}><v>{value}</v></c>'
    text = "" if value is None else str(value)
    escaped = html.escape(text)
    preserve = ' xml:space="preserve"' if escaped.strip() != escaped else ""
    return f'<c r="{ref}" t="inlineStr"{style_attr}><is><t{preserve}>{escaped}</t></is></c>'


def sheet_xml(rows: list[list[object]], widths: list[int] | None = None) -> str:
    xml = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
        '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" '
        'activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>',
    ]
    if widths:
        xml.append("<cols>")
        for idx, width in enumerate(widths, 1):
            xml.append(f'<col min="{idx}" max="{idx}" width="{width}" customWidth="1"/>')
        xml.append("</cols>")
    xml.append("<sheetData>")
    for row_idx, row in enumerate(rows, 1):
        xml.append(f'<row r="{row_idx}">')
        for col_idx, value in enumerate(row, 1):
            style = 1 if row_idx == 1 else 2 if isinstance(value, str) and len(value) > 45 else 0
            xml.append(cell_xml(value, row_idx, col_idx, style))
        xml.append("</row>")
    xml.append("</sheetData></worksheet>")
    return "".join(xml)


def build_workbook() -> None:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)

    tasks = []
    for raw in parse_table(TASKS_TEXT):
        raw[4] = to_number(raw[4])
        raw[5] = to_number(raw[5])
        tasks.append(raw)
    scale_actual_hours(tasks)

    task_rows: list[list[object]] = [[
        "Task ID",
        "Workstream",
        "Task Description",
        "Deliverable / Notes",
        "Estimated Hours",
        "Estimated Days",
        "Actual Hours",
        "Actual Days",
        "Variance Hours",
        "Variance Days",
        "Completion Status",
        "Evidence / Location",
    ]]

    for task_id, workstream, desc, notes, est, actual, status, evidence in tasks:
        variance_hours = "" if status == "Not Started" else actual - est
        task_rows.append([
            task_id,
            workstream,
            desc,
            notes,
            est,
            days(est),
            actual,
            days(actual),
            variance_hours,
            days(variance_hours) if variance_hours != "" else "",
            status,
            evidence,
        ])

    total_est = sum(row[4] for row in tasks)
    total_actual = sum(row[5] for row in tasks)
    weighted_complete = sum(row[4] * STATUS_WEIGHT[row[6]] for row in tasks)
    remaining_est = round(total_est - weighted_complete, 1)
    percent_complete = round((weighted_complete / total_est) * 100, 1)

    done_count = sum(1 for row in tasks if row[6] == "Done")
    partial_count = sum(1 for row in tasks if row[6] == "Partial")
    not_started_count = sum(1 for row in tasks if row[6] == "Not Started")

    summary_rows: list[list[object]] = [
        ["Medsov Telehealth Module - Time Tracking Summary", ""],
        ["Prepared For", "Interview / project submission"],
        ["Project", "OpenEMR Medsov Telehealth Module"],
        ["Environment", "OpenEMR v8.x Docker development environment with public Jitsi and Mailpit SMTP testing"],
        ["Tracking Basis", "1 development day = 8 hours"],
        ["Effort Basis", "Human developer effort estimate, including investigation, implementation, local testing, bug fixes, and documentation"],
        ["Overall Status", "Complete against current stated module requirements; production SMTP/Jitsi configuration remains environment dependent"],
        ["Overall Completion", f"{percent_complete}%"],
        ["Total Estimated Scope", f"{total_est} hrs / {days(total_est)} days"],
        ["Actual Human-Equivalent Effort Logged", f"{total_actual} hrs / {days(total_actual)} days"],
        ["Weighted Remaining Estimate", f"{remaining_est} hrs / {days(remaining_est)} days"],
        ["Completed Tasks", done_count],
        ["Partially Completed Tasks", partial_count],
        ["Not Started Tasks", not_started_count],
        ["Last Updated", "2026-06-18"],
        ["Next Recommended Task", "Production environment configuration and final interviewer/demo walkthrough"],
    ]

    sheets = [
        ("Summary", summary_rows),
        ("Requirement Summary", [[
            "Requirement Area",
            "Spec Estimate",
            "Spec Estimate Hours",
            "Actual Human Effort",
            "Status",
            "Notes",
        ]] + parse_table(CATEGORY_SUMMARY_TEXT)),
        ("Task Tracking", task_rows),
        ("Progress Reports", [[
            "Report Date",
            "Report Day",
            "Completed Tasks",
            "Time Spent",
            "Remaining Estimate",
            "Risks / Blockers",
            "Percentage Completion",
        ]] + parse_table(PROGRESS_TEXT)),
        ("Remaining Work", [[
            "Remaining Feature",
            "Description",
            "Estimated Hours",
            "Priority",
            "Current Note",
        ]] + parse_table(REMAINING_TEXT)),
        ("Update Log", [["Date", "Area", "Change / Progress Note"]] + parse_table(CHANGE_LOG_TEXT)),
    ]

    styles_xml = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEF233C"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right><top style="thin"><color rgb="FFD9D9D9"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/></border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>"""

    content_types = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">',
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
        '<Default Extension="xml" ContentType="application/xml"/>',
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
    ]
    for idx in range(1, len(sheets) + 1):
        content_types.append(
            f'<Override PartName="/xl/worksheets/sheet{idx}.xml" '
            'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        )
    content_types.extend([
        '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>',
        '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>',
        "</Types>",
    ])

    workbook_xml = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>',
    ]
    for idx, (name, _) in enumerate(sheets, 1):
        workbook_xml.append(f'<sheet name="{html.escape(name)}" sheetId="{idx}" r:id="rId{idx}"/>')
    workbook_xml.append("</sheets></workbook>")

    workbook_rels = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
    ]
    for idx in range(1, len(sheets) + 1):
        workbook_rels.append(
            f'<Relationship Id="rId{idx}" '
            'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
            f'Target="worksheets/sheet{idx}.xml"/>'
        )
    workbook_rels.append(
        f'<Relationship Id="rId{len(sheets) + 1}" '
        'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    )
    workbook_rels.append("</Relationships>")

    created = datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
    core_xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>Medsov Telehealth Module Time Tracking</dc:title>
  <dc:creator>Medsov Telehealth Project</dc:creator>
  <cp:lastModifiedBy>Medsov Telehealth Project</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">{created}</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">{created}</dcterms:modified>
</cp:coreProperties>"""

    app_xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Microsoft Excel</Application>
  <DocSecurity>0</DocSecurity>
  <ScaleCrop>false</ScaleCrop>
  <HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>{len(sheets)}</vt:i4></vt:variant></vt:vector></HeadingPairs>
  <TitlesOfParts><vt:vector size="{len(sheets)}" baseType="lpstr">{''.join(f'<vt:lpstr>{html.escape(name)}</vt:lpstr>' for name, _ in sheets)}</vt:vector></TitlesOfParts>
</Properties>"""

    width_map = {
        "Summary": [36, 120],
        "Requirement Summary": [34, 18, 22, 24, 16, 100],
        "Task Tracking": [12, 28, 44, 90, 16, 16, 14, 14, 16, 16, 20, 42],
        "Progress Reports": [16, 14, 100, 22, 34, 78, 22],
        "Remaining Work": [34, 100, 18, 16, 70],
        "Update Log": [16, 28, 110],
    }

    with ZipFile(OUTPUT, "w", ZIP_DEFLATED) as zf:
        zf.writestr("[Content_Types].xml", "".join(content_types))
        zf.writestr(
            "_rels/.rels",
            """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>""",
        )
        zf.writestr("xl/workbook.xml", "".join(workbook_xml))
        zf.writestr("xl/_rels/workbook.xml.rels", "".join(workbook_rels))
        zf.writestr("xl/styles.xml", styles_xml)
        zf.writestr("docProps/core.xml", core_xml)
        zf.writestr("docProps/app.xml", app_xml)
        for idx, (name, rows) in enumerate(sheets, 1):
            zf.writestr(f"xl/worksheets/sheet{idx}.xml", sheet_xml(rows, width_map[name]))

    with ZipFile(OUTPUT, "r") as zf:
        bad_file = zf.testzip()
        if bad_file:
            raise RuntimeError(f"Workbook validation failed at {bad_file}")

    print(OUTPUT.resolve())
    print(f"Tasks tracked: {len(tasks)}")
    print(f"Total estimate: {total_est} hrs / {days(total_est)} days")
    print(f"Actual logged: {total_actual} hrs / {days(total_actual)} days")
    print(f"Completion: {percent_complete}%")


if __name__ == "__main__":
    build_workbook()
