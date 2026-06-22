<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});

const mounted = ref(false);
const activeCard = ref(null);

const loginHref = (type) => `/login?user_type=${encodeURIComponent(type)}`;

const portalCards = [
    {
        key: 'admin',
        title: 'Admin / Super Admin',
        eyebrow: 'System Control',
        description: 'Manage users, permissions, integrations, audit logs, configuration, and executive oversight.',
        icon: 'admin_panel_settings',
        symbol: 'A',
        href: loginHref('admin'),
        accent: '#facc15',
        glow: 'rgba(250, 204, 21, 0.28)',
        stats: ['Full access', 'Audit ready', '2FA controls'],
        actions: ['Users', 'Roles', 'Security'],
    },
    {
        key: 'hr',
        title: 'HR Workspace',
        eyebrow: 'People Operations',
        description: 'Run employee records, recruitment, leave, attendance, benefits, learning, performance, and offboarding.',
        icon: 'diversity_3',
        symbol: 'HR',
        href: loginHref('hr'),
        accent: '#38bdf8',
        glow: 'rgba(56, 189, 248, 0.28)',
        stats: ['Employees', 'Recruitment', 'Performance'],
        actions: ['Leave', 'Attendance', 'Benefits'],
    },
    {
        key: 'finance',
        title: 'Finance Hub',
        eyebrow: 'Payments & Accounts',
        description: 'Access payroll, invoices, receipts, Paystack payments, refunds, bank reconciliation, and journals.',
        icon: 'account_balance_wallet',
        symbol: '₵',
        href: loginHref('finance'),
        accent: '#34d399',
        glow: 'rgba(52, 211, 153, 0.28)',
        stats: ['Payroll', 'AR / AP', 'Reconciliation'],
        actions: ['Invoices', 'Receipts', 'Journal'],
    },
    {
        key: 'employee',
        title: 'Employee Portal',
        eyebrow: 'Self Service',
        description: 'Update profile details, request leave, view attendance, raise tickets, access documents, and follow tasks.',
        icon: 'badge',
        symbol: 'ID',
        href: loginHref('employee'),
        accent: '#a78bfa',
        glow: 'rgba(167, 139, 250, 0.28)',
        stats: ['Profile', 'Leave', 'Tickets'],
        actions: ['Attendance', 'Documents', 'Learning'],
    },
    {
        key: 'member',
        title: 'Member Portal',
        eyebrow: 'Fees & Statements',
        description: 'Members can view fees, invoices, statements, outstanding balances, and payment options.',
        icon: 'card_membership',
        symbol: 'M',
        href: '/portal/login',
        accent: '#fb7185',
        glow: 'rgba(251, 113, 133, 0.28)',
        stats: ['Fees', 'Invoices', 'Statements'],
        actions: ['Payments', 'Profile', 'Receipts'],
    },
    {
        key: 'public',
        title: 'Public Services',
        eyebrow: 'Assisted Access',
        description: 'A guided entry point for public-facing services that require staff oversight or secure access.',
        icon: 'public',
        symbol: 'PS',
        href: loginHref('public-services'),
        accent: '#f97316',
        glow: 'rgba(249, 115, 22, 0.28)',
        stats: ['Careers', 'DPA', 'Whistleblower'],
        actions: ['Complaints', 'Kiosk', 'API Docs'],
    },
];

const highlightedCard = computed(() =>
    portalCards.find((card) => card.key === activeCard.value) ?? portalCards[0]
);

onMounted(() => {
    requestAnimationFrame(() => {
        mounted.value = true;
    });
});
</script>

<template>
    <Head title="CIHRMS Portal Access" />

    <main class="portal-shell min-h-screen overflow-hidden text-white">
        <div class="portal-grid-bg" aria-hidden="true"></div>
        <div class="portal-orbit portal-orbit-one" aria-hidden="true"></div>
        <div class="portal-orbit portal-orbit-two" aria-hidden="true"></div>

        <section class="relative z-10 mx-auto flex min-h-screen w-full max-w-[1480px] flex-col px-5 py-3 sm:px-7 lg:px-8">
            <header class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="brand-mark">
                        <span>CI</span>
                    </div>
                    <div>
                        <p class="text-[17px] font-black leading-none tracking-tight">CIHRMS</p>
                        <p class="mt-1 text-[9px] font-black uppercase tracking-[0.32em] text-white/35">Portal Access</p>
                    </div>
                </div>

            </header>

            <div class="grid flex-1 items-start gap-5 py-5 lg:grid-cols-[0.78fr_1.22fr] lg:py-7">
                <aside
                    class="intro-panel flex h-full flex-col"
                    :class="{ 'is-mounted': mounted }"
                    :style="{ '--active-accent': highlightedCard.accent, '--active-glow': highlightedCard.glow }"
                >
                    <div class="intro-chip">
                        <span class="chip-dot"></span>
                        Choose your workspace
                    </div>

                    <h1 class="mt-6 text-[clamp(2.25rem,4.8vw,5.2rem)] font-black leading-[0.96] tracking-[-0.055em]">
                        One system.
                        <span class="block text-white/45">Right door.</span>
                    </h1>

                    <p class="mt-6 max-w-xl text-[14px] font-medium leading-7 text-white/58 sm:text-[15px]">
                        Select the card that matches your role. Each workspace leads to the correct sign-in path for staff, finance, HR, employees, members, administrators, or public services.
                    </p>

                    <div class="mt-8 rounded-[24px] border border-white/10 bg-white/[0.045] p-3.5 shadow-2xl shadow-black/25 backdrop-blur-xl">
                        <div class="flex items-start gap-4">
                            <div class="featured-icon" :style="{ color: highlightedCard.accent, backgroundColor: `${highlightedCard.accent}1f` }">
                                <span>{{ highlightedCard.symbol }}</span>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-black uppercase tracking-[0.24em]" :style="{ color: highlightedCard.accent }">
                                    {{ highlightedCard.eyebrow }}
                                </p>
                                <h2 class="mt-1.5 text-xl font-black tracking-tight">{{ highlightedCard.title }}</h2>
                                <p class="mt-1.5 text-[13px] leading-5 text-white/52">{{ highlightedCard.description }}</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="props.canLogin" class="mt-10 flex flex-wrap items-center gap-3">
                        <Link
                            :href="route('login')"
                            class="top-action top-action-muted"
                        >
                            Staff login
                        </Link>
                        <Link
                            v-if="props.canRegister"
                            :href="route('register')"
                            class="top-action top-action-primary"
                        >
                            Request access
                        </Link>
                    </div>
                </aside>

                <section class="portal-card-grid" aria-label="CIHRMS workspace cards">
                    <Link
                        v-for="(card, index) in portalCards"
                        :key="card.key"
                        :href="card.href"
                        class="portal-card group"
                        :class="{ 'is-mounted': mounted }"
                        :style="{
                            '--accent': card.accent,
                            '--glow': card.glow,
                            '--delay': `${index * 90}ms`,
                        }"
                        @mouseenter="activeCard = card.key"
                        @focus="activeCard = card.key"
                    >
                        <div class="card-light" aria-hidden="true"></div>
                        <div class="card-sheen" aria-hidden="true"></div>

                        <div class="flex items-start justify-between gap-4">
                            <div class="card-icon">
                                <span>{{ card.symbol }}</span>
                            </div>
                            <span class="card-arrow">↗</span>
                        </div>

                        <div class="mt-4">
                            <p class="card-eyebrow">{{ card.eyebrow }}</p>
                            <h2 class="mt-1.5 text-[20px] font-black leading-tight tracking-tight">{{ card.title }}</h2>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span v-for="stat in card.stats" :key="stat" class="metric-pill">{{ stat }}</span>
                        </div>

                        <div class="mt-3 border-t border-white/10 pt-3">
                            <div class="flex flex-wrap gap-1.5">
                                <span v-for="action in card.actions" :key="action" class="action-token">{{ action }}</span>
                            </div>
                        </div>
                    </Link>
                </section>
            </div>

            <footer class="flex flex-col items-start justify-between gap-2 border-t border-white/10 pt-3 text-[10px] font-bold uppercase tracking-[0.16em] text-white/32 sm:flex-row sm:items-center">
                <span>CIHRMS Ghana Enterprise</span>
                <span>Role-based access · Secure workflows · Audit ready</span>
            </footer>
        </section>
    </main>
</template>

<style scoped>
.portal-shell {
    position: relative;
    background:
        radial-gradient(circle at 18% 18%, rgba(56, 189, 248, 0.16), transparent 28%),
        radial-gradient(circle at 80% 18%, rgba(250, 204, 21, 0.11), transparent 24%),
        linear-gradient(135deg, #050711 0%, #08111f 42%, #08090d 100%);
}

.portal-grid-bg {
    position: absolute;
    inset: 0;
    opacity: 0.11;
    background-image:
        linear-gradient(rgba(255,255,255,0.42) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.42) 1px, transparent 1px);
    background-size: 42px 42px;
    mask-image: radial-gradient(circle at center, black 0%, transparent 76%);
}

.portal-orbit {
    position: absolute;
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 999px;
    pointer-events: none;
}

.portal-orbit-one {
    right: -14rem;
    top: -16rem;
    width: 38rem;
    height: 38rem;
    animation: orbitSpin 24s linear infinite;
}

.portal-orbit-two {
    left: -22rem;
    bottom: -26rem;
    width: 52rem;
    height: 52rem;
    animation: orbitSpin 32s linear infinite reverse;
}

.brand-mark,
.featured-icon,
.card-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.brand-mark {
    width: 40px;
    height: 40px;
    border-radius: 16px;
    background: linear-gradient(135deg, #1d4ed8, #0f172a);
    box-shadow: 0 22px 50px rgba(37, 99, 235, 0.28);
}

.brand-mark span {
    font-size: 13px;
    font-weight: 950;
    letter-spacing: -0.08em;
}

.top-action {
    border-radius: 999px;
    padding: 11px 18px;
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    transition: transform 220ms ease, border-color 220ms ease, background 220ms ease, box-shadow 220ms ease;
}

.top-action:hover,
.top-action:focus-visible {
    transform: translateY(-2px);
}

.top-action-muted {
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,0.72);
}

.top-action-primary {
    border: 1px solid rgba(56,189,248,0.38);
    background: linear-gradient(135deg, rgba(37,99,235,0.95), rgba(14,165,233,0.82));
    box-shadow: 0 18px 45px rgba(14,165,233,0.22);
    color: white;
}

.intro-panel {
    opacity: 0;
    transform: translateY(24px);
    transition: opacity 650ms cubic-bezier(.22,1,.36,1), transform 650ms cubic-bezier(.22,1,.36,1);
}

.intro-panel.is-mounted {
    opacity: 1;
    transform: translateY(0);
}

.intro-chip {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 999px;
    background: rgba(255,255,255,0.055);
    padding: 9px 14px;
    color: rgba(255,255,255,0.68);
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.17em;
    text-transform: uppercase;
    backdrop-filter: blur(18px);
}

.chip-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: var(--active-accent);
    box-shadow: 0 0 22px var(--active-glow);
}

.featured-icon {
    width: 48px;
    height: 48px;
    flex: 0 0 48px;
    border-radius: 18px;
}

.featured-icon span {
    font-size: 15px;
    font-weight: 950;
    letter-spacing: -0.03em;
}

.portal-card-grid {
    display: grid;
    grid-template-columns: repeat(1, minmax(0, 1fr));
    gap: 12px;
}

.portal-card {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    min-height: 175px;
    border: 1px solid rgba(255,255,255,0.11);
    border-radius: 24px;
    background:
        linear-gradient(145deg, rgba(255,255,255,0.105), rgba(255,255,255,0.035)),
        rgba(255,255,255,0.035);
    padding: 16px;
    box-shadow: 0 22px 70px rgba(0,0,0,0.28);
    backdrop-filter: blur(18px);
    opacity: 0;
    transform: translateY(26px) scale(0.985);
    transition:
        opacity 650ms cubic-bezier(.22,1,.36,1) var(--delay),
        transform 650ms cubic-bezier(.22,1,.36,1) var(--delay),
        border-color 260ms ease,
        box-shadow 260ms ease,
        background 260ms ease;
}

.portal-card.is-mounted {
    opacity: 1;
    transform: translateY(0) scale(1);
}

.portal-card:hover,
.portal-card:focus-visible {
    transform: translateY(-6px) scale(1.014);
    border-color: color-mix(in srgb, var(--accent) 54%, white 12%);
    box-shadow: 0 30px 90px rgba(0,0,0,0.42), 0 0 60px var(--glow);
}

.card-light {
    position: absolute;
    inset: -40% -20% auto auto;
    z-index: -2;
    width: 230px;
    height: 230px;
    border-radius: 999px;
    background: var(--accent);
    opacity: 0.18;
    filter: blur(56px);
    transition: opacity 260ms ease, transform 360ms ease;
}

.portal-card:hover .card-light,
.portal-card:focus-visible .card-light {
    opacity: 0.34;
    transform: scale(1.24);
}

.card-sheen {
    position: absolute;
    inset: 0;
    z-index: -1;
    background: linear-gradient(115deg, transparent 20%, rgba(255,255,255,0.16) 46%, transparent 68%);
    transform: translateX(-130%);
    transition: transform 700ms cubic-bezier(.22,1,.36,1);
}

.portal-card:hover .card-sheen,
.portal-card:focus-visible .card-sheen {
    transform: translateX(130%);
}

.card-icon {
    width: 44px;
    height: 44px;
    border-radius: 17px;
    background: color-mix(in srgb, var(--accent) 16%, transparent);
    color: var(--accent);
    border: 1px solid color-mix(in srgb, var(--accent) 28%, transparent);
    transition: transform 260ms ease, background 260ms ease;
}

.portal-card:hover .card-icon,
.portal-card:focus-visible .card-icon {
    transform: rotate(-7deg) scale(1.1);
    background: color-mix(in srgb, var(--accent) 22%, transparent);
}

.card-icon span {
    font-size: 15px;
    font-weight: 950;
    letter-spacing: -0.03em;
}

.card-arrow {
    color: rgba(255,255,255,0.34);
    transition: color 220ms ease, transform 220ms ease;
}

.portal-card:hover .card-arrow,
.portal-card:focus-visible .card-arrow {
    color: var(--accent);
    transform: translate(3px, -3px);
}

.card-eyebrow {
    color: var(--accent);
    font-size: 9px;
    font-weight: 950;
    letter-spacing: 0.24em;
    text-transform: uppercase;
}

.metric-pill,
.action-token {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    white-space: nowrap;
}

.metric-pill {
    border: 1px solid color-mix(in srgb, var(--accent) 30%, transparent);
    background: color-mix(in srgb, var(--accent) 12%, transparent);
    color: rgba(255,255,255,0.82);
    padding: 5px 8px;
    font-size: 9.5px;
    font-weight: 850;
}

.action-token {
    background: rgba(255,255,255,0.075);
    color: rgba(255,255,255,0.5);
    padding: 4px 7px;
    font-size: 9px;
    font-weight: 800;
}

@media (min-width: 720px) {
    .portal-card-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (min-width: 1180px) {
    .portal-card-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@keyframes orbitSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
    .portal-card,
    .intro-panel,
    .portal-orbit,
    .card-sheen,
    .card-light,
    .card-icon,
    .card-arrow,
    .top-action {
        animation: none !important;
        transition: none !important;
    }
}
</style>
