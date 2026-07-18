import { Head, Link } from '@inertiajs/react';

import WorkspaceLayout from '@/layouts/workspace-layout';
import { StepStack, type StackLayer, type Understanding } from '@/pages/wizard';

type Props = {
    project: { id: string; name: string; client_name: string | null; status: string; wizard_step: string; scope_mode: string };
    stack: StackLayer[];
    understanding: Understanding | null;
};

// FR-06: rekomendasi stack sebagai halaman sendiri (bukan bagian wizard)
export default function Stack({ project, stack, understanding }: Props) {
    return (
        <WorkspaceLayout>
            <Head title={`Stack — ${project.name}`} />
            <div className="w-full px-6 py-6">
                <Link href={route('projects.show', project.id)} className="text-[13px] font-bold text-gray-500 hover:text-teal-700">
                    ← {project.name}
                </Link>
                <div className="mt-1">
                    <StepStack project={project} stack={stack} understanding={understanding} />
                </div>
            </div>
        </WorkspaceLayout>
    );
}
