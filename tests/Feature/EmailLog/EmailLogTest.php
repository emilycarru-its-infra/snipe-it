<?php

namespace Tests\Feature\EmailLog;

use App\Mail\StoreOrderStatusMail;
use App\Models\Asset;
use App\Models\EmailLog;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class EmailLogTest extends TestCase
{
    private function storeOrder(User $user, array $attributes = []): StoreOrder
    {
        return StoreOrder::create(array_merge([
            'user_id' => $user->id,
            'status' => 'ordered',
            'program' => 'faculty',
            'funding_account' => 'lease_admin',
        ], $attributes));
    }

    public function test_a_sent_email_is_logged_against_its_order_and_recipient()
    {
        $user = User::factory()->create(['email' => 'Requester@Example.org']);
        $order = $this->storeOrder($user);

        Mail::to($user->email)->cc('team@example.org')->send(new StoreOrderStatusMail($order, 'shipped'));

        $log = EmailLog::sole();
        $this->assertSame(['requester@example.org'], $log->to_addresses);
        $this->assertSame(['team@example.org'], $log->cc_addresses);
        $this->assertSame(StoreOrderStatusMail::class, $log->mailable);
        $this->assertSame(trans('mail.store_order_shipped_subject'), $log->subject);
        $this->assertNotNull($log->message_id);
        $this->assertTrue(EmailLog::about($order)->exists());
        $this->assertTrue(EmailLog::about($user)->exists());
    }

    public function test_the_api_lists_the_mail_for_one_record()
    {
        $user = User::factory()->create();
        $order = $this->storeOrder($user);
        $other = $this->storeOrder($user);

        Mail::to($user->email)->send(new StoreOrderStatusMail($order, 'shipped'));
        Mail::to($user->email)->send(new StoreOrderStatusMail($other, 'arrived'));

        Passport::actingAs(User::factory()->superuser()->create());

        $this->getJson(route('api.email-logs.index', ['type' => 'store_order', 'id' => $order->id]))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.subject', trans('mail.store_order_shipped_subject'))
            ->assertJsonFragment(['type' => 'store_order', 'id' => $order->id]);
    }

    public function test_someone_without_reports_access_cannot_read_the_log()
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('api.email-logs.index'))->assertForbidden();
    }

    public function test_an_imported_trace_links_by_recipient_reference_and_store_event()
    {
        $user = User::factory()->create(['email' => 'requester@example.org']);
        $shipped = $this->storeOrder($user, ['shipped_at' => '2026-11-10 18:00:00']);
        $this->storeOrder($user, ['shipped_at' => '2026-10-01 18:00:00']);
        $quoted = $this->storeOrder($user);

        Passport::actingAs(User::factory()->superuser()->create());

        $this->postJson(route('api.email-logs.import'), [
            'entries' => [
                [
                    'message_id' => '<abc@example.org>',
                    'sent_at' => '2026-11-10T19:30:00Z',
                    'from' => 'assets@example.org',
                    'to' => ['Requester@example.org'],
                    'subject' => trans('mail.store_order_shipped_subject'),
                    'status' => 'Delivered',
                ],
                [
                    'message_id' => 'def@example.org',
                    'sent_at' => '2026-11-11T10:00:00Z',
                    'to' => ['rep@example.com'],
                    'subject' => 'Quote request '.$quoted->reference(),
                ],
            ],
        ])->assertOk()->assertJsonPath('payload.created', 2);

        $this->assertTrue(EmailLog::about($shipped)->where('message_id', 'abc@example.org')->exists());
        $this->assertTrue(EmailLog::about($user)->exists());
        $this->assertTrue(EmailLog::about($quoted)->where('message_id', 'def@example.org')->exists());
        $this->assertSame(1, EmailLog::about($shipped)->count());
        $this->assertSame(EmailLog::SOURCE_EXCHANGE, EmailLog::where('message_id', 'abc@example.org')->value('source'));
    }

    public function test_importing_a_message_already_logged_adds_nothing_twice()
    {
        $user = User::factory()->create();
        Mail::to($user->email)->send(new StoreOrderStatusMail($this->storeOrder($user), 'shipped'));
        $log = EmailLog::sole();

        Passport::actingAs(User::factory()->superuser()->create());

        $this->postJson(route('api.email-logs.import'), ['entries' => [[
            'message_id' => '<'.$log->message_id.'>',
            'sent_at' => now()->toIso8601String(),
            'to' => [$user->email],
            'subject' => $log->subject,
            'status' => 'Delivered',
        ]]])->assertOk()->assertJsonPath('payload.updated', 1);

        $this->assertSame(1, EmailLog::count());
        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_only_a_superuser_can_import()
    {
        Passport::actingAs(User::factory()->canViewReports()->create());

        $this->postJson(route('api.email-logs.import'), ['entries' => [[
            'sent_at' => now()->toIso8601String(),
            'to' => ['someone@example.org'],
        ]]])->assertForbidden();
    }

    public function test_the_asset_page_shows_its_emails()
    {
        $asset = Asset::factory()->create();
        $log = EmailLog::create(['subject' => 'About this laptop', 'to_addresses' => ['a@example.org'], 'sent_at' => now()]);
        $log->linkTo($asset);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.show', $asset))
            ->assertOk()
            ->assertSee('About this laptop');

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.emails', ['type' => 'asset', 'id' => $asset->id]))
            ->assertOk()
            ->assertSee('About this laptop');
    }
}
