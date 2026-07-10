<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';
import InputError from '@/Components/InputError.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    certifications: Object,
    employees:      Array,
});

const canManage = ref(props.employees?.length > 0);

const showAdd = ref(false);
const newCert = useForm({
    employee_id: '', name: '', issuer: '', credential_id: '',
    issued_at: '', expires_at: '', verification_url: '',
});

function createCert() {
    newCert.post(route('governance.certifications.store'), {
        preserveScroll: true,
        onSuccess: () => { showAdd.value = false; newCert.reset(); },
    });
}

function dispatchReminders() {
    if (! confirm('Send reminder events for all certifications expiring within 30 days?')) return;
    router.post(route('governance.certifications.dispatch-reminders'), {}, { preserveScroll: true });
}

function dueColour(d) {
    if (d === null) return 'text-on-surface-variant';
    if (d < 0)      return 'text-rose-700 font-bold';
    if (d <= 30)    return 'text-amber-700 font-bold';
    return 'text-emerald-700';
}
function dueLabel(d) {
    if (d === null) return 'no expiry';
    if (d < 0)      return `${Math.abs(d)}d overdue`;
    if (d === 0)    return 'today';
    return `${d}d`;
}
</script>

<template>
<Head title="Certifications" />
    <div data-page-root="true">
        <div class="p-6 space-y-6 animate-reveal-up">
            <header class="flex items-center justify-between">
                <div>
                    <Link :href="route('governance.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← Governance</Link>
                    <div class="flex items-center gap-2 mt-1 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">verified</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Governance · Compliance certifications</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Certifications</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Tracked certifications across the organisation with automatic expiry reminders.
                    </p>
                </div>
                <div class="flex gap-2" v-if="canManage">
                    <button @click="dispatchReminders" type="button" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Send Reminders Now</button>
                    <button @click="showAdd = true" type="button" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white shadow-glow-sm btn-shimmer">+ Add Certification</button>
                </div>
            </header>

            <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
                <table v-if="props.certifications.data?.length" class="w-full text-sm">
                    <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                        <th class="p-4">Holder</th><th>Certification</th><th>Issuer</th><th>Issued</th><th>Expires</th><th>Status</th><th>Reminded</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="c in props.certifications.data" :key="c.id" class="border-t border-outline-variant/40">
                            <td class="p-4">{{ c.employee?.name ?? '—' }} <span class="text-xs text-on-surface-variant font-mono">({{ c.employee?.employee_no ?? '—' }})</span></td>
                            <td>{{ c.name }} <span v-if="c.credential_id" class="text-xs text-on-surface-variant font-mono">{{ c.credential_id }}</span></td>
                            <td class="text-xs">{{ c.issuer ?? '—' }}</td>
                            <td class="text-xs">{{ c.issued_at ?? '—' }}</td>
                            <td class="text-xs">{{ c.expires_at ?? '—' }}</td>
                            <td :class="['text-xs', dueColour(c.days_to_expiry)]">{{ dueLabel(c.days_to_expiry) }}</td>
                            <td class="text-xs">{{ c.reminder_sent_at ? new Date(c.reminder_sent_at).toLocaleDateString() : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="No certifications tracked yet." class="py-12" />
                <Pagination v-if="props.certifications.meta?.last_page > 1" :links="props.certifications.meta.links" class="p-4" />
            </section>
        </div>

        <SlidePanel :open="showAdd" @close="showAdd = false" title="Add Certification">
            <form @submit.prevent="createCert" class="space-y-3 p-4">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Employee</label><select v-model="newCert.employee_id" aria-label="Employee holding this certification" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option value="" disabled>Select…</option><option v-for="e in props.employees" :key="e.id" :value="e.id">{{ e.employee_no }} — {{ e.position }}</option></select><InputError :message="newCert.errors.employee_id" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Certification Name</label><input v-model="newCert.name" aria-label="Certification name" maxlength="200" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /><InputError :message="newCert.errors.name" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Issuer</label><input v-model="newCert.issuer" aria-label="Issuing body" maxlength="200" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /><InputError :message="newCert.errors.issuer" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Credential ID</label><input v-model="newCert.credential_id" aria-label="Credential ID" maxlength="120" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 font-mono mt-1" /><InputError :message="newCert.errors.credential_id" /></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Issued</label><input v-model="newCert.issued_at" aria-label="Issued date" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /><InputError :message="newCert.errors.issued_at" /></div>
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Expires</label><input v-model="newCert.expires_at" aria-label="Expiry date" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /><InputError :message="newCert.errors.expires_at" /></div>
                </div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Verification URL</label><input v-model="newCert.verification_url" aria-label="Verification URL" type="url" maxlength="500" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /><InputError :message="newCert.errors.verification_url" /></div>
                <button type="submit" :disabled="newCert.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Add Certification</button>
            </form>
        </SlidePanel>
    </div>
</template>
