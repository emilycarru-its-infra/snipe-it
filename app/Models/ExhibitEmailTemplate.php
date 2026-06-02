<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use Watson\Validating\ValidatingTrait;

/**
 * Editable, DB-backed copy for the student emails the device admins send
 * each show cycle. Seeded from the handbook (equipment_confirmation,
 * need_to_contact) and edited in-app. `render()` substitutes an
 * ExhibitProject's {{merge_variables}} into the subject + body.
 */
class ExhibitEmailTemplate extends SnipeModel
{
    use Loggable;
    use ValidatingTrait;

    protected $table = 'exhibit_email_templates';

    public $timestamps = true;

    protected $rules = [
        'key' => 'required|string|max:191',
        'name' => 'required|string|max:191',
        'subject' => 'required|string|max:191',
        'body' => 'required|string|max:65535',
        'enabled' => 'boolean',
    ];

    protected $fillable = [
        'key',
        'name',
        'subject',
        'body',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Render this template against a project, returning
     * ['subject' => ..., 'body' => ...] with {{vars}} filled in.
     */
    public function render(ExhibitProject $project): array
    {
        $subject = $this->subject;
        $body = $this->body;

        foreach ($project->mergeVariables() as $var => $value) {
            $subject = str_replace('{{'.$var.'}}', $value, $subject);
            $body = str_replace('{{'.$var.'}}', $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }
}
