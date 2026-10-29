# Odds Aggregator — developer documentation

Architecture, extension points and reference. For installation and usage see
[README.md](README.md).

---

## 1. Layout

```
odds-aggregator.php        Bootstrap: constants, guarded autoloader, activation hooks
bookmakers.json            Shipped bookmaker catalogue, read by BookmakerRegistry
blocks/odds-table/         Block source (JS/SCSS), compiled into build/
build/                     Compiled block assets (committed)
src/
  Plugin.php               Singleton: builds services, registers hooks
  Domain/                  Immutable value objects and the two registries
  Odds/                    Conversion maths, display formats, cached repository
  Providers/               Data sources behind one interface, plus the factory
  Http/                    Transport, rate limiting, retry/backoff
  Cache/                   Two-tier cache and the WP-Cron warmer
  Admin/                   Settings screen, source status, typed settings access
  Rest/                    REST endpoints (editor, live refresh)
  Blocks/                  Block registration and server-side rendering
assets/                    Settings screen CSS and JS
assets/logos/              Bundled bookmaker icons
tests/Unit/                PHPUnit tests, mirroring src/ (no WordPress required)
tests/Integration/         Opt-in tests against the live feeds
tests/fixtures/            Saved upstream pages the parsers are tested against
```

Everything under `src/` is PSR-4 (`OddsAggregator\` → `src/`), which is why
filenames are `ClassName.php` rather than the WordPress `class-name.php`
convention; the `WordPress.Files.FileName` sniff is excluded for that reason.

---

## 2. Request flow

Rendering a page containing the block:

```
render_callback
  └── OddsTableRenderer::render()
        ├── OddsRepository::find_event() → events()
        └── OddsRepository::odds()
              ├── fresh cache hit  → return, no network
              ├── stale cache hit  → return, queue a background refresh
              └── miss             → take a lock, fetch once, store
                                       └── ProviderFactory::create_or_fallback()
                                             └── ProviderInterface::fetch_odds()
```

`events()` follows the same three paths. Odds are always read for the
site-wide market set, the key the cron warmer fills, and narrowed to the
block's markets at render time.

A page view performs outbound HTTP only on a cold cache (first view after
activation, a settings change, or an event the warmer has not reached). Stale
data is served immediately and refreshed through WP-Cron.

---

## 3. Design patterns

### Singleton — `Plugin`

One instance, reachable from hooks, cron callbacks and REST handlers without a
global. It builds services lazily and registers hooks, and holds no logic of
its own.

```php
\OddsAggregator\Plugin::instance()->repository();
```

### Factory — `ProviderFactory`

Callers ask for a provider by id and never name a concrete class:

```php
$provider = $factory->create( 'oddschecker' );   // throws on unknown id
$provider = $factory->create_or_fallback();      // degrades to sample data
```

`create_or_fallback()` is what the front end uses: a misconfigured or
key-less provider yields sample data rather than a fatal error or an empty
page.

### Strategy — `ProviderInterface`

Scraper, API client and fixture provider are interchangeable. Adding a source
touches no existing class.

### Observer — `odds_aggregator_odds_updated`

Fired after every successful refresh with the fresh rows, the event id and the
provider id. Subscribe to it to write a price history, push updates or send
alerts.

### Registry — `BookmakerRegistry`, `MarketRegistry`

Filterable catalogues. Adding a bookmaker or a market is a data change.

`BookmakerRegistry` reads its defaults from `bookmakers.json` in the plugin
directory, so the catalogue can be reviewed, diffed or regenerated without
touching PHP. Each entry is keyed by bookmaker id:

```json
{
	"bet365": {
		"name": "bet365",
		"url": "https://www.bet365.com",
		"region": "uk",
		"logo": "bet365.png"
	}
}
```

`url` and `logo` are starting points that administrator settings override;
`logo` is a filename inside `assets/logos/`, and an empty one draws a monogram
instead. The file is decoded once per request. An entry with no `name` is
skipped, and a missing or malformed file leaves the catalogue empty rather than
raising an error — the block then renders no columns, which is visible and
fixable, whereas a fatal would take the site down. The
`odds_aggregator_bookmakers_file` filter points the registry at a different
file.

### Value Object — `Domain/`

`Event`, `Market`, `Outcome`, `Bookmaker`, `Odds`, `OddsLine` are immutable and
free of WordPress calls, which is what makes them unit-testable without a
WordPress install. `Bookmaker::with_url()` returns a copy rather than mutating.

---

## 4. Odds conversion

`OddsConverter` is pure static maths. **Decimal is canonical**: providers
normalise to decimal on the way in, and conversion happens only at the display
edge. Chaining conversions would accumulate rounding error, so it never
happens internally.

```
fractional → decimal   n/d + 1
decimal → american     d >= 2 ? (d-1)*100 : -100/(d-1)
american → decimal     a > 0 ? a/100 + 1 : 100/|a| + 1
decimal → fractional   closest price on the bookmaker ladder
```

Decimal → fractional is the only non-obvious one. Bookmakers quote fractions
from a fixed ladder (`… 4/6, 8/11, 4/5, 5/6, 10/11, evens, 21/20, 11/10 …
6/4 … 5/2 … 100/1 …`), written their way (`4/6`, `6/4`, `85/40`), not reduced.
`OddsConverter::FRACTION_LADDER` holds that ladder and the converter returns the
step closest to the decimal price, so 2.04 renders as `21/20` rather than
`26/25`, and 3.47 as `5/2` rather than `247/100`. Ties go to the lower step.

Invalid input throws `InvalidArgumentException`: a decimal of 1.0 or less pays
nothing, a zero denominator is not a price, and American odds between -100 and
+100 do not exist.

The same algorithm is mirrored in `blocks/odds-table/view.js` so the front-end
switcher produces identical output. The two implementations are intentionally
kept small and side by side; the known-values table in
`tests/Unit/OddsConverterTest.php` is the shared reference.

---

## 5. Performance

**Stale-while-revalidate.** `OddsCache` holds two windows: *fresh* (60s by
default) and *stale* (600s). Inside the fresh window a read is a plain hit.
Past it the payload is still served — stale odds beat an empty table and beat
blocking a page render on a scrape — while a refresh is queued through
WP-Cron. Only past the stale window does a read miss.

**Single-flight locking.** On a miss, `acquire_lock()` ensures one process
fetches while concurrent requests return empty and pick the data up on their
next view. `Cache\Lock` uses `wp_cache_add()` with a persistent object cache
and an `INSERT IGNORE` into the options table otherwise.

**Cron warming.** `CacheWarmer` refreshes the event list and the odds of every
listed event every five minutes, so the cache is rarely cold. Both bundled
networked providers read a whole league per upstream request and cache it, so
a run costs one request however many events there are. Outbound volume is
bounded by the rate limiter.

Cache storage prefers a persistent object cache and falls back to transients.
Invalidation is **version-based**: `flush()` bumps a stored counter that
namespaces every key, orphaning the whole set in one write instead of tracking
and deleting keys individually.

Other measures: block assets load only on pages containing the block (standard
`block.json` behaviour); the format switcher recomputes from `data-decimal`
attributes already in the markup rather than refetching; and settings live in
a single option row.

**Live refresh.** Each rendered table carries `data-refresh-url` (the public
`/block` route with the block's attributes) and `data-refresh` (the fresh
window in seconds, at least 30). While the tab is visible, `view.js` fetches
that URL on that interval and swaps the table's content, keeping the reader's
format. The route renders from the same cache and sends
`Cache-Control: public, max-age=<fresh window>`, so prices stay current behind
a full-page cache and a CDN can absorb the polling.

---

## 6. Rate limits and data accuracy

**Rate limiting.** `RateLimiter` is a token bucket persisted in a transient and
updated under a `Cache\Lock`, so the limit holds across concurrent PHP
requests. Short bursts are allowed; the sustained rate is capped.

**Backoff.** `RetryPolicy` retries only genuinely transient failures (408, 429,
500, 502, 503, 504, and transport errors) with exponential backoff and full
jitter. An upstream `Retry-After` header is obeyed.

**Accuracy.** Upstream bookmaker names are normalised through an alias map
(`whill`, `willhill`, `William Hill` → `williamhill`). Any value that does not
parse into a plausible price — outside 1.01 to 1000 — is dropped as a parse
error rather than displayed, enforced in `Odds`'s constructor so an implausible
price cannot exist as an object. Every price carries its fetch timestamp, and
the table prints "Odds updated N ago".

**How the Oddschecker scraper reads data.** Oddschecker renders its pages
server-side and embeds the data it rendered from as JSON in the HTML, so the
scraper never parses table markup:

| Script tag | Contents |
|---|---|
| `<script id="cx-island-data">` | React Query state. Matches are in the query whose key starts with `matches-coupon`, under `state.data.subevents[]`. |
| `<script id="cx-bookmakers-store">` | Bookmaker directory: code → brand (`U1` → FanDuel). |

Each match carries `markets[]` → `bets[]` (outcome, `genericName` HOME / DRAW /
AWAY) → `odds[]` (`bookmakerCode`, `decimal`, `fractional`, `american`,
`status`). `OddscheckerParser` extracts this, keeping only `ACTIVE` prices;
`OddscheckerProvider` maps codes to bookmaker ids (`J3` → bet365, `U1` →
fanduel, …) and `betTypeId 1` to the 1X2 market.

One GET of the league page (default
`https://www.oddschecker.com/us/soccer/premier-league`) returns every upcoming
match with every bookmaker's 1X2 price. The parsed page is kept for 60 seconds
(in a transient), so listing events and pricing all of them
costs one upstream request, not one per event. The listing page carries 1X2
only; Over/Under and BTTS rows are therefore empty for this provider and the
block leaves those tables out.

**Limitations.** Oddschecker is behind Cloudflare; when a challenge page is
served instead, the parser finds no data, throws `ProviderException`, and the
repository keeps serving the last cached odds. Scraping is contrary to
Oddschecker's terms of service; production sites should use a licensed feed
such as `OddsApiProvider`.

In practice Cloudflare usually answers a server-side request with **HTTP 403**
before any page is served. The provider turns that into an explicit message -
"Oddschecker refused the request (HTTP 403): the site blocks server-side
requests with Cloudflare bot protection…" - which the settings screen shows when
the source is selected, so the administrator sees why it cannot be used rather
than a bare status code. The source stays in the list, and the saved page in
`tests/fixtures/` proves the parser against real data.

**The Odds API.** One request to the bulk odds endpoint returns the whole
league with every bookmaker's prices, so listing and pricing all events costs
one request. The feed is metered (one credit per market per region), so the
payload TTL, not the cron cadence, governs spend: the payload is kept for the
interval set on the Performance tab (default 10800 s, minimum 600;
`odds_aggregator_oddsapi_ttl`), which keeps two markets in one region at about
480 credits a month, within the free plan. Only the markets enabled site-wide
are requested (`h2h` alone if none of them is carried, so events still list).
The cache key includes the region, the market list and a hash of the API key,
so a newly entered key is never validated against the old key's payload. A
rejected request is remembered for five minutes instead of being retried on
every cron tick, and the `x-requests-remaining` / `x-requests-used` headers are
stored for the health panel. Error messages never include the request's query
string, which carries the API key.

---

## 7. The admin screen

A top-level menu (`admin.php?page=odds-aggregator`, `dashicons-chart-line`,
`manage_options`). The screen opens with a health panel built by
`Admin\SourceStatus`: whether the active source is returning odds, the last
error and when it happened, and key figures (events, bookmakers and markets the
source quotes, cache timings, and for The Odds API the credits left as the feed
last reported them). When the source fails, the panel offers
**Switch to Sample data** and **Check again**, both `admin-post.php` actions
with a nonce and a `manage_options` check.

Below it, four tabs — **Data source & display**, **Bookmakers**, **Markets**,
**Performance** — each a separate `options.php` form for the *same* option.
Tabs are switched client-side (`assets/admin.js`, ARIA tabs, arrow keys) and
addressed by the URL fragment (`#bookmakers`); each form's
`_wp_http_referer` carries its fragment, so a save returns to the same tab.
Without JavaScript all four forms show one after another. Coverage hints come
from providers: `AbstractProvider::supported_markets()` and
`supported_bookmakers()` (null when unknown).

### The tab contract

A tabbed screen posts only the fields on the tab being saved, so a sanitiser
that rebuilt the whole option from the submission would wipe every other tab.
Two rules make that safe:

1. `Settings::tab_fields()` maps each tab to the fields it owns. A unit test
   asserts the map partitions every key in `Settings::defaults()` exactly once,
   so a new setting cannot be added without being assigned a tab.
2. The form posts a hidden `_tab`. Without it, "this tab was not submitted" and
   "every checkbox on this tab was unticked" are indistinguishable - both send
   nothing - and clearing a checkbox group would be impossible.

`Settings::sanitize()` therefore starts from the stored value and overlays only
the submitted tab's fields. An unrecognised `_tab` falls back to treating every
field as submitted, which keeps programmatic writes working.

### Changing the data source is verified

`SettingsPage::guard_provider_change()` hooks `pre_update_option_*` and probes
the chosen provider once, but only when the provider changed or, for The Odds
API, when the API key or region changed - so unrelated edits never pay for a
network call. The hook fires before the row is written, so the probe is built
from the submitted values (`Settings::with_overrides()` and
`ProviderFactory::for_settings()`); a key and the source that needs it can be
saved in one step. A source that cannot answer is rejected and the previous one
is kept; a replacement key that fails is rolled back to the previous key and
region. A source that answers with no events is accepted with a warning, since
a licensed feed is empty between fixtures.

### Blocks survive a change of data source

A block stores an event id, and every provider has its own ids (The Odds API
uses a hash, Oddschecker a number, sample data a slug). Without help, every
block would go blank the moment the source changes. `OddsRepository` keeps an
**event index** - the `odds_aggregator_event_index` option, not autoloaded,
capped at 300 events - recording every event any source has listed. It is not
part of the cache, so the flush on a provider change leaves it intact.

`find_event()` resolves a block's id in three steps:

1. the id itself, if the active source lists it;
2. otherwise the indexed event's **fixture** - teams and kick-off within two
   days, via `Event::is_same_fixture()` - under the active source's own id.
   Names are compared loosely: "Chelsea FC" = "Chelsea", "Man Utd" =
   "Manchester United", "Leeds" = "Leeds United";
3. otherwise the provider may **adopt** the indexed event
   (`AbstractProvider::adopt_event()`). Only sample data does, because only
   sample data can price a match it does not list. Its tables then say
   "Sample odds for preview only" instead of the update time, so sample prices
   never pass for live ones next to a real fixture name.

An id that no source has listed since the index existed still cannot be
resolved; the block shows a notice, and administrators see why.

### Settings migration

`Settings::upgrade()` runs on boot and is versioned by the
`odds_aggregator_schema` option. Schema 2 replaces the bookmaker list only when
it is exactly the version 1 default.

### Adding a setting

1. Add it to `Settings::defaults()`.
2. Add its key to the owning tab in `Settings::tab_fields()`.
3. Add a `case` to `Settings::sanitize_field()` and render its input in the
   tab's `render_*_section()` method of `SettingsPage`, named
   `odds_aggregator_settings[<key>]`.

Skipping step 2 fails the partition test rather than silently losing data.

---

## 8. Extending

### Add a bookmaker

To ship one with the plugin, add an entry to `bookmakers.json` and drop its icon
in `assets/logos/`. To add one from a theme or another plugin, filter the
catalogue instead:

```php
add_filter( 'odds_aggregator_bookmakers', function ( array $bookmakers ): array {
    $bookmakers['newbook'] = new \OddsAggregator\Domain\Bookmaker(
        'newbook',
        'New Book',
        'https://example.test/affiliate'
    );

    return $bookmakers;
} );
```

Then map whatever the upstream source calls it:

```php
add_filter( 'odds_aggregator_bookmaker_aliases', function ( array $aliases ): array {
    $aliases['newbooksportsbook'] = 'newbook';

    return $aliases;
} );
```

### Add a market

Outcome count is not assumed anywhere, so a two-way or five-way market needs no
other changes:

```php
add_filter( 'odds_aggregator_markets', function ( array $markets ): array {
    $markets['dc'] = new \OddsAggregator\Domain\Market(
        'dc',
        __( 'Double Chance', 'my-theme' ),
        array(
            new \OddsAggregator\Domain\Outcome( 'hd', '1X' ),
            new \OddsAggregator\Domain\Outcome( 'ha', '12' ),
            new \OddsAggregator\Domain\Outcome( 'da', 'X2' ),
        )
    );

    return $markets;
} );
```

Your provider then needs to return prices keyed by those outcome keys.

### Add a provider

Implement `ProviderInterface` (or extend `AbstractProvider` for the
normalisation helpers, or `AbstractScraperProvider` for fetch-and-parse
plumbing), then register a builder:

```php
add_filter( 'odds_aggregator_providers', function ( array $builders, $settings, $http ): array {
    $builders['myfeed'] = static fn() => new \My\Plugin\MyFeedProvider( $http, $settings->api_key() );

    return $builders;
}, 10, 3 );
```

It appears in the settings dropdown automatically. A provider extending
`AbstractProvider` that quotes only some markets overrides
`supported_markets()` to return their keys; the Markets tab then marks the
others as not quoted by that source.

### React to refreshed odds

```php
add_action( 'odds_aggregator_odds_updated', function ( array $lines, string $event_id, string $provider ) {
    // Record a price history, push a notification, warm another cache…
}, 10, 3 );
```

---

## 9. Hook reference

### Actions

| Hook | Arguments | Fires |
|---|---|---|
| `odds_aggregator_loaded` | `Plugin $plugin` | After the plugin boots. |
| `odds_aggregator_odds_updated` | `OddsLine[] $lines`, `string $event_id`, `string $provider_id` | After a successful refresh. |
| `odds_aggregator_warm_cache` | — | Cron: refresh the event list and every event's odds. |
| `odds_aggregator_refresh_event` | `string $event_id`, `string[] $market_keys` | Cron: refresh one event. |
| `odds_aggregator_refresh_events` | — | Cron: refresh the event list. |

### Filters

| Hook | Arguments | Purpose |
|---|---|---|
| `odds_aggregator_bookmakers` | `Bookmaker[]`, `array $url_overrides` | Bookmaker catalogue. |
| `odds_aggregator_bookmakers_file` | `string $file` | Path of the JSON catalogue the defaults are read from. |
| `odds_aggregator_markets` | `Market[]` | Market catalogue. |
| `odds_aggregator_bookmaker_aliases` | `array $aliases` | Upstream name → bookmaker id. |
| `odds_aggregator_providers` | `array $builders`, `Settings`, `HttpClient` | Registered data sources. |
| `odds_aggregator_user_agent` | `string $agent` | Default outbound User-Agent. |
| `odds_aggregator_oddschecker_url` | `string $url` | League page the scraper reads. |
| `odds_aggregator_oddschecker_bookmakers` | `array $codes` | Oddschecker code → bookmaker id. |
| `odds_aggregator_oddschecker_headers` | `array $headers` | Request headers sent to Oddschecker. |
| `odds_aggregator_oddsapi_ttl` | `int $ttl` | Seconds a The Odds API payload is reused (default: the Performance setting, 10800). One fetch costs one credit per market per region. |
| `odds_aggregator_oddsapi_sport` | `string $sport` | Sport key queried at The Odds API (default `soccer_epl`). |

---

## 10. REST API

Namespace `odds-aggregator/v1`. Every endpoint except `/block` requires
`edit_posts` and exists for the block editor.

| Endpoint | Returns |
|---|---|
| `GET /events` | Events the active provider can quote. |
| `GET /markets` | Markets enabled site-wide. |
| `GET /bookmakers` | Bookmakers, each flagged `enabled`. |
| `GET /odds?event=<id>&markets=<csv>` | Cached prices for one event. |
| `GET /status` | Active provider, event count and last error. |
| `GET /block?eventId=<id>&markets[]=…&bookmakers[]=…&format=…&highlightBest=0\|1&highlightWorst=0\|1&showSwitcher=0\|1` | Public. `{ "html": "…" }`, the rendered block; used by the front end's live refresh. |

---

## 11. The block

`odds-aggregator/odds-table`, dynamic (`save()` returns `null`), so a published
page always reflects current cached odds rather than markup frozen at save
time. The editor preview uses `ServerSideRender` against the same PHP renderer,
so editor and front end cannot drift.

| Attribute | Type | Default | Meaning |
|---|---|---|---|
| `eventId` | string | `''` | Event to price. |
| `markets` | string[] | `[]` | Empty means every enabled market. |
| `bookmakers` | string[] | `[]` | Empty means every enabled bookmaker; order is respected. |
| `format` | string | `''` | Empty means the site default. |
| `highlightBest` | boolean | `true` | Mark the best price per outcome. |
| `highlightWorst` | boolean | `false` | Mark the lowest price per outcome (not when all prices are equal). |
| `showSwitcher` | boolean | `true` | Show the format switcher. |

Block-level choices are always intersected with the site-wide settings, so a
bookmaker disabled in settings cannot be resurrected by an old block.

Output is a card with the event header, a bar with the market filter (when the
block shows more than one market; revealed by `view.js`, so without JavaScript
every market stays visible) and the format switcher, and one comparison
grid: a `<table>` with bookmakers as columns and, per market, a `<tbody>`
whose rows are the outcomes (home and away are labelled with the team names).
Each outcome row names its best price and bookmaker; a footer row links to
every bookmaker (`rel="nofollow sponsored noopener"`). The first column is
sticky and the grid scrolls horizontally when it does not fit. The table has a
visually hidden `<caption>` and `scope` attributes, and the best price carries
a screen-reader-only "(best price)" label. The best-price comparison runs over
the bookmakers actually displayed. Colours are CSS custom properties on
`.oa-odds-table` (`--oa-head-bg`, `--oa-best-bg`, …) that a theme can override.

---

## 12. Testing and quality

```bash
composer test              # PHPUnit, unit suite
composer test:coverage     # ...with a coverage report (needs pcov or Xdebug)
composer test:integration  # opt-in: runs against the live feeds
composer lint              # WordPress Coding Standards
npm run lint:js            # ESLint
npm run lint:css           # stylelint
```

Tests run without WordPress: `tests/bootstrap.php` defines in-memory stand-ins
for the WordPress functions the classes call (options, transients, cron, HTTP,
REST, escaping) and for the options-table queries `Cache\Lock` runs.

### Layout

`tests/Unit/` mirrors `src/`, so the tests for a class sit in the directory
matching its namespace:

```
tests/
  bootstrap.php                  WordPress stand-ins
  http-live.php                  Real cURL transport, integration suite only
  integration-bootstrap.php      http-live.php + bootstrap.php
  fixtures/                      Saved upstream responses
  Unit/Admin/                    Settings, SettingsGuard, SourceStatus
  Unit/Blocks/                   OddsTableRenderer
  Unit/Cache/                    CacheEntry, Lock, OddsCache, CacheWarmer
  Unit/Domain/                   BookmakerCatalogue, Event, Odds, OddsLine, Registry
  Unit/Http/                     HttpClient, RateLimiter, RetryPolicy
  Unit/Odds/                     OddsConverter, OddsFormat, OddsRepository
  Unit/Providers/                Each provider, the parser, the factory, source switching
  Unit/Rest/                     OddsController
  Integration/                   OddsApiLive
```

Fixtures are addressed through the `ODDS_AGGREGATOR_FIXTURES` constant rather
than relative to the test file, so a test can be moved between directories
freely.

### Coverage

`composer test:coverage` needs a coverage driver (`pcov` is the faster choice,
Xdebug works too) and writes an HTML report to `coverage/`, which is ignored by
git. `phpunit.xml.dist` excludes three files from the measured source:
`Plugin.php`, `Admin/SettingsPage.php` and `Blocks/BlockRegistrar.php` — menu
registration, hook wiring and markup with no logic of their own, which are
checked by hand against `wp-env` instead. Everything else is covered, including
each value object, the transport, the cache and the REST controller.

### The integration suite

The unit suite pins provider mappings against recorded responses, which cannot
tell you when a feed changes shape: a renamed field would pass there and empty
every table in production. `tests/Integration/` answers that one question
against the real upstream.

It is opt-in and skips itself when the credentials are absent, so it never runs
by accident and never spends credits in CI unless asked:

```bash
ODDS_AGGREGATOR_API_KEY=... composer test:integration
```

It uses the same stand-ins as the unit suite except for `wp_remote_get()`, which
`tests/http-live.php` defines over cURL before `tests/bootstrap.php` gets to
install its stub. The Odds API suite costs two credits per run: one bulk fetch,
reused across the assertions through the provider's own per-request memo, plus a
rejected request that is not billed. An empty payload is reported as a skip, not
a failure, because the league it queries has an off-season.

### Coding standards

Two WPCS sniffs are excluded in `phpcs.xml.dist`: `WordPress.Files.FileName`
(PSR-4 file names) and `ValidVariableName.UsedPropertyNotSnakeCase`
(properties of PHP's own DOM classes). Under `tests/` only,
`file_get_contents()`, `file_put_contents()` and `unlink()` are allowed, since
they read fixtures and write temporary data files and `WP_Filesystem` is not
loaded under PHPUnit. `tests/bootstrap.php` and `tests/http-live.php` are
excluded outright: they declare unprefixed stand-ins for WordPress functions on
purpose.

---

## 13. Out of scope

- **Odds history** — needs a custom table and a retention policy.
- **Push updates (WebSockets / SSE)** — tables poll the `/block` route instead.
- **Circuit breaker** — the retry policy covers transient failure only.
- **Multi-sport** — the model is sport-agnostic; only the fixtures and the
  scraper paths are football-specific.
