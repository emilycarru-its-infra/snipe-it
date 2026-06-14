<?php

return [
    // Board / entity
    'dashboard_title' => 'Deployments',
    'board_title' => 'Deployment Wave',
    'configure' => 'Configure',
    'forecast' => 'Forecast',
    'add_wave' => 'New Wave',
    'wave' => 'Wave',
    'waves' => 'Waves',
    'no_waves' => 'No deployment waves for this fiscal year yet.',
    'no_items' => 'No devices on this wave yet.',

    // Filters
    'filter_fiscal_year' => 'Fiscal Year',
    'filter_type' => 'Type',
    'filter_stage' => 'Stage',
    'all_types' => 'All types',
    'all_stages' => 'All stages',

    // Widgets
    'widget_stage' => 'By Stage',
    'widget_type' => 'By Type',
    'widget_model' => 'By Model',
    'count' => 'Count',
    'total' => 'Total',

    // Forecast (auto-collect)
    'forecast_title' => 'Refresh Forecast',
    'forecast_help' => 'Devices whose end-of-life or lease-end date falls in the selected fiscal year. Check the ones to plan and add them to a wave as replacement items.',
    'forecast_summary' => ':count device(s) due for refresh in :fy',
    'forecast_lease_missing' => 'No "Lease End Date" custom field in this environment — only native end-of-life dates are used.',
    'add_from_forecast' => 'Add devices from forecast',
    'forecast_no_candidates' => 'No devices found for this fiscal year.',
    'forecast_choose_fy' => 'Choose a fiscal year to see refresh candidates.',
    'forecast_added' => 'Added :count device(s) to the wave.',
    'forecast_no_wave' => 'Pick an existing wave or name a new one.',
    'target_wave' => 'Target wave',
    'new_wave_name' => 'or create a new wave named',
    'refresh_reason' => 'Reason',
    'source_date' => 'Due',
    'reason_eol' => 'End of life',
    'reason_lease' => 'Lease end',
    'reason_both' => 'EOL + lease',

    // Wave fields
    'name' => 'Name',
    'fiscal_year' => 'Fiscal Year',
    'deployment_type' => 'Type',
    'wave_state' => 'State',
    'arrival_window_start' => 'Arrival window start',
    'arrival_window_end' => 'Arrival window end',
    'target_start_date' => 'Deploy window start',
    'target_end_date' => 'Deploy window end',
    'arrival_window' => 'Arrival',
    'deploy_window' => 'Deploy',
    'location' => 'Target Location',
    'storage_location' => 'Staging / Storage Location',
    'owner' => 'Owner',
    'purchase_order' => 'Purchase Order',
    'color' => 'Color',
    'notes' => 'Notes',

    // Item / board columns
    'item' => 'Device',
    'device' => 'Device',
    'replaces' => 'Replaces',
    'model' => 'Model',
    'recipient' => 'Recipient',
    'tech' => 'Tech',
    'stage' => 'Stage',
    'target_deploy_date' => 'Target Deploy',
    'storage' => 'Storage',
    'add_item' => 'Add device',
    'update_stage' => 'Update stage',

    // CRUD / config labels
    'create' => 'New Deployment Wave',
    'update' => 'Update Deployment Wave',
    'created' => 'Deployment wave created.',
    'updated' => 'Deployment wave updated.',
    'deleted' => 'Deployment wave deleted.',
    'delete_confirm' => 'Are you sure you want to delete this deployment wave?',
    'item_added' => 'Device added to wave.',
    'item_updated' => 'Device updated.',
    'item_deleted' => 'Device removed from wave.',
    'item_delete_confirm' => 'Remove this device from the wave?',
    'stage_updated' => 'Stage updated.',

    // Catalogs (configure)
    'catalog_types' => 'Wave Types',
    'catalog_stages' => 'Stages',
    'catalog_name' => 'Name',
    'catalog_color' => 'Color',
    'catalog_sort' => 'Sort',
    'catalog_active' => 'Active',
    'catalog_terminal' => 'Terminal (deployed)',
    'catalog_maps_to_status' => 'Maps to Snipe status',
    'catalog_maps_to_status_help' => 'Optional. Advancing a device to this stage flips its asset status to this label.',
    'catalog_none' => '— none —',
    'catalog_saved' => 'Saved.',
    'catalog_deleted' => 'Deleted.',
    'catalog_in_use_deactivated' => 'In use by existing waves/devices — deactivated instead of deleted.',
    'catalog_delete_confirm' => 'Delete this entry?',

    // Download
    'download' => 'Download',

    // Timeline (P2a)
    'timeline_title' => 'Timeline',
    'timeline_legend_arrival' => 'Arrival window',
    'timeline_legend_deploy' => 'Deploy window',
    'timeline_no_dates' => 'No dates set',
    'timeline_empty' => 'No waves to plot. Add a wave with arrival or deploy dates to see the timeline.',

    // Arrivals (P2b)
    'arrivals_title' => 'Arrivals',
    'arrivals_summary' => ':received/:linked received · :in_transit in transit',
    'arrivals_received' => 'Received',
    'arrivals_in_transit' => 'In transit',
    'arrivals_not_ordered' => 'Not ordered',
    'arrivals_none_linked' => 'No devices on this wave are linked to an order line yet.',
    'arrivals_tracking' => 'Tracking',
    'arrival_status' => 'Arrival',

    // Storage (P3)
    'storage_title' => 'Storage',
    'storage_capacity' => 'Capacity',
    'storage_staged' => 'Staged',
    'storage_over_capacity' => ':count over capacity',
    'storage_uncapped' => 'No capacity set',
    'storage_unassigned' => 'Unassigned (no storage location)',
    'storage_no_locations' => 'No locations have a storage capacity set. Set one on a location to track staging here.',
    'storage_waves_here' => 'Waves staging here',
    'storage_no_devices' => 'No staged devices here.',
];
