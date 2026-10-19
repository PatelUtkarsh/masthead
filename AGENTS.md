# AGENTS.md

Masthead is a WordPress plugin: kanban publishing workflows in wp-admin. A card is a post; every stage maps to a core `post_status`. Targets **PHP 8.4+ and WordPress 7.0+**. Commands, layout, CI and the release flow are in `README.md`; `package.json` and `composer.json` scripts are the source of truth for commands.

The product spec (`docs/specs.md`, prototype `docs/Masthead.dc.html`) is local only: `docs/` is gitignored. Where it exists, the spec wins on behaviour and the prototype on visuals.

## Invariants

- **Rules live on the server.** Every stage change goes through `Rules::validate()` / `Rules::move()`. UI checks are feedback only. `Guard` applies the same rules to REST (`rest_pre_insert_*`), Quick Edit and other screens (`wp_insert_post_data`).
- **Masthead's own writes run inside `Sync::applying()`.** This re-entrancy flag stops `Sync` and `Guard` from reacting to Masthead's writes. Wrap any new internal `wp_update_post()` in it.
- **Stages are terms, not meta.** Stages live in the internal `masthead_stage` taxonomy, one term per workflow stage, with the slug `{workflow}__{stage}`. Read and write one post with `Meta::stage()` / `Meta::set_stage()`. Use `StageTaxonomy` (`clause()` for `tax_query`, `set_bulk()`, `post_ids()`, `counts()`) for queries and bulk work. Per-stage totals come from the term `count` column, which counts active statuses through the `update_post_term_count_statuses` filter. REST exposes the stage as the read-only field `masthead_stage`.
- **IDs are stable slugs.** Stage IDs and checklist item IDs are slugs. Never key anything by array position.

## Scale

Assume sites with hundreds of thousands of posts and thousands of users.

- **Lazy by default.** Ship no user or category lists with the page. Pickers, filters and @mentions search on demand: `searchUsers()` / `useUser()` in `src/common/users.js`, `UserPicker` / `CategoryPicker` in `src/common/pickers.js`, and `GET /masthead/v1/users`.
- **No row counts on hot paths.** Page by fetching one extra row and returning `has_more`; skip `COUNT(*)` and `SQL_CALC_FOUND_ROWS`. Board columns load 20 cards at a time.
- **Bulk work.** Use set-based SQL (`StageTaxonomy::set_bulk()`, `Sync::set_stage_bulk()`) with one recount. Do a bounded batch inline and send the rest to WP-Cron (`Cron::queue_*`).

## Frontend

- **Prefixed CSS.** Classes use the `mh-` prefix. Design tokens are CSS variables set on `.mh-app`, `.mh-root`, `.mh-dash` and `.mh-editor`. Anything portalled to `body` needs a `.mh-root` wrapper to get the tokens.
- **Stroke icons.** `@wordpress/icons` are stroke-based. Colour them with `color`, not `fill`.
- **Dates.** The browser's timezone can differ from the site's:
  - Format date-times on the server with `wp_date()`.
  - Parse `Y-m-d` strings with `parseYmd()`.

## Testing

- **Where tests run.** Run tests in wp-env (`npm run test:php`, `npm run test:e2e`). The minimum versions are pinned in `.wp-env.json`.
- **Shared database.** PHPUnit and the e2e site share the wp-env tests database, and PHPUnit's install deactivates the plugin. The e2e helper `ensureTeam()` re-activates it. Keep that call when you add specs.
- **Static caches.** PHPUnit disables move transactions (`masthead_use_transactions`), because each test runs inside a transaction that it rolls back. Static caches must reset between tests: `Workflows::flush()` also clears `StageTaxonomy`.
- **Lint must be clean.** PHPCS, ESLint and Stylelint must report zero problems; CI fails on PHPCS warnings.

## Git and releases

- **`build/` is never committed to `main`.** Releases add it only in a tagged release commit, so Composer installs from tags get it.
- **How to release:**
  1. Bump the version with `bin/bump-version.sh X.Y.Z` in a pull request.
  2. After the merge, run the **Release** workflow on `main`.
- **Branches and commits.** Work on feature branches and open pull requests into `main`. Use conventional commits: `type(scope): Subject`.
