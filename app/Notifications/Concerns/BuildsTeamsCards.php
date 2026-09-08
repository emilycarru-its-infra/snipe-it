<?php

namespace App\Notifications\Concerns;

use App\Models\Asset;
use App\Services\Teams\TeamsCard;
use Illuminate\Database\Eloquent\Model;

/**
 * The pieces every Teams card in this app shares.
 *
 * The cards these replace named an item once, as text, and stopped there — so
 * a reader could not tell two devices apart when someone had renamed one, and
 * could not get from the card to the record without searching for it. Every
 * card built through here identifies the item by tag and serial, links to it,
 * and says who did the thing and when.
 */
trait BuildsTeamsCards
{
    /**
     * Start a card. The footer carries the actor and the event's own time —
     * the time on the Teams message is when the flow ran, which can be minutes
     * later and is not the same fact.
     */
    protected function teamsCard(string $title, string $accent = 'accent', ?Model $actor = null): TeamsCard
    {
        $card = TeamsCard::make($title)->accent($accent);

        $when = now()->timezone(config('app.timezone'))->format('D, M j \a\t g:ia');
        $who = $actor ? $this->teamsTargetName($actor) : null;

        return $card->footer($who ? $who.' · '.$when : $when);
    }

    /**
     * The facts that identify an asset. The tag and serial are what someone
     * pastes into a search; the name alone is not, because names get reused
     * and renamed.
     *
     * @return array<string, mixed>
     */
    protected function teamsAssetFacts(?Asset $asset): array
    {
        if (! $asset) {
            return [];
        }

        return [
            trans('general.asset_tag') => $asset->asset_tag,
            trans('admin/hardware/form.serial') => $asset->serial,
            trans('admin/hardware/form.model') => $asset->model?->getAttribute('name'),
        ];
    }

    /**
     * The one-line description under a card's title: the item's own name, then
     * its model, so the card reads as being about a specific machine.
     */
    protected function teamsAssetSubtitle(?Asset $asset): ?string
    {
        if (! $asset) {
            return null;
        }

        $name = htmlspecialchars_decode((string) $asset->getAttribute('display_name'));
        $model = $asset->model?->getAttribute('name');

        return $model && ! str_contains($name, (string) $model) ? $name.' — '.$model : ($name ?: null);
    }

    /**
     * Where an asset is, falling back to where it belongs. A check-in to stock
     * leaves location_id null, which is why the old card showed "Checked into"
     * with nothing beside it.
     */
    protected function teamsLocation(?Asset $asset): ?string
    {
        if (! $asset) {
            return null;
        }

        $location = $asset->location ?? $asset->defaultLoc;

        return $location?->getAttribute('name');
    }

    /**
     * The record page for a model, when it has one. Returns null rather than a
     * broken link for anything that does not present a viewUrl.
     */
    protected function teamsUrl($model): ?string
    {
        if (! $model || ! method_exists($model, 'present')) {
            return null;
        }

        try {
            $presenter = $model->present();

            return method_exists($presenter, 'viewUrl') ? $presenter->viewUrl() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * How to name whoever an item was checked out to — a user, another asset,
     * or a location all answer to different accessors.
     */
    protected function teamsTargetName($target): ?string
    {
        if (! $target) {
            return null;
        }

        foreach (['display_name', 'name'] as $attribute) {
            // getAttribute() rather than a dynamic property read: a User, an
            // Asset and a Location all answer to different accessors, and only
            // one of the three is guaranteed to have either of these.
            $value = $target instanceof Model ? $target->getAttribute($attribute) : ($target->{$attribute} ?? null);

            if (filled($value)) {
                return htmlspecialchars_decode((string) $value);
            }
        }

        return null;
    }
}
