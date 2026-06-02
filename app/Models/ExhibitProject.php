<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * One student-project in an exhibit. Replaces the hand-kept Grad Show
 * Numbers sheet. Belongs to an editable Exhibit / ExhibitStatus /
 * ExhibitProjectType (catalogs), links to a Snipe user (student) + asset
 * (device assigned at setup), and stores the remaining spreadsheet
 * fields. requested_device stays a free string so combos ("iMac, iPad")
 * survive and match the sheet's device buckets.
 */
class ExhibitProject extends SnipeModel
{
    use Loggable;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'exhibit_projects';

    /** Suggested values for the requested-device datalist (free string allows combos). */
    public const REQUESTED_DEVICES = [
        'iMac',
        'iPad',
        'Mac mini',
        'Windows PC',
        'Display',
    ];

    protected $rules = [
        'exhibit_id' => 'required|exists:exhibits,id',
        'year' => 'required|integer|min:2000|max:2100',
        'user_id' => 'nullable|exists:users,id',
        'student_name' => 'nullable|string|max:191',
        'asset_id' => 'nullable|exists:assets,id',
        'status_id' => 'required|exists:exhibit_statuses,id',
        'project_type_id' => 'nullable|exists:exhibit_project_types,id',
        'project_details' => 'nullable|string|max:65535',
        'requested_device' => 'nullable|string|max:191',
        'peripherals' => 'nullable|string|max:191',
        'submitted_file' => 'boolean',
        'approved' => 'boolean',
        'tdx_id' => 'nullable|string|max:191',
        'notes' => 'nullable|string|max:65535',
    ];

    protected $fillable = [
        'exhibit_id',
        'year',
        'user_id',
        'student_name',
        'asset_id',
        'status_id',
        'project_type_id',
        'project_details',
        'requested_device',
        'peripherals',
        'submitted_file',
        'approved',
        'tdx_id',
        'notes',
    ];

    protected $casts = [
        'submitted_file' => 'boolean',
        'approved' => 'boolean',
        'year' => 'integer',
    ];

    protected $searchableAttributes = ['student_name', 'requested_device', 'tdx_id', 'notes', 'project_details'];

    protected $searchableRelations = [
        'user' => ['first_name', 'last_name', 'username', 'email'],
        'asset' => ['asset_tag', 'serial', 'name'],
        'exhibit' => ['name'],
        'status' => ['name'],
        'projectType' => ['name'],
    ];

    public function exhibit()
    {
        return $this->belongsTo(Exhibit::class, 'exhibit_id');
    }

    public function status()
    {
        return $this->belongsTo(ExhibitStatus::class, 'status_id');
    }

    public function projectType()
    {
        return $this->belongsTo(ExhibitProjectType::class, 'project_type_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * Display label — prefer the linked Snipe user's name, fall back to
     * the free-text student_name captured on the row.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->user?->full_name ?: ($this->student_name ?: '—')
        );
    }

    /** Hex color for this row's status (from the catalog). */
    public function statusColor(): string
    {
        return $this->status?->color ?: '#bdc3c7';
    }

    public function statusLabel(): string
    {
        return $this->status?->name ?: '—';
    }

    public function typeLabel(): string
    {
        return $this->projectType?->name ?: '';
    }

    /**
     * Human label for the assigned device — the Munki Exhibition name
     * (asset name) if set, else the asset tag.
     */
    public function assignedDeviceLabel(): string
    {
        if (! $this->asset) {
            return '—';
        }

        return $this->asset->name ?: $this->asset->asset_tag ?: ('#'.$this->asset->id);
    }

    /** The student's email — the linked Snipe user's address (null if unlinked). */
    public function recipientEmail(): ?string
    {
        return $this->user?->email;
    }

    /**
     * Merge variables substituted into an editable email template. The
     * year-specific pickup dates/links live in the template body; these
     * carry the per-student facts. Missing data renders empty.
     */
    public function mergeVariables(): array
    {
        return [
            'student_name' => (string) ($this->displayName ?? ''),
            'show' => (string) ($this->exhibit?->name ?? ''),
            'year' => (string) ($this->year ?? ''),
            'project_type' => (string) ($this->projectType?->name ?? ''),
            'requested_device' => (string) ($this->requested_device ?? ''),
            'peripherals' => (string) ($this->peripherals ?? ''),
            'assigned_asset' => $this->asset_id ? $this->assignedDeviceLabel() : '',
        ];
    }
}
