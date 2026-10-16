# NexGen

NexGen is a PHP/MySQL business management system with modules for dashboard
analytics, inventory, sales, accounts receivable, user management, reporting,
and administrative controls.

## Technology

- PHP 8.2
- MySQL
- Apache
- Vanilla JavaScript, Bootstrap, and Chart.js
- Docker or XAMPP for local development

## Repository layout

- `CODE/PHP/` - PHP pages, handlers, and shared backend helpers
- `CODE/JS/` - browser-side JavaScript
- `CODE/STYLE/` - stylesheets
- `IMAGES/` - application images and CAPTCHA assets
- `VIDEOS/` - browser-accessible landing-page video assets
- `Dockerfile` - production-equivalent Apache/PHP image

## Local development

### XAMPP

Place the repository under the XAMPP `htdocs` directory, start Apache and
MySQL, and open the application through the local Apache URL.

### Docker

Build and run the application with:

```bash
docker build -t nexgen-local .
docker run --rm -p 8080:80 nexgen-local
```

The application is then available at `http://localhost:8080/`.

## Configuration

Database credentials and service secrets must be supplied through environment
variables. Never commit `.env` files or secret values. The application
recognizes:

- `NEXGEN_ENV`
- `NEXGEN_DB_HOST`
- `NEXGEN_DB_PORT`
- `NEXGEN_DB_NAME`
- `NEXGEN_DB_USER`
- `NEXGEN_DB_PASSWORD`

The database schema and production service configuration are managed separately
from this public source repository.

## Validation

Before deployment, validate PHP syntax, JavaScript linting, the Docker build,
database connectivity, authentication, authorization, uploads, and required
static assets in the target environment.

## Security

Please do not report security issues in public issues. Contact the repository
maintainer privately with a reproducible description and relevant impact.
