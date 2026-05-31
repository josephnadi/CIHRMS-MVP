<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    tokens:           Object,
    activeModule:     String,
    flash_token:      String,
    available_scopes: Array,
});

// ── Normalised rows ────────────────────────────────────────────────
const tokenRows = computed(() => props.tokens?.data ?? []);

// ── Stats derived client-side ──────────────────────────────────────
const stats = computed(() => {
    const all     = tokenRows.value;
    const active  = all.filter(t => t.meta?.is_usable);
    const revoked = all.filter(t => t.meta?.revoked_at);
    const expired = all.filter(t => !t.meta?.is_usable && !t.meta?.revoked_at);
    const used24h = all.filter(t => {
        if (!t.last_used) return false;
        return (Date.now() - new Date(t.last_used).getTime()) < 86_400_000;
    });
    return {
        total:   props.tokens?.meta?.total ?? all.length,
        active:  active.length,
        revoked: revoked.length,
        expired: expired.length,
        used24h: used24h.length,
    };
});

// ── Scope → palette tone ───────────────────────────────────────────
// Each ability gets a colour tied to its access class. read=cyan,
// write=indigo, export=gold (5% flagship), wildcard=red (dangerous).
const scopeMeta = (s) => {
    if (s === '*')                     return { tone: 'wild',  color: '#dc2626' };
    if (s.endsWith(':write'))          return { tone: 'write', color: '#1a237e' };
    if (s.endsWith(':read'))           return { tone: 'read',  color: '#0e8a93' };
    if (s.includes('export'))          return { tone: 'gold',  color: '#b88a08' };
    if (s.includes('manage'))          return { tone: 'mage',  color: '#d912e3' };
    return                                    { tone: 'mute',  color: '#64748b' };
};

// ── Form ───────────────────────────────────────────────────────────
const showPanel = ref(false);
const copied    = ref(false);

const form = useForm({
    name:            '',
    purpose:         '',
    abilities:       [],
    rate_limit:      60,
    expires_in_days: 365,
});

const submit = () => form.post(route('api-tokens.store'), {
    preserveScroll: true,
    onSuccess: () => {
        showPanel.value = false;
        form.reset();
        form.abilities = [];
    },
});

const revoke = (id) => {
    if (! window.confirm('Revoke this token? Any system using it will be disconnected immediately.')) return;
    router.delete(route('api-tokens.destroy', id), { preserveScroll: true });
};

const copySecret = async () => {
    try {
        await navigator.clipboard.writeText(props.flash_token);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2500);
    } catch (e) { /* clipboard blocked */ }
};

const toggleScope = (s) => {
    const i = form.abilities.indexOf(s);
    if (i === -1) form.abilities.push(s);
    else          form.abilities.splice(i, 1);
};

// ── Helpers ────────────────────────────────────────────────────────
const fmtDateTime = (d) => d ? new Date(d).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const fmtRelative = (d) => {
    if (!d) return 'never';
    const diff = Math.floor((Date.now() - new Date(d).getTime()) / 60_000);
    if (diff < 1)    return 'just now';
    if (diff < 60)   return `${diff}m ago`;
    if (diff < 1440) return `${Math.floor(diff / 60)}h ago`;
    return `${Math.floor(diff / 1440)}d ago`;
};
const truncate = (s, n = 28) => (s && s.length > n) ? s.slice(0, n) + '…' : (s ?? '');

const statusMeta = (t) => {
    if (t.meta?.is_usable)        return { label: 'ACTIVE',  cls: 'bg-emerald-50 text-emerald-700 border-emerald-200', dot: '#16a34a' };
    if (t.meta?.revoked_at)       return { label: 'REVOKED', cls: 'bg-rose-50 text-rose-700 border-rose-200',          dot: '#dc2626' };
    return                               { label: 'EXPIRED', cls: 'bg-slate-100 text-slate-600 border-slate-200',     dot: '#64748b' };
};
</script>

<template>
<Head title="API Tokens" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">key</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">INTEGRATIONS · CREDENTIAL VAULT</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">API Tokens</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Sanctum personal-access tokens for the v1 public API · Scoped, rate-limited, audited.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1.5 rounded-full bg-emerald-50 border border-emerald-200 px-3 py-1.5 dark:bg-emerald-900/20 dark:border-emerald-800/40">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 vault-live"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest text-emerald-700 dark:text-emerald-300 tabular-nums">{{ stats.active }} active</span>
                    </div>
                    <button @click="showPanel = true" type="button"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span class="material-symbols-outlined text-[18px]" style="font-variation-settings:'FILL' 1">add_circle</span>
                        Issue token
                    </button>
                </div>
            </div>
        </Teleport>

        <div class="space-y-6">

            <!-- ── One-time plaintext-secret reveal ───────────────────── -->
            <Transition
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 -translate-y-2 scale-[0.98]"
                enter-to-class="opacity-100 translate-y-0 scale-100"
            >
                <div v-if="flash_token" class="vault-secret relative overflow-hidden rounded-2xl px-6 py-5 text-white"
                     style="background:linear-gradient(135deg,#1a237e 0%,#283593 55%,#3949ab 100%);border:1px solid rgba(255,215,0,0.45);">
                    <div class="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(255,215,0,0.30),transparent 70%)"></div>
                    <div class="absolute inset-x-0 top-0 h-px overflow-hidden">
                        <div class="vault-ribbon h-px w-1/3"></div>
                    </div>

                    <div class="relative flex flex-wrap items-start gap-4">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl flex-shrink-0" style="background:rgba(255,215,0,0.18);border:1px solid rgba(255,215,0,0.42);">
                            <span class="material-symbols-outlined text-[20px]" style="color:#ffd700;font-variation-settings:'FILL' 1">lock_open</span>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-[9.5px] font-black uppercase tracking-[0.28em]" style="color:#ffd700">Live secret · save now</p>
                            <h3 class="mt-0.5 text-[16px] font-black leading-tight">This is the only time you'll see this token.</h3>
                            <p class="mt-1 text-[12px]" style="color:rgba(255,255,255,0.6)">
                                Authenticate as <code class="bg-white/10 border border-white/15 rounded px-1.5 py-0.5 font-mono text-[11px]">Authorization: Bearer …</code>
                            </p>

                            <div class="mt-3 flex items-stretch gap-2">
                                <code class="vault-token flex-1 font-mono text-[12.5px] rounded-lg px-3 py-2 break-all"
                                      style="background:rgba(0,0,0,0.35);border:1px solid rgba(255,215,0,0.32);color:#f5f5f5;">{{ flash_token }}</code>
                                <button @click="copySecret" type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3.5 text-[12px] font-black uppercase tracking-wider transition-all hover:-translate-y-px active:scale-[0.96]"
                                        :style="copied ? 'background:#16a34a;color:#fff;' : 'background:#ffd700;color:#0d1452;'">
                                    <span class="material-symbols-outlined text-[15px]">{{ copied ? 'check' : 'content_copy' }}</span>
                                    {{ copied ? 'Copied' : 'Copy' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ── KPI tiles ──────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                <div v-for="(card, i) in [
                    { label: 'Total issued', val: stats.total,   sub: 'Lifetime',         cls: 'icon-brand',   icon: 'vpn_key' },
                    { label: 'Active',       val: stats.active,  sub: 'In use',           cls: 'icon-success', icon: 'verified' },
                    { label: 'Used 24h',     val: stats.used24h, sub: 'Hit in last day',  cls: 'icon-cyan',    icon: 'bolt' },
                    { label: 'Revoked',      val: stats.revoked, sub: 'Manual',           cls: 'icon-magenta', icon: 'block' },
                    { label: 'Expired',      val: stats.expired, sub: 'Time-out',         cls: 'icon-warning', icon: 'schedule' },
                ]" :key="card.label"
                     class="group rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 transition-all hover:shadow-md hover:-translate-y-0.5"
                     :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.045}s`">
                    <div class="icon-tile" :class="card.cls">
                        <span class="material-symbols-outlined">{{ card.icon }}</span>
                    </div>
                    <p class="mt-3 text-[9.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">{{ card.label }}</p>
                    <p class="mt-1 text-[24px] font-black tabular-nums text-primary leading-none">{{ card.val }}</p>
                    <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                </div>
            </div>

            <!-- ── Token register ─────────────────────────────────────── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">

                <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[16px] text-secondary">storage</span>
                        <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Token register</span>
                        <span class="rounded-full bg-secondary/10 border border-secondary/20 px-2 py-0.5 text-[10px] font-black text-secondary tabular-nums">{{ stats.total }}</span>
                    </div>
                    <span class="text-[10px] font-mono text-on-surface-variant/60">
                        Plaintext shown ONCE on issue · only the hash is stored
                    </span>
                </div>

                <div v-if="tokenRows.length === 0" class="px-6 py-16">
                    <EmptyState
                        title="No tokens issued yet"
                        description="Issue a Sanctum token to grant API access to GIFMIS, IPPD, or any partner system."
                        icon="vpn_key"
                    >
                        <template #action>
                            <button @click="showPanel = true" type="button"
                                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-black text-white shadow-glow-sm"
                                    style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                Issue first token
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest bg-surface-container-low/20">
                            <th class="p-3 pl-6">Token</th>
                            <th class="p-3">Issued to</th>
                            <th class="p-3">Scopes</th>
                            <th class="p-3">Rate</th>
                            <th class="p-3">Last used</th>
                            <th class="p-3">Expires</th>
                            <th class="p-3 text-center">Status</th>
                            <th class="p-3 pr-6 text-right">—</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(t, i) in tokenRows" :key="t.id"
                            class="border-t border-outline-variant/40 hover:bg-surface-container-low/20 transition-colors"
                            :style="`animation:slideUpFade 0.3s ease both;animation-delay:${Math.min(i, 12)*0.03}s`">
                            <td class="p-3 pl-6">
                                <p class="text-[13px] font-bold text-primary leading-tight">{{ t.name }}</p>
                                <p v-if="t.meta?.purpose" class="mt-0.5 text-[11px] text-on-surface-variant/70 truncate max-w-[260px]" :title="t.meta.purpose">{{ truncate(t.meta.purpose, 36) }}</p>
                            </td>
                            <td class="p-3 text-[11.5px] text-on-surface-variant">{{ t.meta?.issued_to ?? '—' }}</td>
                            <td class="p-3">
                                <div class="flex flex-wrap gap-1 max-w-[260px]">
                                    <code v-for="s in (t.abilities ?? [])" :key="s"
                                          class="tk-scope inline-flex items-center text-[10px] font-mono font-bold rounded px-1.5 py-0.5"
                                          :style="`background:${scopeMeta(s).color}15;color:${scopeMeta(s).color};border:1px solid ${scopeMeta(s).color}33`">
                                        {{ s }}
                                    </code>
                                </div>
                            </td>
                            <td class="p-3 text-[11.5px] font-mono tabular-nums text-on-surface-variant">{{ t.meta?.rate_limit ?? 60 }}/min</td>
                            <td class="p-3 text-[11.5px]" :title="fmtDateTime(t.last_used)">
                                <span :class="t.last_used ? 'text-primary font-bold' : 'text-on-surface-variant/50 italic'">
                                    {{ fmtRelative(t.last_used) }}
                                </span>
                            </td>
                            <td class="p-3 text-[11.5px] text-on-surface-variant font-mono tabular-nums">
                                <span v-if="t.meta?.expires_at" :title="fmtDateTime(t.meta.expires_at)">
                                    {{ new Date(t.meta.expires_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) }}
                                </span>
                                <span v-else class="text-on-surface-variant/50 italic">no expiry</span>
                            </td>
                            <td class="p-3 text-center">
                                <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                      :class="statusMeta(t).cls">
                                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${statusMeta(t).dot}`"></span>
                                    {{ statusMeta(t).label }}
                                </span>
                            </td>
                            <td class="p-3 pr-6 text-right">
                                <button v-if="t.meta?.is_usable" @click="revoke(t.id)" type="button"
                                        class="inline-flex items-center gap-1 rounded-md border border-rose-200 px-2 py-1 text-[10.5px] font-bold text-rose-600 hover:bg-rose-50 transition-colors">
                                    <span class="material-symbols-outlined text-[12px]">block</span>
                                    Revoke
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="tokens?.meta?.links?.length > 3" class="px-6 py-3 border-t border-outline-variant/40">
                    <Pagination :links="tokens.meta.links" />
                </div>
            </div>
        </div>

        <!-- ── Issue token panel ──────────────────────────────────────── -->
        <SlidePanel :open="showPanel" title="Issue API token" size="lg" @close="showPanel = false">
            <form @submit.prevent="submit" class="space-y-5 p-6">

                <div class="rounded-xl bg-amber-50/60 border border-amber-200/60 dark:bg-amber-900/15 dark:border-amber-800/40 px-4 py-3 flex items-start gap-3">
                    <span class="material-symbols-outlined text-amber-700 text-[20px] mt-0.5" style="font-variation-settings:'FILL' 1">warning</span>
                    <p class="text-[12px] text-amber-900 dark:text-amber-200 leading-relaxed">
                        The plaintext token is shown <span class="font-black">once</span> on the next screen. Copy it immediately — only the hash is stored.
                    </p>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Token name <span class="text-rose-500">*</span></label>
                    <input aria-label="Token name" v-model="form.name" required maxlength="120" placeholder="e.g. GIFMIS production integration"
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"
                           :class="{ 'border-rose-400': form.errors.name }"/>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Audit note (purpose)</label>
                    <textarea aria-label="Audit note (purpose)" v-model="form.purpose" rows="2"
                              placeholder="What system uses this token, and why? This appears in the audit log."
                              class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20 resize-none"></textarea>
                </div>

                <div>
                    <label class="flex items-center justify-between mb-1.5">
                        <span class="text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Scopes <span class="text-rose-500">*</span></span>
                        <span class="text-[10.5px] font-mono text-on-surface-variant/60">{{ form.abilities.length }} selected</span>
                    </label>
                    <div class="grid grid-cols-2 gap-1.5 max-h-56 overflow-y-auto rounded-xl border border-outline-variant bg-surface-container-low/40 p-3">
                        <button v-for="s in available_scopes" :key="s"
                                type="button"
                                @click="toggleScope(s)"
                                :class="['flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-[11px] font-bold transition-all text-left',
                                          form.abilities.includes(s)
                                            ? 'ring-2 shadow-sm'
                                            : 'border border-outline-variant/50 text-on-surface-variant hover:border-secondary/40']"
                                :style="form.abilities.includes(s)
                                    ? `background:${scopeMeta(s).color}18;color:${scopeMeta(s).color};box-shadow:0 0 0 2px ${scopeMeta(s).color}33`
                                    : ''">
                            <span class="material-symbols-outlined text-[13px]">{{ form.abilities.includes(s) ? 'check_circle' : 'circle' }}</span>
                            <code class="font-mono">{{ s }}</code>
                        </button>
                    </div>
                    <p class="mt-1.5 text-[10.5px] text-on-surface-variant/60">
                        <code class="font-mono">*</code> grants every scope — use sparingly and only for trusted integrations.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Rate limit / min</label>
                        <input aria-label="Rate limit / min" v-model.number="form.rate_limit" type="number" min="1" max="6000"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] font-mono focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Expires in days</label>
                        <input aria-label="Expires in days" v-model.number="form.expires_in_days" type="number" min="1" max="3650"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] font-mono focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showPanel = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button type="button" @click="submit"
                            :disabled="form.processing || !form.name || form.abilities.length === 0"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-50 shadow-glow-sm"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span v-else class="material-symbols-outlined text-[16px]">vpn_key</span>
                        Issue token
                    </button>
                </div>
            </template>
        </SlidePanel>

    </div>
</template>

<style scoped>
.vault-live { animation: vaultLive 1.6s ease-in-out infinite; }
@keyframes vaultLive {
    0%, 100% { opacity: 1; transform: scale(1); box-shadow: 0 0 0 0 rgba(16,185,129,0.55); }
    50%      { opacity: 0.4; transform: scale(0.7); box-shadow: 0 0 0 6px rgba(16,185,129,0); }
}

.vault-ribbon {
    background: linear-gradient(90deg, transparent, rgba(255,215,0,0.85), rgba(18,217,227,0.65), transparent);
    animation: vaultRibbon 3.6s linear infinite;
}
@keyframes vaultRibbon {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(400%); }
}

.vault-token { letter-spacing: 0.01em; }

.vault-secret { animation: vaultGlow 2.4s ease-in-out infinite; }
@keyframes vaultGlow {
    0%, 100% { box-shadow: 0 0 0 0 rgba(255,215,0,0.15), 0 8px 22px -8px rgba(13,20,82,0.45); }
    50%      { box-shadow: 0 0 0 4px rgba(255,215,0,0.10), 0 14px 32px -10px rgba(13,20,82,0.6); }
}

@media (prefers-reduced-motion: reduce) {
    .vault-live, .vault-ribbon, .vault-secret { animation: none !important; }
}
</style>
