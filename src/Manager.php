<?php

namespace Blomstra\Search;

use Blomstra\Search\Documents\Document;
use Illuminate\Contracts\Container\Container;

class Manager
{
    public function __construct(protected Container $container)
    {}

    public function document(string $type): ?string
    {
        return $this->fromTagged('blomstra.search.documents', $type);
    }

    protected function fromTagged(string $binding, $search): ?string
    {
        $entities = $this->container->tagged($binding);

        foreach ($entities as $entity) {
            $instance = $this->container->make($entity);

            if ($instance->type() === $search) return $entity;
        }

        return null;
    }
}
