# oe-module-medsov-telehealth

Installable OpenEMR telehealth module for Medsov virtual care workflows.

## Features

- Admin configuration page for feature flags, Jitsi settings, notification channels, and participant capacity
- OpenEMR calendar appointment integration for the `Medsov Telehealth` appointment category
- Provider Start Telehealth action inside the appointment modal
- Embedded public Jitsi Meet room inside OpenEMR using the configurable iframe API URL
- Patient Portal telehealth appointment list, waiting room, device check, and join flow
- Provider waiting-room alert, Admit Patient action, and OpenEMR Message Center notification
- Patient/provider email notifications through OpenEMR SMTP
- Cancellation handling with patient email and portal join protection
- Provider/admin/patient access controls
- Telehealth session and audit tables
- Admin audit log UI with filters

## OpenEMR Module Files

The release package includes the module-manager files OpenEMR expects:

- `composer.json`
- `info.txt`
- `moduleConfig.php`
- `openemr.bootstrap.php`
- `version.php`
- `table.sql`
- `cleanup.sql`
- `src/`
- `templates/`
- `scripts/validate_install.php`

Development-only scripts such as demo data seeding and Docker install helpers are not included in the release zip.

## Database Install

OpenEMR should run `table.sql` during module install. The file creates:

- `medsov_telehealth_sessions`
- `medsov_telehealth_audit`
- `Medsov Telehealth` appointment category

The SQL uses OpenEMR upgrade directives such as `#IfNotTable` and `#IfNotRow`, so rerunning the install SQL is safe for validation and upgrade checks.

## Cleanup

`cleanup.sql` removes:

- Medsov Telehealth global settings
- `medsov_telehealth_audit`
- `medsov_telehealth_sessions`
- `Medsov Telehealth` appointment category

Do not run cleanup against a database that contains demo or test telehealth records you want to keep.

## Release Zip

The validated release package is included in the repository at:

```text
dist/oe-module-medsov-telehealth-1.0.0.zip
```

The zip root contains the `oe-module-medsov-telehealth` folder.

## Validate Install

After installing the module in OpenEMR, run:

```powershell
docker compose exec -T openemr php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth/scripts/validate_install.php --rerun-install-sql
```

The validator checks:

- required package files
- session table and required columns
- audit table and required columns
- Medsov Telehealth appointment category
- module configuration defaults
- cleanup SQL references
- idempotent `table.sql` rerun through OpenEMR `SQLUpgradeService`

## Local Development Mount

For local Docker development, this folder is bind-mounted into:

```text
/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth
```

Use `scripts/dev_install.php` only for the local Docker development database. It is not required for a normal OpenEMR module-manager install.
