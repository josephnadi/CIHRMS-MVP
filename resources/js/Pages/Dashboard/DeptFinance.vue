<script setup>
import { computed } from 'vue';
import Sparkline from '@/Components/charts/Sparkline.vue';

const props = defineProps({
    spark: { type: Object, required: true },   // deptSparkData.finance slice
});

const editionDate = computed(() => new Date().toLocaleDateString('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
}));
const revenueNow = computed(() => props.spark.revenue[props.spark.revenue.length - 1].toFixed(1));
const varianceNow = computed(() => props.spark.variance[props.spark.variance.length - 1].toFixed(1));
const pendingNow = computed(() => props.spark.pending[props.spark.pending.length - 1].toFixed(0));
const efficiencyNow = computed(() => props.spark.efficiency[props.spark.efficiency.length - 1].toFixed(0));
</script>

<template>
    <div class="space-y-8 animate-reveal-up">

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">account_balance</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">TREASURY</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Finance &amp; Treasury</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Treasury, payroll, audit and statutory compliance — the institute's financial posture in one ledger.
                </p>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="grid gap-6 lg:grid-cols-12">
            <div class="lg:col-span-8 space-y-6">

                <!-- Budget vs Actuals Live Chart -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 overflow-hidden">
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <h3 class="text-[15px] font-black text-primary">Budget vs Actuals · 2026</h3>
                            <p class="text-[10px] text-on-surface-variant mt-0.5">Monthly financial performance tracking</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-1.5"><span class="h-2 w-4 rounded-full" style="background:linear-gradient(90deg,#1a237e,#3949ab)"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Actual</span></div>
                            <div class="flex items-center gap-1.5"><span class="h-2 w-4 rounded-full bg-outline-variant"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Budget</span></div>
                        </div>
                    </div>
                    <div class="flex items-end gap-2" style="height:120px;">
                        <div v-for="(month, mi) in ['J','F','M','A','M','J','J','A','S','O','N','D']" :key="month"
                             class="flex-1 flex flex-col items-center gap-0.5 group">
                            <!-- Budget bar (background) -->
                            <div class="relative w-full" style="height:100px">
                                <div class="absolute bottom-0 w-full rounded-t bg-outline-variant/30 transition-all duration-700"
                                     :style="`height:${[65,68,70,72,74,76,78,80,82,84,86,88][mi]}%;`"></div>
                                <!-- Actual bar (foreground) -->
                                <div class="absolute bottom-0 w-3/4 left-1/2 -translate-x-1/2 rounded-t transition-all duration-700"
                                     style="background:linear-gradient(to top,#1a237e,rgba(99,131,255,0.6));"
                                     :style="`height:${[60,65,62,70,74,71,76,78,80,84,85,88][mi]}%;`"></div>
                                <!-- Tooltip -->
                                <div class="absolute -top-8 left-1/2 -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity z-10 whitespace-nowrap rounded-lg px-2 py-1 text-[8px] font-black text-white" style="background:#1a237e">GHS {{ [8.1,8.3,8.2,8.6,8.8,8.7,8.9,9.0,9.1,9.2,9.3,9.4][mi] }}M</div>
                            </div>
                            <span class="text-[8px] font-bold text-on-surface-variant/40">{{ month }}</span>
                        </div>
                    </div>
                </div>

                <!-- Expense Breakdown + Pending Approvals -->
                <div class="grid gap-6 sm:grid-cols-2">

                    <!-- Expense Breakdown -->
                    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                        <h4 class="text-[13px] font-black text-primary mb-4">Expense Breakdown</h4>
                        <div class="space-y-3">
                            <div v-for="exp in [
                                { label: 'Staff Payroll',   pct: 68, amount: 'GHS 1.67M', color: '#3949ab' },
                                { label: 'Operations',      pct: 14, amount: 'GHS 344K',  color: '#059669' },
                                { label: 'IT & Technology', pct: 8,  amount: 'GHS 197K',  color: '#1a237e' },
                                { label: 'Marketing',       pct: 6,  amount: 'GHS 148K',  color: '#d97706' },
                                { label: 'Other',           pct: 4,  amount: 'GHS 98K',   color: '#6b7280' },
                            ]" :key="exp.label" class="space-y-1">
                                <div class="flex items-center justify-between text-[11px] font-bold">
                                    <span class="text-on-surface-variant">{{ exp.label }}</span>
                                    <span class="text-primary">{{ exp.pct }}%</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-surface-container-low overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-700" :style="`width:${exp.pct}%;background:${exp.color}`"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Approvals -->
                    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                        <h4 class="text-[13px] font-black text-primary mb-4">Pending Approvals</h4>
                        <div class="space-y-2.5">
                            <div v-for="approval in [
                                { ref: 'PAY-2026-048', amount: 'GHS 12,400', type: 'Payroll Supplementary', urgency: 'High'   },
                                { ref: 'EXP-2026-112', amount: 'GHS 4,850',  type: 'Travel & Conference',   urgency: 'Medium' },
                                { ref: 'INV-2026-089', amount: 'GHS 28,000', type: 'Vendor Invoice',         urgency: 'High'   },
                                { ref: 'REF-2026-031', amount: 'GHS 1,200',  type: 'Staff Reimbursement',   urgency: 'Low'    },
                            ]" :key="approval.ref"
                                 class="rounded-xl border border-outline-variant/50 p-3 hover:border-secondary/20 transition-colors cursor-pointer group">
                                <div class="flex items-center justify-between">
                                    <span class="text-[9px] font-mono font-bold text-on-surface-variant/50">{{ approval.ref }}</span>
                                    <span class="text-[9px] font-black" :class="approval.urgency === 'High' ? 'text-red-600' : approval.urgency === 'Medium' ? 'text-amber-600' : 'text-green-600'">{{ approval.urgency }}</span>
                                </div>
                                <p class="text-[12px] font-bold text-primary mt-1 group-hover:text-secondary transition-colors">{{ approval.type }}</p>
                                <p class="text-[13px] font-black text-primary mt-0.5">{{ approval.amount }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="lg:col-span-4 space-y-6">

                <!-- Payroll Summary -->
                <div class="rounded-2xl p-6 text-white relative overflow-hidden" style="background:linear-gradient(135deg,#1a237e,#3949ab);border:1px solid rgba(255,255,255,0.06)">
                    <div class="absolute -right-4 -top-4 opacity-10"><span class="material-symbols-outlined text-9xl">payments</span></div>
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-2" style="color:rgba(255,255,255,0.3)">Monthly Payroll</p>
                    <p class="text-3xl font-black mb-1">GHS 2.45M</p>
                    <p class="text-[10px] mb-5" style="color:rgba(255,255,255,0.4)">Next cycle ends in 4 days · 1,284 staff</p>
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between text-[11px] font-bold">
                            <span style="color:rgba(255,255,255,0.55)">Processing Status</span>
                            <span style="color:#34d399">85% Complete</span>
                        </div>
                        <div class="h-2 w-full rounded-full overflow-hidden" style="background:rgba(255,255,255,0.08)">
                            <div class="h-full rounded-full transition-all duration-1000" style="width:85%;background:linear-gradient(90deg,#059669,#34d399)"></div>
                        </div>
                    </div>
                    <div class="mt-5 pt-5 border-t space-y-2" style="border-color:rgba(255,255,255,0.08)">
                        <div v-for="row in [
                            { label: 'Basic Salary',     val: 'GHS 1.80M' },
                            { label: 'Allowances',       val: 'GHS 450K'  },
                            { label: 'SSNIT Deductions', val: 'GHS 200K'  },
                        ]" :key="row.label" class="flex items-center justify-between text-[11px]">
                            <span style="color:rgba(255,255,255,0.45)">{{ row.label }}</span>
                            <span class="font-black text-white">{{ row.val }}</span>
                        </div>
                    </div>
                </div>

                <!-- Compliance Status -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Statutory Compliance</h4>
                    <div class="space-y-3">
                        <div v-for="item in [
                            { label: 'SSNIT Filing — May 2026',     status: 'Filed',      color: 'text-green-600 bg-green-50 border-green-100' },
                            { label: 'Income Tax (PAYE)',           status: 'Filed',      color: 'text-green-600 bg-green-50 border-green-100' },
                            { label: 'Provident Fund Contribution', status: 'Pending',    color: 'text-amber-600 bg-amber-50 border-amber-100' },
                            { label: 'Annual Returns',              status: 'Filed',      color: 'text-green-600 bg-green-50 border-green-100' },
                            { label: 'VAT Declaration',             status: 'Due Jun 15', color: 'text-blue-600 bg-blue-50 border-blue-100' },
                        ]" :key="item.label"
                             class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5 flex-1 min-w-0 mr-3">
                                <span class="material-symbols-outlined text-[16px] flex-shrink-0"
                                      :class="item.status === 'Filed' ? 'text-green-500' : item.status === 'Pending' ? 'text-amber-500' : 'text-blue-500'">
                                    {{ item.status === 'Filed' ? 'check_circle' : item.status === 'Pending' ? 'schedule' : 'event' }}
                                </span>
                                <p class="text-[11.5px] font-bold text-on-surface-variant truncate">{{ item.label }}</p>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border flex-shrink-0" :class="item.color">{{ item.status }}</span>
                        </div>
                    </div>
                </div>

                <!-- Finance Team -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Finance Team (22)</h4>
                    <div class="space-y-2.5">
                        <div v-for="member in [
                            { name: 'Esi Amponsah',     role: 'CFO',                status: 'online' },
                            { name: 'Yaw Mensah',       role: 'Senior Accountant',  status: 'online' },
                            { name: 'Akua Owusu',       role: 'Payroll Specialist', status: 'online' },
                            { name: 'Kwesi Acheampong', role: 'Financial Analyst',  status: 'away'   },
                            { name: 'Abena Darko',      role: 'Tax Compliance',     status: 'online' },
                        ]" :key="member.name"
                             class="flex items-center gap-3">
                            <div class="relative flex-shrink-0">
                                <div class="h-8 w-8 rounded-full bg-amber-500/10 flex items-center justify-center text-[11px] font-black text-amber-600">{{ member.name.charAt(0) }}</div>
                                <div class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white"
                                     :class="member.status === 'online' ? 'bg-green-400' : 'bg-amber-400'"></div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[12px] font-bold text-primary truncate">{{ member.name }}</p>
                                <p class="text-[10px] font-medium text-on-surface-variant">{{ member.role }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
