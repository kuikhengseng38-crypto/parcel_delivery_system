# Upload notes (example)

Private copy: save as `上传说明.txt` locally (that filename is gitignored).

1. Create a MySQL database in cPanel named `your_database_name`.
2. Create user `your_db_user` with password `your_db_password`.
3. Grant the user ALL privileges on that database only.
4. Copy `config/db.example.php` to `config/db.php` and paste those values.
5. Import `database/schema.sql` (tables only, no real customer data).
6. Run `setup.php` once, then delete it.
7. Log in and **change every default/generated password immediately**.

Do not write production passwords, cron keys, recovery keys, or real cPanel paths into this repository.
