<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registered forms
    |--------------------------------------------------------------------------
    |
    | Each entry declares one form module. The 'class' references a class
    | extending App\Forms\FormDefinition that owns the form's logic, views,
    | and storage. Form ↔ group bindings (who can submit) live in the
    | form_eligibility table and are managed via Admin → Settings → Forms.
    |
    */

    'modules' => [
        'faculty-program' => [
            'class'           => \App\Forms\FacultyProgram\FacultyProgramForm::class,
            'label_key'       => 'admin/forms/faculty-program.title',
            'description_key' => 'admin/forms/faculty-program.tile_description',
            'icon'            => 'fas fa-laptop',
        ],
    ],

    'faculty_program' => [
        'external_purchase_url' => env('USER_FORM_EXTERNAL_PURCHASE_URL', 'https://cdw.ca/emilycarru'),
    ],

];
