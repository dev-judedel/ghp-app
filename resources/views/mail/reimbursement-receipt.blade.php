<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; color: #16211D; line-height: 1.5;">
    <p>Hello {{ $member->first_name }},</p>

    <p>
        Attached is your GHP reimbursement receipt for the coverage period
        <strong>{{ $benefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $benefitPeriod->to_date->format('M d, Y') }}</strong>.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; margin: 12px 0;">
        <tr>
            <td style="color: #5B6B65;">Member code</td>
            <td><strong>{{ $member->code }}</strong></td>
        </tr>
        <tr>
            <td style="color: #5B6B65;">Total reimbursed</td>
            <td><strong>&#8369;{{ number_format($total, 2) }}</strong></td>
        </tr>
    </table>

    @if (filled($note))
        <p style="padding: 10px 14px; background: #F2F4F2; border-left: 3px solid #0F5C50; white-space: pre-line;">{{ $note }}</p>
    @endif

    <p style="color: #5B6B65; font-size: 12px;">
        Sent by {{ $sentBy }} through the Group Hospitalization Plan system. The full receipt is in the attached PDF.
    </p>
</body>
</html>
