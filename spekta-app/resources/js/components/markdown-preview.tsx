import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';

// ponytail: cache module-level tanpa eviction — SVG per diagram kecil, halaman jarang punya puluhan diagram unik
const svgCache = new Map<string, string>();
const inflight = new Set<string>(); // cegah render ganda saat html berganti tiap tick stream
let seq = 0;

function swap(code: Element, svg: string) {
    const div = document.createElement('div');
    div.className = 'mermaid-figure my-4 flex cursor-zoom-in justify-center overflow-x-auto';
    div.title = 'Klik untuk perbesar';
    div.innerHTML = svg;
    code.parentElement?.replaceWith(div);
}

/** Lightbox diagram: zoom tombol/scroll + pan drag. ponytail: transform CSS, tanpa lib pan-zoom */
function DiagramLightbox({ svg, onClose }: { svg: string; onClose: () => void }) {
    const [scale, setScale] = useState(1);
    const [pos, setPos] = useState({ x: 0, y: 0 });
    const drag = useRef<{ x: number; y: number } | null>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const zoom = (factor: number) => setScale((s) => Math.min(8, Math.max(0.25, s * factor)));

    return (
        <div
            className="fixed inset-0 z-50 flex flex-col bg-black/80 backdrop-blur-sm"
            onClick={onClose}
            onWheel={(e) => zoom(e.deltaY < 0 ? 1.15 : 1 / 1.15)}
        >
            <div className="flex items-center justify-end gap-1 p-3" onClick={(e) => e.stopPropagation()}>
                {[
                    { label: '−', title: 'Perkecil', act: () => zoom(1 / 1.3) },
                    { label: '+', title: 'Perbesar', act: () => zoom(1.3) },
                    {
                        label: '⟲',
                        title: 'Reset',
                        act: () => {
                            setScale(1);
                            setPos({ x: 0, y: 0 });
                        },
                    },
                    { label: '✕', title: 'Tutup (Esc)', act: onClose },
                ].map((b) => (
                    <button
                        key={b.label}
                        title={b.title}
                        onClick={b.act}
                        className="h-9 w-9 rounded-md bg-white/10 text-lg text-white hover:bg-white/25"
                    >
                        {b.label}
                    </button>
                ))}
            </div>
            <div
                className="flex flex-1 items-center justify-center overflow-hidden"
                style={{ cursor: drag.current ? 'grabbing' : 'grab' }}
                onClick={(e) => e.stopPropagation()}
                onPointerDown={(e) => {
                    drag.current = { x: e.clientX - pos.x, y: e.clientY - pos.y };
                    (e.target as Element).setPointerCapture?.(e.pointerId);
                }}
                onPointerMove={(e) => {
                    if (drag.current) setPos({ x: e.clientX - drag.current.x, y: e.clientY - drag.current.y });
                }}
                onPointerUp={() => (drag.current = null)}
            >
                <div
                    className="[&_svg]:h-auto [&_svg]:max-h-[85vh] [&_svg]:w-auto [&_svg]:max-w-[92vw] [&_svg]:bg-white [&_svg]:p-4 [&_svg]:rounded-lg"
                    style={{ transform: `translate(${pos.x}px, ${pos.y}px) scale(${scale})` }}
                    dangerouslySetInnerHTML={{ __html: svg }}
                />
            </div>
        </div>
    );
}

/**
 * Render HTML markdown + blok ```mermaid jadi diagram SVG (erDiagram, flowchart, dll).
 * `html` WAJIB sudah tersanitasi DOMPurify oleh pemanggil (lihat mdHtml) — komponen ini tidak sanitasi ulang.
 * `skipLastMermaid`: saat streaming, fence terakhir mungkin belum ketutup — blok mermaid terakhir dibiarkan sebagai code.
 */
export default function MarkdownPreview({
    html,
    className,
    skipLastMermaid = false,
}: {
    html: string;
    className: string;
    skipLastMermaid?: boolean;
}) {
    const ref = useRef<HTMLElement>(null);
    const [lightbox, setLightbox] = useState<string | null>(null);
    useLayoutEffect(() => {
        const root = ref.current;
        if (!root) return;
        const codes = [...root.querySelectorAll('pre > code.language-mermaid')];
        if (skipLastMermaid) codes.pop();
        // Blok yang sudah pernah dirender di-swap sinkron dari cache — tanpa flash saat html berganti tiap frame stream
        const pending = codes.filter((code) => {
            const src = code.textContent ?? '';
            const hit = svgCache.get(src);
            if (hit) swap(code, hit);
            return !hit && !inflight.has(src);
        });
        if (!pending.length) return;
        pending.forEach((code) => inflight.add(code.textContent ?? ''));
        import('mermaid').then(async ({ default: mermaid }) => {
            mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'strict', suppressErrorRendering: true });
            for (const code of pending) {
                const src = code.textContent ?? '';
                try {
                    const { svg } = await mermaid.render(`mmd-${seq++}`, src);
                    svgCache.set(src, svg);
                    // node bisa sudah diganti html baru (stream) — swap hanya bila masih terpasang; sisanya kena cache di effect berikutnya
                    if (root.contains(code)) swap(code, svg);
                } catch (e) {
                    // diagram invalid (mis. AI salah sintaks) — biarkan sebagai code block, error ke console biar bisa didiagnosa
                    console.warn('[mermaid] gagal render:', e instanceof Error ? e.message : e, '\n', src.slice(0, 200));
                } finally {
                    inflight.delete(src);
                }
            }
        });
    }, [html, skipLastMermaid]);

    // Delegasi klik — node diagram dibuat via DOM manual, bukan React
    const onClick = useCallback((e: React.MouseEvent) => {
        const fig = (e.target as Element).closest('.mermaid-figure');
        if (fig) setLightbox(fig.innerHTML);
    }, []);

    return (
        <>
            <article ref={ref} className={className} onClick={onClick} dangerouslySetInnerHTML={{ __html: html }} />
            {lightbox && <DiagramLightbox svg={lightbox} onClose={() => setLightbox(null)} />}
        </>
    );
}
