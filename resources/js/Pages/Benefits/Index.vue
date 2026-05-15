<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    enrolments: Object,
    dependants: Object,
    claims:     Object,
    plans:      Object,
    provident:  Array,
});

const showEnrol = ref(false);
const showDependant = ref(false);
const showClaim = ref(false);
const claimEnrolment = ref(null);

const enrolForm = useForm({ plan_id: '', effective_from: new Date().toISOString().slice(0, 10), premium: null });
const dependantForm = useForm({ full_name: '', relationship: 'spouse', date_of_birth: '', national_id: '', gender: '', is_covered: true });
const claimForm = useForm({ enrolment_id: '', amount: null, currency: 'GHS', claim_date: new Date().toISOString().slice(0, 10), description: '' });

function submitEnrol() {
    enrolForm.post(route('benefits.enrol'), {
        preserveScroll: true,
        onSuccess: () => { showEnrol.value = false; enrolForm.reset(); },
    });
}

function submitDependant() {
    dependantForm.post(route('benefits.dependants.store'), {
        preserveScroll: true,
        onSuccess: () => { showDependant.value = false; dependantForm.reset(); },
    });
}

function openClaim(enrolment) {
    claimEnrolment.value = enrolment;
    claimForm.enrolment_id = enrolment.id;
    showClaim.value = true;
}

function submitClaim() {
    claimForm.post(route('benefits.claims.store'), {
        preserveScroll: true,
        onSuccess: () => { showClaim.value = false; claimEnrolment.value = null; claimForm.reset(); },
    });
}

const statusTone = {
    active:      'bg-emerald-100 text-emerald-800',
    suspended:   'bg-amber-100 text-amber-800',
    terminated:  'bg-rose-100 text-rose-800',
    submitted:   'bg-violet-100 text-violet-800',
    reviewing:   'bg-amber-100 text-amber-800',
    approved:    'bg-emerald-100 text-emerald-800',
    rejected:    'bg-rose-100 text-rose-800',
    paid:        'bg-sky-100 text-sky-800',
};

const typeLabel = {
    health_insurance: 'Health', provident_fund: 'Provident',
    life_insurance: 'Life', dental: 'Dental', vision: 'Vision',
    wellness: 'Wellness', other: 'Other',
};
</script>

<template>
<Head title="My Benefits" />
<AuthenticatedLayout active-module="benefits">
    <div class="p-6 space-y-6 animate-reveal-up">
        <header class="flex items-center justify-between">
            <div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary">My Benefits</h1>
                <p class="text-sm text-on-surface-variant">Enrolments · Dependants · Claims · Provident fund</p>
            </div>
            <div class="flex gap-2">
                <button @click="showEnrol = true" v-if="$page.props.auth.permissions?.includes('benefits.enrol')" type="button" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white shadow-glow-sm btn-shimmer">+ Enrol in Plan</button>
                <Link v-if="$page.props.auth.permissions?.includes('benefits.manage')" :href="route('benefits.plans.index')" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Manage Plans</Link>
                <Link v-if="$page.props.auth.permissions?.includes('benefits.manage')" :href="route('benefits.claims.index')" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Claims Queue</Link>
            </div>
        </header>

        <section>
            <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">My Enrolments</h2>
            <div v-if="props.enrolments.data?.length" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div v-for="e in props.enrolments.data" :key="e.id" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 card-lift">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-secondary">{{ typeLabel[e.plan?.type] }}</p>
                            <h3 class="text-lg font-black text-primary mt-1">{{ e.plan?.name }}</h3>
                            <p class="text-xs text-on-surface-variant font-mono">{{ e.plan?.code }}</p>
                        </div>
                        <span :class="['rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase', statusTone[e.status]]">{{ e.status }}</span>
                    </div>
                    <dl class="mt-4 space-y-1 text-xs">
                        <div class="flex justify-between"><dt class="text-on-surface-variant">Effective</dt><dd>{{ e.effective_from }} → {{ e.effective_to ?? 'ongoing' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-on-surface-variant">Premium / mo</dt><dd class="font-mono">GHS {{ e.monthly_premium.toFixed(2) }}</dd></div>
                    </dl>
                    <div class="mt-4 flex gap-2">
                        <a :href="route('benefits.e-card', e.id)" class="rounded-lg bg-emerald-50 text-emerald-800 px-3 py-1.5 text-xs font-bold hover:bg-emerald-100">Download E-Card</a>
                        <button v-if="e.status === 'active'" @click="openClaim(e)" type="button" class="rounded-lg bg-secondary/10 text-secondary px-3 py-1.5 text-xs font-bold hover:bg-secondary/20">Submit Claim</button>
                    </div>
                </div>
            </div>
            <EmptyState v-else title="No active enrolments. Click + Enrol in Plan above." class="py-8" />
        </section>

        <section v-if="props.provident?.length">
            <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">Provident Fund</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div v-for="p in props.provident" :key="p.plan_id" class="rounded-2xl border border-outline-variant/60 bg-gradient-to-br from-secondary/5 to-primary/5 p-5 card-lift">
                    <p class="text-sm font-bold text-primary">{{ p.plan_name }}</p>
                    <p class="mt-1 text-2xl font-black text-secondary">GHS {{ p.total_contributed.toFixed(2) }}</p>
                    <p class="text-xs text-on-surface-variant">contributed over {{ p.months_active }} mo · GHS {{ p.monthly_premium.toFixed(2) }}/mo</p>
                </div>
            </div>
        </section>

        <section>
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">My Dependants</h2>
                <button @click="showDependant = true" v-if="$page.props.auth.permissions?.includes('benefits.enrol')" type="button" class="rounded-lg border border-outline-variant px-3 py-1 text-xs font-bold text-primary hover:bg-surface-container-low">+ Add Dependant</button>
            </div>
            <div v-if="props.dependants.data?.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                        <th class="p-3">Name</th><th>Relationship</th><th>DOB</th><th>National ID</th><th>Covered</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="d in props.dependants.data" :key="d.id" class="border-t border-outline-variant/40">
                            <td class="p-3">{{ d.full_name }}</td>
                            <td>{{ d.relationship }}</td>
                            <td class="text-xs">{{ d.date_of_birth }}</td>
                            <td class="text-xs font-mono">{{ d.national_id ?? '—' }}</td>
                            <td><span :class="['text-[10px] font-bold', d.is_covered ? 'text-emerald-700' : 'text-on-surface-variant']">{{ d.is_covered ? 'YES' : 'NO' }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="No dependants registered." class="py-8" />
        </section>

        <section>
            <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">My Claims</h2>
            <div v-if="props.claims.data?.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                        <th class="p-3">Reference</th><th>Plan</th><th>Amount</th><th>Date</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="c in props.claims.data" :key="c.id" class="border-t border-outline-variant/40">
                            <td class="p-3 font-mono">{{ c.claim_reference }}</td>
                            <td class="text-xs">{{ c.enrolment?.plan_name }}</td>
                            <td class="font-mono">{{ c.currency }} {{ c.amount.toFixed(2) }}</td>
                            <td class="text-xs">{{ c.claim_date }}</td>
                            <td><span :class="['rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase', statusTone[c.status]]">{{ c.status }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="No claims yet." class="py-8" />
        </section>
    </div>

    <SlidePanel :open="showEnrol" @close="showEnrol = false" title="Enrol in Plan">
        <form @submit.prevent="submitEnrol" class="space-y-3 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Plan</label><select v-model="enrolForm.plan_id" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option value="" disabled>Select a plan…</option><option v-for="p in props.plans.data" :key="p.id" :value="p.id">{{ p.name }} ({{ typeLabel[p.type] }})</option></select></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Effective From</label><input v-model="enrolForm.effective_from" type="date" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Premium override (optional)</label><input v-model.number="enrolForm.premium" type="number" step="0.01" min="0" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <button type="submit" :disabled="enrolForm.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Enrol</button>
        </form>
    </SlidePanel>

    <SlidePanel :open="showDependant" @close="showDependant = false" title="Add Dependant">
        <form @submit.prevent="submitDependant" class="space-y-3 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Full Name</label><input v-model="dependantForm.full_name" maxlength="120" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Relationship</label><select v-model="dependantForm.relationship" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option value="spouse">Spouse</option><option value="child">Child</option><option value="parent">Parent</option><option value="other">Other</option></select></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Date of Birth</label><input v-model="dependantForm.date_of_birth" type="date" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">National ID (optional)</label><input v-model="dependantForm.national_id" maxlength="32" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Gender</label><select v-model="dependantForm.gender" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option value="">—</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></div>
            <button type="submit" :disabled="dependantForm.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Add Dependant</button>
        </form>
    </SlidePanel>

    <SlidePanel :open="showClaim" @close="showClaim = false" :title="`Claim against ${claimEnrolment?.plan?.name ?? ''}`">
        <form @submit.prevent="submitClaim" class="space-y-3 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Amount (GHS)</label><input v-model.number="claimForm.amount" type="number" step="0.01" min="0.01" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Claim Date</label><input v-model="claimForm.claim_date" type="date" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Description (10+ chars)</label><textarea v-model="claimForm.description" required minlength="10" maxlength="1000" rows="3" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
            <button type="submit" :disabled="claimForm.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Submit Claim</button>
        </form>
    </SlidePanel>
</AuthenticatedLayout>
</template>
