<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Watson\Validating\ValidatingTrait;

/**
 * One way a product shows up on an endpoint. Patterns are matched against
 * the observed name with its version stripped (see ProductResolver), so
 * "Rhino" catches "Rhino 7" and "Rhino 8" alike; a regex sees the raw name.
 */
class ProductIdentityAlias extends Model
{
    use ValidatingTrait;

    public const PLATFORMS = ['any', 'macos', 'windows'];

    public const MATCH_TYPES = ['exact', 'prefix', 'regex'];

    protected $table = 'product_identity_aliases';

    protected $fillable = [
        'product_identity_id',
        'platform',
        'match_type',
        'pattern',
    ];

    protected $rules = [
        'product_identity_id' => 'required|integer|exists:product_identities,id',
        'platform'            => 'required|in:any,macos,windows',
        'match_type'          => 'required|in:exact,prefix,regex',
        'pattern'             => 'required|string|max:255',
    ];

    /** @return BelongsTo<ProductIdentity, $this> */
    public function productIdentity(): BelongsTo
    {
        return $this->belongsTo(ProductIdentity::class);
    }

    /** Whether a regex alias compiles; other match types always do. */
    public static function patternIsValid(string $matchType, string $pattern): bool
    {
        if ($matchType !== 'regex') {
            return true;
        }

        return @preg_match(self::regexFor($pattern), '') !== false;
    }

    /** Aliases are written bare (no delimiters) and always match case-insensitively. */
    public static function regexFor(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }
}
