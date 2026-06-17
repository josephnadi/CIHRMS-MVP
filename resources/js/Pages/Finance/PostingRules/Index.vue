<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    rules:      { type: Object, required: true },
    glAccounts: { type: Array,  default: () => [] },
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('finance.posting_rules.manage');
});

const rows = computed(() => props.rules.data ?? props.rules ?? []);
const grouped = computed(() => {
    const out = {};
    for (const r of rows.value) (out[r.domain] ??= []).push(r);
    return out;
});
const optionsFor = (rule) => props.glAccounts.filter((a) => a.type === rule.gl_account?.type);

const editingId = ref(null);
const form = useForm({ gl_account_id: null });

const startEdit = (rule) => {
    editingId.value = rule.id;
    form.clearErrors();
    form.gl_account_id = rule.gl_account_id;
};
const cancelEdit = () => { editingId.value = null; form.clearErrors(); };

const save = (rule) => form.patch(route('finance.posting-rules.update', rule.id), {
    preserveScroll: true,
    onSuccess: () => { editingId.value = null; },
});
</script>

<template>
    <Head title="Posting Rules" />

    <div class="space-y-6 animate-reveal-up">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary"
                          style="font-variation-settings:'FILL' 1">rule_settings</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE · UNIVERSAL POSTING</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Posting Rules</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant max-w-2xl">
                    Posting rules map each financial event to the GL account it debits or credits when journals are generated.
                    Locked rules are system-critical and cannot be re-pointed.
                </p>
            </div>
        </div>

        <!-- Grouped sections -->
        <template v-if="rows.length">
            <section v-for="(groupRules, domain) in grouped" :key="domain"
                     class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <div class="flex items-center gap-2 px-5 py-3 border-b border-outline-variant/40 bg-surface-container">
                    <span class="material-symbols-outlined text-[16px] text-on-surface-variant">folder_open</span>
                    <h4 class="text-[12px] font-black uppercase tracking-[0.14em] text-on-surface-variant">{{ domain }}</h4>
                    <span class="ml-auto text-[10px] font-bold text-on-surface-variant/60">
                        {{ groupRules.length }} rule{{ groupRules.length === 1 ? '' : 's' }}
                    </span>
                </div>

                <div class="divide-y divide-outline-variant/30">
                    <div v-for="rule in groupRules" :key="rule.id" class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <!-- Rule identity -->
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-mono text-[12px] font-bold text-primary">{{ rule.slug }}</span>
                                    <span v-if="rule.locked"
                                          class="inline-flex items-center gap-1 rounded-full border border-outline-variant bg-surface-container px-2 py-0.5 text-[9px] font-black uppercase text-on-surface-variant">
                                        <span class="material-symbols-outlined text-[12px]">lock</span>locked
                                    </span>
                                </div>
                                <p v-if="rule.description" class="mt-0.5 text-[11.5px] font-medium text-on-surface-variant">
                                    {{ rule.description }}
                                </p>
                            </div>

                            <!-- Current account + action -->
                            <div class="flex flex-col items-end gap-1.5 min-w-[14rem]">
                                <p class="text-[12px] font-bold text-primary text-right">
                                    <span v-if="rule.gl_account" class="font-mono">{{ rule.gl_account.code }}</span>
                                    <span v-if="rule.gl_account"> — {{ rule.gl_account.name }}</span>
                                    <span v-else class="text-on-surface-variant">Unmapped</span>
                                </p>
                                <button v-if="!rule.locked && canManage && editingId !== rule.id"
                                        @click="startEdit(rule)"
                                        class="inline-flex items-center gap-1 text-[11px] font-bold text-secondary hover:underline">
                                    <span class="material-symbols-outlined text-[14px]">swap_horiz</span>Re-point
                                </button>
                            </div>
                        </div>

                        <!-- Inline editor -->
                        <div v-if="!rule.locked && canManage && editingId === rule.id"
                             class="mt-3 rounded-xl border border-outline-variant/60 bg-surface-container p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <select v-model="form.gl_account_id" aria-label="Re-point GL account"
                                        class="flex-1 min-w-[16rem] rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                                    <option :value="null">— Select account</option>
                                    <option v-for="a in optionsFor(rule)" :key="a.id" :value="a.id">
                                        {{ a.code }} — {{ a.name }}
                                    </option>
                                </select>
                                <PrimaryButton type="button" :disabled="form.processing" @click="save(rule)">Save</PrimaryButton>
                                <button type="button" @click="cancelEdit"
                                        class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">
                                    Cancel
                                </button>
                            </div>
                            <InputError :message="form.errors.gl_account_id" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>
        </template>

        <EmptyState v-else icon="rule_settings" title="No posting rules"
                    description="No posting rules are configured yet." />
    </div>
</template>
