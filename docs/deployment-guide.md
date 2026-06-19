# Medsov Telehealth Deployment Guide

## Package

Use:

```text
dist/oe-module-medsov-telehealth-1.0.0.zip
```

## Production Prerequisites

- OpenEMR 8 Patch 3 or compatible OpenEMR 8 deployment.
- Administrator access to install modules.
- Patient Portal enabled.
- SMTP configured in OpenEMR.
- Jitsi service configured.
- HTTPS enabled for camera/microphone access in production.

## Deployment Steps

1. Back up the OpenEMR database and site files.
2. Extract the module zip and copy `oe-module-medsov-telehealth` into OpenEMR `interface/modules/custom_modules/`.
3. Install and enable the module through OpenEMR Module Manager.
4. Open `Admin -> Medsov Telehealth -> Telehealth Setup`.
5. Configure Jitsi domain/base URL/external API.
6. Configure email flag and confirm OpenEMR SMTP.
7. Confirm SMS remains disabled. SMS delivery is not included in this project scope.
8. Create a test patient and test Medsov Telehealth appointment.
9. Run a provider and patient portal end-to-end test.
10. Review `Admin -> Medsov Telehealth -> Audit Log`.

## Post-Deployment Verification

Confirm:

- provider can start telehealth from appointment
- patient can see the appointment in Patient Portal
- device checks run
- patient waits before admission
- provider receives UI/email/native OpenEMR notification
- provider admits patient
- patient joins embedded Jitsi
- cancelled appointments cannot be joined
- audit log records events

## Production Configuration Notes

Mailpit is for local development only. Production must use real OpenEMR SMTP settings.

Public `meet.jit.si` is acceptable for open-source testing. Production should use the organization-approved Jitsi domain if privacy, branding, recording, or stronger moderation controls are required.

## Recovery Notes

Meeting state is stored in `medsov_telehealth_sessions`. If a browser reloads or has a temporary network issue, the waiting/admitted/in-session state can be recovered by reloading the provider or patient page.

The module uses polling endpoints to refresh status:

- provider session status
- provider waiting queue
- patient portal session status

## Performance Expectations

The project includes a timed performance script:

```powershell
node --experimental-websocket scripts\check-performance-flow.mjs
```

The script validates:

- meeting launch under 10 seconds
- provider UI notification under 30 seconds
- provider email notification under 30 seconds
