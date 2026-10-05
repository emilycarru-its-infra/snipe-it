<?php

namespace App\Services\Settings;

/**
 * Reads a configuration store's editable fields off a request the way its
 * web form does, so the web page and the API coerce input identically:
 * a checkbox is true/false, a sort order is an integer, an optional foreign
 * key that comes in blank is null. Validation itself stays on the model.
 *
 * A full read is the web form's: every field is set, and one left out of a
 * form post reads as blank or false (an unticked checkbox sends nothing).
 * A partial read is a PATCH's: only the fields sent are touched.
 */
final class StoreInput
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, 'string'|'int'|'bool'|'id'>  $fields  field name => how the web form reads it
     * @return array<string, mixed>
     */
    public static function pick(array $input, array $fields, bool $partial = false): array
    {
        $out = [];

        foreach ($fields as $name => $type) {
            if ($partial && ! array_key_exists($name, $input)) {
                continue;
            }

            $value = $input[$name] ?? null;

            $out[$name] = match ($type) {
                'int' => (int) ($value ?? 0),
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'id' => $value ?: null,
                default => $value,
            };
        }

        return $out;
    }
}
