![](https://extiverse.com/extension/blomstra/search/open-graph-image)

Search replaces the native Flarum search functionality which relies on MySQL badly performing
fulltext search with one that is completely relying on the proven elasticsearch engine.

## Features

- Sync discussions to elastic search using your queue, unobtrusively for the user.
- Reduces search loading times to well below 400ms (local tests with 50.000 discussion **260ms**)
- Uses Flarum's group permissions and tags system.
- Compatible with Friends of Flarum Byōbu.

## Premium

Managed Flarum communities that we host on our platform ([blomstra](https://blomstra.net)) will have access to all premium extensions we publish without additional cost.

Alternative you can see our plans on [extiverse](https://extiverse.com/extension/blomstra/search). After completing your order, check your active [subscriptions](https://extiverse.com/premium/subscriptions) and follow the provided instructions. Then install using:

```bash
composer require blomstra/search:*
```

Enable the extension inside the admin area and continue below with the Set up.

### Set up

You will need a running Elasticsearch instance. Many providers offer these as a managed service, including
[Stack Hero](https://www.stackhero.io/en/services/Elasticsearch/benefits).

Modify your `config.php` by adding an entry `elastic` like so:

```php
return [
    'elastic' => [
        'endpoint' => 'https://somedomain:9200',
        'username' => '<username>',
        'password' => '<password>'
    ]
];
```

Or using an api token:

```php
return [
    'elastic' => [
        'endpoint' => 'https://somedomain:9200',
        'api-id' => '<id>',
        'api-key' => '<key>'
    ]
];
```

Enable the extension in your admin area. Now to seed your existing discussions use the following command:

```
php flarum blomstra:search:documents:rebuild
```

All mutations to discussions are automatically added and removed from the elasticsearch index.

### FAQ

*I have another question.*
Reach out to us via https://helpdesk.blomstra.net. We will get back to you as soon as we can. If you have a running subscription please mention when you started your plan and/or which plan you are on. Always add sufficient information when reporting errors. We prefer errors being reported here, but understand that sometimes you can't.

---

- Blomstra provides managed Flarum hosting.
- https://blomstra.net
- https://blomstra.community/t/ext-search
