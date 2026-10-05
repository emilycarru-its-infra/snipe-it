<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One overridden preference: the key as declared in
 * App\Services\Settings\Preferences and its value, JSON-encoded so a list,
 * a number and a flag all round-trip as the type they were saved as.
 *
 * Read through Preferences::get(), never directly — the registry is what
 * knows the default, the type and the cache.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property int|null $updated_by
 */
class RuntimeSetting extends Model
{
    protected $table = 'runtime_settings';

    protected $fillable = ['key', 'value', 'updated_by'];
}
