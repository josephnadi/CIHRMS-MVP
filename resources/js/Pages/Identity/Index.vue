<script setup>
import { reactive, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    verifications: Object,
    stats:         Object,
    filters:       Object,
    activeModule:  String,
});

const form = useForm({
    employee_id: '',
    ghana_card_number: '',
});

const submit = () => form.post(route('identity.store'), { preserveScroll: true });

// ── Editorial-Sovereign masthead label ───────────────────────────
// Volume = year offset from CIHRM-GH platform inception (2023).
// Issue  = day-of-year.
const editionLabel = computed(() => {
    const d   = new Date();
    const day = Math.floor((d - new Date(d.getFullYear(), 0, 0)) / 86_400_000);
    const vol = d.getFullYear() - 2023;
    const roman = (n) => {
        const map = [['M',1000],['CM',900],['D',500],['CD',400],['C',100],['XC',90],['L',50],['XL',40],['X',10],['IX',9],['V',5],['IV',4],['I',1]];
        let s = '';
        for (const [r, v] of map) while (n >= v) { s += r; n -= v; }
        return s;
    };
    return {
        date:    d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
    };
});

// Sub-strip derived count — resilient to whichever key the backend
// serialises (`failed` is the live controller key; `rejected` is the
// future Enum-driven name in IdentityVerificationStatus.php).
const rejectedCount = computed(() => props.stats?.rejected ?? props.stats?.failed ?? 0);
</script>

<template>
    <Head title="Ghana Card Verification" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <section class="space-y-8">

                <!-- ─── Masthead strip ────────────────────────────────────── -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">IDENTITY REGISTER · GHANA CARD</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Live · NIA-aligned register
                    </span>
                </div>

                <!-- ─── Broadsheet hero ───────────────────────────────────── -->
                <div class="es-broadsheet rounded-none">
                    <!-- LEAD column -->
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Ghana Card · NIA-aligned register</p>
                        <h2 class="es-display text-[clamp(2.4rem,5.5vw,4.6rem)]">
                            Identity,
                            <span class="es-display-italic block">verified.</span>
                        </h2>
                        <p class="es-display-sub">
                            Conducted under the National Identification Authority Act, 2006 (Act&nbsp;750).
                            Ghana Card numbers are stored only as a SHA-256 hash for lookup — never in cleartext —
                            and payroll disbursement for every active staff member is gated on a current,
                            verified record in this register.
                        </p>

                        <!-- Quick-action chips -->
                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <a href="#identity-verify" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">how_to_reg</span>
                                Submit verification
                            </a>
                            <span class="text-on-surface-variant/30">·</span>
                            <a href="#identity-register" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">menu_book</span>
                                View register
                            </a>
                        </div>
                    </div>

                    <!-- SIDEBAR column: Verified headline -->
                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Verified employees</p>
                            <p class="es-stat-hero-value">{{ (stats?.verified ?? 0).toLocaleString() }}</p>
                            <p class="es-stat-hero-caption">
                                Hash-matched against NIA · <span class="font-mono">{{ (stats?.unverified_employees ?? 0).toLocaleString() }}</span>
                                still outside the payroll gate
                            </p>
                            <span class="es-stat-hero-delta">
                                <span class="material-symbols-outlined text-[13px]">verified_user</span>
                                Act 750 compliant
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ─── Sub-metric strip ────────────────────────────────── -->
                <div class="es-stat-strip rounded-none">
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Verified</p>
                        <p class="es-stat-cell-value">{{ (stats?.verified ?? 0).toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Hash-matched against NIA</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Pending</p>
                        <p class="es-stat-cell-value">{{ (stats?.pending ?? 0).toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Awaiting registrar review</p>
                    </div>
                    <div class="es-stat-cell es-stat-cell--down">
                        <p class="es-stat-cell-label">Rejected</p>
                        <p class="es-stat-cell-value">{{ rejectedCount.toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Rejected by NIA endpoint</p>
                    </div>
                    <div class="es-stat-cell es-stat-cell--down">
                        <p class="es-stat-cell-label">Outside gate</p>
                        <p class="es-stat-cell-value">{{ (stats?.unverified_employees ?? 0).toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Active staff · payroll blocked</p>
                    </div>
                </div>
            </section>
        </template>

        <div id="identity-verify" class="py-6 space-y-6">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                <h2 class="text-sm font-semibold mb-3">Submit a new verification</h2>
                <form @submit.prevent="submit" class="grid md:grid-cols-3 gap-3">
                    <input v-model="form.employee_id" type="number" placeholder="Employee ID"
                           class="rounded-lg border-slate-200 text-sm" required>
                    <input v-model="form.ghana_card_number" placeholder="GHA-123456789-1"
                           class="rounded-lg border-slate-200 text-sm" required>
                    <PrimaryButton type="submit" :disabled="form.processing">Verify</PrimaryButton>
                </form>
                <p v-if="form.errors.ghana_card_number" class="text-rose-600 text-xs mt-2">{{ form.errors.ghana_card_number }}</p>
            </div>

            <div id="identity-register" class="bg-white rounded-2xl shadow-sm border border-slate-100">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Employee</th>
                            <th class="px-5 py-3 text-left">Card</th>
                            <th class="px-5 py-3 text-left">Provider</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-left">Verified at</th>
                            <th class="px-5 py-3 text-left">Expires</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="v in verifications.data" :key="v.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <div class="font-medium">{{ v.employee?.name ?? '—' }}</div>
                                <div class="text-xs text-slate-500">{{ v.employee?.employee_no }}</div>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs">{{ v.masked_card }}</td>
                            <td class="px-5 py-3">{{ v.provider_label }}</td>
                            <td class="px-5 py-3"><StatusBadge :status="v.status" :label="v.status_label" /></td>
                            <td class="px-5 py-3">{{ v.verified_at ? new Date(v.verified_at).toLocaleDateString('en-GH') : '—' }}</td>
                            <td class="px-5 py-3">{{ v.expires_at ? new Date(v.expires_at).toLocaleDateString('en-GH') : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">
                    <Pagination :links="verifications?.meta?.links ?? []" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
