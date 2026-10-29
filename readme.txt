=== Odds Aggregator ===
Tags: odds, betting, sports, comparison, gutenberg
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compare live odds from up to twenty-two bookmakers in any post or page with an Odds Table block.

== Description ==

Odds Aggregator fetches odds for upcoming football matches from a data source and renders them as a comparison table inside posts and pages.

* **Odds Table block.** Pick an event, narrow the markets, and add, remove or reorder bookmakers per block. The best price for each outcome is highlighted.
* **Three odds formats.** Readers switch between decimal, fractional and American odds without reloading the page; the choice is remembered.
* **Live refresh.** Open pages re-render their tables from the plugin cache once per fresh window, so odds stay current even behind a full-page cache.
* **Data sources.** The Odds API (licensed feed, needs an API key), an Oddschecker scraper, or offline sample data for building pages. Other plugins can add sources through the `odds_aggregator_providers` filter.
* **Settings screen.** Shows whether the source is returning odds, and lets administrators choose bookmakers, outbound (affiliate) links and logos, markets, The Odds API region and cache timings. The remaining The Odds API credits are shown too.
* **Built for performance.** Stale-while-revalidate caching, background refresh through WP-Cron, single-flight locks and an outbound rate limit.

Oddschecker is behind Cloudflare and its terms do not permit scraping, so the scraper can be blocked. Use a licensed feed in production.

== Installation ==

1. Upload the `odds-aggregator` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload.
2. Activate the plugin.
3. Open **Odds Aggregator** in the admin menu and choose a data source.
4. Add the **Odds Table** block to a post or page and pick an event.

== Frequently Asked Questions ==

= The settings screen says the data source is not returning odds =

The source failed on its last fetch; the error is shown on the screen. Click **Switch to Sample data** to keep working, or choose another source.

= Which markets are supported? =

Match Result (1X2), Over/Under 2.5 Goals and Both Teams to Score. Markets a source does not quote are marked on the Markets tab and left out of the tables.

= Does the plugin contact third-party services? =

Only the data source you select: The Odds API (`api.the-odds-api.com`) with your API key, or `www.oddschecker.com` for the scraper. Sample data makes no outbound requests.

== Changelog ==

= 1.0.0 =
* Initial release.
