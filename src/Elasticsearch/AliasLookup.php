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

namespace Blomstra\Search\Elasticsearch;

/**
 * Parameters for looking up the alias, scoped to the indices it can point at.
 *
 * Unscoped (`/_alias/<name>`), the lookup covers every index on the server, and a server whose
 * users are limited to their own index prefix (OpenSearch's security plugin, Elasticsearch's
 * index privileges) refuses it with a 403, even when the alias is the user's own. The alias's
 * indices are always `<alias>_<timestamp>`, or on old installs the alias name itself as a
 * concrete index, and `<alias>*` matches both.
 */
final class AliasLookup
{
    public static function params(string $alias): array
    {
        return ['index' => "$alias*", 'name' => $alias];
    }
}
