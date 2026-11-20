<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ImagePlus } from '@lucide/vue';
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import { create, gallery } from '@/routes/generations';
import type { Generation, Paginated } from '@/types/generation';

defineProps<{ generations: Paginated<Generation> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Gallery', href: gallery() }],
    },
});
</script>

<template>
    <Head title="Gallery" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Gallery</h1>
            <p class="text-sm text-muted-foreground">
                Your finished images. Click one to open it full size.
            </p>
        </div>

        <div
            v-if="generations.data.length === 0"
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center"
        >
            <ImagePlus class="size-8 text-muted-foreground" />
            <p class="text-muted-foreground">No images yet.</p>
            <Button as-child variant="outline">
                <Link :href="create()">Create your first image</Link>
            </Button>
        </div>

        <div
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <nav
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <Button
                v-if="generations.links.prev"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="generations.links.prev">Previous</Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                Previous
            </Button>
            <span class="text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Button
                v-if="generations.links.next"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="generations.links.next">Next</Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>Next</Button>
        </nav>
    </div>
</template>
