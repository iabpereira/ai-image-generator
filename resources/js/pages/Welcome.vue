<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, Layers, Sparkles, WandSparkles } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';

const features = [
    {
        icon: WandSparkles,
        title: 'From text to image',
        description:
            'Describe what you have in mind and get finished images in seconds.',
    },
    {
        icon: Layers,
        title: 'Quick variations',
        description:
            'Tweak the prompt and compare versions side by side until it looks right.',
    },
    {
        icon: Download,
        title: 'Everything saved',
        description: 'Your creations stay in your account, ready to download.',
    },
];

const samples = [
    {
        prompt: 'lighthouse at dusk, watercolor',
        gradient: 'from-amber-300 via-orange-400 to-rose-500',
    },
    {
        prompt: 'misty forest, soft light',
        gradient: 'from-emerald-300 via-teal-500 to-cyan-700',
    },
    {
        prompt: 'neon city in the rain',
        gradient: 'from-fuchsia-500 via-violet-600 to-indigo-800',
    },
    {
        prompt: 'minimalist desert dunes',
        gradient: 'from-yellow-200 via-amber-400 to-orange-600',
    },
];
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Create images with AI" />

        <header
            class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-5"
        >
            <Link :href="home()" class="flex items-center">
                <AppLogo />
            </Link>

            <nav class="flex items-center gap-2">
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="dashboard()">Open dashboard</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child>
                        <Link :href="register()">Sign up</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main class="mx-auto w-full max-w-6xl px-6">
            <section
                class="grid items-center gap-12 py-16 lg:grid-cols-2 lg:py-24"
            >
                <div>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium text-muted-foreground"
                    >
                        <Sparkles class="size-3.5" />
                        AI-powered image generation
                    </span>

                    <h1
                        class="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        Turn ideas into images.
                    </h1>

                    <p
                        class="mt-4 max-w-lg text-lg text-pretty text-muted-foreground"
                    >
                        Write a description, pick a style and let the AI do the
                        rest. That simple.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <Button v-if="$page.props.auth.user" size="lg" as-child>
                            <Link :href="dashboard()">Start creating</Link>
                        </Button>
                        <template v-else>
                            <Button size="lg" as-child>
                                <Link :href="register()">Get started</Link>
                            </Button>
                            <Button size="lg" variant="outline" as-child>
                                <Link :href="login()">I have an account</Link>
                            </Button>
                        </template>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3" aria-hidden="true">
                    <div
                        v-for="(sample, index) in samples"
                        :key="sample.prompt"
                        class="relative aspect-square overflow-hidden rounded-xl bg-linear-to-br shadow-sm"
                        :class="[sample.gradient, index % 2 === 1 && 'mt-6']"
                    >
                        <span
                            class="absolute inset-x-2 bottom-2 truncate rounded-md bg-black/40 px-2 py-1 text-xs text-white backdrop-blur-sm"
                        >
                            {{ sample.prompt }}
                        </span>
                    </div>
                </div>
            </section>

            <section class="grid gap-6 border-t py-16 sm:grid-cols-3">
                <div v-for="feature in features" :key="feature.title">
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-muted"
                    >
                        <component :is="feature.icon" class="size-5" />
                    </div>
                    <h2 class="mt-4 font-medium">{{ feature.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ feature.description }}
                    </p>
                </div>
            </section>
        </main>

        <footer
            class="mx-auto w-full max-w-6xl border-t px-6 py-8 text-sm text-muted-foreground"
        >
            © {{ new Date().getFullYear() }} {{ $page.props.name }}
        </footer>
    </div>
</template>
