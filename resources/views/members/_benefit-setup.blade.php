{{--
    GHP Benefit setup fields shared by the Add member modal (members/index) and
    the Edit member modal (members/show), so the two can never drift apart.

    Variables:
      $p               id prefix ('' for Add, 'edit_' for Edit)
      $member          Member being edited, or null when adding
      $cycleDefaults   [memberType => ['year','from','to']] suggested standard cycles
      $defaultGhpAmount  standard annual amount (Add only)
      $fallbackCycle   ['from','to'] effective cycle, used for the preview of a member
                       with no configured cycle yet (Edit only), else null

    Everything shown here is a SUGGESTION the admin can change. The server
    (StoreMemberRequest / UpdateMemberRequest via ValidatesCoverageSetup) is the
    authority: the live preview below is display-only and never trusted.
--}}
@php
    $isEdit = $member !== null;
    $legacyCycle = $isEdit && $member->coverage_end_date === null;
    $typeNow = (int) old('member_type', $isEdit ? $member->member_type : 0);
    $def = $cycleDefaults[$typeNow] ?? $cycleDefaults[0];

    $vYear = old('coverage_year', $isEdit ? $member->coverage_year : $def['year']);
    $vApply = old('apply_date', $isEdit ? optional($member->apply_date)->toDateString() : $def['from']);
    $vEnd = old('coverage_end_date', $isEdit ? optional($member->coverage_end_date)->toDateString() : $def['to']);
    $vStart = old('start_date', $isEdit ? optional($member->start_date)->toDateString() : now()->toDateString());
    $vDed = old('deduction_start_date', $isEdit ? optional($member->deduction_start_date)->toDateString() : null);
    $vAmount = old('ghp_amount', $defaultGhpAmount ?? 3600);

    // First paint of the Add form: the existing default rule (1st of the month
    // after the member start date, never before the Apply Date).
    if (! $isEdit && $vDed === null && $vStart && $vApply) {
        $derivedDed = \Carbon\Carbon::parse($vStart)->startOfMonth()->addMonthNoOverflow();
        $applyC = \Carbon\Carbon::parse($vApply);
        $vDed = ($derivedDed->lessThan($applyC) ? $applyC : $derivedDed)->toDateString();
    }
@endphp

<div style="display: flex; gap: 12px; flex-wrap: wrap;">
    <div class="field" style="flex: 1 1 150px;">
        <label for="{{ $p }}coverage_year">Coverage year</label>
        <input type="number" id="{{ $p }}coverage_year" name="coverage_year" value="{{ $vYear }}" min="2000" max="2100" step="1" placeholder="{{ $legacyCycle ? 'Not set' : 'e.g. 2026' }}">
        @error('coverage_year') <p class="error">{{ $message }}</p> @enderror
        <p class="hint" style="margin-top: 4px;">The year the GHP cycle begins.</p>
    </div>
    <div class="field" style="flex: 1 1 150px;">
        <label for="{{ $p }}apply_date">GHP apply date <span class="error">*</span></label>
        <input type="date" id="{{ $p }}apply_date" name="apply_date" value="{{ $vApply }}" required>
        @error('apply_date') <p class="error">{{ $message }}</p> @enderror
        <p class="hint" style="margin-top: 4px;">When the member's coverage begins.</p>
    </div>
    <div class="field" style="flex: 1 1 150px;">
        <label for="{{ $p }}coverage_end_date">Coverage end date</label>
        <input type="date" id="{{ $p }}coverage_end_date" name="coverage_end_date" value="{{ $vEnd }}">
        @error('coverage_end_date') <p class="error">{{ $message }}</p> @enderror
        <p class="hint" style="margin-top: 4px;">When the cycle ends (must be later than the apply date).</p>
    </div>
</div>

<div style="display: flex; gap: 12px; flex-wrap: wrap;">
    <div class="field" style="flex: 1 1 150px;">
        <label for="{{ $p }}start_date">Member start date <span class="error">*</span></label>
        <input type="date" id="{{ $p }}start_date" name="start_date" value="{{ $vStart }}" required>
        @error('start_date') <p class="error">{{ $message }}</p> @enderror
        <p class="hint" style="margin-top: 4px;">When the member started / was added.</p>
    </div>
    <div class="field" style="flex: 1 1 150px;">
        <label for="{{ $p }}deduction_start_date">Start date (first deduction)</label>
        <input type="date" id="{{ $p }}deduction_start_date" name="deduction_start_date" value="{{ $vDed }}">
        @error('deduction_start_date') <p class="error">{{ $message }}</p> @enderror
        <p class="hint" style="margin-top: 4px;">When deductions begin. Follows the member start date (1st of the next month) until you change it; changing it never moves the apply or end date.</p>
    </div>
    <div class="field" style="flex: 1 1 150px;">
        @if ($isEdit)
            <label>GHP amount</label>
            <div style="padding-top: 9px;">&#8369;{{ number_format($member->ghp_amount, 2) }} <span class="hint">({{ $member->ghp_amount_is_manual ? 'manual override' : 'automatic' }})</span></div>
            <p class="hint" style="margin-top: 4px;">Change it with "Adjust GHP amount" on the Benefit balance card, so the change is recorded with a reason.</p>
        @else
            <label for="{{ $p }}ghp_amount">GHP amount</label>
            <input type="number" id="{{ $p }}ghp_amount" name="ghp_amount" value="{{ $vAmount }}" step="0.01" min="0" required>
            @error('ghp_amount') <p class="error">{{ $message }}</p> @enderror
            <p class="hint" style="margin-top: 4px;">Annual amount. Standard is &#8369;{{ number_format($defaultGhpAmount, 2) }} (&#8369;4,200.00 with an eligible dependent, added after saving). A different amount is kept as a manual override.</p>
        @endif
    </div>
</div>

<div class="field">
    <p class="hint" style="margin: 0;">
        <strong id="{{ $p }}benefit_preview">&mdash;</strong>
    </p>
    <p class="hint" style="margin-top: 2px;">
        @if ($legacyCycle)
            No custom coverage period is set for this member, so the standard {{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'June–May' : 'April–March' }} cycle applies. Enter a Coverage year and End date to set one.
        @else
            Deductions are prorated by month from the Start date to the End date. The preview is a guide only &mdash; the system recalculates it when you save.
        @endif
    </p>
</div>

<script>
    (function () {
        const P = @json($p);
        const isEdit = @json($isEdit);
        const legacy = @json($legacyCycle);
        const hadOld = @json(session()->hasOldInput());
        const defaults = @json($cycleDefaults);
        const fallback = @json($fallbackCycle);
        const fixedAmount = @json($isEdit ? (float) $member->ghp_amount : null);

        const el = (id) => document.getElementById(P + id);
        const year = el('coverage_year'), apply = el('apply_date'), end = el('coverage_end_date'),
              start = el('start_date'), ded = el('deduction_start_date'), amount = el('ghp_amount'),
              preview = el('benefit_preview');

        if (! year || ! apply || ! end || ! start || ! ded || ! preview) {
            return;
        }

        // A field the admin has typed in is never overwritten by a suggestion.
        const dirty = {
            year: hadOld,
            apply: (isEdit && ! legacy) || hadOld,
            end: (isEdit && ! legacy) || hadOld,
            ded: hadOld,
        };

        const pad = (n) => String(n).padStart(2, '0');
        const iso = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        const parse = (s) => {
            if (! s || ! /^\d{4}-\d{2}-\d{2}$/.test(s)) { return null; }
            const p = s.split('-').map(Number);
            const d = new Date(p[0], p[1] - 1, p[2]);
            return isNaN(d.getTime()) ? null : d;
        };
        const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
        const fmt = (d) => d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
        const peso = (v) => '\u20b1' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        // First day of the 12-month cycle that ends on $endD (mirrors BenefitAccrualService::coverageAnchor()).
        const anchorOf = (endD) => {
            const n = addDays(endD, 1);
            const day = (n.getMonth() === 1 && n.getDate() === 29) ? 28 : n.getDate();
            return new Date(n.getFullYear() - 1, n.getMonth(), day);
        };

        const selectedType = () => {
            const r = document.querySelector('input[name="member_type"]:checked');
            return r ? parseInt(r.value, 10) : 0;
        };

        // Existing rule: the 1st of the month after the member start date, but never before the apply date.
        const derivedDeduction = () => {
            const s = parse(start.value);
            if (! s) { return null; }
            let d = new Date(s.getFullYear(), s.getMonth() + 1, 1);
            const a = parse(apply.value);
            if (a && d < a) { d = a; }
            return d;
        };

        function refreshDeduction() {
            if (dirty.ded) { return; }
            const d = derivedDeduction();
            if (d) { ded.value = iso(d); }
        }

        function syncYearFromEnd() {
            if (dirty.year) { return; }
            const e = parse(end.value);
            if (e) { year.value = anchorOf(e).getFullYear(); }
        }

        function suggestDatesFromYear() {
            const y = parseInt(year.value, 10);
            if (! (y >= 2000 && y <= 2100)) { return; }
            const std = defaults[selectedType()] || defaults[0];
            const f = parse(std.from);
            if (! dirty.apply) { apply.value = iso(new Date(y, f.getMonth(), f.getDate())); }
            if (! dirty.end) { end.value = iso(addDays(new Date(y + 1, f.getMonth(), f.getDate()), -1)); }
        }

        function updatePreview() {
            const e = parse(end.value);
            let from = null, to = null;

            if (e) { from = anchorOf(e); to = e; }
            else if (fallback) { from = parse(fallback.from); to = parse(fallback.to); }

            const d = parse(ded.value) || derivedDeduction();
            const amt = isEdit ? fixedAmount : parseFloat(amount ? amount.value : '');

            if (! from || ! to || ! d || ! (amt >= 0)) {
                preview.textContent = '\u2014';
                return;
            }

            const accrualStart = d > from ? d : from;
            const months = accrualStart <= to
                ? (to.getFullYear() - accrualStart.getFullYear()) * 12 + (to.getMonth() - accrualStart.getMonth()) + 1
                : 0;
            const monthly = amt / 12;

            preview.textContent = 'Coverage period: ' + fmt(from) + ' \u2013 ' + fmt(to)
                + ' \u00b7 Deduction months this cycle: ' + months
                + ' \u00b7 Required this cycle: ' + peso(Math.round(monthly * months * 100) / 100)
                + ' (' + peso(monthly) + '/month)';
        }

        year.addEventListener('input', () => { dirty.year = true; suggestDatesFromYear(); refreshDeduction(); updatePreview(); });
        apply.addEventListener('input', () => { dirty.apply = true; refreshDeduction(); updatePreview(); });
        end.addEventListener('input', () => { dirty.end = true; syncYearFromEnd(); updatePreview(); });
        start.addEventListener('input', () => { refreshDeduction(); updatePreview(); });
        ded.addEventListener('input', () => { dirty.ded = true; updatePreview(); });

        if (amount) {
            amount.addEventListener('input', updatePreview);
        }

        // Add member only: picking Employee/Agent swaps in that type's suggested cycle (unless already edited).
        if (! isEdit) {
            document.querySelectorAll('input[name="member_type"]').forEach((radio) => {
                radio.addEventListener('change', () => {
                    const std = defaults[selectedType()] || defaults[0];
                    if (! dirty.year) { year.value = std.year; }
                    if (! dirty.apply) { apply.value = std.from; }
                    if (! dirty.end) { end.value = std.to; }
                    refreshDeduction();
                    updatePreview();
                });
            });
        }

        updatePreview();
    })();
</script>
