<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMarkup } from '@/lib/pricing';
import { index, store } from '@/routes/admin/markup';

type MarkupChange = {
    id: number;
    markup_basis_points: number;
    previous_basis_points: number | null;
    changed_by: { name: string; email: string } | null;
    created_at: string;
};

defineProps<{
    markupBasisPoints: number;
    history: {
        data: MarkupChange[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Markup', href: index() }],
    },
});

const form = useForm({ markup_percent: '' });

function submit(): void {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function formatChange(change: MarkupChange): string {
    const next = formatMarkup(change.markup_basis_points);

    if (change.previous_basis_points === null) {
        return next;
    }

    return `${formatMarkup(change.previous_basis_points)} → ${next}`;
}

function formatDateTime(isoDate: string): string {
    return new Date(isoDate).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <Head title="Markup" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Markup</h1>
            <p class="text-sm text-muted-foreground">
                The margin added on top of Pixelforge's cost.
            </p>
        </div>

        <Card class="max-w-xl">
            <CardHeader>
                <CardTitle>Current markup</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4 text-sm">
                <p class="text-4xl font-semibold tabular-nums">
                    {{ formatMarkup(markupBasisPoints) }}
                </p>
                <p class="text-muted-foreground">
                    Users pay Pixelforge's cost plus this markup. A change
                    applies to generations created from now on; generations
                    already submitted keep the markup they were quoted with.
                </p>
                <form class="space-y-2" @submit.prevent="submit">
                    <Label for="markup_percent">New markup</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="markup_percent"
                            v-model="form.markup_percent"
                            type="text"
                            inputmode="decimal"
                            placeholder="25"
                            class="max-w-40"
                        />
                        <span class="text-muted-foreground">%</span>
                        <Button type="submit" :disabled="form.processing">
                            Save
                        </Button>
                    </div>
                    <InputError :message="form.errors.markup_percent" />
                </form>
            </CardContent>
        </Card>

        <div class="space-y-3">
            <h2 class="text-lg font-semibold">History</h2>

            <p
                v-if="history.data.length === 0"
                class="rounded-xl border border-sidebar-border/70 p-4 text-sm text-muted-foreground dark:border-sidebar-border"
            >
                No changes yet, so the default markup applies.
            </p>
            <div
                v-else
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">When</th>
                            <th class="px-4 py-3 font-medium">Changed by</th>
                            <th class="px-4 py-3 font-medium">Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="change in history.data"
                            :key="change.id"
                            class="border-t border-sidebar-border/70 dark:border-sidebar-border"
                        >
                            <td
                                class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                            >
                                {{ formatDateTime(change.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="change.changed_by">
                                    <p class="font-medium">
                                        {{ change.changed_by.name }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ change.changed_by.email }}
                                    </p>
                                </template>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatChange(change) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="history.last_page > 1"
                class="flex items-center justify-between text-sm"
            >
                <Button
                    v-if="history.prev_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="history.prev_page_url" :only="['history']">
                        Previous
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Previous
                </Button>
                <span class="text-muted-foreground">
                    Page {{ history.current_page }} of {{ history.last_page }}
                </span>
                <Button
                    v-if="history.next_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="history.next_page_url" :only="['history']">
                        Next
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Next
                </Button>
            </nav>
        </div>
    </div>
</template>
