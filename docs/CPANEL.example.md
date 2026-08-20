# cPanel deploy (example)

Copy this file to `CPANEL.md` on your own machine if you need private notes.  
`CPANEL.md` is gitignored. This example uses placeholders only.

## Database

- Host: `localhost`
- Database: `your_database_name`
- User: `your_db_user`
- Password: `your_db_password`

## App config

1. Upload the project (do not upload `.zip` files that already contain `config/db.php`).
2. Copy `config/db.example.php` → `config/db.php`.
3. Fill in the database values from cPanel → MySQL Databases.
4. Open `setup.php` once from the browser (localhost or a protected URL).
5. Delete or rename `setup.php` after it finishes.
6. Sign in, then **change the admin password immediately**.

## Cron (if you add one later)

Use a placeholder key in public docs:

```text
https://your-domain.example/parcel_delivery_system/cron.php?key=YOUR_CRON_SECRET
```

Put the real key only in cPanel Cron Jobs and in the ignored `config/db.php` / `config/config.php`.

## Paths

- Document root: `/home/YOUR_CPANEL_USER/public_html/`
- App folder: `/home/YOUR_CPANEL_USER/public_html/parcel_delivery_system/`
