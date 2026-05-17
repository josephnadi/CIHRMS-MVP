<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});

const isScrolled = ref(false);
const mounted = ref(false);
const hoveredScreen = ref(-1);

// Showcase screens (sourced from /public/images/showcase/)
const showcaseScreens = [
    {
        title:       'Executive Overview',
        tag:         'Strategic Console',
        path:        'cihrm.gov.gh/dashboard',
        description: 'A real-time pulse of the institution â€” headcount, attendance, payroll position and SLA health, surfaced in one decisive view.',
        image:       '/images/showcase/executive-overview.png',
        icon:        'space_dashboard',
        color:       '#205295',
        metaA:       'Q3 2026',
        metaB:       'Cabinet-grade KPIs',
    },
    {
        title:       'Employee Directory',
        tag:         'Workforce',
        path:        'cihrm.gov.gh/employees',
        description: 'A living register of every staff member â€” searchable, filterable, with deep profiles, performance scoring and document vaults.',
        image:       '/images/showcase/employee-management.png',
        icon:        'badge',
        color:       '#0891b2',
        metaA:       '1,284 staff',
        metaB:       'GDPR-grade vault',
    },
    {
        title:       'Performance & KPIs',
        tag:         'Analytics',
        path:        'cihrm.gov.gh/performance',
        description: 'Quarterly resilience scores, departmental efficiency indices and AI-generated executive recommendations on a single canvas.',
        image:       '/images/showcase/performance-kpis.png',
        icon:        'monitoring',
        color:       '#205295',
        metaA:       'OKR engine',
        metaB:       'AI recommendations',
    },
    {
        title:       'Service Desk',
        tag:         'Governance',
        path:        'cihrm.gov.gh/tickets',
        description: 'Internal tickets routed by SLA, prioritised by urgency and visualised on a Kanban board built for institutional cadence.',
        image:       '/images/showcase/service-desk.png',
        icon:        'support_agent',
        color:       '#059669',
        metaA:       '98.2% SLA',
        metaB:       'Kanban + escalations',
    },
];

// Live dashboard reactive data
const staffCount  = ref(1284);
const openTickets = ref(24);
const leaveCount  = ref(48);
const barHeights  = ref([65, 78, 48, 82, 72, 91, 58, 84, 76, 95, 68, 87]);
const feedIdx     = ref(0);
const chartPoints = ref([50, 38, 45, 28, 33, 18, 24, 12, 20, 10, 15, 22, 14, 8]);

const feedPool = [
    { text: 'Payroll cycle completed â€” 1,284 staff', color: '#059669' },
    { text: 'New hire onboarded: Ama Asante', color: '#0a2647' },
    { text: 'Leave approved: K. Boateng (5 days)', color: '#d97706' },
    { text: 'Ticket #SD-1028 resolved by IT', color: '#205295' },
    { text: 'Q2 Performance reports generated', color: '#0891b2' },
    { text: 'Compliance audit: Grade A+ certified', color: '#059669' },
    { text: 'Job posted: HR Business Partner', color: '#0a2647' },
    { text: 'Security audit passed â€” all clear', color: '#0891b2' },
];

const visibleFeed = computed(() =>
    Array.from({ length: 5 }, (_, i) => feedPool[(feedIdx.value + i) % feedPool.length])
);

const chartPolyPoints = computed(() => {
    const pts = chartPoints.value;
    const n = pts.length;
    return pts.map((y, i) => `${((i / (n - 1)) * 200).toFixed(1)},${((y / 60) * 38).toFixed(1)}`).join(' ');
});

const chartAreaPath = computed(() => {
    const pts = chartPoints.value;
    const n = pts.length;
    const line = pts.map((y, i) => `${((i / (n - 1)) * 200).toFixed(1)},${((y / 60) * 38).toFixed(1)}`).join(' L ');
    return `M 0,${((pts[0] / 60) * 38).toFixed(1)} L ${line} L 200,38 L 0,38 Z`;
});

const intervals = [];

onMounted(() => {
    mounted.value = true;

    window.addEventListener('scroll', () => { isScrolled.value = window.scrollY > 60; }, { passive: true });

    // Scroll-reveal observer
    const io = new IntersectionObserver(
        (entries) => entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('is-revealed'); io.unobserve(e.target); } }),
        { threshold: 0.08, rootMargin: '0px 0px -50px 0px' }
    );
    setTimeout(() => document.querySelectorAll('[data-reveal]').forEach(el => io.observe(el)), 300);

    // Animate stats
    intervals.push(setInterval(() => {
        staffCount.value  += Math.floor(Math.random() * 3) - 1;
        openTickets.value  = Math.max(20, Math.min(30, openTickets.value + Math.floor(Math.random() * 3) - 1));
        leaveCount.value   = Math.max(42, Math.min(55, leaveCount.value + Math.floor(Math.random() * 3) - 1));
    }, 3500));

    // Animate bars
    intervals.push(setInterval(() => {
        barHeights.value = barHeights.value.map(h => Math.max(20, Math.min(98, h + (Math.random() - 0.45) * 18)));
    }, 2200));

    // Animate line chart
    intervals.push(setInterval(() => {
        const last = chartPoints.value[chartPoints.value.length - 1];
        chartPoints.value = [...chartPoints.value.slice(1), Math.max(5, Math.min(55, last + (Math.random() - 0.4) * 10))];
    }, 1400));

    // Rotate feed
    intervals.push(setInterval(() => { feedIdx.value = (feedIdx.value + 1) % feedPool.length; }, 1800));
});

onBeforeUnmount(() => intervals.forEach(clearInterval));
</script>

<template>
    <Head title="CIHRM Ghana Â· Enterprise HR Management" />

    <div class="min-h-screen font-sans text-white overflow-x-hidden" style="background:#08090d;">

        <!-- Ambient background -->
        <div class="fixed inset-0 z-0 pointer-events-none overflow-hidden">
            <div class="absolute -top-1/4 -left-1/4 h-[90vh] w-[90vh] rounded-full opacity-20 animate-float"
                 style="background:radial-gradient(circle,rgba(32,82,149,0.5) 0%,transparent 65%);filter:blur(80px);"></div>
            <div class="absolute -bottom-1/4 -right-1/4 h-[80vh] w-[80vh] rounded-full opacity-15 animate-float"
                 style="background:radial-gradient(circle,rgba(124,92,255,0.6) 0%,transparent 65%);filter:blur(100px);animation-delay:-4s;"></div>
            <div class="absolute inset-0 opacity-[0.07]"
                 style="background-image:radial-gradient(rgba(255,255,255,0.8) 1px,transparent 1px);background-size:32px 32px;"></div>
        </div>

        <!-- Navigation -->
        <header class="fixed left-0 right-0 top-0 z-[100] transition-all duration-500"
                :class="isScrolled ? 'py-3' : 'py-6'"
                :style="isScrolled ? 'background:rgba(8,9,13,0.88);backdrop-filter:blur(24px);border-bottom:1px solid rgba(255,255,255,0.07);' : ''">
            <div class="mx-auto flex max-w-[1320px] items-center justify-between px-6 lg:px-10">
                <div class="flex items-center gap-3.5 group">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl shadow-glow-sm transition-all group-hover:rotate-6 group-hover:shadow-glow"
                         style="background:linear-gradient(135deg,#0a2647,#205295);">
                        <span class="material-symbols-outlined text-2xl text-white" style="font-variation-settings:'FILL' 1">account_balance</span>
                    </div>
                    <div>
                        <h1 class="text-[18px] font-black tracking-tight leading-none text-white">CIHRM <span style="color:#7cb6e8">GHANA</span></h1>
                        <p class="mt-0.5 text-[8.5px] font-bold uppercase tracking-[0.25em]" style="color:rgba(255,255,255,0.3)">Enterprise HRMS</p>
                    </div>
                </div>
                <nav v-if="canLogin" class="flex items-center gap-8">
                    <Link v-if="$page.props.auth.user" :href="route('dashboard')"
                          class="link-underline text-[13.5px] font-bold text-white/80 hover:text-white transition-colors">Go to Console</Link>
                    <template v-else>
                        <Link :href="route('login')" class="link-underline text-[13.5px] font-semibold text-white/50 hover:text-white/90 transition-colors">Sign In</Link>
                        <Link v-if="canRegister" :href="route('register')"
                              class="btn-shimmer inline-flex items-center gap-2 rounded-full px-7 py-2.5 text-[13px] font-black text-white transition-all hover:-translate-y-0.5 hover:shadow-glow active:scale-95"
                              style="background:linear-gradient(135deg,#0a2647,#205295);">
                            <span>Get Access</span>
                            <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main class="relative z-10">

            <!-- Hero -->
            <section class="flex min-h-screen flex-col items-center justify-center px-6 pt-28 pb-16 text-center lg:px-10">

                <!-- Badge -->
                <div class="mb-10 inline-flex items-center gap-2.5 rounded-full px-5 py-2 text-[10px] font-black uppercase tracking-[0.2em]"
                     style="background:rgba(32,82,149,0.12);border:1px solid rgba(59,130,246,0.25);color:#93c5fd;"
                     :class="mounted ? 'animate-reveal-up' : 'opacity-0'">
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full animate-ping-slow rounded-full bg-blue-400 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-blue-400"></span>
                    </span>
                    Mandated by Act 1020 Â· Certified Institutional Platform
                </div>

                <!-- Headline -->
                <h2 class="max-w-[1100px]" :class="mounted ? 'animate-reveal-up' : 'opacity-0'" style="animation-delay:0.15s;">
                    <span class="block text-5xl font-extrabold tracking-tighter leading-[1.02] sm:text-7xl lg:text-[7.5rem]">The Future of</span>
                    <span class="mt-2 block font-serif italic leading-none sm:text-8xl lg:text-[9rem] text-gradient">HR Governance.</span>
                </h2>

                <p class="mt-10 max-w-2xl text-[17px] font-medium leading-relaxed"
                   style="color:rgba(255,255,255,0.45);animation-delay:0.3s;"
                   :class="mounted ? 'animate-reveal-up' : 'opacity-0'">
                    Elevating institutional human resource management to global standards.
                    Secure, intelligent, and precisely engineered for the Chartered Institute.
                </p>

                <!-- CTAs -->
                <div class="mt-12 flex flex-wrap justify-center gap-4"
                     :class="mounted ? 'animate-reveal-up' : 'opacity-0'" style="animation-delay:0.45s;">
                    <Link v-if="$page.props.auth.user" :href="route('dashboard')"
                          class="btn-shimmer inline-flex items-center gap-2.5 rounded-full px-10 py-4 text-[14px] font-black text-white shadow-glow transition-all hover:-translate-y-1 hover:shadow-glow-lg active:scale-95"
                          style="background:linear-gradient(135deg,#0a2647,#205295);">
                        <span class="material-symbols-outlined text-[18px]" style="font-variation-settings:'FILL' 1">rocket_launch</span>
                        Enter Enterprise Console
                    </Link>
                    <template v-else>
                        <Link :href="route('register')"
                              class="btn-shimmer inline-flex items-center gap-2.5 rounded-full px-10 py-4 text-[14px] font-black text-white shadow-glow transition-all hover:-translate-y-1 hover:shadow-glow-lg active:scale-95"
                              style="background:linear-gradient(135deg,#0a2647,#205295);">
                            <span class="material-symbols-outlined text-[18px]" style="font-variation-settings:'FILL' 1">person_add</span>
                            Join the Institute
                        </Link>
                        <Link :href="route('login')"
                              class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-10 py-4 text-[14px] font-black text-white transition-all hover:bg-white/10 hover:border-white/20 active:scale-95">
                            Sign In <span class="material-symbols-outlined text-[17px]">arrow_forward</span>
                        </Link>
                    </template>
                </div>

                <!-- â”€â”€ Live Animated Dashboard Mockup â”€â”€ -->
                <div class="mt-20 w-full max-w-5xl" :class="mounted ? 'animate-reveal-up' : 'opacity-0'" style="animation-delay:0.6s;">
                    <div class="relative overflow-hidden rounded-[2rem]"
                         style="border:1px solid rgba(255,255,255,0.08);background:#0a2647;box-shadow:0 40px 120px rgba(0,0,0,0.7),0 0 0 1px rgba(255,255,255,0.04);">

                        <!-- Browser chrome -->
                        <div class="flex items-center gap-2 px-5 py-3.5 border-b" style="border-color:rgba(255,255,255,0.06);background:#090a0f;">
                            <span class="h-3 w-3 rounded-full" style="background:rgba(239,68,68,0.75)"></span>
                            <span class="h-3 w-3 rounded-full" style="background:rgba(245,158,11,0.75)"></span>
                            <span class="h-3 w-3 rounded-full" style="background:rgba(34,197,94,0.75)"></span>
                            <div class="mx-auto w-52 rounded-lg px-3 py-1.5 text-[11px] text-center" style="background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.25);">
                                cihrm.gov.gh/dashboard
                            </div>
                            <div class="ml-auto flex items-center gap-1.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-green-400 live-pulse"></span>
                                <span class="text-[9px] font-black uppercase tracking-widest" style="color:rgba(74,222,128,0.8)">LIVE</span>
                            </div>
                        </div>

                        <!-- Dashboard body -->
                        <div class="flex" style="height:460px;overflow:hidden;">

                            <!-- Sidebar -->
                            <div class="flex-shrink-0 flex flex-col border-r p-3" style="width:158px;background:#0a2647;border-color:rgba(255,255,255,0.05);">
                                <div class="flex items-center gap-2 px-2 py-2 mb-4">
                                    <div class="h-6 w-6 rounded-lg flex items-center justify-center flex-shrink-0" style="background:linear-gradient(135deg,#0a2647,#205295)">
                                        <span class="material-symbols-outlined text-white" style="font-size:13px;font-variation-settings:'FILL' 1">account_balance</span>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-black text-white leading-none">CIHRM</p>
                                        <p class="text-[6.5px] font-bold uppercase tracking-widest" style="color:rgba(255,255,255,0.3)">Ghana</p>
                                    </div>
                                </div>
                                <div class="space-y-0.5 flex-1">
                                    <div v-for="(item, i) in [
                                        { icon: 'grid_view',     label: 'Overview',    active: true },
                                        { icon: 'badge',         label: 'Employees',   active: false },
                                        { icon: 'calendar_month',label: 'Leave',       active: false },
                                        { icon: 'payments',      label: 'Payroll',     active: false },
                                        { icon: 'monitoring',    label: 'Performance', active: false },
                                        { icon: 'support_agent', label: 'Service Desk',active: false },
                                        { icon: 'person_add',    label: 'Recruitment', active: false },
                                    ]" :key="i"
                                         class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 cursor-default"
                                         :style="item.active ? 'background:rgba(32,82,149,0.22);border:1px solid rgba(59,130,246,0.25)' : 'border:1px solid transparent'">
                                        <span class="material-symbols-outlined" :style="`font-size:13px;${item.active ? 'color:#7cb6e8;font-variation-settings:\'FILL\' 1' : 'color:rgba(255,255,255,0.28)'}`">{{ item.icon }}</span>
                                        <span class="text-[9px] font-semibold truncate" :style="item.active ? 'color:white' : 'color:rgba(255,255,255,0.25)'">{{ item.label }}</span>
                                        <span v-if="item.active" class="ml-auto h-1 w-1 rounded-full bg-blue-400 flex-shrink-0"></span>
                                    </div>
                                </div>
                                <div class="mt-auto pt-2 border-t" style="border-color:rgba(255,255,255,0.05)">
                                    <div class="flex items-center gap-2 px-2 py-1.5 rounded-lg" style="background:rgba(255,255,255,0.04)">
                                        <div class="h-5 w-5 rounded-full flex items-center justify-center text-[7px] font-black text-white flex-shrink-0" style="background:linear-gradient(135deg,#0a2647,#205295)">A</div>
                                        <div class="min-w-0">
                                            <p class="text-[8px] font-bold text-white truncate">Admin User</p>
                                            <p class="text-[6.5px]" style="color:rgba(255,255,255,0.3)">Super Admin</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Main content -->
                            <div class="flex-1 flex flex-col p-4 gap-3 overflow-hidden relative" style="background:#111318;">
                                <!-- Scan line sweep effect -->
                                <div class="scan-sweep pointer-events-none"></div>

                                <!-- Header row -->
                                <div class="flex items-center justify-between flex-shrink-0">
                                    <div>
                                        <p class="text-sm font-black text-white leading-none">Executive Overview</p>
                                        <p class="text-[9px] mt-0.5" style="color:rgba(255,255,255,0.3)">Real-time institutional intelligence</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <div class="flex items-center gap-1.5 rounded-full px-2.5 py-1" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2)">
                                            <span class="h-1.5 w-1.5 rounded-full bg-green-400 live-pulse"></span>
                                            <span class="text-[8px] font-black uppercase text-green-400">Live Data</span>
                                        </div>
                                        <span class="text-[8px] font-bold" style="color:rgba(255,255,255,0.2)">May 13, 2026</span>
                                    </div>
                                </div>

                                <!-- Stat cards -->
                                <div class="grid grid-cols-4 gap-2.5 flex-shrink-0">
                                    <div v-for="(stat, i) in [
                                        { label: 'Active Staff',    val: staffCount,  icon: 'badge',         rgb: '32,82,149' },
                                        { label: 'Monthly Payroll', val: 'GHS 2.4M', icon: 'payments',       rgb: '5,150,105' },
                                        { label: 'Leave Requests',  val: leaveCount,  icon: 'calendar_today', rgb: '217,119,6' },
                                        { label: 'Open Tickets',    val: openTickets, icon: 'support_agent',  rgb: '217,18,227' },
                                    ]" :key="i"
                                         class="rounded-xl p-2.5 relative overflow-hidden"
                                         :style="`background:rgba(${stat.rgb},0.07);border:1px solid rgba(${stat.rgb},0.15)`">
                                        <div class="h-5 w-5 rounded-md flex items-center justify-center mb-1.5" :style="`background:rgba(${stat.rgb},0.2)`">
                                            <span class="material-symbols-outlined" :style="`font-size:11px;color:rgb(${stat.rgb});font-variation-settings:'FILL' 1`">{{ stat.icon }}</span>
                                        </div>
                                        <p class="text-[7px] font-bold uppercase tracking-wider" style="color:rgba(255,255,255,0.38)">{{ stat.label }}</p>
                                        <p class="text-[17px] font-black text-white leading-tight" style="transition:all 0.6s ease">
                                            {{ typeof stat.val === 'number' ? stat.val.toLocaleString() : stat.val }}
                                        </p>
                                        <div class="absolute bottom-0 left-0 h-0.5 rounded-full" :style="`width:${[72,88,45,60][i]}%;background:rgba(${stat.rgb},0.6)`"></div>
                                    </div>
                                </div>

                                <!-- Charts row -->
                                <div class="flex gap-3 flex-1 min-h-0">

                                    <!-- Bar + Line chart -->
                                    <div class="flex-1 rounded-xl p-3 flex flex-col overflow-hidden" style="background:rgba(255,255,255,0.025);border:1px solid rgba(255,255,255,0.05);">
                                        <div class="flex items-center justify-between mb-2 flex-shrink-0">
                                            <p class="text-[8.5px] font-black uppercase tracking-wider" style="color:rgba(255,255,255,0.5)">Workforce Analytics Â· 2026</p>
                                            <div class="flex items-center gap-3">
                                                <div class="flex items-center gap-1">
                                                    <span class="h-1.5 w-3 rounded-full bg-secondary opacity-80"></span>
                                                    <span class="text-[6.5px] text-white/30">Headcount</span>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <span class="h-1.5 w-3 rounded-full bg-blue-400 opacity-80"></span>
                                                    <span class="text-[6.5px] text-white/30">Productivity</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Bar chart -->
                                        <div class="flex items-end gap-1 flex-shrink-0" style="height:90px;">
                                            <div v-for="(h, i) in barHeights" :key="i"
                                                 class="flex-1 rounded-t relative overflow-hidden"
                                                 :style="`height:${h}%;background:rgba(32,82,149,0.12);transition:height 0.9s cubic-bezier(0.22,1,0.36,1);`">
                                                <div class="absolute inset-0 rounded-t" style="background:linear-gradient(to top,#0a2647,rgba(59,130,246,0.7));"></div>
                                            </div>
                                        </div>
                                        <div class="flex justify-between mt-1 flex-shrink-0">
                                            <span v-for="m in ['J','F','M','A','M','J','J','A','S','O','N','D']" :key="m"
                                                  class="flex-1 text-center text-[5.5px] font-bold" style="color:rgba(255,255,255,0.18)">{{ m }}</span>
                                        </div>

                                        <!-- SVG line chart -->
                                        <div class="flex-1 flex flex-col justify-end mt-3 pt-2 border-t" style="border-color:rgba(255,255,255,0.05)">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <p class="text-[7.5px] font-black uppercase" style="color:rgba(255,255,255,0.35)">Performance Trend</p>
                                                <span class="text-[8px] font-black" style="color:#059669">â†‘ +12.4% vs last mo</span>
                                            </div>
                                            <svg viewBox="0 0 200 38" class="w-full" style="height:52px;overflow:visible;">
                                                <defs>
                                                    <linearGradient id="areaG" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="0%" stop-color="#205295" stop-opacity="0.28"/>
                                                        <stop offset="100%" stop-color="#205295" stop-opacity="0.02"/>
                                                    </linearGradient>
                                                    <clipPath id="cClip"><rect x="0" y="0" width="200" height="38"/></clipPath>
                                                </defs>
                                                <g clip-path="url(#cClip)">
                                                    <path :d="chartAreaPath" fill="url(#areaG)"/>
                                                    <polyline :points="chartPolyPoints" fill="none" stroke="#205295" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <!-- Live dot -->
                                                    <circle :cx="200" :cy="((chartPoints[chartPoints.length-1]/60)*38).toFixed(1)" r="2.5" fill="#205295"/>
                                                    <circle :cx="200" :cy="((chartPoints[chartPoints.length-1]/60)*38).toFixed(1)" r="5" fill="rgba(59,130,246,0.25)" class="chart-ping"/>
                                                </g>
                                            </svg>
                                        </div>
                                    </div>

                                    <!-- Right panel -->
                                    <div class="flex-shrink-0 flex flex-col gap-2.5" style="width:168px;">

                                        <!-- Donut chart -->
                                        <div class="rounded-xl p-3 flex-shrink-0" style="background:rgba(255,255,255,0.025);border:1px solid rgba(255,255,255,0.05);">
                                            <p class="text-[7.5px] font-black uppercase mb-2.5" style="color:rgba(255,255,255,0.4)">Workforce Composition</p>
                                            <div class="flex items-center gap-3">
                                                <div class="relative flex-shrink-0" style="height:56px;width:56px;">
                                                    <svg viewBox="0 0 36 36" class="w-full h-full" style="transform:rotate(-90deg)">
                                                        <circle cx="18" cy="18" r="13" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="5"/>
                                                        <circle cx="18" cy="18" r="13" fill="none" stroke="#0a2647"  stroke-width="5" stroke-dasharray="58 100" stroke-linecap="round" class="donut-arc-1"/>
                                                        <circle cx="18" cy="18" r="13" fill="none" stroke="#205295" stroke-width="5" stroke-dasharray="25 100" stroke-dashoffset="-58" stroke-linecap="round" class="donut-arc-2"/>
                                                        <circle cx="18" cy="18" r="13" fill="none" stroke="#059669" stroke-width="5" stroke-dasharray="14 100" stroke-dashoffset="-83" stroke-linecap="round" class="donut-arc-3"/>
                                                    </svg>
                                                    <div class="absolute inset-0 flex items-center justify-center">
                                                        <span class="text-[10px] font-black text-white">97%</span>
                                                    </div>
                                                </div>
                                                <div class="space-y-1.5">
                                                    <div v-for="(d,i) in [{ c:'#0a2647',l:'Senior 58%' },{ c:'#205295',l:'Mid-level 25%' },{ c:'#059669',l:'Junior 14%' }]" :key="i" class="flex items-center gap-1.5">
                                                        <span class="h-1.5 w-1.5 rounded-full flex-shrink-0" :style="`background:${d.c}`"></span>
                                                        <span class="text-[7px]" style="color:rgba(255,255,255,0.35)">{{ d.l }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Activity feed -->
                                        <div class="flex-1 rounded-xl p-3 flex flex-col overflow-hidden" style="background:rgba(255,255,255,0.025);border:1px solid rgba(255,255,255,0.05);">
                                            <div class="flex items-center justify-between mb-2 flex-shrink-0">
                                                <p class="text-[7.5px] font-black uppercase" style="color:rgba(255,255,255,0.4)">Live Activity</p>
                                                <span class="h-1.5 w-1.5 rounded-full bg-green-400 live-pulse flex-shrink-0"></span>
                                            </div>
                                            <div class="flex-1 overflow-hidden relative">
                                                <div class="absolute top-0 left-0 right-0 h-4 z-10 pointer-events-none" style="background:linear-gradient(to bottom,rgba(17,19,24,1),transparent)"></div>
                                                <TransitionGroup name="feed" tag="div" class="space-y-2">
                                                    <div v-for="item in visibleFeed" :key="item.text" class="flex items-start gap-1.5">
                                                        <span class="h-1.5 w-1.5 rounded-full flex-shrink-0 mt-1" :style="`background:${item.color}`"></span>
                                                        <span class="text-[7px] font-medium leading-snug" style="color:rgba(255,255,255,0.38)">{{ item.text }}</span>
                                                    </div>
                                                </TransitionGroup>
                                            </div>
                                        </div>

                                        <!-- SLA metric -->
                                        <div class="rounded-xl px-3 py-2.5 flex items-center justify-between flex-shrink-0" style="background:linear-gradient(135deg,rgba(32,82,149,0.18),rgba(59,130,246,0.1));border:1px solid rgba(59,130,246,0.25)">
                                            <div>
                                                <p class="text-[6.5px] font-black uppercase" style="color:rgba(255,255,255,0.4)">SLA Compliance</p>
                                                <p class="text-[17px] font-black text-white leading-tight">98.2%</p>
                                            </div>
                                            <span class="material-symbols-outlined" style="font-size:20px;color:#205295;font-variation-settings:'FILL' 1">verified</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Glow under the mockup -->
                    <div class="pointer-events-none absolute -bottom-12 left-1/2 -translate-x-1/2 h-32 w-2/3 rounded-full opacity-30 blur-3xl"
                         style="background:radial-gradient(ellipse,rgba(32,82,149,0.7),transparent 70%);"></div>
                </div>
            </section>

            <!-- Feature Grid -->
            <section class="relative py-32 px-6 lg:px-10">
                <div class="mx-auto max-w-[1320px]">
                    <div class="mb-16 text-center" data-reveal>
                        <p class="mb-4 text-[11px] font-black uppercase tracking-[0.25em]" style="color:rgba(255,255,255,0.3)">Platform Capabilities</p>
                        <h2 class="text-5xl font-extrabold tracking-tight lg:text-6xl">Everything your institution needs.</h2>
                    </div>
                    <div class="grid gap-px overflow-hidden rounded-[2.5rem] lg:grid-cols-3"
                         style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.06);">
                        <div v-for="(feature, idx) in [
                            { title: 'Institutional Records',   desc: 'Secure, immutable employee digital profiles with full lifecycle tracking and compliance-grade document management.', icon: 'shield_person', color: '#0a2647' },
                            { title: 'Strategic Analytics',     desc: 'Real-time performance dashboards, OKR tracking, and predictive workforce insights powered by institutional data.', icon: 'analytics',      color: '#205295' },
                            { title: 'Service Governance',      desc: 'Enterprise service desk with SLA enforcement, Kanban workflows, and automated escalation routing.',                icon: 'support_agent',  color: '#059669' },
                            { title: 'Payroll & Compliance',   desc: 'Automated payroll processing with statutory deductions, SSNIT integration, and full audit trails.',                icon: 'payments',       color: '#d97706' },
                            { title: 'Talent Acquisition',     desc: 'End-to-end recruitment pipeline from job postings to onboarding with candidate tracking.',                         icon: 'person_search',  color: '#0891b2' },
                            { title: 'Learning & Growth',      desc: 'Institutional learning paths, certification tracking, and professional development subsidy management.',            icon: 'school',         color: '#205295' },
                        ]" :key="idx"
                             data-reveal
                             :style="`transition-delay:${idx * 0.07}s`"
                             class="group relative overflow-hidden p-10 transition-all duration-300 hover:z-10"
                             style="background:#08090d;"
                             onmouseenter="this.style.background='rgba(255,255,255,0.02)'"
                             onmouseleave="this.style.background='#08090d'">
                            <div class="mb-8 flex h-14 w-14 items-center justify-center rounded-2xl transition-transform duration-300 group-hover:-rotate-6 group-hover:scale-110"
                                 :style="`background:${feature.color}20;border:1px solid ${feature.color}30;`">
                                <span class="material-symbols-outlined text-2xl" :style="`color:${feature.color};font-variation-settings:'FILL' 1`">{{ feature.icon }}</span>
                            </div>
                            <h3 class="text-[22px] font-bold tracking-tight text-white">{{ feature.title }}</h3>
                            <p class="mt-4 text-[15px] leading-relaxed" style="color:rgba(255,255,255,0.4)">{{ feature.desc }}</p>
                            <div class="mt-8 flex items-center gap-2 text-[11px] font-black uppercase tracking-widest transition-all group-hover:gap-3" :style="`color:${feature.color}`">
                                Explore <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Product Showcase: real screens from docs/ -->
            <section class="relative px-6 py-32 lg:px-10">
                <div class="mx-auto max-w-[1320px]">

                    <div class="mb-16 text-center" data-reveal>
                        <p class="mb-4 text-[11px] font-black uppercase tracking-[0.25em]" style="color:rgba(255,255,255,0.3)">A Glimpse of the Console</p>
                        <h2 class="text-5xl font-extrabold tracking-tight lg:text-6xl">
                            Engineered for <span class="font-serif italic text-gradient">institutional clarity.</span>
                        </h2>
                        <p class="mx-auto mt-6 max-w-2xl text-[16px] font-medium" style="color:rgba(255,255,255,0.4)">
                            Four flagship surfaces from the CIHRM enterprise console â€” every pixel measured to the same standard.
                        </p>
                    </div>

                    <!-- Showcase grid -->
                    <div class="grid gap-6 lg:grid-cols-2">
                        <a
                            v-for="(s, i) in showcaseScreens" :key="s.title"
                            href="#"
                            data-reveal
                            :style="`transition-delay:${i * 0.08}s`"
                            class="group relative block overflow-hidden rounded-[2rem] border transition-all duration-500 hover:-translate-y-1.5"
                            style="border-color:rgba(255,255,255,0.07);background:linear-gradient(135deg,#0a2647,#131620);"
                            @mouseenter="hoveredScreen = i" @mouseleave="hoveredScreen = -1"
                        >
                            <!-- Cobalt aura on hover -->
                            <div class="pointer-events-none absolute inset-0 -z-10 opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                                 style="background:radial-gradient(60% 60% at 50% 0%,rgba(59,130,246,0.18),transparent 70%);"></div>

                            <!-- Inner gradient border -->
                            <div class="pointer-events-none absolute inset-0 rounded-[2rem]"
                                 :style="hoveredScreen === i ? 'box-shadow:inset 0 0 0 1px rgba(59,130,246,0.4),0 0 60px rgba(32,82,149,0.18);' : 'box-shadow:inset 0 0 0 1px rgba(255,255,255,0.04);'"
                                 style="transition:box-shadow 0.4s ease;"></div>

                            <!-- Header with chip -->
                            <div class="flex items-center justify-between px-7 pt-6">
                                <div class="flex items-center gap-2 rounded-full px-3 py-1"
                                     :style="`background:${s.color}18;border:1px solid ${s.color}30;`">
                                    <span class="material-symbols-outlined text-[13px]"
                                          :style="`color:${s.color};font-variation-settings:'FILL' 1`">{{ s.icon }}</span>
                                    <span class="text-[10px] font-black uppercase tracking-[0.18em]" :style="`color:${s.color}`">{{ s.tag }}</span>
                                </div>
                                <span class="material-symbols-outlined text-[18px] transition-all duration-300 group-hover:translate-x-1 group-hover:text-blue-300"
                                      style="color:rgba(255,255,255,0.25)">arrow_outward</span>
                            </div>

                            <!-- Title and description -->
                            <div class="px-7 pt-4 pb-6">
                                <h3 class="text-[26px] font-bold tracking-tight text-white leading-tight">{{ s.title }}</h3>
                                <p class="mt-2 text-[14px] leading-relaxed" style="color:rgba(255,255,255,0.4)">{{ s.description }}</p>
                            </div>

                            <!-- Image with browser chrome -->
                            <div class="relative mx-7 mb-8 overflow-hidden rounded-[1.25rem] border"
                                 style="border-color:rgba(255,255,255,0.08);background:#08090d;">
                                <!-- Mini chrome -->
                                <div class="flex items-center gap-1.5 border-b px-3 py-2"
                                     style="border-color:rgba(255,255,255,0.05);background:rgba(255,255,255,0.02);">
                                    <span class="h-2 w-2 rounded-full" style="background:rgba(239,68,68,0.6)"></span>
                                    <span class="h-2 w-2 rounded-full" style="background:rgba(245,158,11,0.6)"></span>
                                    <span class="h-2 w-2 rounded-full" style="background:rgba(34,197,94,0.6)"></span>
                                    <span class="ml-3 truncate text-[9px] font-medium tracking-wider" style="color:rgba(255,255,255,0.25)">{{ s.path }}</span>
                                </div>
                                <!-- Image -->
                                <div class="relative overflow-hidden" style="aspect-ratio:4/3;">
                                    <img
                                        :src="s.image"
                                        :alt="s.title"
                                        loading="lazy"
                                        class="h-full w-full object-cover object-top transition-all duration-700 ease-out group-hover:scale-[1.04]"
                                        style="filter:saturate(1.05);"
                                    />
                                    <!-- Vignette overlay -->
                                    <div class="pointer-events-none absolute inset-0"
                                         style="background:linear-gradient(180deg,transparent 60%,rgba(8,9,13,0.45) 100%);"></div>
                                    <!-- Cobalt glow swipe on hover -->
                                    <div class="pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                                         style="background:linear-gradient(115deg,transparent 35%,rgba(59,130,246,0.12) 50%,transparent 65%);"></div>
                                </div>
                            </div>

                            <!-- Footer caption row -->
                            <div class="flex items-center justify-between px-7 pb-6 text-[11px] font-bold uppercase tracking-[0.18em]"
                                 style="color:rgba(255,255,255,0.3)">
                                <div class="flex items-center gap-3">
                                    <span class="flex items-center gap-1.5">
                                        <span class="h-1 w-1 rounded-full" :style="`background:${s.color}`"></span>
                                        {{ s.metaA }}
                                    </span>
                                    <span class="opacity-30">Â·</span>
                                    <span>{{ s.metaB }}</span>
                                </div>
                                <span class="flex items-center gap-1 transition-all duration-300 group-hover:gap-2"
                                      :style="`color:${s.color}`">View module
                                    <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                </span>
                            </div>
                        </a>
                    </div>

                    <!-- Caption strip -->
                    <p class="mt-10 text-center text-[12px] font-medium" style="color:rgba(255,255,255,0.25)">
                        Live console screens â€” exported from the CIHRM Ghana production design system.
                    </p>
                </div>
            </section>

            <!-- Stats / Credibility -->
            <section class="relative overflow-hidden py-28 px-6 lg:px-10">
                <div class="mx-auto max-w-[1320px]">
                    <div class="overflow-hidden rounded-[3rem] text-black" style="background:#ffffff;position:relative;" data-reveal>
                        <div class="absolute right-12 top-12 opacity-[0.04] pointer-events-none select-none">
                            <span class="material-symbols-outlined text-[20rem]" style="font-variation-settings:'FILL' 1">verified</span>
                        </div>
                        <div class="h-1.5 w-full" style="background:linear-gradient(90deg,#0a2647,#205295,#205295,#12d9e3);"></div>
                        <div class="relative z-10 p-14 lg:p-20">
                            <div class="lg:flex items-end justify-between gap-20">
                                <div class="max-w-2xl">
                                    <p class="mb-6 text-[10px] font-black uppercase tracking-[0.35em] text-black/30">Excellence Mandate</p>
                                    <h2 class="text-[3.5rem] font-extrabold leading-[1.0] tracking-tight lg:text-[5rem]">
                                        Built for<br/><span class="font-serif italic text-gradient">The Elite.</span>
                                    </h2>
                                    <p class="mt-8 text-[17px] font-medium leading-relaxed text-black/55">
                                        We've engineered CIHRM Ghana's enterprise HRMS to handle complex institutional workflows with the same precision used by global financial and governmental institutions.
                                    </p>
                                </div>
                                <div class="mt-16 lg:mt-0 flex flex-col gap-0 divide-y divide-black/8">
                                    <div v-for="(stat, i) in [
                                        { val: 'Act 1020', label: 'Chartered Mandate',    icon: 'gavel' },
                                        { val: '99.9%',    label: 'Platform Uptime',      icon: 'electric_bolt' },
                                        { val: 'A+',       label: 'Security Grade',       icon: 'verified_user' },
                                        { val: '12+',      label: 'HR Modules',           icon: 'dashboard' },
                                    ]" :key="i"
                                         class="flex items-center gap-6 py-7 group"
                                         data-reveal
                                         :style="`transition-delay:${i * 0.1}s`">
                                        <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl transition-transform group-hover:scale-110" style="background:rgba(32,82,149,0.08);">
                                            <span class="material-symbols-outlined text-xl" style="color:#0a2647;font-variation-settings:'FILL' 1">{{ stat.icon }}</span>
                                        </div>
                                        <div>
                                            <p class="text-4xl font-black tracking-tight text-black">{{ stat.val }}</p>
                                            <p class="mt-0.5 text-[10px] font-black uppercase tracking-[0.18em] text-black/35">{{ stat.label }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- CTA -->
            <section class="py-28 px-6 text-center lg:px-10" data-reveal>
                <div class="mx-auto max-w-3xl">
                    <h2 class="text-5xl font-extrabold tracking-tight lg:text-6xl">
                        Ready to transform<br/>
                        <span class="font-serif italic text-gradient">your HR institution?</span>
                    </h2>
                    <p class="mt-6 text-[16px] font-medium" style="color:rgba(255,255,255,0.4)">
                        Join CIHRM Ghana's enterprise platform and elevate your workforce management.
                    </p>
                    <div class="mt-10 flex flex-wrap justify-center gap-4">
                        <Link :href="route('register')"
                              class="btn-shimmer inline-flex items-center gap-2.5 rounded-full px-12 py-5 text-[15px] font-black text-white shadow-glow-lg transition-all hover:-translate-y-1 active:scale-95"
                              style="background:linear-gradient(135deg,#0a2647,#205295);">
                            <span class="material-symbols-outlined text-[19px]" style="font-variation-settings:'FILL' 1">rocket_launch</span>
                            Get Started Now
                        </Link>
                        <Link :href="route('login')"
                              class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-12 py-5 text-[15px] font-black text-white transition-all hover:bg-white/10 active:scale-95">
                            Sign In
                        </Link>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="relative z-10 border-t px-6 py-16 lg:px-10" style="border-color:rgba(255,255,255,0.05);">
            <div class="mx-auto max-w-[1320px]">
                <div class="flex flex-col items-center justify-between gap-10 lg:flex-row">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl" style="background:linear-gradient(135deg,#0a2647,#205295);">
                            <span class="material-symbols-outlined text-xl text-white" style="font-variation-settings:'FILL' 1">account_balance</span>
                        </div>
                        <span class="text-[13px] font-black tracking-tight text-white/70">Â© 2026 CIHRM Ghana Enterprise</span>
                    </div>
                    <nav class="flex flex-wrap justify-center gap-8">
                        <a v-for="link in ['Privacy Charter', 'Terms of Governance', 'Security Audit', 'Technical Support']" :key="link"
                           href="#" class="link-underline text-[11px] font-bold uppercase tracking-[0.15em] transition-colors"
                           style="color:rgba(255,255,255,0.3);"
                           onmouseenter="this.style.color='rgba(255,255,255,0.7)'"
                           onmouseleave="this.style.color='rgba(255,255,255,0.3)'">{{ link }}</a>
                    </nav>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* â”€â”€â”€ Scroll Reveal â”€â”€â”€ */
[data-reveal] {
    opacity: 0;
    transform: translateY(40px);
    transition: opacity 0.75s cubic-bezier(0.22, 1, 0.36, 1),
                transform 0.75s cubic-bezier(0.22, 1, 0.36, 1);
}
[data-reveal].is-revealed {
    opacity: 1;
    transform: translateY(0);
}

/* â”€â”€â”€ Hero entrance â”€â”€â”€ */
.animate-reveal-up {
    animation: revealUp 0.9s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes revealUp {
    0%   { opacity: 0; transform: translateY(50px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* â”€â”€â”€ Scan line sweep across dashboard â”€â”€â”€ */
.scan-sweep {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
}
.scan-sweep::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    width: 120px;
    background: linear-gradient(90deg, transparent, rgba(49, 107, 243, 0.06), transparent);
    animation: sweepX 5s ease-in-out infinite;
}
@keyframes sweepX {
    0%   { left: -120px; }
    70%  { left: 100%; }
    100% { left: 100%; }
}

/* â”€â”€â”€ Live pulse â”€â”€â”€ */
.live-pulse {
    animation: livePulse 1.4s ease-in-out infinite;
}
@keyframes livePulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.3; }
}

/* â”€â”€â”€ Donut arcs draw in â”€â”€â”€ */
.donut-arc-1 {
    stroke-dasharray: 0 100;
    animation: arc1 1.4s cubic-bezier(0.22, 1, 0.36, 1) forwards 0.4s;
}
.donut-arc-2 {
    stroke-dasharray: 0 100;
    animation: arc2 1.4s cubic-bezier(0.22, 1, 0.36, 1) forwards 0.65s;
}
.donut-arc-3 {
    stroke-dasharray: 0 100;
    animation: arc3 1.4s cubic-bezier(0.22, 1, 0.36, 1) forwards 0.85s;
}
@keyframes arc1 { to { stroke-dasharray: 58 100; } }
@keyframes arc2 { to { stroke-dasharray: 25 100; } }
@keyframes arc3 { to { stroke-dasharray: 14 100; } }

/* â”€â”€â”€ Chart pinging dot â”€â”€â”€ */
.chart-ping {
    animation: chartPing 2s ease-out infinite;
}
@keyframes chartPing {
    0%   { r: 2.5; opacity: 0.6; }
    100% { r: 8;   opacity: 0; }
}

/* â”€â”€â”€ Activity feed transition â”€â”€â”€ */
.feed-enter-active { transition: all 0.4s ease; }
.feed-leave-active { transition: all 0.3s ease; position: absolute; }
.feed-enter-from   { opacity: 0; transform: translateY(-8px); }
.feed-leave-to     { opacity: 0; transform: translateY(8px); }
</style>
