CLAUDE.md

This file provides project-specific instructions for Claude Code and other AI coding agents working with the NexGen repository.

Project Overview

NexGen is a PHP-based business management system developed using:

PHP

MySQL

HTML

CSS

Vanilla JavaScript

Bootstrap

Chart.js

XAMPP for local development

The system contains modules for:

Dashboard and analytics

Inventory management

Sales recording

Sales analytics

Accounts receivable

Accounts masterlist

User and administrator management

Authentication and account security

Settings

Reporting

AI-powered chatbot

Multi-tenant/business data isolation

Administrative and audit logging

The project is currently in the deployment, production-hardening, and
cross-environment validation phase. Feature work may still occur, but deployment
stability, environment portability, routing correctness, security, and data
integrity take priority over adding new features or broad refactoring.

Preserving existing functionality, security, tenant isolation, and database integrity is more important than unnecessary refactoring.

Directory Structure

Primary repository structure:

NexGen/
├── CODE/
│ ├── PHP/ # PHP pages, handlers, helpers, and backend logic
│ ├── JS/ # JavaScript functionality
│ └── STYLE/ # CSS stylesheets
├── IMAGES/
│ └── captcha/ # Image-based CAPTCHA assets
├── Dockerfile # Production-equivalent PHP/Apache image
├── package.json
├── package-lock.json
└── CLAUDE.md

CODE/BACKEND and VIDEOS were intentionally removed and must not be
recreated, required by the Docker build, committed, published, or referenced.
The database schema is managed separately from the public source repository.
Do not invent a replacement SQL path or add database exports to Git.

Do not reorganize the repository structure unless explicitly requested.

Local Development Environment

The project normally runs using XAMPP on Windows.

Typical project location:

C:\xampp\htdocs\NexGen

Apache and MySQL are managed through the XAMPP Control Panel.

The application is normally accessed through localhost.

When executing commands, remember that the developer is using Windows.

Claude Code may operate through a Bash-compatible shell where Windows paths
can behave differently.

For example:

Windows:

C:\xampp\htdocs\NexGen

Bash-compatible representation:

/c/xampp/htdocs/NexGen

When already inside the project directory, prefer relative paths where possible.

Do not repeatedly retry malformed Windows paths.

Current Deployment Phase

NexGen is being prepared for live deployment. Treat deployment-related work as
production-sensitive work.

Current environments:

XAMPP on Windows for normal local development.

Docker Desktop on Windows for production-equivalent local validation.

Railway for the live PHP/Apache service and managed MySQL database.

GitHub as Railway's deployment source.

The intended workflow is:

Make a targeted source change.

Verify it in XAMPP when relevant.

Build the current Dockerfile locally.

Recreate and test the local container.

Inspect HTTP responses, browser console/network errors, and container logs.

Only after local validation, let the developer review, commit, and push.

Verify that Railway built the exact new Git commit rather than redeploying an
older successful deployment.

Do not treat a successful Docker build as proof that the application works.
Verify routing, database access, authentication, assets, CAPTCHA, redirects,
sessions, and authorization at runtime.

Environment URLs and Routing

The same source must support these layouts:

XAMPP application: http://localhost/NexGen/CODE/PHP/

Local Docker application: http://localhost:8080/

Railway application: served from the generated/custom domain root /

Do not hardcode:

http://localhost

the Railway hostname

/NexGen/CODE/PHP in browser-facing routes

Windows filesystem paths in web URLs

CODE/PHP/config.php contains environment-aware routing helpers. Prefer:

nxAppUrl() for PHP pages, handlers, login endpoints, and redirects

nxProjectUrl() for root project assets such as IMAGES

nxCodeUrl() for assets under CODE/JS and CODE/STYLE

For browser requests to a PHP endpoint in the same published directory, a
simple relative URL such as login_process.php or captcha_image.php is also
acceptable when verified in all three environments.

Use **DIR**-anchored filesystem paths for PHP include, require, and
require_once. Do not confuse server filesystem paths with browser URLs.

External JavaScript cannot call PHP helper functions directly. When JavaScript
needs environment-aware paths, inject sanitized base paths from PHP (for
example through window.NEXGEN_PATHS) or use a verified same-directory relative
endpoint. Never inject secrets into browser JavaScript.

Linux containers and Railway are case-sensitive. Match IMAGES, JS, STYLE,
and every filename exactly. Logo.png, logo.png, and logo.PNG are different
files in production.

Before claiming a routing fix is complete, search for stale hardcoded paths and
inspect actual 404 requests in the browser Network panel or Apache logs.

Docker Deployment Contract

The root Dockerfile is the deployment definition. Docker must publish:

CODE/PHP to /var/www/html

CODE/JS to /var/www/html/JS

CODE/STYLE to /var/www/html/STYLE

IMAGES to /var/www/html/IMAGES

The Dockerfile must not reference or require the removed CODE/BACKEND or
VIDEOS directories.

The PHP/Apache image currently requires MySQL support (mysqli, pdo, and
pdo_mysql), Apache URL rewriting, and exactly one Apache MPM. Preserve the
working MPM protection unless a verified replacement is requested.

Useful local validation commands include:

docker build --no-cache -t nexgen-local .
docker ps
docker logs --tail 100 nexgen-local-app

The local application container normally maps host port 8080 to Apache port 80. Docker images are snapshots: after source or Dockerfile changes, stop the
old container, rebuild the image, and create a new container. Restarting the
computer may also require restarting Docker Desktop, XAMPP MySQL, and the local
application container.

Do not assume GitHub integration uploads local uncommitted files. Railway builds
only the commit and branch configured under its service Source settings. Compare
the Railway deployment commit hash with Git HEAD when results appear stale.

Database Environment Contract

Do not hardcode database credentials or copy Railway secrets into source files.
The application service uses:

NEXGEN_DB_HOST

NEXGEN_DB_PORT

NEXGEN_DB_NAME

NEXGEN_DB_USER

NEXGEN_DB_PASSWORD

NEXGEN_ENV=production on Railway

Railway variables should reference the managed MySQL service variables. Local
Docker normally reaches XAMPP MySQL through host.docker.internal and a
password-protected local application user.

Do not modify the Railway database schema merely because an application build
or route fails. Code deployment and database migration are separate operations.
Never commit SQL exports, .env files, database passwords, mail credentials,
API keys, or other secrets.

Uploads and generated files stored only in the application container are
ephemeral. Do not claim uploaded IDs or other user files are production-safe
until a Railway Volume or appropriate external storage is configured and access
controls are verified.

Deployment Acceptance Checks

At minimum, verify the following before declaring deployment ready:

/ loads without a PHP or database error.

/admin_login.php is reachable but protected appropriately.

/captcha_image.php returns the intended CAPTCHA response.

/IMAGES/NGlogo.png and required images return successfully.

Required /JS/... and /STYLE/... files return successfully.

Client and administrator login requests do not post to stale XAMPP-only URLs.

Signup, login, logout, timeout, and redirect flows work.

Database access uses environment variables and the correct port.

Business/tenant isolation and authorization remain intact.

Browser console, Network panel, Apache logs, and container logs show no
unresolved application 404/500 errors.

The latest Railway deployment corresponds to the intended Git commit.

Do not weaken authentication, authorization, CAPTCHA, tenant filtering, session
security, or database validation merely to make deployment appear successful.

Existing Code Is the Source of Truth

Do not assume architecture, database structure, file relationships,
routes, permissions, business rules, or implementation details.

Inspect the repository before making significant changes.

Do not invent:

database tables

database columns

foreign keys

routes

PHP files

JavaScript functions

CSS classes

session variables

user roles

permissions

API endpoints

configuration values

If this CLAUDE.md conflicts with the actual implementation, investigate the
repository and inform the developer about the discrepancy.

Do not silently rewrite working code to match documentation.

General Development Rules

Before modifying code:

Read the relevant existing files.

Understand how the requested feature currently works.

Identify dependencies between PHP, JavaScript, CSS, and MySQL.

Check whether shared files are involved.

Preserve existing functionality unless explicitly instructed otherwise.

Make the smallest reasonable change necessary.

Do NOT rewrite an entire file when a targeted modification is sufficient.

Do NOT modify unrelated modules while working on a specific task.

Do NOT remove working functionality merely because another implementation
appears cleaner.

Do NOT perform unrelated refactoring unless explicitly requested.

Major Application Areas

Known application areas include:

Dashboard

Primary dashboard and analytics interface.

Related files may include:

dashboard.php

dashboard.js

dashboard.css or shared styles

Always inspect the actual dependencies before modifying the dashboard.

Inventory Management

Handles product management, categories, stock quantities, stock movements,
inventory updates, imports/exports, and related inventory operations.

Known files include:

inventory_management.php

inventory_save.php

inventory_update.php

inventory_delete.php

inventory_restore.php

inventory_export.php

inventory_stock.php

inventory_management.js

Do not modify stock behavior without checking how sales and other modules
depend on inventory.

Sales Recording

Handles sales transactions and order processing.

Known files include:

sales_recording.php

process_sale.php

process_sale_ajax.php

sales_recording.js

sales_recording.css

Sales operations may affect inventory and accounts receivable.

Trace those relationships before changing transaction logic.

Sales Analytics

Handles reporting, charts, analytics, and sales summaries.

Known files include:

sales_analytics.php

sales_analytics.js

sales_analytics.css

Preserve existing analytics calculations unless explicitly asked to change them.

Accounts Receivable

Handles receivables, customer balances, payments, and related financial records.

Known files include:

accounts_receivable.php

receivable_payment.php

receivable_view.php

Accounts receivable may interact with sales and customer records.

Do not alter financial calculations without tracing the complete data flow.

Accounts Masterlist

Handles account/employee/partner-related records where applicable.

Inspect the actual implementation and database relationships before making changes.

User and Administrator Management

Known functionality includes:

account registration

login/logout

password reset

OTP verification

user management

registration requests

administrative approval

role and permission management

Known files include:

manage_users.php

pending_requests.php

view_request.php

login_process.php

logout.php

config.php

Authentication and authorization logic must not be weakened.

AI Chatbot

The application includes a rule-based chatbot that uses local PHP logic,
database queries, and permission-aware tools. It does not require an external
language model service.

Known related files include:

chatbot.php
CHATBOT/

Inspect the existing integration before modifying chatbot behavior.

Do not assume the coding model used by Claude Code/FCC is related to the
application's own chatbot implementation.

Database Architecture

The database schema is managed separately from the source repository because
the former CODE/BACKEND directory was intentionally removed. Do not expect or
recreate CODE/BACKEND/nexgen_db.sql. Inspect the live/local database through
approved read-only tooling or use a schema file explicitly supplied by the
developer for the current task.

The database includes application data for areas such as:

users

businesses

products

categories

customers

sales

sale items

accounts receivable

receivable payments

accounts masterlist

registration requests

administrative logs

audit logs

The schema may also contain:

foreign keys

unique constraints

indexes

reporting views

analytics-related indexes

tenant/business relationships

The actual SQL schema is the source of truth.

Before changing database-related code:

Inspect the relevant PHP queries.

Verify the table in nexgen_db.sql.

Verify column names.

Verify data types.

Verify foreign keys.

Check indexes where performance is relevant.

Check related PHP handlers.

Check whether business_id filtering is required.

Consider existing data and backward compatibility.

Do not guess database structures.

Multi-Tenant Architecture

NexGen implements business-level data isolation.

Many records may be associated with a:

business_id

This is security-sensitive behavior.

When modifying database queries:

Determine whether the relevant table is tenant-specific.

Preserve business_id filtering where required.

Preserve business isolation.

Never remove tenant filtering merely to simplify a query.

Never allow one business to access another business's records.

Check tenant_helper.php and related access logic when relevant.

When adding a new query to a tenant-aware module, verify whether business_id
must be included.

A query that returns correct data but bypasses tenant isolation is NOT considered
a valid fix.

Role and Permission System

The application uses role-based and module-level access control.

Do not assume authorization is based only on simple "admin" and "user" roles.

The system may contain granular permissions for functionality such as:

inventory

sales

sales analytics

accounts receivable

Permission fields may include patterns such as:

can_inventory

can_sales

can_sales_analytics

can_accounts_receivable

Verify actual field names from the repository/database before using them.

Before modifying access control:

Inspect existing permission checks.

Inspect session/user information.

Preserve granular permissions.

Preserve administrator restrictions.

Preserve tenant restrictions.

Do not automatically grant new permissions.

Do not bypass authorization to make a feature work.

If a page fails because of permissions, investigate the permission system rather
than removing the check.

PHP Rules

Follow the project's existing PHP patterns unless specifically asked to refactor.

When modifying PHP:

Preserve session behavior.

Preserve authentication logic.

Preserve authorization checks.

Preserve tenant isolation.

Preserve redirects unless intentionally changing navigation.

Reuse the existing database configuration.

Prefer prepared statements for user-controlled database values.

Validate server-side input.

Escape output appropriately.

Check dependent handlers before changing request parameters.

Check both GET and POST usage where applicable.

Do not rename existing:

functions

variables

session keys

database fields

form fields

endpoints

without searching for all usages first.

Do not silently change application-wide behavior from a module-specific task.

MySQL / Database Safety Rules

Database operations require extra caution.

NEVER automatically execute destructive operations such as:

DROP DATABASE

DROP TABLE

TRUNCATE TABLE

large or unrestricted DELETE statements

unless explicitly requested and confirmed by the developer.

Do not modify or destroy user/business data as part of debugging.

Do not automatically execute destructive SQL merely because it would make a
development problem easier to solve.

If a schema modification is required:

Explain why it is required.

Show the required SQL.

Explain whether existing data is affected.

Wait for approval before destructive changes.

When querying tenant-specific data, always verify whether business_id filtering
is required.

Frontend and UI Rules

NexGen uses module-specific CSS and JavaScript.

When modifying frontend code:

Preserve responsive behavior.

Preserve existing functionality.

Preserve dark and light modes.

Follow the existing NexGen design system.

Reuse existing CSS variables.

Reuse existing components where practical.

Avoid unnecessary inline styles.

Avoid duplicate CSS rules.

Do not introduce another frontend framework unless explicitly requested.

Check desktop and mobile layouts when relevant.

Do not redesign unrelated components.

Do not replace working functionality solely for visual consistency.

Design Reference / Module Merging Rules

The developer may provide one existing module as the design/layout reference
for another module.

For example:

"Make Accounts Receivable match the design of Sales."

This means the reference module should normally be treated as a VISUAL and
LAYOUT reference unless functionality is explicitly requested.

When Module A must visually match Module B:

Inspect Module B's PHP/HTML structure.

Inspect Module B's CSS.

Inspect Module B's JavaScript if it controls visual behavior.

Identify reusable design patterns.

Inspect Module A's existing implementation.

Adapt the design to Module A.

Preserve Module A's backend and business logic.

Preserve Module A's database queries.

Preserve Module A's unique functionality.

Do NOT blindly copy:

element IDs

PHP variables

JavaScript variables

event handlers

form actions

database queries

backend handlers

module-specific business logic

from the reference module.

A design reference does not automatically authorize copying its backend behavior.

Dark and Light Mode

Dark/light mode is implemented across the application.

Any frontend modification must consider BOTH themes.

When changing CSS or UI:

Check light mode.

Check dark mode.

Reuse existing CSS variables when available.

Avoid hardcoded colors when theme variables can be used.

Preserve theme switching behavior.

Check shared styles before adding duplicate theme rules.

A frontend change is not considered complete if it works correctly in only one
theme.

JavaScript Rules

Before modifying JavaScript:

Search for existing functions with similar behavior.

Check which HTML/PHP elements depend on the script.

Check shared JavaScript files.

Check whether another module depends on the same function.

Avoid:

duplicate functions

duplicate event listeners

unnecessary global variables

unnecessary inline JavaScript

silently replacing shared behavior

When PHP outputs values into JavaScript, ensure strings are safely encoded and
properly quoted.

For example, dynamic text containing spaces or quotes must not generate invalid
JavaScript such as:

openBookingModal(1, Room A, 20)

Use safe encoding/quoting where required.

When fixing JavaScript syntax errors, find the source generating the invalid
JavaScript rather than only suppressing the browser error.

Security Architecture

NexGen contains existing security mechanisms that must be preserved.

These may include:

authentication

authorization

role-based access control

module-level permissions

tenant isolation

CSRF protection

output escaping

password validation

session security

HTTP-only cookies

CAPTCHA

OTP verification

file upload validation/scanning

activity logging

audit logging

prepared database queries

integrity checks

Do not weaken existing security while fixing unrelated functionality.

Do not alter audit-log hashing, integrity checks, permission checks,
CAPTCHA behavior, or tenant isolation as part of an unrelated change.

CAPTCHA

The application uses an image-based CAPTCHA system.

CAPTCHA assets are stored under:

IMAGES/captcha/

Known categories include directories such as:

signages/

stoplights/

vehicles/

Before modifying CAPTCHA-related code:

Inspect the existing CAPTCHA implementation.

Inspect the available assets.

Preserve server-side validation.

Preserve session/state behavior.

Do not replace security checks with client-side-only validation.

Do not disable CAPTCHA simply to make authentication easier during development.

Session and Authentication Safety

Session behavior is security-sensitive.

Before changing:

session timeout

session regeneration

cookies

login state

logout behavior

authentication redirects

inspect config.php and the relevant authentication handlers.

Do not assume the intended timeout value from documentation alone.

The current implementation is the source of truth unless the developer
explicitly requests a different timeout.

File Upload Security

The application contains file upload functionality.

When modifying uploads:

Validate MIME/type using existing mechanisms.

Preserve dangerous-file detection.

Preserve filename/path safety.

Preserve size restrictions where implemented.

Do not allow executable files simply to fix an upload problem.

Do not bypass existing scanning.

Known upload-related storage exists under:

CODE/PHP/uploads/

Inspect the actual implementation before modifying upload behavior.

Audit and Administrative Logs

The application contains administrative and audit logging.

Some logs may include integrity/hash relationships intended to detect tampering.

When modifying logging:

Preserve existing history.

Preserve hash/integrity relationships where implemented.

Do not rewrite old log records.

Do not bypass logging for sensitive administrative operations.

Check both PHP implementation and database schema.

Preserve tenant/business relationships where applicable.

Do not describe the logging implementation as "blockchain" unless that term is
actually used by the project requirements or implementation.

Prefer precise terms such as:

tamper-evident hash chaining

when appropriate.

Secrets and Credentials

Never expose:

database passwords

API keys

SMTP credentials

access tokens

private keys

secrets

Do not print secrets into responses.

Do not add secrets to:

source code

CLAUDE.md

Git commits

JavaScript

public configuration files

If credentials are discovered in the repository, do not unnecessarily repeat
their values.

Shared Files

Some files are used by multiple modules.

Examples may include:

config.php

header.php

shared CSS

shared JavaScript

tenant_helper.php

theme initialization

authentication/session helpers

Treat modifications to shared files as higher risk.

Before modifying a shared file:

Search for all usages.

Determine which modules depend on it.

Consider possible side effects.

Prefer module-specific changes when appropriate.

Do not change a shared component to solve one page's problem unless the shared
change is actually intended.

Multi-File Changes

Many NexGen features consist of multiple related files.

A feature may involve:

PHP + CSS + JavaScript + MySQL

When implementing a multi-file change:

Locate all relevant files.

Trace the full request/data flow.

Identify shared dependencies.

Understand the existing implementation.

Modify only necessary files.

Keep frontend and backend behavior consistent.

Check for side effects.

Do not assume changing only the visible PHP page is sufficient.

Debugging Rules

When fixing a bug:

Understand or reproduce the error.

Locate the root cause.

Identify the responsible files.

Trace relevant dependencies.

Explain the cause briefly.

Implement the smallest reliable fix.

Check whether the same pattern exists elsewhere.

Verify that security and tenant isolation remain intact.

Do not hide errors without fixing their cause.

Do not disable validation simply because validation is causing an error.

Do not remove permission checks to make a page accessible.

Do not suppress database errors instead of fixing invalid queries.

Git Safety

The developer controls Git operations.

Claude may safely inspect commands such as:

git status

git diff

git log

git branch

Do NOT automatically:

commit

push

pull

merge

rebase

cherry-pick

reset

clean

force push

delete branches

discard changes

unless explicitly instructed.

Never discard uncommitted developer work.

Before making large or risky changes, recommend checking:

git status

The developer should review modifications before committing them.

If Git reports unrelated existing modifications, preserve them.

File Safety

Do not:

delete files without explicit permission

rename large groups of files

move directories

reorganize the repository

overwrite configuration files unnecessarily

replace entire working modules when targeted edits are sufficient

If a task requires deleting, replacing, or moving significant files,
explain the proposed operation before doing it.

Dependency Rules

Do not install new:

npm packages

Composer packages

PHP libraries

JavaScript frameworks

external services

without explaining why they are necessary and receiving approval.

Prefer existing project dependencies.

Do not run:

npm update

automatically as part of unrelated development work.

Dependency upgrades can introduce unrelated changes and must be intentional.

Testing

Do not claim something was tested unless it was actually tested.

There is no assumption that a complete automated test suite exists.

Use the most relevant available checks.

PHP

When possible:

check PHP syntax

inspect request parameters

inspect database queries

verify session behavior

verify redirects

verify authorization

verify tenant filtering

JavaScript

When possible:

check for syntax errors

check browser console errors

verify event handlers

verify dynamic values

check for duplicate listeners/functions

CSS / UI

Check where relevant:

desktop layout

responsive/mobile layout

light mode

dark mode

modal behavior

sidebar/header behavior

Database

Verify:

table names

column names

data types

foreign keys

business_id requirements

affected relationships

Working Style

For simple fixes:

Investigate the cause.

Make the targeted change.

Explain what changed.

Mention what should be manually tested.

For larger changes:

Analyze the relevant implementation.

Identify affected files.

Briefly explain the plan.

Implement the requested changes.

Review the resulting diff.

Summarize the changes.

Mention manual testing steps.

Keep explanations concise unless detailed reasoning is requested.

Do not spend excessive time documenting obvious code.

Focus on completing the developer's requested task safely.

Read-Only Requests

When the developer explicitly says:

"Do not modify any files"

treat the task as strictly read-only.

Allowed actions include:

reading files

searching files

inspecting directories

inspecting Git status/diff/log

analyzing code

Do NOT:

create files

edit files

delete files

run formatting that modifies files

install dependencies

modify the database

commit changes

Command Safety

Before executing a shell command, consider whether it modifies:

source files

Git state

dependencies

database data

configuration

operating system state

Read-only inspection commands are generally acceptable.

Be cautious with commands involving:

rm

del

rmdir

git reset

git clean

git checkout

git restore

DROP

TRUNCATE

DELETE

npm update

package installation

Never execute destructive commands merely to resolve a development issue faster.

Repository Investigation Rules

When searching the repository:

Prefer relative paths when possible.

Use repository search tools before guessing filenames.

Search for usages before renaming functions/classes/IDs.

Search for database references before changing schema.

Search for CSS/JS dependencies before changing shared selectors.

If a command fails because of Windows/Bash path syntax, correct the path rather
than assuming the file does not exist.

Important Final Rules

When uncertain about an important:

business rule

database relationship

permission

tenant relationship

security behavior

architectural decision

investigate the repository first.

If the repository does not provide enough information, ask the developer rather
than guessing.

Preserving existing NexGen functionality is more important than unnecessary
refactoring.

Preserving security is more important than making a quick workaround.

Preserving tenant/business isolation is mandatory.

Preserving existing developer work is mandatory.
