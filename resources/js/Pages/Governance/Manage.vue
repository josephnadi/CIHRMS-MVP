<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ policies: Object });

const showCreate = ref(false);

const newPolicy = useForm({
    title: '', slug: '', category: 'hr', summary: '',
    is_active: true, initial_body: '# Policy body\n\nReplace this with the policy text.',
});

function createPolicy() {
    newPolicy.post(route('governance.policies.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; newPolicy.reset(); },
    });
}

const categoryLabel = {
    hr: 'HR', finance: 'Finance', it: 'IT', compliance: 'Compliance',
    safety: 'Safety', conduct: 'Conduct', other: 'Other',
};
</script>

<template>
<Head title="Manage Policies" />
    <div data-page-root="true">
        <div class="p-6 space-y-6 animate-reveal-up">
            <header class="flex items-center justify-between">
                <div>
                    <Link :href="route('governance.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← Governance</Link>
                    <div class="flex items-center gap-2 mt-1 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">policy</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Governance · Policy lifecycle</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Manage Policies</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Create policies, draft new versions, publish for organisation-wide acknowledgement.
                    </p>
                </div>
                <button @click="showCreate = true" type="button" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white shadow-glow-sm btn-shimmer">+ New Policy</button>
            </header>

            <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
                <table v-if="props.policies.data?.length" class="w-full text-sm">
                    <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                        <th class="p-4">Title</th><th>Slug</th><th>Category</th><th>Owner</th><th>Current Version</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="p in props.policies.data" :key="p.id" class="border-t border-outline-variant/40 hover:bg-surface-container-low/30 transition-colors">
                            <td class="p-4 font-bold"><Link :href="route('governance.policies.show', p.id)" class="text-secondary hover:underline">{{ p.title }}</Link></td>
                            <td class="text-xs font-mono text-on-surface-variant">{{ p.slug }}</td>
                            <td class="text-xs">{{ categoryLabel[p.category] }}</td>
                            <td class="text-xs">{{ p.owner?.name ?? '—' }}</td>
                            <td class="text-xs">{{ p.current_version ? `v${p.current_version.version_number}` : 'no version' }}</td>
                            <td><span v-if="p.is_active" class="text-[10px] font-bold text-emerald-700">ACTIVE</span><span v-else class="text-[10px] font-bold text-on-surface-variant">archived</span></td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="No policies created yet." class="py-12" />
            </section>
        </div>

        <SlidePanel :open="showCreate" @close="showCreate = false" title="Create Policy" size="lg">
            <form @submit.prevent="createPolicy" class="space-y-3 p-4">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Title</label><input v-model="newPolicy.title" aria-label="Policy title" maxlength="200" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Slug (optional, auto-derived)</label><input v-model="newPolicy.slug" aria-label="URL slug (optional)" maxlength="200" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 font-mono lowercase mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Category</label><select v-model="newPolicy.category" aria-label="Policy category" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option v-for="(label, key) in categoryLabel" :key="key" :value="key">{{ label }}</option></select></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Summary</label><textarea v-model="newPolicy.summary" aria-label="Policy summary" maxlength="1000" rows="2" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Initial Body (markdown — # for headings, - for bullets)</label><textarea v-model="newPolicy.initial_body" aria-label="Initial policy body (markdown)" rows="8" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm font-mono mt-1" /></div>
                <button type="submit" :disabled="newPolicy.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Create Draft</button>
            </form>
        </SlidePanel>
    </div>
</template>
