<script setup>
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    spark:   { type: Object,  required: true },   // deptSparkData.it slice
    tickets: { type: Array,   default: () => [] },
});

const uptimePct = computed(() => props.spark.uptime[props.spark.uptime.length - 1].toFixed(2));
</script>

<template>
    <div class="space-y-8 animate-reveal-up">

        <!-- ─── Executive header ──────────────────────────────────── -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">dns</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">IT GOVERNANCE</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Infrastructure &amp; Service Desk</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Standing watch on infrastructure, service-desk velocity, and the security perimeter.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1.5 rounded-full bg-green-50 border border-green-100 px-3 py-1.5">
                    <span class="h-2 w-2 rounded-full bg-green-400 live-dot"></span>
                    <span class="text-[10px] font-black uppercase tracking-widest text-green-700">Live · {{ uptimePct }}% SLA</span>
                </div>
                <button @click="router.visit(route('tickets.index', { new: 1 }))"
                        class="btn-shimmer flex items-center gap-1.5 rounded-xl px-4 py-2 text-[12px] font-black text-white"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                    <span class="material-symbols-outlined text-[15px]">add</span> New Ticket
                </button>
                <button @click="router.visit(route('tickets.index'))"
                        class="flex items-center gap-1.5 rounded-xl border border-outline-variant px-4 py-2 text-[12px] font-bold text-primary hover:bg-surface-container-low transition-colors">
                    <span class="material-symbols-outlined text-[15px]">list_alt</span> Service Desk
                </button>
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
                        <button @click="router.visit(route('tickets.index', { new: 1 }))" class="btn-shimmer flex items-center gap-1.5 rounded-xl px-4 py-2 text-[12px] font-black text-white" style="background:linear-gradient(135deg,#0d1452,#1a237e)">
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
                <div class="rounded-2xl p-6 text-white relative overflow-hidden" style="background:linear-gradient(135deg,#1a237e,#3949ab);border:1px solid rgba(255,255,255,0.06)">
                    <div class="absolute -right-4 -top-4 opacity-10"><span class="material-symbols-outlined text-9xl">phonelink_ring</span></div>
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-4" style="color:rgba(255,255,255,0.35)">On-Call Roster · Today</p>
                    <div class="space-y-3">
                        <div v-for="oncall in [
                            { name: 'Kwame Asiedu', role: 'Senior DevOps',    shift: '08:00–16:00', primary: true  },
                            { name: 'Efua Boateng', role: 'Network Engineer', shift: '16:00–00:00', primary: false },
                            { name: 'Isaac Mensah', role: 'Security Analyst', shift: '00:00–08:00', primary: false },
                        ]" :key="oncall.name"
                             class="flex items-center gap-3 rounded-xl p-3"
                             :style="oncall.primary ? 'background:rgba(57, 73, 171,0.18);border:1px solid rgba(57, 73, 171,0.25)' : 'background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.05)'">
                            <div class="h-8 w-8 rounded-full flex items-center justify-center text-[11px] font-black text-white flex-shrink-0" style="background:linear-gradient(135deg,#0d1452,#1a237e)">{{ oncall.name.charAt(0) }}</div>
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
