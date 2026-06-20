# Finance Analytics Dashboard — Design

**Date:** 2026-06-20
**Status:** Approved design — ready for implementation plan
**Context:** A dedicated finance analytics dashboard — KPIs, trend charts, filtering, and export/download — sitting on top of the existing finance/GL stack. RBAC-scoped (super_admin/ceo see everything via wildcard; others see only what their role grants).

## Decisions (locked)

| Decision | Choice |
|---|---|
| Charts | **Chart.js + vue-chartjs** (new frontend dependency) — multi-series line/bar/doughnut with axes, legends, tooltips, and native PNG export. |
| Access gate | **Dedicated `finance.analytics.view`** permission, granted to `finance_officer` + `auditor` (read-only); `super_admin`/`ceo` covered by the `*` wildcard. |
| Export | **CSV** (per-series data) + **PDF** (data snapshot via dompdf) + **chart PNGs** (client-side via Chart.js `toBase64Image`). |

## Architecture

```
FinanceAnalyticsService (cached)
  ├─ kpis(from, to): cash position, income/expenditure/surplus (YTD), AP/AR outstanding,
  │                   budget variance (year), latest payroll cost  [+ prior-period deltas]
  ├─ trends(from, to): monthly income/expenditure/surplus/cash series, top expense accounts,
  │                    AR/AP aging buckets, budget-vs-actual by type
  └─ reuses LedgerBalanceService (activity/asOf), IncomeExpenditureReport, BudgetVsActualsReport;
     AP/AR outstanding + aging from VendorInvoice/ArInvoice; cash-account set mirrors CashFlowReport
        │
   Finance/AnalyticsController (permission:finance.analytics.view)
     ├─ dashboard(Request)  → Inertia 'Finance/Analytics/Dashboard'  (filters: fiscal_year, from, to)
     ├─ exportCsv(Request)  → streamDownload monthly series CSV
     └─ exportPdf(Request)  → dompdf snapshot (KPIs + series tables)
        │
   Finance/Analytics/Dashboard.vue
     KPI strip (cards + sparkline trend + Δ vs prior) · charts (Chart.js) · filters · CSV/PDF links · per-chart PNG
   Nav entry gated on finance.analytics.view
```

- **`FinanceAnalyticsService`** (new): `kpis(CarbonInterface $from, CarbonInterface $to): array`, `trends(CarbonInterface $from, CarbonInterface $to): array`, `aging(): array`. Monthly buckets via `DbExpr::yearMonth` / per-month `LedgerBalanceService::activity`. Cached ~120s.
- **Cash accounts** mirror `CashFlowReport::cashAccountIds()` — active `OrgBankAccount.gl_account_id` + GL codes 1010/1130.
- **KPIs**: `cash_position` (asOf to), `income_ytd`/`expenditure_ytd`/`surplus_ytd` (from→to flow), `ap_outstanding`, `ar_outstanding`, `budget_variance` (BudgetVsActuals year totals), `latest_payroll_cost` (latest approved/paid `PayrollRun.gross_total`); each with a prior-equal-period delta where meaningful.
- **Trends** (monthly over from→to): `income`, `expenditure`, `surplus`, `cash` (cumulative asOf each month-end); `top_expenses` (top 8 expense accounts over range); `aging` (AR & AP current/30/60/90+); `budget` (per type ytd_budget/ytd_actual/variance).
- **Controller** gated by `finance.analytics.view`; CSV + PDF routes use the `export.csv`/`export.pdf` suffix (smoke-test skip). PDF is a dompdf data snapshot (KPI cards + series tables — Chart.js canvases don't render server-side; PNG export is client-side).
- **Frontend**: install `chart.js` + `vue-chartjs`; thin wrapper components (`LineChart`/`BarChart`/`DoughnutChart`) over vue-chartjs; `Dashboard.vue` composes the KPI strip + charts + a filter bar (fiscal-year select, from/to dates) + export buttons + per-chart "PNG" download. Reuse the existing `ChartCard.vue` for framing.
- **RBAC**: every route + the nav entry gated by `finance.analytics.view`; super_admin/ceo pass via wildcard. The dashboard is read-only.

## Charts (default set)

1. **Income vs Expenditure** — monthly grouped bar (12 months).
2. **Surplus / (Deficit)** — monthly line.
3. **Cash balance** — monthly line (cumulative).
4. **AR & AP aging** — doughnut (or stacked bar) of current/30/60/90+.
5. **Budget vs Actuals (YTD)** — grouped bar by account type.
6. **Top expense accounts** — horizontal bar (top 8 over range).

## Filters

- **Fiscal year** select (defaults to current); **from/to** date range (defaults to FY-start → today). KPIs are YTD (FY-start → to); trends are monthly over from→to. Granularity is monthly (MVP).

## Error handling & integrity

- **Read-only**: never mutates the ledger; all aggregation over posted+reversed journal lines (the `LedgerBalanceService` invariant) and open AP/AR.
- **Empty/zero data**: no budget for the year → budget chart shows zeros (not an error); no payroll runs → latest payroll cost 0; charts render empty gracefully.
- **Cached** with a short TTL; cache key includes the date range.
- **Backwards-compatible**: purely additive (new service/controller/page/permission/dep); existing suites untouched.

## Testing (Pest)

- `FinanceAnalyticsService::kpis` — correct cash/income/expenditure/surplus/AP/AR/budget/payroll over a seeded GL + invoices + a payroll run.
- `trends` — monthly series length matches the range; income/expenditure per month correct; aging buckets; top expenses ordering.
- Controller — dashboard renders for finance_officer/auditor/super_admin; **employee forbidden**; CSV (`text/csv`) + PDF (`application/pdf`) export; permission gate on every route.
- Accessibility — filter inputs carry `aria-label`; the param-less dashboard route renders for the smoke test.
- Frontend build green with the new charting dependency.

## Conventions

- Enum/Service/Controller/Resource pattern; DB-backed permission (+ per-user JSON for test grants); export via `streamDownload`/`fputcsv` + dompdf blade; `DbExpr` for date grouping; `declare(strict_types=1)`; every input carries `aria-label`.

## Out of scope (future)

- Drill-down from a chart into the GL ledger; daily/quarterly granularity; saved/custom dashboards; scheduled emailed analytics packs; forecasting/trend projection; department-dimension slicing (KPIs are org-wide for MVP).
