<script setup>
import { computed } from 'vue';
import Sparkline from '@/Components/charts/Sparkline.vue';

const props = defineProps({
    spark: { type: Object, required: true },   // deptSparkData.marketing slice
});

const editionDate = computed(() => new Date().toLocaleDateString('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
}));
const roiNow = computed(() => props.spark.roi[props.spark.roi.length - 1].toFixed(0));
const budgetNow = computed(() => props.spark.budget[props.spark.budget.length - 1].toFixed(0));
const leadsNow = computed(() => Math.round(props.spark.leads[props.spark.leads.length - 1]));
const conversionNow = computed(() => props.spark.conversion[props.spark.conversion.length - 1].toFixed(1));
</script>

<template>
    <div class="space-y-8 animate-reveal-up">

        <!-- ─── Masthead strip ────────────────────────────────────── -->
        <div class="es-masthead">
            <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">BRAND &amp; COMMS EDITION</span></span>
            <span class="es-masthead-spacer"></span>
            <span>{{ editionDate }}</span>
            <span class="es-masthead-spacer"></span>
            <span>Bulletin · Marketing Desk</span>
            <span class="es-masthead-spacer"></span>
            <span class="es-masthead-live">
                <span class="es-dot" aria-hidden="true"></span>
                Live · 6 campaigns running
            </span>
        </div>

        <!-- ─── Broadsheet hero ───────────────────────────────────── -->
        <div class="es-broadsheet rounded-none">
            <!-- LEAD column -->
            <div class="es-broadsheet-lead">
                <p class="es-eyebrow mb-6">From the Brand &amp; Communications desk</p>
                <h2 class="es-display text-[clamp(2.2rem,5vw,4.2rem)]">
                    Stories that compound,
                    <span class="es-display-italic block">audiences that endure.</span>
                </h2>
                <p class="es-display-sub">
                    The state of brand, channel and conversion — every cedi spent and every lead earned,
                    accounted for in a single editorial frame. {{ leadsNow.toLocaleString() }} leads this cycle.
                </p>

                <!-- Quick-action chips -->
                <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                    <span class="es-chip">
                        <span class="material-symbols-outlined text-[15px]">campaign</span>
                        New Campaign
                    </span>
                    <span class="text-on-surface-variant/30">·</span>
                    <span class="es-chip">
                        <span class="material-symbols-outlined text-[15px]">edit_note</span>
                        Content Brief
                    </span>
                </div>
            </div>

            <!-- SIDEBAR column: flagship KPI — campaign ROI -->
            <div class="es-broadsheet-sidebar">
                <div class="es-stat-hero">
                    <p class="es-stat-hero-label">Campaign ROI</p>
                    <p class="es-stat-hero-value">{{ roiNow }}<span style="font-size:0.45em;color:rgb(var(--ct-on-surface-variant)/0.55)">%</span></p>
                    <p class="es-stat-hero-caption">
                        Weighted across active campaigns · target 200%
                    </p>
                    <span class="es-stat-hero-delta">
                        <span class="material-symbols-outlined text-[13px]">trending_up</span>
                        Above benchmark · sparkline below
                    </span>
                    <div class="mt-4 -mx-1">
                        <Sparkline :data="spark.roi" color="#205295" :width="180" :height="36" :stroke-width="1.8" label="Campaign ROI" class="!block w-full"/>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Supporting metrics strip ───────────────────────────── -->
        <div class="es-stat-strip rounded-none">
            <div class="es-stat-cell">
                <p class="es-stat-cell-label">Budget Utilised</p>
                <p class="es-stat-cell-value">{{ budgetNow }}<span style="font-size:0.55em;color:rgb(var(--ct-on-surface-variant)/0.55)">%</span></p>
                <p class="es-stat-cell-caption">of GHS 420K annual envelope</p>
            </div>
            <div class="es-stat-cell">
                <p class="es-stat-cell-label">Leads Generated</p>
                <p class="es-stat-cell-value">{{ leadsNow.toLocaleString() }}</p>
                <p class="es-stat-cell-caption">+8% week-on-week</p>
            </div>
            <div class="es-stat-cell">
                <p class="es-stat-cell-label">Conversion Rate</p>
                <p class="es-stat-cell-value">{{ conversionNow }}<span style="font-size:0.55em;color:rgb(var(--ct-on-surface-variant)/0.55)">%</span></p>
                <p class="es-stat-cell-caption">vs 4% target</p>
            </div>
            <div class="es-stat-cell">
                <p class="es-stat-cell-label">Team Strength</p>
                <p class="es-stat-cell-value">35</p>
                <p class="es-stat-cell-caption">Brand, digital, content, PR</p>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="grid gap-6 lg:grid-cols-12">
            <div class="lg:col-span-8 space-y-6">

                <!-- Active Campaigns -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="px-6 py-4 border-b border-outline-variant/50 flex items-center justify-between">
                        <h3 class="text-[15px] font-black text-primary">Active Campaigns</h3>
                        <span class="rounded-full px-3 py-1 bg-blue-50 text-blue-700 border border-blue-100 text-[9.5px] font-black uppercase">6 Running</span>
                    </div>
                    <div class="divide-y divide-outline-variant/40">
                        <div v-for="campaign in [
                            { name: 'Q2 Institutional Awareness Drive', channel: 'Digital + OOH',   spend: 'GHS 45,000', roi: '342%', status: 'Active', progress: 72 },
                            { name: 'CIHRM Graduate Recruitment 2026',  channel: 'Social + Print',  spend: 'GHS 28,000', roi: '218%', status: 'Active', progress: 45 },
                            { name: 'Annual HR Summit Sponsorship',     channel: 'Events',          spend: 'GHS 12,500', roi: '185%', status: 'Active', progress: 88 },
                            { name: 'Staff Wellness Brand Initiative',  channel: 'Internal Media',  spend: 'GHS 8,200',  roi: '290%', status: 'Active', progress: 30 },
                        ]" :key="campaign.name"
                             class="px-6 py-4 hover:bg-surface-container-low/30 transition-colors">
                            <div class="flex items-start justify-between mb-2">
                                <div>
                                    <p class="text-[13px] font-bold text-primary">{{ campaign.name }}</p>
                                    <p class="text-[10px] text-on-surface-variant mt-0.5">{{ campaign.channel }} · Spend: {{ campaign.spend }}</p>
                                </div>
                                <div class="text-right flex-shrink-0 ml-4">
                                    <p class="text-sm font-black text-green-600">{{ campaign.roi }}</p>
                                    <p class="text-[9px] text-on-surface-variant">ROI</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 mt-2">
                                <div class="flex-1 h-1.5 rounded-full bg-surface-container-low overflow-hidden">
                                    <div class="h-full bg-blue-500 rounded-full transition-all duration-700" :style="`width:${campaign.progress}%`"></div>
                                </div>
                                <span class="text-[10px] font-black text-on-surface-variant flex-shrink-0">{{ campaign.progress }}%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Content Pipeline Kanban -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="px-6 py-4 border-b border-outline-variant/50">
                        <h3 class="text-[15px] font-black text-primary">Content Pipeline</h3>
                    </div>
                    <div class="grid grid-cols-3 gap-px bg-outline-variant/20 overflow-hidden">
                        <div v-for="col in [
                            { title: 'In Production', count: 8,  color: 'bg-blue-400',  items: ['Q3 Annual Report Design', 'Social Media Calendar', 'Brand Refresh Deck'] },
                            { title: 'In Review',     count: 5,  color: 'bg-amber-400', items: ['CIHRM Brand Guidelines', 'Video Script — Recruitment'] },
                            { title: 'Published',     count: 12, color: 'bg-green-400', items: ['May Newsletter', 'LinkedIn Campaign Posts', 'Staff Magazine Issue 4'] },
                        ]" :key="col.title" class="p-4 bg-surface-container-lowest">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="h-2 w-2 rounded-full" :class="col.color"></span>
                                <h4 class="text-[11px] font-black text-primary">{{ col.title }}</h4>
                                <span class="ml-auto h-5 w-5 rounded-full bg-surface-container-low flex items-center justify-center text-[9px] font-black text-on-surface-variant">{{ col.count }}</span>
                            </div>
                            <div class="space-y-2">
                                <div v-for="item in col.items" :key="item"
                                     class="rounded-lg bg-surface-container-low/60 border border-outline-variant/40 px-3 py-2 text-[11px] font-medium text-on-surface cursor-default hover:border-secondary/20 transition-colors">
                                    {{ item }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="lg:col-span-4 space-y-6">

                <!-- Social Media Metrics -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-[13px] font-black text-primary">Social Media</h4>
                        <div class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full live-dot bg-green-400"></span><span class="text-[9px] font-black text-green-600">Live</span></div>
                    </div>
                    <div class="space-y-3">
                        <div v-for="social in [
                            { platform: 'LinkedIn',  followers: '12.4K', growth: '+8.2%',  icon: 'group',           color: '#0077b5' },
                            { platform: 'Twitter/X', followers: '8.1K',  growth: '+3.4%',  icon: 'alternate_email', color: '#1da1f2' },
                            { platform: 'Facebook',  followers: '22.8K', growth: '+1.9%',  icon: 'thumb_up',        color: '#1877f2' },
                            { platform: 'Instagram', followers: '5.2K',  growth: '+12.1%', icon: 'camera_alt',      color: '#e1306c' },
                        ]" :key="social.platform"
                             class="flex items-center gap-3 rounded-xl p-3 bg-surface-container-low/40 border border-outline-variant/30">
                            <div class="h-8 w-8 rounded-xl flex items-center justify-center flex-shrink-0" :style="`background:${social.color}15`">
                                <span class="material-symbols-outlined text-[16px]" :style="`color:${social.color}`">{{ social.icon }}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[12px] font-bold text-primary">{{ social.platform }}</p>
                                <p class="text-[10px] text-on-surface-variant">{{ social.followers }} followers</p>
                            </div>
                            <span class="text-[11px] font-black text-green-600">{{ social.growth }}</span>
                        </div>
                    </div>
                </div>

                <!-- Budget Tracker -->
                <div class="rounded-2xl p-5 text-white" style="background:linear-gradient(135deg,#1a237e,#3949ab);border:1px solid rgba(255,255,255,0.06)">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-1" style="color:rgba(255,255,255,0.35)">Annual Marketing Budget</p>
                    <p class="text-3xl font-black mb-4">GHS 420,000</p>
                    <div class="space-y-3">
                        <div v-for="line in [
                            { label: 'Digital Advertising', spent: 145000, total: 200000 },
                            { label: 'Events & PR',         spent: 62000,  total: 100000 },
                            { label: 'Content Production',  spent: 38000,  total: 80000  },
                            { label: 'Brand & Design',      spent: 27000,  total: 40000  },
                        ]" :key="line.label" class="space-y-1">
                            <div class="flex items-center justify-between text-[10px] font-bold">
                                <span style="color:rgba(255,255,255,0.6)">{{ line.label }}</span>
                                <span style="color:rgba(255,255,255,0.35)">GHS {{ (line.spent/1000).toFixed(0) }}K / {{ (line.total/1000).toFixed(0) }}K</span>
                            </div>
                            <div class="h-1.5 w-full rounded-full overflow-hidden" style="background:rgba(255,255,255,0.08)">
                                <div class="h-full rounded-full transition-all duration-700" style="background:linear-gradient(90deg,#1a237e,#7986cb)" :style="`width:${Math.round(line.spent/line.total*100)}%`"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Team -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Marketing Team (35)</h4>
                    <div class="flex flex-wrap gap-2">
                        <div v-for="m in ['Content', 'Design', 'Digital', 'Events', 'Brand', 'PR', 'Analytics', 'SEO']" :key="m"
                             class="rounded-full px-3 py-1 text-[10px] font-black border border-outline-variant text-on-surface-variant hover:bg-surface-container-low transition-colors cursor-default">
                            {{ m }}
                        </div>
                    </div>
                    <div class="mt-4 flex -space-x-2">
                        <div v-for="i in 8" :key="i"
                             class="h-8 w-8 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-black text-white"
                             :style="`background:linear-gradient(135deg,${['#1a237e','#1a237e','#059669','#d97706','#dc2626','#0891b2','#3949ab','#0d1452'][i-1]},${['#3949ab','#7986cb','#34d399','#fbbf24','#f87171','#22d3ee','#7986cb','#1a237e'][i-1]})`">
                            {{ 'ABCDEFGH'[i-1] }}
                        </div>
                        <div class="h-8 w-8 rounded-full border-2 border-white bg-surface-container-low flex items-center justify-center text-[9px] font-black text-on-surface-variant">+27</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
