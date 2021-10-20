<?php

namespace Blomstra\Search\Save;

use Carbon\Carbon;
use Illuminate\Support\Fluent;

/**
 * @property string $type
 * @property string $id
 * @property string $content
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $user_id
 * @property bool $is_private
 * @property bool $is_sticky
 * @property array|int[] $groups
 * @property array|int[] $recipient_groups
 * @property array|int[] $recipient_users
 */
class Document extends Fluent
{
}
