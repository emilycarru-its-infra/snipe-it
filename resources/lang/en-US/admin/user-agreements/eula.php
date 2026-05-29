<?php

/**
 * Faculty Laptop Program agreement bodies, extracted from the Word
 * templates Sohee maintains at
 *   OneDrive: Devices/Procurement/Current/Faculty Program/
 * — Laptop Pickups Template.docx
 * — Upgrade Agreement Template.docx
 * — Lease End Purchase Template.docx
 *
 * Merge variables ({{faculty_name}}, {{asset_tag}}, ...) are
 * substituted at PDF time by UserAgreement::eulaBody().
 */
return [

    'pickup_title' => 'Faculty Laptop Receipt Acknowledgment',
    'pickup_body' => "Emily Carr University Faculty Laptop Program\n".
        "Laptop Receipt Acknowledgment\n\n".
        "I, {{faculty_name}}, acknowledge receipt of a laptop from Emily Carr University with:\n\n".
        "Serial Number: {{serial}}\n".
        "Asset Tag: {{asset_tag}}\n".
        "Model: {{model}}\n\n".
        "Terms and Conditions\n\n".
        "I will return the laptop promptly to the University upon employment termination.\n\n".
        "Laptop leases are 4 years in duration. I will return the laptop to the University promptly after receiving a laptop return request in the year the laptop's lease is expiring.\n\n".
        "I understand that I may be requested to briefly return the laptop for administrative or technical reasons.\n\n".
        "CARE: I will handle the laptop with due care and take precautions against possible damages.\n\n".
        "PHYSICAL SECURITY: I will take precautions to reduce the possibilities of theft. I will not leave the laptop unattended in a vehicle.\n\n".
        "DATA SECURITY: I will take precautions to encrypt sensitive information on the laptop.\n\n".
        "SECURITY THREATS: I will not disable or alter the laptop's configured anti-virus software or operating system updates.\n\n".
        "SOFTWARE: I will abide by all software license agreements and will not install unlicensed software on the laptop.\n\n".
        "Date of pickup: {{pickup_date}}",

    'upgrade_title' => 'Faculty Laptop Upgrade Agreement',
    'upgrade_body' => "Faculty Laptop Upgrade Agreement\n\n".
        "I, {{faculty_name}}, acknowledge receipt of a laptop from Emily Carr University.\n\n".
        "Device Details\n".
        "Model: {{model}}\n".
        "Asset Tag: {{asset_tag}}\n\n".
        "Cost Breakdown\n".
        "Base Program Price: {{base_price}}\n".
        "Full Purchase Cost: {{full_price}}\n".
        "Upgrade Amount: {{upgrade_amount}}\n".
        "Monthly Payment (24 months): {{monthly_24}}\n\n".
        "Agreement\n\n".
        "The Faculty Laptop Program covers the full cost of the base model. For laptop models that incur an additional charge, I hereby agree to pay the upgrade amount via payroll deductions over the course of 24 months.\n\n".
        "Alternative payment options:\n".
        "  [ ] 12-month payment plan: {{monthly_12}}/month\n".
        "  [ ] 24-month payment plan: {{monthly_24}}/month (recommended)\n\n".
        "Date: {{date}}",

    'purchase_title' => 'Faculty Laptop Purchase Agreement',
    'purchase_body' => "Faculty Laptop Purchase Agreement\n\n".
        "I, {{faculty_name}}, hereby acknowledge I would like to purchase the laptop {{model}} with serial number {{serial}} (Asset Tag: {{asset_tag}}) which I have been using as my assigned device under the Faculty Laptop Program.\n\n".
        "I agree to pay the residual cost of {{buyout_cost}} via one-time payroll deduction.\n\n".
        "I agree that I am purchasing the laptop in its as-is condition.\n\n".
        "I agree and understand that this device will be un-managed by Emily Carr and thus is to be treated as a personally owned device and that ITS is no longer able and willing to provide technical support for this laptop.\n\n".
        "Date: {{date}}",

];
