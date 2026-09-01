import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { PROGRAM_STATUS } from '@/components/helpers/userDemographics';

function normalizeRoles(role) {
    return Array.isArray(role) ? role : role ? [role] : [];
}

export default function CertifiedLinkedInShareModal() {
    // Temporarily disabled — uncomment the block below when ready to re-enable
    return null;

    const page = usePage();
    const user = page.props?.auth?.user;

    const roles = useMemo(() => normalizeRoles(user?.role), [user?.role]);
    const isStudentLike = roles.includes('student');

    const shouldShow = Boolean(
        user &&
            isStudentLike &&
            user.program_status === 'certified' &&
            !user.linkedin_share_prompted_at &&
            !user.linkedin_share_dismissed_at &&
            !user.linkedin_shared_at,
    );

    // Temporary debug — remove once the modal is confirmed working
    console.log('[CertifiedModal]', {
        program_status: user?.program_status,
        isStudentLike,
        linkedin_share_prompted_at: user?.linkedin_share_prompted_at,
        linkedin_share_dismissed_at: user?.linkedin_share_dismissed_at,
        linkedin_shared_at: user?.linkedin_shared_at,
        certificate_share_token: user?.certificate_share_token,
        shouldShow,
    });

    const [open, setOpen] = useState(false);
    const [step, setStep] = useState('ask'); // ask | preview
    const [isBusy, setIsBusy] = useState(false);
    const [error, setError] = useState('');
    const [imageFailed, setImageFailed] = useState(false);

    const certificateImageUrl = user?.id ? `/storage/images/certificationImages/${user.id}.png` : null;
    const certificatePdfUrl = user?.certified_training_id && user?.id ? `/storage/certificates/${user.certified_training_id}/${user.id}.pdf` : null;

    const defaultText = useMemo(() => {
        const field = String(user?.field ?? '').toLowerCase();
        const track = field.includes('media')
            ? 'Media'
            : field.includes('coding') || field.includes('dev') || field.includes('code')
              ? 'Coding'
              : 'program';
        return `I'm honored to share that I completed LionsGeek (${track}).`;
    }, [user?.field]);

    useEffect(() => {
        if (shouldShow) {
            setOpen(true);
        }
    }, [shouldShow]);

    // Mark as prompted on first open (so it only shows once)
    useEffect(() => {
        if (!open || !shouldShow) return;

        fetch('/linkedin/share-prompted', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({}),
        }).catch(() => {});
    }, [open, shouldShow]);

    const close = () => {
        setOpen(false);
        setStep('ask');
        setError('');
        setIsBusy(false);
        setImageFailed(false);
    };

    const dismiss = async () => {
        setIsBusy(true);
        setError('');
        try {
            const resp = await fetch('/linkedin/share-dismiss', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({}),
            });
            if (!resp.ok) throw new Error('Dismiss failed');
            close();
        } catch (e) {
            setError('Failed to dismiss. Please try again.');
        } finally {
            setIsBusy(false);
        }
    };

    const shareLink = () => {
        // Use the server-built URL (respects APP_URL) so LinkedIn's crawler
        // always gets a publicly reachable address, never localhost.
        const sharePageUrl = user?.certificate_share_url;
        if (!sharePageUrl) {
            setError('Certificate share link is not ready yet. Please refresh and try again.');
            return;
        }

        const linkedInShareUrl = `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(sharePageUrl)}`;
        window.open(linkedInShareUrl, '_blank', 'noopener,noreferrer');
        close();
    };

    if (!user) return null;

    return (
        <Dialog open={open} onOpenChange={(v) => (!isBusy ? setOpen(v) : null)}>
            <DialogContent className="w-[95vw] max-w-xl border border-alpha/20 bg-light text-dark dark:bg-dark dark:text-light">
                <DialogHeader>
                    <DialogTitle className="text-xl font-bold">Share your certificate on LinkedIn?</DialogTitle>
                    <DialogDescription className="text-sm text-dark/60 dark:text-light/60">
                        Congratulations on becoming certified. Want to post your LionsGeek certificate on LinkedIn?
                    </DialogDescription>
                </DialogHeader>

                {error && <div className="rounded-md border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-600 dark:text-red-400">{error}</div>}

                {step === 'ask' && (
                    <div className="mt-2 flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="outline" onClick={dismiss} disabled={isBusy}>
                            Not now
                        </Button>
                        <Button
                            type="button"
                            onClick={() => setStep('preview')}
                            disabled={isBusy}
                            className="border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)]"
                        >
                            Yes, show preview
                        </Button>
                    </div>
                )}

                {step === 'preview' && (
                    <div className="space-y-4">
                        <div className="rounded-lg border border-alpha/15 bg-light p-4 dark:bg-dark">
                            <div className="text-xs font-semibold tracking-wide text-dark/50 uppercase dark:text-light/50">Post preview</div>
                            <div className="mt-2 text-sm whitespace-pre-wrap">{defaultText}</div>
                            {certificateImageUrl && !imageFailed && (
                                <div className="mt-3 overflow-hidden rounded-lg border border-alpha/15">
                                    <img
                                        src={certificateImageUrl}
                                        alt="Certificate preview"
                                        className="h-auto w-full"
                                        onError={() => setImageFailed(true)}
                                    />
                                </div>
                            )}
                            {(imageFailed || !certificateImageUrl) && certificatePdfUrl && (
                                <div className="mt-3 overflow-hidden rounded-lg border border-alpha/15 bg-white">
                                    <object data={certificatePdfUrl} type="application/pdf" className="h-[420px] w-full">
                                        <div className="p-3 text-xs text-dark/60">
                                            PDF preview is not supported in this browser. You can open it directly:{' '}
                                            <a href={certificatePdfUrl} className="font-semibold text-alpha hover:underline">
                                                Open certificate PDF
                                            </a>
                                        </div>
                                    </object>
                                </div>
                            )}
                            {(imageFailed || !certificateImageUrl) && !certificatePdfUrl && (
                                <div className="mt-3 rounded-lg border border-alpha/15 p-3 text-xs text-dark/60 dark:text-light/60">
                                    Certificate preview is not available yet.
                                </div>
                            )}
                        </div>

                        <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                            <Button type="button" variant="outline" onClick={dismiss} disabled={isBusy}>
                                Cancel
                            </Button>

                            <Button
                                type="button"
                                onClick={shareLink}
                                disabled={isBusy}
                                className="border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)]"
                            >
                                Share on LinkedIn
                            </Button>
                        </div>
                        <div className="text-xs text-dark/60 dark:text-light/60">
                            This opens LinkedIn in a new tab. The certificate image is shown via the link preview (Open Graph).
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
