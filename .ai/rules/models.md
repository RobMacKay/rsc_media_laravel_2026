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
