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

namespace Blomstra\Search;

class TextNormalizer
{
    public static function fold(string $text): string
    {
        $result = \transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        return $result !== false ? $result : $text;
    }
}
