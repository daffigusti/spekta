import { Head, Link } from '@inertiajs/react';

import SpektaLayout from '@/layouts/spekta-layout';
import { StepStack, type StackLayer, type Understanding } from '@/pages/wizard';

type Props = {
    project: { id: string; name: string; client_name: string | null; status: string; wizard_step: string; scope_mode: string };
    stack: StackLayer[];
    understanding: Understanding | null;
};

// FR-06: rekomendasi stack sebagai halaman sendiri (bukan bagian wizard)
export default function Stack({ project, stack, understanding }: Props) {
    return (
        <SpektaLayout crumb={project.name} active="projects">
            <Head title={`Stack — ${project.name}`} />
            <div className="mx-auto max-w-[1100px] px-6 py-6">
                <Link href={route('projects.show', project.id)} className="text-[13px] font-bold text-gray-500 hover:text-teal-700">
                    ← {project.name}
                </Link>
                <div className="mt-1">
                    <StepStack project={project} stack={stack} understanding={understanding} />
                </div>
            </div>
        </SpektaLayout>
    );
}
