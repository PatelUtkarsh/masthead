# Masthead

Kanban publishing workflows for wp-admin. A card is a post, and every stage maps to a core post status.

Requires WordPress 7.0+ and PHP 8.4+.

## Development

```sh
npm install
composer install
npm run build          # or: npm start
npm run env:start      # wp-env: PHP 8.4, WordPress 7.0 (http://localhost:8888, admin / password)
npm run test:php       # PHPUnit (rule engine, sync, REST) inside wp-env
npm run test:e2e       # Playwright acceptance tests against the tests site (http://localhost:8889)
composer lint          # PHPCS (WordPress-Extra + PHPCompatibilityWP)
npm run lint:js
```

## Layout

- `masthead.php`: bootstrap and autoloader (`Masthead\` → `includes/`).
- `includes/`: PHP. `Rules` (rule engine), `Sync` (status sync), `Guard` (enforcement on REST, Quick Edit and other screens), `Rest/*` (REST API `masthead/v1`), `Notifications`, `Activity`, `Notes`, `ListTable`, `Dashboard`, `Cron`, `Capabilities`, `Install`.
- `src/`: React entry points `admin-app`, `editor-plugin`, `dashboard-widget`, `list-table`, `admin-bar`, plus the `masthead/core` store.
- `tests/php`: PHPUnit. `tests/e2e`: Playwright.

## Notes

- Blocked status changes from Quick Edit show the rule message in the Quick Edit row. Other non-REST screens keep the old status and show an admin notice.
- The editor locks saving (`lockPostSaving('masthead')`) only while the pre-publish panel is open and a check fails, so drafts can still be saved.
- "View as" in the admin bar is available only when `WP_DEBUG` is on, only to administrators, and it can only remove capabilities.

## Large sites

Stages are stored as terms of an internal, non-public taxonomy (`masthead_stage`, one term per workflow stage, slug `{workflow}__{stage}`). Term relationships are indexed by term, so a board column reads only the posts in that stage, and the term count column (counting draft, pending, future, publish and private) gives per-stage totals without a query. REST responses expose the stage as the read-only field `masthead_stage`. On 176k posts this cut the six-column board query from about 800 ms (post meta) to about 140 ms.

Masthead also loads data on demand and avoids `COUNT` queries on hot paths:

- No user or category lists ship with the page. Assignee, reviewer, board filters, the assignee avatar filter and @mentions search `/masthead/v1/users` (or core `/wp/v2/categories`) 20 at a time.
- Lists page by fetching one extra row (`has_more`) instead of `SQL_CALC_FOUND_ROWS`. Board columns load 20 cards and fetch the next page when you scroll to the end. Filtered columns show "20+".
- Per-stage totals (used for WIP limits) come from the stage terms' count column. Board counts load only when the board switcher opens.
- Notifications insert in one multi-row query per 500 recipients. Mentions are capped at 20 people per note.
- Setup import, stage deletion and Undo write stage terms with set-based SQL and recount once. Status changes for more than 100 posts, imports past 500 posts, checklist clean-up and the morning digest run in WP-Cron batches.
