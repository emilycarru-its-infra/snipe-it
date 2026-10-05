<?php

namespace App\Services\Deployments;

use App\Models\StaffBlackout;
use App\Services\Settings\StoreInput;

/**
 * Hand-entered staff blackouts, as the Waves page and the API edit them.
 * Only rows entered by hand are editable: synced rows belong to the
 * calendar sync, which would overwrite any edit on its next run.
 */
final class ManualBlackouts
{
    public const SOURCE = 'manual';

    /** @var array<string, 'string'|'int'|'bool'|'id'> */
    public const FIELDS = [
        'user_id' => 'string',
        'start_date' => 'string',
        'end_date' => 'string',
        'reason' => 'string',
    ];

    public static function isEditable(StaffBlackout $blackout): bool
    {
        return $blackout->source === self::SOURCE;
    }

    /**
     * The editable fields off the request; validation runs on save via the model.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function attributes(array $input, bool $partial = false): array
    {
        return StoreInput::pick($input, self::FIELDS, $partial);
    }
}
