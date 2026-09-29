<?php

namespace Tests\Feature;

use App\Mail\ReimbursementReceiptMail;
use App\Models\BenefitPeriod;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * "Send" button on the Coverage Year -> Reimbursement records page: emails
 * the same PDF receipt to the member's saved email, and reports back if
 * the address is missing/invalid/rejected by the mail server.
 *
 * NOT YET RUN — written from reading the controller/views.
 * Run: php artisan test --filter=SendReimbursementReceiptTest
 */
class SendReimbursementReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    /** @return array{0: Member, 1: BenefitPeriod} */
    private function memberWithReimbursement(array $memberOverrides = [], bool $withReimbursement = true): array
    {
        $member = Member::factory()->create($memberOverrides + ['email' => 'juan@example.com']);

        $period = BenefitPeriod::create([
            'member_id' => $member->id,
            'from_date' => '2026-04-01',
            'to_date' => '2027-03-31',
            'ghp_amount' => 3600,
            'ghp_available' => 3100,
            'ghp_used' => 500,
            'member_type' => $member->member_type,
        ]);

        if ($withReimbursement) {
            $member->reimbursements()->create([
                'benefit_period_id' => $period->id,
                'or_no' => 'OR-1',
                'or_date' => '2026-08-10',
                'or_amount' => 500,
                'hospital_name' => 'City Hospital',
            ]);
        }

        return [$member, $period];
    }

    private function sendUrl(Member $member, BenefitPeriod $period): string
    {
        return route('members.benefit-periods.reimbursements.send', [$member, $period]);
    }

    // ---- Button / modal ----

    public function test_an_admin_sees_the_send_button_and_the_members_email_in_the_modal(): void
    {
        [$member, $period] = $this->memberWithReimbursement();

        $this->actingAs($this->admin())
            ->get(route('members.benefit-periods.reimbursements', [$member, $period]))
            ->assertOk()
            ->assertSee('sendReceiptModal.showModal()', false)
            ->assertSee('juan@example.com');
    }

    public function test_a_non_admin_does_not_see_the_send_button(): void
    {
        [$member, $period] = $this->memberWithReimbursement();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staff)
            ->get(route('members.benefit-periods.reimbursements', [$member, $period]))
            ->assertOk()
            ->assertDontSee('sendReceiptModal.showModal()', false);
    }

    public function test_the_modal_says_so_when_the_member_has_no_email(): void
    {
        [$member, $period] = $this->memberWithReimbursement(['email' => null]);

        $this->actingAs($this->admin())
            ->get(route('members.benefit-periods.reimbursements', [$member, $period]))
            ->assertOk()
            ->assertSee('No email on file');
    }

    // ---- Sending ----

    public function test_the_receipt_is_sent_to_the_members_saved_email_with_the_pdf_attached(): void
    {
        Mail::fake();
        [$member, $period] = $this->memberWithReimbursement();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post($this->sendUrl($member, $period), ['note' => 'Please keep this for your records.'])
            ->assertRedirect(route('members.benefit-periods.reimbursements', [$member, $period]));

        Mail::assertSent(ReimbursementReceiptMail::class, function (ReimbursementReceiptMail $mail) use ($member) {
            return $mail->hasTo('juan@example.com')
                && $mail->note === 'Please keep this for your records.'
                && abs($mail->total - 500.0) < 0.01
                && count($mail->attachments()) === 1;
        });

        $this->assertStringContainsString('sent to juan@example.com', session('status'));

        $entry = Activity::where('log_name', 'reimbursement-mail')->firstOrFail();
        $this->assertSame('sent', $entry->getExtraProperty('result'));
        $this->assertSame($admin->id, $entry->causer_id);
    }

    public function test_a_recipient_submitted_by_the_browser_is_ignored(): void
    {
        Mail::fake();
        [$member, $period] = $this->memberWithReimbursement();

        $this->actingAs($this->admin())
            ->post($this->sendUrl($member, $period), ['email' => 'attacker@evil.test', 'to' => 'attacker@evil.test']);

        Mail::assertSent(ReimbursementReceiptMail::class, fn ($mail) => $mail->hasTo('juan@example.com') && ! $mail->hasTo('attacker@evil.test'));
        Mail::assertSent(ReimbursementReceiptMail::class, 1);
    }

    // ---- The email does not exist / cannot be used ----

    public function test_nothing_is_sent_when_the_member_has_no_email(): void
    {
        Mail::fake();
        [$member, $period] = $this->memberWithReimbursement(['email' => null]);

        $this->actingAs($this->admin())->post($this->sendUrl($member, $period));

        Mail::assertNothingSent();
        $this->assertStringContainsString('no email address on file', session('status'));
    }

    public function test_nothing_is_sent_when_the_saved_email_is_not_a_valid_address(): void
    {
        Mail::fake();
        [$member, $period] = $this->memberWithReimbursement();
        $member->forceFill(['email' => 'not-an-email'])->save();

        $this->actingAs($this->admin())->post($this->sendUrl($member, $period));

        Mail::assertNothingSent();
        $this->assertStringContainsString("isn't a valid, reachable address", session('status'));
        $this->assertSame('failed', Activity::where('log_name', 'reimbursement-mail')->firstOrFail()->getExtraProperty('result'));
    }

    public function test_a_mail_server_rejection_is_reported_as_the_mailbox_not_existing(): void
    {
        [$member, $period] = $this->memberWithReimbursement();

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new TransportException(
            'Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown".'
        ));

        $this->actingAs($this->admin())->post($this->sendUrl($member, $period));

        $this->assertStringContainsString('was NOT sent to juan@example.com', session('status'));
        $this->assertStringContainsString('does not exist', session('status'));

        $entry = Activity::where('log_name', 'reimbursement-mail')->firstOrFail();
        $this->assertSame('failed', $entry->getExtraProperty('result'));
    }

    public function test_any_other_delivery_failure_is_reported_without_claiming_it_was_sent(): void
    {
        [$member, $period] = $this->memberWithReimbursement();

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new TransportException('Connection could not be established with host "smtp.example.com".'));

        $this->actingAs($this->admin())->post($this->sendUrl($member, $period));

        $this->assertStringContainsString('was NOT sent', session('status'));
        $this->assertStringNotContainsString('does not exist', session('status'));
        $this->assertStringNotContainsString('smtp.example.com', session('status'));   // raw server error is logged, not shown
    }

    // ---- Guards ----

    public function test_a_period_with_no_reimbursements_is_not_sent(): void
    {
        Mail::fake();
        [$member, $period] = $this->memberWithReimbursement([], withReimbursement: false);

        $this->actingAs($this->admin())->post($this->sendUrl($member, $period));

        Mail::assertNothingSent();
        $this->assertStringContainsString('Nothing to send', session('status'));
    }

    public function test_a_non_admin_cannot_send(): void
    {
        Mail::fake();
        [$member, $period] = $this->memberWithReimbursement();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staff)->post($this->sendUrl($member, $period))->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_a_period_belonging_to_another_member_returns_404(): void
    {
        Mail::fake();
        [$member] = $this->memberWithReimbursement();
        [, $otherPeriod] = $this->memberWithReimbursement(['email' => 'other@example.com']);

        $this->actingAs($this->admin())->post($this->sendUrl($member, $otherPeriod))->assertNotFound();

        Mail::assertNothingSent();
    }
}
