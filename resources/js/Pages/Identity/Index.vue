<script setup>
import { reactive, computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import BiometricCapture from '@/Components/Identity/BiometricCapture.vue';
import EmptyState from '@/Components/EmptyState.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    verifications: Object,
    stats:         Object,
    filters:       Object,
    activeModule:  String,
});

const form = useForm({
    employee_id: '',
    ghana_card_number: '',
    evidence: null,
});

const showCapture = ref(false);
const capturedName = ref('');

function onBiometricCaptured(file) {
    form.evidence = file;
    capturedName.value = file.name;
    showCapture.value = false;
}

function clearCapture() {
    form.evidence = null;
    capturedName.value = '';
}

const submit = () => form.post(route('identity.store'), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
        form.reset();
        capturedName.value = '';
    },
});

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
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">verified_user</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">IDENTITY REGISTER · GHANA CARD</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Ghana Card Verification</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            NIA-aligned register under Act 750 · SHA-256 hashed lookup · payroll disbursement gated on verified records.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="#identity-verify"
                           class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                           style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">how_to_reg</span>
                            Submit Verification
                        </a>
                    </div>
                </div>
            </Teleport>

            <div id="identity-verify" class="py-6 space-y-6">
                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40 p-5">
                    <h2 class="text-sm font-semibold mb-3">Submit a new verification</h2>
                    <form @submit.prevent="submit" class="grid md:grid-cols-3 gap-3">
                        <input v-model="form.employee_id" type="number" placeholder="Employee ID"
                               class="rounded-lg border-outline-variant text-sm" required>
                        <input v-model="form.ghana_card_number" placeholder="GHA-123456789-1"
                               class="rounded-lg border-outline-variant text-sm" required>
                        <PrimaryButton type="submit" :disabled="form.processing">Verify</PrimaryButton>
                    </form>

                    <!-- Biometric evidence: live capture or file upload. The captured JPEG flows
                         into form.evidence; backend stores it under identity_evidence/ and
                         pins it to the IdentityVerification row for audit. -->
                    <div class="mt-3 flex items-center gap-2 flex-wrap">
                        <button type="button" @click="showCapture = true"
                                class="flex items-center gap-2 rounded-lg border border-outline-variant px-3 py-2 text-xs font-bold hover:bg-surface-container/40 transition-colors">
                            <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">photo_camera</span>
                            Capture biometric photo
                        </button>
                        <label class="inline-flex items-center gap-2 cursor-pointer text-xs text-on-surface-variant">
                            <span class="rounded-lg border border-outline-variant px-3 py-2 font-bold hover:bg-surface-container/40 transition-colors">
                                Or upload scan
                            </span>
                            <input type="file" accept=".jpg,.jpeg,.png,.pdf" class="hidden"
                                   @change="(e) => { form.evidence = e.target.files[0]; capturedName = e.target.files[0]?.name ?? ''; }" />
                        </label>
                        <span v-if="capturedName"
                              class="inline-flex items-center gap-1.5 rounded-lg bg-secondary/[0.06] border border-secondary/30 px-2.5 py-1 text-[11px] font-bold text-secondary">
                            <span class="material-symbols-outlined text-[12px]">verified</span>
                            {{ capturedName }}
                            <button type="button" @click="clearCapture" class="ml-1 hover:text-rose-600">×</button>
                        </span>
                    </div>

                    <p v-if="form.errors.ghana_card_number" class="text-rose-600 text-xs mt-2">{{ form.errors.ghana_card_number }}</p>
                    <p v-if="form.errors.evidence" class="text-rose-600 text-xs mt-1">{{ form.errors.evidence }}</p>
                </div>

                <BiometricCapture :open="showCapture" @captured="onBiometricCaptured" @close="showCapture = false" />

                <div id="identity-register" class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                    <div v-if="!(verifications?.data?.length)">
                        <EmptyState
                            title="No verifications submitted yet"
                            description="Submit a Ghana Card above to begin building the verified-identity register for this tenant."
                            icon="badge"
                        />
                    </div>
                    <table v-else class="w-full text-sm">
                        <thead class="bg-surface-container text-on-surface-variant text-xs uppercase">
                            <tr>
                                <th class="px-5 py-3 text-left">Employee</th>
                                <th class="px-5 py-3 text-left">Card</th>
                                <th class="px-5 py-3 text-left">Provider</th>
                                <th class="px-5 py-3 text-left">Status</th>
                                <th class="px-5 py-3 text-left">Verified at</th>
                                <th class="px-5 py-3 text-left">Expires</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="v in verifications.data" :key="v.id" class="hover:bg-surface-container/40">
                                <td class="px-5 py-3">
                                    <div class="font-medium">{{ v.employee?.name ?? '—' }}</div>
                                    <div class="text-xs text-on-surface-variant">{{ v.employee?.employee_no }}</div>
                                </td>
                                <td class="px-5 py-3 font-mono text-xs">{{ v.masked_card }}</td>
                                <td class="px-5 py-3">{{ v.provider_label }}</td>
                                <td class="px-5 py-3"><StatusBadge :status="v.status" :label="v.status_label" /></td>
                                <td class="px-5 py-3">{{ v.verified_at ? new Date(v.verified_at).toLocaleDateString('en-GH') : '—' }}</td>
                                <td class="px-5 py-3">{{ v.expires_at ? new Date(v.expires_at).toLocaleDateString('en-GH') : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="px-5 py-3 border-t border-outline-variant/40">
                        <Pagination :links="verifications?.meta?.links ?? []" />
                    </div>
                </div>
            </div>
    </div>
</template>
