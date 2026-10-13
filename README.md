<p align="center">
  <img src="assets/logo.webp" alt="com_fgsqlreports logo" width="128" height="128">
</p>

<h1 align="center">FG SQL Reports for Joomla</h1>

<p align="center">
  <img src="https://img.shields.io/github/v/release/FGcodework/com_fgsqlreports?color=FF6B4A&label=release" alt="Latest release">
  <img src="https://img.shields.io/badge/Joomla-5.4%2B%20%7C%206.x-1a6877.svg?logo=joomla&logoColor=white" alt="Joomla">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-purple.svg?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/license-GPL--2.0-green.svg" alt="License">
  <img src="https://img.shields.io/github/downloads/FGcodework/com_fgsqlreports/total?cacheSeconds=3600&color=brown" alt="Downloads">
  <a href="https://ko-fi.com/FGcodework"><img src="https://img.shields.io/badge/support-Ko--fi-F16061.svg?logo=ko-fi&logoColor=white" alt="Support on Ko-fi"></a>
</p>

A native Joomla component (namespaced MVC, PSR-4) that displays reports
from an MS SQL Server database on the front end. One SQL script = one report, rendered as a
table.

Built for deployment on a local network: Joomla runs on one local server
(an intranet), the MS SQL database on another local server.

## Features

- Admin editor with a live "Run Preview" that executes the script against
  the configured connection before you ever save anything
- Front-end list of all reports the logged-in user is allowed to see,
  plus a single-report view
- Menu item type with a report picker ("Jeden report" / Single Report),
  so you can link directly to one specific report
- Per-report Joomla Access Level (Public / Registered / custom levels)
- Optional per-report result caching (Joomla cache, TTL in minutes)
- Front-end pagination and click-to-sort column headers, both computed
  in PHP over the already-fetched/cached result set - a cached report
  doesn't re-hit MS SQL just because someone changes page or sort order
- Drag-and-drop ordering in the admin report list
- Optional per-report "Stack on mobile" toggle and an "Extra CSS
  Class(es)" field, for integration with responsive-table plugins like
  `plg_system_fgresponsivetables`
- Encrypted connection password (libsodium, key derived from Joomla's own
  site secret) - the password field never round-trips the stored value
  into the page HTML
- Separate connection and query timeouts, plus a global row cap (PHP-side
  memory safeguard) so a report without a restrictive WHERE/TOP can't
  exhaust memory - shows a warning instead of a silently incomplete result

## Requirements

- Joomla 5.4+ or 6.x (developed against 6.x APIs, but actively running on
  5.4.8 in production - not tested below 5.4), **with Joomla itself
  running on MySQL/MariaDB** (the
  component's own schema ships as a MySQL variant only - PostgreSQL is
  not currently supported, since I have no way to test against it)
- PHP 8.1+ with the **pdo_sqlsrv** extension enabled (Microsoft Drivers
  for PHP for SQL Server -
  https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server)
- Network access from the Joomla server to the MS SQL Server (usually
  port 1433)
- Recommended: a dedicated MS SQL login with **SELECT-only** permission
  on the tables/views you want to report on

This requirement is about the database Joomla itself stores its own
content in (`#__fgsqlreports_reports` and everything else) - it has nothing
to do with the reported-on MS SQL Server, which is always MS SQL
regardless of what Joomla runs on.

## Installation

1. Install the release ZIP via Extensions → Manage → Install.
2. Go to **Components → FG SQL Reports → Options** and fill in the MS SQL
   connection (server, port, database, login, password).
3. Use the **Test Connection** button (in the reports list) to confirm it
   works.
4. Create a new report (Components → FG SQL Reports → New), paste a SQL
   script (a single `SELECT`), check it with **Run Preview**, set an
   Access Level, and publish it.
5. On the front end, add a menu item of type **FG SQL Reports → Reports**
   (a list of every report the logged-in user may see) or **Report** (one
   specific report, chosen from a dropdown in the menu item's Required
   Settings).

### Upgrading from `com_fgreports` (before 2.0.0)
The component was renamed to `com_fgsqlreports` in 2.0.0. Joomla treats a
different element name as a different extension, so this is a **new
install, not an update**:

1. Install `com_fgsqlreports_v2.0.5.zip` while the old component is still
   installed. Your reports and Options (including the already-encrypted
   password) are copied over automatically.
2. Re-create your front-end menu items with the new component (old ones
   point to `com_fgreports`) and re-check the `fgsqlreports.execute`
   permission and component permissions in Options → Permissions.
3. Uninstall the old `com_fgreports`.

## Security - important

A report runs exactly as written - no query builder, no parsing. The
actual protection against unwanted writes/deletes is:

1. **A read-only MS SQL login** configured in Options - this is the
   **only real** security boundary, not anything in this component.
   Ideally grant it `SELECT` on specific reporting views/tables, not a
   broad role like `db_datareader`, unless you genuinely want a report to
   be able to reach every table in the database.
2. Editing/creating reports (i.e. direct access to running arbitrary SQL)
   is gated in Joomla's ACL by `core.edit`/`core.create` on
   `com_fgsqlreports` - Super Users by default. Adjust this in Users →
   Access Levels if you want to let other trusted groups in. The
   interactive "Run Preview" button additionally requires the
   `fgsqlreports.execute` action, since being able to create/edit a report
   and being able to run ad-hoc SQL interactively are different levels of
   trust. Changing a report's status or access level requires `core.edit.state`, and changing the SQL of an already published report requires `fgsqlreports.execute` too, so a user who can edit reports cannot publish or alter a live report's SQL on their own.
3. Both on Save and on Preview, the component shows a **non-blocking
   warning** (nothing is ever refused) if the script contains words like
   `INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `EXEC`, `BACKUP`,
   `DBCC`, `WAITFOR`, `INTO`, etc. This is a quick heads-up for the admin,
   **not a security check** - it has false positives (e.g.
   `SELECT 'UPDATE' AS Operation`) and gaps (it won't reliably catch
   every way to write data). Don't skip point 1 just because this warning
   stayed quiet.
4. The database password is stored **encrypted** in `#__extensions`
   (libsodium, key derived from `$secret` in `configuration.php`), never
   in plain text. The Password field in Options never shows the stored
   value - it's always empty; leave it empty to keep the current
   password, or type a new one to replace it. Note: if the site's
   `$secret` ever changes, a previously saved password can no longer be
   decrypted and needs to be re-entered in Options.

## Cache

Each report has its own **Cache (minutes)** field. `0` = the query runs
on every page view. Any other value caches the result for that many
minutes (Joomla cache, group `com_fgsqlreports`), so the database isn't hit
on every visit.

**Important:** enabling cache means the report's result is physically
stored as a second copy of the data - in Joomla's own cache (a file on
disk, a database table, or Redis, depending on your Joomla cache
configuration), not just in the MS SQL Server. For reports with sensitive
data you don't want duplicated outside the source database, leave Cache
at `0`.

## Access Levels (ACL)

Each report has a standard Joomla **Access** field (Public / Registered /
Special / custom levels). Both the front-end list and a direct report
view always check the logged-in user's `getAuthorisedViewLevels()`.

## Pagination & sorting

Front-end pagination and column sorting both operate on the full,
already-fetched (and, if enabled, cached) result set - no `OFFSET/FETCH`
or `ORDER BY` is injected into the report's own SQL script. Page size can
be set globally (Options → Execution) or overridden per report.

## Known limitations / roadmap

- Reports don't yet support front-end parameters/filters (e.g. a date
  range) - the `params` column on `#__fgsqlreports_reports` is already
  reserved for this, so adding it later won't need a schema change.
- Only one global MS SQL connection is supported (shared by every
  report).
- Only the script's first result set is used.
- No PostgreSQL schema for Joomla's own database (see Requirements).
- No alias-based front-end routing yet - report URLs use `id`, not the
  report's `alias`.

## License

GNU General Public License version 2 or later - see LICENSE.txt.
