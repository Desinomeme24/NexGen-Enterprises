# NexGen Railway Deployment

Railway must build and run the root `Dockerfile`. Do not use Nixpacks,
`Procfile`, `start.sh`, or the removed `CODE/BACKEND` directory.

## 1. Repository and builder

Push the root `Dockerfile`, `.dockerignore`, `railway.json`, and `railway.toml`.
Railway service settings should show:

- Builder: Dockerfile
- Dockerfile path: `Dockerfile`
- Source branch: the branch containing the intended commit

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
NEXGEN_PRIVATE_UPLOAD_DIR=/var/www/html/uploads/.nexgen-private
NEXGEN_PUBLIC_UPLOAD_DIR=/var/www/html/uploads
NEXGEN_RESEND_API_KEY=<Railway secret>
NEXGEN_RESEND_FROM_ADDRESS=<verified Resend sender>
NEXGEN_RESEND_FROM_NAME=NexGen
```

Railway supplies `PORT`; do not hardcode it. The Docker entrypoint listens on
that value.

## 3. Persistent storage

Railway allows one volume per service. Attach one volume to the PHP service at:

- `/var/www/html/uploads` for profile and product images, plus the protected
  private-ID directory under `/var/www/html/uploads/.nexgen-private`

Set `NEXGEN_PRIVATE_UPLOAD_DIR` to
`/var/www/html/uploads/.nexgen-private`. Valid IDs are then written below
`.nexgen-private/valid_ids`, denied by Apache, and served only through the
authenticated `valid_id_file.php` endpoint. Do not place valid IDs in
`uploads/valid_ids`; that path is retained only for legacy compatibility.

## 4. Database schema

The schema is managed outside this repository. Import the approved schema into
the Railway MySQL database using its connection details before testing the app.
Do not recreate `CODE/BACKEND/nexgen_db.sql`; that path is intentionally absent.

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
