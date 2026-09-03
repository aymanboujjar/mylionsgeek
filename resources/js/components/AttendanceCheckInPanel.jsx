import FaceCaptureWidget from '@/components/FaceCaptureWidget';
import { Button } from '@/components/ui/button';
import { buildButtonLabel, buildHelperText, isCheckInDisabled, resolveCheckInError } from '@/lib/attendance-check-in-ui';
import { shouldShowReminderBanner, slotLabel } from '@/lib/attendance-slots';
import { CheckCircle2, Clock, Coffee, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/**
 * Interactive check-in UI shared by the full attendance page and the home banner modal.
 *
 * @param {{
 *   formation: { id: number, name?: string } | null,
 *   slot_status: object | null,
 *   attendance_day?: string | null,
 *   onCheckInSuccess?: () => void,
 * }} props
 */
export default function AttendanceCheckInPanel({
    formation,
    slot_status: initialSlotStatus,
    attendance_day: attendanceDayProp,
    onCheckInSuccess,
}) {
    const attendanceDay = attendanceDayProp ?? initialSlotStatus?.attendance_day ?? null;

    const [slotStatus, setSlotStatus] = useState(initialSlotStatus);
    const [row, setRow] = useState(initialSlotStatus?.row ?? null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    const [showFaceCapture, setShowFaceCapture] = useState(false);
    const [faceError, setFaceError] = useState(null);
    const [isVerifying, setIsVerifying] = useState(false);
    const [successTime, setSuccessTime] = useState(null);
    const [faceRetryNonce, setFaceRetryNonce] = useState(0);

    useEffect(() => {
        setSlotStatus(initialSlotStatus);
        setRow(initialSlotStatus?.row ?? null);
    }, [initialSlotStatus]);

    const refreshSlotStatus = useCallback(async () => {
        if (!formation?.id) {
            return;
        }

        try {
            const params = new URLSearchParams({
                formation_id: String(formation.id),
                ...(attendanceDay ? { attendance_day: attendanceDay } : {}),
            });
            const response = await fetch(`/students/attendance/slot-status?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            setSlotStatus(data);
            if (data.row) {
                setRow(data.row);
            }
        } catch {
            // ignore polling errors
        }
    }, [formation?.id, attendanceDay]);

    useEffect(() => {
        if (!formation?.id) {
            return undefined;
        }

        const interval = setInterval(refreshSlotStatus, 60_000);
        return () => clearInterval(interval);
    }, [formation?.id, refreshSlotStatus]);

    const buttonLabel = useMemo(() => (slotStatus ? buildButtonLabel(slotStatus) : 'Check in'), [slotStatus]);
    const helperText = useMemo(() => (slotStatus ? buildHelperText(slotStatus) : ''), [slotStatus]);
    const disabled = !formation || !slotStatus || isCheckInDisabled(slotStatus, submitting || isVerifying);
    const reminderVisible = slotStatus ? shouldShowReminderBanner(slotStatus) : false;
    const presentWindow = slotStatus?.present_minutes ?? 15;

    const handleCheckIn = () => {
        if (!formation?.id || disabled) {
            return;
        }

        setError(null);
        setSuccess(null);
        setFaceError(null);
        setSuccessTime(null);
        setShowFaceCapture(true);
    };

    const handleFaceCaptureOpenChange = (open) => {
        if (!open && isVerifying) {
            return;
        }
        if (!open) {
            setShowFaceCapture(false);
            setFaceError(null);
        } else {
            setShowFaceCapture(true);
        }
    };

    const handleCapture = async (blob) => {
        if (!formation?.id) {
            return;
        }

        setFaceError(null);
        setIsVerifying(true);
        setSubmitting(true);
        setError(null);
        setSuccess(null);

        const formData = new FormData();
        formData.append('formation_id', String(formation.id));
        if (attendanceDay) {
            formData.append('attendance_day', attendanceDay);
        }
        formData.append('live_photo', blob, 'live_photo.jpg');

        try {
            const res = await fetch('/students/attendance/check-in', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    // Do NOT set Content-Type manually
                },
                body: formData,
            });

            const data = await res.json().catch(() => ({}));
            setIsVerifying(false);
            setSubmitting(false);

            if (res.ok) {
                const time = new Date().toLocaleTimeString('en-GB', {
                    hour: '2-digit',
                    minute: '2-digit',
                });
                setSuccessTime(time);
                setSuccess(`You're marked present`);
                setRow(data.row);
                await refreshSlotStatus();
                onCheckInSuccess?.();

                setTimeout(() => {
                    setShowFaceCapture(false);
                    setFaceError(null);
                }, 1500);
                return;
            }

            const resolved = resolveCheckInError(res.status, data);

            if (resolved.type === 'face_error') {
                setFaceError(resolved.message);
                setShowFaceCapture(true);
                setFaceRetryNonce((n) => n + 1);
                return;
            }

            setShowFaceCapture(false);
            setError(resolved.message);
        } catch {
            setIsVerifying(false);
            setSubmitting(false);
            setShowFaceCapture(false);
            setError('Network error. Please try again.');
        }
    };

    if (!formation || !slotStatus) {
        return null;
    }

    return (
        <div className="space-y-4">
            {reminderVisible && (
                <div className="flex items-start gap-3 rounded-lg border border-alpha/30 bg-alpha/10 p-4 text-dark dark:text-light">
                    <Clock className="mt-0.5 h-5 w-5 shrink-0 text-alpha" />
                    <p className="text-sm">
                        Present window: first {presentWindow} minutes of the slot. Check in now to avoid a late mark.
                    </p>
                </div>
            )}

            {slotStatus.phase === 'gap' && (
                <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-4">
                    <Coffee className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
                    <p className="text-sm text-muted-foreground">{helperText}</p>
                </div>
            )}

            {successTime && !showFaceCapture ? (
                <div className="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 text-green-900 dark:border-green-800 dark:bg-green-950/40 dark:text-green-100">
                    <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-[#51b04f]" />
                    <div>
                        <p className="font-medium">You&apos;re marked present</p>
                        <p className="mt-0.5 text-sm opacity-80">Checked in · Today {successTime}</p>
                    </div>
                </div>
            ) : null}

            {success && !successTime && (
                <div className="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 text-green-900 dark:border-green-800 dark:bg-green-950/40 dark:text-green-100">
                    <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" />
                    <p className="text-sm">{success}</p>
                </div>
            )}

            {error && (
                <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100">
                    {error}
                </div>
            )}

            {isVerifying && !showFaceCapture ? (
                <div className="flex h-12 items-center justify-center gap-3 rounded-md border border-border bg-muted/40">
                    <div className="h-4 w-4 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-[#ffc801]" />
                    <span className="text-sm text-muted-foreground">Checking it&apos;s you…</span>
                </div>
            ) : !successTime ? (
                <Button type="button" className="h-12 w-full text-base" disabled={disabled} onClick={handleCheckIn}>
                    {submitting ? (
                        <>
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            Checking in…
                        </>
                    ) : (
                        buttonLabel
                    )}
                </Button>
            ) : null}

            {helperText && slotStatus.phase !== 'gap' && !successTime && (
                <p className="text-center text-sm text-muted-foreground">{helperText}</p>
            )}

            {row && (
                <div className="rounded-lg border border-border p-4">
                    <h2 className="mb-3 text-sm font-semibold text-dark dark:text-light">Today&apos;s slots</h2>
                    <ul className="space-y-2 text-sm">
                        {['morning', 'lunch', 'evening'].map((slot) => {
                            const value = row[slot] ?? null;
                            const normalized = String(value || '').toLowerCase();
                            const tone =
                                normalized === 'present'
                                    ? 'text-green-700 dark:text-green-400'
                                    : normalized === 'late'
                                      ? 'text-amber-700 dark:text-amber-400'
                                      : normalized === 'absent'
                                        ? 'text-red-700 dark:text-red-400'
                                        : normalized === 'excused'
                                          ? 'text-sky-700 dark:text-sky-400'
                                          : normalized === 'pending'
                                            ? 'text-muted-foreground'
                                            : 'text-dark dark:text-light';

                            return (
                                <li key={slot} className="flex items-center justify-between">
                                    <span className="text-muted-foreground">{slotLabel(slot)}</span>
                                    <span className={`font-medium capitalize ${tone}`}>{value ?? '—'}</span>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}

            <FaceCaptureWidget
                open={showFaceCapture}
                onOpenChange={handleFaceCaptureOpenChange}
                onCapture={handleCapture}
                errorMessage={faceError}
                isVerifying={isVerifying}
                successTime={showFaceCapture ? successTime : null}
                retryNonce={faceRetryNonce}
                onClearFaceError={() => setFaceError(null)}
            />
        </div>
    );
}
