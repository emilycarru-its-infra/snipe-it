<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

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
            'reports' => 'Reports & alerts',
            'agreements' => 'User Agreements',
        ];
    }

    /**
     * Every registered email, in display order.
     *
     * @return array<int, array{key:string, category:string, label:string, description:string, merge_vars:array<string,string>, factory:callable}>
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
            // Notification-channel reports — recipient-configurable now; subject/body
            // editing + preview fold in with the other notifications in Phase E
            // (no factory yet, so they're listed for recipients only).
            [
                'key' => 'report.expected_checkin',
                'category' => 'reports',
                'label' => 'Expected checkin report',
                'description' => 'Daily admin digest of assets due for check-in soon.',
                'merge_vars' => [],
                'configurable_recipients' => true,
            ],
            [
                'key' => 'report.low_inventory',
                'category' => 'reports',
                'label' => 'Low inventory report',
                'description' => 'Alert when consumables/accessories fall below their minimum quantity.',
                'merge_vars' => [],
                'configurable_recipients' => true,
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
}
