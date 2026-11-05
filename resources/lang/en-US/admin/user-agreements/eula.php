<?php

// Canonical text shared by PDF previews, signing requests and accepted copies.
return [
    'pickup_title' => "Faculty Laptop Receipt Acknowledgment",
    'pickup_body' =>
        "Emily Carr University Faculty Laptop Program\nLaptop Receipt Acknowledgment\n\n".
        "I, {{faculty_name}}, acknowledge receipt of a laptop from Emily Carr University with:\nSerial Number: {{serial}}\nAsset Tag: {{asset_tag}}\nModel: {{model}}\n\n".
        "I will return the laptop promptly to the University upon employment termination. Laptop leases are {{lease_years}} years in duration. I will return the laptop to the University promptly after receiving a laptop return request in the year the laptop's lease is expiring. I understand that I may be requested to briefly return the laptop for administrative or technical reasons.\n\n".
        "COST\nThe program covers the full cost of the base model we offer. Any additional charge for an upgraded model and its selected payment method are recorded in a separate upgrade agreement.\n\n".
        "CARE\nI will handle the laptop with due care and take precautions against possible damages. The leasing company will accept returned end of lease laptops with normal wear and tear, defined as faded lettering on keyboard, minor scratches on cover or base, minor scratches on monitor screen, and removable institution labels or tags.\nNormal wear and tear does not include broken hinge or latch, cracked lid or frame, the addition of non-institution stickers, labels or tags, missing or broken components (power adapters), or more than minor scratches to the screen or case.\n\n".
        "PHYSICAL SECURITY\nI will take precautions to reduce the possibilities of theft. I will not leave the laptop unattended in a vehicle, and will consider security and take precautions when I leave the laptop unattended. I will promptly notify my Dean (or Supervisor), Facilities, and the Information Technology Services Help Desk if the laptop is missing or stolen. If the laptop is stolen, I will file a police report and provide Facilities and ITS with a copy of the report.\n\n".
        "DATA SECURITY\nI will take precautions to encrypt sensitive information on the laptop. Generally, information that has the potential for privacy concerns or could negatively impact the University's security, reputation, and business should be encrypted on my laptop.\n\n".
        "SECURITY THREATS\nI will not disable or alter the laptop's configured anti-virus software. I will not disable the laptop's configured operating system updates. I will regularly check for operating system updates. I will regularly acknowledge and proceed with the installation of operating system updates. I will contact ITS with any concerns regarding the anti-virus software, operating system updates, or any other security related issue.\n\n".
        "SOFTWARE\nI will abide by all software license agreements associated with the software installed on the laptop and/or provided to me on media. I will not install unlicensed software on the laptop.\n\n".
        "Date of pickup: {{pickup_date}}",
    'upgrade_title' => "Faculty Laptop Upgrade Agreement",
    'upgrade_body' =>
        "Faculty Laptop Upgrade Agreement\n\n".
        "I, {{faculty_name}}, acknowledge receipt of a laptop from Emily Carr University of Art + Design and agree to repay the additional costs to lease this upgraded laptop.\n\n".
        "Model: {{model}}\nSerial Number: {{serial}}\nAsset Tag: {{asset_tag}}\n\n".
        "Base Program Price: {{base_price}}\nFull Purchase Cost: {{full_price}}\nUpgrade Amount: {{upgrade_amount}}\n\n".
        "1. I agree to repay the additional cost of {{upgrade_amount}} using the following payment option:\n{{upgrade_payment_terms}}\n\n".
        "2. I agree to return the laptop to Emily Carr should cessation of employment with Emily Carr occur during the loan period. Otherwise I agree to repay the loan as above and return the laptop after a {{return_term_months}} month term, unless Emily Carr approval is granted stating otherwise.\n\n".
        "3. I accept that a taxable benefit will accrue to me if I choose to receive an interest free loan and I agree not to hold Emily Carr responsible for any rulings, decisions, or actions by Canada Revenue Agency arising in this regard.\n\n".
        "Date: {{date}}",
    'purchase_title' => "Faculty Laptop Purchase Agreement",
    'purchase_body' =>
        "Faculty Laptop Purchase Agreement\n\n".
        "I, {{faculty_name}}, hereby acknowledge I would like to purchase the laptop {{model}} with serial number {{serial}} (Asset Tag: {{asset_tag}}) which I have been using as my assigned device under the Faculty Laptop Program.\n\n".
        "{{purchase_price_terms}}\n\n".
        "I agree that I am purchasing the laptop in its as-is condition.\n\n".
        "I agree and understand that this device will be un-managed by Emily Carr and thus is to be treated as a personally owned device and that ITS is no longer able and willing to provide technical support for this laptop.\n\n".
        "Date: {{date}}",
];
