<script setup>
import { reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    logs:         Object,
    filters:      Object,
    activeModule: String,
});

const localFilters = reactive({
    search:  props.filters?.search  ?? '',
    user_id: props.filters?.user_id ?? '',
});

const applyFilters = () => {
    router.get(route('audit-logs.index'), {
        search:  localFilters.search  || undefined,
        user_id: localFilters.user_id || undefined,
    }, { preserveState: true, replace: true });
};

let searchTimer = null;
watch(() => localFilters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

const methodClasses = {
    GET:    'bg-slate-100 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300',
    POST:   'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
    PATCH:  'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    PUT:    'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    DELETE: 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300',
};

const formatDateTime = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-GB', {
        day:   '2-digit',
        month: 'short',
        year:  'numeric',
        hour:  '2-digit',
        minute:'2-digit',
        second:'2-digit',
    });
};
</script>

<template>
    <Head title="Audit Logs" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Audit Logs</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Immutable trail of system activity for compliance and forensic review.
                    </p>
                </div>
                <div class="inline-flex items-center gap-2 rounded-full bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 px-3 py-1.5">
                    <span class="material-symbols-outlined text-[16px] text-amber-700 dark:text-amber-400">shield</span>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">
                        {{ logs?.meta?.total ?? 0 }} events recorded
                    </span>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[260px] max-w-md">
                    <SearchInput v-model="localFilters.search" placeholder="Search path or action…" />
                </div>
                <input
                    v-model="localFilters.user_id"
                    @keyup.enter="applyFilters"
                    type="number"
                    placeholder="User ID"
                    class="w-32 rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                />
                <button
                    v-if="localFilters.search || localFilters.user_id"
                    @click="() => { localFilters.search = ''; localFilters.user_id = ''; applyFilters(); }"
                    class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-[16px]">close</span>
                    Clear
                </button>
            </div>

            <!-- Table -->
            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                <div v-if="logs?.data?.length === 0" class="p-12">
                    <EmptyState
                        title="No audit log entries"
                        description="No system activity matches the current filters."
                        icon="shield"
                    />
                </div>

                <div v-else class="max-h-[calc(100vh-360px)] min-h-[280px] overflow-auto">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Time</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">User</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Method</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Path</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Action</th>
                                <th class="bg-surface-container-low px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/40">
                            <tr v-for="log in logs.data" :key="log.id" class="hover:bg-surface-container/40 transition-colors">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="text-[11px] font-mono text-on-surface-variant">{{ formatDateTime(log.created_at) }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-[12px] font-semibold text-on-surface">{{ log.user?.name ?? '—' }}</p>
                                    <p class="text-[10px] text-on-surface-variant/60">#{{ log.user_id ?? '?' }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-mono font-bold', methodClasses[log.method] ?? methodClasses.GET]">
                                        {{ log.method }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 max-w-md">
                                    <span class="text-[12px] font-mono text-on-surface truncate block" :title="log.path">{{ log.path }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-[12px] text-on-surface-variant">{{ log.action ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-[11px] font-mono text-on-surface-variant/70">{{ log.ip_address ?? '—' }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="logs?.total > logs?.per_page" class="border-t border-outline-variant/50 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <p class="text-[12px] text-on-surface-variant">
                            Showing
                            <span class="font-semibold text-on-surface">{{ logs.from }}</span>
                            –
                            <span class="font-semibold text-on-surface">{{ logs.to }}</span>
                            of
                            <span class="font-semibold text-on-surface">{{ logs.total }}</span>
                        </p>
                        <Pagination :links="logs.links" />
                    </div>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
