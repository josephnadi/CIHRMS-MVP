<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useSafeHtml } from '@/composables/useSafeHtml';

const sanitize = useSafeHtml();

defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    policy:   Object,
    versions: Object,
    current:  Object,
});

const ackForm = useForm({ signed_full_name: '' });

function submitAck() {
    if (! props.current?.id) return;
    ackForm.post(route('governance.versions.ack', props.current.id), {
        preserveScroll: true,
        onSuccess: () => ackForm.reset(),
    });
}

const renderedBody = computed(() => {
    const body = props.current?.body ?? '';
    const rendered = body
        .split(/\n\n+/)
        .map(block => {
            if (/^#\s+/.test(block)) return `<h1 class="text-2xl font-black text-primary mt-6 mb-3">${block.replace(/^#\s+/, '')}</h1>`;
            if (/^##\s+/.test(block)) return `<h2 class="text-xl font-black text-primary mt-5 mb-2">${block.replace(/^##\s+/, '')}</h2>`;
            if (/^-\s+/m.test(block)) {
                const items = block.split(/\n/).map(line => `<li class="ml-6 list-disc">${line.replace(/^-\s+/, '')}</li>`).join('');
                return `<ul class="my-3">${items}</ul>`;
            }
            return `<p class="my-3 text-on-surface leading-relaxed">${block.replace(/\n/g, '<br>')}</p>`;
        })
        .join('');
    return sanitize(rendered);
});

const myAckStatus = computed(() => props.policy.data?.my_ack_status ?? 'no_version');
</script>

<template>
<Head :title="policy.data.title" />
    <div data-page-root="true">
        <div class="p-6 space-y-6 animate-reveal-up max-w-6xl mx-auto">
            <header>
                <Link :href="route('governance.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← All Policies</Link>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary mt-1">{{ policy.data.title }}</h1>
                <p class="text-sm text-on-surface-variant">{{ policy.data.summary }}</p>
                <p v-if="current" class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant/70 mt-2">
                    Version {{ current.data.version_number }} · Effective {{ current.data.effective_from ?? '—' }}
                    <span v-if="current.data.published_at"> · Published {{ new Date(current.data.published_at).toLocaleDateString() }}</span>
                </p>
            </header>

            <div class="grid grid-cols-12 gap-6">
                <main class="col-span-12 lg:col-span-9 space-y-6">
                    <article v-if="current" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8 card-lift">
                        <div class="prose max-w-none" v-html="renderedBody"></div>
                    </article>
                    <article v-else class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8 text-center">
                        <p class="text-on-surface-variant">This policy has no published version yet.</p>
                    </article>

                    <section v-if="current && myAckStatus === 'pending'" class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-6">
                        <h2 class="text-lg font-black text-amber-900">Acknowledge this policy</h2>
                        <p class="mt-1 text-sm text-amber-800">Type your full name (matching the name on your account) to record acknowledgement. Your timestamp, IP address, and browser will be captured for audit.</p>
                        <form @submit.prevent="submitAck" class="mt-4 space-y-3">
                            <input aria-label="Signed full name" v-model="ackForm.signed_full_name" required maxlength="120"
                                :placeholder="$page.props.auth.user.name"
                                class="w-full max-w-md rounded-xl border border-amber-300 bg-surface-container-lowest px-3 py-2 text-sm" />
                            <button type="submit" :disabled="ackForm.processing" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-5 py-2 text-sm font-bold text-white shadow-glow-sm">
                                Acknowledge
                            </button>
                        </form>
                    </section>

                    <section v-else-if="myAckStatus === 'acknowledged'" class="rounded-2xl border border-emerald-300 bg-emerald-50 p-4">
                        <p class="text-sm font-bold text-emerald-900">You have acknowledged this version.</p>
                    </section>
                </main>

                <aside class="col-span-12 lg:col-span-3 space-y-4">
                    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 card-lift">
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Version History</h3>
                        <ul class="mt-3 space-y-2">
                            <li v-for="v in versions.data" :key="v.id"
                                class="text-xs border-l-2 pl-3 py-1"
                                :class="v.id === current?.data?.id ? 'border-primary' : 'border-outline-variant'">
                                <p class="font-bold">v{{ v.version_number }}</p>
                                <p v-if="v.published_at" class="text-on-surface-variant">{{ new Date(v.published_at).toLocaleDateString() }}</p>
                                <p v-else class="text-on-surface-variant italic">draft</p>
                                <p v-if="v.changelog" class="text-on-surface-variant/80 mt-0.5">{{ v.changelog }}</p>
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>
        </div>
    </div>
</template>
