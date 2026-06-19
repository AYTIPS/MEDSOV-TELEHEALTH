# Medsov Telehealth Upgrade Guide

## Current Version

Current module package:

```text
oe-module-medsov-telehealth-1.0.0
```

## Upgrade Approach

The module uses OpenEMR-compatible SQL installation logic in:

```text
oe-module-medsov-telehealth/table.sql
```

The install SQL uses guarded table/category creation so rerunning the install SQL is safe during validation.

## Upgrade Validation

Run:

```powershell
docker compose exec -T openemr php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-medsov-telehealth/scripts/validate_install.php --rerun-install-sql
```

Expected:

```text
Medsov Telehealth install validation passed.
```

## Future Version Upgrade Files

For a future version such as `1.1.0`, add a versioned SQL upgrade file if schema changes are required. The expected pattern is:

```text
sql/1_0_0-to-1_1_0_upgrade.sql
```

Schema changes should be additive and backward compatible where possible:

- add new nullable columns first
- backfill data safely
- add indexes after data exists
- avoid destructive changes unless migration and rollback are documented

## Rollback / Cleanup

Development cleanup references are in:

```text
oe-module-medsov-telehealth/cleanup.sql
```

Do not run cleanup on an environment that contains telehealth data you need to keep.
