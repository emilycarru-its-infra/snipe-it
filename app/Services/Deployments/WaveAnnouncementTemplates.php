<?php

namespace App\Services\Deployments;

use App\Models\DeploymentWave;
use App\Models\EmailTemplate;

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
     * The key the saved version of the annual letter lives under, in the same
     * `email_templates` table the Settings → Emails overrides use. A wave
     * announcement is composed in the browser, so "save this wording" has to mean
     * writing it down somewhere the next send reads — not a code change and a
     * deploy a year later, when whoever edited it is not in the room.
     */
    public const STORE_KEY = 'deployments.wave_announcement';

    /**
     * @return array<int, array{key: string, label: string, subject: string, body: string, saved: bool}>
     */
    public static function all(?DeploymentWave $wave = null): array
    {
        $saved = EmailTemplate::forKey(self::STORE_KEY);

        $templates = [
            [
                'key' => 'faculty_program',
                'label' => trans('admin/deployments/general.announce_template_faculty'),
                'subject' => trans('admin/deployments/general.announce_faculty_subject'),
                'body' => trans('admin/deployments/general.announce_faculty_body'),
                'saved' => false,
            ],
            [
                'key' => 'refresh',
                'label' => trans('admin/deployments/general.announce_template_refresh'),
                'subject' => trans('admin/deployments/general.announce_refresh_subject'),
                'body' => trans('admin/deployments/general.announce_refresh_body'),
                'saved' => false,
            ],
            [
                'key' => 'blank',
                'label' => trans('admin/deployments/general.announce_template_blank'),
                'subject' => $wave ? $wave->name : '',
                'body' => '',
                'saved' => false,
            ],
        ];

        // The saved wording leads, because if somebody pressed Update Template it
        // is because last year's shipped default was not what they wanted to send.
        // The defaults stay listed underneath so there is always a way back.
        if ($saved && (filled($saved->subject) || filled($saved->body))) {
            array_unshift($templates, [
                'key' => 'saved',
                'label' => trans('admin/deployments/general.announce_template_saved'),
                'subject' => $saved->subject ?: trans('admin/deployments/general.announce_faculty_subject'),
                'body' => $saved->body ?: trans('admin/deployments/general.announce_faculty_body'),
                'saved' => true,
            ]);
        }

        return $templates;
    }

    /**
     * Write the wording somebody has just composed back as the saved template.
     */
    public static function save(string $subject, string $body, ?int $userId = null): EmailTemplate
    {
        $template = EmailTemplate::firstOrNew(['key' => self::STORE_KEY]);
        $template->fill([
            'subject' => $subject,
            'body' => $body,
            'updated_by' => $userId,
        ])->save();

        return $template;
    }

    /**
     * The template a wave should open on. The deployment type decides it: a
     * faculty program wave is the annual letter, everything else is the shorter
     * refresh note.
     */
    public static function defaultKeyFor(DeploymentWave $wave): string
    {
        $templates = self::all($wave);

        if (($templates[0]['key'] ?? null) === 'saved') {
            return 'saved';
        }

        $haystack = strtolower(($wave->name ?? '').' '.($wave->type->name ?? ''));

        return str_contains($haystack, 'faculty') ? 'faculty_program' : 'refresh';
    }
}
