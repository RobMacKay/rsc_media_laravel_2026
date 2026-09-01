{{-- Rendered by dompdf, which understands roughly CSS 2.1: tables and inline
     styles only, no flex or grid, and always light regardless of the viewer's
     theme. The on-screen version lives in pages/client/invoice. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }} — {{ $settings->company_name }}</title>
    <style>
        @page { margin: 34px 40px 60px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            line-height: 1.5;
            color: #0a2029;
            margin: 0;
        }

        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; padding: 0; }

        .muted { color: #5a6b72; }
        .right { text-align: right; }
        .strong { font-weight: bold; }

        .label {
            font-size: 8px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #5a6b72;
            padding-bottom: 4px;
        }

        .rule { border-bottom: 1.5px solid #0a2029; height: 1px; }
        .hairline { border-bottom: 0.75px solid #c9d4d6; height: 1px; }

        .doc-title { font-size: 26px; font-weight: bold; letter-spacing: -0.5px; }
        .number { font-size: 13px; font-weight: bold; }

        .lines th {
            font-size: 8px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #5a6b72;
            border-bottom: 1.5px solid #0a2029;
            padding: 0 0 6px;
            text-align: left;
        }
        .lines td { padding: 11px 0; border-bottom: 0.75px solid #e2e9ea; }

        .totals td { padding: 5px 0; }
        .totals .grand td {
            border-top: 1.5px solid #0a2029;
            padding-top: 9px;
            font-size: 14px;
            font-weight: bold;
        }

        .panel { background: #f1f5f5; padding: 12px 14px; }

        .status {
            display: inline-block;
            padding: 3px 9px;
            font-size: 8.5px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .status-paid { background: #d8efe6; color: #10614c; }
        .status-due { background: #fde8dc; color: #8a3a12; }

        .foot {
            position: fixed;
            bottom: -34px; left: 0; right: 0;
            font-size: 8px;
            color: #5a6b72;
            border-top: 0.75px solid #e2e9ea;
            padding-top: 7px;
        }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width: 58%">
                <img src="{{ public_path('images/logo.svg') }}" alt="{{ $settings->company_name }}" width="105" height="21">
                <div class="muted" style="padding-top: 9px">
                    @foreach ($settings->addressLines() as $line)
                        {{ $line }}@if (! $loop->last)<br>@endif
                    @endforeach
                </div>
                <div class="muted" style="padding-top: 7px">
                    @if ($settings->email){{ $settings->email }}<br>@endif
                    @if ($settings->phone){{ $settings->phone }}<br>@endif
                    @if ($settings->website){{ $settings->website }}@endif
                </div>
            </td>
            <td class="right">
                <div class="doc-title">Invoice</div>
                <div class="number" style="padding-top: 2px">{{ $invoice->number }}</div>
                <div style="padding-top: 9px">
                    <span class="status {{ $invoice->status->isOutstanding() ? 'status-due' : 'status-paid' }}">
                        {{ $invoice->status->isOutstanding() ? ($invoice->isOverdue() ? 'Overdue' : 'Due') : 'Paid' }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <div class="rule" style="margin: 18px 0"></div>

    <table>
        <tr>
            <td style="width: 42%">
                <div class="label">Billed to</div>
                <div class="strong">{{ $invoice->team->name }}</div>
                @if ($invoice->team->billing_email)
                    <div class="muted">{{ $invoice->team->billing_email }}</div>
                @endif
                @if ($invoice->team->purchase_order_ref)
                    <div class="muted" style="padding-top: 5px">PO {{ $invoice->team->purchase_order_ref }}</div>
                @endif
            </td>
            <td style="width: 29%">
                <div class="label">Issued</div>
                <div>{{ $invoice->issued_on->format('j F Y') }}</div>
                <div class="label" style="padding-top: 11px">Due</div>
                <div @class(['strong' => $invoice->isOverdue()])>{{ $invoice->due_on->format('j F Y') }}</div>
            </td>
            <td style="width: 29%">
                <div class="label">Payment reference</div>
                <div class="strong">{{ $invoice->paymentReference($settings) }}</div>
                @if ($invoice->project)
                    <div class="label" style="padding-top: 11px">Project</div>
                    <div>{{ $invoice->project->reference ?? $invoice->project->title }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 26px">
        <thead>
            <tr>
                <th style="width: 58%">Description</th>
                <th style="width: 14%" class="right">Qty</th>
                <th style="width: 14%" class="right">Unit</th>
                <th style="width: 14%" class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="strong">{{ $invoice->note }}</div>
                    <div class="muted" style="font-size: 9.5px">{{ $invoice->type->label() }}</div>
                </td>
                <td class="right">1</td>
                <td class="right">{{ $invoice->money($invoice->amount, 2) }}</td>
                <td class="right">{{ $invoice->money($invoice->amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table style="margin-top: 18px">
        <tr>
            <td style="width: 55%"></td>
            <td>
                <table class="totals">
                    <tr>
                        <td class="muted">Subtotal</td>
                        <td class="right">{{ $invoice->money($invoice->amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="muted">
                            VAT
                            @if ($invoice->vat_rate > 0)
                                at {{ rtrim(rtrim(number_format($invoice->vat_rate, 1), '0'), '.') }}%
                            @endif
                        </td>
                        <td class="right">{{ $invoice->money($invoice->vatAmount(), 2) }}</td>
                    </tr>
                    <tr class="grand">
                        <td>Total due</td>
                        <td class="right">{{ $invoice->money($invoice->total(), 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 26px">
        <tr>
            <td style="width: 48%; padding-right: 10px">
                <div class="panel">
                    <div class="label">How to pay</div>
                    <div class="strong">Bank transfer</div>
                    <table style="margin-top: 7px">
                        <tr><td class="muted" style="width: 47%">Account name</td><td>{{ $settings->account_name }}</td></tr>
                        <tr><td class="muted">Bank</td><td>{{ $settings->bank_name }}</td></tr>
                        <tr><td class="muted">Sort code</td><td>{{ $settings->sort_code }}</td></tr>
                        <tr><td class="muted">Account number</td><td>{{ $settings->account_number }}</td></tr>
                        <tr><td class="muted">Reference</td><td class="strong">{{ $invoice->paymentReference($settings) }}</td></tr>
                    </table>
                </div>
            </td>
            <td style="width: 52%; padding-left: 10px">
                <div class="label">Terms</div>
                <div>
                    Payment due within {{ $terms }} days of issue.
                    @if ($settings->late_fee_percent > 0)
                        Late payments may carry interest at
                        {{ rtrim(rtrim(number_format($settings->late_fee_percent, 1), '0'), '.') }}% per month.
                    @endif
                </div>
                @unless ($settings->vat_registered)
                    <div class="muted" style="padding-top: 9px">No VAT is charged on this invoice.</div>
                @endunless
                @if ($invoice->status->isOutstanding() === false && $invoice->paid_at)
                    <div style="padding-top: 9px">Paid {{ $invoice->paid_at->format('j F Y') }} — thank you.</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="foot">
        <table>
            <tr>
                <td>
                    {{ $settings->company_name }}@if ($settings->company_number) · Registered in Scotland {{ $settings->company_number }}@endif
                    @if ($settings->vat_registered && $settings->vat_number) · VAT {{ $settings->vat_number }}@endif
                </td>
                <td class="right">{{ $invoice->number }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
