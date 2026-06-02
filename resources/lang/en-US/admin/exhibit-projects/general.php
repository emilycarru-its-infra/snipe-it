<?php

return [
    // Entity / board
    'dashboard_title' => 'Exhibit Tracking',
    'exhibit_projects' => 'Exhibit Projects',
    'project' => 'Exhibit Project',
    'create' => 'New Exhibit Project',
    'update' => 'Update Exhibit Project',
    'add_project' => 'Add Project',
    'email_templates' => 'Email Templates',
    'send_to_approved' => 'Send confirmation to all approved',
    'no_projects' => 'No projects for this show and year yet.',

    // Filters
    'filter_show' => 'Show',
    'filter_year' => 'Year',
    'filter_status' => 'Status',
    'all_statuses' => 'All statuses',

    // Fields
    'show' => 'Show',
    'year' => 'Year',
    'student' => 'Student',
    'student_name' => 'Student name (if not a Snipe user)',
    'asset' => 'Assigned Device',
    'status' => 'Status',
    'project_type' => 'Project Type',
    'project_details' => 'Project Details',
    'requested_device' => 'Requested Device(s)',
    'peripherals' => 'Peripherals',
    'submitted_file' => 'Submitted File',
    'approved' => 'Approved',
    'tdx_id' => 'TDX ID',
    'notes' => 'Notes',
    'name' => 'Name',
    'assigned_asset' => 'Assigned Asset',
    'sign_status' => 'Sign Status',

    // Widgets
    'widget_project_type' => 'Project Type',
    'widget_status' => 'Status',
    'widget_device' => 'Requested Devices',
    'count' => 'Count',
    'total' => 'Total',

    // Row actions
    'send_email' => 'Send email',
    'choose_template' => 'Choose a template',
    'edit' => 'Edit',

    // Email outcomes
    'email_sent' => 'Email sent to :name.',
    'email_failed' => 'The email could not be sent — check the logs.',
    'email_no_recipient' => 'This project has no linked student email address.',
    'email_bulk_done' => 'Sent :sent email(s); :skipped skipped (no email address).',

    // CRUD messages
    'created' => 'Exhibit project created.',
    'updated' => 'Exhibit project updated.',
    'deleted' => 'Exhibit project deleted.',
    'delete_confirm' => 'Are you sure you want to delete this exhibit project?',

    // Email templates editor
    'template' => 'Email Template',
    'template_key' => 'Key',
    'template_name' => 'Name',
    'template_subject' => 'Subject',
    'template_body' => 'Body',
    'template_enabled' => 'Enabled',
    'template_updated' => 'Email template saved.',
    'merge_vars' => 'Merge variables',
    'merge_vars_help' => 'These placeholders are filled in per student when the email is sent. Year-specific pickup dates and links should be typed directly into the body each cycle.',

    // Status values (Numbers-sheet dropdown)
    'status_value_none' => 'None',
    'status_value_pending' => 'Pending',
    'status_value_need_to_contact' => 'Need to Contact',
    'status_value_reserved' => 'Reserved',
    'status_value_waitlisted' => 'Waitlisted',
    'status_value_scheduled' => 'Scheduled',
    'status_value_in_progress' => 'In Progress',
    'status_value_done' => 'Done',
    'status_value_cancelled' => 'Cancelled',
    'status_value_self_setup' => 'Self Setup',
    'status_value_master_student' => 'Master Student',
    'status_value_early_setup' => 'Early Setup',
    'status_value_ready' => 'Ready',
    'status_value_late' => 'Late',
    'status_value_undetermined' => 'Undetermined',
    'status_value_media_resources' => 'Media Resources',

    // Project type values
    'type_value_looping_video' => 'Looping Video',
    'type_value_website' => 'Website',
    'type_value_specialized_app' => 'Specialized App',
    'type_value_figma' => 'Figma',
    'type_value_audio' => 'Audio',
    'type_value_looping_pdf' => 'Looping PDF',
    'type_value_other' => 'Other',
];
