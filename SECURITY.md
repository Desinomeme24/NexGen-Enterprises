# Signup Document Upload Security

## Scope and upload flow

This retrofit applies to signup documents and administrator resubmissions only.
It does not change profile-image, product-image, or sales-receipt uploads.

Signup accepts one government ID image (JPG/JPEG, PNG, HEIC, or HEIF) and,
for SME Owners, one required business permit (PDF or one of those image types).
ID slots reject PDFs. HEIC/HEIF is input-only and is converted to a sanitized
JPEG. PDFs are rasterized page-by-page into image-only PDFs. Original uploads
are scanned before decoding; only sanitized outputs are encrypted and retained.
Rejection removes the request's encrypted files and their per-file keys.

The application checks upload cardinality/structure, client filename syntax,
fixed slot-specific extensions, MIME detection, magic bytes, and size before
calling the private sanitizer. Images are decoded and re-encoded with metadata
removed. PDFs are checked for encryption, active-content tokens/objects, page
count, and trailing data before rasterization. ClamAV errors and timeouts fail
closed. Stored names are random UUIDv4 values; the submitted filename is not
used for storage and is not retained.

The signup ID and business permit are encrypted with libsodium secretbox using
a random per-file key. That key is wrapped by `NEXGEN_UPLOAD_ENCRYPTION_KEY`
and stored in a private sidecar. Permanent storage must be outside the web root
at `/data/nexgen-private`. Temporary original and sanitized data is kept in
private quarantine and removed after processing.

## Required database change

The application schema is maintained outside this repository. Apply these
additive changes to the already-existing `users` and `registration_requests`
tables using the approved database change process, after taking the normal
database backup. Do not run these statements automatically from the app:

```sql
ALTER TABLE users
    ADD COLUMN business_permit_path VARCHAR(255) NULL AFTER valid_id_path;

ALTER TABLE registration_requests
    ADD COLUMN business_permit_path VARCHAR(255) NULL AFTER valid_id_path,
    ADD COLUMN privacy_consent_at DATETIME NULL AFTER business_permit_path,
    ADD COLUMN privacy_notice_version VARCHAR(32) NULL AFTER privacy_consent_at;
```

The request columns record the distinct permit reference and the signup
privacy-notice version and consent timestamp. The approved user's permit
reference is copied to `users`. Deploying the PHP changes before applying the
schema change will cause upload/review queries to fail; do not deploy until the
migration has been applied and verified.

## Docker Compose setup

1. Copy `.env.example` to `.env` and set the local database variables. Use a
   password-protected application database account rather than a production
   credential or an unrestricted root account.
2. Generate a new 32-byte encryption key:

   ```text
   openssl rand -base64 32
   ```

   Put the output in `NEXGEN_UPLOAD_ENCRYPTION_KEY` in the untracked `.env`.
   Back up the key separately from the document volume. Losing the key makes
   all encrypted documents unrecoverable; do not rotate it without a key
   migration.
3. Run `docker compose up --build`. ClamAV has no published host port; the app
   reaches it on the Compose network. Initial signature download can delay
   ClamAV health readiness. Do not accept signups until the scanner is healthy.
4. Keep the named `nexgen_private_uploads` volume persistent. It is mounted at
   `/data/nexgen-private`, outside Apache's document root. Quarantine uses a
   temporary filesystem mount.
5. For Railway, configure the same variables on the PHP service, attach its
   persistent volume at `/data/nexgen-private`, and deploy an official
   `clamav/clamav` service reachable only over Railway's private network. The
   local Compose file is not deployed by Railway. Do not publish ClamAV port
   3310 publicly.

The Docker image installs a dedicated Python virtual environment from
`requirements-upload.txt`, including Pillow, pillow-heif/libheif bindings,
pypdf, pypdfium2, and img2pdf. HEIC acceptance depends on the container's
libheif decoder being able to decode the submitted codec. Keep both the
Python packages and the operating-system libheif package patched. Monitor
Dependabot/security advisories for `requirements-upload.txt`, run `pip-audit`
against that file regularly, and rebuild/rescan the image when Debian or
libheif security updates are released. Pin reviewed versions; do not install
packages at runtime.

For native XAMPP testing, enable the PHP `sodium` extension in its `php.ini`,
install the pinned Python dependencies from `requirements-upload.txt` in a
local virtual environment, and configure the Apache environment with the
private storage path, Python interpreter/worker paths, and a reachable
ClamAV host. Signup intentionally fails closed if any of these controls is
missing. Restrict the Windows storage directory with NTFS ACLs; PHP's POSIX
mode bits do not enforce private ACLs on Windows.

## Request size limits

The application enforces slot-specific sizes and the Python sanitizer enforces
image dimensions (8,000 by 8,000 maximum) and PDF page/raster limits. Docker
also sets PHP `upload_max_filesize=10M`, `post_max_size=22M`,
`max_file_uploads=3` (so the application can reject an unexpected third file),
and Apache `LimitRequestBody=23068672`. The project `.htaccess` applies the
Apache body and PHP upload limits in XAMPP when mod_php and `AllowOverride`
are enabled. If XAMPP is configured with PHP-FPM/FastCGI or disallows those
overrides, set the same PHP values in its `php.ini`. The hosting ingress may
impose an additional lower body limit.

## Tests

Run the sanitizer tests in the upload-enabled image:

```text
docker compose run --rm --no-deps app /opt/nexgen-upload-venv/bin/python -m unittest discover -s /app/tests/upload -p test_upload_sanitizer.py
docker compose run --rm --no-deps app php /app/tests/upload/document_upload_security_test.php
```

With Compose ClamAV healthy, run its integration checks, including the EICAR
test signature (a harmless antivirus test string):

```text
docker compose run --rm app sh -c 'RUN_CLAMAV_TESTS=1 php /app/tests/upload/document_upload_security_test.php'
```

These tests cover sanitizer behavior and PHP validation helpers. They do not
replace end-to-end HTTP testing against the migrated database. Before
production, also exercise signup, admin resubmission, rejection cleanup,
document streaming authorization, ClamAV outage behavior, reverse-proxy size
limits, and the Railway volume/backup configuration.
The PHP test prints an explicit skip if `sodium` is not enabled; the Docker
image build requires it.

## Access, delivery, and operational controls

- Permanent document storage is private, outside the web root, encrypted by
  the application, and never exposed as a static URL.
- `valid_id_file.php` requires an active `system_admin`, rejects rejected
  requests, decrypts only the selected stored document, and sends a deterministic
  server-generated filename with `nosniff`, no-store, restrictive CSP, and
  attachment disposition for PDF. Re-encoded images may be displayed inline.
- The admin correction endpoint uses CSRF validation, active-admin checks,
  upload rate limits per administrator and IP, exact upload-field validation,
  and generic upload errors. Signup retains its IP/email rate limits and CSRF
  protection.
- Apache disables script execution under `/uploads`; the new identity
  documents are not stored there.
- Upload/scan/rejection/access events are written to the application error log
  with request/slot, administrator ID where available, timestamp, and IP. File
  bytes and complete ID numbers are not logged.
- User-supplied names and metadata are not rendered. The delivery filename is
  generated by the server.
- HTTPS is required in production configuration. Application-level encryption
  complements, but does not prove, encryption of Railway volumes or backups.

## Known limitations and items requiring deployment validation

- The accepted implementation uses an authenticated same-origin PHP endpoint,
  not a cookie-free file domain or separate bucket. Sanitization, encryption,
  authorization, and safe response headers reduce exposure, but do not provide
  the isolation of a separate origin. The administrator's browser still has
  the main application's origin when viewing an image.
- A strict enforcing site-wide CSP is not enabled by this change. The existing
  application contains inline scripts/handlers and multiple `innerHTML` sinks;
  replacing its partial CSP with `script-src 'self'` without a nonce/hash and
  sink review would break existing pages. The upload path itself is sanitized
  and served with a restrictive per-response CSP, but the broader CSP/XSS
  review remains deployment work and must not be represented as complete.
- No production Railway volume was attached in this work. Existing production
  files have not been inventoried, scanned, quarantined, sanitized, or migrated.
  The checked-out repository contains only upload-directory placeholders.
  Legacy requests that still point to plaintext/old-format documents cannot be
  streamed by the new endpoint, and approval now blocks until documents use
  the new private UUID storage format. Run a controlled inventory/migration
  before enabling the new flow on existing requests.
- Rejection cleanup is attempted immediately after the database rejection is
  committed. If the storage volume is unavailable, the request is inaccessible
  through the endpoint and cleanup failure is logged, but an operator must
  reconcile the orphaned encrypted file/key pair.
- No automatic retention period or scheduled deletion job is configured at
  the controller's direction. Active system administrators can manually delete
  each approved ID or permit from its registration-request page; deletion
  clears both registration-request and user references, revokes access, and
  removes the encrypted file and its per-file key. Rejected documents are
  deleted immediately.
- At-rest volume encryption, Railway backup policy, and restore/key-recovery
  procedures must be verified in the hosting account. The encryption key is a
  critical secret and must be backed up separately.
- Site ingress size limits, XAMPP PHP settings, ClamAV signature freshness,
  and actual browser response headers must be verified in each target
  environment. A successful image build alone is not deployment acceptance.
