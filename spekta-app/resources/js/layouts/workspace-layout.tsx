import { PropsWithChildren } from 'react';

// Shell full-screen halaman kerja proyek — tanpa sidebar/topbar SpektaLayout, biar lebih leluasa.
// fullBleed: canvas edge-to-edge h-screen (structure/wireframes); default: scroll halaman normal.
export default function WorkspaceLayout({ children, fullBleed = false }: PropsWithChildren<{ fullBleed?: boolean }>) {
    return fullBleed ? (
        <div className="flex h-screen flex-col overflow-hidden bg-gray-50 text-sm text-gray-700">{children}</div>
    ) : (
        <div className="min-h-screen bg-gray-50 p-7 text-sm text-gray-700">{children}</div>
    );
}
