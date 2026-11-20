const NANOS_PER_DOLLAR = 1_000_000_000;

/** Parse a 9-decimal USD string (as sent by the server) into integer nanodollars. */
export function decimalToNanos(amount: string): number {
    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace('-', '').split('.');
    const nanos =
        Number(whole) * NANOS_PER_DOLLAR + Number(fraction.padEnd(9, '0'));

    return negative ? -nanos : nanos;
}

/** Display a USD amount. Sub-cent prices keep 4 decimals so they never read as $0.00. */
export function formatUsd(nanos: number): string {
    return `$${(nanos / NANOS_PER_DOLLAR).toFixed(4)}`;
}

export function formatUsdString(amount: string): string {
    return formatUsd(decimalToNanos(amount));
}
