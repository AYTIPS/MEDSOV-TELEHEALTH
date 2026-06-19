# Medsov Telehealth User Guide

## Provider Workflow

1. Log into OpenEMR.
2. Open the Calendar.
3. Create or open a `Medsov Telehealth` appointment.
4. Confirm the appointment has a patient and provider.
5. Use the Medsov Telehealth card in the appointment modal.
6. Click `Start Telehealth`.
7. If the patient is waiting, click `Admit Patient`.
8. The Jitsi meeting opens inside OpenEMR.

## Provider Upcoming Appointments

Open:

```text
Calendar -> Upcoming Appointments
```

or, for administrators:

```text
Admin -> Medsov Telehealth -> Upcoming Appointments
```

This page shows upcoming Medsov Telehealth visits with:

- appointment time
- patient
- provider
- visit title
- status indicator
- Start action
- Open Appointment action

Regular providers see assigned appointments. Administrators can view appointments across providers.

## Patient Portal Workflow

1. Patient logs into the OpenEMR Patient Portal.
2. Patient clicks `Telehealth`.
3. Patient opens the upcoming virtual care visit.
4. Patient clicks `Check Devices`.
5. Patient waits for the provider to admit them.
6. After admission, the patient joins the embedded Jitsi meeting.

## Waiting Room Statuses

| Status | Meaning |
| --- | --- |
| Waiting for provider | Patient is ready but not yet admitted. |
| Patient waiting | Provider can see the patient is waiting. |
| Patient admitted | Patient can join the visit. |
| Visit canceled | Appointment is cancelled and cannot be joined. |

## Notifications

Provider receives:

- Medsov floating alert in OpenEMR
- OpenEMR Message Center notification
- email through OpenEMR SMTP

Patient receives:

- appointment invitation email
- appointment update email when date/time changes
- provider-started email if provider starts first
- cancellation email

SMS is not active in this build.
