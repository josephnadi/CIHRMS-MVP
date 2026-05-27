<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const member = computed(() => page.props.auth?.member ?? null);

const logout = useForm({});
function doLogout() {
    logout.post(route('portal.logout'));
}

const nav = [
    { name: 'Dashboard',  href: 'portal.home',       icon: 'dashboard' },
    { name: 'My fees',    href: 'portal.fees',       icon: 'request_quote' },
    { name: 'Statements', href: 'portal.statements', icon: 'receipt_long' },
    { name: 'Profile',    href: 'portal.profile',    icon: 'person' },
];
</script>

<template>
<div class="min-h-screen bg-surface text-on-surface">
    <header class="border-b border-outline-variant/60 bg-surface-container-lowest sticky top-0 z-30">
        <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Link :href="route('portal.home')" class="flex items-center gap-2">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-primary text-white font-black">CI</span>
                    <span class="font-black tracking-tight text-primary">CIHRM Member Portal</span>
                </Link>
            </div>
            <div v-if="member" class="flex items-center gap-4">
                <div class="text-right hidden sm:block">
                    <p class="text-[12.5px] font-bold">{{ member.name }}</p>
                    <p class="text-[10px] font-mono text-on-surface-variant">{{ member.member_no }}</p>
                </div>
                <button @click="doLogout"
                        class="rounded-xl border border-outline-variant px-3 py-1.5 text-xs font-bold hover:bg-surface-container">
                    Log out
                </button>
            </div>
        </div>
        <nav v-if="member" class="border-t border-outline-variant/40 bg-surface-container/50">
            <div class="max-w-6xl mx-auto px-6 flex items-center gap-1">
                <Link v-for="item in nav" :key="item.href"
                      :href="route(item.href)"
                      class="px-3 py-2.5 text-[12.5px] font-bold border-b-2 border-transparent hover:border-primary hover:text-primary"
                      :class="{ 'border-primary text-primary': route().current(item.href) }">
                    {{ item.name }}
                </Link>
            </div>
        </nav>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <div v-if="$page.props.flash?.success"
             class="mb-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            {{ $page.props.flash.success }}
        </div>
        <slot />
    </main>

    <footer class="border-t border-outline-variant/40 mt-12 py-6 text-center text-[10px] uppercase tracking-widest text-on-surface-variant/60">
        Chartered Institute of Human Resource Management Ghana
    </footer>
</div>
</template>
