<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatUsdString } from '@/lib/money';
import { index } from '@/routes/admin/users';
import { store } from '@/routes/admin/impersonation';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    email_verified: boolean;
    credit_balance: string;
    generations_count: number;
    created_at: string | null;
};

defineProps<{
    users: {
        data: AdminUser[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: index() }],
    },
});

const page = usePage();

function canImpersonate(user: AdminUser): boolean {
    return !user.is_admin && user.id !== page.props.auth.user.id;
}

function impersonate(user: AdminUser): void {
    router.post(store.url(user.id));
}

function formatDate(isoDate: string | null): string {
    if (isoDate === null) {
        return '—';
    }

    return new Date(isoDate).toLocaleDateString(undefined, {
        dateStyle: 'medium',
    });
}
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Users</h1>
            <p class="text-sm text-muted-foreground">
                Every account. Impersonate a regular user to see the app as they
                do.
            </p>
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Role</th>
                        <th class="px-4 py-3 font-medium">Verified</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Balance
                        </th>
                        <th class="px-4 py-3 text-right font-medium">
                            Generations
                        </th>
                        <th class="px-4 py-3 font-medium">Joined</th>
                        <th class="px-4 py-3 text-right font-medium">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users.data"
                        :key="user.id"
                        class="border-t border-sidebar-border/70 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ user.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ user.email }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <Badge v-if="user.is_admin">Admin</Badge>
                            <span v-else>User</span>
                        </td>
                        <td class="px-4 py-3">
                            {{ user.email_verified ? 'Yes' : 'No' }}
                        </td>
                        <td
                            class="px-4 py-3 text-right tabular-nums"
                            :title="`$${user.credit_balance}`"
                        >
                            {{ formatUsdString(user.credit_balance) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ user.generations_count }}
                        </td>
                        <td
                            class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                        >
                            {{ formatDate(user.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="canImpersonate(user)"
                                size="sm"
                                variant="outline"
                                @click="impersonate(user)"
                            >
                                Impersonate
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="users.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <Button
                v-if="users.prev_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="users.prev_page_url" :only="['users']">
                    Previous
                </Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                Previous
            </Button>
            <span class="text-muted-foreground">
                Page {{ users.current_page }} of {{ users.last_page }}
            </span>
            <Button
                v-if="users.next_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="users.next_page_url" :only="['users']">Next</Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>Next</Button>
        </nav>
    </div>
</template>
