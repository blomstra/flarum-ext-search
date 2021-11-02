![](https://extiverse.com/extension/blomstra/search/open-graph-image)

Search replaces the native Flarum search functionality which relies on MySQL badly performing
fulltext search with one that is completely relying on the proven elasticsearch engine.

## Features

- Sync discussions to elastic search using your queue, unobtrusively for the user.
- Reduces search loading times to well below 400ms (local tests with 50.000 discussion **260ms**)
- Uses Flarum's group permissions and tags system.
- Compatible with Friends of Flarum Byōbu.

## Installation

Use composer:

```bash
composer require blomstra/search:*
```

Enable the extension inside the admin area and configure the settings.

### Set up

Enable the extension in your admin area. Now to seed your existing discussions use the following command:

```
php flarum blomstra:search:build
```

All mutations to discussions are automatically added and removed from the elasticsearch index.

### FAQ

*I have another question.*
Reach out to us via https://helpdesk.blomstra.net. We will get back to you as soon as we can. If you have a running subscription please mention when you started your plan and/or which plan you are on. Always add sufficient information when reporting errors. We prefer errors being reported here, but understand that sometimes you can't.

*Can I dispatch the sync jobs to another queue?*
Yes:

```php
\Blomstra\Search\Observe\Job::$onQueue = 'sync';
```

---

- Blomstra provides managed Flarum hosting.
- https://blomstra.net
- https://blomstra.community/t/ext-search

Icon made by [Freepik](https://www.freepik.com) from [Flaticon](https://www.flaticon.com/).
