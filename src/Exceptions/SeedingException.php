<?php

namespace Blomstra\Search\Exceptions;

use Throwable;

class SeedingException extends \Exception
{

    public function __construct($message = "", public array $items, $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
