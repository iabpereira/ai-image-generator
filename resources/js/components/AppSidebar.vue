<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    ChartColumn,
    FolderGit2,
    Images,
    LayoutGrid,
    Percent,
    Sparkles,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { spendReport } from '@/routes/admin';
import { index as usersIndex } from '@/routes/admin/users';
import { index as markupIndex } from '@/routes/admin/markup';
import { index as creditsIndex } from '@/routes/credits';
import { create, gallery } from '@/routes/generations';
import { formatUsdString } from '@/lib/money';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Generate',
        href: create(),
        icon: Sparkles,
    },
    {
        title: 'Gallery',
        href: gallery(),
        icon: Images,
    },
    {
        title: `Credits · ${formatUsdString(page.props.auth.user.credit_balance)}`,
        href: creditsIndex(),
        icon: Wallet,
    },
]);

const adminNavItems: NavItem[] = [
    {
        title: 'Admin report',
        href: spendReport(),
        icon: ChartColumn,
    },
    {
        title: 'Users',
        href: usersIndex(),
        icon: Users,
    },
    {
        title: 'Markup',
        href: markupIndex(),
        icon: Percent,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/iabpereira/ai-image-generator',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://github.com/iabpereira/ai-image-generator#readme',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="page.props.auth.user.is_admin"
                :items="adminNavItems"
                label="Admin"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
