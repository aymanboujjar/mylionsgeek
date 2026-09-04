import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import DOMPurify from 'dompurify';
import { ArrowLeft, Calendar, MapPin, Send } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatApplicationDeadline, formatJobTypeLabel } from './jobHelpers';

function looksLikeHtml(s) {
    const t = typeof s === 'string' ? s.trim() : '';
    return t.startsWith('<') && /<\/?[a-z][a-z0-9]*[\s>/]/i.test(t);
}

export default function JobShow({ job }) {
    const { flash, auth } = usePage().props;
    // Gated resume_view_url (or resume filename) — public resume_url is always null
    const hasProfileResume = Boolean(auth?.user?.resume_view_url || auth?.user?.resume);

    const descriptionHtml = useMemo(() => {
        const raw = job?.description ?? '';
        if (!looksLikeHtml(raw)) {
            return null;
        }
        return DOMPurify.sanitize(raw, { USE_PROFILES: { html: true } });
    }, [job?.description]);

    const [applyOpen, setApplyOpen] = useState(false);
    const [cvInputKey, setCvInputKey] = useState(0);
    const [cvChoiceError, setCvChoiceError] = useState('');

    const { data, setData, post, processing, errors, reset } = useForm({
        subject: '',
        cover_letter: '',
        cv: null,
        use_profile_cv: hasProfileResume,
    });

    const submitApply = (e) => {
        e.preventDefault();
        if (!data.use_profile_cv && !data.cv) {
            setCvChoiceError('Please attach a CV file.');
            return;
        }
        setCvChoiceError('');
        post(`/students/jobs/${job.id}/apply`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                reset();
                setApplyOpen(false);
                setCvInputKey((k) => k + 1);
            },
        });
    };

    const handleApplyDialogChange = (open) => {
        setApplyOpen(open);
        if (open) {
            setCvInputKey((k) => k + 1);
            setCvChoiceError('');
            const useSaved = Boolean(auth?.user?.resume_view_url || auth?.user?.resume);
            setData('subject', '');
            setData('cover_letter', '');
            setData('cv', null);
            setData('use_profile_cv', useSaved);
        }
    };

    return (
        <AppLayout>
            <Head title={job?.title ? `${job.title} · Jobs` : 'Job'} />
            <div className="z-30 dark:bg-dark">
                <div className="min-h-screen bg-transparent">
                    <div className="mx-auto max-w-3xl px-4 py-6">
                        <Button variant="ghost" size="sm" className="mb-4 -ml-2 gap-1 text-alpha" asChild>
                            <Link href="/students/jobs">
                                <ArrowLeft className="h-4 w-4" />
                                All jobs
                            </Link>
                        </Button>

                        {flash?.success && (
                            <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200">
                                {flash.success}
                            </div>
                        )}
                        {flash?.error && (
                            <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                                {flash.error}
                            </div>
                        )}

                        <article className="rounded-lg border border-alpha/15 bg-white p-6 shadow-sm dark:border-light/10 dark:bg-dark_gray">
                            <p className="text-sm font-medium text-alpha dark:text-alpha">{job.reference}</p>
                            <div className="mt-2 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <h1 className="text-2xl font-bold text-beta dark:text-light">{job.title}</h1>
                                {(job.has_applied || job.can_apply) && (
                                    <div className="shrink-0">
                                        {job.has_applied ? (
                                            <Badge className="bg-green-100 text-green-900 dark:bg-green-900/40 dark:text-green-100">Applied</Badge>
                                        ) : (
                                            <Button
                                                type="button"
                                                onClick={() => setApplyOpen(true)}
                                                className="bg-alpha text-black hover:bg-alpha/90"
                                            >
                                                <Send className="mr-2 h-4 w-4" />
                                                Apply
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </div>

                            <div className="mt-4 flex flex-wrap items-center gap-3 text-sm text-beta/75 dark:text-light/75">
                                <Badge className="bg-alpha/15 text-black dark:bg-alpha/25 dark:text-black">{formatJobTypeLabel(job.job_type)}</Badge>
                                {job.location && (
                                    <span className="inline-flex items-center gap-1">
                                        <MapPin className="h-4 w-4" />
                                        {job.location}
                                    </span>
                                )}
                                {job.application_deadline && (
                                    <span className="inline-flex items-center gap-1">
                                        <Calendar className="h-4 w-4" />
                                        Apply by {formatApplicationDeadline(job.application_deadline)}
                                    </span>
                                )}
                            </div>

                            {job.skills?.length > 0 && (
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {job.skills.map((s) => (
                                        <Badge
                                            key={s}
                                            variant="outline"
                                            className="border-alpha/30 font-normal text-beta dark:border-light/20 dark:text-light"
                                        >
                                            {s}
                                        </Badge>
                                    ))}
                                </div>
                            )}

                            <div className="prose prose-sm dark:prose-invert mt-6 max-w-none text-beta dark:text-light">
                                {descriptionHtml != null ? (
                                    <div className="text-beta/90 dark:text-light/90" dangerouslySetInnerHTML={{ __html: descriptionHtml }} />
                                ) : (
                                    <p className="whitespace-pre-wrap text-beta/90 dark:text-light/90">{job?.description ?? ''}</p>
                                )}
                            </div>

                            {job.can_apply && (
                                <div className="mt-8 border-t border-alpha/10 pt-6 dark:border-light/10">
                                    <p className="mb-3 text-sm text-beta/80 dark:text-light/80">
                                        Interested in this role? Submit your application with a short subject, description, and CV.
                                    </p>
                                    <Button type="button" onClick={() => setApplyOpen(true)} className="bg-alpha text-black hover:bg-alpha/90">
                                        <Send className="mr-2 h-4 w-4" />
                                        Open application
                                    </Button>
                                </div>
                            )}
                        </article>

                        <Dialog open={applyOpen} onOpenChange={handleApplyDialogChange}>
                            <DialogContent className="max-h-[90vh] overflow-y-auto border-alpha/15 bg-light sm:max-w-lg dark:border-light/10 dark:bg-dark">
                                <DialogHeader>
                                    <DialogTitle className="text-beta dark:text-light">Apply — {job.title}</DialogTitle>
                                </DialogHeader>
                                <form id="job-apply-form" onSubmit={submitApply} className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="apply-subject" className="text-beta dark:text-light">
                                            Subject
                                        </Label>
                                        <Input
                                            id="apply-subject"
                                            value={data.subject}
                                            onChange={(e) => setData('subject', e.target.value)}
                                            placeholder="Short title for your application"
                                            className="border-alpha/30 dark:border-light/15"
                                        />
                                        {errors.subject && <p className="text-sm text-red-600">{errors.subject}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="apply-description" className="text-beta dark:text-light">
                                            Description
                                        </Label>
                                        <Textarea
                                            id="apply-description"
                                            value={data.cover_letter}
                                            onChange={(e) => setData('cover_letter', e.target.value)}
                                            placeholder="Introduce yourself and why you fit this role"
                                            rows={5}
                                            className="border-alpha/30 dark:border-light/15"
                                        />
                                        {errors.cover_letter && <p className="text-sm text-red-600">{errors.cover_letter}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label className="text-beta dark:text-light">CV (PDF or Word)</Label>
                                        {hasProfileResume ? (
                                            <>
                                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setCvChoiceError('');
                                                            setData('use_profile_cv', true);
                                                            setData('cv', null);
                                                            setCvInputKey((k) => k + 1);
                                                        }}
                                                        className={`rounded-lg border px-3 py-2.5 text-left text-sm font-medium transition-colors ${
                                                            data.use_profile_cv
                                                                ? 'border-alpha bg-alpha/15 text-beta ring-2 ring-alpha dark:text-light'
                                                                : 'border-alpha/30 text-beta hover:bg-alpha/5 dark:border-light/15 dark:text-light'
                                                        }`}
                                                    >
                                                        Use my saved profile CV
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setCvChoiceError('');
                                                            setData('use_profile_cv', false);
                                                        }}
                                                        className={`rounded-lg border px-3 py-2.5 text-left text-sm font-medium transition-colors ${
                                                            !data.use_profile_cv
                                                                ? 'border-alpha bg-alpha/15 text-beta ring-2 ring-alpha dark:text-light'
                                                                : 'border-alpha/30 text-beta hover:bg-alpha/5 dark:border-light/15 dark:text-light'
                                                        }`}
                                                    >
                                                        Upload a different CV
                                                    </button>
                                                </div>
                                                {data.use_profile_cv ? (
                                                    <p className="text-sm text-beta/75 dark:text-light/75">
                                                        The CV from your profile will be sent with this application.
                                                    </p>
                                                ) : (
                                                    <Input
                                                        key={cvInputKey}
                                                        id="apply-cv"
                                                        type="file"
                                                        accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                                        className="cursor-pointer border-alpha/30 dark:border-light/15"
                                                        onChange={(e) => {
                                                            setCvChoiceError('');
                                                            setData('cv', e.target.files?.[0] ?? null);
                                                        }}
                                                    />
                                                )}
                                            </>
                                        ) : (
                                            <Input
                                                key={cvInputKey}
                                                id="apply-cv"
                                                type="file"
                                                accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                                className="cursor-pointer border-alpha/30 dark:border-light/15"
                                                onChange={(e) => {
                                                    setCvChoiceError('');
                                                    setData('cv', e.target.files?.[0] ?? null);
                                                }}
                                            />
                                        )}
                                        {cvChoiceError && <p className="text-sm text-red-600">{cvChoiceError}</p>}
                                        {errors.cv && <p className="text-sm text-red-600">{errors.cv}</p>}
                                    </div>
                                </form>
                                <DialogFooter className="gap-2 sm:gap-0">
                                    <Button type="button" variant="outline" onClick={() => setApplyOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        form="job-apply-form"
                                        disabled={processing}
                                        className="bg-alpha text-black hover:bg-alpha/90"
                                    >
                                        {processing ? 'Sending…' : 'Submit application'}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
