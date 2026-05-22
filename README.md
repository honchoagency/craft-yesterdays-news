![Banner](./docs/img/banner.png)

# Yesterday’s News

A Craft CMS 5 plugin that automatically removes stale pages from your [Blitz](https://putyourlightson.com/plugins/blitz) static cache.

Requires Craft CMS ^5.9 and Blitz.

## TL;DR

Yesterday's News automatically deletes pages from the Blitz cache if the page hasn't been visited in X time (configurable).

## The problem

On sites with a large amount of infrequently-visited content, the Blitz cache can grow to include thousands of pages that nobody is actually reading. This makes Blitz' refresh cycles unnecessarily large and slow.

Yesterday's News tracks when each page was last visited and removes pages that haven't been seen within a configurable threshold. The cache stays lean, and refresh cycles only cover pages people are actually reading.

## Quick start

1. Install the plugin
2. Set up the two cron jobs
3. Visit **Utilities → Yesterday's News** in the Craft CP to see tracked pages and their status
4. 🎉

The plugin works automatically from there. Pages visited by real users are tracked via a lightweight background request that fires on every page load. Pages that fall outside the configured threshold are pruned from Blitz on the next scheduled run.

## Installation

### 1. Install Yesterday's News
You can install Yesterday's News by searching for “Yesterday's News” in the Craft Plugin Store, or install manually using composer.
```sh
composer require honchoagency/craft-yesterdays-news
```

### 2. Configure the plugin
Copy the default config file into your project:

```sh
cp vendor/honchoagency/craft-yesterdays-news/src/config.php config/yesterdays-news.php
```

Then edit `config/yesterdays-news.php` to adjust the thresholds for your site.

### 3. Set up cron jobs

Two cron entries are required.

```sh
# Track visits and sync with Blitz — eg every 5 minutes
*/5 * * * * php craft yesterdays-news/maintain/run

# Prune stale pages from Blitz — eg every hour
0 * * * * php craft yesterdays-news/prune/run
```

## Configuration

All settings are in `config/yesterdays-news.php`. The defaults work for most sites:

| Setting | Default | Description |
|---|---|---|
| `pagePruningEnabled` | `true` | Whether to prune stale pages from Blitz |
| `threshold` | `86400` (24h) | Seconds since last visit before a page is pruned |
| `includePruningEnabled` | `true` | Whether to prune stale cached template includes |
| `entryAgeThreshold` | `2592000` (30d) | Seconds since an entry was last updated before its cached includes are eligible for pruning |
| `includeThreshold` | `86400` (24h) | Seconds a cached include must be before it is eligible for pruning |
| `includeTemplates` | `[]` | Map of include template paths to their entry ID param — see `config/yesterdays-news.php` for details |

## CP Diagnostics

The **Utilities → Yesterday's News** page in the Craft CP shows:

- All tracked pages with their age, fresh/stale status, and time until pruning
- Any visits currently buffered and waiting to be recorded
- Cached template includes and their prune status

Buttons let you manually flush, prune, sync, or clear all data without waiting for the cron schedule.

## Credits

Built by [honcho.agency](https://honcho.agency)
