# CIHRMS — System Design Diagrams

> **Format:** Mermaid (renders natively on GitHub, GitLab, VS Code with the Mermaid extension, and most static site generators).
> **Companion docs:** [PRD.md](PRD.md), [TRD.md](TRD.md), [SYSTEM_ARCHITECTURE.md](SYSTEM_ARCHITECTURE.md)
> **Last revised:** 2026-05-20

This document gathers the visual system designs for CIHRMS. Diagrams are grouped by concern:

1. C4 — System Context
2. C4 — Container view
3. C4 — Component view (per high-leverage module)
4. Deployment topology
5. Request/response sequence (Inertia + Audit + 2FA)
6. Webhook / event-driven sequences
7. State machines (Payroll Run, Leave, Off-boarding, Performance Contract, Loan, Whistleblower)
8. ERD — high-level entity relationships
9. RBAC resolution flow
10. Module dependency map

---

## 1. C4 — System Context

```mermaid
flowchart LR
    %% External actors
    Employee([Employee])
    Manager([Manager / Dept Head])
    HR([HR Admin / Officer])
    Finance([Finance Officer])
    Auditor([Auditor / DPO])
    Investigator([Investigator])
    Applicant([Public Applicant])
    Reporter([Anonymous Reporter])

    %% System
    CIHRMS["<b>CIHRMS</b><br/>Government-grade HRMS<br/>Laravel 13 + Vue 3"]

    %% External systems
    NIA[(NIA / Ghana Card)]
    GhIPSS[(GhIPSS / Banks)]
    MoMo[(MoMo<br/>MTN / Voda / AT)]
    SSNIT[(SSNIT)]
    GRA[(GRA / PAYE)]
    NPRA[(NPRA / Tier-2)]
    NHIA[(NHIA)]
    Hubtel[(Hubtel<br/>SMS / USSD)]
    WhatsApp[(WhatsApp<br/>Business)]
    Zoho[(Zoho CRM)]
    ESign[(E-sign Provider)]
    MSGraph[(Microsoft Graph)]
    Google[(Google Workspace)]
    Slack[(Slack)]
    IdP[(SSO IdP<br/>SAML/OIDC)]
    Biometric[(Biometric<br/>Device)]
    Sentry[(Sentry)]
    S3[(S3-compatible<br/>Storage)]

    %% User interactions
    Employee -- "Self-service web / PWA / USSD" --> CIHRMS
    Manager -- "Approvals" --> CIHRMS
    HR -- "Manage org / payroll / lifecycle" --> CIHRMS
    Finance -- "Run + approve payroll" --> CIHRMS
    Auditor -- "Audit pack" --> CIHRMS
    Investigator -- "Triage WB reports" --> CIHRMS
    Applicant -- "Apply via careers" --> CIHRMS
    Reporter -- "Anonymous report" --> CIHRMS

    %% External integrations
    CIHRMS -- "Identity verify" --> NIA
    CIHRMS -- "Bulk credit" --> GhIPSS
    CIHRMS -- "Mobile money disburse" --> MoMo
    CIHRMS -- "Tier-1 returns" --> SSNIT
    CIHRMS -- "PAYE returns" --> GRA
    CIHRMS -- "Tier-2 returns" --> NPRA
    CIHRMS -- "Health levy" --> NHIA
    Hubtel -- "Inbound SMS/USSD" --> CIHRMS
    CIHRMS -- "Outbound SMS" --> Hubtel
    WhatsApp -- "Inbound msg webhook" --> CIHRMS
    Zoho -- "Contact sync webhook" --> CIHRMS
    ESign -- "Envelope events" --> CIHRMS
    MSGraph -- "Calendar webhook" --> CIHRMS
    Google -- "Drive/Calendar webhook" --> CIHRMS
    Slack -- "Slash/event webhook" --> CIHRMS
    IdP -- "SAML/OIDC assertion" --> CIHRMS
    Biometric -- "Clock-in webhook" --> CIHRMS
    CIHRMS -- "Errors" --> Sentry
    CIHRMS -- "Payslips / Documents" --> S3
```

---

## 2. C4 — Container View

```mermaid
flowchart TB
    subgraph Client["Client tier"]
        Browser["Browser (Vue 3 + Inertia)<br/>+ PWA Service Worker"]
        USSD["USSD Phone<br/>(via Hubtel)"]
        Kiosk["Web Kiosk<br/>(shared device)"]
    end

    subgraph Edge["Edge"]
        TLS["Reverse Proxy<br/>Nginx / Caddy<br/>TLS · Trusted Proxies"]
    end

    subgraph App["Application tier (single deployable)"]
        WEB["php-fpm pool<br/>(Laravel HTTP)"]
        Horizon["Horizon Workers<br/>queues: audit · analytics ·<br/>notifications · integrations · payroll · default"]
        Sched["Scheduler<br/>schedule:work"]
    end

    subgraph Data["Data tier"]
        PG[(PostgreSQL 15<br/>OLTP + JSONB)]
        Redis[(Redis 7<br/>cache + queue + session)]
        Files[(S3-compatible<br/>storage)]
    end

    subgraph Observability
        Sentry[(Sentry)]
        Backups[(Daily backups<br/>spatie/laravel-backup)]
    end

    subgraph External["External systems"]
        NIA[(NIA)]
        GhIPSS[(GhIPSS)]
        MoMo[(MoMo)]
        Statutory[(SSNIT · GRA · NPRA · NHIA)]
        Hubtel[(Hubtel SMS/USSD)]
        Webhooks[(WhatsApp · Zoho · MSGraph ·<br/>Google · Slack · E-sign · Biometric)]
        IdP[(SAML / OIDC IdPs)]
    end

    Browser --> TLS
    USSD --> Hubtel
    Kiosk --> TLS
    Hubtel --> TLS

    TLS --> WEB
    WEB --> PG
    WEB --> Redis
    WEB --> Files

    WEB --> Horizon
    Horizon --> PG
    Horizon --> Redis
    Horizon --> Files

    Sched --> WEB

    WEB --> Sentry
    Horizon --> Sentry
    PG --> Backups
    Files --> Backups

    Horizon --> NIA
    Horizon --> GhIPSS
    Horizon --> MoMo
    Horizon --> Statutory
    Horizon --> Hubtel
    Horizon -.signed outbound.-> Webhooks

    Webhooks -.signed inbound.-> WEB
    IdP <--> WEB
```

---

## 3. C4 — Component View (Payroll Module)

```mermaid
flowchart LR
    subgraph Web["HTTP layer"]
        PRC["PayrollRunController"]
        DC["DisbursementController"]
        FR1["StorePayrollRunRequest"]
        FR2["ApprovePayrollRunRequest"]
    end

    subgraph Mw["Middleware"]
        Auth["auth"]
        Perm["permission:payroll.run /<br/>payroll.approve / ..."]
        TFA["2fa:fresh"]
        Audit["audit"]
    end

    subgraph App["Services & domain"]
        PRS["PayrollRunService"]
        Calc["PayrollCalculator<br/>(PAYE · SSNIT · Tier-2/3 · NHIA)"]
        DS["DisbursementService"]
        StatS["StatutoryReturnService"]
    end

    subgraph Models["Aggregates"]
        Run[(PayrollRun)]
        Line[(PayrollLine)]
        Alw[(Allowance / Deduction)]
        Tax[(TaxBracket · StatutoryRate)]
        Stmt[(StatutoryReturn)]
        Disb[(Disbursement)]
    end

    subgraph Events["Domain events"]
        EApp["PayrollApproved"]
        EPaid["PayrollMarkedPaid"]
        ERev["PayrollReversed"]
    end

    subgraph Listeners["Queued listeners"]
        L1["RecordAnalyticsEvent<br/>(analytics)"]
        L2["WriteAuditLog<br/>(audit, hash chain)"]
        L3["DispatchDisbursement<br/>(integrations)"]
    end

    subgraph External["External"]
        Bank[(GhIPSS / MoMo)]
        Statutory[(SSNIT · GRA · NPRA · NHIA)]
    end

    PRC --> FR1
    PRC --> FR2
    PRC -. authed via .-> Auth
    PRC -. authz via .-> Perm
    PRC -. fresh 2FA .-> TFA
    PRC -. logs via .-> Audit

    PRC --> PRS
    DC --> DS

    PRS --> Calc
    PRS --> Run
    Calc --> Line
    Calc --> Alw
    Calc --> Tax
    PRS --> Stmt
    PRS -- dispatches --> EApp
    PRS -- dispatches --> EPaid
    PRS -- dispatches --> ERev

    EApp --> L1
    EApp --> L2
    EApp --> L3

    L3 --> DS
    DS --> Disb
    DS --> Bank

    StatS --> Stmt
    StatS --> Statutory
```

---

## 4. Deployment Topology

```mermaid
flowchart TB
    Internet((Internet))

    subgraph Cloud["Hosting (NITA gov-cloud OR managed VPS)"]
        subgraph FE["Frontend zone"]
            LB["Reverse Proxy<br/>(Nginx + TLS)"]
        end

        subgraph APPZ["App zone"]
            FPM["php-fpm pool<br/>(N instances)"]
            HZ["Horizon workers<br/>(M instances)"]
            SCH["Scheduler"]
        end

        subgraph DATA["Data zone"]
            PG[(Postgres 15<br/>primary + read replica*)]
            RD[(Redis 7)]
            OBJ[(S3-compatible Object Storage)]
        end

        subgraph OBS["Ops"]
            ST[(Sentry agent)]
            BKP[(Backup runner)]
        end
    end

    subgraph Edge["Browser / Mobile"]
        UA["User browser / PWA"]
        USSDph["USSD phone"]
    end

    UA --> Internet --> LB
    USSDph --> Internet
    LB --> FPM
    FPM <--> PG
    FPM <--> RD
    FPM <--> OBJ
    HZ <--> PG
    HZ <--> RD
    HZ <--> OBJ
    SCH --> FPM
    FPM --> ST
    HZ --> ST
    PG --> BKP
    OBJ --> BKP
```

`*` Read replica is P7 (when read load justifies it).

---

## 5. Sequence — Authenticated Inertia Request (with Audit + RBAC)

```mermaid
sequenceDiagram
    autonumber
    participant U as User (Vue/Inertia)
    participant N as Nginx (TLS)
    participant L as Laravel HTTP
    participant M as Middleware Pipeline
    participant C as Controller
    participant S as Service
    participant DB as Postgres
    participant Q as Redis Queue
    participant W as Audit Worker (Horizon)

    U->>N: POST /payroll-runs/123/approve (CSRF + 2FA token)
    N->>L: forward
    L->>M: auth → permission:payroll.approve → 2fa:fresh → audit
    M-->>L: dispatch WriteAuditLog job (audit queue)
    L->>C: PayrollRunController@approve
    C->>S: PayrollRunService::approve(run, user)
    S->>DB: BEGIN; UPDATE payroll_runs SET state='approved'; ...
    S-->>S: dispatch event PayrollApproved
    S->>DB: COMMIT
    C-->>L: redirect ('payroll-runs.show')
    L-->>U: 303 + Inertia response (flash success)

    par async listeners
        Q->>W: WriteAuditLog
        W->>DB: SELECT prev_hash; INSERT audit_logs (hash=sha256(prev||row))
    and
        Q->>W: RecordAnalyticsEvent
        W->>DB: INSERT analytics_events
    and
        Q->>W: DispatchDisbursement
        W->>+External: POST /ghipss/bulk_credit
        External-->>-W: 200 OK
        W->>DB: INSERT disbursements (status=submitted)
    end
```

---

## 6. Sequence — Inbound Webhook (Biometric Clock-in)

```mermaid
sequenceDiagram
    autonumber
    participant Dev as Biometric Device
    participant N as Nginx (TLS)
    participant V as VerifyWebhookSignature
    participant Ctrl as BiometricWebhookController
    participant Svc as AttendanceService
    participant DB as Postgres
    participant Q as Queue

    Dev->>N: POST /webhooks/biometric<br/>X-Signature: hmac-sha256
    N->>V: forward
    V->>V: compute HMAC over body using device_secret<br/>compare to header (timing-safe)
    alt signature invalid
        V-->>Dev: 401 Unauthorized
    else valid
        V->>Ctrl: dispatch
        Ctrl->>Svc: recordClockEvent(device, employee_no, ts)
        Svc->>DB: INSERT attendance_records<br/>(idempotent on device_event_id)
        Svc->>Q: enqueue analytics event
        Ctrl-->>Dev: 200 OK
    end
```

---

## 7. Sequence — Anonymous Whistleblower Submission

```mermaid
sequenceDiagram
    autonumber
    participant R as Anonymous Reporter
    participant App as Laravel App
    participant DB as Postgres
    participant Q as Queue
    participant Inv as Investigator (later)

    R->>App: POST /whistleblower<br/>(category, narrative, evidence files)
    App->>App: rate-limit 6/min<br/>(no auth required)
    App->>DB: INSERT whistleblower_reports<br/>reference = 'WB-XXXX'<br/>track_pin_hash = bcrypt(pin)
    App->>DB: INSERT whistleblower_evidence (uuid-named files)
    App-->>R: redirect /whistleblower/confirmation<br/>reference shown ONCE
    App->>Q: NotifyInvestigators (notifications)

    Note over R,App: Reporter retains reference + PIN out-of-band.

    R->>App: GET /whistleblower/track + POST { reference, pin }
    App->>DB: SELECT reports WHERE reference AND pin matches
    App-->>R: redacted status + investigator messages

    Inv->>App: POST /admin/whistleblower/{report}/triage (2fa:fresh)
    App->>DB: UPDATE status; INSERT whistleblower_actions
    Inv->>App: POST /admin/whistleblower/{report}/messages
    App->>DB: INSERT whistleblower_messages (visible to reporter via track)
```

---

## 8. State Machines

### 8.1 PayrollRun

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> calculating: POST /calculate
    calculating --> calculated: engine finished
    calculated --> approved: POST /approve  (2fa:fresh)
    calculated --> draft: edit / fix
    approved --> dispatching: POST /disbursements/dispatch  (2fa:fresh)
    dispatching --> paid: POST /mark-paid
    approved --> paid: POST /mark-paid (no disbursement)
    approved --> reversed: POST /reverse  (2fa:fresh)
    paid --> reversed: POST /reverse  (2fa:fresh)
    reversed --> [*]
    paid --> [*]
```

### 8.2 LeaveRequest

```mermaid
stateDiagram-v2
    [*] --> submitted
    submitted --> approved: manager approves
    submitted --> rejected: manager rejects
    submitted --> cancelled: employee cancels
    approved --> taken: covers period
    approved --> cancelled: employee cancels (pre-start)
    rejected --> [*]
    cancelled --> [*]
    taken --> [*]
```

### 8.3 OffboardingCase

```mermaid
stateDiagram-v2
    [*] --> initiated
    initiated --> clearance_in_progress: any item touched
    clearance_in_progress --> clearance_complete: all items cleared
    clearance_complete --> settlement_calculated: POST settlement/calculate
    settlement_calculated --> settlement_approved: POST settlement/approve (2fa:fresh)
    settlement_approved --> completed: POST complete (2fa:fresh)
    initiated --> cancelled: POST cancel
    clearance_in_progress --> cancelled: POST cancel
    completed --> [*]
    cancelled --> [*]
```

### 8.4 PerformanceContract

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> sent: POST /send
    sent --> signed_by_employee: employee signs
    signed_by_employee --> signed_by_manager: manager counter-signs
    sent --> revoked: POST /revoke
    signed_by_manager --> evaluated: POST /evaluate (2fa:fresh)
    evaluated --> [*]
    revoked --> [*]
```

### 8.5 LoanAccount

```mermaid
stateDiagram-v2
    [*] --> applied
    applied --> approved: POST /decide (approve, 2fa:fresh)
    applied --> rejected: POST /decide (reject)
    approved --> disbursed: POST /disburse (2fa:fresh)
    disbursed --> active: amortisation starts
    active --> closed: balance == 0
    rejected --> [*]
    closed --> [*]
```

### 8.6 WhistleblowerReport

```mermaid
stateDiagram-v2
    [*] --> received
    received --> triaged: POST /triage (2fa:fresh)
    triaged --> assigned: POST /assign (2fa:fresh)
    assigned --> investigating: actions logged
    investigating --> substantiated: finding
    investigating --> unsubstantiated: finding
    investigating --> closed: no further action
    substantiated --> closed
    unsubstantiated --> closed
    closed --> [*]
```

---

## 9. Entity Relationship Diagram (high-level)

> Selected core entities; full schema is in [database/migrations/](../database/migrations/).

```mermaid
erDiagram
    USER ||--o{ USER_ROLE : has
    ROLE ||--o{ USER_ROLE : assigned
    ROLE ||--o{ ROLE_PERMISSION : grants
    PERMISSION ||--o{ ROLE_PERMISSION : granted
    USER ||--o| EMPLOYEE : "linked to"
    DEPARTMENT ||--o{ EMPLOYEE : employs
    DEPARTMENT ||--o| USER : "headed by"

    EMPLOYEE ||--o{ POSITION_ASSIGNMENT : holds
    POSITION ||--o{ POSITION_ASSIGNMENT : has
    GRADE ||--o{ GRADE_STEP : contains
    POSITION }o--|| GRADE : at
    POSITION_ASSIGNMENT }o--|| GRADE_STEP : on

    EMPLOYEE ||--o{ LEAVE_REQUEST : files
    EMPLOYEE ||--o{ LEAVE_BALANCE : owns
    EMPLOYEE ||--o{ TICKET : opens
    EMPLOYEE ||--o{ COMPLAINT : files
    EMPLOYEE ||--o{ EMPLOYEE_DOCUMENT : has
    EMPLOYEE ||--o{ EMPLOYEE_SKILL : has
    EMPLOYEE ||--o{ ATTENDANCE_RECORD : clocks
    EMPLOYEE ||--o{ SHIFT_ASSIGNMENT : on
    EMPLOYEE ||--o{ GOAL : pursues
    EMPLOYEE ||--o{ REVIEW : "subject of"
    EMPLOYEE ||--o{ LOAN_ACCOUNT : holds
    EMPLOYEE ||--o{ OFFBOARDING_CASE : "subject of"
    EMPLOYEE ||--o{ IDENTITY_VERIFICATION : verified
    EMPLOYEE ||--o{ BENEFIT_ENROLMENT : enrolled
    EMPLOYEE ||--o{ ASSET_ASSIGNMENT : assigned

    REVIEW_CYCLE ||--o{ REVIEW : has
    REVIEW_CYCLE ||--o{ GOAL : "scoped to"
    GOAL ||--o{ GOAL_CHECKIN : tracks
    REVIEW_CYCLE ||--o{ PERFORMANCE_CONTRACT : produces
    REVIEW_CYCLE ||--o{ CALIBRATION_SESSION : closes
    EMPLOYEE ||--o{ PERFORMANCE_IMPROVEMENT_PLAN : on

    PAYROLL_RUN ||--o{ PAYROLL_LINE : contains
    PAYROLL_LINE }o--|| EMPLOYEE : pays
    PAYROLL_LINE ||--o{ PAYROLL_ITEM : "split into"
    PAYROLL_RUN ||--o{ STATUTORY_RETURN : produces
    PAYROLL_RUN ||--o{ DISBURSEMENT : "dispatched as"
    EMPLOYEE ||--o{ ALLOWANCE : receives
    EMPLOYEE ||--o{ DEDUCTION : owes
    PAYMENT }o--|| EMPLOYEE : "paid to"
    PAYMENT ||--o{ PAYROLL_ITEM : composed_of

    LOAN_PRODUCT ||--o{ LOAN_ACCOUNT : "issued under"
    LOAN_ACCOUNT ||--o{ LOAN_REPAYMENT : repaid

    OFFBOARDING_CASE ||--o{ CLEARANCE_ITEM : requires
    OFFBOARDING_CASE ||--|| FINAL_SETTLEMENT : settles

    DOCUMENT ||--o{ DOCUMENT_VERSION : versioned
    DOCUMENT ||--o{ DOCUMENT_ROUTE : routed
    DOCUMENT ||--o{ DOCUMENT_ANNOTATION : annotated
    DOCUMENT ||--o{ DOCUMENT_EVENT : logged

    JOB_POSTING ||--o{ APPLICANT : receives
    APPLICANT }o--|| USER : "may become"

    POLICY ||--o{ POLICY_VERSION : versioned
    POLICY_VERSION ||--o{ POLICY_ACKNOWLEDGEMENT : signed

    WHISTLEBLOWER_REPORT ||--o{ WHISTLEBLOWER_EVIDENCE : has
    WHISTLEBLOWER_REPORT ||--o{ WHISTLEBLOWER_ACTION : logged
    WHISTLEBLOWER_REPORT ||--o{ WHISTLEBLOWER_MESSAGE : conversed
    WHISTLEBLOWER_REPORT ||--o{ WHISTLEBLOWER_SUBJECT : names

    AUDIT_LOG }o--|| USER : "by"
    AUDIT_LOG ||--|| AUDIT_LOG : "prev_hash chain"

    INTEGRATION ||--o{ INTEGRATION_TOKEN : has
    INTEGRATION ||--o{ INTEGRATION_EVENT : produced
    WEBHOOK_SUBSCRIPTION ||--o{ WEBHOOK_DELIVERY : dispatched

    USER ||--o{ SSO_LOGIN_ATTEMPT : tried
    SSO_IDENTITY_PROVIDER ||--o{ SSO_LOGIN_ATTEMPT : via
    USER ||--o{ USER_IDENTITY_LINK : links
```

---

## 10. RBAC Resolution Flow

```mermaid
flowchart TD
    Start([Request hits route with<br/>middleware permission:slug]) --> Q1{User authenticated?}
    Q1 -- No --> Deny[403/redirect login]
    Q1 -- Yes --> Cache{cache hit?<br/>perms.{uid}.{slug}}
    Cache -- Hit, allow --> Allow[continue request]
    Cache -- Hit, deny --> Deny

    Cache -- Miss --> L1{user.role enum<br/>has '*' wildcard?<br/>(super_admin)}
    L1 -- Yes --> StoreAllow[cache 60s allow] --> Allow
    L1 -- No --> L2{User::ROLE_PERMISSIONS<br/>contains slug for role?}
    L2 -- Yes --> StoreAllow
    L2 -- No --> L3{DB roles via user_roles ->
roles -> role_permissions
contains slug?}
    L3 -- Yes --> StoreAllow
    L3 -- No --> L4{User.permissions JSON<br/>contains slug?}
    L4 -- Yes --> StoreAllow
    L4 -- No --> StoreDeny[cache 60s deny] --> Deny
```

For department-scoped checks, `User::managesDepartment($id)` is called inside Policies; it merges `headedDepartments` with `user_roles.department_id` pivots.

---

## 11. Domain Event Fan-out

```mermaid
flowchart LR
    subgraph Services
        ES[EmployeeService.create]
        LS[LeaveService.submit / decide]
        TS[TicketService.create]
        PS[PaymentService.create / markPaid]
        RS[RecruitmentService.apply / sendOffer]
        WS[WhistleblowerService.submit]
        PRS[PayrollRunService.approve]
    end

    subgraph Events
        E1[EmployeeCreated]
        E2[LeaveRequested]
        E3[LeaveStatusUpdated]
        E4[TicketCreated]
        E5[PaymentCreated]
        E6[PaymentMarkedPaid]
        E7[ApplicantCreated]
        E8[OfferEnvelopeSent]
        E9[WhistleblowerReceived]
        E10[PayrollApproved]
    end

    subgraph Queues
        QA["queue:audit<br/>WriteAuditLog"]
        QAN["queue:analytics<br/>RecordAnalyticsEvent"]
        QN["queue:notifications<br/>NotifyManager / NotifyEmployee / NotifyInvestigators"]
        QI["queue:integrations<br/>SyncZohoContact / UploadPayslipToCloud / DispatchOfferToESign"]
        QP["queue:payroll<br/>(reserved heavy)"]
    end

    ES --> E1 --> QAN
    E1 --> QI
    LS --> E2 --> QN
    E2 --> QAN
    LS --> E3 --> QN
    E3 --> QAN
    TS --> E4 --> QN
    E4 --> QAN
    PS --> E5 --> QAN
    PS --> E6 --> QI
    E6 --> QAN
    RS --> E7 --> QAN
    E7 --> QI
    RS --> E8 --> QI
    WS --> E9 --> QN
    E9 --> QAN
    PRS --> E10 --> QA
    E10 --> QAN
    E10 --> QI
```

---

## 12. SSO + 2FA Login Sequence

```mermaid
sequenceDiagram
    autonumber
    participant U as User
    participant App as Laravel
    participant IdP as SAML/OIDC IdP

    U->>App: GET /auth/sso/{slug}
    App->>App: load SsoIdentityProvider
    App-->>U: 302 → IdP authorise URL (with state)
    U->>IdP: authenticate (MFA at IdP, optionally)
    IdP-->>U: redirect /auth/sso/{slug}/callback?code=...
    U->>App: GET/POST /auth/sso/{slug}/callback
    App->>IdP: exchange code (OIDC) / verify SAML assertion
    IdP-->>App: identity claims
    App->>App: lookup user_identity_links<br/>create/link user; write sso_login_attempts
    alt user has TOTP enabled
        App-->>U: 302 → /two-factor/challenge
        U->>App: POST /two-factor/challenge { code }
        App->>App: verify TOTP, set fresh-2fa marker
    end
    App-->>U: 302 → /dashboard (session established)
```

---

## 13. Module Dependency Map

```mermaid
flowchart TB
    subgraph Foundation
        IAM[Identity & Access<br/>Users · Roles · Perms · SSO · 2FA]
        Audit[Audit Log<br/>tamper-evident]
        Notif[Notifications + Messaging]
        Integ[Integrations + Webhooks]
        Docs[Documents / DMS]
    end

    subgraph People
        Dept[Departments]
        Emp[Employees + Skills]
        Estab[Positions / Grades / Steps]
        IDV[Identity Verification]
    end

    subgraph Lifecycle
        Recr[Recruitment]
        Off[Off-boarding + Settlement]
    end

    subgraph Pay
        Payroll[Payroll Runs + Calculator]
        Loans[Loans + Disbursements]
        Stat[Statutory + AG Report]
    end

    subgraph Workforce
        Time[Time & Attendance + Shifts]
        Perf[Performance + Calibration + PIPs]
        Learn[Learning + Certifications]
        Assets[Assets]
        Bene[Benefits]
    end

    subgraph Service
        Tick[Tickets]
        Comp[Complaints]
        WB[Whistleblower]
        Inc[Incident Reports]
        Anc[Announcements]
    end

    subgraph Compliance
        Gov[Governance Policies]
        Privacy[DPA Privacy Portal]
        Reports[Reports + BI]
    end

    IAM --> People
    IAM --> Lifecycle
    IAM --> Pay
    IAM --> Workforce
    IAM --> Service
    IAM --> Compliance

    Dept --> Emp
    Estab --> Emp
    Emp --> Lifecycle
    Emp --> Pay
    Emp --> Workforce
    Emp --> Service
    Emp --> Compliance

    Time --> Payroll
    Payroll --> Loans
    Payroll --> Stat
    Off --> Stat

    Audit --> Compliance
    Notif --> Service
    Notif --> Lifecycle
    Integ --> Notif
    Docs --> Compliance
    Docs --> Lifecycle
```

---

## 14. How to view these diagrams

- **GitHub / GitLab:** Render natively in the markdown viewer.
- **VS Code:** Install the *"Markdown Preview Mermaid Support"* extension; preview with `Ctrl+Shift+V`.
- **Locally:** Use [mermaid-cli](https://github.com/mermaid-js/mermaid-cli) — `mmdc -i SYSTEM_DESIGN_DIAGRAMS.md -o diagrams.pdf`.
- **Web:** Paste into <https://mermaid.live> to interact, export SVG/PNG.

---

## 15. Cross-References

- [PRD.md](PRD.md) — Product requirements
- [TRD.md](TRD.md) — Technical requirements
- [SYSTEM_ARCHITECTURE.md](SYSTEM_ARCHITECTURE.md) — Narrative architecture
- [PROJECT_STATE.md](PROJECT_STATE.md) — Current build status
