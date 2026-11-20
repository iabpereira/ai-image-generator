<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { decimalToNanos, formatUsd, formatUsdString } from '@/lib/money';
import { formatMarkup } from '@/lib/pricing';
import { spendReport } from '@/routes/admin';

type Totals = {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
    effective_markup_basis_points: number | null;
};

type UserRow = Totals & {
    date: string;
    user_id: number;
    user_name: string;
    user_email: string;
};

type DayRow = Totals & {
    date: string;
    pixelforge_cost: string | null;
    matches: boolean | null;
};

const props = defineProps<{
    filters: { start_date: string; end_date: string };
    currentMarkupBasisPoints: number;
    report: {
        rows: UserRow[];
        days: DayRow[];
        totals: Totals;
        usage_error: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: spendReport() }],
    },
});

type ReportTab = 'daily' | 'users';

const activeTab = ref<ReportTab>('daily');

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);

function tabClass(isActive: boolean): string {
    return [
        '-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors',
        isActive
            ? 'border-primary text-foreground'
            : 'border-transparent text-muted-foreground hover:text-foreground',
    ].join(' ');
}

function apply(): void {
    router.get(
        spendReport.url(),
        { start_date: startDate.value, end_date: endDate.value },
        { preserveState: true },
    );
}

function difference(day: DayRow): string {
    if (day.pixelforge_cost === null) {
        return '';
    }

    return formatUsd(
        Math.abs(
            decimalToNanos(day.provider_cost) -
                decimalToNanos(day.pixelforge_cost),
        ),
    );
}
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Admin report</h1>
            <p class="text-sm text-muted-foreground">
                Credit spend, provider cost and profit per UTC completion day,
                reconciled against Pixelforge.
            </p>
        </div>

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div class="grid gap-1.5">
                <Label for="start_date">Start date</Label>
                <Input
                    id="start_date"
                    v-model="startDate"
                    type="date"
                    required
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="end_date">End date</Label>
                <Input id="end_date" v-model="endDate" type="date" required />
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <Alert v-if="report.usage_error" variant="destructive">
            <AlertTitle>Pixelforge usage unavailable</AlertTitle>
            <AlertDescription>{{ report.usage_error }}</AlertDescription>
        </Alert>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        Credit spend
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        class="text-2xl font-semibold tabular-nums"
                        :title="`$${report.totals.charged}`"
                    >
                        {{ formatUsdString(report.totals.charged) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ report.totals.generations }} generations
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        Provider cost
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        class="text-2xl font-semibold tabular-nums"
                        :title="`$${report.totals.provider_cost}`"
                    >
                        {{ formatUsdString(report.totals.provider_cost) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ report.totals.generations }} generations
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        Profit
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        class="text-2xl font-semibold tabular-nums"
                        :title="`$${report.totals.profit}`"
                    >
                        {{ formatUsdString(report.totals.profit) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ report.totals.generations }} generations
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        Effective markup
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold tabular-nums">
                        <template
                            v-if="
                                report.totals.effective_markup_basis_points !==
                                null
                            "
                        >
                            {{
                                formatMarkup(
                                    report.totals.effective_markup_basis_points,
                                )
                            }}
                        </template>
                        <template v-else>—</template>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Profit ÷ provider cost. Markup in force:
                        {{ formatMarkup(currentMarkupBasisPoints) }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div
            role="tablist"
            aria-label="Report sections"
            class="flex gap-1 border-b border-sidebar-border/70 dark:border-sidebar-border"
        >
            <button
                id="tab-daily"
                type="button"
                role="tab"
                aria-controls="panel-daily"
                :aria-selected="activeTab === 'daily'"
                :class="tabClass(activeTab === 'daily')"
                @click="activeTab = 'daily'"
            >
                Daily reconciliation
            </button>
            <button
                id="tab-users"
                type="button"
                role="tab"
                aria-controls="panel-users"
                :aria-selected="activeTab === 'users'"
                :class="tabClass(activeTab === 'users')"
                @click="activeTab = 'users'"
            >
                Per user ({{ report.rows.length }})
            </button>
        </div>

        <section
            id="panel-daily"
            v-show="activeTab === 'daily'"
            role="tabpanel"
            aria-labelledby="tab-daily"
            class="flex flex-col gap-3"
        >
            <div
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Date (UTC)</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Profit
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Markup
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Pixelforge /v1/usage
                            </th>
                            <th class="px-4 py-3 font-medium">Check</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in report.days"
                            :key="day.date"
                            class="border-t border-sidebar-border/70 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ day.date }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ day.generations }}
                            </td>
                            <td
                                class="px-4 py-3 text-right tabular-nums"
                                :title="`$${day.charged}`"
                            >
                                {{ formatUsdString(day.charged) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right tabular-nums"
                                :title="`$${day.provider_cost}`"
                            >
                                {{ formatUsdString(day.provider_cost) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right tabular-nums"
                                :title="`$${day.profit}`"
                            >
                                {{ formatUsdString(day.profit) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{
                                    day.effective_markup_basis_points === null
                                        ? '—'
                                        : formatMarkup(
                                              day.effective_markup_basis_points,
                                          )
                                }}
                            </td>
                            <td
                                class="px-4 py-3 text-right tabular-nums"
                                :title="
                                    day.pixelforge_cost === null
                                        ? undefined
                                        : `$${day.pixelforge_cost}`
                                "
                            >
                                <template v-if="day.pixelforge_cost !== null">
                                    {{ formatUsdString(day.pixelforge_cost) }}
                                </template>
                                <span v-else class="text-muted-foreground">
                                    —
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span
                                    v-if="day.matches === true"
                                    class="font-medium text-green-600 dark:text-green-400"
                                >
                                    Matches
                                </span>
                                <span
                                    v-else-if="day.matches === false"
                                    class="font-medium text-amber-600 dark:text-amber-400"
                                >
                                    Differs by {{ difference(day) }}
                                </span>
                                <span v-else class="text-muted-foreground">
                                    —
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            id="panel-users"
            v-show="activeTab === 'users'"
            role="tabpanel"
            aria-labelledby="tab-users"
            class="flex flex-col gap-3"
        >
            <div
                v-if="report.rows.length === 0"
                class="rounded-xl border border-dashed p-12 text-center text-muted-foreground"
            >
                No completed generations in this range.
            </div>
            <div
                v-else
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Date (UTC)</th>
                            <th class="px-4 py-3 font-medium">User</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Profit
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Markup
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in report.rows"
                            :key="`${row.date}-${row.user_id}`"
                            class="border-t border-sidebar-border/70 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ row.date }}
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ row.user_name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.user_email }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ row.generations }}
                            </td>
                            <td
                                class="px-4 py-3 text-right tabular-nums"
                                :title="`$${row.charged}`"
                            >
                                {{ formatUsdString(row.charged) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right tabular-nums"
                                :title="`$${row.provider_cost}`"
                            >
                                {{ formatUsdString(row.provider_cost) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right tabular-nums"
                                :title="`$${row.profit}`"
                            >
                                {{ formatUsdString(row.profit) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
