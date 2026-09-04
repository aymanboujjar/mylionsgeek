import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    canAssignProgramStatusLeft,
    canViewHealthData,
    HANDICAP_OPTIONS,
    PROGRAM_STATUS,
    PROGRAM_STATUS_CERTIFICATE_OUTCOME_OPTIONS,
    programStatusLabel,
} from '@/components/helpers/userDemographics';
import AppLayout from '@/layouts/app-layout';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import { defaultUnresolvedSlotValue, SLOT_ORDER } from '@/lib/attendance-slots';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Award,
    BookOpen,
    CalendarCheck,
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Play,
    Plus,
    QrCode,
    RefreshCw,
    Settings,
    User,
    UserCheck,
    UserPlus,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import QRCode from 'react-qr-code';
// Use native fetch instead of axios
import CoursesModal from "@/components/EXP'S/courses_modal";
import ExercicesModal from "@/components/EXP'S/exersices_modal";
import { Checkbox } from '@/components/ui/checkbox';
import RolesMultiSelect from '@/pages/admin/users/partials/RolesMultiSelect';
import CertificateModal from './partials/CertificateModal';
import GeekyWheel from './partials/geekyWheel';
import TrainingStatsCards from './partials/TrainingStatsCards';

export default function Show({ training, usersNull, courses = [] }) {
    const { auth } = usePage().props;
    const userRoles = Array.isArray(auth?.user?.role) ? auth.user.role : [auth?.user?.role].filter(Boolean);
    const isCoachRole = userRoles.includes('coach');
    const isTrainingCoach = auth?.user?.id && training?.coach?.id ? auth.user.id === training.coach.id : false;
    const isAdminRole = userRoles.some((r) => ['admin', 'super_admin'].includes(r));
    const canPrintCertificates = isAdminRole || (isCoachRole && isTrainingCoach);
    const canAssignProgramStatusLeftOnTraining = canAssignProgramStatusLeft(userRoles, {
        requireTrainingCoach: true,
        isTrainingCoach,
    });
    const canEditHealthData = canViewHealthData(userRoles);
    // Staff-controlled gate (formations.is_active). Replaces the old nonexistent training.status field.
    const isActiveTraining = !!training?.is_active;
    const [students, setStudents] = useState(training.users || []);
    const [availableUsers, setAvailableUsers] = useState(usersNull || []);
    const [filter, setFilter] = useState('');
    const [modalFilter, setModalFilter] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [showAttendance, setShowAttendance] = useState(false);
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [studentToDelete, setStudentToDelete] = useState(null);
    const [showAttendanceList, setShowAttendanceList] = useState(false);
    const [selectedDate, setSelectedDate] = useState(null);
    const [attendanceData, setAttendanceData] = useState({});
    const [events, setEvents] = useState([]);
    const [currentAttendanceId, setCurrentAttendanceId] = useState(null);
    const [showPlayDropdown, setShowPlayDropdown] = useState(false);
    const [showGeekyWheel, setShowGeekyWheel] = useState(false);
    const [wheelParticipants, setWheelParticipants] = useState([]);
    const [isSpinning, setIsSpinning] = useState(false);
    const [selectedWinner, setSelectedWinner] = useState(null);
    const [wheelRotation, setWheelRotation] = useState(0);
    const [showWinnerModal, setShowWinnerModal] = useState(false);
    const dropdownRef = useRef(null);
    const calendarRef = useRef(null);
    const [calendarApi, setCalendarApi] = useState(null);
    const [calendarTitle, setCalendarTitle] = useState('');
    const [calendarError, setCalendarError] = useState('');
    const [hasAttendanceToday, setHasAttendanceToday] = useState(false);
    const [reminderModal, setReminderModal] = useState({ open: false, slot: null });
    const [showBulkUpdateModal, setShowBulkUpdateModal] = useState(false);
    const [selectedUserIds, setSelectedUserIds] = useState([]);
    const [bulkRoles, setBulkRoles] = useState([]);
    const [bulkProgramStatus, setBulkProgramStatus] = useState('');
    const [bulkHasHandicap, setBulkHasHandicap] = useState('');
    const [qrCodeDate, setQrCodeDate] = useState(new Date().toISOString().split('T')[0]);
    const [showCertificateModal, setShowCertificateModal] = useState(false);

    // Map status to color styles for SelectTrigger
    const statusClass = (value) => {
        const v = String(value || '').toLowerCase();
        if (v === 'present') return 'border-green-500/30 bg-green-500/10 text-green-600 dark:text-green-400';
        if (v === 'absent') return 'border-red-500/30 bg-red-500/10 text-red-600 dark:text-red-400';
        if (v === 'late') return 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-alpha';
        if (v === 'excused') return 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400';
        if (v === 'pending') return 'border-gray-400/40 bg-gray-400/10 text-gray-600 dark:text-gray-400';
        return '';
    };

    // Close dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setShowPlayDropdown(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    // Initialize FullCalendar API once mounted
    useEffect(() => {
        if (calendarRef.current && typeof calendarRef.current.getApi === 'function') {
            setCalendarApi(calendarRef.current.getApi());
        }
    }, [calendarRef]);

    // Fetch attendance events for this training
    useEffect(() => {
        async function fetchEvents() {
            try {
                const res = await fetch(`/training/${training.id}/attendance-events`);
                const data = await res.json();
                if (Array.isArray(data.events)) {
                    setEvents(data.events.map((e) => ({ ...e })));
                }
            } catch (e) {}
        }
        fetchEvents();
    }, [training.id]);

    useEffect(() => {
        const todayStr = new Date().toISOString().slice(0, 10);
        const hasToday = events.some((event) => {
            if (!event?.date) return false;
            try {
                return new Date(event.date).toISOString().slice(0, 10) === todayStr;
            } catch {
                return false;
            }
        });
        setHasAttendanceToday(hasToday);
        if (hasToday && reminderModal.open) {
            setReminderModal({ open: false, slot: null });
        }
    }, [events, reminderModal.open]);

    useEffect(() => {
        if (!isCoachRole || !isTrainingCoach || !isActiveTraining) return;
        const checkReminder = () => {
            if (hasAttendanceToday || typeof window === 'undefined') {
                return;
            }
            const now = new Date();
            const todayStr = now.toISOString().slice(0, 10);
            const thresholds = [
                { hour: 11, key: 'morning', label: '11:00 AM' },
                { hour: 15, key: 'afternoon', label: '03:00 PM' },
            ];

            for (const threshold of thresholds) {
                if (now.getHours() >= threshold.hour) {
                    const storageKey = `attendance-reminder-${training.id}-${todayStr}-${threshold.key}`;
                    if (!localStorage.getItem(storageKey)) {
                        localStorage.setItem(storageKey, 'shown');
                        setReminderModal({ open: true, slot: threshold });
                        break;
                    }
                }
            }
        };

        checkReminder();
        const interval = setInterval(checkReminder, 60 * 1000);
        return () => clearInterval(interval);
    }, [isCoachRole, isTrainingCoach, isActiveTraining, hasAttendanceToday, training.id]);

    //   attendance

    async function AddAttendance(dateStr) {
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            const res = await fetch('/attendances', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    formation_id: training.id,
                    attendance_day: dateStr,
                }),
            });

            if (!res.ok) {
                throw new Error(`Failed to create/load attendance (${res.status})`);
            }

            const data = await res.json();

            setCurrentAttendanceId(data.attendance_id);

            const initialized = {};
            const existing = data.lists || [];
            const byUserId = new Map(existing.map((l) => [l.user_id, l]));

            students.forEach((s) => {
                const key = `${dateStr}-${s.id}`;
                const saved = byUserId.get(s.id);
                const base = getDefaultSlots(s, dateStr);
                const studentMarkedSlots = Array.isArray(saved?.student_marked_slots) ? saved.student_marked_slots : [];

                initialized[key] = {
                    morning: resolveSlotDisplayValue('morning', saved, base, studentMarkedSlots),
                    lunch: resolveSlotDisplayValue('lunch', saved, base, studentMarkedSlots),
                    evening: resolveSlotDisplayValue('evening', saved, base, studentMarkedSlots),
                    notes: saved?.note ? String(saved.note).split(' | ').filter(Boolean) : base.notes,
                    user_id: s.id,
                    studentMarkedSlots,
                };
            });

            setAttendanceData((prev) => ({ ...prev, ...initialized }));

            setShowAttendanceList(true);
        } catch (err) {}
    }

    //   atteandacelist
    async function handleSave() {
        const dataToSave = Object.entries(attendanceData).map(([key, value]) => {
            const studentId =
                value?.user_id ??
                (() => {
                    const i = key.lastIndexOf('-');
                    return i !== -1 ? key.slice(i + 1) : key;
                })();
            return {
                user_id: studentId,
                attendance_day: selectedDate,
                attendance_id: currentAttendanceId ? Number(currentAttendanceId) : null,
                morning: value.morning,
                lunch: value.lunch,
                evening: value.evening,
                note: Array.isArray(value.notes) ? value.notes.join(' | ') : value.notes || null,
            };
        });

        try {
            if (!selectedDate) return;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const res = await fetch('/admin/attendance/save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    formation_id: training.id,
                    attendance: dataToSave,
                }),
            });
            if (!res.ok) return;
            const saveResult = await res.json().catch(() => ({}));
            if (saveResult?.attendance_id) {
                setCurrentAttendanceId(saveResult.attendance_id);
            }
            setShowAttendanceList(false);
            try {
                const evRes = await fetch(`/training/${training.id}/attendance-events`);
                const evData = await evRes.json();
                if (Array.isArray(evData.events)) setEvents(evData.events);
            } catch {}
        } catch (err) {}
    }

    // Wheel functions
    const openGeekyWheel = () => {
        setWheelParticipants([...students]);
        setSelectedWinner(null);
        setWheelRotation(0);
        setShowGeekyWheel(true);
        setShowPlayDropdown(false);
    };

    const spinWheel = () => {
        if (isSpinning || wheelParticipants.length === 0) return;

        setIsSpinning(true);
        setSelectedWinner(null);

        // Random rotation between 1800-3600 degrees (5-10 full rotations)
        const randomRotation = 1800 + Math.random() * 1800;
        const finalRotation = wheelRotation + randomRotation;

        setWheelRotation(finalRotation);

        setTimeout(() => {
            const segmentAngle = 360 / wheelParticipants.length;
            const normalizedRotation = finalRotation % 360;
            // Arrow is at 180 degrees (9 o'clock position - left side)
            // Calculate which segment the arrow is pointing to
            const winnerIndex = Math.floor((360 - normalizedRotation + 180) / segmentAngle) % wheelParticipants.length;

            setSelectedWinner(wheelParticipants[winnerIndex]);
            setIsSpinning(false);
            setShowWinnerModal(true);
        }, 5000);
    };

    const removeWinner = () => {
        if (selectedWinner) {
            setWheelParticipants((prev) => prev.filter((p) => p.id !== selectedWinner.id));
            setSelectedWinner(null);
            setShowWinnerModal(false);
        }
    };

    const resetWheel = () => {
        setWheelParticipants([...students]);
        setSelectedWinner(null);
        setWheelRotation(0);
        setShowWinnerModal(false);
    };

    const continueSpinning = () => {
        setSelectedWinner(null);
        setShowWinnerModal(false);
    };

    const getDefaultSlots = (student, dateStr = selectedDate) => {
        const legacyLeft = String(student?.status || '').toLowerCase() === 'left';
        const programLeft = student?.program_status === PROGRAM_STATUS.LEFT;

        if (legacyLeft || programLeft) {
            return { morning: 'absent', lunch: 'absent', evening: 'absent', notes: [] };
        }

        const slots = { notes: [] };
        for (const slot of SLOT_ORDER) {
            slots[slot] = defaultUnresolvedSlotValue();
        }
        return slots;
    };

    const isStudentCheckInNote = (note) => /^Check-in at \d{2}:\d{2}$/.test(String(note ?? '').trim());

    const resolveSlotDisplayValue = (slot, saved, base, studentMarkedSlots) => {
        if (studentMarkedSlots.includes(slot)) {
            return saved?.[slot] ?? base[slot];
        }
        const savedValue = saved?.[slot];
        if (savedValue !== null && savedValue !== undefined && String(savedValue).trim() !== '') {
            return savedValue;
        }
        return base[slot];
    };

    const isStudentMarkedSlot = (currentData, slot) => Array.isArray(currentData?.studentMarkedSlots) && currentData.studentMarkedSlots.includes(slot);

    const renderSlotSelect = (studentKey, currentData, slot, placeholder) => {
        const value = currentData[slot] ?? defaultUnresolvedSlotValue();
        const studentMarked = isStudentMarkedSlot(currentData, slot);

        return (
            <div className="relative">
                {studentMarked && (
                    <span
                        className="mb-1 inline-flex items-center gap-1 rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300"
                        title="Student self check-in — preserved on save"
                    >
                        <UserCheck className="h-3 w-3" />
                        Student
                    </span>
                )}
                <Select
                    value={value}
                    disabled={studentMarked}
                    onValueChange={(val) => {
                        if (studentMarked) return;
                        const newData = { ...currentData, [slot]: val };
                        setAttendanceData((prev) => ({ ...prev, [studentKey]: newData }));
                    }}
                >
                    <SelectTrigger
                        className={`h-10 rounded-xl border text-sm ${studentMarked ? 'cursor-not-allowed opacity-90' : ''} ${statusClass(value) || 'border-alpha/30'}`}
                    >
                        <SelectValue placeholder={placeholder} />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="pending">Pending</SelectItem>
                        <SelectItem value="present">Present</SelectItem>
                        <SelectItem value="absent">Absent</SelectItem>
                        <SelectItem value="late">Late</SelectItem>
                        <SelectItem value="excused">Excused</SelectItem>
                    </SelectContent>
                </Select>
            </div>
        );
    };
    // Notes helpers: add/remove chip-style notes per student
    const addNote = (studentKey, noteText) => {
        const text = (noteText || '').trim();
        if (!text) return;

        setAttendanceData((prev) => {
            const student = students.find((s) => s.id === studentKey);
            const base = getDefaultSlots(student);
            const prevForStudent = prev[studentKey] || base;

            const existingNotes = Array.isArray(prevForStudent.notes) ? prevForStudent.notes : prevForStudent.notes ? [prevForStudent.notes] : [];

            return {
                ...prev,
                [studentKey]: {
                    ...prevForStudent,
                    notes: [...existingNotes, text],
                },
            };
        });
    };

    const removeNote = (studentKey, index) => {
        setAttendanceData((prev) => {
            const student = students.find((s) => s.id === studentKey);
            const base = getDefaultSlots(student);
            const prevForStudent = prev[studentKey] || base;

            const existingNotes = Array.isArray(prevForStudent.notes)
                ? [...prevForStudent.notes]
                : prevForStudent.notes
                  ? [prevForStudent.notes]
                  : [];

            if (isStudentCheckInNote(existingNotes[index])) {
                return prev;
            }

            existingNotes.splice(index, 1);

            return {
                ...prev,
                [studentKey]: {
                    ...prevForStudent,
                    notes: existingNotes,
                },
            };
        });
    };
    // Filter enrolled students
    const filteredStudents = students.filter(
        (s) => s.name.toLowerCase().includes(filter.toLowerCase()) || s.email.toLowerCase().includes(filter.toLowerCase()),
    );

    // Filter available users to exclude admins, coaches, and already assigned students
    const filteredAvailableUsers = availableUsers.filter((user) => {
        // Exclude admins (assuming role field exists)
        if (user.role.includes('admin') || user.role.includes('moderateur')) return false;

        // Exclude coaches (assuming role field exists)
        if (user.role.includes('coach')) return false;

        // Exclude users already assigned to this training
        const isAlreadyAssigned = students.some((student) => student.id === user.id);
        if (isAlreadyAssigned) return false;

        // Apply search filter
        if (modalFilter) {
            const searchTerm = modalFilter.toLowerCase();
            if (!user.name.toLowerCase().includes(searchTerm)) return false;
        }

        return true;
    });

    // Delete student

    // Add student from modal
    const handleAddStudent = (user) => {
        router.post(
            `/trainings/${training.id}/students`,
            { student_id: user.id },
            {
                onSuccess: () => {
                    setStudents((prev) => [...prev, user]);
                    setAvailableUsers((prev) => prev.filter((u) => u.id !== user.id));
                },
            },
        );
    };
    const handleDelete = (userId) => {
        const student = students.find((s) => s.id === userId);
        setStudentToDelete(student);
        setShowDeleteConfirm(true);
    };

    const confirmDelete = () => {
        if (studentToDelete) {
            router.delete(`/trainings/${training.id}/students/${studentToDelete.id}`, {
                onSuccess: () => {
                    setStudents((prev) => prev.filter((s) => s.id !== studentToDelete.id));
                    setAvailableUsers((prev) => [...prev, studentToDelete]);
                    setShowDeleteConfirm(false);
                    setStudentToDelete(null);
                },
            });
        }
    };

    return (
        <AppLayout>
            <Head title={training.name} />

            <div className="min-h-screen p-6">
                {/* Header */}
                <div className="mb-6 flex flex-col gap-3 sm:mb-8 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-2xl leading-tight font-extrabold break-words text-dark sm:text-3xl dark:text-light">{training.name}</h1>
                        <p className="mt-1 text-dark/70 sm:mt-2 dark:text-light/70">{training.category}</p>
                    </div>
                    <div className="flex w-full items-center gap-2 sm:w-auto">
                        <ExercicesModal trainingId={training.id} courses={courses} />
                        <Button
                            onClick={() => setIsModalOpen(true)}
                            className="flex-1 gap-2 border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)] sm:flex-none"
                        >
                            <Plus size={16} />
                            <span>Add Student</span>
                        </Button>
                        <Button
                            onClick={() => setShowAttendance(true)}
                            className="flex-1 gap-2 border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)] sm:flex-none"
                        >
                            <CalendarCheck size={16} />
                            <span>Attendance</span>
                        </Button>
                        <Button
                            onClick={() => setShowBulkUpdateModal(true)}
                            className="flex-1 gap-2 border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)] sm:flex-none"
                        >
                            <Settings size={16} />
                            <span>Update Users</span>
                        </Button>
                        {canPrintCertificates && (
                            <Button
                                onClick={() => setShowCertificateModal(true)}
                                className="flex-1 gap-2 border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)] sm:flex-none"
                            >
                                <Award size={16} />
                                <span className="hidden sm:inline">Certificats</span>
                            </Button>
                        )}

                        {/* Play Dropdown */}
                        <div className="relative flex-1 sm:flex-initial" ref={dropdownRef}>
                            <Button
                                onClick={() => setShowPlayDropdown(!showPlayDropdown)}
                                className="w-full gap-2 border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black transition-all duration-300 hover:bg-transparent hover:text-[var(--color-alpha)] sm:w-auto"
                            >
                                <Play size={16} />
                                <span className="hidden sm:inline">Play</span>
                                <ChevronDown
                                    size={16}
                                    className={`transform transition-transform duration-300 ${showPlayDropdown ? 'rotate-180' : ''}`}
                                />
                            </Button>

                            {showPlayDropdown && (
                                <div className="absolute right-0 z-50 mt-2 w-full rounded-xl border border-alpha/20 bg-light text-dark shadow-xl duration-200 animate-in slide-in-from-top-2 sm:w-48 dark:bg-dark dark:text-light">
                                    <button
                                        onClick={openGeekyWheel}
                                        className="w-full rounded-t-xl px-4 py-3 text-left font-semibold text-dark transition-colors hover:bg-alpha/10 dark:text-light"
                                    >
                                        Geeky Wheel
                                    </button>
                                    <button
                                        onClick={() => {
                                            setShowPlayDropdown(false);
                                            router.visit(`/training/${training.id}/geeko`);
                                        }}
                                        className="w-full rounded-b-xl px-4 py-3 text-left font-semibold text-dark transition-colors hover:bg-alpha/10 dark:text-light"
                                    >
                                        Geeko
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Hero Image */}
                <div className="mb-8 h-64 w-full overflow-hidden rounded-2xl border border-alpha/20">
                    {training.img ? (
                        <img
                            src={
                                training.category?.toLowerCase() === 'coding'
                                    ? '/assets/images/training/coding.jpg'
                                    : training.category?.toLowerCase() === 'media'
                                      ? '/assets/images/training/media.jpg'
                                      : training.img
                                        ? `/storage/img/training/${training.img}`
                                        : '/assets/images/training/default.jpg'
                            }
                            alt={training.name}
                            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110"
                        />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-alpha to-alpha/70 text-xl font-bold text-light">
                            {training.name}
                        </div>
                    )}
                </div>

                {/* Courses Section */}
                <div className="mb-8 rounded-2xl border border-alpha/20 bg-light p-6 text-dark dark:bg-dark dark:text-light">
                    <div className="mb-6 flex items-center justify-between">
                        <h2 className="text-xl font-bold">Courses ({courses.length})</h2>
                        <CoursesModal />
                    </div>

                    {courses.length > 0 ? (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {courses.map((course) => (
                                <div
                                    key={course.id}
                                    className="rounded-xl border border-alpha/10 bg-light/50 p-4 transition-colors hover:border-alpha/30 dark:bg-dark/50"
                                >
                                    <div className="mb-3 flex items-start justify-between">
                                        <div>
                                            <h3 className="text-lg font-semibold text-dark dark:text-light">{course.name}</h3>
                                            {course.description && (
                                                <p className="mt-1 line-clamp-2 text-sm text-dark/70 dark:text-light/70">{course.description}</p>
                                            )}
                                        </div>
                                    </div>

                                    {/* Badges Display */}
                                    <div className="mt-3 flex items-center gap-2">
                                        {course.badge1 && (
                                            <img
                                                src={`/storage/img/courses/${course.badge1}`}
                                                alt="Badge 1"
                                                className="h-8 w-8 rounded-full border border-alpha/20 object-cover"
                                            />
                                        )}
                                        {course.badge2 && (
                                            <img
                                                src={`/storage/img/courses/${course.badge2}`}
                                                alt="Badge 2"
                                                className="h-8 w-8 rounded-full border border-alpha/20 object-cover"
                                            />
                                        )}
                                        {course.badge3 && (
                                            <img
                                                src={`/storage/img/courses/${course.badge3}`}
                                                alt="Badge 3"
                                                className="h-8 w-8 rounded-full border border-alpha/20 object-cover"
                                            />
                                        )}
                                        {!course.badge1 && !course.badge2 && !course.badge3 && (
                                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-alpha/10">
                                                <Award className="h-4 w-4 text-alpha" />
                                            </div>
                                        )}
                                    </div>

                                    {/* Exercises Count */}
                                    {course.exercices && course.exercices.length > 0 && (
                                        <div className="mt-3 text-xs text-dark/60 dark:text-light/60">
                                            {course.exercices.length} exercise{course.exercices.length > 1 ? 's' : ''}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="py-8 text-center">
                            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-alpha/10">
                                <BookOpen className="h-8 w-8 text-alpha" />
                            </div>
                            <p className="text-dark/70 dark:text-light/70">No courses assigned to this training yet.</p>
                            <p className="mt-1 text-sm text-dark/50 dark:text-light/50">Add exercises and assign them to courses to see them here.</p>
                        </div>
                    )}
                </div>

                {students.length > 0 && <TrainingStatsCards students={students} />}

                <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">
                    {/* Left Side – Students List */}
                    <div className="space-y-6 lg:col-span-2">
                        {students.length > 0 && (
                            <div className="rounded-2xl border border-alpha/20 bg-light p-6 text-dark dark:bg-dark dark:text-light">
                                <div className="mb-4 flex items-center justify-between">
                                    <h2 className="text-xl font-bold">Enrolled Students ({students.length})</h2>
                                </div>

                                {/* Filter Input */}
                                <Input
                                    type="text"
                                    placeholder="Filter by name or email..."
                                    value={filter}
                                    onChange={(e) => setFilter(e.target.value)}
                                    className="mb-6"
                                />

                                <ul className="space-y-3">
                                    {filteredStudents.map((user) => (
                                        <li key={user.id} className="flex items-center justify-between space-x-3">
                                            <div
                                                className="flex flex-1 cursor-pointer items-center space-x-3 rounded-lg p-2 transition-colors hover:bg-alpha/5"
                                                onClick={() => router.visit(`/admin/users/${user.id}`)}
                                            >
                                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-alpha font-bold text-black">
                                                    {user.name.charAt(0).toUpperCase()}
                                                </div>
                                                <div>
                                                    <p className="font-semibold text-dark dark:text-light">{user.name}</p>
                                                    <p className="text-sm text-dark/70 dark:text-light/70">{user.email}</p>
                                                    <button
                                                        className="mt-1 inline-block text-xs text-red-600 hover:text-red-700 md:hidden"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            handleDelete(user.id);
                                                        }}
                                                    >
                                                        Remove
                                                    </button>
                                                </div>
                                            </div>
                                            <Button
                                                variant="outline"
                                                onClick={() => handleDelete(user.id)}
                                                className="hidden gap-1 border-red-600 px-3 py-1 text-red-600 hover:bg-red-50 md:inline-flex dark:hover:bg-red-900/10"
                                            >
                                                Remove
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>

                    {/* Right Side – Info */}
                    <div className="space-y-6">
                        {/* Coach Card */}
                        <div className="flex items-center space-x-4 rounded-2xl border border-alpha/20 bg-light p-6 dark:bg-dark">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-alpha text-lg font-bold text-black">
                                {training.coach
                                    ? training.coach.name
                                          .split(' ')
                                          .map((n) => n[0])
                                          .join('')
                                          .toUpperCase()
                                    : 'C'}
                            </div>
                            <div>
                                <p className="font-bold text-dark dark:text-light">{training.coach?.name || 'Expert Instructor'}</p>
                                <p className="text-sm text-dark/70 dark:text-light/70">{training.coach?.speciality || 'Professional Mentor'}</p>
                            </div>
                        </div>

                        {/* Course Info */}
                        <div className="space-y-4 rounded-2xl border border-alpha/20 bg-light p-6 dark:bg-dark">
                            <div className="flex items-center space-x-3">
                                <CalendarDays className="text-alpha" />
                                <div>
                                    <p className="text-sm text-dark/70 dark:text-light/70">Start Time</p>
                                    <p className="font-bold text-dark dark:text-light">{training.start_time || 'N/A'}</p>
                                </div>
                            </div>

                            <div className="flex items-center space-x-3">
                                <Users className="text-alpha" />
                                <div>
                                    <p className="text-sm text-dark/70 dark:text-light/70">Enrolled Students</p>
                                    <p className="font-bold text-dark dark:text-light">{students.length}</p>
                                </div>
                            </div>

                            <div className="flex items-center space-x-3">
                                <User className="text-alpha" />
                                <div>
                                    <p className="text-sm text-dark/70 dark:text-light/70">Max Participants</p>
                                    <p className="font-bold text-dark dark:text-light">{training.max_participants || 'Unlimited'}</p>
                                </div>
                            </div>
                        </div>

                        {/* QR Code Card */}
                        {(isCoachRole || isTrainingCoach) && (
                            <div className="rounded-2xl border border-alpha/20 bg-light p-6 dark:bg-dark">
                                <div className="mb-4 flex items-center justify-between">
                                    <div className="flex items-center space-x-2">
                                        <QrCode className="text-alpha" size={20} />
                                        <h3 className="text-lg font-bold text-dark dark:text-light">Attendance QR Code</h3>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setQrCodeDate(new Date().toISOString().split('T')[0])}
                                        className="h-8 gap-1 px-2"
                                    >
                                        <RefreshCw size={14} />
                                    </Button>
                                </div>
                                <div className="flex flex-col items-center space-y-3">
                                    <div className="rounded-xl border border-alpha/20 bg-white p-4 dark:bg-dark_gray [&_svg]:dark:invert">
                                        <QRCode
                                            value={JSON.stringify({
                                                training_id: training.id,
                                                date: qrCodeDate,
                                            })}
                                            size={200}
                                            level="M"
                                            bgColor="#FFFFFF"
                                            fgColor="#000000"
                                        />
                                    </div>
                                    <div className="space-y-1 text-center">
                                        <p className="text-sm font-semibold text-dark dark:text-light">{training.name}</p>
                                        <p className="text-xs text-dark/70 dark:text-light/70">
                                            Date:{' '}
                                            {new Date(qrCodeDate).toLocaleDateString('en-US', {
                                                weekday: 'long',
                                                year: 'numeric',
                                                month: 'long',
                                                day: 'numeric',
                                            })}
                                        </p>
                                        <p className="mt-2 text-xs text-dark/50 dark:text-light/50">Scan with mobile app to mark attendance</p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Active for attendance reminders */}
                        <div className="rounded-2xl border border-alpha/20 bg-light p-4 text-center dark:bg-dark">
                            <span
                                className={`rounded-full px-4 py-2 text-sm font-bold ${
                                    isActiveTraining ? 'bg-alpha/10 text-alpha' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'
                                }`}
                            >
                                {isActiveTraining ? 'REMINDERS ON' : 'REMINDERS OFF'}
                            </span>
                            <p className="mt-2 text-xs text-dark/50 dark:text-light/50">
                                Toggle via Edit Training on the programs list
                            </p>
                        </div>
                    </div>
                </div>

                {/* Modal for adding students */}

                <Dialog open={isModalOpen} onOpenChange={setIsModalOpen}>
                    <DialogContent className="max-w-lg border border-alpha/20 bg-light text-dark dark:bg-dark dark:text-light">
                        <DialogHeader>
                            <DialogTitle className="text-2xl">Add Student</DialogTitle>
                        </DialogHeader>

                        {/* Search Filter */}
                        <div className="mt-4">
                            <Input type="text" placeholder="Search by name..." value={modalFilter} onChange={(e) => setModalFilter(e.target.value)} />
                        </div>

                        <div className="mt-4 max-h-96 overflow-y-auto">
                            <div className="space-y-2">
                                {filteredAvailableUsers.length === 0 ? (
                                    <div className="px-4 py-6 text-center text-dark/50 dark:text-light/60">No available students</div>
                                ) : (
                                    filteredAvailableUsers.map((user) => (
                                        <div
                                            key={user.id}
                                            className="flex cursor-pointer items-center justify-between rounded-lg border border-alpha/20 p-3 transition-colors hover:border-alpha/40"
                                            onClick={() => router.visit(`/students/${user.id}`)}
                                        >
                                            <div className="flex items-center space-x-3">
                                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-alpha font-bold text-black">
                                                    {user.name.charAt(0).toUpperCase()}
                                                </div>
                                                <div>
                                                    <p className="font-semibold text-dark dark:text-light">{user.name}</p>
                                                </div>
                                            </div>
                                            <Button
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    handleAddStudent(user);
                                                }}
                                                variant="outline"
                                                className="inline-flex items-center gap-2 px-3 py-1 text-sm font-semibold"
                                            >
                                                <UserPlus size={16} />
                                                Add
                                            </Button>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                        <div className="mt-4 text-right">
                            <Button variant="outline" onClick={() => setIsModalOpen(false)}>
                                Close
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>

                {/* Attendance Modal with FullCalendar */}
                <Dialog open={showAttendance} onOpenChange={setShowAttendance}>
                    <DialogContent className="flex h-[100svh] w-full max-w-full flex-col gap-4 overflow-x-hidden overflow-y-auto rounded-none border border-alpha/20 bg-light p-4 text-dark shadow-xl sm:h-auto sm:max-w-[95vw] sm:gap-5 sm:rounded-2xl sm:p-6 md:p-8 lg:max-w-[1100px] dark:bg-dark dark:text-light">
                        {/* Header */}
                        <DialogHeader>
                            <DialogTitle className="text-3xl font-extrabold text-dark lg:text-4xl dark:text-light">
                                Training Attendance Calendar
                            </DialogTitle>
                            <p className="text-lg text-dark/70 lg:text-xl dark:text-light/70">Click on any day to manage attendance for that date</p>
                        </DialogHeader>

                        {/* Calendar */}
                        {/* Custom calendar toolbar */}
                        <div className="flex flex-col gap-3">
                            <div className="text-center text-sm font-semibold sm:text-base md:text-lg">{calendarTitle}</div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" onClick={() => calendarApi && calendarApi.prev()} className="p-2">
                                        <ChevronLeft size={16} />
                                    </Button>
                                    <Button variant="outline" onClick={() => calendarApi && calendarApi.today()} className="px-3">
                                        Today
                                    </Button>
                                    <Button variant="outline" onClick={() => calendarApi && calendarApi.next()} className="p-2">
                                        <ChevronRight size={16} />
                                    </Button>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        className="border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)]"
                                        onClick={() => {
                                            const api =
                                                calendarApi ||
                                                (calendarRef.current && typeof calendarRef.current.getApi === 'function'
                                                    ? calendarRef.current.getApi()
                                                    : null);
                                            if (api && typeof api.changeView === 'function') {
                                                api.changeView('dayGridMonth');
                                                if (typeof api.today === 'function') {
                                                    api.today();
                                                }
                                            }
                                        }}
                                    >
                                        Month
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div
                            className="overflow-x-auto overflow-y-auto rounded-xl border border-alpha/20 bg-light p-2 text-dark shadow-sm sm:p-3 md:p-4 dark:bg-dark dark:text-light"
                            style={{ height: 'calc(100svh - 260px)' }}
                        >
                            {calendarError && (
                                <div className="mb-3 rounded-lg border border-yellow-300 bg-yellow-50 px-3 py-2 text-sm text-yellow-800 dark:border-yellow-700/60 dark:bg-yellow-900/40 dark:text-yellow-100">
                                    {calendarError}
                                </div>
                            )}
                            <FullCalendar
                                ref={(el) => {
                                    calendarRef.current = el;
                                    if (el && typeof el.getApi === 'function') {
                                        const api = el.getApi();
                                        if (api !== calendarApi) setCalendarApi(api);
                                    }
                                }}
                                plugins={[dayGridPlugin, interactionPlugin]}
                                initialView="dayGridMonth"
                                selectable={true}
                                selectMirror={true}
                                editable={true}
                                events={events}
                                datesSet={(arg) => setCalendarTitle(arg.view.title)}
                                eventClick={(info) => {
                                    const eventDate = info?.event?.start;
                                    const dateStr = info?.event?.startStr || info?.event?._instance?.range?.start?.toISOString()?.slice(0, 10);
                                    if (!dateStr) return;

                                    setCalendarError('');
                                    setSelectedDate(dateStr);
                                    AddAttendance(dateStr);
                                    setShowAttendance(false);
                                    setShowAttendanceList(true);
                                }}
                                dateClick={(info) => {
                                    setCalendarError('');
                                    setSelectedDate(info.dateStr);
                                    AddAttendance(info.dateStr);
                                    setShowAttendance(false);
                                    setShowAttendanceList(true);
                                }}
                                height="100%"
                                headerToolbar={{ left: '', center: '', right: '' }}
                                dayMaxEvents={true}
                                moreLinkClick="popover"
                                eventDisplay="block"
                                eventTextColor="#000000"
                                dayCellClassNames="hover:bg-alpha/10 cursor-pointer transition-colors duration-200 rounded-md"
                                dayHeaderClassNames="bg-secondary/50 text-dark dark:text-light font-semibold text-[13px]"
                                todayClassNames="bg-alpha/20 border border-alpha/60"
                                dayCellContent={(info) => (
                                    <div className="flex h-full items-center justify-center font-semibold text-dark dark:text-light">
                                        {info.dayNumberText}
                                    </div>
                                )}
                                dayHeaderContent={(info) => <div className="text-center font-bold text-dark dark:text-light">{info.text}</div>}
                            />
                        </div>

                        {/* Legend removed to allow calendar more vertical space */}
                    </DialogContent>
                </Dialog>

                {/* Attendance List Modal */}
                <Dialog open={showAttendanceList} onOpenChange={setShowAttendanceList}>
                    <DialogContent className="h-[100svh] w-full max-w-full overflow-x-hidden overflow-y-auto rounded-none border border-alpha/20 bg-light p-4 text-dark sm:h-auto sm:max-w-[95vw] sm:rounded-2xl sm:p-5 md:p-6 lg:max-w-[1150px] dark:bg-dark dark:text-light">
                        <DialogHeader>
                            <DialogTitle className="text-2xl font-extrabold text-dark md:text-3xl dark:text-light">
                                Attendance for{' '}
                                {selectedDate &&
                                    new Date(selectedDate).toLocaleDateString('en-US', {
                                        weekday: 'long',
                                        year: 'numeric',
                                        month: 'long',
                                        day: 'numeric',
                                    })}
                            </DialogTitle>
                            <p className="text-sm text-dark/70 md:text-base dark:text-light/70">Mark attendance for each student</p>
                        </DialogHeader>
                        <div className="mt-4">
                            <div className="-ml-3 h-[52vh] overflow-x-hidden overflow-y-auto rounded-xl border border-alpha/20 bg-light text-dark shadow-sm md:-ml-4 dark:bg-dark dark:text-light">
                                {/* Mobile cards layout */}
                                <div className="block space-y-3 p-3 pr-4 md:hidden">
                                    {students.map((student) => {
                                        const studentKey = `${selectedDate}-${student.id}`;
                                        const currentData = attendanceData[studentKey] || getDefaultSlots(student);
                                        return (
                                            <div key={student.id} className="rounded-lg border border-alpha/20 p-3">
                                                <div className="mb-3 flex items-center gap-3">
                                                    <div className="flex h-9 w-9 items-center justify-center rounded-full bg-alpha font-bold text-black">
                                                        {student.name.charAt(0).toUpperCase()}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="truncate font-semibold text-dark dark:text-light">{student.name}</p>
                                                        <p className="truncate text-xs text-dark/70 dark:text-light/70">{student.email}</p>
                                                    </div>
                                                </div>
                                                <div className="grid grid-cols-1 gap-2">
                                                    {renderSlotSelect(studentKey, currentData, 'morning', '9:30 - 11:00')}
                                                    {renderSlotSelect(studentKey, currentData, 'lunch', '11:30 - 13:00')}
                                                    {renderSlotSelect(studentKey, currentData, 'evening', '14:00 - 17:00')}

                                                    <div className="flex flex-wrap items-center gap-2 rounded-xl border border-alpha/30 bg-light p-2 dark:bg-dark">
                                                        {Array.isArray(currentData.notes) &&
                                                            currentData.notes.map((n, idx) => (
                                                                <span
                                                                    key={idx}
                                                                    className="inline-flex items-center gap-2 rounded-full bg-secondary/50 px-3 py-1 text-xs"
                                                                >
                                                                    {n}
                                                                    {!isStudentCheckInNote(n) && (
                                                                        <button
                                                                            type="button"
                                                                            className="text-red-500 hover:text-red-600"
                                                                            onClick={() => removeNote(studentKey, idx)}
                                                                        >
                                                                            ×
                                                                        </button>
                                                                    )}
                                                                </span>
                                                            ))}
                                                        <div className="flex w-full items-center gap-2 sm:w-auto">
                                                            <input
                                                                type="text"
                                                                placeholder="Add note"
                                                                className="flex-1 bg-transparent text-sm outline-hidden"
                                                                onKeyDown={(e) => {
                                                                    if (e.key === 'Enter') {
                                                                        e.preventDefault();
                                                                        addNote(studentKey, e.currentTarget.value);
                                                                        e.currentTarget.value = '';
                                                                    }
                                                                }}
                                                            />
                                                            <button
                                                                type="button"
                                                                className="rounded-md bg-alpha/10 px-3 py-1 text-xs font-medium text-black transition-colors hover:bg-alpha/20 dark:text-black"
                                                                onClick={(e) => {
                                                                    const input = e.currentTarget.previousElementSibling;
                                                                    const val = input && 'value' in input ? input.value : '';
                                                                    addNote(studentKey, val);
                                                                    if (input && 'value' in input) input.value = '';
                                                                }}
                                                            >
                                                                Add
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {/* Desktop table */}
                                <div className="hidden md:block">
                                    <div className="overflow-x-hidden">
                                        <table className="w-full text-sm">
                                            <thead className="sticky top-0 z-10 bg-secondary/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-semibold text-dark dark:text-light">Student</th>
                                                    <th className="px-4 py-3 text-center font-semibold text-dark dark:text-light">9:30 - 11:00</th>
                                                    <th className="px-4 py-3 text-center font-semibold text-dark dark:text-light">11:30 - 13:00</th>
                                                    <th className="px-4 py-3 text-center font-semibold text-dark dark:text-light">14:00 - 17:00</th>
                                                    <th className="px-4 py-3 text-center font-semibold text-dark dark:text-light">Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-alpha/10">
                                                {students.map((student) => {
                                                    const studentKey = `${selectedDate}-${student.id}`;
                                                    const currentData = attendanceData[studentKey] || getDefaultSlots(student);
                                                    return (
                                                        <tr key={student.id} className="transition-colors hover:bg-accent/30">
                                                            <td className="px-4 py-3">
                                                                <div className="flex items-center space-x-3">
                                                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-alpha font-bold text-black">
                                                                        {student.name.charAt(0).toUpperCase()}
                                                                    </div>
                                                                    <div>
                                                                        <p className="text-base font-bold text-dark dark:text-light">
                                                                            {student.name}
                                                                        </p>
                                                                        <p className="text-xs text-dark/70 md:text-sm dark:text-light/70">
                                                                            {student.email}
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td className="px-4 py-3 text-center">
                                                                <div className="inline-block min-w-[150px]">
                                                                    {renderSlotSelect(studentKey, currentData, 'morning', 'Morning')}
                                                                </div>
                                                            </td>
                                                            <td className="px-4 py-3 text-center">
                                                                <div className="inline-block min-w-[150px]">
                                                                    {renderSlotSelect(studentKey, currentData, 'lunch', '11:30 - 13:00')}
                                                                </div>
                                                            </td>
                                                            <td className="px-4 py-3 text-center">
                                                                <div className="inline-block min-w-[150px]">
                                                                    {renderSlotSelect(studentKey, currentData, 'evening', '14:00 - 17:00')}
                                                                </div>
                                                            </td>

                                                            <td className="px-4 py-3 text-center">
                                                                <div className="flex flex-wrap items-center gap-2 rounded-xl border border-alpha/30 bg-light px-2 py-2 text-sm dark:bg-dark">
                                                                    {Array.isArray(currentData.notes) &&
                                                                        currentData.notes.map((n, idx) => (
                                                                            <span
                                                                                key={idx}
                                                                                className="inline-flex items-center gap-2 rounded-full bg-secondary/50 px-3 py-1 text-xs"
                                                                            >
                                                                                {n}
                                                                                <button
                                                                                    type="button"
                                                                                    className="text-red-500 hover:text-red-600"
                                                                                    onClick={() => removeNote(studentKey, idx)}
                                                                                >
                                                                                    ×
                                                                                </button>
                                                                            </span>
                                                                        ))}
                                                                    <input
                                                                        type="text"
                                                                        placeholder="Add note"
                                                                        className="flex-1 bg-transparent outline-hidden"
                                                                        onKeyDown={(e) => {
                                                                            if (e.key === 'Enter') {
                                                                                e.preventDefault();
                                                                                addNote(studentKey, e.currentTarget.value);
                                                                                e.currentTarget.value = '';
                                                                            }
                                                                        }}
                                                                    />
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="sticky bottom-0 mt-6 flex flex-col gap-3 border-t border-alpha/20 bg-light py-3 pr-3 sm:flex-row sm:items-center sm:justify-between md:pr-6 dark:bg-dark">
                            <div className="text-sm text-dark/70 md:text-base dark:text-light/70">
                                Total Students: <span className="text-xl font-bold">{students.length}</span>
                            </div>
                            <div className="flex flex-col gap-2 sm:flex-row sm:gap-0 sm:space-x-4">
                                <Button variant="outline" onClick={() => setShowAttendanceList(false)} className="w-full px-6 py-2 sm:w-auto">
                                    Cancel
                                </Button>
                                <Button
                                    onClick={() => {
                                        // Save attendance logic here
                                        handleSave();
                                    }}
                                    className="w-full border border-[var(--color-alpha)] bg-[var(--color-alpha)] px-6 py-2 text-black hover:bg-transparent hover:text-[var(--color-alpha)] sm:w-auto"
                                >
                                    Save Attendance
                                </Button>
                            </div>
                        </div>
                    </DialogContent>
                </Dialog>

                <GeekyWheel
                    setShowWinnerModal={setShowWinnerModal}
                    showGeekyWheel={showGeekyWheel}
                    setShowGeekyWheel={setShowGeekyWheel}
                    wheelParticipants={wheelParticipants}
                    isSpinning={isSpinning}
                    selectedWinner={selectedWinner}
                    continueSpinning={continueSpinning}
                    resetWheel={resetWheel}
                    removeWinner={removeWinner}
                    showWinnerModal={showWinnerModal}
                    spinWheel={spinWheel}
                    wheelRotation={wheelRotation}
                />
                {/* Delete Confirmation Modal */}
                <Dialog open={showDeleteConfirm} onOpenChange={setShowDeleteConfirm}>
                    <DialogContent className="max-w-md border border-alpha/20 bg-light text-dark dark:bg-dark dark:text-light">
                        <DialogHeader>
                            <DialogTitle className="text-lg">Confirm Deletion</DialogTitle>
                        </DialogHeader>
                        <div className="mt-4">
                            <p className="text-dark/70 dark:text-light/70">
                                Are you sure you want to remove <strong>{studentToDelete?.name}</strong> from this training?
                            </p>
                        </div>
                        <div className="mt-6 flex justify-end space-x-3">
                            <Button variant="outline" onClick={() => setShowDeleteConfirm(false)}>
                                Cancel
                            </Button>
                            <Button className="bg-red-500 text-white hover:bg-red-600" onClick={confirmDelete}>
                                Remove Student
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>

                {/* Certificate Generation Modal */}
                {canPrintCertificates && (
                    <CertificateModal
                        open={showCertificateModal}
                        onOpenChange={setShowCertificateModal}
                        training={{ ...training, users: students }}
                    />
                )}

                {/* Bulk Update Users Modal */}
                <Dialog open={showBulkUpdateModal} onOpenChange={setShowBulkUpdateModal}>
                    <DialogContent className="max-h-[80vh] w-full max-w-2xl overflow-y-auto border border-alpha/20 bg-light text-dark dark:bg-dark dark:text-light">
                        <DialogHeader>
                            <DialogTitle className="text-xl font-semibold">Update Users - {training.name}</DialogTitle>
                            <p className="text-sm text-dark/70 dark:text-light/70">Select users and update their roles, program status, and/or handicap</p>
                        </DialogHeader>

                        <div className="mt-4 space-y-6">
                            {/* Select All Checkbox */}
                            <div className="flex items-center space-x-2 border-b border-alpha/20 pb-3">
                                <Checkbox
                                    id="select-all"
                                    checked={selectedUserIds.length === students.length && students.length > 0}
                                    onCheckedChange={(checked) => {
                                        if (checked) {
                                            setSelectedUserIds(students.map((s) => s.id));
                                        } else {
                                            setSelectedUserIds([]);
                                        }
                                    }}
                                />
                                <label htmlFor="select-all" className="cursor-pointer text-sm font-medium">
                                    Select All ({students.length} users)
                                </label>
                            </div>

                            {/* Users List with Checkboxes */}
                            <div className="max-h-60 space-y-2 overflow-y-auto">
                                {students.map((student) => (
                                    <div
                                        key={student.id}
                                        className="flex items-center space-x-3 rounded-lg border border-alpha/20 p-3 transition-colors hover:bg-alpha/5"
                                    >
                                        <Checkbox
                                            id={`user-${student.id}`}
                                            checked={selectedUserIds.includes(student.id)}
                                            onCheckedChange={(checked) => {
                                                if (checked) {
                                                    setSelectedUserIds([...selectedUserIds, student.id]);
                                                } else {
                                                    setSelectedUserIds(selectedUserIds.filter((id) => id !== student.id));
                                                }
                                            }}
                                        />
                                        <div className="flex flex-1 items-center space-x-3">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-alpha font-bold text-black">
                                                {student.name.charAt(0).toUpperCase()}
                                            </div>
                                            <div>
                                                <p className="font-semibold text-dark dark:text-light">{student.name}</p>
                                                <p className="text-xs text-dark/70 dark:text-light/70">{student.email}</p>
                                                <div className="mt-1 text-xs text-dark/60 dark:text-light/60">
                                                    Role: {Array.isArray(student.role) ? student.role.join(', ') : student.role || 'N/A'} | Program status:{' '}
                                                    {programStatusLabel(student.program_status) || 'N/A'}
                                                    {canEditHealthData && (
                                                        <>
                                                            {' '}
                                                            | Handicap:{' '}
                                                            {student.has_handicap === true ||
                                                            student.has_handicap === 1 ||
                                                            student.has_handicap === '1'
                                                                ? 'Oui'
                                                                : student.has_handicap === false ||
                                                                    student.has_handicap === 0 ||
                                                                    student.has_handicap === '0'
                                                                  ? 'Non'
                                                                  : 'N/A'}
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {/* Update Options */}
                            {selectedUserIds.length > 0 && (
                                <div className="space-y-4 border-t border-alpha/20 pt-4">
                                    <div>
                                        <label className="mb-2 block text-sm font-medium">Update Roles (optional)</label>
                                        <RolesMultiSelect roles={bulkRoles} onChange={setBulkRoles} />
                                        <p className="mt-1 text-xs text-dark/60 dark:text-light/60">Leave empty to keep existing roles</p>
                                    </div>

                                    <div>
                                        <label className="mb-2 block text-sm font-medium">Update Program status (optional)</label>
                                        <Select
                                            value={bulkProgramStatus || undefined}
                                            onValueChange={(value) => setBulkProgramStatus(value === 'keep-existing' ? '' : value)}
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select program status (optional)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="keep-existing">Keep existing program status</SelectItem>
                                                <SelectItem value={PROGRAM_STATUS.ACTIVE}>Active</SelectItem>
                                                {canAssignProgramStatusLeftOnTraining && (
                                                    <SelectItem value={PROGRAM_STATUS.LEFT}>Left</SelectItem>
                                                )}
                                                <SelectGroup>
                                                    <SelectLabel>Certificate &amp; Not Certificate</SelectLabel>
                                                    {PROGRAM_STATUS_CERTIFICATE_OUTCOME_OPTIONS.map((option) => (
                                                        <SelectItem key={option.value} value={option.value}>
                                                            {option.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {canEditHealthData && (
                                        <div>
                                            <label className="mb-2 block text-sm font-medium">Update Handicap (optional)</label>
                                            <Select
                                                value={bulkHasHandicap || undefined}
                                                onValueChange={(value) => setBulkHasHandicap(value === 'keep-existing' ? '' : value)}
                                            >
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Select handicap (optional)" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="keep-existing">Keep existing handicap</SelectItem>
                                                    {HANDICAP_OPTIONS.map((option) => (
                                                        <SelectItem key={option.value} value={option.value}>
                                                            {option.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="mt-6 flex justify-end space-x-3 border-t border-alpha/20 pt-4">
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setShowBulkUpdateModal(false);
                                    setSelectedUserIds([]);
                                    setBulkRoles([]);
                                    setBulkProgramStatus('');
                                    setBulkHasHandicap('');
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={() => {
                                    if (selectedUserIds.length === 0) {
                                        return;
                                    }

                                    const formData = {
                                        user_ids: selectedUserIds,
                                    };

                                    if (bulkRoles.length > 0) {
                                        formData.roles = bulkRoles;
                                    }

                                    if (bulkProgramStatus && bulkProgramStatus !== 'keep-existing') {
                                        formData.program_status = bulkProgramStatus === 'all' ? '' : bulkProgramStatus;
                                    }

                                    if (canEditHealthData && bulkHasHandicap && bulkHasHandicap !== 'keep-existing') {
                                        formData.has_handicap = bulkHasHandicap;
                                    }

                                    router.post(`/trainings/${training.id}/bulk-update-users`, formData, {
                                        onSuccess: () => {
                                            setShowBulkUpdateModal(false);
                                            setSelectedUserIds([]);
                                            setBulkRoles([]);
                                            setBulkProgramStatus('');
                                            setBulkHasHandicap('');
                                        },
                                        onError: (errors) => {
                                            console.error('Update error:', errors);
                                        },
                                    });
                                }}
                                disabled={
                                    selectedUserIds.length === 0 ||
                                    (bulkRoles.length === 0 &&
                                        (!bulkProgramStatus || bulkProgramStatus === 'keep-existing') &&
                                        (!canEditHealthData || !bulkHasHandicap || bulkHasHandicap === 'keep-existing'))
                                }
                                className="border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)] disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Update {selectedUserIds.length} User{selectedUserIds.length !== 1 ? 's' : ''}
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>

                {/* <Dialog
          open={reminderModal.open}
          onOpenChange={(open) => setReminderModal((prev) => ({ open, slot: open ? prev.slot : null }))}
        >
          <DialogContent className="w-full max-w-md bg-light text-dark dark:bg-dark dark:text-light border border-alpha/20">
            <DialogHeader>
              <DialogTitle className="text-xl font-semibold">Attendance Reminder</DialogTitle>
            </DialogHeader>
            <p className="text-sm text-muted-foreground mb-4">
              It&apos;s past {reminderModal.slot?.label}. Don&apos;t forget to submit today&apos;s attendance for {training.name}.
            </p>
            <div className="flex flex-col sm:flex-row gap-2">
              <Button
                className="flex-1 bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)]"
                onClick={() => {
                  const todayStr = new Date().toISOString().slice(0, 10);
                  setReminderModal({ open: false, slot: null });
                  setSelectedDate(todayStr);
                  AddAttendance(todayStr);
                  setShowAttendance(false);
                  setShowAttendanceList(true);
                }}
              >
                Go to Attendance
              </Button>
              <Button
                variant="outline"
                className="flex-1"
                onClick={() => setReminderModal({ open: false, slot: null })}
              >
                Later
              </Button>
            </div>
          </DialogContent>
        </Dialog> */}
            </div>
        </AppLayout>
    );
}
