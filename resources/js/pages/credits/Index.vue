<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { decimalToNanos, formatUsdString } from '@/lib/money';
import { index, store } from '@/routes/credits';
import type { Paginated } from '@/types/generation';

type CreditTransactionType = 'purchase' | 'hold' | 'release' | 'charge';

type CreditTransaction = {
    id: number;
    type: CreditTransactionType;
    amount: string;
    balance_after: string;
    generation_id: number | null;
    created_at: string;
};

defineProps<{
    packs: number[];
    transactions: Paginated<CreditTransaction>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const page = usePage();

const balance = computed(() => page.props.auth.user.credit_balance);

const showWelcome = computed(
    () =>
        new URLSearchParams(page.url.split('?')[1] ?? '').get('verified') ===
            '1' || decimalToNanos(balance.value) === 0,
);

const purchasingPack = ref<number | null>(null);

function buy(pack: number): void {
    router.post(
        store.url(),
        { pack },
        {
            preserveScroll: true,
            onStart: () => (purchasingPack.value = pack),
            onFinish: () => (purchasingPack.value = null),
        },
    );
}

function describe(transaction: CreditTransaction): string {
    switch (transaction.type) {
        case 'purchase':
            return 'Credits purchased';
        case 'hold':
            return `Reserved for image #${transaction.generation_id}`;
        case 'release':
            return `Reservation returned for image #${transaction.generation_id}`;
        case 'charge':
            return `Charged for image #${transaction.generation_id}`;
    }
}

function formatSignedAmount(amount: string): string {
    const nanos = decimalToNanos(amount);

    return `${nanos > 0 ? '+' : ''}${formatUsdString(amount)}`;
}

function formatDate(isoDate: string): string {
    return new Date(isoDate).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <Head title="Credits" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Credits</h1>
            <p class="text-sm text-muted-foreground">
                When you generate, we reserve the maximum price; once the image
                is done you're charged the actual price and the rest comes back.
                Failed images are free.
            </p>
        </div>

        <div
            v-if="showWelcome"
            class="rounded-xl border border-primary/30 bg-primary/5 p-4 text-sm"
        >
            Welcome! Buy credits to start generating images.
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Wallet class="size-4" /> Balance
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        class="text-4xl font-semibold tabular-nums"
                        :title="`$${balance}`"
                    >
                        {{ formatUsdString(balance) }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Add credits</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="pack in packs"
                            :key="pack"
                            variant="outline"
                            :disabled="purchasingPack !== null"
                            @click="buy(pack)"
                        >
                            <Spinner v-if="purchasingPack === pack" />
                            Add ${{ pack }}
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Demo purchase: no payment is taken.
                    </p>
                </CardContent>
            </Card>
        </div>

        <div>
            <h2 class="mb-3 text-base font-semibold">Statement</h2>

            <div
                v-if="transactions.data.length === 0"
                class="rounded-xl border border-dashed p-12 text-center text-muted-foreground"
            >
                No transactions yet.
            </div>

            <div
                v-else
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Description</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Amount
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Balance after
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="transaction in transactions.data"
                            :key="transaction.id"
                            class="border-t border-sidebar-border/70 dark:border-sidebar-border"
                        >
                            <td
                                class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                            >
                                <time :datetime="transaction.created_at">
                                    {{ formatDate(transaction.created_at) }}
                                </time>
                            </td>
                            <td class="px-4 py-3">
                                {{ describe(transaction) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums"
                                :class="
                                    decimalToNanos(transaction.amount) > 0
                                        ? 'text-green-600 dark:text-green-400'
                                        : 'text-red-600 dark:text-red-400'
                                "
                                :title="`$${transaction.amount}`"
                            >
                                {{ formatSignedAmount(transaction.amount) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                                :title="`$${transaction.balance_after}`"
                            >
                                {{ formatUsdString(transaction.balance_after) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="transactions.meta.last_page > 1"
                class="mt-4 flex items-center justify-between text-sm"
            >
                <Button
                    v-if="transactions.links.prev"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="transactions.links.prev"
                        :only="['transactions']"
                    >
                        Previous
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Previous
                </Button>
                <span class="text-muted-foreground">
                    Page {{ transactions.meta.current_page }} of
                    {{ transactions.meta.last_page }}
                </span>
                <Button
                    v-if="transactions.links.next"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="transactions.links.next"
                        :only="['transactions']"
                    >
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
