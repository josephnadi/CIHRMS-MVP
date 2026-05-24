<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import Scanner from '@/Components/Documents/Scanner.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    documents:    Object,
    tab:          String,
    filters:      Object,
    inboxCount:   Number,
    activeModule: String,
});

const TABS = [
    { id: 'all',     label: 'All' },
    { id: 'inbox',   label: 'Inbox' },
    { id: 'sent',    label: 'Sent' },
    { id: 'drafts',  label: 'Drafts' },
    { id: 'archive', label: 'Archive' },
];

const q = ref(props.filters?.q ?? '');

function setTab(id) {
    router.get(route('documents.index'), { tab: id, q: q.value }, { preserveState: true, preserveScroll: true });
}
function search() {
    router.get(route('documents.index'), { tab: props.tab, q: q.value }, { preserveState: true });
}

const showUpload = ref(false);
const showScanner = ref(false);
const scanFileName = ref('');
const form = useForm({ title: '', description: '', confidentiality: 'internal', file: null, tags: [] });

function submit() {
    form.post(route('documents.store'), {
        forceFormData: true,
        onSuccess: () => {
            showUpload.value = false;
            scanFileName.value = '';
            form.reset();
        },
    });
}

/**
 * Scanner returned a captured JPEG. Attach it to the upload form, open the
 * standard upload panel so the user can title/describe the scan, then close
 * the scanner. The actual POST goes through the same /documents endpoint —
 * no separate "scanned doc" pipeline needed.
 */
function onScanCaptured(file) {
    form.file = file;
    scanFileName.value = file.name;
    if (!form.title) {
        form.title = `Scan · ${new Date().toLocaleDateString('en-GB')}`;
    }
    showScanner.value = false;
    showUpload.value = true;
}

const tone = (status) => ({
    draft:     'bg-slate-100 text-slate-700',
    in_review: 'bg-amber-50 text-amber-900',
    completed: 'bg-emerald-50 text-emerald-800',
    rejected:  'bg-rose-50 text-rose-800',
    withdrawn: 'bg-slate-100 text-slate-500',
    archived:  'bg-slate-100 text-slate-500',
}[status] ?? 'bg-slate-100 text-slate-700');
</script>

<template>
    <Head title="Documents" />
    <div data-page-root="true" class="space-y-6 animate-reveal-up">
        <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">description</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">DOCUMENT REGISTER</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Documents</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Upload, route, sign and stamp documents across the institute.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('documents.compose')"
                              class="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2.5 text-[13px] font-black text-primary shadow-card transition-all hover:-translate-y-px hover:shadow-card-hover">
                            <span class="material-symbols-outlined text-[17px]">edit_note</span>
                            Compose Letter
                        </Link>
                        <button @click="showScanner = true"
                                class="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2.5 text-[13px] font-black text-primary shadow-card transition-all hover:-translate-y-px hover:shadow-card-hover"
                                title="Use the device camera to scan paper documents">
                            <span class="material-symbols-outlined text-[17px]">document_scanner</span>
                            Scan
                        </button>
                        <button @click="showUpload = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">upload_file</span>
                            Upload Document
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-5">
                <!-- Tabs -->
                <div class="inline-flex items-center gap-1 rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-1 shadow-card"
                     role="tablist" aria-label="Document categories">
                    <button v-for="t in TABS" :key="t.id" @click="setTab(t.id)"
                            role="tab"
                            :aria-selected="tab === t.id"
                            :tabindex="tab === t.id ? 0 : -1"
                            :class="['rounded-xl px-4 py-2 text-[12px] font-black transition-all',
                                     tab === t.id ? 'bg-secondary/10 text-secondary' : 'text-on-surface-variant hover:text-primary']">
                        {{ t.label }}
                        <span v-if="t.id === 'inbox' && inboxCount > 0"
                              class="ml-1.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-rose-600 px-1.5 text-[10px] font-black text-white">
                            {{ inboxCount }}
                        </span>
                    </button>
                </div>

                <!-- Search -->
                <div class="flex items-center gap-3">
                    <input v-model="q" @keyup.enter="search" placeholder="Search title or ref no…"
                           class="flex-1 max-w-md rounded-xl border border-outline-variant bg-surface-container-lowest text-[13px] px-3 py-2 font-semibold" />
                    <button @click="search" class="rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2 text-[12px] font-black">Search</button>
                </div>

                <!-- List -->
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="border-b border-outline-variant">
                            <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant">
                                <th class="px-5 py-3">Ref</th>
                                <th class="px-5 py-3">Title</th>
                                <th class="px-5 py-3">Owner</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Updated</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in documents.data" :key="d.id" class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                                <td class="px-5 py-3 font-mono text-[12px] font-bold text-primary">{{ d.ref_no }}</td>
                                <td class="px-5 py-3 font-black">{{ d.title }}</td>
                                <td class="px-5 py-3 text-on-surface-variant">{{ d.owner?.name }}</td>
                                <td class="px-5 py-3">
                                    <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-widest', tone(d.status)]">
                                        {{ d.status_label }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-[12px] text-on-surface-variant">{{ new Date(d.updated_at).toLocaleDateString('en-GB') }}</td>
                                <td class="px-5 py-3 text-right">
                                    <Link :href="route('documents.show', d.uuid)" class="text-[12px] font-black text-secondary">Open</Link>
                                </td>
                            </tr>
                            <tr v-if="!documents.data?.length">
                                <td colspan="6" class="px-5 py-12 text-center text-on-surface-variant text-[13px]">No documents in this view.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Upload slide panel -->
            <SlidePanel :open="showUpload" title="Upload Document" @close="showUpload = false">
                <form @submit.prevent="submit" enctype="multipart/form-data" class="space-y-4">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Title</label>
                        <input v-model="form.title" required maxlength="255" aria-label="Document title"
                               class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
                        <p v-if="form.errors.title" class="text-rose-600 text-xs mt-1">{{ form.errors.title }}</p>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Description</label>
                        <textarea v-model="form.description" rows="3" aria-label="Document description"
                                  class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]"></textarea>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Confidentiality</label>
                        <select v-model="form.confidentiality" aria-label="Confidentiality level"
                                class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                            <option value="internal">Internal</option>
                            <option value="confidential">Confidential</option>
                            <option value="restricted">Restricted</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">File (PDF, DOCX, PNG, JPG · ≤ 25 MB)</label>
                        <div v-if="scanFileName"
                             class="mb-2 flex items-center justify-between gap-2 rounded-lg border border-secondary/30 bg-secondary/[0.06] px-3 py-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="material-symbols-outlined text-[16px] text-secondary">document_scanner</span>
                                <span class="text-[12px] font-bold text-on-surface truncate">{{ scanFileName }}</span>
                                <span class="text-[10px] font-bold uppercase tracking-widest text-secondary/80">from scanner</span>
                            </div>
                            <button type="button" @click="() => { scanFileName = ''; form.file = null; }"
                                    class="text-[11px] font-bold text-on-surface-variant hover:text-rose-600 transition-colors">
                                Remove
                            </button>
                        </div>
                        <input v-if="!scanFileName" type="file" required accept=".pdf,.docx,.doc,.png,.jpg,.jpeg"
                               @change="(e) => form.file = e.target.files[0]"
                               class="w-full text-[12px]" />
                        <p v-if="form.errors.file" class="text-rose-600 text-xs mt-1">{{ form.errors.file }}</p>
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-outline-variant/40">
                        <button type="button" @click="showUpload = false" class="rounded-lg border border-outline-variant px-4 py-2 text-[12px] font-bold">Cancel</button>
                        <button type="submit" :disabled="form.processing"
                                class="rounded-lg px-4 py-2 text-[12px] font-black text-white shadow-glow-sm"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                            {{ form.processing ? 'Uploading…' : 'Upload' }}
                        </button>
                    </div>
                </form>
            </SlidePanel>

            <!-- Camera-based scanner. Captures a JPEG and hands it to the upload form. -->
            <Scanner :open="showScanner" @captured="onScanCaptured" @close="showScanner = false" />
    </div>
</template>
