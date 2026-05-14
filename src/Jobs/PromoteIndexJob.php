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

namespace Blomstra\Search\Jobs;

use Blomstra\Search\Commands\BuildCommand;
use Blomstra\Search\Commands\SavesIndexedConfig;
use Elasticsearch\Client;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;

class PromoteIndexJob extends AbstractJob
{
    use SavesIndexedConfig;

    public function __construct(
        private string $alias,
        private bool $keepBackup = false,
        private ?string $onQueue = null
    ) {
        if ($this->onQueue) {
            $this->onQueue($this->onQueue);
        }
    }

    public function handle(Client $client, SettingsRepositoryInterface $settings, LoggerInterface $logger): void
    {
        $staging = $settings->get('blomstra-search.staging-index');

        if (!$staging || !$client->indices()->exists(['index' => $staging])) {
            $logger->error('blomstra/search: PromoteIndexJob found no staging index to promote.');
            return;
        }

        $aliasExists = (bool) $client->indices()->existsAlias(['name' => $this->alias]);
        $indexExists = !$aliasExists && (bool) $client->indices()->exists(['index' => $this->alias]);

        if ($aliasExists) {
            $result      = $client->indices()->getAlias(['name' => $this->alias]);
            $activeIndex = array_key_first($result);

            $client->indices()->updateAliases([
                'body' => ['actions' => [
                    ['remove' => ['index' => $activeIndex,  'alias' => $this->alias]],
                    ['add'    => ['index' => $staging,      'alias' => $this->alias]],
                ]],
            ]);

            if ($this->keepBackup) {
                $settings->set('blomstra-search.backup-index', $activeIndex);
                $logger->info("blomstra/search: promoted '$staging' to '{$this->alias}', kept '$activeIndex' as backup.");
            } else {
                $client->indices()->delete(['index' => $activeIndex, 'ignore_unavailable' => true]);
                $logger->info("blomstra/search: promoted '$staging' to '{$this->alias}', deleted '$activeIndex'.");
            }
        } else {
            // One-time migration: concrete index → alias (legacy installs only).
            if ($indexExists) {
                $client->indices()->delete(['index' => $this->alias]);
            }

            $client->indices()->putAlias(['index' => $staging, 'name' => $this->alias]);
            $logger->info("blomstra/search: promoted '$staging' to '{$this->alias}'.");
        }

        $client->indices()->putSettings([
            'index' => $staging,
            'body'  => ['index' => ['refresh_interval' => BuildCommand::REFRESH_INTERVAL_LIVE]],
        ]);

        $settings->set('blomstra-search.active-index', $staging);
        $settings->set('blomstra-search.staging-index', null);
        $this->saveIndexedConfig($client, $settings, $staging);
    }

}
