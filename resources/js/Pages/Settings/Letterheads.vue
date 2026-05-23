<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    templates:    { type: Object, required: true },
    canManageOrg: { type: Boolean, default: false },
    departmentId: { type: Number, default: null },
});

const scope = ref('personal');
const form  = useForm({ name: '', owner_scope: 'personal', owner_id: null, header_height_mm: 36, file: null });

function submit() {
    form.transform((d) => ({
        ...d,
        owner_id: d.owner_scope === 'department' ? props.departmentId : null,
    })).post(route('settings.letterheads.store'), {
        forceFormData: true, onSuccess: () => form.reset(),
    });
}

function remove(t) {
    if (! confirm(`Remove letterhead "${t.name}"?`)) return;
    router.delete(route('settings.letterheads.destroy', t.id), { preserveScroll: true });
}

const SCOPES = ['personal', 'department', 'organization'];
</script>

<template>
    <Head title="Letterheads" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">SETTINGS · LETTERHEADS</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Letterhead templates</h1>
                <p class="mt-1 text-[13px] text-on-surface-variant">Upload letterhead banners for the in-portal composer.</p>
            </div>
        </Teleport>

        <form @submit.prevent="submit" enctype="multipart/form-data"
              class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card mb-6 grid md:grid-cols-5 gap-3">
            <input v-model="form.name" required maxlength="120" placeholder="Template name"
                   class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
            <select v-model="form.owner_scope" aria-label="Scope" class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                <option v-for="s in SCOPES" :key="s" :value="s"
                        :disabled="(s === 'organization' && !canManageOrg) || (s === 'department' && !departmentId)">{{ s }}</option>
            </select>
            <input v-model.number="form.header_height_mm" type="number" min="20" max="80"
                   aria-label="Header height mm"
                   class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
            <input type="file" required accept="image/png,image/jpeg"
                   @change="e => form.file = e.target.files[0]" class="text-[12px]" />
            <button type="submit" :disabled="form.processing"
                    class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                {{ form.processing ? 'Uploading…' : 'Upload' }}
            </button>
        </form>

        <div class="flex gap-2 mb-3">
            <button v-for="s in SCOPES" :key="s" @click="scope = s"
                    :class="['rounded-xl px-3 py-1.5 text-[11px] font-black uppercase tracking-widest',
                             scope === s ? 'bg-secondary/10 text-secondary' : 'text-on-surface-variant']">{{ s }}</button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div v-for="t in templates.data.filter(x => x.owner_scope === scope)" :key="t.id"
                 class="rounded-xl border border-outline-variant/50 bg-surface-container-lowest p-3 shadow-card">
                <img :src="t.preview_url" :alt="t.name" class="w-full max-h-32 object-contain bg-white rounded" />
                <div class="mt-2 flex items-center justify-between">
                    <div>
                        <p class="text-[12px] font-black">{{ t.name }} <span v-if="t.is_default" class="ml-2 text-[10px] uppercase font-black text-emerald-700">default</span></p>
                        <p class="text-[10px] text-on-surface-variant">height {{ t.header_height_mm }} mm</p>
                    </div>
                    <button v-if="!t.is_default" @click="remove(t)" class="text-[11px] font-black text-rose-600">Remove</button>
                </div>
            </div>
        </div>
    </div>
</template>
