# Chapter 47 — How to fund and sequence the roadmap

> *In one paragraph.* Three sequencing paths a buyer might take. Each has its own dependency on Chapter 46's 4-phase plan, its own engineering-week shape, and its own team composition. Pricing in GHS is intentionally absent — this chapter argues effort and risk, not money. The same code is on the same Git tag whichever path is signed; what changes is who sits in the standup, which slice of the audit JSON gets pulled into v1.0, and how many third parties the PM has to herd. The question this chapter answers is not "what does CIHRMS cost?" but "what shape of team, over what calendar, do you need to land which slice of the roadmap?"

---

## How to read this chapter

One engineering-week (`ew`) = one senior engineer × 40 productive hours. It is the unit Chapter 41's performance budgets are quoted in, and the unit Chapter 46's phase totals are summed in, so the numbers below are commensurable with everything else in Part III. We talk about team COMPOSITION (who sits at the desk) and RISK (what could derail the calendar), not GHS. FTE means "full-time equivalent for the duration of the phase" — a 0.5 designer for 14 weeks is 7 weeks of designer time, not a designer who works half-days. Where a path totals fractional FTE the implication is shared headcount (one PM split across two engagements, for example), not a person working a partial week.

Effort estimates in this chapter are bottom-up totals of the phase numbers carried forward from Chapter 46's roadmap (Phase 1 ≈ 14 ew of items called out as priority, Phase 2 ≈ 18 ew, Phase 3 ≈ 12 ew, Phase 4 ≈ 8 ew), uplifted for the team-shape overhead (QA, DevOps, PM, design, GRC) that pure-engineering numbers ignore. The uplift is the difference between "a senior backend writes the listener" and "the listener is shipped, reviewed, tested, deployed, documented, and announced."

---

## Three sequencing paths

The same v1.0 codebase can land via three distinct go-to-market motions. Each one is internally consistent — the scope, team, and calendar reinforce each other. Mixing motions (Path A scope with Path B team, or Path C calendar with Path B compliance posture) is a known anti-pattern that has sunk every public-sector software programme the project lead has seen in the last decade.

### Path A — Pilot MDA → tender

Best for a Ministry, Agency, or Department (MDA) that wants to land a pilot before going to formal tender. The motion is: sign an MoU or letter-of-intent with a single ministry, deliver Phase 1 plus a small, named slice of Phase 2 against a fixed scope, and use the production deployment as proof for the open tender that follows.

**Scope.** Phase 1 in full (Ghana Card NIA adapter live, ApplicantHired chain wired, BenefitPremium DeductionType, PostgreSQL + Redis + Horizon migration, scheduled cron entries, OffboardingCompleted listener, e-Levy disclosure on payslip, audit-chain integration on Loans + Documents + Benefits, SequenceService completeness, dual-control breadcrumb hardening, AG-pack v1.1) plus the selected Phase 2 items that the procurement framework specifically asks for: Whistleblower Phase 2 hardening (evidence download, CAPTCHA, retention schedule, CHRAJ transfer-of-custody hook), DPA §44 breach-notification workflow promoted from Phase 4, and the Auditor-General reconciliation report (which is shipped from Ch 24 but needs the variance-vs-establishment view to be tender-ready). Total: **≈ 14 weeks** of focused engineering against a locked scope.

**Team shape.**

- Engineering: 2 senior backend, 1 senior frontend, 1 QA, 1 DevOps (part time, 0.5 FTE)
- Product: 1 PM (full time)
- Design: 0.5 designer (mostly accessibility-audit preparation per Ch 35 + Wave 4 visual polish from Ch 33 against the realities of the shipped UI)
- Total: **≈ 5.5 FTE for 14 weeks ≈ 77 ew**

Engineering carries 4.5 of those 5.5 FTE, which is the ratio the codebase has been built and tested at; pushing more bodies in does not produce more code under Brooks's law, and pushing fewer means QA gets squeezed first and the AG-pack ships uncertified.

**Risks.**

1. **NITA hosting capacity.** The Phase 4 hosting decision (NITA vs cloud vs hybrid) gets dragged forward in scope because the pilot ministry insists on government-cloud day one. This is the single most common Path A failure mode.
2. **Ghana Card NIA sandbox access.** The NIA Identity Verification Service (IVS) sandbox queue is long; without a signed MoU before kickoff the Phase 1 Ghana Card adapter ships against fixture data, and the pilot's go-live is contingent on a third-party milestone outside the engineering team's control.
3. **Key-person dependency.** Phase 1 work is concentrated in code paths the original CIHRMS architect knows by heart (audit chain, sequence service, Ghana Card detection model). Two of the 14 weeks are at risk if that engineer is unavailable.
4. **Scope creep mid-pilot.** Especially around Performance management (the ministry wants the 360-review cycle "since you have it") and Reports (the auditor wants three more saved definitions). Phase 1 does not include those; the Performance 360 engine is a Phase 2 deliverable explicitly.
5. **Tender-pack drift.** The pilot ends with a winning bid in a tender — but the tender's mandatory requirements have evolved since the pilot kicked off (PSC issues a new directive, the Cybersecurity Authority publishes a registration deadline). The product must ride that drift or lose the very tender it was built to win.

**Mitigations.**

- Lock Phase 1 scope in writing as an annex to the MoU **before** kickoff. The annex names the roadmap items that ship and explicitly states which Phase 2/3/4 items are out of scope. Change orders against the annex carry their own engineering-week cost and PM approval.
- Negotiate NIA IVS sandbox access during MoU negotiation, not after kickoff. The ministry's letterhead opens that door faster than the vendor's.
- Pair-program the audit-chain and sequence-service work in Phase 1 (already nominally pair-programmed but tighten the discipline) so knowledge spreads to at least two engineers before pilot acceptance.
- Reserve 10% of each sprint as PM-controlled "stakeholder asks" capacity. Visible reserve is harder for the ministry to argue with than refusal-by-default.
- Subscribe the PM to PSC and CSA circulars so drift is detected as it happens, not at the tender briefing.

### Path B — Donor-funded

Best for a World Bank, GIZ, USAID, GAVI, or similar programme — typically as a sub-component of a broader public-sector modernisation grant. The motion is multi-quarter, milestone-funded against a logframe with measurable indicators, and the scope is wider because the donor wants the platform to outlast the engagement.

**Scope.** Phase 1 + Phase 2 + Phase 3 (compliance hardening + integrations + commercial-reach features that increase sustainability post-grant). That means everything from Path A's pilot scope, plus the Phase 2 catalogue (per-grade leave entitlements, Performance 360 + KPI cascade, Documents v2, Identity Phase 2 with biometric template extraction, Audit Log vocabulary widening, Whistleblower hardening, Chat Phase 2 with Reverb realtime + body encryption, DPA breach + retention promoted in, third-party WCAG axe + NVDA + VoiceOver audit + Public Accessibility Statement, ISO 27001 formal certification + third-party pen test, Postgres read replica), plus the Phase 3 items that donors specifically reward (live GRA/SSNIT/NPRA/NHIA submission webhooks, GIFMIS live REST push, NiaOfficialProvider against the certified IVS SDK, multi-currency posting + FX rate tables, off-site sealed audit-log replication, CSA Act §59 incident-reporting integration). Total: **≈ 24-28 weeks** depending on how aggressive the donor wants the logframe.

**Team shape.**

- Engineering: 3 senior backend, 2 senior frontend, 2 QA, 1 DevOps (full time), 1 SRE
- Product: 1 PM + 0.5 programme manager (donor reporting, logframe tracking, indicator harvesting)
- Design: 1 designer (full UX audit, WCAG 2.1 AA pass, design system extension for Wave 5 and Phase 2 surfaces)
- Compliance / GRC: 0.5 analyst (DPA §46 registration with the Data Protection Commission, Cybersecurity Authority registration under Act 1038, ISO 27001 readiness assessment + Statement of Applicability, third-party pen test coordination)
- Total: **≈ 11 FTE for 28 weeks ≈ 308 ew**

The team doubles roughly along the line that distinguishes "shipping code" from "shipping a programme." Two QA, an SRE, a programme manager, and a GRC analyst are the donor-funded delta over Path A. Each of those four roles is sized against a specific donor expectation that does not exist in a pure-MDA pilot.

**Risks.**

1. **Donor reporting overhead.** Logframe reporting, semi-annual reviews, mid-term evaluation, end-of-grant evaluation. The combined drag on PM + programme-manager time is 15-20% of their joint capacity — material in a 28-week calendar.
2. **Multiple stakeholder ministries.** Donor programmes touch 3-5 ministries by default. Each one has different sign-off processes, conflicting priorities, and competing visions for what the platform should do for them. The vendor is not the steward of that politics — but the project's calendar absorbs every week of it.
3. **IRR / programme-evaluation gates.** Mid-term evaluations may require Phase 4 items earlier than planned (independent audit attestation, hosting-location certificates, marketing role permissions seeded, manager-on-leave escalation timeout — all currently scoped for Phase 4 in Ch 46's roadmap). The PM has to negotiate which Phase 4 work gets promoted versus deferred to a follow-on programme.
4. **Currency / FX shifts on donor commitments.** Donor obligations are denominated in USD or EUR; vendor costs are local. A 15% FX move over 28 weeks eats margin if the contract has no FX clause. (This is a contract-terms risk, not an engineering risk — but the engineering team feels it as headcount uncertainty.)
5. **Open-ended scope from the logframe side.** Indicators like "x% of MDA staff onboarded" are not engineering-bounded; they require an adoption push that engineering can support but not own. If the adoption work is not budgeted as its own line item, engineering ends up doing on-site training in week 26 and not finishing Phase 3.
6. **Procurement-induced delays.** Donor procurement rules require open competition for sub-contracts (audit, pen test, accessibility audit, biometric SDK). Each of those carries a 6-10 week procurement tail that is not engineering-controlled.

**Mitigations.**

- Sprint-based deliveries aligned to donor milestones — every two-week sprint demo doubles as a sub-component progress report. Milestone reports become a by-product of the engineering cadence, not extra work.
- Single accountable Project Management Unit (PMU) on the buyer side, with a named individual who owns conflicting-priority arbitration. Multi-ministry governance without a single point of decision is the most common reason donor programmes overrun.
- Reserve **15% capacity per sprint** for stakeholder asks. This is double the Path A reserve and matches the empirical drag observed on comparable World Bank human-capital programmes.
- Get the audit / pen-test / accessibility-audit / biometric-SDK procurements running in week 1, not week 18. The procurement tail is the long pole; engineering can adjust to whichever provider wins but cannot adjust to "no provider yet."
- FX clause in the master agreement. Engineering doesn't sign it but engineering feels every week of fuzziness about whether the team is funded.

### Path C — Commercial-SaaS

Best for a private-sector customer — a large local employer (telco, brewery, bank, NGO with 500+ staff), a multinational subsidiary that needs a Ghana-compliant payroll without piping data to a US headquarters, or a regional NGO operating in multiple West African countries. The motion is quicker time-to-revenue, less compliance polish, more emphasis on customisation and on the commercial-reach features that Ch 46 currently parks in Phase 3.

**Scope.** Phase 1 minus the IPPD/GIFMIS items (a private-sector customer does not file IPPD returns and posts to their own ERP, not GIFMIS), plus the Phase 3 commercial-reach items the customer actually needs: MoMo channels toggled on with live MTN/Vodafone/AirtelTigo provider onboarding, eNPS pulse surveys, multi-currency posting with effective-dated FX tables, second-gateway pair (Stripe + Flutterwave alongside Paystack), and the realtime layer (Reverb + Echo) that the customer's UX expectations assume. Total: **≈ 12-14 weeks**.

**Team shape.**

- Engineering: 2 senior backend, 1 senior frontend, 1 QA, 0.5 DevOps
- Product: 0.5 PM (split across pre-sales discovery + delivery)
- Design: 0.25 (branding, white-label theme injection, customer-specific page polish — no fresh UX audit because the surfaces shipped are the surfaces sold)
- Total: **≈ 4.25 FTE for 14 weeks ≈ 60 ew**

This is the leanest of the three paths because the customer has fewer compliance gates, less audit overhead, and a defined acceptance bar ("does it run our payroll on the 25th and does it not embarrass us in the team-comms tab?") that engineering can hit without GRC, SRE, or a dedicated programme manager.

**Risks.**

1. **Quicker time-to-revenue offsets compliance polish.** The customer discovers Phase 4 gaps (no ISO 27001 cert, no third-party pen test attestation, no formal accessibility statement) at vendor due diligence in month 6 — three months after they've paid. Engineering's response is to either ship those at "Phase 1.5" pace under contractual pressure, or take a hit on the next renewal.
2. **Customisation requests for industry-specific add-ons.** Telcos want bill-payment integrations against their own billing system. Banks want core-banking SSO. NGOs want donor-reporting export formats. Each of these is bounded engineering work but each of them sits outside Ch 46's roadmap.
3. **Multi-currency assumption may require accelerated Phase 3 work.** Multi-currency posting is a Phase 3 item; commercial customers (especially multinationals) assume it day one. The 12-14 week calendar will compress if the customer treats multi-currency as v1.0 table stakes rather than v1.1 stretch.
4. **Compliance posture mismatch with customer's own compliance lens.** A bank customer's risk-and-compliance team will read Ch 40 and Ch 44 of this dossier, find the partial-status items, and ask for shipping commitments on each one. Engineering ends up with a Phase 1.5 backlog assembled by the customer, not by the product team.
5. **Customer-paid does not mean customer-trained.** Adoption support is light in Path C — if month 4 sees low usage the customer renegotiates the deal, blames the platform, and renewal becomes contentious. The engineering team had no levers on usage; the result still lands on the engineering calendar as "build the adoption nudges we should have built up-front."
6. **Lock-in risk for the customer.** Paystack-only payment rail in v1.0 means the customer is one-gateway-dependent. If their commercial terms with Paystack shift, the second-gateway pair from Phase 3 gets pulled into v1.1 at the customer's pace.

**Mitigations.**

- Tight v1.0 scope contract — name the modules shipped, the modules deferred, and the modules explicitly out of scope. Three-bucket clarity reduces month-6 surprise.
- Premium tier for industry add-ons. The customisation requests are real and should be billed as such; refusing them outright kills the renewal, doing them on the base contract kills the margin.
- Phase 3 multi-currency promoted into the v1.0 sprint plan as a stretch item if the customer profile signals multinational scope. Single-currency-only is fine for Ghana-only customers and that should be confirmed in pre-sales discovery, not assumed.
- Compliance posture briefing in pre-sales. Show the customer's risk team the audit JSON's `standards_summary` table from Ch 44 in week 0, not week 18. Surface the partial-status items proactively so they're negotiated, not discovered.
- Adoption pack (kiosk decals, induction deck, sample policies, a 30-minute "what's in this for you" recording) bundled into v1.0 delivery. Not engineering work, but the engineering calendar absorbs its absence.
- Build the second-gateway pair on a feature flag from day one even if only Paystack is enabled at go-live. The architecture supports it (Ch 22); the engineering cost of the flag is negligible; the renegotiation cost without it is material.

---

## Team shape per phase

The path-level totals above sum the team shape across all phases included in that path. The table below decomposes the team shape per phase, so a mixed buyer (Path A buyer who adds a Phase 3 line item, or a Path B buyer who descopes Phase 4) can rebuild their own total.

| Phase | Senior BE | Senior FE | QA | DevOps | PM | Design | GRC | Total FTE |
|---|---|---|---|---|---|---|---|---|
| 1 | 2 | 1 | 1 | 0.5 | 1 | 0.5 | 0 | 6.0 |
| 2 | 2 | 1.5 | 1 | 0.5 | 1 | 0.5 | 0.25 | 6.75 |
| 3 | 2 | 2 | 1 | 1 | 1 | 1 | 0 | 8.0 |
| 4 | 1.5 | 1 | 0.5 | 0.5 | 0.5 | 0.25 | 0.5 | 4.75 |

A reading of the table:

- **Phase 1** is the densest backend phase — listeners, event chains, schedule wiring, identity gate retrofits, audit-chain integration. Frontend stays at 1 FTE because most of Phase 1 is plumbing that does not change a screen. GRC is 0 because Phase 1's compliance work is engineering-shaped (audit chain integration, scheduled cron entries) rather than policy-shaped.
- **Phase 2** adds frontend weight (Performance 360 surfaces, Documents v2 routing UI, Identity Disputed workflow, Chat Phase 2 attachments) and introduces GRC at 0.25 FTE — DPA breach-notification metadata and ISO 27001 readiness paperwork start here.
- **Phase 3** is the most frontend-heavy phase (live MoMo provider onboarding screens, multi-currency surfaces across Finance, second-gateway pair admin, kiosk Phase 3 polish), full DevOps (live submission webhooks, off-site sealed audit replication), and a full designer FTE (AI surfaces, in-system learning content layer, multi-currency UX, scoreboard re-themes for the breadth of new screens). GRC is 0 because Phase 3's compliance work happens at the regulator-integration layer, not the certification layer.
- **Phase 4** shrinks across the board. The remaining work is operational maturity, regulatory drift management, and certification refresh — high-trust, low-volume engineering with a half-time GRC analyst running the ISO 27001 recertification cadence.

---

## Risk register (cross-path)

| # | Risk | Path | Owner | Mitigation |
|---|---|---|---|---|
| 1 | NITA hosting capacity / certification timeline | A, B | Buyer ops | Pre-engagement capacity check; Ch 43 hosting decision negotiated in MoU not week 12 |
| 2 | Ghana Card NIA sandbox access | A, B | CIHRMS architect + buyer | Apply for IVS sandbox during MoU negotiation, not after kickoff |
| 3 | Key-person dependency on architect | All | CIHRMS team lead | Pair-programming on audit chain + sequence service + Ghana Card, ADRs per Ch 37 |
| 4 | Scope creep mid-engagement | All | PM | Locked scope annex on the MSA; change-order discipline; 10–15% sprint reserve per path |
| 5 | Third-party module IP / licence | All | Legal | Audit composer.json + package.json before signing; Ch 36 dependency catalogue exists |
| 6 | Donor / buyer reporting overhead | B | PM + programme manager | 15% capacity reserve; sprint demos double as logframe reports |
| 7 | Currency / FX swing on donor obligations | B | Buyer + vendor finance | FX clause in master agreement |
| 8 | Sub-contract procurement tail | B | PMU + vendor PM | Start audit / pen-test / accessibility / biometric procurements in week 1 |
| 9 | Compliance posture surprise at customer due diligence | C | PM + sales | Show audit JSON `standards_summary` in pre-sales week 0 |
| 10 | Multi-currency assumed in v1.0 | C | Pre-sales | Flag-on-from-day-one architecture; pre-sales discovery confirms scope |
| 11 | Single-gateway lock-in (Paystack only) | C | Customer + vendor | Second-gateway pair on feature flag from day one |
| 12 | Adoption work not budgeted as its own line | B, C | PMU + customer | Adoption pack bundled with v1.0; usage telemetry from Ch 31 surfaced weekly |
| 13 | Regulatory drift mid-engagement (PSC directive, CSA registration deadline, PAYE bracket refresh) | All | PM | PSC + CSA + GRA circular subscriptions on the PM's desk |
| 14 | Mid-term evaluation pulls Phase 4 forward | B | PM | Phase 4 backlog pre-prioritised so promotion candidates are pre-named |
| 15 | Engineering team turnover during long engagements (Path B) | B | CIHRMS team lead | ADRs, runbook completeness (Ch 43), pair-programming, 13-week minimum tenure tracking |

---

## Out of scope of this dossier

The Delivery Dossier is an engineering and compliance artefact. The following are deliberately not addressed here and live in the commercial proposal that accompanies this document:

- **Pricing in GHS, USD, or any other currency** (per spec §3 — this chapter argues effort, not money).
- **Contract terms, payment milestones, and acceptance criteria** — those are MSA and SOW concerns, not engineering concerns.
- **Partner identification** for sub-components (audit firm, pen-test firm, accessibility auditor, biometric SDK provider, hosting partner) — named in the commercial proposal once the path is selected.
- **Margin, overhead structure, and internal cost-recovery** of the vendor's own delivery org.
- **Marketing, sales lifecycle, lead generation, and pipeline development** — referenced lightly in Path C ("pre-sales discovery") but not modelled here.
- **Future product lines beyond CIHRMS v1.0** (cross-institute reporting suite, in-country payroll bureau, regional West-African expansion) — these are interesting product bets but not roadmap items, and the team-shape numbers above would change materially if any of them moved into scope.
- **Specific customer or buyer identities.** The paths are archetypes, not RFP responses; live opportunities map onto these archetypes with deltas of their own.

---

## A closing word

There's a real, shippable product here. Chapters 3 through 36 walked the modules; Chapters 37 through 46 walked the architecture, the compliance posture, the test surface, the deployment, the gaps, and the roadmap. This chapter argued that the same Git tag can be financed three different ways, each with internally consistent scope, team, and calendar. The choice between them is not technical — it is a question of which buyer is in the room, what their posture is, and what they are willing to commit to over the next 14 to 28 weeks.

Pick the path that matches the buyer in front of you. Lock the scope. Reserve the capacity for the asks you know are coming. Get the third-party procurement queues running in week 1. And let the engineering team do the work the dossier has already proved they can do.

That is how you fund and sequence this roadmap.
