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

### Connection from config.php (hosts)

A host that provisions the search server for its forums can provide the connection in
`config.php` instead of the admin settings. When `search.endpoint` is set, the whole connection
comes from there and the four connection settings are ignored, so credentials never have to be
stored in the database:

```php
'search' => [
    'endpoint' => 'http://opensearch:9200', // always with a port: without one the client uses 9200, even for https
    'username' => 'forum-1',
    'password' => '...',
    'index'    => 'forum-1',                // alias name, default `flarum`
    'managed'  => true,                     // optional, see below
],
```

The admin page then shows a note that the connection is set in config.php instead of the four
connection fields, which would be ignored. With `managed` set, the note says the host manages the
connection instead.

Only the connection moves. What the build command records about the index (the live, staging
and backup index names, the indexed analyzer) stays in the database settings, whichever
connection wrote it.

## Development

```bash
composer test:unit                 # no database needed
composer test:setup                # once: installs Flarum into the test database (DB_* env vars)
composer test:integration
```

The visibility tests answer searches from a fake search server. The round-trip tests (build,
rebuild, rollback, fill, live updates, search) run against a real server, and are skipped unless
it is given, with credentials limited to the index prefix the way a host would issue them:

```bash
SEARCH_TEST_ENDPOINT=http://localhost:9200 SEARCH_TEST_USERNAME=forum-1 \
SEARCH_TEST_PASSWORD=... SEARCH_TEST_INDEX=forum-1 composer test:integration
```

Every index matching `SEARCH_TEST_INDEX*` is deleted before each test.

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
# Simple rebuild — promotes automatically once all jobs are queued
php flarum blomstra:search:index build

# Or keep a backup of the old index in case you need to roll back
php flarum blomstra:search:index build --keep-backup
```

After the queue drains, fill any gaps from content posted during the build:

```bash
php flarum blomstra:search:index fill
```

If you kept a backup and want to roll back:

```bash
php flarum blomstra:search:index rollback
```

Once satisfied with the new index, drop the backup:

```bash
php flarum blomstra:search:index discard --backup
```

### Blue-green rebuild (manual promotion)

Use `--staging` to keep the old index live until you explicitly promote:

```bash
# 1. Build into a staging index — live index is untouched
php flarum blomstra:search:index build --staging

# 2. Drain the queue
php flarum queue:work --stop-when-empty

# 3. Promote the staging index to live
php flarum blomstra:search:index promote
# Or keep the old index as a backup:
php flarum blomstra:search:index promote --keep-backup
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
| `build` | Rebuild the index and promote automatically once all jobs are queued. On first install, aliases immediately so search is live during seeding. |
| `build --keep-backup` | Rebuild and promote, retaining the old index as a backup for rollback. |
| `build --staging` | Build into a staging index without promoting — use `promote` when ready (blue-green workflow). |
| `build --resume` | Resume an interrupted build from where each seeder left off. |
| `build --fresh` | Drop the staging index and start completely fresh. |
| `promote` | Atomically swap the alias to the staging index. Prompts for confirmation (blue-green workflow). |
| `promote --keep-backup` | Promote and retain the replaced live index as a backup for rollback. |
| `rollback` | Restore the backup index to live. Deletes the index that was live. |
| `discard --pending` | Drop the staging index without promoting (cancels an in-progress build). |
| `discard --backup` | Drop the backup index (cleanup after `--keep-backup`). |
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
