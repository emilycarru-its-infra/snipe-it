<?php

return [
    'bad_regex'          => 'The pattern ":pattern" is not a valid regular expression.',
    'pattern_too_long'   => 'A pattern can be at most 255 characters.',
    'bad_license'        => 'That license does not exist.',
    'bad_fiscal_year'    => '":fy" is not a fiscal year. Use the FY2026-27 form.',

    'create' => [
        'success' => 'Product identity created.',
    ],

    'update' => [
        'success' => 'Product identity updated.',
    ],

    'delete' => [
        'confirm' => 'Delete this product identity? Its alias patterns and license links go with it; the licenses stay.',
        'success' => 'Product identity deleted.',
    ],
];
