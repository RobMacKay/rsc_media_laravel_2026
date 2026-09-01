---
paths:
  - 'app/Models/**'
---

# Models

## Proposals become projects; PRJ references are shared
Work bigger than a ticket runs Proposal → Project. A client submits a proposal, the studio writes scope/phases/price onto the same record, sends it, and the client signs off. `Proposal::approve()` is the one place that transition happens: it opens the Project, links it back, and raises the deposit invoice in a transaction.

References are `PRJ-000` and are shared across both tables — `Proposal::nextReference()` takes the max of both so an approved proposal keeps the number the client already knows it by. Do not allocate references anywhere else.

Scope is stored one line per bullet, phases as `name | date | note`, matching the admin textareas. Parse with `scopeLines()` and `phaseRows()`; `phaseRows()` tolerates malformed lines rather than throwing.

Money decisions follow the same rule as ticket quotes: only `ClientAccess::Full` can sign off, because it commits spend.

## VAT needs both the toggle and the number
`effectiveVatRate()` returns the rate only when `vat_registered` is on **and** `vat_number` is filled; otherwise zero. A VAT invoice has to show the number, so charging without one would produce an invoice that is not valid, and the studio expects clearing the number to stop VAT everywhere.

`chargesVat()` follows `effectiveVatRate()`, and every user-facing "+ VAT" / "ex VAT" / "inc VAT" line is gated on it, so the charge and its label can never disagree. Do not gate copy on `vat_registered` alone — that was the first attempt and it left "+ VAT" on proposal prices while nothing was being charged.

Invoice documents gate on the invoice's own snapshotted `vat_rate` instead, so one raised while registered keeps its VAT line if the studio later deregisters.

Tests that expect VAT on an invoice must set `vat_number` as well as `vat_registered`.
