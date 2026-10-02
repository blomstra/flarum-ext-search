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

namespace Blomstra\Search\Content;

use Blomstra\Search\Connection;
use Flarum\Frontend\Document;

/**
 * Tells the admin page where the connection comes from, so it does not offer fields that would
 * be ignored. Only that: the connection itself never goes into a frontend payload.
 */
class AdminPayload
{
    public function __construct(protected Connection $connection)
    {
    }

    public function __invoke(Document $document): void
    {
        $document->payload['blomstraSearchConnection'] = [
            'fromConfig' => $this->connection->fromConfig(),
            'managed'    => $this->connection->managed(),
        ];
    }
}
