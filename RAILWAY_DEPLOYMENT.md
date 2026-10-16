# NexGen Railway Deployment

Railway must build and run the root `Dockerfile`. Do not use Nixpacks,
`Procfile`, `start.sh`, or the removed `CODE/BACKEND` directory.

## 1. Repository and builder

Push the root `Dockerfile`, `.dockerignore`, `railway.json`, and `railway.toml`.
Railway service settings should show:

- Builder: Dockerfile
- Dockerfile path: `Dockerfile`
- Source branch: the branch containing the intended commit

Before deploying, verify the exact commit selected by Railway and compare the
local branch with its intended upstream. Do not publish a diverged local branch
or assume it contains the latest upstream changes; reconcile branch history and
review the resulting changes before committing or deploying.

## 2. Required service variables

Set these on the PHP service. Never commit their values:

```text
NEXGEN_ENV=production
NEXGEN_FORCE_HTTPS=true
NEXGEN_DB_HOST=${{MySQL.MYSQLHOST}}
NEXGEN_DB_PORT=${{MySQL.MYSQLPORT}}
NEXGEN_DB_NAME=${{MySQL.MYSQLDATABASE}}
NEXGEN_DB_USER=${{MySQL.MYSQLUSER}}
NEXGEN_DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
NEXGEN_PRIVATE_UPLOAD_DIR=/data/nexgen-private
NEXGEN_UPLOAD_QUARANTINE_DIR=/tmp/nexgen-upload-quarantine
NEXGEN_UPLOAD_ENCRYPTION_KEY=<base64-encoded 32-byte key>
NEXGEN_PYTHON_BIN=/opt/nexgen-upload-venv/bin/python
CLAMAV_HOST=<private ClamAV service host>
CLAMAV_PORT=3310
CLAMAV_TIMEOUT_SECONDS=8
NEXGEN_PUBLIC_UPLOAD_DIR=/var/www/html/uploads
NEXGEN_RESEND_API_KEY=<Railway secret>
NEXGEN_RESEND_FROM_ADDRESS=<verified Resend sender>
NEXGEN_RESEND_FROM_NAME=NexGen
```

Railway supplies `PORT`; do not hardcode it. The Docker entrypoint listens on
that value.

Railway's `/health.php` startup check verifies both PHP and database
availability. The service should not pass its startup health check until the
required production database variables are configured and MySQL accepts a
connection. This endpoint does not validate schema migrations or ClamAV
readiness; confirm those separately before accepting users.

## 3. Persistent storage

Attach a persistent volume to the PHP service at:

- `/data/nexgen-private` for encrypted signup ID and business-permit documents

`NEXGEN_PRIVATE_UPLOAD_DIR` must match this mount. Quarantine and conversion
temporary files use `/tmp/nexgen-upload-quarantine`, not the persistent volume.
The signup documents are encrypted before being written to permanent storage
and are delivered only through the authenticated `valid_id_file.php` endpoint.
Do not mount this volume below `/var/www/html` or expose it as a static asset.

The PHP service also requires a private-network ClamAV service. Deploy the
official `clamav/clamav` image separately, keep its TCP 3310 port private, and
set `CLAMAV_HOST` to its Railway private-network hostname. Verify its health and
freshclam signature updates before accepting signups. Railway does not deploy
the local `docker-compose.yml` automatically. The Railway volume's physical
encryption and backup-retention behavior must be confirmed in the Railway
service settings; application encryption does not replace key backup/recovery.

## 4. Database schema

The schema is managed outside this repository. Import the approved schema into
the Railway MySQL database using its connection details before testing the app.
Do not recreate `CODE/BACKEND/nexgen_db.sql`; that path is intentionally absent.
Before deploying this upload retrofit, apply the additive column changes
documented in `SECURITY.md`. Do not deploy signup code before those columns are
present.

## 5. Deployment acceptance checks

After a deployment, verify:

1. `/` loads without a PHP or database error.
2. `/nx-control-1407` reaches the administrator login.
3. Direct `/admin_login.php` returns 404.
4. `/captcha_image.php` returns a CAPTCHA image.
5. `/JS/...`, `/STYLE/...`, and `/IMAGES/...` return 200.
6. Login, logout, timeout, password reset, and redirects work.
7. Profile/product uploads work and survive a redeploy.
8. Direct `/uploads/valid_ids/...` access is denied.
9. Tenant filtering and administrator authorization remain enforced.
10. Railway logs show the expected Git commit and no unresolved 404/500 errors.

Test the newly deployed commit, not an older successful deployment. Never put
database passwords, Resend keys, or other secrets in GitHub, source files, or
screenshots.
