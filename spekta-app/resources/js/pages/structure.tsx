import { Head, Link } from '@inertiajs/react';

import WorkspaceLayout from '@/layouts/workspace-layout';
import { StepStructure, type Node, type StepJob } from '@/pages/wizard';

type Props = {
    project: { id: string; name: string; client_name: string | null; status: string; wizard_step: string; scope_mode: string };
    nodes: Node[];
    step_job: StepJob;
};

// FR-04/FR-05: canvas struktur sebagai halaman sendiri, full body (di luar wizard)
export default function Structure({ project, nodes, step_job }: Props) {
    return (
        <WorkspaceLayout fullBleed>
            <Head title={`Struktur — ${project.name}`} />
            <div className="flex min-h-0 flex-1 flex-col">
                <div className="flex flex-none items-center gap-3 border-b border-gray-200 bg-white px-6 py-3">
                    <Link href={route('projects.show', project.id)} className="text-[13px] font-bold text-gray-500 hover:text-teal-700">
                        ← {project.name}
                    </Link>
                    <span className="text-gray-300">/</span>
                    <h1 className="text-[15px] font-extrabold text-gray-900">Struktur proyek</h1>
                </div>
                <div className="min-h-0 flex-1">
                    <StepStructure project={project} nodes={nodes} step_job={step_job} fullHeight />
                </div>
            </div>
        </WorkspaceLayout>
    );
}
