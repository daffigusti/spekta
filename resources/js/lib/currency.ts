// Format uang sisi klien — satu sumber untuk estimate/ratecards/billing.
// Angka persis (proposal/RAB DOCX-XLSX) diformat server via App\Support\Money.

export const fmtMoney = (n: number, currency = 'IDR'): string =>
    new Intl.NumberFormat(currency === 'IDR' ? 'id-ID' : 'en-US', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(n);

/** Gaya ringkas Indonesia: Rp 1,2 M / 45 jt / 900 rb. Non-IDR pakai notasi compact Intl. */
export const fmtMoneyCompact = (n: number, currency = 'IDR'): string => {
    if (currency !== 'IDR') {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency, notation: 'compact', maximumFractionDigits: 1 }).format(n);
    }
    if (n >= 1_000_000_000) return `Rp ${(n / 1_000_000_000).toFixed(1)} M`;
    if (n >= 1_000_000) return `Rp ${Math.round(n / 1_000_000)} jt`;
    if (n >= 1_000) return `Rp ${Math.round(n / 1000)} rb`;
    return `Rp ${Math.round(n)}`;
};
