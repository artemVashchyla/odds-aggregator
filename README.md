# Odds Aggregator

A WordPress plugin that compares live odds across roughly ten bookmakers and
renders them as a contextual table inside any post or page.

- **Modular data sources.** Scraper, licensed API and offline sample data all
  sit behind one interface and are swapped from a dropdown.
- **Admin control.** Choose bookmakers, choose markets, and set the outbound
  link for each bookmaker.
- **Gutenberg block.** Pick an event, add or remove bookmakers per block, and
  reorder them.
- **Three odds formats.** Fractional, decimal and American, switchable by the
  reader without reloading the page.
- **Built for performance.** Pages are rendered from cache; stale data is
  served immediately and refreshed in the background via WP-Cron.

See [DOCS.md](DOCS.md) for architecture, extension points and the hook
reference.

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| WordPress | 6.3 or newer |
| Node | 22.22.2 or newer (see `.nvmrc`) |
| Composer | 2.x |
| Docker | only for the bundled local environment |

## Setup

```bash
composer install     # PHP dependencies and the autoloader
nvm use              # switches to the Node version in .nvmrc
npm ci               # build toolchain
npm run build        # compiles the block into build/
```

To run it against a throwaway WordPress install:

```bash
npm run env:start    # http://localhost:8888  (admin / password)
```

The plugin is already active in that environment, and a demo page with three
odds tables is created at http://localhost:8888/odds-demo/ (re-create it with
`npm run env:demo`).

The default data source is *Sample data*, which works offline with nothing to
configure. Switch to The Odds API (free API key from the-odds-api.com) or the
Oddschecker scraper on the *Data source & display* tab.

To stop the environment:

```bash
npm run env:stop
```

## Using it

**Odds Aggregator** in the admin sidebar (its own top-level menu). The screen
opens with the health of the data source: whether it is returning odds, the
last error, events available, how many of your bookmakers and markets it
quotes, and for The Odds API the credits left on your plan. If the source is failing you can switch to sample data or re-check it
in one click. A sidebar previews an odds table. Settings sit in four tabs:

- *Data source & display* — pick a provider (each card lists what it quotes),
  set an API key and region if it needs them, and choose the odds format pages
  open in.
- *Bookmakers* — tick the bookmakers available site-wide, grouped by region and
  marked by whether the current source quotes them, and set the outbound
  (affiliate) URL and logo for each. Most bookmakers ship with an icon; the
  rest show their initials until a logo is set.
- *Markets* — enable Match Result (1X2), Over/Under 2.5 Goals and Both Teams
  to Score.
- *Performance* — cache windows, how often The Odds API is fetched, and the
  outbound rate limit.

Each tab saves independently; saving one never clears the others.

Changing the provider is checked before it is applied. If the chosen source
cannot return odds (a blocked scraper, a missing API key), the change is
rejected with an explanation and the current source is kept.

**In the editor**

Add the **Odds Table** block, then in the sidebar choose an event, narrow the
markets, and add, remove or reorder bookmakers for that block. The editor
preview is rendered by the same PHP as the front end.

**On the front end**

Readers can switch between decimal, fractional and American odds without a
reload (the choice is remembered) and, when a block shows several markets,
filter the grid to one market.

While the page is open, each table re-renders itself from the public
`/wp-json/odds-aggregator/v1/block` route once per fresh window (60 s by
default), so odds stay current even behind a full-page cache.

## Data sources

| Provider | Default | Markets | Notes |
|---|---|---|---|
| Oddschecker | no | Match Result | Scraper. The site is behind Cloudflare and its terms do not permit scraping; use a licensed feed in production. |
| The Odds API | no | Match Result, Over/Under 2.5 | Licensed feed. Needs an API key. One fetch per interval (default three hours) serves every event; only enabled markets are paid for. |
| Sample data | yes | All | Offline fixtures. |

See DOCS.md for how each source is read, cached and rate-limited.

## Commands

```bash
composer lint        # WordPress Coding Standards
composer lint:fix    # auto-fix what can be auto-fixed
composer test        # PHPUnit, unit suite
composer test:coverage     # ...with a coverage report (needs pcov or Xdebug)
composer test:integration  # opt-in: runs against the live feeds

npm run build        # production build
npm start            # watch mode
npm run lint:js      # ESLint (WordPress config)
npm run lint:css     # stylelint (WordPress config)
npm run env:start    # start WordPress
npm run env:demo     # (re)create the demo page
npm run env:stop     # stop WordPress
npm run env:cli -- <wp-cli command>
```

## Building a release

```bash
npm run build
composer dump-autoload --no-dev -o   # production autoloader only
npm run plugin-zip                   # odds-aggregator.zip, files listed in package.json "files"
composer dump-autoload               # restore the dev autoloader
```

`readme.txt` holds the WordPress.org metadata; `.distignore` lists what stays
out for `wp dist-archive`.

## Tests

```bash
composer test
```

Unit tests run as plain PHPUnit without a WordPress installation. `tests/Unit/`
mirrors `src/`, so the tests for a class sit in the directory matching its
namespace. They cover odds conversion and display formats, every provider and
the Oddschecker parser (against a saved page), the transport with its rate limit
and retry policy, caching and locks, the bookmaker catalogue, the domain value
objects, the settings sanitiser and migration, the REST endpoints, and block
rendering.

`composer test:coverage` adds a coverage report, which needs a coverage driver
(`pcov` or Xdebug) installed.

`tests/Integration/` checks the plugin against the real upstream feeds, which is
what catches a feed changing shape. It is opt-in and skips itself without
credentials:

```bash
ODDS_AGGREGATOR_API_KEY=... composer test:integration
```

See the Testing section of DOCS.md for the layout, what is excluded from
coverage and what a live run costs.

## Licence

GPL-2.0-or-later.
