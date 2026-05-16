<script setup>
import { computed, ref, onMounted, watch } from 'vue';
import Dropdown         from '@/Components/Dropdown.vue';
import DropdownLink     from '@/Components/DropdownLink.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import Toast            from '@/Components/Toast.vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { useDark } from '@/composables/useDark';

const showingNavigationDropdown = ref(false);
const mounted = ref(false);
const page = usePage();
const permissions = computed(() => page.props.auth?.permissions ?? []);
const user = computed(() => page.props.auth?.user);

const { isDark, toggle, init } = useDark();

onMounted(() => {
    init();
    setTimeout(() => { mounted.value = true; }, 50);
});

const can = (permission) =>
    permissions.value.includes('*') || permissions.value.includes(permission);

const navSections = computed(() => {
    if (user.value?.role === 'super_admin' || user.value?.role === 'hr_admin') {
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
                    { label: 'Service Desk', route: 'modules.tickets',      module: 'tickets',     icon: 'support_agent',    visible: true },
                    {
                        label: 'Learning', icon: 'school', expandable: true, visible: can('learning.view'),
                        children: [
                            { label: 'Catalogue',     route: 'learning.catalog',       module: 'learning',         icon: 'menu_book',  visible: true },
                            { label: 'My Learning',   route: 'learning.my',            module: 'learning-my',      icon: 'play_lesson',visible: true },
                            { label: 'Skills Matrix', route: 'learning.skills-matrix', module: 'learning-skills',  icon: 'grid_on',    visible: can('learning.manage') },
                        ],
                    },
                    { label: 'Governance',   route: 'modules.governance',   module: 'governance',  icon: 'account_balance',  visible: true },
                    { label: 'Assets',       route: 'modules.assets',       module: 'assets',      icon: 'inventory_2',      visible: true },
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
            { label: 'IT & Technology', route: 'departments.portal', routeParams: { slug: 'it' },        module: 'dept-it',        icon: 'computer',               visible: can('portal.it') },
            { label: 'Human Resources', route: 'departments.portal', routeParams: { slug: 'hr' },        module: 'dept-hr',        icon: 'people',                 visible: can('portal.hr') },
            { label: 'Marketing',       route: 'departments.portal', routeParams: { slug: 'marketing' }, module: 'dept-marketing', icon: 'campaign',               visible: can('portal.marketing') },
            { label: 'Finance',         route: 'departments.portal', routeParams: { slug: 'finance' },   module: 'dept-finance',   icon: 'account_balance_wallet', visible: can('portal.finance') },
        ];
        if (portalChildren.some(c => c.visible)) {
            sections.push({
                title: 'Departments',
                items: [
                    { label: 'Departments', icon: 'corporate_fare', expandable: true, visible: true, children: portalChildren },
                ],
            });
        }

        if (user.value?.role === 'super_admin') {
            sections.push({
                title: 'System',
                items: [
                    { label: 'Integrations',  route: 'admin.integrations.index',  module: 'integrations',  icon: 'extension', visible: true },
                    { label: 'Whistleblower', route: 'whistleblower.admin.index', module: 'whistleblower', icon: 'campaign',  visible: can('whistleblower.investigate') || can('whistleblower.view_all') },
                    { label: 'Settings',      route: 'profile.edit',              module: 'settings',      icon: 'settings',  visible: true },
                    { label: 'Audit Logs',    route: 'modules.audit-logs',        module: 'audit-logs',    icon: 'history',   visible: true },
                ]
            });
        } else if (can('integrations.manage') || can('whistleblower.investigate') || can('whistleblower.view_all')) {
            sections.push({
                title: 'System',
                items: [
                    { label: 'Integrations',  route: 'admin.integrations.index',  module: 'integrations',  icon: 'extension', visible: can('integrations.manage') },
                    { label: 'Whistleblower', route: 'whistleblower.admin.index', module: 'whistleblower', icon: 'campaign',  visible: can('whistleblower.investigate') || can('whistleblower.view_all') },
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

    return [
        {
            title: '',
            items: [
                { label: 'Dashboard',        route: 'dashboard',        module: 'overview',  icon: 'grid_view',      visible: true },
                { label: 'My Profile',       route: 'profile.edit',     module: 'profile',   icon: 'person',         visible: true },
                { label: 'Leave & Time-Off', route: 'modules.leave',    module: 'leave',     icon: 'calendar_today', visible: true },
                { label: 'Benefits',         route: 'dashboard',        module: 'benefits',  icon: 'diversity_3',    visible: true },
                { label: 'Learning & Dev',   route: 'learning.catalog', module: 'learning',  icon: 'school',         visible: true },
            ]
        },
        {
            title: 'Support',
            items: [
                { label: 'Settings', route: 'profile.edit',    module: 'settings', icon: 'settings', visible: true },
                { label: 'Support',  route: 'modules.tickets', module: 'tickets',  icon: 'help',     visible: true },
            ]
        }
    ];
});

const isItemActive = (item) => {
    if (!item) return false;
    const currentModule = page.props.activeModule;

    // 1. Route-name match (preferred when the item points to a dedicated route).
    //    We skip this for items routed to `dashboard` because every dashboard
    //    sub-module shares the same route and disambiguates via `?module=`.
    //    This stops sibling children (e.g. Performance > Analytics) lighting up
    //    when the page sets a generic activeModule shared with the group.
    if (item.route && item.route !== 'dashboard') {
        if (item.routeParams) {
            try {
                if (route().current() !== item.route) return false;
                const params = route().params ?? {};
                return Object.entries(item.routeParams).every(([k, v]) => String(params[k]) === String(v));
            } catch (e) { return false; }
        }
        return route().current(item.route);
    }

    // 2. Module-prop match — fallback for dashboard sub-modules and any item
    //    that doesn't declare a specific route.
    if (currentModule !== undefined && currentModule !== null) {
        return item.module === currentModule;
    }

    return false;
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
const expandedGroups = ref(new Set(['Departments', 'Performance', 'Learning', 'Attendance', 'Reports']));

const isGroupExpanded = (label) => expandedGroups.value.has(label);

const toggleGroup = (label) => {
    const next = new Set(expandedGroups.value);
    if (next.has(label)) next.delete(label); else next.add(label);
    expandedGroups.value = next;
};

// Auto-expand any group whose child is currently active. Watches the page's
// active-module signal so navigating from Dashboard → Performance/Goals opens
// the Performance group automatically (and keeps it open) without disturbing
// any manual expand/collapse the user has done on unrelated groups.
watch(
    () => page.props.activeModule,
    () => {
        const next = new Set(expandedGroups.value);
        navSections.value.forEach(section => {
            section.items.forEach(item => {
                if (item.expandable && isGroupActive(item)) {
                    next.add(item.label);
                }
            });
        });
        expandedGroups.value = next;
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
    ? { background: '#0a1f5c', borderColor: 'rgba(255,255,255,0.06)' }
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
            ? 'background:rgba(29,78,216,0.22);border:1px solid rgba(59,130,246,0.25);box-shadow:0 0 16px rgba(29,78,216,0.12);'
            : 'background:rgba(29,78,216,0.08);border:1px solid rgba(29,78,216,0.15);';
    }
    return 'border:1px solid transparent;';
};

const navIconStyle = (item) => {
    if (!isItemActive(item)) return '';
    return isDark.value
        ? "font-variation-settings:'FILL' 1;color:#5b9fd9;"
        : "font-variation-settings:'FILL' 1;color:#0a1f5c;";
};

const activeDotClass = computed(() => isDark.value ? 'bg-blue-400' : 'bg-secondary');

const userSectionBorderStyle = computed(() => isDark.value
    ? { borderColor: 'rgba(255,255,255,0.06)' }
    : { borderColor: 'rgba(198,198,205,0.4)' }
);

const userCardStyle = computed(() => isDark.value
    ? { background: 'rgba(255,255,255,0.04)' }
    : { background: 'rgba(29,78,216,0.04)' }
);

const avatarBorderStyle = computed(() => isDark.value
    ? { borderColor: 'rgba(255,255,255,0.12)' }
    : { borderColor: 'rgba(198,198,205,0.6)' }
);

const onlineDotBorderStyle = computed(() => isDark.value
    ? { borderColor: '#0a1f5c' }
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
    { label: 'Overview',    icon: 'grid_view',      href: route('dashboard'),                  module: 'overview',    color: '#0a1f5c', rgb: '29,78,216',   visible: true },
    { label: 'Employees',   icon: 'badge',          href: route('employees.index'),            module: 'employees',   color: '#1d4ed8', rgb: '59,130,246', visible: can('employees.view') || can('employees.manage') },
    { label: 'Leave',       icon: 'calendar_month', href: route('leave.index'),                module: 'leave',       color: '#d97706', rgb: '217,119,6',  visible: can('leave.request') },
    { label: 'Tickets',     icon: 'support_agent',  href: route('tickets.index'),              module: 'tickets',     color: '#dc2626', rgb: '220,38,38',  visible: can('tickets.create') },
    { label: 'Payroll',     icon: 'payments',       href: route('payments.index'),             module: 'payroll',     color: '#059669', rgb: '5,150,105',  visible: can('payroll.manage') || can('payroll.view') },
    { label: 'Performance', icon: 'monitoring',     href: route('modules.performance'),        module: 'performance', color: '#7c3aed', rgb: '124,58,237', visible: can('performance.view') },
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
].filter(i => i.visible));
</script>

<template>
    <div class="h-screen overflow-hidden bg-background font-sans text-on-surface">

        <!-- ── Sidebar ─────────────────────────────────────── -->
        <aside
            class="fixed inset-y-0 left-0 z-50 hidden w-[248px] flex-col border-r lg:flex"
            :style="sidebarStyle"
        >
            <!-- Ambient glow behind logo -->
            <div class="pointer-events-none absolute -top-10 left-4 h-40 w-40 rounded-full bg-secondary/20 blur-3xl"></div>

            <!-- Logo -->
            <div class="relative flex items-center gap-3 px-5 py-5 border-b" :style="logoBorderStyle">
                <div class="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl overflow-hidden shadow-glow-sm"
                     style="background:linear-gradient(135deg,#0a1f5c,#1d4ed8)">
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
            <nav class="sidebar-scroll flex-1 overflow-y-auto px-3 py-4 space-y-5">
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
                                    :style="isGroupActive(item) ? (isDark ? 'background:rgba(29,78,216,0.16);border-color:rgba(59,130,246,0.22);' : 'background:rgba(29,78,216,0.07);border-color:rgba(29,78,216,0.14);') : 'border-color:transparent;'"
                                >
                                    <span class="material-symbols-outlined flex-shrink-0 text-[19px] transition-all duration-150"
                                          :style="isGroupActive(item) ? (isDark ? 'font-variation-settings:\'FILL\' 1;color:#5b9fd9;' : 'font-variation-settings:\'FILL\' 1;color:#0a1f5c;') : ''">{{ item.icon }}</span>
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
                                         :style="isDark ? 'border-color:rgba(255,255,255,0.08)' : 'border-color:rgba(29,78,216,0.14)'">
                                        <Link
                                            v-for="child in item.children.filter(c => c.visible)"
                                            :key="child.label"
                                            :href="resolveHref(child)"
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
                            <img v-if="user?.avatar" :src="user.avatar" class="h-full w-full object-cover" />
                            <div v-else class="h-full w-full flex items-center justify-center text-[11px] font-black text-white"
                                 style="background:linear-gradient(135deg,#0a1f5c,#1d4ed8)">
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
        <div class="flex h-screen flex-col lg:ml-[248px]">

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

                    <!-- Search -->
                    <div class="relative hidden flex-1 max-w-xl lg:block">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant/50 pointer-events-none">search</span>
                        <input
                            type="text"
                            placeholder="Search employees, IDs, departments…"
                            class="w-full rounded-full border border-outline-variant/60 bg-surface-container-low/60 dark:bg-surface-container-low/80 py-2.5 pl-11 pr-5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 transition-all duration-200 focus:outline-none focus:border-secondary/40 focus:ring-4 focus:ring-secondary/8 focus:bg-surface-container-lowest"
                        />
                    </div>

                    <div class="ml-auto flex items-center gap-1.5">
                        <!-- Notifications -->
                        <NotificationBell />

                        <!-- ── Dark / Light toggle ── -->
                        <button
                            @click="toggle"
                            class="theme-toggle text-on-surface-variant"
                            :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                            aria-label="Toggle dark mode"
                        >
                            <Transition
                                enter-active-class="transition-all duration-200"
                                enter-from-class="opacity-0 rotate-90 scale-50"
                                enter-to-class="opacity-100 rotate-0 scale-100"
                                leave-active-class="transition-all duration-150"
                                leave-from-class="opacity-100 rotate-0 scale-100"
                                leave-to-class="opacity-0 -rotate-90 scale-50"
                                mode="out-in"
                            >
                                <span
                                    v-if="isDark"
                                    key="sun"
                                    class="material-symbols-outlined text-[20px] text-amber-400"
                                    style="font-variation-settings:'FILL' 1"
                                >light_mode</span>
                                <span
                                    v-else
                                    key="moon"
                                    class="material-symbols-outlined text-[20px]"
                                >dark_mode</span>
                            </Transition>
                        </button>

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
                                        style="background:linear-gradient(135deg,#0a1f5c,#1d4ed8)"
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

                        <!-- Avatar -->
                        <div class="ml-1 h-9 w-9 flex-shrink-0 cursor-pointer overflow-hidden rounded-full border-2 border-surface-container-lowest shadow-sm ring-1 ring-outline-variant/40 transition-all hover:ring-secondary/50">
                            <img v-if="user?.avatar" :src="user.avatar" class="h-full w-full object-cover" />
                            <div v-else class="flex h-full w-full items-center justify-center text-[11px] font-black text-white"
                                 style="background:linear-gradient(135deg,#0a1f5c,#1d4ed8)">
                                {{ user?.name?.charAt(0) }}
                            </div>
                        </div>
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

            <!-- Page Title Slot -->
            <div v-if="$slots.header" class="flex-shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest px-6 py-5 sm:px-8">
                <slot name="header" />
            </div>

            <!-- Main (independently scrollable) -->
            <main class="main-scroll flex-1 overflow-y-auto p-5 sm:p-7 lg:p-8">
                <slot />
            </main>
        </div>

        <!-- Global toast queue (Inertia flash + useToast() programmatic) -->
        <Toast />
    </div>
</template>

<style>
.main-scroll::-webkit-scrollbar { width: 10px; }
.main-scroll::-webkit-scrollbar-track { background: transparent; }
.main-scroll::-webkit-scrollbar-thumb {
    background: rgba(100, 116, 139, 0.25);
    border-radius: 10px;
    border: 2px solid transparent;
    background-clip: padding-box;
}
.main-scroll::-webkit-scrollbar-thumb:hover { background-color: rgba(100, 116, 139, 0.45); background-clip: padding-box; }
</style>
