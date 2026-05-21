<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    conversation: { type: Object, required: true },
    messages:     { type: Array,  default: () => [] },
    threads:      { type: Array,  default: () => [] },
    me:           { type: Object, required: true },
    unreadTotal:  { type: Number, default: 0 },
});

const liveMessages = ref([...props.messages]);
watch(() => props.messages, (m) => {
    liveMessages.value = [...m];
    scrollToBottom();
});

const form = useForm({ body: '' });

const messagesEl = ref(null);
function scrollToBottom(smooth = true) {
    nextTick(() => {
        const el = messagesEl.value;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    });
}

function submit() {
    if (!form.body.trim()) return;
    const optimistic = {
        id: 'tmp-' + Date.now(),
        sender_id: props.me.id,
        sender: { id: props.me.id, name: props.me.name },
        body: form.body,
        created_at: new Date().toISOString(),
        time: new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
        date: new Date().toISOString().slice(0, 10),
        _pending: true,
    };
    liveMessages.value.push(optimistic);
    scrollToBottom();

    form.post(route('chat.send', props.conversation.id), {
        preserveScroll: true,
        preserveState:  true,
        onSuccess: () => {
            form.reset('body');
            pollNow();
        },
        onError: () => {
            // Drop the optimistic bubble — server has rejected.
            liveMessages.value = liveMessages.value.filter(m => m.id !== optimistic.id);
        },
    });
}

// Polling — every 4s while the tab is visible. We poll only for ids
// greater than the highest id we've already seen, so a single poll never
// re-sends the same message twice.
let pollTimer = null;
const lastSeenId = computed(() => liveMessages.value
    .filter(m => typeof m.id === 'number')
    .reduce((max, m) => Math.max(max, m.id), 0));

async function pollNow() {
    if (document.hidden) return;
    try {
        const { data } = await axios.get(route('chat.poll', props.conversation.id), {
            params: { since: lastSeenId.value },
        });
        if (data.messages?.length) {
            // Drop optimistic placeholders if their server twin arrived
            liveMessages.value = liveMessages.value.filter(m => typeof m.id === 'number');
            liveMessages.value.push(...data.messages);
            scrollToBottom();
        }
    } catch (e) { /* swallow — next tick will retry */ }
}

onMounted(() => {
    scrollToBottom(false);
    pollTimer = setInterval(pollNow, 4000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) pollNow(); });
});
onBeforeUnmount(() => {
    if (pollTimer) clearInterval(pollTimer);
});

// Group messages by date for the day separators.
const grouped = computed(() => {
    const out = [];
    let lastDate = null;
    for (const m of liveMessages.value) {
        if (m.date !== lastDate) {
            out.push({ kind: 'separator', date: m.date });
            lastDate = m.date;
        }
        out.push({ kind: 'msg', ...m });
    }
    return out;
});

function deleteMessage(id) {
    if (!window.confirm('Delete this message for everyone?')) return;
    router.delete(route('chat.messages.destroy', id), {
        preserveScroll: true,
        onSuccess: () => {
            liveMessages.value = liveMessages.value.filter(m => m.id !== id);
        },
    });
}

const otherName = computed(() => props.conversation?.other?.name ?? 'Conversation');
const otherInitials = computed(() => props.conversation?.other?.initials ?? '?');
</script>

<template>
    <Head :title="otherName + ' · Messages'" />

    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link :href="route('chat.index')"
                          class="h-9 w-9 rounded-xl border border-outline-variant bg-surface-container-lowest flex items-center justify-center text-on-surface-variant hover:text-primary hover:border-secondary transition-all">
                        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                    </Link>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">INTERNAL CHAT</p>
                        <h1 class="text-[1.4rem] font-black tracking-tight text-primary leading-tight">{{ otherName }}</h1>
                        <p v-if="conversation.other?.position" class="text-[12px] font-medium text-on-surface-variant">
                            {{ conversation.other.position }}<span v-if="conversation.other.department"> · {{ conversation.other.department }}</span>
                        </p>
                    </div>
                </div>
                <div v-if="unreadTotal > 0"
                     class="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1.5 border border-rose-200">
                    <span class="h-2 w-2 rounded-full bg-rose-500 animate-pulse"></span>
                    <span class="text-[12px] font-black text-rose-700">{{ unreadTotal }} other unread</span>
                </div>
            </div>
        </Teleport>

        <!-- Two-column: threads sidebar + active chat -->
        <div class="grid gap-6 lg:grid-cols-12">

            <!-- Threads sidebar -->
            <aside class="lg:col-span-4 xl:col-span-3 rounded-3xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex items-center justify-between">
                    <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">Threads</p>
                    <Link :href="route('chat.index')" class="text-[10px] font-black text-secondary hover:underline">+ New</Link>
                </div>
                <ul class="divide-y divide-outline-variant/30 max-h-[600px] overflow-y-auto">
                    <li v-for="t in threads" :key="t.id">
                        <Link :href="route('chat.show', t.id)"
                              :class="['flex items-center gap-3 px-5 py-4 hover:bg-surface-container-low transition-colors',
                                       t.id === conversation.id ? 'bg-secondary/[0.06]' : '']">
                            <div class="relative h-10 w-10 flex-shrink-0">
                                <img v-if="t.other?.avatar_url" :src="t.other.avatar_url" :alt="t.other?.name ?? ''"
                                     class="h-10 w-10 rounded-xl object-cover" />
                                <div v-else class="h-10 w-10 rounded-xl flex items-center justify-center text-[11px] font-black"
                                     style="background:#0a1138;color:#fbc02d">
                                    {{ t.other?.initials ?? '?' }}
                                </div>
                                <span v-if="t.unread_count > 0"
                                      class="absolute -top-1 -right-1 inline-flex h-4 min-w-[16px] items-center justify-center rounded-full bg-rose-600 px-1 text-[9px] font-black text-white">
                                    {{ t.unread_count }}
                                </span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[12px] font-black text-primary truncate">{{ t.other?.name ?? 'Unknown' }}</p>
                                <p class="text-[10px] font-medium text-on-surface-variant truncate">
                                    <span v-if="t.last_message?.is_mine" class="text-on-surface-variant/70">You: </span>{{ t.last_message?.body ?? '—' }}
                                </p>
                            </div>
                            <span class="text-[9px] font-bold text-on-surface-variant whitespace-nowrap">{{ t.last_message?.time ?? '' }}</span>
                        </Link>
                    </li>
                    <li v-if="!threads.length" class="px-5 py-12 text-center text-[12px] text-on-surface-variant">
                        No threads yet.
                    </li>
                </ul>
            </aside>

            <!-- Chat panel -->
            <section class="lg:col-span-8 xl:col-span-9 rounded-3xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden flex flex-col"
                     style="height: 75vh; min-height: 540px;">

                <!-- Chat header (other party) -->
                <header class="px-6 py-4 border-b border-outline-variant/40 flex items-center gap-3 bg-surface-container-lowest">
                    <img v-if="conversation.other?.avatar_url" :src="conversation.other.avatar_url"
                         class="h-10 w-10 rounded-xl object-cover" />
                    <div v-else class="h-10 w-10 rounded-xl flex items-center justify-center text-[12px] font-black"
                         style="background:#0c8b86;color:#ffffff">
                        {{ otherInitials }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-[13px] font-black text-primary truncate">{{ otherName }}</p>
                        <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest flex items-center gap-1">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Live · auto-refreshes
                        </p>
                    </div>
                </header>

                <!-- Messages stream -->
                <div ref="messagesEl"
                     class="flex-1 overflow-y-auto px-6 py-6 space-y-3"
                     style="background:linear-gradient(180deg,#f7f9fc 0%, #ffffff 100%);">
                    <div v-if="grouped.length === 0" class="h-full flex flex-col items-center justify-center text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-4xl mb-2" style="font-variation-settings:'FILL' 1">forum</span>
                        <p class="text-[13px] font-bold">No messages yet — say hello.</p>
                    </div>

                    <template v-for="item in grouped" :key="item.kind === 'separator' ? 's-' + item.date : 'm-' + item.id">
                        <!-- Date separator -->
                        <div v-if="item.kind === 'separator'" class="flex items-center gap-3 my-4">
                            <div class="flex-1 h-px bg-outline-variant/40"></div>
                            <span class="text-[9px] font-black text-on-surface-variant uppercase tracking-[0.18em]">{{ item.date }}</span>
                            <div class="flex-1 h-px bg-outline-variant/40"></div>
                        </div>

                        <!-- Message bubble -->
                        <div v-else
                             :class="['flex items-end gap-2', item.sender_id === me.id ? 'justify-end' : 'justify-start']">
                            <div v-if="item.sender_id !== me.id"
                                 class="h-7 w-7 rounded-lg flex items-center justify-center text-[10px] font-black flex-shrink-0"
                                 style="background:#0c8b86;color:#ffffff">
                                {{ otherInitials }}
                            </div>
                            <div :class="['max-w-[70%] rounded-2xl px-4 py-2.5 text-[13px] leading-relaxed shadow-sm group/msg relative',
                                          item.sender_id === me.id
                                              ? 'rounded-br-md text-white'
                                              : 'rounded-bl-md bg-white text-primary border border-outline-variant/40',
                                          item._pending ? 'opacity-70' : '']"
                                 :style="item.sender_id === me.id ? 'background:linear-gradient(135deg,#1a237e,#3949ab)' : ''">
                                <p class="whitespace-pre-wrap break-words">{{ item.body }}</p>
                                <div class="mt-1 flex items-center justify-end gap-1.5">
                                    <span class="text-[9px] font-bold opacity-70">{{ item.time }}</span>
                                    <span v-if="item._pending" class="text-[9px] opacity-70">sending…</span>
                                    <button v-if="item.sender_id === me.id && !item._pending"
                                            @click="deleteMessage(item.id)"
                                            class="opacity-0 group-hover/msg:opacity-100 transition-opacity text-[9px] font-bold underline"
                                            title="Delete for everyone">
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Composer -->
                <form @submit.prevent="submit"
                      class="px-5 py-4 border-t border-outline-variant/40 bg-surface-container-lowest flex items-end gap-3">
                    <textarea v-model="form.body"
                              rows="1"
                              maxlength="4000"
                              placeholder="Type a message…"
                              :aria-label="`Type a message to ${otherName}`"
                              @keydown.enter.exact.prevent="submit"
                              @keydown.shift.enter="(e) => { /* let newline through */ }"
                              class="flex-1 resize-none rounded-2xl border border-outline-variant bg-surface-container-lowest px-4 py-2.5 text-[13px] font-medium focus:border-secondary focus:outline-none focus:ring-2 focus:ring-secondary/20"
                              style="max-height: 140px;" />
                    <button type="submit" :disabled="form.processing || !form.body.trim()"
                            :aria-label="`Send message to ${otherName}`"
                            class="flex items-center justify-center h-11 w-11 rounded-2xl shadow-sm transition-all disabled:opacity-40 disabled:cursor-not-allowed hover:-translate-y-0.5"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab);color:white">
                        <span class="material-symbols-outlined text-[20px]">send</span>
                    </button>
                </form>
                <p v-if="form.errors.body" class="px-6 pb-3 text-[11px] font-bold text-rose-600">{{ form.errors.body }}</p>
            </section>
        </div>
    </div>
</template>
