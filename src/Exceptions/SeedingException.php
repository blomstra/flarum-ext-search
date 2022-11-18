<?php

/*
 * This file is part of ianm/translate.
 *
 * Copyright (c) 2022 Blomstra Ltd.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 *
 */

namespace Blomstra\Search\Exceptions;

use Throwable;

class SeedingException extends \Exception
{
    public function __construct($message = '', public array $items, $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
