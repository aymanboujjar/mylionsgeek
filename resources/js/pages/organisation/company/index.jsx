import RecruiterWorkspaceBanner from '@/components/recruiter/RecruiterWorkspaceBanner';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Building2 } from 'lucide-react';

function DetailRow({ label, value }) {
    return (
        <div className="grid gap-1 border-b border-alpha/10 py-4 last:border-0 dark:border-light/10 sm:grid-cols-[200px_1fr]">
            <dt className="text-sm font-medium text-beta/70 dark:text-light/70">{label}</dt>
            <dd className="text-sm text-beta dark:text-light">{value || '—'}</dd>
        </div>
    );
}

export default function OrganisationCompanyIndex({ organization }) {
    const org = organization ?? {};

    return (
        <AppLayout>
            <Head title="Company profile" />
            <div className="flex flex-col gap-6 p-6">
                <RecruiterWorkspaceBanner />
                <div>
                    <ButtonLink />
                    <div className="mt-2 flex flex-wrap items-center gap-3">
                        <Building2 className="h-8 w-8 text-alpha" />
                        <div>
                            <h1 className="text-2xl font-bold text-beta dark:text-light">Company profile</h1>
                            <p className="mt-1 text-sm text-beta/70 dark:text-light/70">
                                Your organisation&apos;s public hiring identity. Team members see this name in their workspace.
                            </p>
                        </div>
                        {org.onboarding_completed ? (
                            <Badge className="bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200">Profile complete</Badge>
                        ) : (
                            <Badge variant="secondary">Onboarding incomplete</Badge>
                        )}
                    </div>
                </div>

                <div className="rounded-lg border border-alpha/15 bg-white p-6 dark:border-light/10 dark:bg-dark_gray">
                    <dl>
                        <DetailRow label="Company name" value={org.enterprise_name} />
                        <DetailRow label="Contact name" value={org.contact_name} />
                        <DetailRow label="Email" value={org.email} />
                        <DetailRow label="Sector" value={org.sector} />
                        <DetailRow label="Location" value={org.location} />
                        <DetailRow label="Phone" value={org.phone} />
                    </dl>
                </div>
            </div>
        </AppLayout>
    );
}

function ButtonLink() {
    return (
        <Link href="/recruiter/dashboard" className="inline-flex items-center gap-1 text-sm text-alpha hover:underline">
            <ArrowLeft className="h-4 w-4" />
            Dashboard
        </Link>
    );
}
