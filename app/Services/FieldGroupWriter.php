<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\FieldGroup;
use App\Services\Settings\StoreInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Writes to the field-group taxonomy for both the Field Groups admin page
 * and the API: the editable fields, what deleting a group does to the
 * fields in it, and assigning a field to a group.
 */
final class FieldGroupWriter
{
    /** @var array<string, 'string'|'int'|'bool'|'id'> */
    public const FIELDS = [
        'name' => 'string',
        'color' => 'string',
        'icon' => 'string',
        'sort_order' => 'int',
        'collapsed_by_default' => 'bool',
        'active' => 'bool',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function attributes(array $input, bool $partial = false): array
    {
        return StoreInput::pick($input, self::FIELDS, $partial);
    }

    /**
     * Delete a group. Its fields are unassigned first, so they fall back to
     * the "Other" box rather than pointing at a missing group.
     */
    public static function remove(FieldGroup $group): void
    {
        CustomField::where('field_group_id', $group->id)->update(['field_group_id' => null]);
        $group->delete();
    }

    /**
     * Put a field in a group, or take it out of every group when the group
     * is blank. A group that does not exist is refused.
     *
     * @throws ValidationException
     */
    public static function assign(CustomField $field, mixed $groupId): void
    {
        if ($groupId !== null && $groupId !== '') {
            Validator::make(['field_group_id' => $groupId], ['field_group_id' => 'integer|exists:field_groups,id'])->validate();
            $field->field_group_id = (int) $groupId;
        } else {
            $field->field_group_id = null;
        }

        $field->saveQuietly();
    }
}
