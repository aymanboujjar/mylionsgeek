import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { saveAs } from 'file-saver';
import {
    AlertTriangle,
    Award,
    Calendar,
    CheckCircle2,
    ChevronRight,
    Download,
    Loader2,
    Mail,
    Users,
    XCircle,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { PROGRAM_STATUS } from '@/components/helpers/userDemographics';

/* ─────────────────────────────────────────────
   Helpers
───────────────────────────────────────────── */
const resolveTrack = (field) => {
    const n = String(field || '').toLowerCase().trim();
    if (!n) return null;
    if (n === 'coding' || n.includes('coding') || n.includes('code') || n.includes('dev')) return 'coding';
    if (n === 'media' || n.includes('media') || n.includes('content') || n.includes('studio')) return 'media';
    return null;
};

const isGeekLabTraining = (training) =>
    String(training?.name || '')
        .toLowerCase()
        .includes('geeklab');

const trackMeta = (field) => {
    const t = resolveTrack(field);
    if (t === 'coding') return { label: 'Coding · Web Dev', color: 'bg-blue-500/10 text-blue-600 dark:text-blue-400' };
    if (t === 'media') return { label: 'UGC · Digital Marketing', color: 'bg-purple-500/10 text-purple-600 dark:text-purple-400' };
    return null;
};

/**
 * Why a student cannot be certified, or null when they can be.
 * Students who left the program are never eligible, whatever their track.
 */
const ineligibilityReason = (student) => {
    if (student?.program_status === PROGRAM_STATUS.LEFT) return 'Left the program — cannot be certified';
    if (!resolveTrack(student?.field)) return 'No valid track — will be skipped';
    return null;
};

const isEligible = (student) => ineligibilityReason(student) === null;

const attendanceColor = (pct) => {
    if (pct >= 80) return { bar: 'bg-green-500', text: 'text-green-600 dark:text-green-400' };
    if (pct >= 60) return { bar: 'bg-amber-500', text: 'text-amber-600 dark:text-amber-400' };
    return { bar: 'bg-red-500', text: 'text-red-500 dark:text-red-400' };
};

const formatPreviewDate = (iso) => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-').map(Number);
    if (!y || !m || !d) return '';
    return `${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
};

const todayIso = () => new Date().toISOString().slice(0, 10);

/* ─────────────────────────────────────────────
   Sub-components
───────────────────────────────────────────── */
const AttendancePill = ({ score }) => {
    const pct = Math.round(score);
    const { text } = attendanceColor(pct);
    return (
        <span className={`text-xs font-bold tabular-nums ${text}`} title="Attendance rate">
            {pct}%
        </span>
    );
};

const StudentCard = ({ student, checked, onToggle }) => {
    const track = trackMeta(student.field);
    const blockedReason = ineligibilityReason(student);

    return (
        <label
            htmlFor={`cert-${student.id}`}
            className={`group relative flex items-center gap-3 rounded-xl border p-3.5 transition-all duration-150 select-none ${
                blockedReason ? 'cursor-not-allowed' : 'cursor-pointer'
            } ${
                checked
                    ? 'border-alpha bg-alpha/8 shadow-sm shadow-alpha/15'
                    : blockedReason
                      ? 'border-dashed border-red-300/40 bg-red-500/5 opacity-70 dark:border-red-500/20'
                      : 'border-alpha/10 bg-light hover:border-alpha/30 hover:bg-alpha/5 dark:bg-dark dark:hover:border-alpha/25'
            }`}
        >
            <Checkbox
                id={`cert-${student.id}`}
                checked={checked}
                onCheckedChange={onToggle}
                disabled={Boolean(blockedReason)}
                className="h-4 w-4 flex-shrink-0 data-[state=checked]:border-alpha data-[state=checked]:bg-alpha"
            />

            <Avatar
                name={student.name}
                image={student.image ?? null}
                lastActivity={student.last_login ?? student.last_online ?? student.last_activity ?? null}
                className="h-10 w-10 flex-shrink-0 text-sm"
                onlineCircleClass="w-3 h-3"
            />

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold leading-tight text-dark dark:text-light">{student.name}</p>

                {track && !blockedReason && (
                    <span className={`mt-1 inline-block rounded-md px-1.5 py-0.5 text-[10px] font-semibold ${track.color}`}>
                        {track.label}
                    </span>
                )}

                {blockedReason && (
                    <p className="mt-0.5 flex items-center gap-1 text-[10px] font-medium text-red-500">
                        <XCircle className="h-3 w-3" />
                        {blockedReason}
                    </p>
                )}

                {student.email && (
                    <p className="mt-0.5 truncate text-[10px] text-dark/40 dark:text-light/40">{student.email}</p>
                )}
            </div>

            <div className="flex flex-shrink-0 flex-col items-end gap-1.5">
                {student.discipline != null && <AttendancePill score={student.discipline} />}
                {checked && <CheckCircle2 className="h-4 w-4 text-alpha" />}
            </div>
        </label>
    );
};

/* ─────────────────────────────────────────────
   Main modal
───────────────────────────────────────────── */
export default function CertificateModal({ open, onOpenChange, training }) {
    const students = training?.users ?? training?.students ?? [];
    const eligibleStudents = students.filter(isEligible);
    const isGeekLab = isGeekLabTraining(training);

    const [selectedIds, setSelectedIds] = useState([]);
    const [issuedDate, setIssuedDate] = useState(todayIso);
    const [isGenerating, setIsGenerating] = useState(false);
    const [activeAction, setActiveAction] = useState(null); // 'email' | 'zip' | null
    const [warnings, setWarnings] = useState([]);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState(false);
    const [successMessage, setSuccessMessage] = useState('');

    const allEligibleSelected =
        eligibleStudents.length > 0 && eligibleStudents.every((s) => selectedIds.includes(s.id));

    const toggleSelectAll = useCallback(() => {
        setSelectedIds(allEligibleSelected ? [] : eligibleStudents.map((s) => s.id));
    }, [allEligibleSelected, eligibleStudents]);

    const toggleStudent = useCallback((id) => {
        setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    }, []);

    const resetForm = useCallback(() => {
        setSelectedIds([]);
        setIssuedDate(todayIso());
        setWarnings([]);
        setError('');
        setSuccess(false);
        setSuccessMessage('');
        setActiveAction(null);
    }, []);

    const handleConfirm = async (action = 'zip') => {
        if (selectedIds.length === 0 || isGenerating) return;
        if (!isGeekLab && !issuedDate) return;

        const mode = isGeekLab ? action : 'zip';

        setIsGenerating(true);
        setActiveAction(mode);
        setError('');
        setWarnings([]);
        setSuccess(false);
        setSuccessMessage('');

        const endpoint =
            mode === 'email'
                ? `/trainings/${training.id}/certificates/email`
                : `/trainings/${training.id}/certificates/zip`;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: mode === 'email' ? 'application/json' : 'application/json, application/zip',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    user_ids: selectedIds,
                    issued_date: isGeekLab ? todayIso() : issuedDate,
                }),
            });

            let parsedWarnings = [];

            if (mode === 'email') {
                const data = await response.json().catch(() => ({}));

                if (Array.isArray(data?.skipped) && data.skipped.length > 0) {
                    parsedWarnings = data.skipped;
                    setWarnings(data.skipped);
                }

                if (!response.ok) {
                    throw new Error(data?.error || `Request failed (${response.status})`);
                }

                setSuccessMessage(
                    data?.message ||
                        `${data?.queued ?? selectedIds.length} certificat(s) généré(s) et e-mail(s) mis en file d’attente.`,
                );

                if (parsedWarnings.length === 0) {
                    setSuccess(true);
                    setTimeout(() => {
                        resetForm();
                        onOpenChange(false);
                    }, 1600);
                } else {
                    setSuccess(true);
                }

                return;
            }

            const warningsHeader = response.headers.get('X-Certificate-Warnings');
            if (warningsHeader) {
                try {
                    const parsed = JSON.parse(warningsHeader);
                    if (Array.isArray(parsed) && parsed.length > 0) {
                        parsedWarnings = parsed;
                        setWarnings(parsed);
                    }
                } catch {
                    /* ignore malformed header */
                }
            }

            if (!response.ok) {
                let message = `Request failed (${response.status})`;
                const ct = response.headers.get('Content-Type') || '';
                if (ct.includes('application/json')) {
                    const data = await response.json();
                    if (data?.error) message = data.error;
                    if (Array.isArray(data?.skipped) && data.skipped.length > 0) setWarnings(data.skipped);
                } else {
                    const text = await response.text().catch(() => '');
                    if (text) message = text.slice(0, 200);
                }
                throw new Error(message);
            }

            const zipBlob = await response.blob();
            saveAs(zipBlob, `certificates-${training.id}.zip`);
            setSuccessMessage('Certificates generated! Your download is starting…');

            if (parsedWarnings.length === 0) {
                setSuccess(true);
                setTimeout(() => {
                    resetForm();
                    onOpenChange(false);
                }, 1400);
            } else {
                setSuccess(true);
            }
        } catch (err) {
            console.error('Certificate generation failed:', err);
            setError(err?.message || 'Certificate generation failed. Please try again.');
        } finally {
            setIsGenerating(false);
            setActiveAction(null);
        }
    };

    const handleOpenChange = (value) => {
        if (!isGenerating) {
            if (!value) resetForm();
            onOpenChange(value);
        }
    };

    const datePreview = formatPreviewDate(issuedDate);
    const selectionPct = eligibleStudents.length > 0 ? (selectedIds.length / eligibleStudents.length) * 100 : 0;

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="max-w-3xl gap-0 overflow-hidden border border-alpha/15 bg-light p-0 text-dark shadow-2xl shadow-black/20 dark:bg-dark dark:text-light [&>button]:hidden">

                {/* ── Header ── */}
                <div className="relative overflow-hidden border-b border-alpha/10">
                    {/* Decorative gradient */}
                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-alpha/8 via-transparent to-transparent" />

                    <div className="relative px-6 pt-6 pb-5">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-alpha text-beta shadow-md shadow-alpha/30">
                                    <Award className="h-5 w-5" />
                                </div>
                                <div>
                                    <h2 className="text-lg font-bold leading-tight text-dark dark:text-light">
                                        {isGeekLab ? 'Send GeekLab Certificates' : 'Print Certificates'}
                                    </h2>
                                    {training?.name && (
                                        <p className="mt-0.5 flex items-center gap-1.5 text-xs text-dark/50 dark:text-light/50">
                                            <ChevronRight className="h-3 w-3" />
                                            {training.name}
                                        </p>
                                    )}
                                </div>
                            </div>

                            {/* Selection counter */}
                            <div className="flex flex-shrink-0 flex-col items-end gap-1">
                                <div className="flex items-center gap-1.5 text-sm font-semibold">
                                    <Users className="h-4 w-4 text-alpha" />
                                    <span className="text-alpha">{selectedIds.length}</span>
                                    <span className="text-dark/30 dark:text-light/30">/</span>
                                    <span className="text-dark/60 dark:text-light/60">{eligibleStudents.length}</span>
                                </div>
                                {/* progress bar */}
                                <div className="h-1.5 w-28 overflow-hidden rounded-full bg-alpha/10">
                                    <div
                                        className="h-full rounded-full bg-alpha transition-all duration-300"
                                        style={{ width: `${selectionPct}%` }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Date + Select-all row */}
                        <div className="mt-5 flex flex-wrap items-end gap-4">
                            {!isGeekLab && (
                                <div className="min-w-[180px]">
                                    <label className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-dark/60 uppercase tracking-wider dark:text-light/60">
                                        <Calendar className="h-3.5 w-3.5" />
                                        Issue Date
                                    </label>
                                    <div className="relative">
                                        <Input
                                            type="date"
                                            value={issuedDate}
                                            onChange={(e) => setIssuedDate(e.target.value)}
                                            disabled={isGenerating}
                                            className="h-9 border-alpha/20 bg-light pr-3 text-sm focus-visible:ring-alpha dark:bg-dark"
                                        />
                                    </div>
                                    {datePreview && (
                                        <p className="mt-1.5 flex items-center gap-1 text-[11px] text-dark/45 dark:text-light/45">
                                            <span className="font-medium text-alpha">On certificate:</span>
                                            {datePreview}
                                        </p>
                                    )}
                                </div>
                            )}

                            <div className="flex flex-1 justify-end">
                                <button
                                    type="button"
                                    onClick={toggleSelectAll}
                                    disabled={eligibleStudents.length === 0 || isGenerating}
                                    className="rounded-lg border border-alpha/20 px-3 py-1.5 text-xs font-semibold text-alpha transition hover:bg-alpha/10 disabled:pointer-events-none disabled:opacity-40"
                                >
                                    {allEligibleSelected ? 'Deselect all' : 'Select all eligible'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {/* ── Alerts ── */}
                {success && (
                    <div className="flex items-center gap-2 border-b border-green-500/20 bg-green-500/10 px-6 py-3 text-sm font-medium text-green-700 dark:text-green-300">
                        <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                        {successMessage ||
                            (isGeekLab
                                ? 'Certificates queued for email…'
                                : 'Certificates generated! Your download is starting…')}
                    </div>
                )}

                {error && (
                    <div className="flex items-start gap-2 border-b border-red-500/20 bg-red-500/8 px-6 py-3 text-sm text-red-700 dark:text-red-300">
                        <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                        <span>{error}</span>
                    </div>
                )}

                {warnings.length > 0 && (
                    <div className="border-b border-amber-500/20 bg-amber-500/8 px-6 py-3">
                        <p className="flex items-center gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-300 uppercase tracking-wider">
                            <AlertTriangle className="h-3.5 w-3.5" />
                            Skipped students
                        </p>
                        <ul className="mt-1.5 space-y-0.5">
                            {warnings.map((w) => (
                                <li key={w.id ?? w.name} className="text-xs text-amber-800 dark:text-amber-200">
                                    <span className="font-semibold">{w.name}</span>
                                    {w.reason ? <span className="text-amber-700/70 dark:text-amber-300/70"> — {w.reason}</span> : ''}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* ── Student list ── */}
                <div className="flex-1 overflow-y-auto overscroll-contain px-6 py-4" style={{ maxHeight: '42vh' }}>
                    {students.length === 0 ? (
                        <div className="flex flex-col items-center justify-center gap-3 py-16">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-alpha/10">
                                <Users className="h-7 w-7 text-alpha/50" />
                            </div>
                            <p className="text-sm font-medium text-dark/40 dark:text-light/40">
                                No students enrolled in this training.
                            </p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-2 ">
                            {students.map((student) => (
                                <StudentCard
                                    key={student.id}
                                    student={student}
                                    checked={selectedIds.includes(student.id)}
                                    onToggle={() => toggleStudent(student.id)}
                                />
                            ))}
                        </div>
                    )}
                </div>

                {/* ── Footer ── */}
                <div className="flex items-center justify-between gap-3 border-t border-alpha/10 bg-light/60 px-6 py-4 backdrop-blur dark:bg-dark/60">
                    <p className="hidden text-xs text-dark/40 sm:block dark:text-light/40">
                        {isGeekLab
                            ? 'Download ZIP or email PDFs · students certified immediately'
                            : 'Each selected student gets an individual PDF · packaged as a ZIP'}
                    </p>
                    <div className="flex w-full items-center justify-end gap-2 sm:w-auto">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => handleOpenChange(false)}
                            disabled={isGenerating}
                            className="border-alpha/20 text-dark/70 hover:border-alpha/40 dark:text-light/70"
                        >
                            Cancel
                        </Button>

                        {isGeekLab && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => handleConfirm('zip')}
                                disabled={selectedIds.length === 0 || isGenerating || success}
                                className="min-w-[140px] gap-2 border-alpha/30 font-semibold text-alpha hover:bg-alpha/10 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {isGenerating && activeAction === 'zip' ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Generating…
                                    </>
                                ) : success && activeAction !== 'email' ? (
                                    <>
                                        <CheckCircle2 className="h-4 w-4" />
                                        Done!
                                    </>
                                ) : (
                                    <>
                                        <Download className="h-4 w-4" />
                                        Download ({selectedIds.length})
                                    </>
                                )}
                            </Button>
                        )}

                        <Button
                            type="button"
                            onClick={() => handleConfirm(isGeekLab ? 'email' : 'zip')}
                            disabled={selectedIds.length === 0 || (!isGeekLab && !issuedDate) || isGenerating || success}
                            className="min-w-[140px] gap-2 border border-alpha bg-alpha font-semibold text-beta transition hover:bg-transparent hover:text-alpha disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isGenerating && (!isGeekLab || activeAction === 'email') ? (
                                <>
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                    {isGeekLab ? 'Sending…' : 'Generating…'}
                                </>
                            ) : success ? (
                                <>
                                    <CheckCircle2 className="h-4 w-4" />
                                    Done!
                                </>
                            ) : isGeekLab ? (
                                <>
                                    <Mail className="h-4 w-4" />
                                    Send ({selectedIds.length})
                                </>
                            ) : (
                                <>
                                    <Download className="h-4 w-4" />
                                    Download ({selectedIds.length})
                                </>
                            )}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
