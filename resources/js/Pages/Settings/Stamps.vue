<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    assets:       { type: Object, required: true },
    canManageOrg: { type: Boolean, default: false },
    departmentId: { type: Number, default: null },
    activeModule: { type: String, default: 'settings' },
});

const scope = ref('personal');
const form  = useForm({ name: '', owner_scope: 'personal', owner_id: null, file: null });

function submit() {
    form.transform((data) => ({
        ...data,
        owner_id: data.owner_scope === 'department' ? props.departmentId : null,
    })).post(route('settings.stamps.store'), {
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
}

function remove(asset) {
    if (! confirm(`Remove stamp "${asset.name}"?`)) return;
    router.delete(route('settings.stamps.destroy', asset.id), { preserveScroll: true });
}

const SCOPES = ['personal', 'department', 'organization'];
</script>

<template>
    <Head title="Stamp Library" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">SETTINGS · STAMPS</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Stamp library</h1>
                <p class="mt-1 text-[13px] text-on-surface-variant">Upload PNG stamps you can place on documents.</p>
            </div>
        </Teleport>

        <form @submit.prevent="submit" enctype="multipart/form-data"
              class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card mb-6 grid md:grid-cols-4 gap-3">
            <input aria-label="Name" v-model="form.name" required maxlength="120" placeholder="Stamp name"
                   class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
            <select v-model="form.owner_scope" aria-label="Scope"
                    class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                <option v-for="s in SCOPES" :key="s" :value="s"
                        :disabled="(s === 'organization' && !canManageOrg) || (s === 'department' && !departmentId)">
                    {{ s }}
                </option>
            </select>
            <input type="file" required accept="image/png" aria-label="Stamp file (PNG, up to 1 MB)"
                   @change="e => form.file = e.target.files[0]" class="text-[12px]" />
            <button type="submit" :disabled="form.processing"
                    class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                {{ form.processing ? 'Uploading…' : 'Upload PNG' }}
            </button>
            <p v-if="form.errors.file" class="md:col-span-4 text-rose-600 text-xs">{{ form.errors.file }}</p>
        </form>

        <div class="flex gap-2 mb-3">
            <button v-for="s in SCOPES" :key="s" @click="scope = s"
                    :class="['rounded-xl px-3 py-1.5 text-[11px] font-black uppercase tracking-widest',
                             scope === s ? 'bg-secondary/10 text-secondary' : 'text-on-surface-variant']">
                {{ s }}
            </button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div v-for="a in assets.data.filter(x => x.owner_scope === scope)" :key="a.id"
                 class="rounded-xl border border-outline-variant/50 bg-surface-container-lowest p-3 shadow-card">
                <img :src="a.preview_url" :alt="a.name" class="h-16 w-full object-contain bg-white rounded" />
                <p class="mt-2 text-[12px] font-black truncate">{{ a.name }}</p>
                <div class="flex justify-end mt-1">
                    <button @click="remove(a)" class="text-[11px] font-black text-rose-600">Remove</button>
                </div>
            </div>
            <p v-if="!assets.data.some(x => x.owner_scope === scope)" class="col-span-full text-center text-on-surface-variant text-[12px] py-6">
                No {{ scope }} stamps yet.
            </p>
        </div>
    </div>
</template>
