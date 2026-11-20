export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    credit_balance: string;
    is_admin: boolean;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    impersonator: { name: string } | null;
};
