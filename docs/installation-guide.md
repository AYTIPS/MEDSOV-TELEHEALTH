# Medsov Telehealth Installation Guide

## Target Module

Module name: `oe-module-medsov-telehealth`

Validated OpenEMR image: `openemr/openemr:8.0.0.3`

Installable package:

```text
dist/oe-module-medsov-telehealth-1.0.0.zip
```

## Prerequisites

- OpenEMR 8 Patch 3 or compatible OpenEMR 8 environment.
- OpenEMR administrator account.
- Patient Portal enabled if patient joining is required.
- SMTP configured in OpenEMR if email notifications are required.
- Jitsi domain available. Local testing uses `meet.jit.si`.

## Docker Development Install

For the local Docker development stack:

```powershell
docker compose up -d
docker compose exec -T openemr php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth/scripts/dev_install.php
```

OpenEMR:

```text
http://localhost:8080
```

Default local login:

```text
admin / pass
```

## OpenEMR Module Manager Install

1. Log into OpenEMR as an administrator.
2. Extract `dist/oe-module-medsov-telehealth-1.0.0.zip`.
3. Copy the extracted `oe-module-medsov-telehealth` folder into:

```text
openemr/interface/modules/custom_modules/
```

4. Open `Modules -> Manage Modules` or the OpenEMR Module Manager screen available in the target OpenEMR build.
5. Confirm the module appears in the Custom Module Listings table.
6. Click `Install`.
7. Click `Enable`.
8. Confirm OpenEMR shows the module as active.
9. Open `Admin -> Medsov Telehealth -> Telehealth Setup`.
10. Confirm the Jitsi and notification settings.
11. Create or open a `Medsov Telehealth` appointment and verify the Start Telehealth card appears.

## Installation Validation

Run the package/install validator from inside the OpenEMR container:

```powershell
docker compose exec -T openemr php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth/scripts/validate_install.php --rerun-install-sql
```

Expected result:

```text
Medsov Telehealth install validation passed.
```

## OpenEMR Patch 3 Docker Note

OpenEMR Patch 3 applies strict permissions at startup. For a bind-mounted development module, restore container-side read permissions after restarting OpenEMR:

```powershell
docker compose exec -T openemr chown -R apache:root /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth
docker compose exec -T openemr chmod -R a+rX /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth
```

This note applies to the local Docker bind-mount workflow. A normal installed module package should be owned and permissioned by the target OpenEMR environment.
