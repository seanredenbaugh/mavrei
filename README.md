# Maverick Real Estate Investments

Custom PHP/MySQL property portfolio application for `mavrei.com`.

## Requirements

- PHP 8.1 or newer with PDO MySQL
- MySQL 8 or MariaDB 10.5+
- Apache with `.htaccess` support
- HTTPS for production

## Hostinger installation

1. Create a new empty MySQL database and database user in Hostinger hPanel.
2. Open phpMyAdmin for that database.
3. Import `database/schema.sql`.
4. Import `database/legacy_import.sql`.
5. Copy `config.example.php` to `config.php` and enter the new database credentials.
6. Upload the application contents to `public_html`. Do not upload the old WordPress SQL export.
7. Confirm that `.htaccess` was uploaded (it protects configuration, database, and script files).
8. Visit `/admin/setup.php` once and create the first administrator account.
9. Sign in at `/admin/` and review the imported property details.

The first-user setup page disables itself as soon as an administrator exists.

## GitHub

`config.php` is excluded by `.gitignore`; never commit production database credentials or the legacy WordPress export. The migrated property photos are intentionally part of this project unless image storage is moved elsewhere later.

## Map

The application uses MapLibre GL JS and OpenFreeMap. It requires no API key. Map styles can be changed from the map toolbar.

## Legacy migration

The generated import includes 14 properties and 155 linked property photos. To regenerate it from the original backups:

```bash
python3 scripts/migrate_wordpress.py /path/to/i4776105_wp1.sql /path/to/wp-content.zip .
```

The original WordPress database is migration input only and is not used by the application.

