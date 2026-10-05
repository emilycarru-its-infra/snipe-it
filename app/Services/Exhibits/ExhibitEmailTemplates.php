<?php

namespace App\Services\Exhibits;

use App\Services\Settings\StoreInput;

/**
 * The editable fields of an exhibit student-email template, read the same
 * way for the in-app editor and the API. Templates are seeded per cycle and
 * referenced by key, so the key is never editable and neither surface
 * creates or deletes them. The name is only changed when it is sent; the
 * editor form does not carry it.
 */
final class ExhibitEmailTemplates
{
    /** @var array<string, 'string'|'int'|'bool'|'id'> */
    public const FIELDS = [
        'subject' => 'string',
        'body' => 'string',
        'enabled' => 'bool',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function attributes(array $input, bool $partial = false): array
    {
        return StoreInput::pick($input, self::FIELDS, $partial)
            + StoreInput::pick($input, ['name' => 'string'], true);
    }
}
