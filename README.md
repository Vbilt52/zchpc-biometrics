# ZCHPC Biometrics

ZCHPC Biometrics is an access-control and biometrics solution for managing Hikvision ISAPI face-recognition/biometric terminals and working with the data they produce. It combines a reusable Laravel integration package with a web dashboard for day-to-day operations.

## Project overview

The repository contains two main components:

- **`hikvision-isapi-master/`** — `zchpc/access-control`, a Laravel package for integrating with Hikvision ISAPI terminals. It provides device communication, person/card/face/fingerprint management, door control, event handling, multi-device support, and a read-only device probe CLI (`bin/zchpc-probe`).
- **`zchpc-dashboard/`** — a Laravel 12 web application for operating the system. It includes personnel and terminal management, device health monitoring/diagnostics, device access assignments, access-log collection, and an attendance register with audited logout entry and CSV/PDF export. Personnel can be removed individually or in a page-scoped bulk selection; removal archives the local identity and queues the matching deletions on every controller, so an offline terminal never blocks the request. The dashboard depends on the local package via a Composer path repository.

## Repository structure

```text
.
├── hikvision-isapi-master/   # Hikvision ISAPI Laravel package and probe tool
└── zchpc-dashboard/          # Laravel dashboard application
```

Additional dashboard documentation lives in `zchpc-dashboard/docs/` (operations, device monitoring, device access, attendance register, etc.).

## Prerequisites

- **PHP** 8.2+ with common Laravel extensions (`curl`, `json`, `mbstring`, `openssl`, `pdo`, `xml`, `simplexml`, `libxml`); the dashboard also uses `sqlite` by default
- **Composer**
- **Node.js** and **npm** (for dashboard frontend assets)
- Network access to Hikvision ISAPI terminals for live device features

> Real device operations (status checks, sync, enrollment, door control, probing) require reachable terminals and valid device credentials. The dashboard can still be installed and explored locally without hardware.

## Running the dashboard (`zchpc-dashboard`)

### 1. Install and configure

From `zchpc-dashboard/`:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

The default environment uses SQLite (`DB_CONNECTION=sqlite`). Create the database file if it does not exist, then run migrations:

```powershell
if (!(Test-Path database/database.sqlite)) { New-Item -ItemType File database/database.sqlite | Out-Null }
php artisan migrate
```

Install frontend dependencies and build assets:

```powershell
npm install
npm run build
```

Alternatively, the dashboard includes a setup script that performs the common steps (install, env copy, key generation, migrate, npm install/build):

```powershell
composer run setup
```

### 2. Configure environment

Edit `zchpc-dashboard/.env` as needed:

- `APP_NAME`, `APP_URL`, `APP_DEBUG`
- Database settings (`DB_*`) if not using SQLite
- `ATTENDANCE_TIMEZONE` / `ATTENDANCE_DEVICE_TIMEZONE` (default `Africa/Harare`)
- Device monitoring/refresh settings (`DEVICE_MONITOR_*`, `DEVICE_REACHABILITY_TIMEOUT`, `DEVICE_REFRESH_CONCURRENCY`)
- Session/cache/queue settings as required for your environment

Configure terminals/devices through the application as required by your deployment.

### 3. Start the application

Development (serves the app, runs queue listener, logs, Vite dev server, and scheduler):

```powershell
composer run dev
```

Or run components separately:

```powershell
php artisan serve
npm run dev
```

For device monitoring and automatic event collection to work continuously, keep the scheduler and a queue worker running in addition to the web server:

```powershell
php artisan schedule:work
php artisan queue:work
```

See `zchpc-dashboard/docs/OPERATIONS.md` and `zchpc-dashboard/docs/DEVICE_MONITORING.md` for operational details, limitations, and deployment notes.

### 4. Useful commands (dashboard)

```powershell
php artisan terminals:ping --force                 # check all devices now
php artisan terminals:ping --device="Main Entrance" --force
php artisan terminals:sync-events                  # run one access-log collection
php artisan account:recover <username>             # recover a local account password
composer test                                      # clear config and run tests
```

## Running the ISAPI package / probe tool (`hikvision-isapi-master`)

The package is consumed by the dashboard from `../hikvision-isapi-master` (path repository). To work on it standalone:

```powershell
cd hikvision-isapi-master
composer install
Copy-Item .env.example .env
```

Set device connection values in `.env` (see `.env.example`): `ZCHPC_DEVICE_IP`, `ZCHPC_DEVICE_PORT`, `ZCHPC_DEVICE_USERNAME`, `ZCHPC_DEVICE_PASSWORD`, `ZCHPC_DEVICE_PROTOCOL`, `ZCHPC_DEVICE_TIMEOUT`, `ZCHPC_DEVICE_VERIFY_SSL`, `ZCHPC_DEVICE_FORMAT`, etc.

Probe a device (read-only; does not write to the terminal or export personal field values):

```powershell
$env:ZCHPC_DEVICE_PASSWORD='your_password'; php vendor/bin/zchpc-probe --host=192.168.1.100
```

Package quality checks:

```powershell
composer test      # phpunit
composer lint      # pint --test
composer analyse   # phpstan
composer ci        # lint + analyse + test
```

## Notes

- `hikvision-isapi-master/README.md` contains detailed package usage (services, multi-device providers, examples).
- `zchpc-dashboard/docs/` contains feature and operations guides (attendance, device access/monitoring, operations).
- Do not commit secrets or `.env` files; use the provided `.env.example` files as templates. Keep device credentials and private keys secure.
