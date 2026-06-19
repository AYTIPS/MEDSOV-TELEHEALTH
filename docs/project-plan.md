# Project Plan

## Architecture Decision

Use the current OpenEMR module pattern from the Weno module as the reference architecture. The old `oe-module-lifemesh-telehealth-1.0.0.zip` package is useful only as a functional reference because the interviewer explicitly said its structure is outdated.

## Local Development

Use Docker Compose with:

- OpenEMR application container
- MariaDB database container
- Local bind mount for `oe-module-medsov-telehealth`
- `.env` values for OpenEMR credentials and public Jitsi test config

Jitsi itself is not part of the local Docker stack for the first phase. The module should use the normal public Jitsi Meet test domain:

```text
meet.jit.si
```

This does not mean users leave OpenEMR. Meeting rooms should be embedded inside an OpenEMR module page using Jitsi's iframe API and the configured external API script:

```text
https://meet.jit.si/external_api.js
```

Rooms should be generated as configurable Jitsi rooms, for example:

```text
https://meet.jit.si/medsov-{opaque-session-id}
```

The visible user flow should be:

1. Patient/provider opens the Telehealth page in OpenEMR.
2. OpenEMR validates appointment/session access.
3. Waiting room checks audio/video permissions.
4. Provider admits patient when required.
5. The OpenEMR module renders the Jitsi meeting iframe inside the OpenEMR page.

Provider appointment flow:

1. Provider creates or opens a calendar appointment.
2. Provider selects the `Medsov Telehealth` appointment category.
3. OpenEMR saves the appointment and the module creates/reuses a telehealth session.
4. Provider reopens the appointment and clicks `Start Telehealth`.
5. The module launches the appointment's embedded Jitsi room inside OpenEMR.

Running a local Jitsi Docker stack is only needed when the project requires self-hosting, custom authentication, custom branding, recording infrastructure, or testing behavior that public `meet.jit.si` cannot support.

## Milestones

| Milestone | Output | Estimate |
| --- | --- | --- |
| Requirements confirmation | Version/Jitsi/module architecture confirmed | 1 day |
| Docker dev environment | OpenEMR running locally with module bind mount | 0.5-1 day |
| Module skeleton | Installable module visible in OpenEMR module manager | 1 day |
| Configuration | Admin page, feature flags, telehealth domain settings | 1-2 days |
| Session management | Meeting/session records tied to appointments, patients, providers, encounters | 3-5 days |
| Waiting room | Device checks, patient waiting state, provider admit flow | 2-4 days |
| Patient portal | Appointment list and secure join flow | 2-3 days |
| Provider UI | Upcoming sessions, waiting indicators, join/admit actions | 2-3 days |
| Notification integration | Patient/provider arrival and status notifications | 2-3 days |
| Security and audit | Authorization checks, signed links/tokens, audit events | 1-2 days |
| Testing and docs | Test cases, evidence, install/config/upgrade guides | 3-5 days |

## Core Data Model Draft

`medsov_telehealth_sessions`

- `id`
- `uuid`
- `pc_eid`
- `pid`
- `encounter`
- `provider_id`
- `meeting_room`
- `status`
- `patient_waiting_at`
- `provider_joined_at`
- `admitted_at`
- `ended_at`
- `created_at`
- `updated_at`

`medsov_telehealth_audit`

- `id`
- `session_id`
- `event_type`
- `actor_type`
- `actor_id`
- `ip_address`
- `user_agent`
- `created_at`
- `metadata_json`

## Security Direction

- Do not expose predictable room names.
- Generate opaque UUID-backed meeting rooms.
- Validate appointment ownership before patient access.
- Validate provider assignment or authorized role before provider access.
- Use short-lived signed join tokens for external meeting links.
- Log meeting creation, waiting room arrival, provider join, admission, and session end.
