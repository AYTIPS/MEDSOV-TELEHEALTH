# Medsov OpenEMR Telehealth Module

This repository is the working area for `oe-module-medsov-telehealth`, an installable OpenEMR module that adds telehealth appointment links, waiting room state, provider/patient join flows, provider notifications, admin configuration, audit logging, and embedded Jitsi visits.

## Target Version

The interviewer said "OpenEMR v8.3" and "get version 8 and install a patch to v8.3". This project is validated on OpenEMR `8.0.0` Patch 3 using the Docker image:

```powershell
openemr/openemr:8.0.0.3
```

The local `.env` sets:

```powershell
OPENEMR_IMAGE=openemr/openemr:8.0.0.3
```

## Start The Dev Stack

```powershell
Copy-Item .env.example .env
docker compose up -d
```

Open OpenEMR at:

```text
http://localhost:8080
```

Open Mailpit at:

```text
http://localhost:8026
```

Default local credentials from `.env.example`:

```text
admin / pass
```

## Local Dev Install

The module is mounted into the OpenEMR container, but the local Docker database still needs a module registry row and install SQL. For this dev environment, run:

```powershell
docker compose exec openemr chmod -R a+rX /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth
docker compose exec openemr php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth/scripts/dev_install.php
```

Then log in to OpenEMR and use:

```text
Admin -> Medsov Telehealth -> Telehealth Setup
Admin -> Medsov Telehealth -> Upcoming Appointments
Admin -> Medsov Telehealth -> Audit Log
Admin -> Medsov Telehealth -> Telehealth Test Room
```

To create local demo patients and telehealth appointments:

```powershell
docker compose exec openemr php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth/scripts/seed_demo_data.php
```

## Release Package

The mounted Docker workflow is for development only. For final submission/testing, build a release zip from the repository root:

```powershell
.\scripts\package_medsov_telehealth.ps1
```

Output:

```text
dist/oe-module-medsov-telehealth-1.0.0.zip
```

The zip contains the OpenEMR module folder and excludes development-only helpers such as demo data seeding.

For a normal OpenEMR install, extract the zip and copy `oe-module-medsov-telehealth` into:

```text
openemr/interface/modules/custom_modules/
```

Then use OpenEMR Module Manager to install and enable the module.

After installing the package in OpenEMR, validate the install SQL and database shape:

```powershell
docker compose exec -T openemr php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth/scripts/validate_install.php --rerun-install-sql
```

The validator checks required module files, session/audit tables, required columns, appointment category creation, default configuration keys, cleanup SQL coverage, and safe rerun of `table.sql`.

## Provider Appointment Flow

The test room is only for development smoke testing. The provider-facing workflow is appointment-driven:

1. Log in to OpenEMR.
2. Open the Calendar.
3. Create or edit an appointment.
4. Set the appointment category to `Medsov Telehealth`.
5. Save the appointment.
6. Reopen the saved appointment.
7. Click `Start Telehealth`.

The `Start Telehealth` button is injected into the OpenEMR appointment edit page. It creates or reuses a `medsov_telehealth_sessions` row for that appointment and opens the embedded Jitsi meeting inside the Medsov module page.

## Provider Waiting Notification Flow

The patient admission workflow is:

1. Patient logs in to the OpenEMR Patient Portal.
2. Patient opens `Telehealth`.
3. Patient clicks `Join Visit`.
4. Patient clicks `Check Devices`.
5. OpenEMR keeps the patient in the waiting room.
6. The assigned provider sees a Medsov-branded alert inside OpenEMR.
7. Mailpit captures the provider email notification.
8. Provider clicks `Admit`.
9. Patient enters the embedded Jitsi visit page.

The provider alert is scoped by the appointment provider. Doctor A only sees Doctor A's telehealth patients, because the queue endpoint filters by the logged-in OpenEMR provider user ID.

Local email is captured by Mailpit. The OpenEMR Docker service sends SMTP to `mailpit:1025`; your browser opens Mailpit at `http://localhost:8026`.

SMS delivery is not implemented in this build.

To verify the full UI plus email flow:

```powershell
node --experimental-websocket scripts\check-provider-notification-flow.mjs
```

## Module Mount

The local module folder is bind-mounted into OpenEMR:

```text
./oe-module-medsov-telehealth
  -> /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth
```

That lets us edit the module locally and test it inside the OpenEMR container.

## Jitsi Test Config

For this project, use the normal public Jitsi Meet service for testing:

```text
MEDSOV_TELEHEALTH_JITSI_DOMAIN=meet.jit.si
MEDSOV_TELEHEALTH_BASE_URL=https://meet.jit.si
MEDSOV_TELEHEALTH_JITSI_EXTERNAL_API=https://meet.jit.si/external_api.js
```

That means we are not running a Jitsi Docker stack locally for the first phase. OpenEMR runs in Docker, and the module embeds a `meet.jit.si` room inside an OpenEMR page using the Jitsi iframe API.

The patient and provider should stay inside OpenEMR. They should click a Telehealth action in OpenEMR, pass through the waiting room/admission flow, and then see the Jitsi meeting embedded in the OpenEMR module page. The module should keep the Jitsi domain configurable so the same code can later point to a private domain such as `video.medsov.com` or `telehealth.poundsoff.com`.

## First Milestone

Build the module in this order:

1. Confirm Weno module architecture in OpenEMR 8.0.0 patch 3.
2. Create installable module metadata and installer/migration path.
3. Add admin configuration page and feature flags.
4. Add telehealth session table, audit table, and meeting creation service.
5. Add provider appointment join/waiting status UI.
6. Add patient portal appointment join/waiting room UI.
7. Add provider UI/email/OpenEMR Message Center notifications.
8. Add test evidence and installation/configuration/upgrade docs.

The old LifeMesh telehealth zip can be used for behavior ideas, but not for module architecture.
