<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * One student-project in an exhibit (Grad Show, MFA Thesis, Foundation,
 * Type). Replaces the hand-maintained Grad Show Numbers sheet: it stores
 * the spreadsheet-native fields (status, project type, requested device,
 * submitted-file / approved flags, peripherals, TDX id) and *links* to a
 * Snipe user (student) and asset (the device reserved/assigned at setup)
 * so their details are derived, never duplicated.
 */
class ExhibitProject extends SnipeModel
{
    use Loggable;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'exhibit_projects';

    /** Status values, in the order the Numbers sheet dropdown lists them. */
    public const STATUSES = [
        'none',
        'pending',
        'need_to_contact',
        'reserved',
        'waitlisted',
        'scheduled',
        'in_progress',
        'done',
        'cancelled',
        'self_setup',
        'master_student',
        'early_setup',
        'ready',
        'late',
        'undetermined',
        'media_resources',
    ];

    /** Status → Bootstrap 3 label class for the colored cells/cards. */
    public const STATUS_COLORS = [
        'none' => 'default',
        'pending' => 'default',
        'need_to_contact' => 'danger',
        'reserved' => 'info',
        'waitlisted' => 'warning',
        'scheduled' => 'primary',
        'in_progress' => 'primary',
        'done' => 'success',
        'cancelled' => 'default',
        'self_setup' => 'info',
        'master_student' => 'info',
        'early_setup' => 'info',
        'ready' => 'warning',
        'late' => 'danger',
        'undetermined' => 'default',
        'media_resources' => 'default',
    ];

    public const PROJECT_TYPES = [
        'looping_video',
        'website',
        'specialized_app',
        'figma',
        'audio',
        'looping_pdf',
        'other',
    ];

    /** Suggested values for the requested-device datalist (free string allows combos). */
    public const REQUESTED_DEVICES = [
        'iMac',
        'iPad',
        'Mac mini',
        'Windows PC',
        'Display',
    ];

    public const SHOWS = [
        'Grad Show',
        'MFA Thesis',
        'Foundation Show',
        'Type Show',
    ];

    protected $rules = [
        'show' => 'required|string|max:191',
        'year' => 'required|integer|min:2000|max:2100',
        'user_id' => 'nullable|exists:users,id',
        'student_name' => 'nullable|string|max:191',
        'asset_id' => 'nullable|exists:assets,id',
        'status' => 'required|string|in:none,pending,need_to_contact,reserved,waitlisted,scheduled,in_progress,done,cancelled,self_setup,master_student,early_setup,ready,late,undetermined,media_resources',
        'project_type' => 'nullable|string|in:looping_video,website,specialized_app,figma,audio,looping_pdf,other',
        'project_details' => 'nullable|string|max:65535',
        'requested_device' => 'nullable|string|max:191',
        'peripherals' => 'nullable|string|max:191',
        'submitted_file' => 'boolean',
        'approved' => 'boolean',
        'tdx_id' => 'nullable|string|max:191',
        'notes' => 'nullable|string|max:65535',
    ];

    protected $fillable = [
        'show',
        'year',
        'user_id',
        'student_name',
        'asset_id',
        'status',
        'project_type',
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

    protected $searchableAttributes = ['show', 'student_name', 'status', 'project_type', 'requested_device', 'tdx_id', 'notes'];

    protected $searchableRelations = [
        'user' => ['first_name', 'last_name', 'username', 'email'],
        'asset' => ['asset_tag', 'serial', 'name'],
    ];

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
            get: fn () => $this->user?->full_name ?: ($this->student_name ?: trans('general.unknown'))
        );
    }

    /** Bootstrap label class for this row's status. */
    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'default';
    }

    /**
     * Human label for the assigned device — the Munki Exhibition name
     * (asset name) if set, else the asset tag, else an em dash.
     */
    public function assignedDeviceLabel(): string
    {
        if (! $this->asset) {
            return '—';
        }

        return $this->asset->name ?: $this->asset->asset_tag ?: ('#'.$this->asset->id);
    }

    /**
     * The student's email — the linked Snipe user's address. Null when no
     * user is linked (then the row can't be emailed).
     */
    public function recipientEmail(): ?string
    {
        return $this->user?->email;
    }

    /**
     * Merge variables substituted into an editable email template. The
     * year-specific pickup dates/links live in the template body itself;
     * these keys carry the per-student facts. Missing data renders empty
     * rather than leaving the {{placeholder}} behind.
     */
    public function mergeVariables(): array
    {
        return [
            'student_name' => (string) ($this->displayName ?? ''),
            'show' => (string) ($this->show ?? ''),
            'year' => (string) ($this->year ?? ''),
            'project_type' => $this->project_type
                ? (string) trans('admin/exhibit-projects/general.type_value_'.$this->project_type)
                : '',
            'requested_device' => (string) ($this->requested_device ?? ''),
            'peripherals' => (string) ($this->peripherals ?? ''),
            'assigned_asset' => $this->asset_id ? $this->assignedDeviceLabel() : '',
        ];
    }
}
