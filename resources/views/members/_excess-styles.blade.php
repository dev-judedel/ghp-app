{{--
    Red marking for excess deductions, shared by the member page and the
    reimbursement records page so the indicator is identical everywhere.
    print-color-adjust keeps the red when a page is printed straight from the
    browser (the Print button on the records page opens the PDF receipt, which
    carries its own red styling).
--}}
<style>
    .excess-row td { color: #C62828; }
    .excess-row td:first-child { box-shadow: inset 3px 0 0 #C62828; }
    .excess-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #FDECEA; color: #C62828; font-weight: 600; font-size: 12px; }
    .excess-note { color: #C62828; font-size: 12px; }
    .excess-card { border-left: 3px solid #C62828; }
    .excess-card h2, .excess-line .label, .excess-line .amount { color: #C62828; }
    @media print {
        .excess-row td, .excess-badge, .excess-note, .excess-card h2, .excess-line .label, .excess-line .amount {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
</style>
