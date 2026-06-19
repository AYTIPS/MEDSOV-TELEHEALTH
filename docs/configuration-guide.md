# Medsov Telehealth Configuration Guide

Open the configuration screen from:

```text
Admin -> Medsov Telehealth -> Telehealth Setup
```

## Feature Flags

| Setting | Purpose |
| --- | --- |
| Enable Medsov Telehealth | Enables or disables the module features. |
| Enable Waiting Room | Requires patients to wait until the assigned provider admits them. |
| Enable Video | Enables video device checks and Jitsi video behavior. |
| Enable Audio | Enables microphone checks and Jitsi audio behavior. |
| Enable Email Notifications | Sends patient/provider emails through OpenEMR SMTP. |
| Enable SMS Notifications | Not used in this delivery. SMS delivery is intentionally disabled/out of scope for now. |

## Telehealth Service Settings

| Setting | Example | Purpose |
| --- | --- | --- |
| Jitsi Domain | `meet.jit.si` | Domain passed to the Jitsi iframe API. |
| Jitsi Base URL | `https://meet.jit.si` | Base URL for meeting service configuration. |
| Jitsi External API Script | `https://meet.jit.si/external_api.js` | Script loaded on the embedded meeting page. |

## Capacity Settings

| Setting | Purpose |
| --- | --- |
| Maximum Participants | Enforces the maximum number of active provider/patient participants allowed into a telehealth session. |

Note: participant capacity is enforced by the Medsov Telehealth module before the embedded Jitsi page loads. A private Jitsi deployment may still add a second layer of capacity policy if required for production.

## Email Settings

Emails are sent through OpenEMR SMTP settings. In local Docker development, Mailpit receives the messages:

```text
http://localhost:8026
```

For production, configure SMTP in OpenEMR using the organization email provider.

## Recommended Local Test Settings

```text
Jitsi Domain: meet.jit.si
Jitsi Base URL: https://meet.jit.si
Jitsi External API Script: https://meet.jit.si/external_api.js
Waiting Room: enabled
Audio: enabled
Video: enabled
Email Notifications: enabled
SMS Notifications: disabled
```
