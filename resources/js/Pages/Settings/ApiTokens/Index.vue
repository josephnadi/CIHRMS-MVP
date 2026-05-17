<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    tokens:           Object,
    activeModule:     String,
    flash_token:      String,
    available_scopes: Array,
});

const showPanel = ref(false);
const copied = ref(false);

const form = useForm({
    name:            '',
    purpose:         '',
    abilities:       [],
    rate_limit:      60,
    expires_in_days: 365,
});

const submit = () => form.post(route('api-tokens.store'), {
    preserveScroll: true,
    onSuccess: () => { showPanel.value = false; form.reset(); form.abilities = []; },
});

const revoke = (id) => {
    if (confirm('Revoke this token? Any system using it will be disconnected immediately.')) {
        router.delete(route('api-tokens.destroy', id), { preserveScroll: true });
    }
};

const copy = async () => {
    try { await navigator.clipboard.writeText(props.flash_token); copied.value = true; setTimeout(() => copied.value = false, 2500); } catch (e) {}
};
</script>

<template>
    <Head title="API Tokens" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-on-surface-variant/60">Sanctum personal access tokens · Scoped &amp; rate-limited</p>
                    <h1 class="text-2xl font-semibold tracking-tight">API Tokens</h1>
                </div>
                <PrimaryButton @click="showPanel = true">+ Issue token</PrimaryButton>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <!-- One-time flash of the plaintext token -->
            <div v-if="flash_token"
                 class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800 mb-2">⚠ Save this token now — you will not see it again</p>
                <div class="flex items-center gap-3">
                    <code class="flex-1 font-mono text-sm bg-white border border-amber-200 rounded-lg px-3 py-2 break-all">{{ flash_token }}</code>
                    <PrimaryButton @click="copy">{{ copied ? 'Copied!' : 'Copy' }}</PrimaryButton>
                </div>
                <p class="text-xs text-on-surface-variant mt-3">
                    Use as <code class="bg-white px-1.5 py-0.5 rounded">Authorization: Bearer &lt;token&gt;</code>.
                </p>
            </div>

            <!-- Token table -->
            <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                <table class="w-full text-sm">
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Name</th>
                            <th class="px-5 py-3 text-left">Purpose</th>
                            <th class="px-5 py-3 text-left">Issued to</th>
                            <th class="px-5 py-3 text-left">Scopes</th>
                            <th class="px-5 py-3 text-left">Last used</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="t in tokens.data" :key="t.id" class="hover:bg-surface-container-low/60">
                            <td class="px-5 py-3 font-medium">{{ t.name }}</td>
                            <td class="px-5 py-3 text-xs text-on-surface-variant">{{ t.meta?.purpose ?? '—' }}</td>
                            <td class="px-5 py-3 text-xs">{{ t.meta?.issued_to ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="a in (t.abilities ?? [])" :key="a"
                                          class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-brand-navy/10 text-brand-navy">{{ a }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-xs">{{ t.last_used ? new Date(t.last_used).toLocaleString('en-GH') : 'never' }}</td>
                            <td class="px-5 py-3">
                                <StatusBadge v-if="t.meta?.is_usable" status="active" label="Active" />
                                <StatusBadge v-else-if="t.meta?.revoked_at" status="cancelled" label="Revoked" />
                                <StatusBadge v-else status="expired" label="Expired" />
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button @click="revoke(t.id)" class="text-rose-600 text-xs hover:underline">Revoke</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-outline-variant/40">
                    <Pagination :links="tokens?.meta?.links ?? []" />
                </div>
            </div>
        </div>

        <SlidePanel v-model="showPanel" title="Issue API token">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Token name</label>
                    <input v-model="form.name" required maxlength="120" placeholder="GIFMIS production integration"
                           class="w-full rounded-lg border-outline-variant text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Purpose (audit note)</label>
                    <textarea v-model="form.purpose" rows="2"
                              class="w-full rounded-lg border-outline-variant text-sm"
                              placeholder="What system will use this token, and why?"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Scopes</label>
                    <div class="grid grid-cols-2 gap-1.5 max-h-48 overflow-y-auto rounded-lg border border-outline-variant/40 p-3 bg-surface-container-low/40">
                        <label v-for="s in available_scopes" :key="s" class="flex items-center gap-2 text-xs cursor-pointer">
                            <input type="checkbox" :value="s" v-model="form.abilities" :aria-label="`Grant ${s} scope`">
                            <code class="font-mono">{{ s }}</code>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Rate limit / min</label>
                        <input v-model.number="form.rate_limit" type="number" min="1" max="6000"
                               class="w-full rounded-lg border-outline-variant text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Expires in days</label>
                        <input v-model.number="form.expires_in_days" type="number" min="1" max="3650"
                               class="w-full rounded-lg border-outline-variant text-sm">
                    </div>
                </div>
                <PrimaryButton type="submit" :disabled="form.processing || !form.name || form.abilities.length === 0">Issue token</PrimaryButton>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>
