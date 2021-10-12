<?php

namespace Blomstra\Search\Searchables;

use Flarum\Extension\ExtensionManager;

abstract class Searchable
{
    /**
     * Returns an array of filterable attributes.
     *
     * @return array|null
     */
    abstract public function filters(): ?array;

    /**
     * An array of strings with which fulltext matching can be executed.
     *
     * @return array|null
     */
    abstract public function fulltext(): ?array;

    protected function extensionEnabled(string $extension): bool
    {
        /** @var ExtensionManager $manager */
        $manager = resolve(ExtensionManager::class);

        return $manager->isEnabled($extension);
    }
}
