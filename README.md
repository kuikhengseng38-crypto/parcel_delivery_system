# ParcelTrack Pro

PHP + MySQL courier dispatch portal for **educational use**. Admins assign parcels and watch the live fleet; riders update duty status, GPS, and delivery proof from their own portal.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/license-MIT%20(Educational)-0B6E4F)
![Purpose](https://img.shields.io/badge/purpose-Education-1B9AAA)

## Screenshots

<p align="center">
  <img src="docs/screenshots/live-radar.png" alt="Live fleet radar" width="48%" />
  <img src="docs/screenshots/manage-parcels.png" alt="Manage parcels" width="48%" />
</p>
<p align="center">
  <img src="docs/screenshots/manage-riders.png" alt="Manage riders" width="48%" />
  <img src="docs/screenshots/completed-orders.png" alt="Completed orders" width="48%" />
</p>
<p align="center">
  <img src="docs/screenshots/rider-dashboard.png" alt="Rider dashboard" width="48%" />
  <img src="docs/screenshots/audit-logs.png" alt="Audit logs" width="48%" />
</p>

| Left | Right |
| --- | --- |
| Live fleet radar with planned vs actual GPS trails | Create parcels, geocode addresses, assign riders |
| Register riders and view traveled routes | Completed orders with proof photos |
| Rider duty status and assigned parcels | System audit / activity logs |

## Features

- **Admin dispatch** — create parcels, geocode delivery addresses, assign riders, search and filter orders
- **Live radar** — online riders on a map, planned route vs actual GPS trail
- **Rider portal** — go online/offline, update parcel status, upload delivery proof
- **Route planner** — multi-stop routes for assigned parcels
- **Completed orders** — proof images and delivery route playback
- **Reports & audit logs** — date-range summaries and staff activity history
- **Role-based auth** — admin and rider sessions with CSRF protection

## Tech stack

- PHP 8 (PDO / MySQL)
- Vanilla JavaScript
- Leaflet maps
- HTML / CSS (admin + rider themes)

## Quick start

1. Clone this repository into your web root (XAMPP `htdocs`, Laragon `www`, etc.).
2. Copy the example database config and edit **local** values only:

   ```bash
   copy config\db.example.php config\db.php
   ```

   Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` for your own machine.  
   `config/db.php` is gitignored — do **not** commit real hosting credentials.

3. Create an empty MySQL database named `parcel_delivery_db` (or match the name in `config/db.php`).
4. Open `http://localhost/parcel_delivery_system/setup.php` once to create tables and seed demo users.
5. Sign in at `http://localhost/parcel_delivery_system/login.php`.

### Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@parcel.local` | `Admin@1234` |
| Rider | `rider@parcel.local` | `Rider@1234` |
| Rider 2 | `rider2@parcel.local` | `Rider@1234` |

Change these passwords after setup if you share a server with other people.

## Project layout

```
admin/          Dispatcher UI (radar, parcels, riders, reports, logs)
rider/          Rider UI (duty status, parcels, routes, history)
api/            JSON endpoints (GPS, status, uploads, geocoding)
config/         db.example.php  →  copy to db.php (not committed)
database/       schema.sql and migrations
assets/         CSS, JS, and upload folders
includes/       Auth, header/footer, helpers
```

## License

This project is released under the [MIT License](LICENSE) **for educational purposes**.
Use it to learn, demo, or extend a courier workflow — not as production hosting credentials.
