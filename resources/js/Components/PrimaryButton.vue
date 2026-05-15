<template>
    <button
        class="btn-shimmer inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-[13.5px] font-black tracking-tight text-white shadow-glow-sm transition-all duration-200 hover:-translate-y-px hover:shadow-glow active:scale-[0.97] active:translate-y-0 disabled:opacity-50 disabled:pointer-events-none"
        style="background:linear-gradient(135deg,#0051d5,#316bf3);"
    >
        <slot />
    </button>
</template>
