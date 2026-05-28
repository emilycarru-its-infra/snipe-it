@props([
    'count' => null,
    'class' => false,
])

<x-tabs.nav-item
    :$class
    name="agreements"
    icon="fas fa-file-signature"
    label="{{ trans('admin/forms/general.agreements_tab_label') }}"
    count="{{ $count }}"
    tooltip="{{ trans('admin/forms/general.agreements_tab_label') }}"
/>
