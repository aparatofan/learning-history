# TBT Learning History — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the requested task, not from a full-repository scan.
- Inspect `git status`, then use targeted search and narrow file reads.
- Do not reread the large frontend file unless the task actually touches it.
- Do not modernize legacy code, rename files, replace libraries, or rewrite stored data unless the task explicitly requires it.
- Keep changes small and compatible with the live TBT WordPress/Divi site.

## What this plugin does

- This is a TBT learning-history plugin, although the WordPress plugin name remains `WP Custom Calendar` for compatibility.
- It records lessons completed by logged-in users and displays those lessons in a personal calendar/history view.
- Main bootstrap: `wp-custom-calendar.php`.
- PHP behavior: `inc/`.
- TBT/custom frontend CSS and JS: `custom/`.
- Bundled/library frontend assets: `lib/`.
- The plugin source lives at the repository root; there is no nested deployable plugin directory.

## Data model and compatibility

- Completion records live in the custom `$wpdb->prefix . 'wpcc_tracking'` table.
- A completion belongs to one WordPress user (`customerID`) and one lesson/post (`post_id`).
- Preserve the effective one-user/one-post completion rule. Do not intentionally create duplicate history records.
- Existing historical rows are important user data. Never delete, backfill, bulk-update, or migrate them as incidental cleanup.
- Several legacy inconsistencies are deliberately repaired at **read time** so old records benefit without rewriting the database. Preserve that approach unless a migration is specifically requested.
- `getprivate_event_callback()` resolves current lesson/category display data and deduplicates legacy rows; do not replace that with blind trust in old stored values.
- Lesson completion uses the `complete_chapter_report` AJAX action and the `wpcc_complete_chapter` nonce.

## WordPress and security

- Keep all writes scoped to the current logged-in user.
- Authenticated writes must keep nonce checks and user checks.
- Validate post IDs before storing completion data.
- Sanitize request data, escape rendered output, and use `$wpdb->prepare()` for SQL containing values.
- Do not expose another user's learning history through AJAX or frontend output.
- Do not weaken access checks merely to make an old interaction easier to reproduce.

## Frontend behavior to preserve

- The completion action on a lesson page and the personal history/calendar are two sides of the same feature; changes to one may affect the other.
- The calendar uses FullCalendar-era bundled assets and legacy custom JS/CSS. Do not replace or upgrade those libraries during an unrelated task.
- Event titles/icons may be normalized dynamically for old rows. Keep compatibility with historical records.
- Divi cache/static-CSS clearing is intentional where already wired; do not remove it without verifying live-site behavior.
- TBT is desktop-first. Do not launch an unsolicited responsive/mobile redesign.

## Coding style

- Follow the surrounding WordPress/PHP style rather than reformatting whole files.
- Preserve the existing `wpcc_` public function/action names unless a breaking change is explicitly requested.
- Prefer a small local fix to a broad refactor in this legacy plugin.
- Avoid adding dependencies or build tooling unless the requested feature genuinely needs them.
- Treat odd legacy names such as `custome-calendar-frontend.php` as compatibility paths; do not rename them incidentally.

## Validation

- Run `php -l` on each changed PHP file.
- For a completion-flow change, verify at minimum: logged-out rejection, nonce handling, first completion, duplicate completion, and calendar retrieval for the same user.
- For calendar/display changes, verify existing historical rows still render and another user's records are not returned.
- If WordPress/Divi behavior cannot be tested locally, state exactly what still needs live-site/browser verification.

## Git and deployment

- `main` is the integration branch; do not commit directly to it unless the user explicitly requests that.
- Use a focused feature branch and descriptive commits.
- Before finishing, inspect the final diff for unrelated legacy cleanup.
- Every push to `main` triggers the FTPS deployment workflow.
- The workflow deploys the repository root to `/wp-custom-calendar/` and excludes Markdown, `.git`, `.github` and similar non-plugin files from upload.
- Never change FTP secrets or deployment paths unless the task is specifically about deployment.

## Context discipline

- Prefer targeted search + selective reads over broad exploration.
- Do not paste large legacy files or long logs into the conversation when a short summary is enough.
- Read deeper implementation files only when the task points there.
- Finish with a short summary of what changed, what was checked, and any remaining manual verification.
- For a new unrelated task, favor a fresh Claude Code session rather than carrying a long old conversation forward.
