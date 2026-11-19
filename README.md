# NexGen

NexGen is a PHP and MySQL business management system for small businesses and
their administrators. It supports local development with XAMPP, container
testing with Docker, and production deployment through Railway.

## Features

- Business and administrator dashboards and reporting
- Inventory, categories, stock tracking, and batch management
- Sales recording, receipts, and sales analytics
- San Andres business and permit analytics
- User, employee, and account management
- Registration and workspace request review
- Authentication, password recovery, OTP, and image CAPTCHA
- Business-level data isolation, permissions, and administrative/audit logs
- A local rule-based chatbot for supported business questions

## Technology

- PHP 8.2 and MySQL
- Apache with URL rewriting
- HTML, CSS, and vanilla JavaScript
- Bootstrap and Chart.js
- Node.js and ESLint for JavaScript linting
- Python upload-sanitizing tools and ClamAV scanning for protected document
  uploads
- XAMPP for Windows development; Docker for container validation

## Repository layout

```text
CODE/
  PHP/       Application pages, handlers, and shared PHP helpers
  JS/        Browser-side JavaScript
  STYLE/     Application stylesheets
IMAGES/      Application images and CAPTCHA assets
TESTS/       Database-independent checks
docker/      Docker and Apache configuration
Dockerfile   Production-equivalent PHP/Apache image
```

`CODE/PHP/uploads/` is for generated uploads, not source documents. Private
signup documents must remain outside the web root and be served through the
application's authorized endpoint.

## Local development

### XAMPP on Windows

1. Place the project in `C:\xampp\htdocs\NexGen`.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Configure the local database using the schema and setup instructions
   provided by the project maintainer. The schema and application data are
   managed separately from the tracked source.
4. Configure the `NEXGEN_DB_*` variables for the Apache/PHP process as needed.
   XAMPP does not load the Docker Compose `.env` file automatically.
5. Open `http://localhost/NexGen/CODE/PHP/`.

Protected document uploads also require the Python upload dependencies and a
reachable ClamAV service. See the comments in `.env.example` and the security
notes in [SECURITY.md](./SECURITY.md) before configuring upload handling.

### Docker Compose

Install Docker Desktop, then in the repository directory:

1. Copy `.env.example` to `.env`.
2. Replace the example database values with valid local settings. Set a strong
   upload-encryption key using the project's approved secret-generation
   process. Do not commit `.env`.
3. Build and start the application and ClamAV services:

   ```sh
   docker compose up --build
   ```

4. Open `http://localhost:8080/` (or the host port set by `APP_PORT`).

The Compose setup uses a persistent Docker volume for private uploads. Stopping
the services with `docker compose down` preserves named volumes; do not use
`docker compose down -v` unless you intentionally want to delete that local
volume and its data.

## Configuration and data

The application reads database settings from:

- `NEXGEN_DB_HOST`
- `NEXGEN_DB_PORT`
- `NEXGEN_DB_NAME`
- `NEXGEN_DB_USER`
- `NEXGEN_DB_PASSWORD`

Production and upload configuration may also require variables such as
`NEXGEN_ENV`, `NEXGEN_FORCE_HTTPS`, `NEXGEN_UPLOAD_ENCRYPTION_KEY`, and the
ClamAV connection settings. See `.env.example` for the local Compose variable
names and [RAILWAY_DEPLOYMENT.md](./RAILWAY_DEPLOYMENT.md) for the production
contract. Never put actual secret values in source code, this README, Git, or
screenshots.

The database schema and live data are not a substitute for this source
repository and must be obtained and backed up through the approved database
process. Generated uploads and local database backups are also separate from
the tracked application source.

## Validation

Install the JavaScript development dependencies and lint the browser scripts:

```sh
npm ci
npm run lint
```

Run the database-independent chatbot checks with PHP:

```sh
php TESTS/rule_based_checks.php
```

The Docker build also checks PHP syntax. A successful build or test run does not
replace runtime checks for database connectivity, authentication, permissions,
tenant isolation, uploads, routing, and static assets.

## Deployment and security

- Follow [RAILWAY_DEPLOYMENT.md](./RAILWAY_DEPLOYMENT.md) for the production
  Docker and Railway setup.
- Review [SECURITY.md](./SECURITY.md) before changing document-upload behavior.
- Do not weaken authentication, authorization, tenant isolation, CAPTCHA,
  upload scanning, or audit logging to work around setup problems.
- Do not include `.env`, database exports/backups, or generated/private uploads
  in an ordinary source-code copy. If transferring the project on USB, protect
  the drive and handle database and upload backups separately.
- Please report security issues privately to the repository maintainer rather
  than in public issues.
