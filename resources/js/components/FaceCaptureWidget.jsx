import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { useFaceCapture } from '@/hooks/useFaceCapture';
import { CameraOff, Check as CheckIcon } from 'lucide-react';
import { useEffect, useMemo } from 'react';

/**
 * Face verification dialog for student attendance check-in.
 * Live selfie via getUserMedia only — no photo library.
 *
 * @param {{
 *   open: boolean,
 *   onOpenChange: (open: boolean) => void,
 *   onCapture: (blob: Blob) => void,
 *   errorMessage?: string | null,
 *   isVerifying?: boolean,
 *   successTime?: string | null,
 *   retryNonce?: number,
 *   onClearFaceError?: () => void,
 * }} props
 */
export default function FaceCaptureWidget({
    open,
    onOpenChange,
    onCapture,
    errorMessage = null,
    isVerifying = false,
    successTime = null,
    retryNonce = 0,
    onClearFaceError,
}) {
    const {
        videoRef,
        isReady,
        capturedBlob,
        cameraError,
        isCapturing,
        wantsCamera,
        startCamera,
        capture,
        retake,
        stopCamera,
    } = useFaceCapture();

    useEffect(() => {
        if (!open) {
            stopCamera();
        }
    }, [open, stopCamera]);

    useEffect(() => {
        if (!open || retryNonce < 1) {
            return;
        }

        retake();
    }, [open, retryNonce, retake]);

    const previewUrl = useMemo(() => {
        if (!capturedBlob) return null;
        return URL.createObjectURL(capturedBlob);
    }, [capturedBlob]);

    useEffect(() => {
        return () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
        };
    }, [previewUrl]);

    const handleOpenChange = (next) => {
        if (!next && isVerifying) return;
        if (!next) {
            stopCamera();
        }
        onOpenChange(next);
    };

    const handleCancel = () => {
        stopCamera();
        onOpenChange(false);
    };

    const handleConfirm = () => {
        if (!capturedBlob) return;
        onCapture(capturedBlob);
    };

    const handleCaptureClick = () => {
        onClearFaceError?.();
        capture();
    };

    const showSuccess = Boolean(successTime);
    const showVerifying = isVerifying && !showSuccess;
    const cameraStarted = wantsCamera || Boolean(cameraError) || Boolean(capturedBlob);
    const pageOrigin = typeof window !== 'undefined' ? window.location.origin : '';

    const errorCopy =
        cameraError === 'insecure'
            ? {
                  title: 'Live camera needs a secure page',
                  body: `Browsers block the webcam on plain HTTP LAN addresses. Production uses HTTPS. For local HTTP testing, allow this origin in Chrome, then reload:`,
                  hint: pageOrigin,
              }
            : cameraError === 'permission'
              ? {
                    title: 'Camera access needed',
                    body: 'Enable camera access in your browser settings, then try again.',
                    hint: null,
                }
              : {
                    title: 'Camera unavailable',
                    body: 'We couldn’t open the webcam. Check that no other app is using it, then try again.',
                    hint: null,
                };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent
                showCloseButton={!isVerifying && !showSuccess}
                className="sm:max-w-md p-0 overflow-hidden rounded-xl bg-background dark:bg-[#1c1c1c] border border-border dark:border-[#2e2e2e] gap-0"
            >
                <DialogTitle className="sr-only">Face verification</DialogTitle>

                {showSuccess ? (
                    <div className="flex flex-col items-center gap-3 py-8 px-6">
                        <div className="mb-2 flex h-14 w-14 items-center justify-center rounded-full bg-[#51b04f]/15">
                            <CheckIcon className="h-7 w-7 text-[#51b04f]" />
                        </div>
                        <p className="text-lg font-semibold text-foreground">You&apos;re marked present</p>
                        <p className="text-sm text-muted-foreground">Checked in · Today · {successTime}</p>
                    </div>
                ) : showVerifying ? (
                    <div className="flex flex-col items-center gap-3 py-6">
                        <div className="h-10 w-10 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-[#ffc801]" />
                        <p className="text-sm font-medium text-foreground">Checking it&apos;s you…</p>
                        <p className="text-xs text-muted-foreground">Takes a second</p>
                    </div>
                ) : !cameraStarted ? (
                    <div className="p-8">
                        <p className="mb-3 text-xs font-semibold tracking-widest text-[#ffc801]">FACE CHECK</p>
                        <h2 className="mb-2 text-xl font-semibold text-foreground">Let&apos;s confirm it&apos;s you</h2>
                        <p className="mb-6 text-sm text-muted-foreground">
                            Just once per check-in, we&apos;ll take a quick live selfie — no codes, no fuss.
                        </p>
                        <Button
                            type="button"
                            className="h-11 w-full bg-[#ffc801] font-bold text-[#1a1400] hover:bg-[#ffc801]/90"
                            onClick={startCamera}
                        >
                            Open camera
                        </Button>
                        <p className="mt-3 text-center text-xs text-muted-foreground">
                            Your photo is only used for this check-in.
                        </p>
                    </div>
                ) : cameraError ? (
                    <div className="p-8 text-center">
                        <CameraOff className="mx-auto mb-4 size-10 text-muted-foreground" />
                        <h2 className="mb-2 text-lg font-semibold">{errorCopy.title}</h2>
                        <p className="mb-3 text-sm text-muted-foreground">{errorCopy.body}</p>
                        {errorCopy.hint ? (
                            <div className="mb-4 space-y-2 text-left">
                                <code className="block break-all rounded-md bg-muted px-3 py-2 text-xs text-foreground">
                                    {errorCopy.hint}
                                </code>
                                <ol className="list-decimal space-y-1 pl-4 text-xs text-muted-foreground">
                                    <li>
                                        Open{' '}
                                        <code className="rounded bg-muted px-1">
                                            chrome://flags/#unsafely-treat-insecure-origin-as-secure
                                        </code>
                                    </li>
                                    <li>Paste the origin above, enable the flag, relaunch Chrome</li>
                                    <li>Reload this page and open the camera again</li>
                                </ol>
                            </div>
                        ) : null}
                        <div className="flex flex-col gap-3">
                            <Button
                                type="button"
                                className="h-11 w-full bg-[#ffc801] font-bold text-[#1a1400] hover:bg-[#ffc801]/90"
                                onClick={startCamera}
                            >
                                Try camera again
                            </Button>
                            <Button type="button" variant="outline" className="w-full" onClick={() => onOpenChange(false)}>
                                Close
                            </Button>
                        </div>
                    </div>
                ) : capturedBlob && previewUrl ? (
                    <>
                        <img
                            src={previewUrl}
                            alt="Captured"
                            className="aspect-video w-full object-cover"
                            style={{ transform: 'scaleX(-1)' }}
                        />
                        <div className="p-4">
                            <p className="mb-1 text-base font-semibold text-foreground">Looks good?</p>
                            <p className="mb-4 text-sm text-muted-foreground">
                                This is the live selfie we&apos;ll use to recognise you.
                            </p>
                            <div className="flex gap-3">
                                <Button type="button" variant="outline" className="flex-1" onClick={retake}>
                                    Retake
                                </Button>
                                <Button
                                    type="button"
                                    className="h-10 flex-1 rounded-md bg-[#ffc801] font-bold text-[#1a1400] hover:bg-[#ffc801]/90"
                                    onClick={handleConfirm}
                                >
                                    Looks good — use this
                                </Button>
                            </div>
                        </div>
                    </>
                ) : (
                    <>
                        {errorMessage ? (
                            <div className="px-4 pb-0 pt-4">
                                <div className="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950/40">
                                    <p className="text-sm font-medium text-red-800 dark:text-red-200">{errorMessage}</p>
                                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">
                                        No worries — better light usually does it.
                                    </p>
                                </div>
                            </div>
                        ) : null}

                        <div className="relative">
                            <video
                                ref={videoRef}
                                autoPlay
                                muted
                                playsInline
                                className="aspect-video w-full object-cover"
                                style={{ transform: 'scaleX(-1)' }}
                            />
                            <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                                <div
                                    style={{
                                        width: 180,
                                        height: 220,
                                        borderRadius: '50%',
                                        border: '2.5px solid #ffc801',
                                        boxShadow:
                                            '0 0 0 4px rgba(255,200,1,0.15), 0 0 20px rgba(255,200,1,0.25)',
                                    }}
                                />
                            </div>
                            <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent px-4 pb-4 pt-8">
                                <p className="text-center text-sm font-medium text-white">
                                    Center your face · Live selfie only
                                </p>
                            </div>
                        </div>

                        <div className="flex gap-3 border-t border-border p-4 dark:border-[#2e2e2e]">
                            <Button type="button" variant="outline" className="flex-1" onClick={handleCancel}>
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                className="h-10 flex-1 rounded-md bg-[#ffc801] font-bold text-[#1a1400] hover:bg-[#ffc801]/90"
                                onClick={handleCaptureClick}
                                disabled={!isReady || isCapturing}
                            >
                                {isCapturing ? 'Capturing…' : 'Capture'}
                            </Button>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
