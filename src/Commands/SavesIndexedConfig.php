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

namespace Blomstra\Search\Commands;

use Elasticsearch\Client;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;

trait SavesIndexedConfig
{
    /**
     * Persist the analysis config and compat version actually live in ES for the given index.
     * Reading from ES (rather than from Flarum settings) means rollbacks are covered: the
     * stored values always reflect the aliased index, not the settings at command run time.
     * Indexes built before _meta tracking existed yield a null compat version, which
     * correctly triggers the reindex warning.
     */
    protected function saveIndexedConfig(Client $client, SettingsRepositoryInterface $settings, string $indexName): void
    {
        $settingsResponse = $client->indices()->getSettings(['index' => $indexName]);
        $analysis         = Arr::get($settingsResponse, "$indexName.settings.index.analysis", []);

        $stemExclusion = Arr::get($analysis, 'analyzer.flarum_analyzer.stem_exclusion', []);

        $mappingResponse = $client->indices()->getMapping(['index' => $indexName]);
        $compatVersion   = Arr::get($mappingResponse, "$indexName.mappings._meta.index_compat_version");
        $language        = Arr::get($mappingResponse, "$indexName.mappings._meta.analyzer_language", 'english');

        $settings->set('blomstra-search.indexed-analyzer', $language);
        $settings->set('blomstra-search.indexed-stem-exclusion', implode("\n", $stemExclusion));
        $settings->set('blomstra-search.index-compatible', $compatVersion);
    }
}
