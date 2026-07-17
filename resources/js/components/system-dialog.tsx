import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { FormEvent, useEffect, useState } from 'react';

type Request =
    | { kind: 'confirm'; message: string; resolve: (v: boolean) => void }
    | { kind: 'prompt'; message: string; defaultValue: string; resolve: (v: string | null) => void }
    | { kind: 'select'; message: string; options: string[]; defaultValue: string; resolve: (v: string | null) => void };

const CUSTOM = '__custom__';

let enqueue: ((r: Request) => void) | null = null;

/** Drop-in async replacement for window.confirm — renders a shadcn Dialog. */
export const confirmDialog = (message: string) =>
    new Promise<boolean>((resolve) => {
        if (enqueue) enqueue({ kind: 'confirm', message, resolve });
        else resolve(window.confirm(message));
    });

/** Drop-in async replacement for window.prompt — renders a shadcn Dialog with an input. */
export const promptDialog = (message: string, defaultValue = '') =>
    new Promise<string | null>((resolve) => {
        if (enqueue) enqueue({ kind: 'prompt', message, defaultValue, resolve });
        else resolve(window.prompt(message, defaultValue));
    });

/** Async select dialog: pick from predefined options or enter a custom value. */
export const selectDialog = (message: string, options: string[], defaultValue = '') =>
    new Promise<string | null>((resolve) => {
        if (enqueue) enqueue({ kind: 'select', message, options, defaultValue, resolve });
        else resolve(window.prompt(message, defaultValue));
    });

export function SystemDialogHost() {
    const [req, setReq] = useState<Request | null>(null);
    const [value, setValue] = useState('');
    const [selected, setSelected] = useState('');

    useEffect(() => {
        enqueue = (r) => {
            setValue(r.kind === 'prompt' ? r.defaultValue : '');
            setSelected(r.kind === 'select' ? r.defaultValue : '');
            setReq(r);
        };
        return () => {
            enqueue = null;
        };
    }, []);

    const finish = (result: boolean | string | null) => {
        if (!req) return;
        const { resolve, kind } = req;
        setReq(null);
        if (kind === 'confirm') (resolve as (v: boolean) => void)(result as boolean);
        else (resolve as (v: string | null) => void)(result as string | null);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (req?.kind === 'select') {
            const result = selected === CUSTOM ? value.trim() : selected;
            if (!result) return;
            finish(result);
        } else finish(req?.kind === 'prompt' ? value : true);
    };

    return (
        <Dialog open={req !== null} onOpenChange={(open) => !open && finish(req?.kind === 'confirm' ? false : null)}>
            {/* ponytail: halaman app hardcode light — paksa dialog light juga, tokenize kalau app nanti benar-benar support dark */}
            <DialogContent className="rounded-xl border-gray-200 bg-white text-gray-900 sm:max-w-md">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="text-[17px] font-extrabold tracking-[-0.01em] text-gray-900">
                            {req?.kind === 'prompt' ? 'Input' : req?.kind === 'select' ? 'Pilih' : 'Konfirmasi'}
                        </DialogTitle>
                        <DialogDescription className="text-[13px] font-medium whitespace-pre-line text-gray-500">{req?.message}</DialogDescription>
                    </DialogHeader>
                    {req?.kind === 'prompt' && (
                        <Input
                            autoFocus
                            className="mt-4 h-auto rounded-[10px] border-2 border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 focus-visible:border-teal-400 focus-visible:ring-[3px] focus-visible:ring-[#F0FDFA] focus-visible:ring-offset-0"
                            value={value}
                            onChange={(e) => setValue(e.target.value)}
                        />
                    )}
                    {req?.kind === 'select' && (
                        <div className="mt-4 flex max-h-[320px] flex-col gap-1.5 overflow-y-auto pr-1">
                            {[...req.options, CUSTOM].map((opt) => {
                                const isCustom = opt === CUSTOM;
                                const active = selected === opt;
                                return (
                                    <button
                                        key={opt}
                                        type="button"
                                        onClick={() => setSelected(opt)}
                                        className={`flex items-center gap-2.5 rounded-[10px] border-2 px-3.5 py-2.5 text-left text-sm font-medium ${
                                            active ? 'border-teal-400 bg-teal-50/50 text-teal-900' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50'
                                        }`}
                                    >
                                        <span
                                            className={`flex size-4 flex-none items-center justify-center rounded-full border-2 ${active ? 'border-teal-500' : 'border-gray-300'}`}
                                        >
                                            {active && <span className="size-2 rounded-full bg-teal-500" />}
                                        </span>
                                        {isCustom ? <span className="italic">Custom / isi sendiri…</span> : opt}
                                    </button>
                                );
                            })}
                            {selected === CUSTOM && (
                                <Input
                                    autoFocus
                                    placeholder="Tulis pilihan custom…"
                                    className="mt-1 h-auto rounded-[10px] border-2 border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 focus-visible:border-teal-400 focus-visible:ring-[3px] focus-visible:ring-[#F0FDFA] focus-visible:ring-offset-0"
                                    value={value}
                                    onChange={(e) => setValue(e.target.value)}
                                />
                            )}
                        </div>
                    )}
                    <DialogFooter className="mt-4">
                        <Button
                            type="button"
                            variant="outline"
                            className="rounded-[10px] border-gray-200 bg-white px-4 py-2.5 text-[13px] font-bold text-gray-700 hover:bg-gray-50 hover:text-gray-900"
                            onClick={() => finish(req?.kind === 'confirm' ? false : null)}
                        >
                            Batal
                        </Button>
                        <Button type="submit" className="rounded-[10px] bg-teal-600 px-5 py-2.5 text-[13px] font-bold text-white hover:bg-teal-700">
                            OK
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
