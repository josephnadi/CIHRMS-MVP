// Central glossary of the abbreviations and jargon that appear across the app.
// Each entry is a short, plain-English explanation aimed at non-technical staff.
// The <Term> component looks terms up here (case-insensitively by key) and shows
// a small hover/tap card. Add or reword entries here in one place.
//
// Keep definitions to one concise sentence: "<Full name> — <what it means>."

export const GLOSSARY = {
    // ── Finance / accounting ──
    AR:      { term: 'Accounts Receivable', definition: 'Money owed to the institute by members or customers that has not been paid yet.' },
    AP:      { term: 'Accounts Payable', definition: 'Money the institute owes to suppliers and vendors that it has not paid yet.' },
    GL:      { term: 'General Ledger', definition: 'The master record of every financial transaction, from which the accounts and statements are produced.' },
    JE:      { term: 'Journal Entry', definition: 'A single balanced record in the ledger — what was debited and what was credited.' },
    CoA:     { term: 'Chart of Accounts', definition: 'The organised list of all the accounts money can be recorded against.' },
    GHS:     { term: 'Ghana Cedi', definition: 'The national currency of Ghana — the currency this system keeps its books in.' },
    WHT:     { term: 'Withholding Tax', definition: 'Tax deducted from a payment at source and remitted to the tax authority on the payee’s behalf.' },
    YTD:     { term: 'Year to Date', definition: 'The running total from the start of the year up to today.' },
    VAT:     { term: 'Value Added Tax', definition: 'A tax charged on the sale of most goods and services.' },
    'DR':    { term: 'Debit', definition: 'The left side of an entry — increases assets/expenses, decreases income/liabilities.' },
    'CR':    { term: 'Credit', definition: 'The right side of an entry — increases income/liabilities, decreases assets/expenses.' },

    // ── Payroll / statutory (Ghana) ──
    PAYE:    { term: 'Pay As You Earn', definition: 'Income tax deducted from an employee’s salary each month and paid to the GRA.' },
    SSNIT:   { term: 'Social Security & National Insurance Trust', definition: 'Ghana’s national pension scheme — employer and employee both contribute.' },
    NHIA:    { term: 'National Health Insurance Authority', definition: 'The body that runs Ghana’s national health insurance, funded partly from SSNIT contributions.' },
    NHIL:    { term: 'National Health Insurance Levy', definition: 'A levy that helps fund Ghana’s national health insurance scheme.' },
    SSF:     { term: 'Social Security Fund', definition: 'The employee’s 5.5% pension contribution deducted before tax.' },
    'Tier-1':{ term: 'Tier 1 (SSNIT)', definition: 'The mandatory basic national pension managed by SSNIT.' },
    'Tier-2':{ term: 'Tier 2', definition: 'A mandatory occupational pension (5% of basic) managed by a private trustee.' },
    'Tier-3':{ term: 'Tier 3', definition: 'A voluntary pension/provident contribution that attracts tax relief up to a cap.' },
    BIK:     { term: 'Benefit in Kind', definition: 'A non-cash benefit (e.g. a fuel allowance) that is taxed as part of income.' },
    GRA:     { term: 'Ghana Revenue Authority', definition: 'Ghana’s national tax collection body.' },
    NITA:    { term: 'National Information Technology Agency', definition: 'The government agency IT-related statutory levies are paid to.' },
    GhIPSS:  { term: 'Ghana Interbank Payment & Settlement Systems', definition: 'The network that clears and settles electronic bank payments in Ghana.' },

    // ── HR / people ──
    HR:      { term: 'Human Resources', definition: 'The function that manages staff — hiring, records, leave, performance and pay.' },
    PIP:     { term: 'Performance Improvement Plan', definition: 'A structured plan to help an underperforming employee reach the expected standard.' },
    KPI:     { term: 'Key Performance Indicator', definition: 'A measurable value that shows how well a goal is being met.' },
    SLA:     { term: 'Service Level Agreement', definition: 'A promised standard — e.g. how quickly a request should be handled.' },
    CV:      { term: 'Curriculum Vitae', definition: 'A document summarising a person’s work history and qualifications.' },
    OT:      { term: 'Overtime', definition: 'Hours worked beyond normal working hours, usually paid at a premium.' },

    // ── Compliance / data ──
    DPA:     { term: 'Data Protection Act', definition: 'Ghana’s law governing how personal data must be collected, stored and used.' },
    DPO:     { term: 'Data Protection Officer', definition: 'The person responsible for making sure personal data is handled lawfully.' },
    DPIA:    { term: 'Data Protection Impact Assessment', definition: 'A check of the privacy risks before starting something that uses personal data.' },
    '2FA':   { term: 'Two-Factor Authentication', definition: 'A second security step (e.g. a code) on top of a password when signing in.' },
    RBAC:    { term: 'Role-Based Access Control', definition: 'Deciding what each person can see or do based on their assigned role.' },

    // ── Membership / learning (CIHRM) ──
    CIHRM:   { term: 'Chartered Institute of Human Resource Management', definition: 'The professional institute this system serves (Ghana).' },
    CPD:     { term: 'Continuing Professional Development', definition: 'Ongoing learning members must complete to keep their professional standing.' },
    PCP:     { term: 'Professional Certification Programme', definition: 'CIHRM’s certification pathway for students progressing to membership.' },
    LMS:     { term: 'Learning Management System', definition: 'The part of the platform that hosts courses, lessons and assessments.' },
};

/** Look up a glossary entry by code, case-insensitively. Returns null if unknown. */
export function lookupTerm(code) {
    if (!code) return null;
    if (GLOSSARY[code]) return GLOSSARY[code];
    const hit = Object.keys(GLOSSARY).find(k => k.toLowerCase() === String(code).toLowerCase());
    return hit ? GLOSSARY[hit] : null;
}
