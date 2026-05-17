<script setup>
import { router } from '@inertiajs/vue3';
import Sparkline from '@/Components/charts/Sparkline.vue';

const props = defineProps({
    spark:   { type: Object,  required: true },   // deptSparkData.it slice
    tickets: { type: Array,   default: () => [] },
});
</script>

<template>
    <div class="space-y-6 animate-reveal-up">

        <!-- Hero Banner -->
        <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white"
             style="background:linear-gradient(135deg,#0c0e14 0%,#111827 100%);border:1px solid rgba(255,255,255,0.06);">
            <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(32,82,149,0.25),transparent 70%)"></div>
            <div class="relative flex flex-wrap items-center justify-between gap-6">
                <div class="flex items-center gap-5">
                    <div class="h-14 w-14 rounded-2xl flex items-center justify-center flex-shrink-0" style="background:rgba(44,116,179,0.2);border:1px solid rgba(44,116,179,0.3)">
                        <span class="material-symbols-outlined text-3xl text-blue-400" style="font-variation-settings:'FILL' 1">computer</span>
                    </div>
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-1" style="color:rgba(255,255,255,0.3)">Department</p>
                        <h2 class="text-2xl font-black leading-tight">IT &amp; Technology</h2>
                        <p class="text-sm font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Infrastructure · Support · Security · Development</p>
                    </div>
                </div>
                <div class="flex items-center gap-10 flex-shrink-0">
                    <div v-for="m in [
                        { label: 'Team Members',   val: '42' },
                        { label: 'Servers Online', val: Math.round(spark.servers[spark.servers.length-1]) },
                        { label: 'Uptime SLA',     val: spark.uptime[spark.uptime.length-1].toFixed(2) + '%' },
                    ]" :key="m.label" class="text-center">
                        <p class="text-3xl font-black leading-none kpi-val">{{ m.val }}</p>
                        <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.3)">{{ m.label }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div v-for="(card, i) in [
                { label: 'Servers Online',  display: Math.round(spark.servers[spark.servers.length-1]) + ' / 24', trend: '100% capacity', color: '#12d9e3', rgb: '18,217,227',  icon: 'dns',           up: true,  spark: spark.servers },
                { label: 'Open IT Tickets', display: Math.round(spark.tickets[spark.tickets.length-1]),            trend: '3 critical',    color: '#dc2626', rgb: '220,38,38',   icon: 'bug_report',    up: false, spark: spark.tickets },
                { label: 'Security Alerts', display: Math.round(spark.alerts[spark.alerts.length-1]),              trend: 'Low severity',  color: '#d97706', rgb: '217,119,6',   icon: 'security',      up: false, spark: spark.alerts  },
                { label: 'Uptime SLA',      display: spark.uptime[spark.uptime.length-1].toFixed(2) + '%',         trend: 'Target: 99.9%', color: '#ffd700', rgb: '255,215,0',   icon: 'electric_bolt', up: true,  spark: spark.uptime  },
            ]" :key="i"
                 class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                 :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.07}s`">
                <div class="absolute right-3.5 top-3.5 flex items-center gap-1">
                    <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${card.color}`"></span>
                    <span class="text-[7.5px] font-black uppercase tracking-widest" :style="`color:${card.color};opacity:0.65`">live</span>
                </div>
                <div class="mb-3 h-9 w-9 rounded-xl flex items-center justify-center" :style="`background:rgba(${card.rgb},0.1)`">
                    <span class="material-symbols-outlined text-[18px]" :style="`color:${card.color};font-variation-settings:'FILL' 1`">{{ card.icon }}</span>
                </div>
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                <p class="mt-1.5 text-2xl font-black text-primary leading-none kpi-val">{{ card.display }}</p>
                <p class="mt-1 text-[10px] font-semibold" :style="`color:${card.up ? '#059669' : '#d97706'}`">{{ card.up ? '↑' : '↓' }} {{ card.trend }}</p>
                <div class="-mx-1 mt-3">
                    <Sparkline :data="card.spark" :color="card.color" :width="96" :height="28"
                               :stroke-width="1.5" :label="card.label" class="!block w-full"/>
                </div>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="grid gap-6 lg:grid-cols-12">

            <!-- Infrastructure Status + Incidents -->
            <div class="lg:col-span-8 space-y-6">

                <!-- Infrastructure Status -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                        <h3 class="text-[15px] font-black text-primary">Infrastructure Status</h3>
                        <div class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-green-400 live-dot"></span><span class="text-[9px] font-black text-green-600 uppercase tracking-widest">All Systems</span></div>
                    </div>
                    <div class="divide-y divide-outline-variant/40">
                        <div v-for="sys in [
                            { name: 'CIHRM Production API',        status: 'Operational',  latency: '42ms',  uptime: '99.98%', icon: 'api',           color: 'text-green-600 bg-green-50' },
                            { name: 'PostgreSQL Database Cluster', status: 'Operational',  latency: '8ms',   uptime: '99.99%', icon: 'storage',       color: 'text-green-600 bg-green-50' },
                            { name: 'Redis Cache Layer',           status: 'Operational',  latency: '1ms',   uptime: '100%',   icon: 'memory',        color: 'text-green-600 bg-green-50' },
                            { name: 'Mail & Notification Service', status: 'Degraded',     latency: '320ms', uptime: '98.2%',  icon: 'mail',          color: 'text-amber-600 bg-amber-50' },
                            { name: 'Document Storage (S3)',       status: 'Operational',  latency: '85ms',  uptime: '99.95%', icon: 'folder_open',   color: 'text-green-600 bg-green-50' },
                            { name: 'VPN Gateway (Accra HQ)',      status: 'Operational',  latency: '12ms',  uptime: '99.97%', icon: 'vpn_lock',      color: 'text-green-600 bg-green-50' },
                        ]" :key="sys.name"
                             class="flex items-center justify-between px-6 py-3.5 hover:bg-surface-container-low/30 transition-colors">
                            <div class="flex items-center gap-4">
                                <div class="h-8 w-8 rounded-xl flex items-center justify-center flex-shrink-0" :class="sys.color.split(' ')[1]">
                                    <span class="material-symbols-outlined text-[16px]" :class="sys.color.split(' ')[0]">{{ sys.icon }}</span>
                                </div>
                                <div>
                                    <p class="text-[13px] font-bold text-primary">{{ sys.name }}</p>
                                    <p class="text-[10px] font-medium text-on-surface-variant">Latency: {{ sys.latency }} · Uptime: {{ sys.uptime }}</p>
                                </div>
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-[9px] font-black uppercase tracking-wider" :class="sys.status === 'Operational' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-amber-50 text-amber-700 border border-amber-100'">{{ sys.status }}</span>
                        </div>
                    </div>
                </div>

                <!-- Open Incidents -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                        <h3 class="text-[15px] font-black text-primary">Active Incidents &amp; Tickets</h3>
                        <button @click="router.visit(route('tickets.index', { new: 1 }))" class="btn-shimmer flex items-center gap-1.5 rounded-xl px-4 py-2 text-[12px] font-black text-white" style="background:linear-gradient(135deg,#0a2647,#205295)">
                            <span class="material-symbols-outlined text-[15px]">add</span> New Ticket
                        </button>
                    </div>
                    <div class="divide-y divide-outline-variant/40">
                        <div v-for="ticket in tickets.slice(0, 5)" :key="ticket.id"
                             class="flex items-center justify-between px-6 py-3.5 hover:bg-surface-container-low/30 transition-colors group cursor-pointer">
                            <div class="flex items-center gap-4">
                                <span class="text-[10px] font-mono font-bold text-on-surface-variant/50">#SD-{{ 1000 + ticket.id }}</span>
                                <p class="text-[13px] font-bold text-primary group-hover:text-secondary transition-colors">{{ ticket.title || 'IT Support Request' }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase"
                                      :class="(ticket.priority||'medium') === 'high' ? 'bg-red-50 text-red-700 border border-red-100' : (ticket.priority||'medium') === 'medium' ? 'bg-amber-50 text-amber-700 border border-amber-100' : 'bg-surface-container-low text-on-surface-variant border border-outline-variant'">
                                    {{ ticket.priority || 'Medium' }}
                                </span>
                                <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase bg-blue-50 text-blue-700 border border-blue-100">{{ ticket.status || 'Open' }}</span>
                            </div>
                        </div>
                        <div v-if="!tickets.length" class="px-6 py-8 text-center text-sm font-bold text-on-surface-variant italic">No open tickets — all clear.</div>
                    </div>
                </div>
            </div>

            <!-- Team + On-Call -->
            <div class="lg:col-span-4 space-y-6">

                <!-- On-Call Roster -->
                <div class="rounded-2xl p-6 text-white relative overflow-hidden" style="background:linear-gradient(135deg,#0c0e14,#131620);border:1px solid rgba(255,255,255,0.06)">
                    <div class="absolute -right-4 -top-4 opacity-10"><span class="material-symbols-outlined text-9xl">phonelink_ring</span></div>
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-4" style="color:rgba(255,255,255,0.35)">On-Call Roster · Today</p>
                    <div class="space-y-3">
                        <div v-for="oncall in [
                            { name: 'Kwame Asiedu', role: 'Senior DevOps',    shift: '08:00–16:00', primary: true  },
                            { name: 'Efua Boateng', role: 'Network Engineer', shift: '16:00–00:00', primary: false },
                            { name: 'Isaac Mensah', role: 'Security Analyst', shift: '00:00–08:00', primary: false },
                        ]" :key="oncall.name"
                             class="flex items-center gap-3 rounded-xl p-3"
                             :style="oncall.primary ? 'background:rgba(44,116,179,0.18);border:1px solid rgba(44,116,179,0.25)' : 'background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.05)'">
                            <div class="h-8 w-8 rounded-full flex items-center justify-center text-[11px] font-black text-white flex-shrink-0" style="background:linear-gradient(135deg,#0a2647,#205295)">{{ oncall.name.charAt(0) }}</div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[12px] font-black text-white truncate">{{ oncall.name }}</p>
                                <p class="text-[9.5px] font-medium" style="color:rgba(255,255,255,0.4)">{{ oncall.role }}</p>
                            </div>
                            <span class="text-[8.5px] font-bold flex-shrink-0" style="color:rgba(255,255,255,0.3)">{{ oncall.shift }}</span>
                        </div>
                    </div>
                </div>

                <!-- IT Team Directory -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-[13px] font-black text-primary">IT Team (42)</h4>
                        <span class="text-[10px] font-black text-secondary">View All</span>
                    </div>
                    <div class="space-y-2.5">
                        <div v-for="member in [
                            { name: 'Ama Asante',   role: 'Lead Engineer', status: 'online'  },
                            { name: 'Kofi Darko',   role: 'Backend Dev',   status: 'online'  },
                            { name: 'Yaa Osei',     role: 'QA Engineer',   status: 'away'    },
                            { name: 'Nana Adjei',   role: 'Sys Admin',     status: 'online'  },
                            { name: 'Abena Mensah', role: 'Data Engineer', status: 'offline' },
                        ]" :key="member.name"
                             class="flex items-center gap-3">
                            <div class="relative flex-shrink-0">
                                <div class="h-8 w-8 rounded-full bg-secondary/10 flex items-center justify-center text-[11px] font-black text-secondary">{{ member.name.charAt(0) }}</div>
                                <div class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white"
                                     :class="member.status === 'online' ? 'bg-green-400' : member.status === 'away' ? 'bg-amber-400' : 'bg-slate-300'"></div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[12px] font-bold text-primary truncate">{{ member.name }}</p>
                                <p class="text-[10px] font-medium text-on-surface-variant">{{ member.role }}</p>
                            </div>
                            <span class="text-[9px] font-bold capitalize" :class="member.status === 'online' ? 'text-green-600' : member.status === 'away' ? 'text-amber-600' : 'text-on-surface-variant/40'">{{ member.status }}</span>
                        </div>
                    </div>
                </div>

                <!-- Tech Stack -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Tech Stack Health</h4>
                    <div class="space-y-3">
                        <div v-for="tech in [
                            { name: 'Laravel API',     pct: 99,  color: 'bg-red-500' },
                            { name: 'Vue.js Frontend', pct: 100, color: 'bg-green-500' },
                            { name: 'PostgreSQL',      pct: 99,  color: 'bg-blue-500' },
                            { name: 'Redis Cache',     pct: 100, color: 'bg-blue-500' },
                            { name: 'Nginx Proxy',     pct: 98,  color: 'bg-amber-500' },
                        ]" :key="tech.name" class="space-y-1">
                            <div class="flex items-center justify-between text-[11px] font-bold">
                                <span class="text-on-surface-variant">{{ tech.name }}</span>
                                <span :class="tech.pct >= 99 ? 'text-green-600' : 'text-amber-600'">{{ tech.pct }}%</span>
                            </div>
                            <div class="h-1.5 w-full rounded-full bg-surface-container-low overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-700" :class="tech.color" :style="`width:${tech.pct}%`"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
