<?php

namespace App\Mail;

use App\Notifications\AcceptanceItemAcceptedNotification;
use App\Notifications\AcceptanceItemAcceptedToUserNotification;
use App\Notifications\AcceptanceItemDeclinedNotification;
use App\Notifications\CurrentInventory;
use App\Notifications\ExpectedCheckinAdminNotification;
use App\Notifications\ExpectedCheckinNotification;
use App\Notifications\FirstAdminNotification;
use App\Notifications\InventoryAlert;
use App\Notifications\RequestAssetCancelation;
use App\Notifications\RequestAssetNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Notification;

/**
 * The single source of truth for every email Snipe-IT can send, used by the
 * Settings → Emails CMS (App\Http\Controllers\EmailsController). Each entry
 * carries display metadata, the merge variables an admin may reference, and a
 * factory that builds the real Mailable from throwaway sample data
 * (App\Mail\EmailSampleData) so it can be previewed exactly as recipients see
 * it.
 *
 * Phase A only reads this for the preview hub. Later phases hang subject
 * (B) and body (C/D, via lightncandy) overrides off the same keys, stored in
 * the email_templates table.
 */
class EmailRegistry
{
    /** Ordered category key => display label. */
    public static function categories(): array
    {
        return [
            'checkout' => 'Checkout',
            'checkin' => 'Checkin',
            'acceptance' => 'Acceptance & reminders',
            'requests' => 'Requests',
            'reports' => 'Reports & alerts',
            'agreements' => 'User Agreements',
            'store' => 'Store & procurement',
            'account' => 'Account & user',
        ];
    }

    /**
     * Every registered email, in display order.
     *
     * @return array<int, array{key:string, category:string, label:string, description:string, merge_vars:array<string,string>, factory?:callable, notification?:callable, configurable_recipients?:bool, configurable_cc?:bool}>
     */
    public static function all(): array
    {
        return [
            // ---- Checkout ----
            [
                'key' => 'checkout.asset',
                'category' => 'checkout',
                'label' => 'Asset checked out',
                'description' => 'Sent to the person an asset is checked out to.',
                'merge_vars' => ['target' => 'Recipient name', 'item' => 'The asset', 'admin' => 'Who checked it out', 'note' => 'Checkout note'],
                'factory' => fn (EmailSampleData $s) => new CheckoutAssetMail($s->asset(), $s->recipient(), $s->admin(), null, 'Welcome to your new device.'),
            ],
            [
                'key' => 'checkout.accessory',
                'category' => 'checkout',
                'label' => 'Accessory checked out',
                'description' => 'Sent when an accessory is checked out to a user.',
                'merge_vars' => ['target' => 'Recipient name', 'item' => 'The accessory', 'admin' => 'Who checked it out'],
                'factory' => fn (EmailSampleData $s) => new CheckoutAccessoryMail($s->accessory(), $s->recipient(), $s->admin(), null, 'Issued at the help desk.'),
            ],
            [
                'key' => 'checkout.component',
                'category' => 'checkout',
                'label' => 'Component checked out',
                'description' => 'Sent when a component (RAM, drive, etc.) is checked out.',
                'merge_vars' => ['target' => 'Recipient name', 'item' => 'The component'],
                'factory' => fn (EmailSampleData $s) => new CheckoutComponentMail($s->component(), $s->asset(), $s->admin(), null, 'Installed during upgrade.'),
            ],
            [
                'key' => 'checkout.consumable',
                'category' => 'checkout',
                'label' => 'Consumable checked out',
                'description' => 'Sent when a consumable is issued to a user.',
                'merge_vars' => ['target' => 'Recipient name', 'item' => 'The consumable'],
                'factory' => fn (EmailSampleData $s) => new CheckoutConsumableMail($s->consumable(), $s->recipient(), $s->admin(), null, 'Picked up from stores.'),
            ],
            [
                'key' => 'checkout.license',
                'category' => 'checkout',
                'label' => 'License checked out',
                'description' => 'Sent when a license seat is assigned to a user.',
                'merge_vars' => ['target' => 'Recipient name', 'license' => 'The license'],
                'factory' => fn (EmailSampleData $s) => new CheckoutLicenseMail($s->licenseSeat(), $s->recipient(), $s->admin(), null, 'Your software license is ready.'),
            ],
            [
                'key' => 'checkout.bulk_asset',
                'category' => 'checkout',
                'label' => 'Bulk asset checkout',
                'description' => 'Sent once when several assets are checked out to a user at the same time.',
                'merge_vars' => ['target' => 'Recipient name', 'assets' => 'The assets', 'admin' => 'Who checked them out'],
                'factory' => fn (EmailSampleData $s) => new BulkAssetCheckoutMail($s->assets(), $s->recipient(), $s->admin(), 'Jun 02, 2026', '', 'Onboarding kit.'),
            ],

            // ---- Checkin ----
            [
                'key' => 'checkin.asset',
                'category' => 'checkin',
                'label' => 'Asset checked in',
                'description' => 'Sent to the user an asset was returned from.',
                'merge_vars' => ['target' => 'Recipient name', 'item' => 'The asset'],
                'factory' => fn (EmailSampleData $s) => new CheckinAssetMail($s->asset(), $s->recipient(), $s->admin(), 'Returned in good condition.'),
            ],
            [
                'key' => 'checkin.accessory',
                'category' => 'checkin',
                'label' => 'Accessory checked in',
                'description' => 'Sent when an accessory is returned.',
                'merge_vars' => ['target' => 'Recipient name', 'item' => 'The accessory'],
                'factory' => fn (EmailSampleData $s) => new CheckinAccessoryMail($s->accessory(), $s->recipient(), $s->admin(), 'Returned to the help desk.'),
            ],
            [
                'key' => 'checkin.component',
                'category' => 'checkin',
                'label' => 'Component checked in',
                'description' => 'Sent when a component is checked back in.',
                'merge_vars' => ['target' => 'Recipient name', 'item' => 'The component'],
                'factory' => fn (EmailSampleData $s) => new CheckinComponentMail($s->component(), $s->asset(), $s->admin(), 'Removed during decommission.'),
            ],
            [
                'key' => 'checkin.license',
                'category' => 'checkin',
                'label' => 'License checked in',
                'description' => 'Sent when a license seat is released.',
                'merge_vars' => ['target' => 'Recipient name', 'license' => 'The license'],
                'factory' => fn (EmailSampleData $s) => new CheckinLicenseMail($s->licenseSeat(), $s->recipient(), $s->admin(), 'Seat freed up.'),
            ],

            // ---- Acceptance & reminders ----
            [
                'key' => 'acceptance.response',
                'category' => 'acceptance',
                'label' => 'Acceptance response (to initiator)',
                'description' => 'Sent to the admin who initiated a checkout when the user accepts or declines.',
                'merge_vars' => ['item' => 'The item', 'assignedTo' => 'Who responded'],
                'factory' => fn (EmailSampleData $s) => new CheckoutAcceptanceResponseMail($s->acceptance(), $s->admin(), true),
            ],
            [
                'key' => 'acceptance.unaccepted_reminder',
                'category' => 'acceptance',
                'label' => 'Unaccepted asset reminder',
                'description' => 'Reminds a user to accept items still awaiting their acceptance.',
                'merge_vars' => ['count' => 'How many items', 'assigned_to' => 'Recipient name'],
                'factory' => fn (EmailSampleData $s) => new UnacceptedAssetReminderMail($s->acceptance(), 2),
            ],

            // ---- Reports & alerts ----
            // These go to admins (the global Setting::alert_email list by default).
            // configurable_recipients exposes a per-email Recipients field so each
            // report can be routed to its own audience.
            [
                'key' => 'report.expiring_assets',
                'category' => 'reports',
                'label' => 'Expiring assets report',
                'description' => 'Scheduled digest of assets with warranties/leases expiring soon.',
                'merge_vars' => ['assets' => 'Expiring assets', 'threshold' => 'Day threshold'],
                'configurable_recipients' => true,
                'factory' => fn (EmailSampleData $s) => new ExpiringAssetsMail($s->assets(), 60),
            ],
            [
                'key' => 'report.expiring_licenses',
                'category' => 'reports',
                'label' => 'Expiring licenses report',
                'description' => 'Scheduled digest of licenses expiring soon.',
                'merge_vars' => ['licenses' => 'Expiring licenses', 'threshold' => 'Day threshold'],
                'configurable_recipients' => true,
                'factory' => fn (EmailSampleData $s) => new ExpiringLicenseMail($s->licenses(), 60),
            ],
            [
                'key' => 'report.upcoming_audits',
                'category' => 'reports',
                'label' => 'Upcoming audits report',
                'description' => 'Scheduled digest of assets due for audit.',
                'merge_vars' => ['assets' => 'Assets due', 'threshold' => 'Day threshold', 'total' => 'Total count'],
                'configurable_recipients' => true,
                'factory' => fn (EmailSampleData $s) => new SendUpcomingAuditMail($s->assets(), 30, 3),
            ],
            [
                'key' => 'report.contract_renewal',
                'category' => 'reports',
                'label' => 'Contract renewal alert',
                'description' => 'Alerts when contracts are 30/14 days out or expired. Per-contract admin_user wins; this list is the fallback.',
                'merge_vars' => ['contracts' => 'Contracts', 'window' => 'Alert window'],
                'configurable_recipients' => true,
                'factory' => fn (EmailSampleData $s) => new ContractRenewalAlertMail($s->contracts(), '30d'),
            ],
            // Notification-channel reports — recipient-configurable + previewable.
            // They render through the notification path (no subject/body editing).
            [
                'key' => 'report.expected_checkin',
                'category' => 'reports',
                'label' => 'Expected checkin report',
                'description' => 'Daily admin digest of assets due for check-in soon.',
                'merge_vars' => ['rows' => 'Rows (asset_tag, name, assigned_to, expected_checkin)', 'assets' => 'Asset objects'],
                'configurable_recipients' => true,
                'notification' => fn (EmailSampleData $s) => [new ExpectedCheckinAdminNotification($s->assets()), $s->notifiable()],
            ],
            [
                'key' => 'report.low_inventory',
                'category' => 'reports',
                'label' => 'Low inventory report',
                'description' => 'Alert when consumables/accessories fall below their minimum quantity. Toners are grouped by printer model.',
                'merge_vars' => ['groups' => 'Printer-model groups (model_name, manufacturer, printers_count, items)', 'items' => 'Flat low-stock rows (name, type, remaining, min_amt)', 'count' => 'Number of low items', 'threshold' => 'Alert threshold'],
                'configurable_recipients' => true,
                'notification' => fn (EmailSampleData $s) => [new InventoryAlert($s->lowInventoryItems(), 5), $s->notifiable()],
            ],

            // ---- User Agreements ----
            [
                'key' => 'agreement.signature_request',
                'category' => 'agreements',
                'label' => 'Agreement signature request',
                'description' => 'Sent to a user asking them to sign a pickup/upgrade/purchase agreement.',
                'merge_vars' => ['agreement' => 'The agreement', 'faculty_name' => 'Recipient name', 'asset_tag' => 'Asset tag'],
                'factory' => fn (EmailSampleData $s) => new UserAgreementSignatureRequestMail($s->userAgreement('pickup')),
            ],
            [
                'key' => 'agreement.signature_reminder',
                'category' => 'agreements',
                'label' => 'Agreement signature reminder',
                'description' => 'Follow-up reminder to sign an outstanding agreement.',
                'merge_vars' => ['agreement' => 'The agreement', 'faculty_name' => 'Recipient name'],
                'factory' => fn (EmailSampleData $s) => new UserAgreementSignatureReminderMail($s->userAgreement('pickup'), 1),
            ],

            // ---- Acceptance responses (notification-channel; preview-only) ----
            [
                'key' => 'acceptance.accepted_admin',
                'category' => 'acceptance',
                'label' => 'Item accepted (to admin)',
                'description' => 'Sent to the admin when a user accepts an item.',
                'merge_vars' => ['assigned_to' => 'User', 'item_name' => 'Item name', 'item_tag' => 'Asset tag', 'item_model' => 'Model', 'item_serial' => 'Serial', 'item_status' => 'Status', 'company_name' => 'Company', 'qty' => 'Quantity', 'note' => 'Note', 'accepted_date' => 'Accepted date', 'intro_text' => 'Intro line'],
                'notification' => fn (EmailSampleData $s) => [new AcceptanceItemAcceptedNotification($s->acceptanceParams()), $s->notifiable()],
            ],
            [
                'key' => 'acceptance.accepted_user',
                'category' => 'acceptance',
                'label' => 'Item accepted (to user)',
                'description' => 'Confirmation sent to the user who accepted an item.',
                'merge_vars' => ['assigned_to' => 'User', 'item_name' => 'Item name', 'item_tag' => 'Asset tag', 'item_model' => 'Model', 'item_serial' => 'Serial', 'item_status' => 'Status', 'company_name' => 'Company', 'qty' => 'Quantity', 'note' => 'Note', 'accepted_date' => 'Accepted date', 'intro_text' => 'Intro line'],
                'notification' => fn (EmailSampleData $s) => [new AcceptanceItemAcceptedToUserNotification($s->acceptanceParams()), $s->recipient()],
            ],
            [
                'key' => 'acceptance.declined',
                'category' => 'acceptance',
                'label' => 'Item declined',
                'description' => 'Sent to the admin when a user declines an item.',
                'merge_vars' => ['assigned_to' => 'User', 'item_name' => 'Item name', 'item_tag' => 'Asset tag', 'item_model' => 'Model', 'item_serial' => 'Serial', 'item_status' => 'Status', 'company_name' => 'Company', 'qty' => 'Quantity', 'note' => 'Note', 'declined_date' => 'Declined date', 'intro_text' => 'Intro line'],
                'notification' => fn (EmailSampleData $s) => [new AcceptanceItemDeclinedNotification($s->acceptanceParams()), $s->notifiable()],
            ],

            // ---- Requests (notification-channel; preview-only) ----
            [
                'key' => 'request.asset',
                'category' => 'requests',
                'label' => 'Asset requested',
                'description' => 'Sent when a user requests an asset.',
                'merge_vars' => ['item' => 'Item (item.display_name, item.asset_tag)', 'requested_by' => 'Requester (requested_by.display_name)', 'requested_date' => 'Requested date', 'qty' => 'Quantity', 'note' => 'Note', 'last_checkout' => 'Last checkout', 'expected_checkin' => 'Expected checkin'],
                'notification' => fn (EmailSampleData $s) => [new RequestAssetNotification($s->requestParams()), $s->notifiable()],
            ],
            [
                'key' => 'request.cancel',
                'category' => 'requests',
                'label' => 'Asset request canceled',
                'description' => 'Sent when a user cancels an asset request.',
                'merge_vars' => ['item' => 'Item (item.display_name, item.asset_tag)', 'requested_by' => 'Requester (requested_by.display_name)', 'requested_date' => 'Requested date', 'qty' => 'Quantity', 'note' => 'Note', 'last_checkout' => 'Last checkout', 'expected_checkin' => 'Expected checkin'],
                'notification' => fn (EmailSampleData $s) => [new RequestAssetCancelation($s->requestParams()), $s->notifiable()],
            ],
            [
                'key' => 'request.asset_buyout',
                'category' => 'requests',
                'label' => 'Lease buyout request',
                'description' => 'Sent to a leased asset\'s lessor when an admin clicks "Request Buyout" on the asset detail page. Recipients are not editable here — the email names one lessor\'s contract and device, so it goes to that lessor\'s contact email plus the extra addresses on that Supplier record ("Lease contact emails" on Suppliers → edit) and nowhere else. CC set here replaces the built-in team CC list (the assigned end user and the requesting admin are always CC\'d as well).',
                'merge_vars' => ['asset' => 'The leased asset (asset.asset_tag, asset.serial)', 'lease' => 'Lease facts (lease.contract_id, lease.end_date, lease.buyout_cost)', 'lessor' => 'The lessor (lessor.name)', 'requester' => 'Admin who requested it (requester.full_name)'],
                'configurable_cc' => true,
                'factory' => fn (EmailSampleData $s) => new AssetBuyoutRequestMail($s->asset(), $s->admin()),
            ],

            // ---- Account & user (notification-channel; preview-only) ----
            // ---- Store & procurement ----
            [
                'key' => 'store.requested',
                'category' => 'store',
                'label' => 'Store order received',
                'description' => 'Sent to the requester the moment they place a store order. CC defaults to the procurement admin lists.',
                'merge_vars' => ['target' => 'Requester', 'order' => 'The order (items, notes)'],
                'factory' => fn (EmailSampleData $s) => new StoreOrderStatusMail($s->storeOrder(), 'requested'),
                'configurable_cc' => true,
            ],
            [
                'key' => 'store.approved',
                'category' => 'store',
                'label' => 'Store order approved',
                'description' => 'Sent to the requester when procurement approves their order.',
                'merge_vars' => ['target' => 'Requester', 'order' => 'The order'],
                'factory' => fn (EmailSampleData $s) => new StoreOrderStatusMail($s->storeOrder('approved'), 'approved'),
            ],
            [
                'key' => 'store.declined',
                'category' => 'store',
                'label' => 'Store order declined',
                'description' => 'Sent to the requester when an order is declined, including the reviewer\'s note.',
                'merge_vars' => ['target' => 'Requester', 'order' => 'The order', 'note' => 'Reviewer note'],
                'factory' => fn (EmailSampleData $s) => new StoreOrderStatusMail($s->storeOrder('declined'), 'declined'),
            ],
            [
                'key' => 'store.ordered',
                'category' => 'store',
                'label' => 'Store order placed with vendor',
                'description' => 'Sent to the requester when their order request goes to the vendor.',
                'merge_vars' => ['target' => 'Requester', 'order' => 'The order'],
                'factory' => fn (EmailSampleData $s) => new StoreOrderStatusMail($s->storeOrder('ordered'), 'ordered'),
            ],
            [
                'key' => 'store.shipped',
                'category' => 'store',
                'label' => 'Store order shipped',
                'description' => 'Sent to the requester when the vendor\'s shipping webhook fires, with the tracking number.',
                'merge_vars' => ['target' => 'Requester', 'order' => 'The order', 'tracking' => 'Tracking number'],
                'factory' => fn (EmailSampleData $s) => new StoreOrderStatusMail($s->storeOrder('ordered'), 'shipped', ['tracking' => '1Z999AA10123456784']),
            ],
            [
                'key' => 'store.arrived',
                'category' => 'store',
                'label' => 'Store order arrived',
                'description' => 'Sent to the requester when their order arrives on campus.',
                'merge_vars' => ['target' => 'Requester', 'order' => 'The order', 'serials' => 'Serial numbers'],
                'factory' => fn (EmailSampleData $s) => new StoreOrderStatusMail($s->storeOrder('ordered'), 'arrived', ['serials' => ['C02ABC123DEF']]),
            ],
            [
                'key' => 'store.vendor_order',
                'category' => 'store',
                'label' => 'Vendor order request (to reps)',
                'description' => 'The order request emailed to the vendor\'s reps from the queue. Recipients default to the supplier\'s order email list; CC defaults to the procurement admin lists.',
                'merge_vars' => ['orders' => 'The orders (lines with CDW/MFR part numbers)', 'references' => 'ECU-STORE references', 'supplier' => 'The vendor'],
                'factory' => fn (EmailSampleData $s) => new StoreVendorOrderMail(collect([$s->storeOrder('approved'), $s->storeOrder('approved', 482)])),
                'configurable_recipients' => true,
                'configurable_cc' => true,
            ],
            [
                'key' => 'deployments.wave_announcement',
                'category' => 'store',
                'label' => 'Wave announcement (to the people in it)',
                'description' => 'The note sent to everyone in a deployment wave — the annual Faculty Laptop Program invitation, or advance warning of a swap. Subject and body are composed per send on the wave page and rendered against each person\'s own device; this entry is here so the layout can be previewed.',
                'merge_vars' => ['recipient' => 'The person', 'device' => 'Their device in the wave', 'lease_end' => 'When its lease ends', 'wave' => 'The wave', 'form_url' => 'The faculty program form'],
                'factory' => fn (EmailSampleData $s) => $s->waveAnnouncement(),
            ],
            [
                'key' => 'procurement.vendor_order',
                'category' => 'store',
                'label' => 'Vendor order (to reps)',
                'description' => 'The order emailed to the vendor\'s reps once finance has issued the purchase order — agreed prices, not an estimate. Recipients default to the supplier\'s order email list; CC defaults to the procurement admin lists.',
                'merge_vars' => ['order' => 'The purchase order (account, quote, totals)', 'lines' => 'The lines being ordered', 'reference' => 'The purchase order number', 'supplier' => 'The vendor'],
                'factory' => fn (EmailSampleData $s) => new RequisitionVendorOrderMail($s->purchaseOrder()),
                'configurable_recipients' => true,
                'configurable_cc' => true,
            ],

            [
                'key' => 'account.welcome',
                'category' => 'account',
                'label' => 'Welcome (new user)',
                'description' => 'Sent to a new user when their account is created.',
                'merge_vars' => ['first_name' => 'First name', 'last_name' => 'Last name', 'username' => 'Username', 'email' => 'Email', 'token' => 'Invite token', 'expire_date' => 'Invite expiry'],
                'notification' => fn (EmailSampleData $s) => [new WelcomeNotification($s->recipient()), $s->recipient()],
            ],
            [
                'key' => 'account.first_admin',
                'category' => 'account',
                'label' => 'First admin setup',
                'description' => 'Sent to the first administrator during initial setup.',
                'merge_vars' => ['first_name' => 'First name', 'last_name' => 'Last name', 'username' => 'Username', 'email' => 'Email', 'password' => 'Password', 'url' => 'Site URL'],
                'notification' => fn (EmailSampleData $s) => [new FirstAdminNotification($s->firstAdminData()), $s->recipient()],
            ],
            [
                'key' => 'account.expected_checkin_user',
                'category' => 'account',
                'label' => 'Expected checkin reminder (user)',
                'description' => 'Reminds a user that an item assigned to them is due for check-in.',
                'merge_vars' => ['asset' => 'Asset name', 'asset_tag' => 'Asset tag', 'serial' => 'Serial', 'date' => 'Expected checkin (formatted)', 'expected_checkin_date' => 'Expected checkin (raw)'],
                'notification' => fn (EmailSampleData $s) => [new ExpectedCheckinNotification($s->asset()), $s->recipient()],
            ],
            [
                'key' => 'account.inventory',
                'category' => 'account',
                'label' => 'Inventory report (to user)',
                'description' => 'A user’s personal inventory summary, sent on request.',
                'merge_vars' => ['assets' => 'Assigned assets', 'accessories' => 'Assigned accessories', 'licenses' => 'Assigned licenses', 'consumables' => 'Assigned consumables'],
                'notification' => fn (EmailSampleData $s) => [new CurrentInventory($s->userWithInventory()), $s->userWithInventory()],
            ],
        ];
    }

    /** @return array<string, array> key => definition */
    public static function flat(): array
    {
        $out = [];
        foreach (self::all() as $entry) {
            $out[$entry['key']] = $entry;
        }

        return $out;
    }

    public static function find(string $key): ?array
    {
        return self::flat()[$key] ?? null;
    }

    /** Build the Mailable for a key from sample data, ready to ->render(). */
    public static function makeMailable(string $key): ?Mailable
    {
        $entry = self::find($key);
        if (! $entry || ! isset($entry['factory'])) {
            return null;
        }

        return ($entry['factory'])(new EmailSampleData);
    }

    /**
     * Build the notification + notifiable pair for a key from sample data.
     * Returns [Notification, Notifiable] or null when the key isn't a
     * notification-channel email.
     *
     * @return array{0: Notification, 1: mixed}|null
     */
    public static function makeNotification(string $key): ?array
    {
        $entry = self::find($key);
        if (! $entry || ! isset($entry['notification'])) {
            return null;
        }

        return ($entry['notification'])(new EmailSampleData);
    }

    /**
     * The pristine built-in subject for an email — mailable or notification —
     * used by the hub to show a placeholder. Caller is responsible for setting
     * BaseMailable::$ignoreOverrides so this reads the default, not an override.
     */
    public static function defaultSubject(string $key): string
    {
        $entry = self::find($key);
        if (! $entry) {
            return '';
        }

        try {
            if (isset($entry['factory'])) {
                return (string) ($entry['factory'])(new EmailSampleData)->envelope()->subject;
            }
            if (isset($entry['notification'])) {
                [$notification, $notifiable] = ($entry['notification'])(new EmailSampleData);

                return (string) $notification->toMail($notifiable)->subject;
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    /**
     * Render an email to preview HTML from sample data. Mailables render via
     * ->render(); notification-channel emails render their toMail() MailMessage
     * markdown through the same mail pipeline. Returns null if the key can't be
     * previewed.
     */
    public static function renderPreview(string $key): ?string
    {
        $entry = self::find($key);
        if (! $entry) {
            return null;
        }

        if (isset($entry['factory'])) {
            return ($entry['factory'])(new EmailSampleData)->render();
        }

        if (isset($entry['notification'])) {
            [$notification, $notifiable] = ($entry['notification'])(new EmailSampleData);
            $message = $notification->toMail($notifiable);

            return app(Markdown::class)->render($message->markdown, $message->data());
        }

        return null;
    }

    /** Can this email be previewed (mailable or notification)? */
    public static function isPreviewable(array $entry): bool
    {
        return isset($entry['factory']) || isset($entry['notification']);
    }

    /**
     * Subject/body overrides apply to mailables (via BaseMailable) and to
     * notification-channel emails (via the OverridableMailNotification trait).
     */
    public static function isEditable(array $entry): bool
    {
        return isset($entry['factory']) || isset($entry['notification']);
    }
}
