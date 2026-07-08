@props([
    'infoPanelObj' => null,
])

{{-- Lessor = the financing company behind a leased device (a Supplier record
     playing the lessor role). Asset-only; guarded so it no-ops on other
     info-panel entities that have no lessor relation. --}}
@if (method_exists($infoPanelObj, 'lessor') && $infoPanelObj->lessor)
    <x-info-element icon_type="contract" icon_color="{{ $infoPanelObj->lessor->tag_color }}" title="{{ trans('general.lessor') }}">
        {{ trans('general.lessor') }}
        {!! $infoPanelObj->lessor->present()->nameUrl !!}
        <a class="pull-right js-copy-link" style="font-size: 16px; margin-right: 3px;" type="button" data-toggle="collapse" data-target="#lessorContact" aria-expanded="false" aria-controls="lessorContact">
            <x-icon type="plus" class="fa-fw"/>
        </a>
    </x-info-element>

    <span class="collapse" id="lessorContact">
        <x-info-element class="subitem well well-sm">
            <p style="line-height: 25px;">
                @if($infoPanelObj->lessor->contact)
                    <x-icon type="contact-card" class="fa-fw"/>
                    {{ $infoPanelObj->lessor->contact }}
                    <br>
                @endif
                @if($infoPanelObj->lessor->phone)
                    <x-icon type="phone" class="fa-fw"/>
                    <x-info-element.phone>{{ $infoPanelObj->lessor->phone }}</x-info-element.phone>
                    <br>
                @endif
                @if($infoPanelObj->lessor->email)
                    <x-icon type="email" class="fa-fw"/>
                    <x-info-element.email>{{ $infoPanelObj->lessor->email }}</x-info-element.email>
                    <br>
                @endif
                @if($infoPanelObj->lessor->url)
                    <x-icon type="external-link" class="fa-fw"/>
                    <x-info-element.url>{{ $infoPanelObj->lessor->url }}</x-info-element.url>
                    <br>
                @endif
                {!! nl2br($infoPanelObj->lessor->present()->displayAddress) !!}
            </p>
        </x-info-element>
    </span>
@endif
