import { useLayoutEffect, useRef } from 'react';

// ponytail: cache module-level tanpa eviction — SVG per diagram kecil, halaman jarang punya puluhan diagram unik
const svgCache = new Map<string, string>();
let seq = 0;

function swap(code: Element, svg: string) {
    const div = document.createElement('div');
    div.className = 'my-4 flex justify-center overflow-x-auto';
    div.innerHTML = svg;
    code.parentElement?.replaceWith(div);
}

/**
 * Render HTML markdown + blok ```mermaid jadi diagram SVG (erDiagram, flowchart, dll).
 * `html` WAJIB sudah tersanitasi DOMPurify oleh pemanggil (lihat mdHtml) — komponen ini tidak sanitasi ulang.
 * `skipLastMermaid`: saat streaming, fence terakhir mungkin belum ketutup — blok mermaid terakhir dibiarkan sebagai code.
 */
export default function MarkdownPreview({ html, className, skipLastMermaid = false }: { html: string; className: string; skipLastMermaid?: boolean }) {
    const ref = useRef<HTMLElement>(null);
    useLayoutEffect(() => {
        const root = ref.current;
        if (!root) return;
        const codes = [...root.querySelectorAll('pre > code.language-mermaid')];
        if (skipLastMermaid) codes.pop();
        // Blok yang sudah pernah dirender di-swap sinkron dari cache — tanpa flash saat html berganti tiap frame stream
        const pending = codes.filter((code) => {
            const hit = svgCache.get(code.textContent ?? '');
            if (hit) swap(code, hit);
            return !hit;
        });
        if (!pending.length) return;
        let alive = true;
        import('mermaid').then(async ({ default: mermaid }) => {
            mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'strict', suppressErrorRendering: true });
            for (const code of pending) {
                const src = code.textContent ?? '';
                try {
                    const { svg } = await mermaid.render(`mmd-${seq++}`, src);
                    svgCache.set(src, svg);
                    if (alive && root.contains(code)) swap(code, svg);
                } catch {
                    // diagram invalid (mis. AI salah sintaks) — biarkan sebagai code block
                }
            }
        });
        return () => {
            alive = false;
        };
    }, [html, skipLastMermaid]);

    return <article ref={ref} className={className} dangerouslySetInnerHTML={{ __html: html }} />;
}
