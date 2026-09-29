<?php

namespace App\Mail;

use App\Models\BenefitPeriod;
use App\Models\Member;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The reimbursement receipt PDF (same document as Print / Download PDF),
 * emailed to the member. Not queued: the sender needs the delivery result
 * immediately (see BenefitPeriodController::sendReceipt()).
 */
class ReimbursementReceiptMail extends Mailable
{
    public function __construct(
        public readonly Member $member,
        public readonly BenefitPeriod $benefitPeriod,
        private readonly string $pdfBinary,
        private readonly string $filename,
        public readonly float $total,
        public readonly ?string $note,
        public readonly string $sentBy,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'GHP Reimbursement Receipt — %s to %s',
                $this->benefitPeriod->from_date->format('M Y'),
                $this->benefitPeriod->to_date->format('M Y'),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reimbursement-receipt');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBinary, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
