<?php

/*
 * This file is part of blomstra/search.
 *
 * Copyright (c) 2022 Blomstra Ltd.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 *
 */

namespace Blomstra\Search;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Where the search server is and how to authenticate to it.
 *
 * Read from the admin settings, unless config.php has a `search` key with an
 * `endpoint`: then the whole connection comes from config and the settings are
 * ignored. A host that provisions the search server for its forums sets it there,
 * so credentials never live in the database (and so never reach the admin
 * frontend, which receives every setting).
 *
 *     'search' => [
 *         'endpoint' => 'http://opensearch:9200',
 *         'username' => 'forum-1',
 *         'password' => '...',
 *         'index'    => 'forum-1',
 *         'managed'  => true, // optional: the admin note says the host manages the connection
 *     ],
 *
 * resolve() is the only way to build one, so a connection is "managed" only when it
 * comes from config.
 */
class Connection
{
    public const DEFAULT_INDEX = 'flarum';

    private function __construct(
        private ?string $endpoint,
        private ?string $username,
        private ?string $password,
        private string $index,
        private bool $fromConfig,
        private bool $managed
    ) {
    }

    public static function resolve(Config $config, SettingsRepositoryInterface $settings): self
    {
        $search = $config['search'];

        if (is_array($search) && !empty($search['endpoint'])) {
            return new self(
                (string) $search['endpoint'],
                empty($search['username']) ? null : (string) $search['username'],
                empty($search['password']) ? null : (string) $search['password'],
                empty($search['index']) ? self::DEFAULT_INDEX : (string) $search['index'],
                true,
                // A boolean, but also "false" or "0" from an environment variable.
                filter_var($search['managed'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
        }

        return new self(
            $settings->get('blomstra-search.elastic-endpoint') ?: null,
            $settings->get('blomstra-search.elastic-username') ?: null,
            $settings->get('blomstra-search.elastic-password') ?: null,
            $settings->get('blomstra-search.elastic-index') ?: self::DEFAULT_INDEX,
            false,
            false
        );
    }

    public function endpoint(): ?string
    {
        return $this->endpoint;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    /**
     * The alias searches and live updates go to. Concrete indices are `<index>_YmdHis`.
     */
    public function index(): string
    {
        return $this->index;
    }

    /**
     * The connection comes from config.php, so the four connection settings are ignored and
     * the admin page does not offer them.
     */
    public function fromConfig(): bool
    {
        return $this->fromConfig;
    }

    /**
     * The host provisioned the connection: the admin page says so, instead of pointing at
     * config.php. Only ever true for a connection from config.
     */
    public function managed(): bool
    {
        return $this->managed;
    }
}
