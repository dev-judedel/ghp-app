<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendReimbursementReceiptRequest;
use App\Http\Requests\UpdateBenefitPeriodRequest;
use App\Mail\ReimbursementReceiptMail;
use App\Models\BenefitPeriod;
use App\Models\Member;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class BenefitPeriodController extends Controller
{
    /**
     * Direct manual correction — used from the Data Quality Report to fix
     * corrupted historical rows (e.g. a period dated 1943-09-10). This does
     * NOT go through BenefitAccrualService, deliberately: it's fixing
     * broken data, not recalculating a live balance.
     */
    public function update(UpdateBenefitPeriodRequest $request, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        $benefitPeriod->update($request->validated());

        return redirect()->route('data-quality.index')
            ->with('status', "Benefit period corrected for {$benefitPeriod->member->code}.");
    }

    /**
     * Coverage Year History drill-down: shows only the reimbursement
     * records belonging to this one Benefit Period, via the
     * reimbursements.benefit_period_id link (see
     * ReimbursementController::linkToBenefitPeriod()) — filtered at the
     * database level, not in JavaScript. Reached by clicking a period row
     * on the member page's "Benefit periods" table.
     */
    public function reimbursements(Member $member, BenefitPeriod $benefitPeriod): View
    {
        abort_unless($benefitPeriod->member_id === $member->id, 404);

        $reimbursements = $benefitPeriod->reimbursements()
            ->orderBy('or_date')
            ->get();

        return view('benefit-periods.reimbursements', [
            'member' => $member,
            'benefitPeriod' => $benefitPeriod,
            'reimbursements' => $reimbursements,
            'total' => $reimbursements->where('is_voided', false)->sum('or_amount'),
            'voidedCount' => $reimbursements->where('is_voided', true)->count(),
        ]);
    }

    /**
     * The printable / downloadable company-letterhead version of the same
     * drill-down. Streams inline by default so the browser's own PDF
     * viewer (and its print button) opens straight away for "Print";
     * ?download=1 forces a "Save As" download instead.
     */
    public function reimbursementsPdf(Request $request, Member $member, BenefitPeriod $benefitPeriod): Response
    {
        abort_unless($benefitPeriod->member_id === $member->id, 404);

        [$pdf, $filename] = $this->buildReceiptPdf($request, $member, $benefitPeriod);

        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }

    /**
     * Emails the same PDF receipt (Print / Download PDF) to the member.
     *
     * The recipient is ALWAYS the email saved on the member's record,
     * read here on the server — the modal only displays it, and nothing
     * the browser submits can change where the receipt goes.
     *
     * "Does this email exist?" is checked in layers, because a mailbox
     * can't be proven to exist without actually delivering to it:
     *   1. an email must be on file;
     *   2. it must be a valid address whose domain can receive mail
     *      (RFC format + MX/DNS — the same rule the member forms use;
     *      the DNS lookup is skipped in the testing environment);
     *   3. at send time, the mail server's own rejection (unknown
     *      mailbox, refused domain, ...) is caught and reported.
     * Some servers accept mail and only bounce it later; that can't be
     * detected from here. Every attempt is written to the activity log.
     */
    public function sendReceipt(SendReimbursementReceiptRequest $request, Member $member, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        abort_unless($benefitPeriod->member_id === $member->id, 404);

        $back = redirect()->route('members.benefit-periods.reimbursements', [$member, $benefitPeriod]);

        $email = trim((string) $member->email);

        if ($email === '') {
            return $back->with('status', "Can't send — {$member->code} has no email address on file. Add one under Edit member first.");
        }

        $validator = Validator::make(['email' => $email], ['email' => ['required', $this->emailRule()]]);

        if ($validator->fails()) {
            $this->logSend($request, $member, $benefitPeriod, $email, false, 'Email address failed validation (invalid format or the domain cannot receive mail).');

            return $back->with('status', "Can't send — the email on file for {$member->code} ({$email}) isn't a valid, reachable address. Please correct it under Edit member.");
        }

        [$pdf, $filename, $reimbursements, $total] = $this->buildReceiptPdf($request, $member, $benefitPeriod);

        if ($reimbursements->isEmpty()) {
            return $back->with('status', 'Nothing to send — there are no reimbursement records in this coverage period.');
        }

        try {
            // Sent synchronously (not queued) on purpose: the admin needs to
            // know right now whether the mail server accepted it.
            Mail::to($email)->send(new ReimbursementReceiptMail(
                member: $member,
                benefitPeriod: $benefitPeriod,
                pdfBinary: $pdf->output(),
                filename: $filename,
                total: (float) $total,
                note: $request->validated('note'),
                sentBy: $request->user()->name,
            ));
        } catch (TransportExceptionInterface $e) {
            Log::warning('Reimbursement receipt email failed', ['member_id' => $member->id, 'to' => $email, 'error' => $e->getMessage()]);

            $mailboxMissing = (bool) preg_match(
                '/\b55[0134]\b|5\.1\.[1-3]|user unknown|unknown user|does not exist|no such user|mailbox (unavailable|not found)|recipient (address )?rejected/i',
                $e->getMessage()
            );

            $reason = $mailboxMissing
                ? "The mail server reports that {$email} does not exist or can't accept mail."
                : 'The mail server could not deliver the message. Check the mail settings and try again.';

            $this->logSend($request, $member, $benefitPeriod, $email, false, $reason);

            return $back->with('status', "Receipt was NOT sent to {$email}. {$reason}");
        } catch (Throwable $e) {
            report($e);

            $this->logSend($request, $member, $benefitPeriod, $email, false, 'Unexpected error while sending.');

            return $back->with('status', "Receipt was NOT sent to {$email} because of an unexpected error. Please try again or contact the administrator.");
        }

        // MAIL_MAILER=log accepts the message but only writes it to
        // storage/logs/laravel.log — nothing reaches an inbox. Say so instead
        // of claiming it was sent.
        if (config('mail.default') === 'log') {
            $this->logSend($request, $member, $benefitPeriod, $email, false, "Mail driver is 'log' — written to the log only, not delivered.");

            return $back->with('status', "Receipt for {$email} was NOT delivered: the system's mail driver is set to 'log', so the email was only written to storage/logs/laravel.log. Set up SMTP in .env (MAIL_MAILER=smtp and your mail provider's host, port, username and password), then run php artisan config:clear.");
        }

        $this->logSend($request, $member, $benefitPeriod, $email, true);

        return $back->with('status', "Reimbursement receipt sent to {$email}.");
    }

    /**
     * Shared by Print, Download and Send so all three are the exact same
     * document.
     *
     * @return array{0: \Barryvdh\DomPDF\PDF, 1: string, 2: \Illuminate\Support\Collection, 3: float}
     */
    private function buildReceiptPdf(Request $request, Member $member, BenefitPeriod $benefitPeriod): array
    {
        $reimbursements = $benefitPeriod->reimbursements()
            ->orderBy('or_date')
            ->get();

        $total = $reimbursements->where('is_voided', false)->sum('or_amount');

        $pdf = Pdf::loadView('reports.pdf.reimbursement-receipt', [
            'member' => $member,
            'benefitPeriod' => $benefitPeriod,
            'reimbursements' => $reimbursements,
            'total' => $total,
            'voidedCount' => $reimbursements->where('is_voided', true)->count(),
            'generatedAt' => now(),
            'generatedBy' => $request->user(),
        ])->setPaper('a4', 'portrait');

        $filename = sprintf(
            'reimbursement-receipt-%s-%s.pdf',
            $member->code,
            $benefitPeriod->from_date->format('Y-m')
        );

        return [$pdf, $filename, $reimbursements, (float) $total];
    }

    /**
     * RFC format always; the MX/DNS lookup is real network I/O, so it is
     * skipped in the testing environment (same approach as HasEmailRule).
     */
    private function emailRule(): \Illuminate\Validation\Rules\Email
    {
        $rule = Rule::email()->rfcCompliant();

        if (! app()->environment('testing')) {
            $rule->validateMxRecord();
        }

        return $rule;
    }

    private function logSend(Request $request, Member $member, BenefitPeriod $benefitPeriod, string $email, bool $sent, ?string $reason = null): void
    {
        activity('reimbursement-mail')
            ->performedOn($member)
            ->causedBy($request->user())
            ->withProperties(array_filter([
                'to' => $email,
                'benefit_period' => $benefitPeriod->from_date->toDateString().' to '.$benefitPeriod->to_date->toDateString(),
                'result' => $sent ? 'sent' : 'failed',
                'reason' => $reason,
            ]))
            ->log($sent
                ? "Reimbursement receipt emailed to {$email}"
                : "Reimbursement receipt email to {$email} FAILED".($reason ? " — {$reason}" : ''));
    }
}
