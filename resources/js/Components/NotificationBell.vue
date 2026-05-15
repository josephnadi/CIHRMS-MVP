<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const open = ref(false);
const dropdownRef = ref(null);

const notifications = computed(() => page.props.notifications ?? []);
const count = computed(() => page.props.notificationCount ?? 0);

const handleClickOutside = (e) => {
    if (dropdownRef.value && !dropdownRef.value.contains(e.target)) {
        open.value = false;
    }
};

onMounted(()       => document.addEventListener('click', handleClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside));

const markAllRead = () => {
    router.post(route('notifications.readAll'), {}, {
        preserveScroll: true,
        onSuccess: () => { open.value = false; },
    });
};
</script>

<template>
    <div ref="dropdownRef" class="relative">
        <button
            @click="open = !open"
            class="relative flex h-9 w-9 items-center justify-center rounded-xl text-on-surface-variant transition-all hover:bg-surface-container-low hover:text-on-surface"
            title="Notifications"
        >
            <span class="material-symbols-outlined text-[20px]">notifications</span>
            <span
                v-if="count > 0"
                class="absolute top-0.5 right-0.5 min-w-[16px] h-[16px] px-1 inline-flex items-center justify-center rounded-full bg-red-500 text-white text-[9px] font-bold ring-2 ring-surface-container-lowest"
            >{{ count > 99 ? '99+' : count }}</span>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <div
                v-if="open"
                class="absolute right-0 mt-2 w-[360px] origin-top-right rounded-2xl bg-surface-container-lowest border border-outline-variant/60 shadow-lifted-lg overflow-hidden z-50"
            >
                <div class="flex items-center justify-between border-b border-outline-variant/40 px-4 py-3">
                    <p class="text-[13px] font-bold text-on-surface">Notifications</p>
                    <button
                        v-if="count > 0"
                        @click="markAllRead"
                        class="text-[11px] font-semibold text-secondary hover:underline"
                    >Mark all read</button>
                </div>

                <div v-if="notifications.length === 0" class="p-8 text-center">
                    <span class="material-symbols-outlined text-[32px] text-on-surface-variant/30">notifications_none</span>
                    <p class="mt-2 text-[12px] text-on-surface-variant">You're all caught up.</p>
                </div>

                <div v-else class="max-h-[400px] overflow-y-auto divide-y divide-outline-variant/30">
                    <div
                        v-for="n in notifications"
                        :key="n.id"
                        class="px-4 py-3 hover:bg-surface-container/50 transition-colors"
                    >
                        <p class="text-[12px] font-medium text-on-surface leading-snug">{{ n.message ?? 'Notification' }}</p>
                        <p class="mt-1 text-[10px] text-on-surface-variant/60">{{ n.time }}</p>
                    </div>
                </div>

                <div class="border-t border-outline-variant/40 px-4 py-2.5">
                    <Link
                        :href="route('notifications.index')"
                        class="block text-center text-[12px] font-semibold text-secondary hover:underline"
                        @click="open = false"
                    >View all notifications</Link>
                </div>
            </div>
        </Transition>
    </div>
</template>
