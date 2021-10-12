<?php

namespace Blomstra\Search\Observe;

use Illuminate\Contracts\Queue\Queue;

class Observer
{

    public function saved($model)
    {
        $this->queue()->push(new Job($model));
    }

    public function deleted($model)
    {

    }

    protected function queue(): Queue
    {
        return resolve(Queue::class);
    }
}
