import { useEffect, useRef } from 'react';

/**
 * Render HTML markdown + blok ```mermaid jadi diagram SVG (erDiagram, flowchart, dll).
 * `html` WAJIB sudah tersanitasi DOMPurify oleh pemanggil (lihat mdHtml) — komponen ini tidak sanitasi ulang.
 */
export default function MarkdownPreview({ html, className }: { html: string; className: string }) {
    const ref = useRef<HTMLElement>(null);
    useEffect(() => {
        const root = ref.current;
        if (!root || !root.querySelector('code.language-mermaid')) return;
        let alive = true;
        import('mermaid').then(({ default: mermaid }) => {
            if (!alive || !ref.current) return;
            mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'strict' });
            ref.current.querySelectorAll('pre > code.language-mermaid').forEach((code) => {
                const div = document.createElement('div');
                div.className = 'mermaid my-4 flex justify-center';
                div.textContent = code.textContent ?? '';
                code.parentElement!.replaceWith(div);
            });
            // Diagram invalid dibiarkan — mermaid menampilkan pesan error inline
            mermaid.run({ nodes: ref.current.querySelectorAll<HTMLElement>('div.mermaid') }).catch(() => {});
        });
        return () => {
            alive = false;
        };
    }, [html]);

    return <article ref={ref} className={className} dangerouslySetInnerHTML={{ __html: html }} />;
}
