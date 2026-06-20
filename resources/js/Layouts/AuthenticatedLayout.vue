<script setup>
import { computed, ref, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import Dropdown            from '@/Components/Dropdown.vue';
import DropdownLink        from '@/Components/DropdownLink.vue';
import NotificationBell    from '@/Components/NotificationBell.vue';
import SoundToggle         from '@/Components/SoundToggle.vue';
import AnnouncementTicker  from '@/Components/AnnouncementTicker.vue';
import Toast               from '@/Components/Toast.vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { useDark } from '@/composables/useDark';

const showingNavigationDropdown = ref(false);
const mounted = ref(false);
const page = usePage();

// ── Desktop sidebar collapse ─────────────────────────────────────────────────
// State persisted to localStorage so the choice survives Inertia navigation
// and page reloads. Defaults to open.
const SIDEBAR_OPEN_KEY = 'cihrms.sidebar.open';
const readSidebarOpen = () => {
    if (typeof window === 'undefined') return true;
    try {
        const raw = window.localStorage.getItem(SIDEBAR_OPEN_KEY);
        return raw === null ? true : raw === '1';
    } catch (e) { return true; }
};
const sidebarOpen = ref(readSidebarOpen());
const toggleSidebar = () => {
    sidebarOpen.value = !sidebarOpen.value;
    try { window.localStorage.setItem(SIDEBAR_OPEN_KEY, sidebarOpen.value ? '1' : '0'); } catch (e) {}
};
const permissions = computed(() => page.props.auth?.permissions ?? []);
const user = computed(() => page.props.auth?.user);

const { isDark, init } = useDark();

// ── Sidebar scroll preservation ──────────────────────────────────────────────
// The AuthenticatedLayout is rendered inline by each page, so navigation
// destroys and recreates this component, losing the sidebar's scroll
// position. Save scrollTop to sessionStorage on each Inertia 'before' event
// and on unmount, then restore it after the nav element mounts.
const saveSidebarScroll = () => {
    if (typeof window === 'undefined') return;
    const top = sidebarNavRef.value?.scrollTop;
    if (typeof top !== 'number') return;
    try { window.sessionStorage.setItem(SIDEBAR_SCROLL_KEY, String(top)); } catch (e) {}
};

const restoreSidebarScroll = () => {
    if (typeof window === 'undefined') return;
    try {
        const raw = window.sessionStorage.getItem(SIDEBAR_SCROLL_KEY);
        const top = raw ? parseInt(raw, 10) : 0;
        if (sidebarNavRef.value && Number.isFinite(top)) {
            sidebarNavRef.value.scrollTop = top;
        }
    } catch (e) {}
};

let removeInertiaBefore = null;

const onSidebarShortcut = (e) => {
    // Ctrl/Cmd + B toggles the desktop sidebar. Ignored when the user is
    // typing into an input/textarea/contenteditable so it doesn't hijack
    // common text-editing shortcuts.
    if (!(e.ctrlKey || e.metaKey) || e.key?.toLowerCase() !== 'b') return;
    const t = e.target;
    const tag = (t?.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || t?.isContentEditable) return;
    e.preventDefault();
    toggleSidebar();
};

onMounted(() => {
    init();
    setTimeout(() => { mounted.value = true; }, 50);
    nextTick(restoreSidebarScroll);
    removeInertiaBefore = router.on('before', saveSidebarScroll);
    window.addEventListener('keydown', onSidebarShortcut);
});

onBeforeUnmount(() => {
    saveSidebarScroll();
    if (typeof removeInertiaBefore === 'function') removeInertiaBefore();
    window.removeEventListener('keydown', onSidebarShortcut);
});

const can = (permission) =>
    permissions.value.includes('*') || permissions.value.includes(permission);

const PRIVILEGED_NAV_ROLES = ['super_admin', 'ceo', 'hr_admin'];
const FULL_SYSTEM_NAV_ROLES = ['super_admin', 'ceo'];

const navSections = computed(() => {
    if (PRIVILEGED_NAV_ROLES.includes(user.value?.role)) {
        const sections = [
            {
                title: '',
                items: [
                    { label: 'Dashboard',    route: 'dashboard',            module: 'overview',    icon: 'grid_view',      visible: true },
                    { label: 'Employees',    route: 'modules.employees',    module: 'employees',   icon: 'badge',          visible: true },
                    {
                        label: 'Attendance', icon: 'person_check', expandable: true,
                        visible: can('attendance.view') || can('attendance.correct') || can('attendance.clock_self'),
                        children: [
                            { label: 'Overview',      route: 'attendance.index',             module: 'attendance',             icon: 'dashboard',  visible: can('attendance.view') },
                            { label: 'My Attendance', route: 'attendance.me',                module: 'attendance-me',          icon: 'person',     visible: true },
                            { label: 'Shifts',        route: 'attendance.shifts.index',      module: 'attendance-shifts',      icon: 'schedule',   visible: can('attendance.shift_manage') },
                            { label: 'Corrections',   route: 'attendance.corrections.index', module: 'attendance-corrections', icon: 'fact_check', visible: can('attendance.approve') || can('attendance.correct') },
                        ],
                    },
                    { label: 'Leave',        route: 'modules.leave',        module: 'leave',       icon: 'calendar_month', visible: true },
                    { label: 'Payroll',      route: 'modules.payroll',      module: 'payroll',     icon: 'payments',       visible: true },
                    { label: 'Loans',        route: 'loans.index',          module: 'loans',       icon: 'request_quote',  visible: can('loans.view') || can('loans.apply') },
                    { label: 'Benefits',     route: 'benefits.index',       module: 'benefits',    icon: 'diversity_3',    visible: can('benefits.view') || can('benefits.view_all') },
                    {
                        label: 'Finance', icon: 'account_balance', expandable: true,
                        visible: can('finance.hub') || can('accounts.view') || can('bank_accounts.view') || can('vendors.view') || can('ap_invoices.view') || can('journal.view') || can('customers.view') || can('ar_invoices.view') || can('statements.view') || can('gateway.view') || can('reconciliation.view'),
                        children: [
                            { label: 'Hub',            route: 'finance.hub',                     module: 'finance',                  icon: 'account_balance',         visible: can('finance.hub') },
                            { label: 'Chart of Accounts', route: 'finance.accounts.index',       module: 'finance-accounts',         icon: 'account_tree',            visible: can('accounts.view') },
                            { label: 'Bank Accounts',  route: 'finance.bank-accounts.index',     module: 'finance-bank-accounts',    icon: 'account_balance_wallet',  visible: can('bank_accounts.view') },
                            { label: 'Vendors',        route: 'finance.vendors.index',           module: 'finance-vendors',          icon: 'store',                   visible: can('vendors.view') },
                            { label: 'AP Invoices',    route: 'finance.ap-invoices.index',       module: 'finance-ap-invoices',      icon: 'receipt_long',            visible: can('ap_invoices.view') },
                            { label: 'AP Payments',    route: 'finance.ap-payments.index',       module: 'finance-ap-payments',      icon: 'payments',                visible: can('ap_invoices.view') },
                            { label: 'Customers',      route: 'finance.customers.index',         module: 'finance-customers',        icon: 'storefront',              visible: can('customers.view') },
                            { label: 'AR Invoices',    route: 'finance.ar-invoices.index',       module: 'finance-ar-invoices',      icon: 'request_quote',           visible: can('ar_invoices.view') },
                            { label: 'AR Receipts',    route: 'finance.ar-receipts.index',       module: 'finance-ar-receipts',      icon: 'savings',                 visible: can('ar_invoices.view') },
                            { label: 'Statements',     route: 'finance.statements.index',        module: 'finance-statements',       icon: 'description',             visible: can('statements.view') },
                            { label: 'Payment Links',  route: 'finance.payment-intents.index',   module: 'finance-payment-intents',  icon: 'link',                    visible: can('gateway.view') },
                            { label: 'Reconciliation', route: 'finance.reconciliation.index',    module: 'finance-reconciliation',   icon: 'compare_arrows',          visible: can('reconciliation.view') },
                            { label: 'Journal',        route: 'finance.journal.index',           module: 'finance-journal',          icon: 'list_alt',                visible: can('journal.view') },
                        ],
                    },
                    {
                        label: 'Billing', icon: 'card_membership', expandable: true,
                        visible: can('members.view') || can('members.manage') || can('fee_catalog.view') || can('fee_catalog.manage') || can('billing.run'),
                        children: [
                            { label: 'Members',      route: 'billing.members.index',     module: 'billing-members',     icon: 'badge',         visible: can('members.view') || can('members.manage') },
                            { label: 'Fee Catalog',  route: 'billing.fee-catalog.index', module: 'billing-fee-catalog', icon: 'receipt',       visible: can('fee_catalog.view') || can('fee_catalog.manage') },
                            { label: 'Billing Runs', route: 'billing.runs.index',        module: 'billing-runs',        icon: 'playlist_play', visible: can('billing.run') },
                        ],
                    },
                    { label: 'Onboarding',   route: 'onboarding.index',     module: 'onboarding',  icon: 'login',          visible: can('onboarding.view') || can('onboarding.initiate') },
                    { label: 'Off-boarding', route: 'offboarding.index',    module: 'offboarding', icon: 'logout',         visible: can('offboarding.view') || can('offboarding.initiate') },
                    {
                        label: 'Performance', icon: 'monitoring', expandable: true, visible: can('performance.view'),
                        children: [
                            { label: 'Analytics',  route: 'modules.performance',          module: 'performance',             icon: 'insights',     visible: true },
                            { label: 'Goals',      route: 'performance.goals.index',      module: 'performance-goals',       icon: 'flag',         visible: true },
                            { label: 'Reviews',    route: 'performance.reviews.index',    module: 'performance-reviews',     icon: 'rate_review',  visible: true },
                            { label: 'Contracts',  route: 'performance.contracts.index',  module: 'performance-contracts',   icon: 'description',  visible: true },
                            { label: 'Calibration',route: 'performance.calibration.index',module: 'performance-calibration', icon: 'tune',         visible: can('performance.calibrate') },
                            { label: 'PIPs',       route: 'performance.pips.index',       module: 'performance-pips',        icon: 'support',      visible: can('performance.pip_manage') },
                            { label: '9-Box',      route: 'performance.nine-box',         module: 'performance-9box',        icon: 'grid_view',    visible: can('performance.manage') },
                        ],
                    },
                ]
            },
            {
                title: 'Organization',
                items: [
                    { label: 'Recruitment',  route: 'modules.recruitment',  module: 'recruitment', icon: 'person_add',       visible: true },
                    { label: 'Chat',         route: 'chat.index',           module: 'chat',        icon: 'forum',            visible: true },
                    { label: 'Service Desk', route: 'modules.tickets',      module: 'tickets',     icon: 'support_agent',    visible: true },
                    {
                        label: 'Learning', icon: 'school', expandable: true, visible: can('learning.view'),
                        children: [
                            { label: 'Catalogue',     route: 'learning.catalog',       module: 'learning',         icon: 'menu_book',  visible: true },
                            { label: 'My Learning',   route: 'learning.my',            module: 'learning-my',      icon: 'play_lesson',visible: true },
                            { label: 'Skills Matrix', route: 'learning.skills-matrix', module: 'learning-skills',  icon: 'grid_on',    visible: can('learning.manage') },
                            { label: 'Compliance',    route: 'learning.compliance.index', module: 'learning-compliance', icon: 'gavel',  visible: can('learning.compliance.manage') },
                        ],
                    },
                    {
                        label: 'Governance', icon: 'account_balance', expandable: true, visible: true,
                        children: [
                            { label: 'Overview',         route: 'modules.governance',          module: 'governance',          icon: 'dashboard',  visible: true },
                            { label: 'Manage',           route: 'governance.manage',           module: 'governance-manage',   icon: 'tune',       visible: can('governance.manage') },
                            { label: 'Certifications',   route: 'governance.certifications.index', module: 'governance-certs',  icon: 'verified',   visible: true },
                            { label: 'Incident Reports', route: 'incidents.index',             module: 'incidents',           icon: 'report',     visible: true },
                        ],
                    },
                    { label: 'Assets',       route: 'modules.assets',       module: 'assets',      icon: 'inventory_2',      visible: true },
                    { label: 'Documents',    route: 'documents.index',      module: 'documents',   icon: 'description',      visible: can('documents.view') },
                    {
                        label: 'Reports', icon: 'assessment', expandable: true, visible: can('reports.view'),
                        children: [
                            { label: 'Overview',        route: 'modules.reports',  module: 'reports',           icon: 'dashboard',     visible: true },
                            { label: 'Auditor-General', route: 'ag-reports.index', module: 'ag-reports',        icon: 'gavel',         visible: can('statutory.export') },
                        ],
                    },
                ]
            }
        ];

        // Department portals — each child shows only when the user holds the matching `portal.*` permission.
        // The whole Departments group hides if the user can see no portal at all.
        // Each portal renders its own dedicated Departments/Show.vue page (slug-driven).
        const portalChildren = [
            { label: 'IT & Technology', route: 'departments.portal', routeParams: { slug: 'it' },             module: 'dept-it',             icon: 'computer',               visible: can('portal.it') },
            { label: 'Human Resources', route: 'departments.portal', routeParams: { slug: 'hr' },             module: 'dept-hr',             icon: 'people',                 visible: can('portal.hr') },
            { label: 'Marketing',       route: 'departments.portal', routeParams: { slug: 'marketing' },      module: 'dept-marketing',      icon: 'campaign',               visible: can('portal.marketing') },
            { label: 'Finance',         route: 'departments.portal', routeParams: { slug: 'finance' },        module: 'dept-finance',        icon: 'account_balance_wallet', visible: can('portal.finance') },
            { label: 'Membership',      route: 'departments.portal', routeParams: { slug: 'membership' },     module: 'dept-membership',     icon: 'card_membership',        visible: can('portal.membership') },
            { label: 'PCP',             route: 'departments.portal', routeParams: { slug: 'pcp' },            module: 'dept-pcp',            icon: 'verified',               visible: can('portal.pcp') },
            { label: 'CPD',             route: 'departments.portal', routeParams: { slug: 'cpd' },            module: 'dept-cpd',            icon: 'school',                 visible: can('portal.cpd') },
            { label: 'Administration',  route: 'departments.portal', routeParams: { slug: 'administration' }, module: 'dept-administration', icon: 'admin_panel_settings',   visible: can('portal.administration') },
        ];
        if (portalChildren.some(c => c.visible)) {
            sections.push({
                title: 'Departments',
                items: [
                    { label: 'Departments', icon: 'corporate_fare', expandable: true, visible: true, children: portalChildren },
                ],
            });
        }

        if (FULL_SYSTEM_NAV_ROLES.includes(user.value?.role)) {
            sections.push({
                title: 'System',
                items: [
                    { label: 'User Management', route: 'admin.users.index',        module: 'admin-users',   icon: 'manage_accounts', visible: can('users.manage') },
                    { label: 'Notice Board',  route: 'announcements.index',       module: 'announcements', icon: 'campaign',  visible: true },
                    { label: 'Integrations',  route: 'admin.integrations.index',  module: 'integrations',  icon: 'extension', visible: true },
                    { label: 'Whistleblower', route: 'whistleblower.admin.index', module: 'whistleblower', icon: 'flag',      visible: can('whistleblower.investigate') || can('whistleblower.view_all') },
                    { label: 'DPA Requests',  route: 'privacy.admin.index',       module: 'privacy-admin', icon: 'policy',    visible: can('privacy.fulfill') },
                    {
                        label: 'Messaging', icon: 'sms', expandable: true,
                        visible: can('messaging.view') || can('broadcasts.view'),
                        children: [
                            { label: 'SMS Log',     route: 'messaging.index',                  module: 'messaging',            icon: 'sms',           visible: can('messaging.view') },
                            { label: 'Broadcasts',  route: 'messaging.broadcasts.index',       module: 'messaging-broadcasts', icon: 'campaign',      visible: can('broadcasts.view') },
                            { label: 'Templates',   route: 'messaging.templates.index',        module: 'messaging-templates',  icon: 'description',   visible: can('broadcasts.view') },
                        ],
                    },
                    { label: 'SSO Providers', route: 'sso-admin.index',           module: 'sso',           icon: 'key',       visible: can('sso.manage') },
                    { label: 'API Tokens',    route: 'api-tokens.index',          module: 'api-tokens',    icon: 'vpn_key',   visible: can('api.token_manage') },
                    { label: 'Webhooks',      route: 'webhooks.index',            module: 'webhooks',      icon: 'webhook',   visible: can('api.webhooks_manage') },
                    { label: 'API Docs',      route: 'api.docs',                  module: 'api-docs',      icon: 'menu_book', visible: true },
                    { label: 'Settings',      route: 'profile.edit',              module: 'settings',      icon: 'settings',  visible: true },
                    { label: 'Audit Logs',    route: 'modules.audit-logs',        module: 'audit-logs',    icon: 'history',   visible: true },
                ]
            });
        } else if (can('announcements.manage') || can('integrations.manage') || can('whistleblower.investigate') || can('whistleblower.view_all') || can('privacy.fulfill') || can('api.token_manage') || can('api.webhooks_manage') || can('users.manage')) {
            sections.push({
                title: 'System',
                items: [
                    { label: 'User Management', route: 'admin.users.index',        module: 'admin-users',   icon: 'manage_accounts', visible: can('users.manage') },
                    { label: 'Notice Board',  route: 'announcements.index',       module: 'announcements', icon: 'campaign',  visible: can('announcements.manage') },
                    { label: 'Integrations',  route: 'admin.integrations.index',  module: 'integrations',  icon: 'extension', visible: can('integrations.manage') },
                    { label: 'Whistleblower', route: 'whistleblower.admin.index', module: 'whistleblower', icon: 'flag',      visible: can('whistleblower.investigate') || can('whistleblower.view_all') },
                    { label: 'DPA Requests',  route: 'privacy.admin.index',       module: 'privacy-admin', icon: 'policy',    visible: can('privacy.fulfill') },
                    {
                        label: 'Messaging', icon: 'sms', expandable: true,
                        visible: can('messaging.view') || can('broadcasts.view'),
                        children: [
                            { label: 'SMS Log',     route: 'messaging.index',                  module: 'messaging',            icon: 'sms',           visible: can('messaging.view') },
                            { label: 'Broadcasts',  route: 'messaging.broadcasts.index',       module: 'messaging-broadcasts', icon: 'campaign',      visible: can('broadcasts.view') },
                            { label: 'Templates',   route: 'messaging.templates.index',        module: 'messaging-templates',  icon: 'description',   visible: can('broadcasts.view') },
                        ],
                    },
                    { label: 'SSO Providers', route: 'sso-admin.index',           module: 'sso',           icon: 'key',       visible: can('sso.manage') },
                    { label: 'API Tokens',    route: 'api-tokens.index',          module: 'api-tokens',    icon: 'vpn_key',   visible: can('api.token_manage') },
                    { label: 'Webhooks',      route: 'webhooks.index',            module: 'webhooks',      icon: 'webhook',   visible: can('api.webhooks_manage') },
                    { label: 'API Docs',      route: 'api.docs',                  module: 'api-docs',      icon: 'menu_book', visible: true },
                    { label: 'Settings',      route: 'profile.edit',              module: 'settings',      icon: 'settings',  visible: true },
                ]
            });
        } else {
            sections.push({
                title: 'System',
                items: [
                    { label: 'Settings', route: 'profile.edit', module: 'settings', icon: 'settings', visible: true },
                ]
            });
        }

        return sections;
    }

    const sections = [
        {
            title: '',
            items: [
                { label: 'Dashboard',        route: 'dashboard',        module: 'overview',   icon: 'grid_view',      visible: true },
                { label: 'Tasks',            route: 'modules.tickets',  module: 'tickets',    icon: 'task_alt',       visible: true },
                { label: 'Documents',        route: 'documents.index',  module: 'documents',  icon: 'description',    visible: can('documents.view') },
                { label: 'Leave & Time-Off', route: 'modules.leave',    module: 'leave',      icon: 'calendar_today', visible: true },
                { label: 'Benefits',         route: 'benefits.index',   module: 'benefits',   icon: 'diversity_3',    visible: can('benefits.view') || can('benefits.view_all') },
                { label: 'Learning & Dev',   route: 'learning.catalog', module: 'learning',   icon: 'school',         visible: true },
            ],
        },
    ];

    if (can('finance.hub') || can('accounts.view') || can('bank_accounts.view') ||
        can('vendors.view') || can('ap_invoices.view') || can('journal.view') ||
        can('customers.view') || can('ar_invoices.view') || can('statements.view') ||
        can('gateway.view') || can('reconciliation.view')) {
        sections.push({
            title: 'Finance',
            items: [
                { label: 'Finance Hub',        route: 'finance.hub',                module: 'finance',                  icon: 'account_balance',         visible: can('finance.hub') },
                { label: 'Chart of Accounts',  route: 'finance.accounts.index',     module: 'finance-accounts',         icon: 'account_tree',            visible: can('accounts.view') },
                { label: 'Bank Accounts',      route: 'finance.bank-accounts.index',module: 'finance-bank-accounts',    icon: 'account_balance_wallet',  visible: can('bank_accounts.view') },
                { label: 'Vendors',        route: 'finance.vendors.index',     module: 'finance-vendors',     icon: 'store',          visible: can('vendors.view') },
                { label: 'AP Invoices',    route: 'finance.ap-invoices.index', module: 'finance-ap-invoices', icon: 'receipt_long',   visible: can('ap_invoices.view') },
                { label: 'AP Payments',    route: 'finance.ap-payments.index', module: 'finance-ap-payments', icon: 'payments',       visible: can('ap_invoices.view') },
                { label: 'Customers',      route: 'finance.customers.index',   module: 'finance-customers',   icon: 'storefront',     visible: can('customers.view') },
                { label: 'AR Invoices',    route: 'finance.ar-invoices.index', module: 'finance-ar-invoices', icon: 'request_quote',  visible: can('ar_invoices.view') },
                { label: 'AR Receipts',    route: 'finance.ar-receipts.index', module: 'finance-ar-receipts', icon: 'savings',        visible: can('ar_invoices.view') },
                { label: 'Statements',     route: 'finance.statements.index',  module: 'finance-statements',  icon: 'description',    visible: can('statements.view') },
                { label: 'Payment Links',  route: 'finance.payment-intents.index', module: 'finance-payment-intents', icon: 'link', visible: can('gateway.view') },
                { label: 'Reconciliation', route: 'finance.reconciliation.index', module: 'finance-reconciliation', icon: 'compare_arrows', visible: can('reconciliation.view') },
                { label: 'Journal',        route: 'finance.journal.index',     module: 'finance-journal',     icon: 'list_alt',       visible: can('journal.view') },
            ],
        });
    }

    if (can('members.view') || can('members.manage') || can('fee_catalog.view') || can('fee_catalog.manage') || can('billing.run')) {
        sections.push({
            title: 'Billing',
            items: [
                { label: 'Members',      route: 'billing.members.index',     module: 'billing-members',     icon: 'badge',         visible: can('members.view') || can('members.manage') },
                { label: 'Fee Catalog',  route: 'billing.fee-catalog.index', module: 'billing-fee-catalog', icon: 'receipt',       visible: can('fee_catalog.view') || can('fee_catalog.manage') },
                { label: 'Billing Runs', route: 'billing.runs.index',        module: 'billing-runs',        icon: 'playlist_play', visible: can('billing.run') },
            ],
        });
    }

    sections.push({
        title: 'Support',
        items: [
            { label: 'My Profile', route: 'profile.edit', module: 'profile',  icon: 'person',   visible: true },
            { label: 'Settings',   route: 'profile.edit', module: 'settings', icon: 'settings', visible: true },
        ],
    });

    return sections;
});

const isItemActive = (item) => {
    if (!item) return false;
    const currentModule = page.props.activeModule;
    const hasModuleSignal = item.module && currentModule !== undefined && currentModule !== null;

    // Items without a real route (or pointed at `dashboard`, which every
    // sub-module shares) can only disambiguate via the module prop.
    if (!item.route || item.route === 'dashboard') {
        return hasModuleSignal && item.module === currentModule;
    }

    // Items bound to a route with explicit params (e.g. department portal
    // slugs) need every declared param to match the current URL.
    if (item.routeParams) {
        try {
            if (route().current() !== item.route) return false;
            const params = route().params ?? {};
            return Object.entries(item.routeParams).every(([k, v]) => String(params[k]) === String(v));
        } catch (e) { return false; }
    }

    // Exact route-name match. When both `item.module` and `page.activeModule`
    // are set, also require module agreement so two items sharing the same
    // route (e.g. My Profile + Settings → profile.edit) don't both light up.
    if (route().current(item.route)) {
        return !hasModuleSignal || item.module === currentModule;
    }

    // Wildcard match on the route namespace so a CRUD detail page lights its
    // index sibling — `billing.members.index` covers `.show` / `.edit` / etc.
    // Restricted to CRUD-shaped names so unrelated siblings like
    // `modules.recruitment` don't broaden to `modules.*`.
    const crud = item.route.match(/^(.*)\.(index|show|create|edit|store|update|destroy)$/);
    if (crud) {
        try {
            if (route().current(`${crud[1]}.*`)) {
                return !hasModuleSignal || item.module === currentModule;
            }
        } catch (e) { /* older Ziggy w/o wildcard — fall through to module match */ }
    }

    // Last-resort module fallback. Catches controllers that set
    // `activeModule` but route to a name absent from the nav config.
    return hasModuleSignal && item.module === currentModule;
};

// True if the expandable group `item` contains any active child.
const isGroupActive = (item) => {
    if (!item?.children) return false;
    return item.children.some(c => c.visible && isItemActive(c));
};

// Resolve a sidebar nav entry to a real URL.
// Honors `item.routeParams` when set; falls back to the legacy `dashboard?module=...`
// pattern for entries that don't have a dedicated route name.
const resolveHref = (item) => {
    if (!item) return '#';
    if (item.route === 'dashboard') {
        return route('dashboard', { module: item.module });
    }
    return item.routeParams ? route(item.route, item.routeParams) : route(item.route);
};

// ── Expandable sidebar groups ─────────────────────────────────────────────────
// Default-expanded groups (initial state only — auto-expand on active child
// will keep things open when the user navigates into a child route).
// Start collapsed; user-toggled state persists across navigations via
// sessionStorage. The watcher below still auto-expands a group when one of
// its children is the active route, so a deep-link into Performance/Goals
// still opens the Performance group on first paint.
const SIDEBAR_EXPANDED_KEY = 'cihrms.sidebar.expandedGroups';
const SIDEBAR_SCROLL_KEY   = 'cihrms.sidebar.scrollTop';
const sidebarNavRef = ref(null);

const loadExpandedGroups = () => {
    if (typeof window === 'undefined') return new Set();
    try {
        const raw = window.sessionStorage.getItem(SIDEBAR_EXPANDED_KEY);
        if (!raw) return new Set();
        const arr = JSON.parse(raw);
        return Array.isArray(arr) ? new Set(arr) : new Set();
    } catch (e) { return new Set(); }
};

const expandedGroups = ref(loadExpandedGroups());

const persistExpandedGroups = () => {
    if (typeof window === 'undefined') return;
    try {
        window.sessionStorage.setItem(SIDEBAR_EXPANDED_KEY, JSON.stringify([...expandedGroups.value]));
    } catch (e) { /* sessionStorage unavailable (private mode, quota) */ }
};

const isGroupExpanded = (label) => expandedGroups.value.has(label);

const toggleGroup = (label) => {
    const next = new Set(expandedGroups.value);
    if (next.has(label)) next.delete(label); else next.add(label);
    expandedGroups.value = next;
    persistExpandedGroups();
};

// Auto-expand any group whose child is currently active. Watches the page's
// active-module signal so navigating from Dashboard â†’ Performance/Goals opens
// the Performance group automatically (and keeps it open) without disturbing
// any manual expand/collapse the user has done on unrelated groups.
watch(
    () => page.props.activeModule,
    () => {
        const before = expandedGroups.value;
        const next = new Set(before);
        navSections.value.forEach(section => {
            section.items.forEach(item => {
                if (item.expandable && isGroupActive(item)) {
                    next.add(item.label);
                }
            });
        });
        if (next.size !== before.size) {
            expandedGroups.value = next;
            persistExpandedGroups();
        }
    },
    { immediate: true },
);

// Include expandable children in mobile nav flat list
const allNavItems = computed(() =>
    navSections.value.flatMap(section =>
        section.items.flatMap(item => item.expandable ? (item.children ?? []) : [item])
    )
);

const roleLabel = computed(() => {
    const map = {
        super_admin: 'Super Admin',
        hr_admin: 'HR Admin',
        manager: 'Manager',
        employee: 'Employee',
        finance_officer: 'Finance',
        it_support: 'IT Support',
        auditor: 'Auditor',
    };
    return map[user.value?.role] ?? 'Staff';
});

// ── Theme-aware sidebar computed styles ──
const sidebarStyle = computed(() => isDark.value
    ? { background: '#0d1452', borderColor: 'rgba(255,255,255,0.06)' }
    : { background: '#ffffff', borderColor: 'rgba(198,198,205,0.5)' }
);

const logoBorderStyle = computed(() => isDark.value
    ? { borderColor: 'rgba(255,255,255,0.06)' }
    : { borderColor: 'rgba(198,198,205,0.4)' }
);

const sectionLabelStyle = computed(() => isDark.value
    ? { color: 'rgba(255,255,255,0.22)' }
    : { color: 'rgba(69,70,77,0.5)' }
);

const navItemClass = (item) => {
    if (isItemActive(item)) {
        return isDark.value ? 'text-white' : 'text-secondary';
    }
    return isDark.value
        ? 'text-white/40 hover:text-white/80 hover:bg-white/[0.05]'
        : 'text-on-surface-variant/70 hover:text-on-surface hover:bg-surface-container-low';
};

const navItemStyle = (item) => {
    if (isItemActive(item)) {
        return isDark.value
            ? 'background:rgba(26, 35, 126,0.28);border:1px solid rgba(59,130,246,0.30);box-shadow:inset 2px 0 0 0 #ffd700, 0 0 16px rgba(26, 35, 126,0.16);'
            : 'background:rgba(26, 35, 126,0.14);border:1px solid rgba(26, 35, 126,0.20);box-shadow:inset 2px 0 0 0 #ffd700;';
    }
    return 'border:1px solid transparent;';
};

// ── Sidebar icon palette ──────────────────────────────────────────
// Every icon is colour-keyed by module. Gold is the 5% institutional accent
// reserved for the two flagship surfaces (Dashboard + Reports group).
// Cyan = tech/time/learning. Magenta = people-side. Blue family = operational.
const SIDEBAR_ICON_COLORS = {
    // Flagship — gold (5% rule)
    'overview':                 '#ffd700',
    'reports':                  '#ffd700',
    'ag-reports':               '#b88a08',

    // Cyan — technology, time, learning, IT department
    'attendance':               '#12d9e3',
    'attendance-me':            '#7986cb',
    'attendance-shifts':        '#7986cb',
    'attendance-corrections':   '#7986cb',
    'learning':                 '#12d9e3',
    'learning-my':              '#7986cb',
    'learning-skills':          '#7986cb',
    'learning-compliance':      '#7986cb',
    'dept-it':                  '#12d9e3',
    'tickets':                  '#7986cb',

    // Magenta — people, HR, performance
    'employees':                '#d912e3',
    'recruitment':              '#d912e3',
    'performance':              '#d912e3',
    'performance-goals':        '#d912e3',
    'performance-reviews':      '#d912e3',
    'performance-contracts':    '#d912e3',
    'performance-calibration':  '#d912e3',
    'performance-pips':         '#d912e3',
    'performance-9box':         '#d912e3',
    'dept-hr':                  '#d912e3',

    // Blue family — operational, financial, governance
    'leave':                    '#7986cb',
    'payroll':                  '#3949ab',
    'loans':                    '#3949ab',
    'onboarding':               '#7986cb',
    'offboarding':              '#7986cb',
    'governance':               '#3949ab',
    'assets':                   '#7986cb',
    'dept-finance':             '#3949ab',
    'dept-marketing':           '#7986cb',
    'finance':                  '#3949ab',
    'finance-accounts':         '#3949ab',
    'finance-bank-accounts':    '#3949ab',
    'finance-vendors':          '#3949ab',
    'finance-ap-invoices':      '#3949ab',
    'finance-ap-payments':      '#3949ab',
    'finance-customers':        '#3949ab',
    'finance-ar-invoices':      '#3949ab',
    'finance-ar-receipts':      '#3949ab',
    'finance-statements':       '#3949ab',
    'finance-payment-intents':  '#3949ab',
    'finance-reconciliation':   '#3949ab',
    'finance-journal':          '#3949ab',

    // Billing (member fees) — keep in the finance blue family
    'billing-members':          '#3949ab',
    'billing-fee-catalog':      '#3949ab',
    'billing-runs':             '#3949ab',

    // Messaging
    'messaging':                '#3949ab',
    'messaging-broadcasts':     '#3949ab',
    'messaging-templates':      '#3949ab',
};
const SIDEBAR_ICON_DEFAULT = '#7986cb';

// Expandable group labels (no module slug) → palette colour
const SIDEBAR_GROUP_COLORS = {
    'Attendance':  '#12d9e3',   // cyan
    'Performance': '#d912e3',   // magenta
    'Learning':    '#12d9e3',   // cyan
    'Reports':     '#ffd700',   // gold (flagship)
    'Finance':     '#3949ab',   // blue family
    'Billing':     '#3949ab',   // blue family (finance-adjacent)
};

const iconColor = (item) =>
    SIDEBAR_ICON_COLORS[item.module]
    ?? SIDEBAR_GROUP_COLORS[item.label]
    ?? SIDEBAR_ICON_DEFAULT;

const navIconStyle = (item) => {
    const c = iconColor(item);
    const active = isItemActive(item);
    return `color:${c};font-variation-settings:'FILL' ${active ? 1 : 0};` +
           `${active ? `filter:drop-shadow(0 0 6px ${c}80);` : ''}`;
};

// Active sidebar dot — gold institutional accent (5% of UI palette)
const activeDotClass = computed(() => 'bg-brand-gold shadow-[0_0_8px_rgba(255,215,0,0.65)]');

const userSectionBorderStyle = computed(() => isDark.value
    ? { borderColor: 'rgba(255,255,255,0.06)' }
    : { borderColor: 'rgba(198,198,205,0.4)' }
);

const userCardStyle = computed(() => isDark.value
    ? { background: 'rgba(255,255,255,0.04)' }
    : { background: 'rgba(26, 35, 126,0.04)' }
);

const avatarBorderStyle = computed(() => isDark.value
    ? { borderColor: 'rgba(255,255,255,0.12)' }
    : { borderColor: 'rgba(198,198,205,0.6)' }
);

const onlineDotBorderStyle = computed(() => isDark.value
    ? { borderColor: '#0d1452' }
    : { borderColor: '#ffffff' }
);

const userNameClass = computed(() => isDark.value ? 'text-white' : 'text-on-surface');
const userRoleStyle = computed(() => isDark.value
    ? { color: 'rgba(255,255,255,0.35)' }
    : { color: 'rgba(69,70,77,0.6)' }
);

const logoutClass = computed(() => isDark.value
    ? 'text-white/30 hover:text-red-400 hover:bg-red-500/[0.08] hover:border-red-500/15'
    : 'text-on-surface-variant/60 hover:text-red-600 hover:bg-red-500/[0.08] hover:border-red-500/20'
);

// ── App switcher (apps grid) — permission-gated, links to dedicated module pages ──
const appSwitcherItems = computed(() => [
    { label: 'Overview',    icon: 'grid_view',      href: route('dashboard'),                  module: 'overview',    color: '#0d1452', rgb: '26, 35, 126',   visible: true },
    { label: 'Employees',   icon: 'badge',          href: route('employees.index'),            module: 'employees',   color: '#1a237e', rgb: '57, 73, 171', visible: can('employees.view') || can('employees.manage') },
    { label: 'Leave',       icon: 'calendar_month', href: route('leave.index'),                module: 'leave',       color: '#d97706', rgb: '217,119,6',  visible: can('leave.request') },
    { label: 'Tickets',     icon: 'support_agent',  href: route('tickets.index'),              module: 'tickets',     color: '#dc2626', rgb: '220,38,38',  visible: can('tickets.create') },
    { label: 'Payroll',     icon: 'payments',       href: route('payments.index'),             module: 'payroll',     color: '#059669', rgb: '5,150,105',  visible: can('payroll.manage') || can('payroll.view') },
    { label: 'Performance', icon: 'monitoring',     href: route('modules.performance'),        module: 'performance', color: '#1a237e', rgb: '124,58,237', visible: can('performance.view') },
    { label: 'Recruit',     icon: 'person_add',     href: route('jobs.index'),                 module: 'recruitment', color: '#0891b2', rgb: '8,145,178',  visible: can('recruitment.apply') || can('recruitment.manage') },
    { label: 'Reports',     icon: 'assessment',     href: route('reports.index'),              module: 'reports',     color: '#475569', rgb: '71,85,105',  visible: can('reports.view') },
    { label: 'Audit',       icon: 'history',        href: route('audit-logs.index'),           module: 'audit-logs',  color: '#7c2d12', rgb: '124,45,18',  visible: can('audit.view') },
    { label: 'Profile',     icon: 'person',         href: route('profile.edit'),               module: 'profile',     color: '#64748b', rgb: '100,116,139',visible: true },
].filter(i => i.visible));

const isAppActive = (item) => page.props.activeModule === item.module
    || (item.module === 'overview' && (!page.props.activeModule || page.props.activeModule === 'overview'));

// ── Quick Actions — items that land on a page with a creation flow ──
// Each href appends `?new=1` so the target page can auto-open its create slide-panel.
const quickActions = computed(() => [
    { label: 'Request leave',  icon: 'event_available', href: route('leave.index')      + '?new=1', visible: can('leave.request') },
    { label: 'Open ticket',    icon: 'support_agent',   href: route('tickets.index')    + '?new=1', visible: can('tickets.create') },
    { label: 'Add employee',   icon: 'person_add',      href: route('employees.index')  + '?new=1', visible: can('employees.manage') },
    { label: 'Record payment', icon: 'payments',        href: route('payments.index')   + '?new=1', visible: can('payroll.manage') },
    { label: 'Set a goal',     icon: 'track_changes',   href: route('performance.goals.index') + '?new=1', visible: can('performance.view') },
    { label: 'Export report',  icon: 'download',        href: route('reports.index'),               visible: can('reports.view') },
    { label: 'Compose Incident', icon: 'edit_note', href: route('incidents.index') + '?new=1', visible: true },
].filter(i => i.visible));
</script>

<template>
    <div class="h-screen overflow-hidden bg-background font-sans text-on-surface">

        <!-- WCAG 2.4.1 Bypass Blocks — first focusable element on every page. -->
        <SkipLink />

        <!-- WCAG 4.1.3 Status Messages — global polite + assertive live regions. -->
        <AriaLiveAnnouncer />

        <!-- ── Sidebar ─────────────────────────────────────── -->
        <aside
            class="sidebar-collapsible fixed inset-y-0 left-0 z-50 hidden w-[248px] flex-col border-r lg:flex"
            :class="{ 'sidebar-collapsed': !sidebarOpen }"
            :style="sidebarStyle"
        >
            <!-- Ambient glow behind logo -->
            <div class="pointer-events-none absolute -top-10 left-4 h-40 w-40 rounded-full bg-secondary/20 blur-3xl"></div>

            <!-- Logo -->
            <div class="relative flex items-center gap-3 px-5 py-5 border-b" :style="logoBorderStyle">
                <div class="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl overflow-hidden shadow-glow-sm"
                     style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                    <span class="material-symbols-outlined text-xl text-white" style="font-variation-settings:'FILL' 1">account_balance</span>
                </div>
                <div class="min-w-0">
                    <h1 class="text-[15px] font-black tracking-tight leading-none" :class="isDark ? 'text-white' : 'text-on-surface'">
                        CIHRM <span :class="isDark ? 'text-blue-400' : 'text-secondary'">Ghana</span>
                    </h1>
                    <p class="mt-0.5 text-[9px] font-bold uppercase tracking-[0.18em]"
                       :style="isDark ? 'color:rgba(255,255,255,0.3)' : 'color:rgba(69,70,77,0.45)'">
                        Enterprise HRMS
                    </p>
                </div>
            </div>

            <!-- Nav -->
            <nav ref="sidebarNavRef" class="sidebar-scroll flex-1 overflow-y-auto px-3 py-4 space-y-5">
                <div v-for="section in navSections" :key="section.title">
                    <div v-if="section.title"
                         class="mb-2 px-3 text-[9.5px] font-black uppercase tracking-[0.18em]"
                         :style="sectionLabelStyle">
                        {{ section.title }}
                    </div>
                    <div class="space-y-0.5">
                        <template v-for="item in section.items.filter(n => n.visible)" :key="item.label">

                            <!-- ── Expandable group (Performance, Learning, Departments) ── -->
                            <template v-if="item.expandable">
                                <button
                                    @click="toggleGroup(item.label)"
                                    class="w-full flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-[13px] font-semibold transition-all duration-150 border"
                                    :class="isGroupActive(item) ? (isDark ? 'text-white' : 'text-secondary') : (isDark ? 'text-white/40 hover:text-white/80 hover:bg-white/[0.05]' : 'text-on-surface-variant/70 hover:text-on-surface hover:bg-surface-container-low')"
                                    :style="isGroupActive(item) ? (isDark ? 'background:rgba(26, 35, 126,0.16);border-color:rgba(59,130,246,0.22);' : 'background:rgba(26, 35, 126,0.07);border-color:rgba(26, 35, 126,0.14);') : 'border-color:transparent;'"
                                >
                                    <span class="material-symbols-outlined flex-shrink-0 text-[19px] transition-all duration-150"
                                          :style="navIconStyle(item) || `color:${iconColor(item)};opacity:0.85;`">{{ item.icon }}</span>
                                    <span class="tracking-[-0.01em] flex-1 text-left">{{ item.label }}</span>
                                    <span class="material-symbols-outlined text-[16px] flex-shrink-0 transition-transform duration-200"
                                          :style="`transform:rotate(${isGroupExpanded(item.label) ? 90 : 0}deg);opacity:0.5`">chevron_right</span>
                                </button>
                                <Transition
                                    enter-active-class="transition-all duration-200 ease-out"
                                    enter-from-class="opacity-0 -translate-y-1"
                                    enter-to-class="opacity-100 translate-y-0"
                                    leave-active-class="transition-all duration-150 ease-in"
                                    leave-from-class="opacity-100"
                                    leave-to-class="opacity-0 -translate-y-1"
                                >
                                    <div v-if="isGroupExpanded(item.label)"
                                         class="mt-1 ml-3 space-y-0.5 border-l pl-2.5"
                                         :style="isDark ? 'border-color:rgba(255,255,255,0.08)' : 'border-color:rgba(26, 35, 126,0.14)'">
                                        <Link
                                            v-for="child in item.children.filter(c => c.visible)"
                                            :key="child.label"
                                            :href="resolveHref(child)"
                                            prefetch="hover"
                                            class="flex items-center gap-2.5 rounded-[8px] px-2.5 py-2 text-[12.5px] font-semibold transition-all duration-150"
                                            :class="navItemClass(child)"
                                            :style="navItemStyle(child)"
                                        >
                                            <span class="material-symbols-outlined flex-shrink-0 text-[17px]" :style="navIconStyle(child)">{{ child.icon }}</span>
                                            <span class="tracking-[-0.01em]">{{ child.label }}</span>
                                            <span v-if="isItemActive(child)" class="ml-auto h-1.5 w-1.5 rounded-full flex-shrink-0" :class="activeDotClass"></span>
                                        </Link>
                                    </div>
                                </Transition>
                            </template>

                            <!-- ── Regular nav link ── -->
                            <Link
                                v-else
                                :href="resolveHref(item)"
                                prefetch="hover"
                                class="group flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-[13px] font-semibold transition-all duration-150"
                                :class="navItemClass(item)"
                                :style="navItemStyle(item)"
                            >
                                <span class="material-symbols-outlined flex-shrink-0 text-[19px] transition-all duration-150" :style="navIconStyle(item)">{{ item.icon }}</span>
                                <span class="tracking-[-0.01em]">{{ item.label }}</span>
                                <span v-if="isItemActive(item)" class="ml-auto h-1.5 w-1.5 rounded-full flex-shrink-0" :class="activeDotClass"></span>
                            </Link>

                        </template>
                    </div>
                </div>
            </nav>

            <!-- User section -->
            <div class="border-t px-3 py-3" :style="userSectionBorderStyle">
                <!-- User card -->
                <div class="mb-2 flex items-center gap-3 rounded-[10px] px-3 py-2.5" :style="userCardStyle">
                    <div class="relative flex-shrink-0">
                        <div class="h-8 w-8 rounded-full overflow-hidden border" :style="avatarBorderStyle">
                            <img v-if="user?.avatar" :src="user.avatar" :alt="`${user.name} profile photo`" class="h-full w-full object-cover" />
                            <div v-else class="h-full w-full flex items-center justify-center text-[11px] font-black text-white"
                                 style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                {{ user?.name?.charAt(0) }}
                            </div>
                        </div>
                        <div class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 bg-green-400"
                             :style="onlineDotBorderStyle"></div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[12px] font-bold leading-tight" :class="userNameClass">{{ user?.name }}</p>
                        <p class="text-[10px] font-medium" :style="userRoleStyle">{{ roleLabel }}</p>
                    </div>
                </div>

                <!-- Logout -->
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="flex w-full items-center gap-3 rounded-[10px] px-3 py-2.5 text-[13px] font-semibold transition-all duration-150 border border-transparent"
                    :class="logoutClass"
                >
                    <span class="material-symbols-outlined text-[19px]">logout</span>
                    <span>Log Out</span>
                </Link>
            </div>
        </aside>

        <!-- ── Main Content ──────────────────────────────────── -->
        <div
            class="main-shift flex h-screen flex-col"
            :class="sidebarOpen ? 'lg:ml-[248px]' : 'lg:ml-0'"
        >

            <!-- Announcement ticker — runs across the very top of the page -->
            <AnnouncementTicker />

            <!-- Header -->
            <header class="z-40 flex-shrink-0 header-glass border-b border-black/[0.06] dark:border-outline-variant/40"
                    style="box-shadow:0 1px 0 rgba(0,0,0,0.05);">
                <div class="flex h-[64px] items-center gap-4 px-6 sm:px-8">

                    <!-- Mobile hamburger -->
                    <button
                        class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl border border-outline-variant text-on-surface-variant transition-colors hover:bg-surface-container-low lg:hidden"
                        @click="showingNavigationDropdown = !showingNavigationDropdown"
                    >
                        <span class="material-symbols-outlined text-xl">{{ showingNavigationDropdown ? 'close' : 'menu' }}</span>
                    </button>

                    <!-- Desktop sidebar toggle -->
                    <button
                        class="hidden h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl border border-outline-variant text-on-surface-variant transition-all duration-200 hover:bg-surface-container-low hover:border-secondary/30 hover:text-secondary lg:flex"
                        :title="sidebarOpen ? 'Collapse sidebar (Ctrl+B)' : 'Open sidebar (Ctrl+B)'"
                        :aria-label="sidebarOpen ? 'Collapse sidebar' : 'Open sidebar'"
                        :aria-expanded="sidebarOpen"
                        @click="toggleSidebar"
                    >
                        <span class="material-symbols-outlined text-xl transition-transform duration-300"
                              :style="sidebarOpen ? '' : 'transform:rotate(180deg);'">
                            {{ sidebarOpen ? 'menu_open' : 'menu' }}
                        </span>
                    </button>

                    <!-- Search -->
                    <div class="relative hidden flex-1 max-w-xl lg:block">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant/50 pointer-events-none">search</span>
                        <input aria-label="Search employees, IDs, departments…"
                            type="text"
                            placeholder="Search employees, IDs, departments…"
                            class="w-full rounded-full border border-outline-variant/60 bg-surface-container-low/60 dark:bg-surface-container-low/80 py-2.5 pl-11 pr-5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 transition-all duration-200 focus:outline-none focus:border-secondary/40 focus:ring-4 focus:ring-secondary/8 focus:bg-surface-container-lowest"
                        />
                    </div>

                    <div class="ml-auto flex items-center gap-1.5">
                        <!-- Sound effects toggle -->
                        <SoundToggle />

                        <!-- Notifications -->
                        <NotificationBell />

                        <!-- App switcher: permission-gated, links to dedicated module pages -->
                        <Dropdown align="right" width="72">
                            <template #trigger>
                                <button
                                    class="flex h-9 w-9 items-center justify-center rounded-xl text-on-surface-variant transition-all hover:bg-surface-container-low hover:text-on-surface"
                                    title="Apps"
                                    aria-label="Apps"
                                    type="button"
                                >
                                    <span class="material-symbols-outlined text-[20px]">apps</span>
                                </button>
                            </template>
                            <template #content>
                                <div class="p-3 bg-surface-container-lowest dark:bg-surface-container-low">
                                    <p class="px-1 pb-2.5 text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/60">
                                        Apps · {{ appSwitcherItems.length }}
                                    </p>
                                    <div class="grid grid-cols-3 gap-1.5">
                                        <Link
                                            v-for="m in appSwitcherItems" :key="m.label"
                                            :href="m.href"
                                            prefetch="hover"
                                            class="group flex flex-col items-center gap-1.5 rounded-xl p-2.5 border transition-all duration-150 hover:-translate-y-0.5"
                                            :class="isAppActive(m)
                                                ? 'border-secondary/30 bg-secondary/5'
                                                : 'border-transparent hover:bg-surface-container-low hover:border-outline-variant/40'"
                                        >
                                            <span
                                                class="flex h-9 w-9 items-center justify-center rounded-lg transition-transform group-hover:scale-110"
                                                :style="`background:rgba(${m.rgb},0.12);color:${m.color};border:1px solid rgba(${m.rgb},0.2)`"
                                            >
                                                <span
                                                    class="material-symbols-outlined text-[18px]"
                                                    style="font-variation-settings:'FILL' 1"
                                                >{{ m.icon }}</span>
                                            </span>
                                            <span class="text-[10px] font-bold leading-tight text-center" :class="isAppActive(m) ? 'text-secondary' : 'text-on-surface'">{{ m.label }}</span>
                                        </Link>
                                    </div>
                                </div>
                            </template>
                        </Dropdown>

                        <!-- Divider -->
                        <div class="mx-2 h-6 w-px bg-outline-variant/60"></div>

                        <!-- Quick Action: permission-gated; each item lands on a page with ?new=1 to auto-open the create flow -->
                        <Dropdown align="right" width="64">
                            <template #trigger>
                                <button type="button"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white shadow-glow-sm transition-all duration-200 hover:shadow-glow hover:-translate-y-px active:scale-[0.97]"
                                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                                        aria-label="Quick action menu">
                                    <span class="material-symbols-outlined text-[17px]">add</span>
                                    Quick Action
                                </button>
                            </template>
                            <template #content>
                                <div class="bg-surface-container-lowest dark:bg-surface-container-low overflow-hidden">
                                    <p class="px-4 pt-3 pb-2 text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/60 border-b border-outline-variant/40">
                                        Quick Actions
                                    </p>
                                    <div class="py-1">
                                        <Link
                                            v-for="a in quickActions" :key="a.label"
                                            :href="a.href"
                                            prefetch="hover"
                                            class="flex items-center gap-2.5 px-4 py-2.5 text-[13px] font-semibold text-on-surface hover:bg-secondary/[0.06] transition-colors"
                                        >
                                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-secondary/10 text-secondary">
                                                <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">{{ a.icon }}</span>
                                            </span>
                                            <span>{{ a.label }}</span>
                                        </Link>
                                        <p v-if="quickActions.length === 0" class="px-4 py-3 text-[12px] italic text-on-surface-variant/60">
                                            No quick actions available for your role.
                                        </p>
                                    </div>
                                </div>
                            </template>
                        </Dropdown>

                        <!-- Avatar with profile dropdown -->
                        <Dropdown align="right" width="56">
                            <template #trigger>
                                <button type="button"
                                        :title="user?.name"
                                        :aria-label="`${user?.name ?? 'Account'} menu`"
                                        class="ml-1 h-9 w-9 flex-shrink-0 cursor-pointer overflow-hidden rounded-full border-2 border-surface-container-lowest shadow-sm ring-1 ring-outline-variant/40 transition-all hover:ring-secondary/50 focus:outline-none focus:ring-2 focus:ring-secondary">
                                    <img v-if="user?.avatar" :src="user.avatar" :alt="`${user.name} profile photo`" class="h-full w-full object-cover" />
                                    <span v-else class="flex h-full w-full items-center justify-center text-[11px] font-black text-white"
                                          style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                        {{ user?.name?.charAt(0) }}
                                    </span>
                                </button>
                            </template>
                            <template #content>
                                <div class="bg-surface-container-lowest dark:bg-surface-container-low overflow-hidden">
                                    <!-- Identity strip -->
                                    <div class="flex items-center gap-3 border-b border-outline-variant/40 px-4 py-3">
                                        <div class="h-10 w-10 flex-shrink-0 overflow-hidden rounded-full ring-2 ring-white dark:ring-surface-container-lowest shadow-sm">
                                            <img v-if="user?.avatar" :src="user.avatar" :alt="user.name" class="h-full w-full object-cover" />
                                            <div v-else class="flex h-full w-full items-center justify-center text-[12px] font-black text-white"
                                                 style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                                {{ user?.name?.charAt(0) }}
                                            </div>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-[13px] font-bold text-on-surface">{{ user?.name }}</p>
                                            <p class="truncate text-[11px] text-on-surface-variant">{{ user?.email }}</p>
                                        </div>
                                    </div>
                                    <!-- Actions -->
                                    <DropdownLink :href="route('profile.edit')" class="flex items-center gap-2.5">
                                        <span class="material-symbols-outlined text-[17px] text-secondary">person</span>
                                        My profile
                                    </DropdownLink>
                                    <DropdownLink :href="route('notifications.channels.edit')" class="flex items-center gap-2.5">
                                        <span class="material-symbols-outlined text-[17px] text-secondary">tune</span>
                                        Notification channels
                                    </DropdownLink>
                                    <div class="my-1 border-t border-outline-variant/40"></div>
                                    <DropdownLink :href="route('logout')" method="post" as="button" class="flex w-full items-center gap-2.5 text-red-600 hover:bg-red-500/[0.06]">
                                        <span class="material-symbols-outlined text-[17px]">logout</span>
                                        Sign out
                                    </DropdownLink>
                                </div>
                            </template>
                        </Dropdown>
                    </div>
                </div>

                <!-- Mobile Nav drawer -->
                <Transition
                    enter-active-class="transition-all duration-200 ease-spring"
                    enter-from-class="opacity-0 -translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition-all duration-150 ease-in"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-2"
                >
                    <div v-if="showingNavigationDropdown"
                         class="border-t border-outline-variant/60 bg-surface-container-lowest px-4 py-4 lg:hidden max-h-[65vh] overflow-y-auto">
                        <nav class="space-y-0.5">
                            <Link
                                v-for="item in allNavItems.filter(n => n.visible)"
                                :key="`mobile-${item.label}`"
                                :href="resolveHref(item)"
                                prefetch="hover"
                                class="flex items-center gap-3 rounded-xl px-4 py-3 text-[13px] font-semibold transition-all"
                                :class="isItemActive(item)
                                    ? 'bg-secondary text-white shadow-glow-sm'
                                    : 'text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface'"
                            >
                                <span class="material-symbols-outlined text-[20px]"
                                      :style="isItemActive(item) ? 'font-variation-settings:\'FILL\' 1' : ''">{{ item.icon }}</span>
                                <span>{{ item.label }}</span>
                            </Link>
                        </nav>
                    </div>
                </Transition>
            </header>

            <!-- Page-header strip ─────────────────────────────────────────
                 Persistent-layout mount point. Each page Teleports its
                 executive header into #page-header-mount; the CSS :empty
                 rule below collapses the strip when no page is mounted yet
                 or the page chose to omit a header. The Teleport pattern
                 is what keeps the sidebar/header alive across navigations
                 — Vue diffs the persistent layout and only re-renders this
                 div's children instead of unmounting the whole shell. -->
            <div id="page-header-mount" class="page-header-strip flex-shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest px-6 py-5 sm:px-8"></div>

            <!-- Main (independently scrollable). tabindex="-1" + id makes
                 SkipLink's anchor target focusable.
                 The keyed inner div forces Vue to fully remount the page
                 component whenever the Inertia URL changes — guarantees
                 the slot's content swaps even if the layout's persistent
                 instance reuses internal state across navigations. -->
            <main
                id="main-content"
                tabindex="-1"
                class="main-scroll flex-1 overflow-y-auto p-5 sm:p-7 lg:p-8"
            >
                <div :key="page.url">
                    <slot />
                </div>
            </main>
        </div>

        <!-- Global toast queue (Inertia flash + useToast() programmatic) -->
        <Toast />
    </div>
</template>

<style>
/* Collapse the page-header strip when no page has teleported anything into
   it yet (initial paint between navigations). Keeps the layout from showing
   an empty bordered band before the page's Teleport runs. */
.page-header-strip:empty { display: none; }

.main-scroll::-webkit-scrollbar { width: 10px; }
.main-scroll::-webkit-scrollbar-track { background: transparent; }
.main-scroll::-webkit-scrollbar-thumb {
    background: rgba(100, 116, 139, 0.25);
    border-radius: 10px;
    border: 2px solid transparent;
    background-clip: padding-box;
}
.main-scroll::-webkit-scrollbar-thumb:hover { background-color: rgba(100, 116, 139, 0.45); background-clip: padding-box; }

/* ── Sidebar collapse/expand animation ─────────────────────────────────────
   Slides the sidebar off-screen on collapse, shrinks the main content's left
   margin in lockstep so nothing jumps. Single easing curve on both keeps the
   two surfaces visually attached during the motion. */
.sidebar-collapsible {
    transition: transform 320ms cubic-bezier(0.4, 0, 0.2, 1),
                box-shadow 320ms cubic-bezier(0.4, 0, 0.2, 1);
    will-change: transform;
}
.sidebar-collapsed {
    transform: translateX(-100%);
    box-shadow: none;
    pointer-events: none;
}
.main-shift {
    transition: margin-left 320ms cubic-bezier(0.4, 0, 0.2, 1);
}

/* Respect reduced-motion preferences (WCAG 2.3.3). */
@media (prefers-reduced-motion: reduce) {
    .sidebar-collapsible,
    .main-shift { transition-duration: 0.01ms; }
}
</style>
