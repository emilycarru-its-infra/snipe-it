<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    | The following language lines are used in the user permissions system.
    | Each permission has a 'name' and a 'note' that describes
    | the permission in detail.
    |
    | DO NOT edit the keys (left-hand side) of each permission as these are
    | used throughout the system for translations.
    |---------------------------------------------------------------------------
    */

    'superuser' => [
        'name' => 'Super User',
        'note' => 'Determines whether the user has full access to all aspects of the admin. This setting overrides ALL more specific and restrictive permissions throughout the system. ',
    ],
    'admin' => [
        'name' => 'Admin Access',
        'note' => 'Determines whether the user has access to most aspects of the system EXCEPT the System Admin Settings. These users will be able to manage users, locations, categories, etc, but ARE constrained by Full Multiple Company Support if it is enabled.',
    ],

    'import' => [
        'name' => 'CSV Import',
        'note' => 'This will allow users to import even if access to users, assets, etc is denied elsewhere.',
    ],

    'reports' => [
        'name' => 'Reports',
        'note' => 'Grants access to the Reports section of the application. Use the sub-permissions below to allow access to individual reports.',
    ],

    'reportsview' => [
        'name' => 'View Reports Landing Page',
        'note' => 'Allows the user to load the Reports index page. Access to individual reports is controlled by the more specific permissions below.',
    ],

    'reportscustomview' => [
        'name' => 'Custom Asset Report',
        'note' => 'View and export the custom asset report, including managing saved report templates the user owns.',
    ],

    'reportsactivityview' => [
        'name' => 'Activity Report',
        'note' => 'View and export the activity log report (web and API), including the activity chart.',
    ],

    'reportsauditview' => [
        'name' => 'Audit Report',
        'note' => 'View the asset audit report.',
    ],

    'reportsdepreciationview' => [
        'name' => 'Depreciation Report',
        'note' => 'View and export the asset depreciation report.',
    ],

    'reportslicensesview' => [
        'name' => 'License Report',
        'note' => 'View and export the license report.',
    ],

    'reportsaccessoriesview' => [
        'name' => 'Accessory Report',
        'note' => 'View and export the accessory report.',
    ],

    'reportsmaintenancesview' => [
        'name' => 'Maintenance Report',
        'note' => 'View and export the asset maintenance report.',
    ],

    'reportsunacceptedview' => [
        'name' => 'Unaccepted Assets Report',
        'note' => 'View the unaccepted assets report, resend acceptance reminders, and delete pending acceptance records.',
    ],

    'reportstemplatesmanage' => [
        'name' => 'Manage Report Templates',
        'note' => 'Create, edit, and delete saved templates for the custom asset report. Requires Custom Asset Report access to view templates.',
    ],

    'reportsprocurementview' => [
        'name' => 'Procurement Reports',
        'note' => 'View the procurement reports section (PO budget, invoices, leases, capital, GL transfers, etc.).',
    ],

    'reportscontractsview' => [
        'name' => 'Contracts Reports',
        'note' => 'View the contracts reports dashboard and its sub-reports (umbrellas, fiscal-year rollups, serial register, etc.).',
    ],

    'assets' => [
        'name' => 'Assets',
        'note' => 'Grants access to the Assets section of the application.',
    ],

    'assetsview' => [
        'name' => 'View Assets',
    ],

    'assetscreate' => [
        'name' => 'Create New Assets',
    ],

    'assetsedit' => [
        'name' => 'Edit Assets',
    ],

    'assetsdelete' => [
        'name' => 'Delete Assets',
    ],

    'assetscheckin' => [
        'name' => 'Check In',
        'note' => 'Check assets back into inventory that are currently checked out.',
    ],

    'assetscheckout' => [
        'name' => 'Check Out',
        'note' => 'Assign assets in inventory by checking them out.',
    ],

    'assetsaudit' => [
        'name' => 'Audit Assets',
        'note' => 'Allows the user to mark an asset as physically inventoried.',
    ],

    'assetsrequest-buyout' => [
        'name' => 'Request Lease Buyout',
        'note' => 'Allows the user to send a lease buyout request to a leased asset\'s lessor from the asset page, without needing edit rights. Intended for HR / Finance operations staff.',
    ],

    'assetsviewrequestable' => [
        'name' => 'View Requestable Assets',
        'note' => 'Allows the user to view assets that are marked as requestable.',
    ],

    'assetsviewencrypted-custom-fields' => [
        'name' => 'View Encrypted Custom Fields',
        'note' => 'Allows the user to view and modify encrypted custom fields on assets.',
    ],

    'accessories' => [
        'name' => 'Accessories',
        'note' => 'Grants access to the Accessories section of the application.',
    ],

    'accessoriesview' => [
        'name' => 'View Accessories',
    ],
    'accessoriescreate' => [
        'name' => 'Create New Accessories',
    ],
    'accessoriesedit' => [
        'name' => 'Edit Accessories',
    ],
    'accessoriesdelete' => [
        'name' => 'Delete Accessories',
    ],
    'accessoriescheckout' => [
        'name' => 'Check Out Accessories',
        'note' => 'Assign accessories in inventory by checking them out.',
    ],
    'accessoriescheckin' => [
        'name' => 'Check In Accessories',
        'note' => 'Check accessories back into inventory that are currently checked out.',
    ],
    'accessoriesfiles' => [
        'name' => 'Manage Accessory Files',
        'note' => 'Allows the user to upload, download, and delete files associated with accessories. (This only makes sense with view privileges or higher.)',
    ],

    'assetsfiles' => [
        'name' => 'Manage Asset Files',
        'note' => 'Allows the user to upload, download, and delete files associated with assets. (This only makes sense with view privileges or higher.)',
    ],

    'usersfiles' => [
        'name' => 'Manage User Files',
        'note' => 'Allows the user to upload, download, and delete files associated with users. (This only makes sense with view privileges or higher.)',
    ],

    'modelsfiles' => [
        'name' => 'Manage Model Files',
        'note' => 'Allows the user to upload, download, and delete files associated with asset models on both the model view and the asset view screens. (This only makes sense with view privileges or higher.)',
    ],

    'departmentsfiles' => [
        'name' => 'Manage Department Files',
        'note' => 'Allows the user to upload, download, and delete files associated with departments. (This only makes sense with view privileges or higher.)',
    ],

    'suppliersfiles' => [
        'name' => 'Manage Supplier Files',
        'note' => 'Allows the user to upload, download, and delete files associated with suppliers. (This only makes sense with view privileges or higher.)',
    ],

    'locationsfiles' => [
        'name' => 'Manage Location Files',
        'note' => 'Allows the user to upload, download, and delete files associated with locations.(This only makes sense with view privileges or higher.)',
    ],

    'companiesfiles' => [
        'name' => 'Manage Company Files',
        'note' => 'Allows the user to upload, download, and delete files associated with companies. (This only makes sense with view privileges or higher.)',
    ],

    'consumablesfiles' => [
        'name' => 'Manage Consumable Files',
        'note' => 'Allows the user to upload, download, and delete files associated with consumables. (This only makes sense with view privileges or higher.)',
    ],

    'consumables' => [
        'name' => 'Consumables',
        'note' => 'Grants access to the Consumables section of the application.',
    ],
    'consumablesview' => [
        'name' => 'View Consumables',
    ],
    'consumablescreate' => [
        'name' => 'Create New Consumables',
    ],
    'consumablesedit' => [
        'name' => 'Edit Consumables',
    ],
    'consumablesdelete' => [
        'name' => 'Delete Consumables',
    ],
    'consumablescheckout' => [
        'name' => 'Check Out Consumables',
        'note' => 'Assign consumables in inventory by checking them out.',
    ],

    'licenses' => [
        'name' => 'Licenses',
        'note' => 'Grants access to the Licenses section of the application.',
    ],
    'licensesview' => [
        'name' => 'View Licenses',
    ],
    'licensescreate' => [
        'name' => 'Create New Licenses',
    ],
    'licensesedit' => [
        'name' => 'Edit Licenses',
    ],
    'licensesdelete' => [
        'name' => 'Delete Licenses',
    ],
    'licensescheckout' => [
        'name' => 'Assign Licenses',
        'note' => 'Allows the user to assign licenses to assets or users.',
    ],
    'licensescheckin' => [
        'name' => 'Unassign Licenses',
        'note' => 'Allows the user to unassign licenses from assets or users.',
    ],
    'licensesfiles' => [
        'name' => 'Manage License Files',
        'note' => 'Allows the user to upload, download, and delete files associated with licenses.',
    ],
    'componentsfiles' => [
        'name' => 'Manage Component Files',
        'note' => 'Allows the user to upload, download, and delete files associated with components.',
    ],

    'licenseskeys' => [
        'name' => 'Manage License Keys',
        'note' => 'Allows the user to view product keys associated with licenses.',
    ],

    'licensemodelsview' => [
        'name' => 'View License Models',
        'note' => 'View the list of license model definitions (SaaS, Product Key, License Server, etc.) that categorise licenses by behavior.',
    ],
    'licensemodelscreate' => [
        'name' => 'Create License Models',
    ],
    'licensemodelsedit' => [
        'name' => 'Edit License Models',
    ],
    'licensemodelsdelete' => [
        'name' => 'Delete License Models',
        'note' => 'Deleting a license model removes the categorization from any licenses that used it (they fall back to default Product Key behavior).',
    ],

    'components' => [
        'name' => 'Components',
        'note' => 'Grants access to the Components section of the application.',
    ],
    'componentsview' => [
        'name' => 'View Components',
    ],
    'componentscreate' => [
        'name' => 'Create New Components',
    ],
    'componentsedit' => [
        'name' => 'Edit Components',
    ],
    'componentsdelete' => [
        'name' => 'Delete Components',
    ],

    'componentscheckout' => [
        'name' => 'Check Out Components',
        'note' => 'Assign components in inventory by checking them out.',
    ],
    'componentscheckin' => [
        'name' => 'Check In Components',
        'note' => 'Check components back into inventory that are currently checked out.',
    ],
    'kits' => [
        'name' => 'Predefined Kits',
        'note' => 'Grants access to the Predefined Kits section of the application.',
    ],
    'kitsview' => [
        'name' => 'View Predefined Kits',
    ],
    'kitscreate' => [
        'name' => 'Create New Predefined Kits',
    ],
    'kitsedit' => [
        'name' => 'Edit Predefined Kits',
    ],
    'kitsdelete' => [
        'name' => 'Delete Predefined Kits',
    ],
    'users' => [
        'name' => 'Users',
        'note' => 'Grants access to the Users section of the application.',
    ],
    'usersview' => [
        'name' => 'View Users',
    ],
    'userscreate' => [
        'name' => 'Create New Users',
    ],
    'usersedit' => [
        'name' => 'Edit Users',
    ],
    'usersdelete' => [
        'name' => 'Delete Users',
    ],
    'models' => [
        'name' => 'Models',
        'note' => 'Grants access to the Models section of the application.',
    ],
    'modelsview' => [
        'name' => 'View Models',
    ],

    'modelscreate' => [
        'name' => 'Create New Models',
    ],
    'modelsedit' => [
        'name' => 'Edit Models',
    ],
    'modelsdelete' => [
        'name' => 'Delete Models',
    ],
    'categories' => [
        'name' => 'Categories',
        'note' => 'Grants access to the Categories section of the application.',
    ],
    'categoriesview' => [
        'name' => 'View Categories',
    ],
    'categoriescreate' => [
        'name' => 'Create New Categories',
    ],
    'categoriesedit' => [
        'name' => 'Edit Categories',
    ],
    'categoriesdelete' => [
        'name' => 'Delete Categories',
    ],
    'departments' => [
        'name' => 'Departments',
        'note' => 'Grants access to the Departments section of the application.',
    ],
    'departmentsview' => [
        'name' => 'View Departments',
    ],
    'departmentscreate' => [
        'name' => 'Create New Departments',
    ],
    'departmentsedit' => [
        'name' => 'Edit Departments',
    ],
    'departmentsdelete' => [
        'name' => 'Delete Departments',
    ],
    'locations' => [
        'name' => 'Locations',
        'note' => 'Grants access to the Locations section of the application.',
    ],
    'locationsview' => [
        'name' => 'View Locations',
    ],
    'locationscreate' => [
        'name' => 'Create New Locations',
    ],
    'locationsedit' => [
        'name' => 'Edit Locations',
    ],
    'locationsdelete' => [
        'name' => 'Delete Locations',
    ],
    'status-labels' => [
        'name' => 'Status Labels',
        'note' => 'Grants access to the Status Labels section of the application used by Assets.',
    ],
    'statuslabelsview' => [
        'name' => 'View Status Labels',
    ],
    'statuslabelscreate' => [
        'name' => 'Create New Status Labels',
    ],
    'statuslabelsedit' => [
        'name' => 'Edit Status Labels',
    ],
    'statuslabelsdelete' => [
        'name' => 'Delete Status Labels',
    ],
    'custom-fields' => [
        'name' => 'Custom Fields',
        'note' => 'Grants access to the Custom Fields section of the application used by Assets.',
    ],
    'customfieldsview' => [
        'name' => 'View Custom Fields',
    ],
    'customfieldscreate' => [
        'name' => 'Create New Custom Fields',
    ],
    'customfieldsedit' => [
        'name' => 'Edit Custom Fields',
    ],
    'customfieldsdelete' => [
        'name' => 'Delete Custom Fields',
    ],
    'suppliers' => [
        'name' => 'Suppliers',
        'note' => 'Grants access to the Suppliers section of the application.',
    ],
    'suppliersview' => [
        'name' => 'View Suppliers',
    ],
    'supplierscreate' => [
        'name' => 'Create New Suppliers',
    ],
    'suppliersedit' => [
        'name' => 'Edit Suppliers',
    ],
    'suppliersdelete' => [
        'name' => 'Delete Suppliers',
    ],
    'manufacturers' => [
        'name' => 'Manufacturers',
        'note' => 'Grants access to the Manufacturers section of the application.',
    ],
    'manufacturersview' => [
        'name' => 'View Manufacturers',
    ],
    'manufacturerscreate' => [
        'name' => 'Create New Manufacturers',
    ],
    'manufacturersedit' => [
        'name' => 'Edit Manufacturers',
    ],
    'manufacturersdelete' => [
        'name' => 'Delete Manufacturers',
    ],
    'companies' => [
        'name' => 'Companies',
        'note' => 'Grants access to the Companies section of the application.',
    ],
    'companiesview' => [
        'name' => 'View Companies',
    ],
    'companiescreate' => [
        'name' => 'Create New Companies',
    ],
    'companiesedit' => [
        'name' => 'Edit Companies',
    ],
    'companiesdelete' => [
        'name' => 'Delete Companies',
    ],
    'user-self-accounts' => [
        'name' => 'User Self Accounts',
        'note' => 'Grants non-admin users the ability to manage certain aspects of their own user accounts.',
    ],
    'selftwo-factor' => [
        'name' => 'Manage Two-Factor Authentication',
        'note' => 'Allows users to enable, disable, and manage two-factor authentication for their own accounts.',
    ],
    'selfapi' => [
        'name' => 'Manage API Tokens',
        'note' => 'Allows users to create, view, and revoke their own API tokens. User tokens will have the same permissions as the user who created them.',
    ],
    'selfedit-location' => [
        'name' => 'Edit Location',
        'note' => 'Allows users to edit the location associated with their own user account.',
    ],
    'selfcheckout-assets' => [
        'name' => 'Self Check Out Assets',
        'note' => 'Allows users to check out assets to themselves without admin intervention.',
    ],
    'selfview-purchase-cost' => [
        'name' => 'View Purchase Cost',
        'note' => 'Allows users to view the purchase cost of items in their account view.',
    ],

    'depreciations' => [
        'name' => 'Depreciation Management',
        'note' => 'Allows users to manage and view asset depreciation details.',
    ],
    'depreciationsview' => [
        'name' => 'View Depreciation Details',
    ],
    'depreciationsedit' => [
        'name' => 'Edit Depreciation Settings',
    ],
    'depreciationsdelete' => [
        'name' => 'Delete Depreciation Records',
    ],
    'depreciationscreate' => [
        'name' => 'Create Depreciation Records',
    ],

    'grant_all' => 'Grant all permissions for :area',
    'deny_all' => 'Deny all permissions for :area',
    'inherit_all' => 'Inherit all permissions for :area from permission groups',
    'grant' => 'Grant Permission for :area',
    'deny' => 'Deny Permission for :area',
    'inherit' => 'Inherit Permission for :area from permission groups',
    'use_groups' => 'We strongly suggest using Permission Groups instead of assigning individual permissions for easier management.',

];
