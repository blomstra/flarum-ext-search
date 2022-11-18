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

namespace Blomstra\Search\Save;

use Carbon\Carbon;
use Illuminate\Support\Fluent;

/**
 * @property string      $type
 * @property string      $id
 * @property string      $content
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 * @property int         $user_id
 * @property bool        $is_private
 * @property bool        $is_sticky
 * @property array|int[] $groups
 * @property array|int[] $recipient_groups
 * @property array|int[] $recipient_users
 */
class Document extends Fluent
{
}
