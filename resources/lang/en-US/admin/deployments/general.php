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

    'count' => 'Count',
    'total' => 'Total',

    // Forecast (auto-collect)
    'forecast_title' => 'Refresh Forecast',
    'forecast_help' => 'Devices whose End of Life or lease-end date falls in the selected fiscal year. Check the ones to plan and add them to a wave as replacement items.',
    'forecast_summary' => ':count device(s) due for refresh in :fy',
    'forecast_lease_missing' => 'No "Lease End Date" custom field in this environment — only native end-of-life dates are used.',
    'add_from_forecast' => 'Add devices from forecast',
    'forecast_no_candidates' => 'No devices found for this fiscal year.',
    'forecast_choose_fy' => 'Choose a fiscal year to see refresh candidates.',
    'forecast_added' => 'Added :count device(s) to the wave.',
    'forecast_no_wave' => 'Pick an existing wave or name a new one.',
    'forecast_col_decision' => 'Decision',
    'plan_builder_title' => 'Plan a wave',
    'target_wave' => 'Target wave',
    'new_wave_name' => 'or create a new wave named',
    'refresh_reason' => 'Reason',
    'source_date' => 'Due',
    'reason_eol' => 'End of Life',
    'reason_lease' => 'Lease End',
    'reason_both' => 'Lease End',

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
    'storage_location' => 'Staging Location',
    'owner' => 'Owner',
    'purchase_order' => 'Purchase Order',
    'color' => 'Color',
    'notes' => 'Notes',

    // Item / board columns
    'item' => 'Device',
    'device' => 'Device',
    'replaces' => 'Replaces',
    'model' => 'Model',
    'projected_replacement' => 'Projected Replacement',
    'replacement' => 'Replacement',
    'usage_assigned' => 'Individually assigned',
    'usage_shared' => 'Shared fleet',
    'projected_cost_total' => 'Projected replacement spend: $:total',
    'projected_cost_help' => 'Comparable current models priced from the store catalog; devices without a mapping use the replaced device\'s original cost.',
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
    'catalog_new_type' => 'New wave type…',
    'catalog_new_stage' => 'New stage…',
    'decom_future_none' => 'A future year has no outgoing work yet.',

    // Download
    'download' => 'Download',

    // Timeline (P2a)
    'timeline_title' => 'Timeline',
    'waves_title' => 'Waves',
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

    // Staff availability blackouts (P4)
    'blackouts_title' => 'Staffing',
    'blackouts_button' => 'Staffing',
    'blackout_create' => 'New Time Off',
    'blackout_update' => 'Edit Time Off',
    'blackout_add' => 'Add time off',
    'blackout_staff' => 'Staff member',
    'blackout_start' => 'Start',
    'blackout_end' => 'End',
    'blackout_reason' => 'Reason',
    'blackout_source' => 'Source',
    'blackout_source_manual' => 'Manual',
    'blackout_source_graph' => 'Calendar',
    'blackout_saved' => 'Time off saved.',
    'blackout_deleted' => 'Time off removed.',
    'blackout_delete_confirm' => 'Remove this time-off entry?',
    'blackout_none' => 'No staff time off recorded. Add a window to see it overlaid on the timeline.',
    'blackout_user_unknown' => 'No matching user for the supplied id or email.',
    'blackout_unknown_user' => 'Unknown staff member',
    'blackout_synced_readonly' => 'Synced blackouts are managed by the calendar sync and cannot be edited here.',

    // Timeline overlay / collision (P4)
    'timeline_blackouts_label' => 'Staff OOO',
    'timeline_collision_tooltip' => 'Deploy window overlaps staff time off',
    'timeline_collision_callout' => ':count wave(s) overlap staff time off',

    // Stage rail — the per-device funnel across the filtered waves.
    'rail_title' => 'Device Flow',
    'rail_hint' => 'Every device sits in one stage — click a chevron to see exactly that list, click it again to show all.',
    'rail_devices' => 'devices',

    // Unified device table under the rail (wave items + refresh backlog +
    // order lines + past-year reconstruction).
    'flow_devices_title' => ':count device(s) · :fy',
    'flow_stage_suffix' => 'in :stage',
    'flow_stage_empty' => 'No devices in this stage.',
    'flow_backlog_chip' => 'Backlog',
    'flow_backlog_stage' => 'Planned — no wave',
    'flow_backlog_note' => ':count of these are due for refresh and not on a wave yet.',
    'flow_backlog_note_past' => ':count device(s) were due for refresh in :fy and never got refreshed — they are still the open plan, counted under Planned.',
    'flow_empty' => 'No devices in this fiscal year\'s flow.',
    'flow_group_label' => 'Group',
    'flow_group_none' => 'None',
    'flow_group_type' => 'Type',
    'flow_group_model' => 'Model',
    'flow_group_group' => 'Group',
    'flow_group_wave' => 'Wave',

    // Announcing a wave to the people in it. These are annual emails whose
    // wording barely changes year to year, so the form opens on a draft rather
    // than an empty box; the merge fields are the part that used to be retyped,
    // and mis-typed.
    'checked_out_to' => 'Checked Out To',
    'roster_title' => 'Who Was Invited',
    'roster_ordered_count' => ':ordered of :total have ordered',
    'roster_person' => 'Person',
    'roster_device' => 'Current device',
    'roster_due' => 'Due',
    'roster_due_eol' => 'end of life',
    'roster_due_lease' => 'lease end',
    'roster_said' => 'They said',
    'roster_actual' => 'Actually',
    'roster_ordered' => 'Order',
    'roster_not_ordered' => 'not yet',
    'roster_no_answer' => 'no form yet',
    'roster_ineligible_warning' => '{1} One person in this wave has no device due for replacement — neither its lease end nor its end of life falls within the year. That is allowed, exceptions happen, but worth a look before the invitation goes out.|[2,*] :count people in this wave have no device due for replacement — neither lease end nor end of life falls within the year. That is allowed, exceptions happen, but worth a look before the invitation goes out.',
    'roster_reason_not_due' => 'Neither the lease end nor the end of life falls within the next year.',
    'roster_reason_no_dates' => 'Their device has neither a lease end date nor an end-of-life date recorded.',
    'announce_insert_field' => 'Insert a field',
    'announce_insert_field_prompt' => 'Add a value that changes per person…',
    'announce_field_first_name' => 'their first name',
    'announce_field_recipient' => 'their full name',
    'announce_field_device' => 'the device they hold, with its tag',
    'announce_field_device_model' => 'the model of that device',
    'announce_field_lease_end' => 'when its lease ends',
    'announce_field_lease_end_year' => 'the year the lease ends',
    'announce_field_wave' => 'this wave',
    'announce_field_year' => 'the year',
    'announce_field_fiscal_year' => 'the fiscal year',
    'announce_field_form_url' => 'the faculty program form',
    'announce_field_store_url' => 'the store',
    'announce_cc' => 'Also copy',
    'announce_cc_help' => 'Copied on every one of these emails. Pick people, not typed addresses.',
    'announce_test_to' => 'Send the test to',
    'announce_test_to_help' => 'Leave empty and it comes to you. More than one pair of eyes on an annual letter is the normal case.',
    'announce_save_template' => 'Update Template',
    'announce_template_saved' => 'Saved wording (last used)',
    'announce_template_saved_confirm' => 'Template saved. The next announcement opens on this wording, and the shipped defaults are still in the picker.',
    'announce_template_replace' => 'Replace what you have written with this template?',
    'announce_title' => 'Email the People in This Wave',
    'announce_help' => 'One email per person, written against their own device and lease dates. Sending it starts the wave.',
    'announce_recipients' => 'Goes to :count people in this wave',
    'announce_no_recipients' => 'Nobody in this wave has a device checked out to them, so there is nobody to email. Assign the devices first.',
    'announce_template' => 'Start from',
    'announce_template_faculty' => 'Faculty Laptop Program - annual invitation',
    'announce_template_refresh' => 'Refresh notice - advance warning of a swap',
    'announce_template_blank' => 'Blank',
    'announce_subject' => 'Subject',
    'announce_body' => 'Message',
    'announce_body_help' => 'Markdown. These resolve per person: :fields',
    'announce_submit' => 'Send to Everyone in the Wave',
    'announce_test_submit' => 'Send a test to me first',
    'announce_test_sent' => 'Test sent to :email, written against the first recipient\'s device. Nobody else was emailed and the wave was not started.',
    'announce_sent' => 'Sent to :count people. The wave has started.',
    'announce_partial' => 'These addresses failed and were not sent: :emails.',
    'announce_failed' => 'The announcement could not be sent: :error',
    'announced_at' => 'Announced',
    'announce_already' => 'Announced :date. Sending again re-sends the same message to the same people.',

    'announce_faculty_subject' => 'Faculty Laptop Program {{ year }} — new laptop time!',
    'announce_faculty_body' => 'Hello {{ first_name }},

I am pleased to announce that Emily Carr is continuing the University sponsored Faculty Laptop Program. This program provides leased Mac laptops to regular faculty on a four-year cycle. At the end of the fourth year, faculty are eligible to request a replacement leased laptop for another four years, and return the one they have.

**Action required: please respond through [this form]({{ form_url }}) to be included in this year\'s Faculty Laptop Program.**

### Your current laptop

Our records show you hold {{ device }}, on a lease ending {{ lease_end }}. The form asks whether you are returning it or would like to buy it at its residual value.

### This year\'s laptop

The base model this year is the 13-inch MacBook Air with 16GB of memory and a 1TB drive. If a different configuration suits your teaching or research better, the form is where to say so.

Two groups of regular faculty are eligible: those whose current faculty laptop is at the end of its lease, and those who have never received one. You have this email because you are in one of them.

### Ordering

Ordering happens in our own store now — no external site and no separate sign-in. Complete the form first; the store link follows it, and your order goes through our approvals from there.

If anything does not work as described, reply to this email and we will sort it out.',

    'announce_refresh_subject' => '{{ wave }} - your device is scheduled for replacement',
    'announce_refresh_body' => 'Hello {{ first_name }},

Your device is part of {{ wave }}, scheduled for {{ fiscal_year }}.

Our records show you hold {{ device }}, on a lease ending {{ lease_end }}. We will be in touch to arrange a time to swap it, and we will move your files and applications across as part of that.

Nothing is needed from you yet — this is advance notice so the timing is not a surprise.

Reply to this email if the device above is not the one you are using, or if there is a period that will not work for you.',
    'flow_group_location' => 'Location',
    'flow_selected' => ':count selected',
    'flow_move_to' => 'Move to',
    'flow_add_to_wave' => 'Add selected to wave',
    'flow_set_group' => 'Set group',
    'flow_gate_hint' => 'A device leaves Planned only once it sits on a real order line — ordering is a fact from procurement (requisition → purchase order), not a board move.',
    'bulk_moved' => 'Moved :count device(s) to :stage.',
    'bulk_gated' => ':count device(s) stayed in Planned — not linked to an order line yet. Link each device to its procurement order line to move it forward.',
    'group_set' => ':count device(s) grouped as ":group".',
    'group_cleared' => 'Group cleared on :count device(s).',

    // Derived rows: procurement order lines and past-year reconstruction.
    'flow_order_chip' => 'Order',
    'flow_order_stage_ordered' => 'Ordered',
    'flow_order_stage_arrived' => 'Arrived',
    'flow_order_stage_deployed' => 'Deployed',
    'flow_history_chip' => 'History',
    'flow_requisition_chip' => 'Requisition',
    'flow_requisition_stage' => 'Planned — requisition',
    'hist_stage_ordered' => 'Purchased',
    'hist_stage_inventoried' => 'Inventoried',
    'hist_stage_deployed' => 'Deployed',

    // Decommissioning lane — the reverse flow (lease returns, donations,
    // recycling).
    'decom_title' => 'Decommissioning — Outgoing Devices',
    'decom_nav' => 'Decommissioning',
    'decom_hint' => 'The reverse flow: devices leaving the fleet — lease returns, donations, recycling.',
    'decom_collecting' => 'Collecting',
    'decom_collecting_note' => 'on a Processing status — being gathered, wiped, packed',
    'decom_decommissioned' => 'Decommissioned',
    'decom_decommissioned_note' => 'decommission date stamped — returned / donated / recycled',
    'decom_unarchived_note' => ':count of these are not parked on an archived status yet.',
    'decom_locations' => 'Holding locations',
    'decom_none' => 'Nothing is in decommissioning right now.',
    'decom_open_disposition' => 'Disposition Grid',
    'decom_bucket_returns' => 'Returns',
    'decom_bucket_donations' => 'Donations',
    'decom_bucket_recycling' => 'Recycling',
    'holding_location_label' => 'Set holding location',
    'holding_location_apply' => 'Apply',
    'holding_location_set' => ':count device(s) moved to :location.',
    // Pickups: decommissioned devices grouped by decommission date — each
    // date is one physical lease-return / donation / recycling run.
    'decom_pickups_title' => 'Pickups & disposal runs',
    'decom_pickups_hint' => 'grouped by decommission date — each date is one lease return, donation or recycling run',
    'pickup_col_date' => 'Pickup date',
    'pickup_col_devices' => 'Devices',
    'pickup_col_models' => 'Models',
    'pickup_col_locations' => 'From locations',
    'pickup_col_lessors' => 'Lease company',
    'pickup_csv' => 'CSV',
    'decom_no_pickups' => 'No decommission dates recorded in this fiscal year.',
    // Aging fleet box (shown on procurement + fleet health).
    'legacy_box_title' => 'Aging Fleet',
    'legacy_box_subtitle' => 'Operational Risk, degraded student experience',
    'legacy_title' => 'Legacy fleet — :count devices with no funded replacement',
    'legacy_note' => 'No replacement money was pre-approved for these devices — they age until funding is found.',
    'legacy_age_note' => 'avg age :age yrs · oldest :oldest',
    'legacy_view_devices' => 'View devices',
    'decom_permalink' => 'Link straight to this section',

    'decom_col_asset' => 'Asset Tag',
    'decom_col_model' => 'Model',
    'decom_col_status' => 'Status',
    'decom_col_location' => 'Location',
    'decom_col_lease_end' => 'Lease End',
];
