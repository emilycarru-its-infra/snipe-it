<?php

namespace App\Services\Deployments;

use App\Models\DeploymentWave;

/**
 * Starting points for a wave announcement.
 *
 * These are annual emails whose wording barely changes between years, so the
 * form opens on a draft rather than an empty box — the year, the model on offer
 * and the recipient's own machine come from merge fields, which is the part that
 * used to be retyped and mis-typed. Everything here is editable before sending;
 * a template is a first draft, not a form to fill in.
 *
 * The Faculty Laptop Program text is last year's letter with the two things that
 * changed: the store and the form are ours now, so it links to the form in this
 * system rather than a Microsoft Form, and to our own store rather than CDW's
 * retired premium page.
 */
class WaveAnnouncementTemplates
{
    /**
     * @return array<int, array{key: string, label: string, subject: string, body: string}>
     */
    public static function all(?DeploymentWave $wave = null): array
    {
        return [
            [
                'key' => 'faculty_program',
                'label' => trans('admin/deployments/general.announce_template_faculty'),
                'subject' => trans('admin/deployments/general.announce_faculty_subject'),
                'body' => trans('admin/deployments/general.announce_faculty_body'),
            ],
            [
                'key' => 'refresh',
                'label' => trans('admin/deployments/general.announce_template_refresh'),
                'subject' => trans('admin/deployments/general.announce_refresh_subject'),
                'body' => trans('admin/deployments/general.announce_refresh_body'),
            ],
            [
                'key' => 'blank',
                'label' => trans('admin/deployments/general.announce_template_blank'),
                'subject' => $wave ? $wave->name : '',
                'body' => '',
            ],
        ];
    }

    /**
     * The template a wave should open on. The deployment type decides it: a
     * faculty program wave is the annual letter, everything else is the shorter
     * refresh note.
     */
    public static function defaultKeyFor(DeploymentWave $wave): string
    {
        $haystack = strtolower(($wave->name ?? '').' '.($wave->type->name ?? ''));

        return str_contains($haystack, 'faculty') ? 'faculty_program' : 'refresh';
    }
}
