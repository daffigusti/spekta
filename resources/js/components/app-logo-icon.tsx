import { SVGAttributes } from 'react';

// Logomark Spekta: huruf S sebagai jalur switchback (alur transkrip → proses → dokumen),
// diakhiri titik terpisah — "spec selesai, titik."
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M36 11 H19 A6.5 6.5 0 0 0 19 24 H29 A6.5 6.5 0 0 1 29 37 H20"
                stroke="currentColor"
                strokeWidth="6"
                strokeLinecap="round"
                fill="none"
            />
            <circle cx="12" cy="37" r="3" fill="currentColor" />
        </svg>
    );
}
