import AttendanceCheckInPanel from '@/components/AttendanceCheckInPanel';
import FlashMessage from '@/components/FlashMessage';
import { flashFromNetworkCheckFailure } from '@/lib/attendance-network-check';
import {
    homeReminderBannerText,
    reminderDismissKey,
    shouldShowHomeReminderBanner,
} from '@/lib/attendance-slots';
import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePage } from '@inertiajs/react';
import { Clock, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const POLL_INTERVAL_MS = 60_000;
const MODAL_CLOSE_AFTER_SUCCESS_MS = 1800;

export default function StudentAttendanceReminderBanner() {
    const { auth } = usePage().props;
    const userRoles = Array.isArray(auth?.user?.role) ? auth.user.role : [auth?.user?.role].filter(Boolean);
    const isStudent = userRoles.includes('student');

    const [formation, setFormation] = useState(null);
    const [slotStatus, setSlotStatus] = useState(null);
    const [dismissedKeys, setDismissedKeys] = useState(() => new Set());
    const [tabVisible, setTabVisible] = useState(() => !document.hidden);
    const [modalOpen, setModalOpen] = useState(false);
    const [flashMessage, setFlashMessage] = useState(null);
    const [networkChecking, setNetworkChecking] = useState(false);
    const closeTimerRef = useRef(null);

    const refreshSlotStatus = useCallback(async () => {
        if (!isStudent) {
            return;
        }

        try {
            const response = await fetch('/students/attendance/home-slot-status', {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            setFormation(data.formation ?? null);
            setSlotStatus(data.slot_status ?? null);
        } catch {
            // ignore polling errors
        }
    }, [isStudent]);

    useEffect(() => {
        if (!isStudent) {
            return undefined;
        }

        const handleVisibilityChange = () => {
            setTabVisible(!document.hidden);
        };

        document.addEventListener('visibilitychange', handleVisibilityChange);

        return () => {
            document.removeEventListener('visibilitychange', handleVisibilityChange);
        };
    }, [isStudent]);

    useEffect(() => {
        if (!isStudent || !tabVisible) {
            return undefined;
        }

        refreshSlotStatus();

        const interval = setInterval(refreshSlotStatus, POLL_INTERVAL_MS);

        return () => clearInterval(interval);
    }, [isStudent, tabVisible, refreshSlotStatus]);

    useEffect(() => {
        return () => {
            if (closeTimerRef.current) {
                clearTimeout(closeTimerRef.current);
            }
        };
    }, []);

    const dismissKey = useMemo(() => reminderDismissKey(slotStatus), [slotStatus]);

    const bannerVisible = useMemo(() => {
        if (!shouldShowHomeReminderBanner(slotStatus)) {
            return false;
        }

        return dismissKey !== null && !dismissedKeys.has(dismissKey);
    }, [slotStatus, dismissKey, dismissedKeys]);

    const handleDismiss = (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (dismissKey) {
            setDismissedKeys((prev) => new Set(prev).add(dismissKey));
        }
    };

    const handleBannerActivate = async () => {
        if (networkChecking) {
            return;
        }

        setNetworkChecking(true);

        try {
            const response = await fetch('/students/attendance/network-check', {
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                setModalOpen(true);
                return;
            }

            let data = {};
            if (response.status === 403 || response.status === 503) {
                data = await response.json().catch(() => ({}));
            }

            setFlashMessage(flashFromNetworkCheckFailure(response.status, data));
        } catch {
            setFlashMessage(flashFromNetworkCheckFailure(0, null));
        } finally {
            setNetworkChecking(false);
        }
    };

    const handleCheckInSuccess = useCallback(() => {
        if (closeTimerRef.current) {
            clearTimeout(closeTimerRef.current);
        }

        closeTimerRef.current = setTimeout(() => {
            setModalOpen(false);
            closeTimerRef.current = null;
            refreshSlotStatus();
        }, MODAL_CLOSE_AFTER_SUCCESS_MS);
    }, [refreshSlotStatus]);

    const handleModalOpenChange = (open) => {
        if (!open && closeTimerRef.current) {
            clearTimeout(closeTimerRef.current);
            closeTimerRef.current = null;
        }
        setModalOpen(open);
    };

    if (!isStudent) {
        return null;
    }

    const bannerText = homeReminderBannerText(slotStatus);
    const showBanner = bannerVisible && slotStatus?.current_slot;

    return (
        <>
            {showBanner && (
                <div
                    key={dismissKey}
                    className={cn(
                        'pointer-events-auto fixed top-24 right-4 z-40 w-[calc(100%-2rem)] max-w-sm',
                        'rounded-xl border border-alpha/20 bg-background/95 shadow-lg backdrop-blur-sm',
                        'duration-300 animate-in slide-in-from-right fade-in',
                    )}
                >
                    <div
                        role="button"
                        tabIndex={0}
                        className="group flex cursor-pointer items-start gap-3 p-4 text-foreground transition-colors hover:opacity-90"
                        onClick={handleBannerActivate}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                handleBannerActivate();
                            }
                        }}
                    >
                        <Clock className="mt-0.5 h-5 w-5 shrink-0 text-alpha" />
                        <p className="flex-1 text-sm leading-snug">{bannerText}</p>
                        <button
                            type="button"
                            aria-label="Dismiss attendance reminder"
                            className="shrink-0 rounded p-0.5 text-muted-foreground opacity-70 transition-opacity hover:text-foreground hover:opacity-100"
                            onClick={handleDismiss}
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}

            <Dialog open={modalOpen} onOpenChange={handleModalOpenChange}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Attendance</DialogTitle>
                        <DialogDescription>
                            {formation?.name ? formation.name : 'Check in'}
                            {slotStatus?.attendance_day ? ` · ${slotStatus.attendance_day}` : ''}
                        </DialogDescription>
                    </DialogHeader>
                    <AttendanceCheckInPanel
                        formation={formation}
                        slot_status={slotStatus}
                        attendance_day={slotStatus?.attendance_day}
                        onCheckInSuccess={handleCheckInSuccess}
                    />
                </DialogContent>
            </Dialog>

            {flashMessage && (
                <FlashMessage
                    message={flashMessage.message}
                    type={flashMessage.type}
                    onClose={() => setFlashMessage(null)}
                />
            )}
        </>
    );
}
