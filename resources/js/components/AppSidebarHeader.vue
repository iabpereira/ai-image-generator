<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { UserRoundCog } from '@lucide/vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Button } from '@/components/ui/button';
import { destroy } from '@/routes/impersonation';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();

function stopImpersonating(): void {
    router.delete(destroy.url());
}
</script>

<template>
    <div
        v-if="page.props.auth.impersonator"
        class="sticky top-0 z-40 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-amber-500/50 bg-amber-500 px-4 py-1.5 text-sm font-medium text-amber-950 shadow-sm"
        role="status"
    >
        <UserRoundCog class="size-4 shrink-0" />
        <span>
            You are impersonating {{ page.props.auth.user.name }} ({{
                page.props.auth.user.email
            }}) as {{ page.props.auth.impersonator.name }}.
        </span>
        <Button
            size="sm"
            variant="outline"
            class="h-7 border-amber-950/30 bg-amber-50 text-amber-950 hover:bg-white"
            @click="stopImpersonating"
        >
            Back to my account
        </Button>
    </div>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
    </header>
</template>
