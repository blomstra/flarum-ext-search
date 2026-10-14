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
use Blomstra\Search\LiveIndex;
use Elasticsearch\Client;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;

class PromoteIndexJob extends AbstractJob
{
    use SavesIndexedConfig;

    public function __construct(
        private bool $keepBackup = false,
        private ?string $onQueue = null
    ) {
        if ($this->onQueue) {
            $this->onQueue($this->onQueue);
        }
    }

    public function handle(Client $client, SettingsRepositoryInterface $settings, LiveIndex $live, LoggerInterface $logger): void
    {
        $staging = $settings->get('blomstra-search.staging-index');

        if (!$staging || !$client->indices()->exists(['index' => $staging])) {
            $logger->error('blomstra/search: PromoteIndexJob found no staging index to promote.');
            return;
        }

        $client->indices()->putSettings([
            'index' => $staging,
            'body'  => ['index' => ['refresh_interval' => BuildCommand::REFRESH_INTERVAL_LIVE]],
        ]);

        $previous = $live->switchTo($staging);

        $settings->set('blomstra-search.staging-index', null);
        $this->saveIndexedConfig($client, $settings, $staging);

        if (!$previous) {
            $logger->info("blomstra/search: promoted '$staging'.");
        } elseif ($this->keepBackup) {
            $settings->set('blomstra-search.backup-index', $previous);
            $logger->info("blomstra/search: promoted '$staging', kept '$previous' as backup.");
        } else {
            $client->indices()->delete(['index' => $previous, 'ignore_unavailable' => true]);
            $logger->info("blomstra/search: promoted '$staging', deleted '$previous'.");
        }
    }
}
