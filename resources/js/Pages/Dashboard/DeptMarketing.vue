<script setup>
import Sparkline from '@/Components/charts/Sparkline.vue';

const props = defineProps({
    spark: { type: Object, required: true },   // deptSparkData.marketing slice
});
</script>

<template>
    <div class="space-y-6 animate-reveal-up">

        <!-- Hero Banner -->
        <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white"
             style="background:linear-gradient(135deg,#1a237e,#3949ab);border:1px solid rgba(255,255,255,0.06)">
            <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(124,92,255,0.22),transparent 70%)"></div>
            <div class="relative flex flex-wrap items-center justify-between gap-6">
                <div class="flex items-center gap-5">
                    <div class="h-14 w-14 rounded-2xl flex items-center justify-center flex-shrink-0" style="background:rgba(124,92,255,0.2);border:1px solid rgba(124,92,255,0.3)">
                        <span class="material-symbols-outlined text-3xl text-blue-400" style="font-variation-settings:'FILL' 1">campaign</span>
                    </div>
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-1" style="color:rgba(255,255,255,0.3)">Department</p>
                        <h2 class="text-2xl font-black leading-tight">Marketing</h2>
                        <p class="text-sm font-medium mt-0.5" style="color:rgba(255,255,255,0.45)">Campaigns · Brand · Digital · Content</p>
                    </div>
                </div>
                <div class="flex items-center gap-10 flex-shrink-0">
                    <div v-for="m in [
                        { label: 'Team Members', val: '35' },
                        { label: 'Campaign ROI', val: spark.roi[spark.roi.length-1].toFixed(0) + '%' },
                        { label: 'Budget Used',  val: spark.budget[spark.budget.length-1].toFixed(0) + '%' },
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
                { label: 'Campaign ROI',    display: spark.roi[spark.roi.length-1].toFixed(0) + '%',                  trend: 'vs 200% target', color: '#ffd700', rgb: '255,215,0',  icon: 'trending_up',          up: true,  spark: spark.roi        },
                { label: 'Budget Utilised', display: spark.budget[spark.budget.length-1].toFixed(0) + '%',            trend: 'of GHS 420K',    color: '#0891b2', rgb: '8,145,178',  icon: 'account_balance_wallet', up: false, spark: spark.budget   },
                { label: 'Leads Generated', display: Math.round(spark.leads[spark.leads.length-1]).toLocaleString(),  trend: '+8% this week',  color: '#3949ab', rgb: '57, 73, 171', icon: 'group_add',            up: true,  spark: spark.leads      },
                { label: 'Conversion Rate', display: spark.conversion[spark.conversion.length-1].toFixed(1) + '%',     trend: 'Target: 4%',     color: '#d97706', rgb: '217,119,6',  icon: 'swap_horiz',           up: true,  spark: spark.conversion },
            ]" :key="i"
                 class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                 :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.07}s`">
                <div class="absolute right-3.5 top-3.5 flex items-center gap-1">
                    <span class="h-1.5 w-1.5 rounded-full live-dot" :style="`background:${card.color}`"></span>
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
