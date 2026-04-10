![](https://extiverse.com/extension/blomstra/search/open-graph-image)

Search replaces the native Flarum search functionality which relies on MySQL badly performing
fulltext search with one that is completely relying on the proven elasticsearch engine.

## Features

- Sync discussions and posts to Elasticsearch using your queue, unobtrusively for the user.
- Reduces search loading times to well below 400ms (local tests with 50,000 discussions: **260ms**)
- Uses Flarum's group permissions and tags system.
- Compatible with Friends of Flarum Byōbu.

## Requirements

- Elasticsearch 7.x or OpenSearch 1.x+
- A non-sync queue driver with a running worker (`php flarum queue:work`) is strongly recommended for production. The extension works with the default sync driver, but index jobs run inline which adds latency to user-facing changes like posting.

## Installation

```bash
composer require blomstra/search:*
```

Enable the extension in the admin area and configure the Elasticsearch endpoint and index name in the extension settings.

## Setting up the index

### First install

Run the build command once. It creates a timestamped concrete index, immediately aliases your configured index name to it, and begins queuing documents. Search is available and improves as the queue processes:

```bash
php flarum blomstra:search:index build
php flarum queue:work
```

### Subsequent rebuilds (zero-downtime)

When you need to rebuild the full index (e.g. after a mapping change):

```bash
# 1. Build into a staging index — live index is untouched
php flarum blomstra:search:index build

# 2. Drain the queue
php flarum queue:work --stop-when-empty

# 3a. Promote the new index to live
php flarum blomstra:search:index promote
# 3b: promote but keep the old index as a backup for rollback
php flarum blomstra:search:index promote --keep-backup

# 4. Add content added between 'build' and 'promote'
php flarum blomstra:search:index fill 

```

If you kept the backup and want to roll back:

```bash
php flarum blomstra:search:index rollback
```

Once you are satisfied with the new index, drop the backup:

```bash
php flarum blomstra:search:index discard --backup
```

### Resuming or cancelling an interrupted build

If a build is interrupted, re-run it with the appropriate flag:

```bash
# Resume each seeder from where it left off
php flarum blomstra:search:index build --resume

# Drop the staging index and start completely fresh
php flarum blomstra:search:index build --fresh

# Cancel the build without starting a new one
php flarum blomstra:search:index discard --pending
```

### Filling gaps in an existing index

If documents are missing from the live index (e.g. due to queue failures):

```bash
php flarum blomstra:search:index fill
```

### Updating the mapping only

To push a mapping change to the live index without rebuilding:

```bash
php flarum blomstra:search:index mapping
```

## Command reference

| Command | Description |
|---|---|
| `build` | Build into a new timestamped staging index. On first install, aliases it immediately so search is live during seeding. On subsequent runs, use `promote` when the queue is drained. |
| `build --resume` | Resume an interrupted build from where each seeder left off. |
| `build --fresh` | Drop the staging index and start completely fresh. |
| `promote` | Atomically swap the alias from the live index to the completed staging index. Prompts for confirmation. |
| `promote --keep-backup` | Promote and retain the replaced live index as a backup for rollback. |
| `rollback` | Restore the backup index to live (after `promote --keep-backup`). Deletes the index that was live. |
| `discard --pending` | Drop the staging index without promoting (cancels an in-progress build). |
| `discard --backup` | Drop the backup index (cleanup after `promote --keep-backup`). |
| `mapping` | Push updated mapping to the live index without rebuilding or reseeding. |
| `fill` | Seed only documents missing from the live index. |
| `build --only=discussions` | Seed only the specified document type (`discussions` or `posts`). |
| `build --throttle=N` | Wait N seconds between batches (reduces queue pressure). |
| `build --max-id=N` | Limit seeding to documents with ID ≤ N. |
| `promote --i-am-sure` | Skip the promotion confirmation prompt (for scripts and CI). |

## FAQ

## Queue configuration

*"Can I dispatch indexing jobs to a specific queue?"*

Yes:

```php
\Blomstra\Search\Jobs\Job::$onQueue = 'search';
```

*"I have a different question"*

Reach out ot us via https://support.on-floxum.com/t/ext-search . If you have an active subscription, please mention what plan you are on. 

---

- Floxum provides managed Flarum hosting.
- https://floxum.com
- https://support.on-floxum.com/t/ext-search

Icon made by [Freepik](https://www.freepik.com) from [Flaticon](https://www.flaticon.com/).
