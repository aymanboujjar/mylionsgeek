import Banner from '@/components/banner';
import CoursesModal from "@/components/EXP'S/courses_modal";
import StatCard from '@/components/StatCard';
import { Avatar } from '@/components/ui/avatar';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Award, BookOpen, Building2, Calendar, CheckCircle2, Clock, Sparkles, Target, Timer, Trash2, TrendingUp, User, X } from 'lucide-react';
import { useState } from 'react';
import illustration from '../../../../../public/assets/images/banner/Lesson-bro.png';
import CreatTraining from './partials/CreatTraining';
import UpdateTraining from './partials/UpdateTraining';

export default function Training({ trainings, coaches, filters = {}, tracks = [], promos = [] }) {
    const [selectedCoach, setSelectedCoach] = useState(filters.coach || '');
    const [selectedTrack, setSelectedTrack] = useState(filters.track || '');
    const [selectedPromo, setSelectedPromo] = useState(filters.promo || '');
    const [hoveredCard, setHoveredCard] = useState(null);
    const openModal = () => setModalOpen(true);
    const closeModal = () => setModalOpen(false);
    const [modalOpen, setModalOpen] = useState(false);

    const [deleteModalOpen, setDeleteModalOpen] = useState(false);
    const [trainingToDelete, setTrainingToDelete] = useState(null);

    const openDeleteModal = (training) => {
        setTrainingToDelete(training);
        setDeleteModalOpen(true);
    };

    const closeDeleteModal = () => {
        setTrainingToDelete(null);
        setDeleteModalOpen(false);
    };

    const confirmDelete = () => {
        if (trainingToDelete) {
            router.delete(`/trainings/${trainingToDelete?.id}`, {
                onSuccess: () => closeDeleteModal(),
            });
        }
    };

    const deleteEnrolledCount = trainingToDelete?.users_count ?? 0;
    const deleteHasStudents = deleteEnrolledCount > 0;

    const applyFilters = (trackValue = selectedTrack, coachValue = selectedCoach, promoValue = selectedPromo) => {
        const params = new URLSearchParams();
        if (coachValue) params.set('coach', coachValue);
        if (trackValue) params.set('track', trackValue);
        if (promoValue) params.set('promo', promoValue);

        router.visit(`/admin/training?${params.toString()}`);
    };

    const handleCoachChange = (e) => {
        const value = e.target.value;
        setSelectedCoach(value);
        applyFilters(selectedTrack, value, selectedPromo);
    };

    const handleTrackChange = (e) => {
        const value = e.target.value;
        setSelectedTrack(value);
        applyFilters(value, selectedCoach, selectedPromo);
    };
    const filteredCoach = coaches.find((c) => c?.id == selectedCoach) || null;

    const handlePromoChange = (e) => {
        const value = e.target.value;
        setSelectedPromo(value);
        applyFilters(selectedTrack, selectedCoach, value);
    };

    const getTrainingStatus = (training) => {
        const start = new Date(training.start_time);
        const now = new Date();
        const sixMonthsLater = new Date(start);
        sixMonthsLater.setMonth(start.getMonth() + 6);
        if (now < sixMonthsLater && start < now) return 'active';
    };

    const activeTrainingsCount = trainings.filter((t) => getTrainingStatus(t) === 'active').length;
    const coachTraining = trainings.find((c) => c.coach?.id == selectedCoach) || null;
    const statsData = [
        {
            title: 'Total Programs',
            value: trainings.length,
            icon: BookOpen,
        },
        {
            title: 'Active Now',
            value: activeTrainingsCount,
            icon: Timer,
        },
        {
            title: filteredCoach ? 'Expert Mentor' : 'Expert Mentors',
            value: filteredCoach ? filteredCoach.name : coaches.length,
            icon: Award,
            isSmallNumber: !!filteredCoach,
        },
    ];
    return (
        <AppLayout>
            <Head title="Training" />

            <div className="min-h-screen p-4 md:p-6">
                <Banner illustration={illustration} size={400} />
                {/* Premium Header */}
                <div className="relative mb-8 overflow-hidden rounded-3xl p-8">
                    <div className="relative z-10 flex flex-col items-center justify-between gap-6 md:flex-row">
                        <div className="flex items-center gap-4">
                            {/* <div className="w-16 h-16 rounded-2xl bg-yellow-400 dark:bg-yellow-500 flex items-center justify-center shadow-xl group-hover:scale-110 transition-transform">
                                <GraduationCap className="w-8 h-8 text-white" />
                            </div> */}
                            <div>
                                <h1 className="mb-1 flex items-center gap-2 text-4xl font-black text-black md:text-5xl dark:text-white">
                                    Training Programs
                                </h1>
                                <p className="text-lg font-medium text-black/40 dark:text-white/40">Discover amazing coding and media courses</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <CoursesModal />
                            <CreatTraining coaches={coaches} />
                        </div>
                    </div>
                </div>

                {/* Stats Cards - Premium Design */}
                {trainings && trainings.length > 0 && <StatCard statsData={statsData} />}

                {/* Filters - Modern Design */}
                <div className="my-8 rounded-2xl border-2 border-yellow-200 bg-white p-6 shadow-xl dark:border-yellow-600/30 dark:bg-[#1c1c1c]">
                    <div className="mb-4 flex items-center gap-2">
                        <Target className="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                        <h3 className="text-lg font-bold text-dark dark:text-light">Filter Programs</h3>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                        {/* Coach Filter */}
                        <div className="group">
                            <label className="mb-2 flex items-center gap-2 text-sm font-bold text-yellow-600 dark:text-yellow-300">
                                <User size={16} className="text-yellow-600 dark:text-yellow-400" />
                                Coach
                            </label>
                            <select
                                value={selectedCoach}
                                onChange={handleCoachChange}
                                className="w-full cursor-pointer rounded-xl border-2 border-yellow-200 bg-yellow-50 px-4 py-3 text-gray-800 shadow-sm transition-all group-hover:shadow-md hover:border-yellow-400 focus:border-yellow-500 focus:ring-2 focus:ring-yellow-400/40 dark:border-yellow-600/30 dark:bg-[#222] dark:text-gray-200"
                            >
                                <option value="">All Coaches</option>
                                {coaches.map((c) => (
                                    <option key={c?.id} value={c?.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Track Filter */}
                        <div className="group">
                            <label className="mb-2 flex items-center gap-2 text-sm font-bold text-yellow-600 dark:text-yellow-300">
                                <BookOpen size={16} className="text-yellow-600 dark:text-yellow-400" />
                                Track
                            </label>
                            <select
                                value={selectedTrack}
                                onChange={handleTrackChange}
                                className="w-full cursor-pointer rounded-xl border-2 border-yellow-200 bg-yellow-50 px-4 py-3 text-gray-800 shadow-sm transition-all group-hover:shadow-md hover:border-yellow-400 focus:border-yellow-500 focus:ring-2 focus:ring-yellow-400/40 dark:border-yellow-600/30 dark:bg-[#222] dark:text-gray-200"
                            >
                                <option value="">All Tracks</option>
                                {tracks
                                    .filter((t) => t)
                                    .map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                            </select>
                        </div>

                        {/* Promo Filter */}
                        <div className="group">
                            <label className="mb-2 flex items-center gap-2 text-sm font-bold text-yellow-600 dark:text-yellow-300">
                                <TrendingUp size={16} className="text-yellow-600 dark:text-yellow-400" />
                                Promo
                            </label>
                            <select
                                value={selectedPromo}
                                onChange={handlePromoChange}
                                className="w-full cursor-pointer rounded-xl border-2 border-yellow-200 bg-yellow-50 px-4 py-3 text-gray-800 shadow-sm transition-all group-hover:shadow-md hover:border-yellow-400 focus:border-yellow-500 focus:ring-2 focus:ring-yellow-400/40 dark:border-yellow-600/30 dark:bg-[#222] dark:text-gray-200"
                            >
                                <option value="">All Promos</option>
                                {promos
                                    .filter((p) => p)
                                    .map((p) => (
                                        <option key={p} value={p}>
                                            {p}
                                        </option>
                                    ))}
                            </select>
                        </div>
                    </div>

                    {(selectedTrack || selectedPromo || selectedCoach) && (
                        <div className="mt-6 flex justify-center gap-4">
                            <button
                                onClick={() => {
                                    setSelectedCoach('');
                                    setSelectedTrack('');
                                    setSelectedPromo('');
                                    router.visit('/admin/training');
                                }}
                                className="flex cursor-pointer items-center gap-2 rounded-xl bg-yellow-500 px-6 py-3 text-sm font-bold text-white shadow-lg transition-all hover:scale-105 hover:bg-yellow-600 hover:shadow-xl"
                            >
                                Reset Filters
                            </button>
                        </div>
                    )}
                </div>

                {/* Training Cards - Premium Grid */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                    {trainings && trainings.length > 0 ? (
                        trainings.map((training) => (
                            <div
                                key={training?.id}
                                onMouseEnter={() => setHoveredCard(training?.id)}
                                onMouseLeave={() => setHoveredCard(null)}
                                className="group relative cursor-pointer overflow-hidden rounded-2xl border-2 border-yellow-200 bg-white shadow-lg transition-all duration-300 hover:border-yellow-400 hover:shadow-2xl dark:border-yellow-600/30 dark:bg-[#1c1c1c] dark:hover:border-yellow-400"
                                onClick={() => router.visit(`/trainings/${training?.id}`)}
                            >
                                {/* Image Container */}
                                <div className="relative h-48 overflow-hidden">
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

                                    {/* Gradient Overlay */}
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>

                                    {/* Category Badge */}
                                    {training.category && (
                                        <span className="absolute top-3 left-3 rounded-full bg-yellow-400 px-4 py-1.5 text-xs font-bold text-black shadow-lg">
                                            {training.category}
                                        </span>
                                    )}

                                    {/* Date-window badge (heuristic only — not the reminders gate) */}
                                    <div className="absolute top-3 right-3 flex flex-col items-end gap-1.5">
                                        {getTrainingStatus(training) === 'active' && (
                                            <span className="animate-pulse rounded-full bg-green-500 px-4 py-1.5 text-xs font-bold text-white shadow-lg">
                                                In session
                                            </span>
                                        )}
                                        {/* Stored is_active — staff-controlled gate for attendance reminders */}
                                        <span
                                            className={`rounded-full px-4 py-1.5 text-xs font-bold shadow-lg ${
                                                training.is_active
                                                    ? 'bg-alpha text-black'
                                                    : 'bg-black/70 text-white'
                                            }`}
                                        >
                                            {training.is_active ? 'Reminders on' : 'Reminders off'}
                                        </span>
                                    </div>
                                </div>

                                {/* Card Content */}
                                <div className="relative z-10 space-y-2 px-5">
                                    {training.name && (
                                        <h3 className="line-clamp-2 pt-3 text-xl font-bold text-dark transition-colors dark:text-light">
                                            {training.name}
                                        </h3>
                                    )}
                                    {/* Coach Info */}
                                    {training.coach?.name && (
                                        <div className="group/coach relative">
                                            <div className="flex items-center gap-3 rounded-xl border border-yellow-200 bg-yellow-50 p-3 transition-all duration-300 group-hover/coach:translate-x-20 group-hover/coach:opacity-0 dark:border-yellow-600/30 dark:bg-yellow-900/20">
                                                {/* <Avatar className="h-10 w-10 ring-2 ring-yellow-400">
                                                    <AvatarImage
                                                        className="h-full w-full object-cover"
                                                        src={training.coach.image}
                                                        alt={training.coach.name}
                                                    />
                                                    <AvatarFallback className="bg-yellow-400 text-black font-bold text-sm">
                                                        {getInitials(training.coach.name)}
                                                    </AvatarFallback>
                                                </Avatar> */}
                                                <Avatar
                                                    className="h-10 w-10"
                                                    image={training.coach.image}
                                                    name={training.coach.name}
                                                    onlineCircleClass="hidden"
                                                />
                                                <div>
                                                    <div className="text-xs font-semibold text-yellow-600 dark:text-yellow-400">Coach</div>
                                                    <div className="text-sm font-bold text-dark dark:text-light">{training.coach.name}</div>
                                                </div>
                                            </div>

                                            {/* Hover Preview Button */}
                                            <button
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setSelectedCoach(training.coach?.id);
                                                    setModalOpen(true);
                                                }}
                                                className="absolute inset-0 flex -translate-x-8 transform items-center justify-center rounded-xl border border-yellow-200 bg-yellow-50 text-sm font-semibold text-black opacity-0 transition-all duration-300 group-hover/coach:translate-x-0 group-hover/coach:opacity-100 dark:border-yellow-600/30 dark:bg-yellow-900/20 dark:text-yellow-400"
                                            >
                                                Preview
                                            </button>
                                        </div>
                                    )}

                                    {/* Footer Info */}
                                    <div className="flex items-center justify-between border-t border-yellow-200 dark:border-yellow-600/30">
                                        <div className="flex items-center gap-4">
                                            {training.start_time && (
                                                <div className="flex items-center gap-1.5 text-dark/70 dark:text-light/70">
                                                    <Clock size={16} className="text-yellow-600 dark:text-yellow-400" />
                                                    <span className="text-xs font-medium">{training.start_time}</span>
                                                </div>
                                            )}
                                            <div className="flex items-center gap-1.5 text-dark/70 dark:text-light/70">
                                                <User size={16} className="text-yellow-600 dark:text-yellow-400" />
                                                <span className="text-xs font-medium">{training.users_count ?? 0}</span>
                                            </div>
                                        </div>

                                        {/* Action Buttons */}
                                        <div className="flex items-center gap-1 py-2">
                                            <button
                                                onClick={(e) => e.stopPropagation()}
                                                className="rounded-lg transition-colors hover:bg-yellow-100 dark:hover:bg-yellow-900/30"
                                            >
                                                <UpdateTraining training={training} coaches={coaches} />
                                            </button>
                                            <button
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    openDeleteModal(training);
                                                }}
                                                className="cursor-pointer rounded-lg border border-transparent p-2 px-3 text-red-600 transition-colors hover:border-red-600 hover:bg-red-50 dark:hover:bg-red-900/30"
                                            >
                                                <Trash2 size={16} />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="col-span-full flex flex-col items-center justify-center px-4 py-20">
                            <div className="mb-6 flex h-24 w-24 items-center justify-center rounded-3xl bg-gradient-to-br from-yellow-400 to-yellow-600 shadow-2xl">
                                <Sparkles className="h-12 w-12 text-white" />
                            </div>
                            <h3 className="mb-3 text-3xl font-black text-gray-800 dark:text-gray-200">Ready to Create Something Amazing?</h3>
                            <p className="mb-8 max-w-md text-center text-gray-600 dark:text-gray-400">
                                Start your journey by adding your first coding or media training program.
                            </p>
                            <CreatTraining coaches={coaches} />
                        </div>
                    )}
                </div>

                {/* Delete Modal - Premium */}
                {deleteModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div className="w-full max-w-md rounded-2xl border-2 border-red-200 bg-white p-8 shadow-2xl duration-200 animate-in fade-in zoom-in dark:border-red-600/30 dark:bg-[#1c1c1c]">
                            <div className="mb-6 flex items-center gap-3">
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-red-100 dark:bg-red-900/30">
                                    <Trash2 className="h-6 w-6 text-red-600" />
                                </div>
                                <h3 className="text-2xl font-black text-dark dark:text-light">
                                    {deleteHasStudents ? 'Training has students' : 'Confirm Deletion'}
                                </h3>
                            </div>
                            {deleteHasStudents ? (
                                <div className="mb-8 space-y-3 text-dark/70 dark:text-light/70">
                                    <p>
                                        <span className="font-bold text-yellow-600 dark:text-yellow-400">
                                            "{trainingToDelete?.name}"
                                        </span>{' '}
                                        has{' '}
                                        <span className="font-bold text-red-600 dark:text-red-400">
                                            {deleteEnrolledCount} enrolled student{deleteEnrolledCount === 1 ? '' : 's'}
                                        </span>
                                        .
                                    </p>
                                    <p>
                                        Deleting it will unassign those students from this program. Attendance and related
                                        data for this training may also be removed. This action cannot be undone.
                                    </p>
                                </div>
                            ) : (
                                <p className="mb-8 text-dark/70 dark:text-light/70">
                                    Are you sure you want to delete{' '}
                                    <span className="font-bold text-yellow-600 dark:text-yellow-400">
                                        "{trainingToDelete?.name}"
                                    </span>
                                    ? This action cannot be undone.
                                </p>
                            )}
                            <div className="flex gap-3">
                                <button
                                    onClick={closeDeleteModal}
                                    className="flex-1 rounded-xl bg-gray-200 px-6 py-3 font-bold text-dark transition-colors hover:bg-gray-300 dark:bg-gray-700 dark:text-light dark:hover:bg-gray-600"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={confirmDelete}
                                    className="flex-1 rounded-xl bg-red-600 px-6 py-3 font-bold text-white shadow-lg transition-colors hover:bg-red-700 hover:shadow-xl"
                                >
                                    {deleteHasStudents ? 'Delete anyway' : 'Delete'}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
            {/* Coach Preview Modal */}
            {modalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-md duration-200 animate-in fade-in">
                    <div className="w-full max-w-2xl overflow-hidden rounded-3xl border border-yellow-200/50 bg-gradient-to-br from-white via-yellow-50/30 to-white shadow-2xl duration-300 animate-in zoom-in-95 dark:border-yellow-600/20 dark:from-[#1a1a1a] dark:via-yellow-950/10 dark:to-[#1a1a1a]">
                        <div className="h-1.5 bg-gradient-to-r from-yellow-400 via-yellow-500 to-yellow-600"></div>

                        <div className="p-8">
                            {/* Header */}
                            <div className="mb-8 flex items-start justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="rounded-xl bg-gradient-to-br from-yellow-400 to-yellow-500 p-2.5 shadow-lg">
                                        <User className="text-white" size={24} strokeWidth={2.5} />
                                    </div>
                                    <div>
                                        <h3 className="bg-gradient-to-r from-gray-900 to-gray-700 bg-clip-text text-2xl font-bold text-transparent dark:from-white dark:to-gray-300">
                                            Coach Profile
                                        </h3>
                                        <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">Detailed Overview</p>
                                    </div>
                                </div>
                                <button
                                    onClick={(e) => {
                                        (setSelectedCoach(''), closeModal());
                                    }}
                                    className="rounded-xl p-2 text-gray-400 transition-all duration-200 hover:rotate-90 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-950/30"
                                >
                                    <X size={24} strokeWidth={2} />
                                </button>
                            </div>

                            {/* Coach Info */}
                            {filteredCoach ? (
                                <div className="space-y-6">
                                    {/* Avatar & Basic Info */}
                                    <div className="flex items-start gap-6 rounded-2xl border border-yellow-200/50 bg-gradient-to-br from-yellow-50 to-amber-50/50 p-6 dark:border-yellow-600/20 dark:from-yellow-950/20 dark:to-amber-950/10">
                                        <div className="relative">
                                            {/* <Avatar className="w-24 h-24 ring-4 ring-yellow-200/50 dark:ring-yellow-600/30 shadow-xl">
                                                {filteredCoach.image ? (
                                                    <AvatarImage
                                                        src={`/storage/img/profile/${filteredCoach.image}`}
                                                        alt={filteredCoach.name}
                                                        className="object-cover rounded-2xl"
                                                    />
                                                ) : (
                                                    <AvatarFallback>
                                                        <div className="w-24 h-24 rounded-2xl bg-gradient-to-br from-yellow-400 to-yellow-500 flex items-center justify-center text-white text-2xl font-bold shadow-xl">
                                                            {getInitials(filteredCoach.name)}
                                                        </div>
                                                    </AvatarFallback>
                                                )}
                                            </Avatar> */}
                                            <Avatar
                                                className="h-24 w-24"
                                                image={filteredCoach?.image}
                                                name={filteredCoach?.name}
                                                lastActivity={filteredCoach?.last_login ?? filteredCoach?.last_online ?? filteredCoach?.last_activity ?? null}
                                                onlineCircleClass="hidden"
                                                edit={true}
                                            />
                                            <div className="absolute -right-2 -bottom-2 rounded-lg bg-green-500 p-1.5 shadow-lg">
                                                <CheckCircle2 size={16} className="text-white" />
                                            </div>
                                        </div>

                                        <div className="flex-1 space-y-2">
                                            <h4 className="text-2xl font-bold text-gray-900 dark:text-white">{filteredCoach.name}</h4>

                                            {filteredCoach.entreprise && (
                                                <div className="flex items-center gap-2 text-yellow-600 dark:text-yellow-400">
                                                    <Building2 size={16} />
                                                    <span className="text-sm font-semibold">{filteredCoach.entreprise}</span>
                                                </div>
                                            )}

                                            {coachTraining?.category && (
                                                <div className="inline-flex items-center gap-2 rounded-lg border border-yellow-300/50 bg-yellow-100 px-3 py-1.5 dark:border-yellow-600/30 dark:bg-yellow-900/30">
                                                    <Award size={14} className="text-yellow-600 dark:text-yellow-400" />
                                                    <span className="text-sm font-semibold text-yellow-700 dark:text-yellow-300">
                                                        {coachTraining.category}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {/* Experience */}
                                    {filteredCoach.experience && (
                                        <div className="rounded-2xl border border-yellow-200/50 bg-white p-5 dark:border-yellow-600/30 dark:bg-[#1f1f1f]">
                                            <h5 className="mb-2 text-lg font-semibold text-gray-800 dark:text-yellow-300">Experience</h5>
                                            <p className="text-sm leading-relaxed text-gray-700 dark:text-gray-300">{filteredCoach.experience}</p>
                                        </div>
                                    )}

                                    {/* Contact Info */}
                                    <div className="mt-4 flex flex-wrap gap-3">
                                        {filteredCoach.email && (
                                            <div className="flex items-center gap-2 rounded-full border border-yellow-300/50 bg-yellow-100 px-3 py-1.5 text-sm font-semibold text-yellow-800 dark:border-yellow-600/30 dark:bg-yellow-900/30 dark:text-yellow-300">
                                                Email: {filteredCoach.email}
                                            </div>
                                        )}
                                        {filteredCoach.phone && (
                                            <div className="flex items-center gap-2 rounded-full border border-yellow-300/50 bg-yellow-100 px-3 py-1.5 text-sm font-semibold text-yellow-800 dark:border-yellow-600/30 dark:bg-yellow-900/30 dark:text-yellow-300">
                                                Phone: {filteredCoach.phone}
                                            </div>
                                        )}
                                        {filteredCoach.status && (
                                            <div className="flex items-center gap-2 rounded-full border border-yellow-300/50 bg-yellow-100 px-3 py-1.5 text-sm font-semibold text-yellow-800 dark:border-yellow-600/30 dark:bg-yellow-900/30 dark:text-yellow-300">
                                                Status: {filteredCoach.status}
                                            </div>
                                        )}
                                    </div>

                                    {/* Stats Grid */}
                                    {(() => {
                                        const now = new Date();
                                        const completedCount = trainings.filter((t) => {
                                            if (!t.coach || !t.start_time) return false;

                                            const startDate = new Date(t.start_time);
                                            const durationMonths = t.duration_months || 1;
                                            const diffMonths =
                                                (now.getFullYear() - startDate.getFullYear()) * 12 + (now.getMonth() - startDate.getMonth());

                                            return t.coach?.id === selectedCoach && diffMonths >= durationMonths;
                                        }).length;
                                        const trainingsActiv = trainings.filter(
                                            (t) => getTrainingStatus(t) === 'active' && t.coach?.id == selectedCoach,
                                        ).length;
                                        const trainingsTotal = trainings.filter((t) => t.coach && t.coach?.id == selectedCoach).length;
                                        const pendingCount =
                                            trainings.filter((t) => t.coach && t.coach?.id === selectedCoach).length -
                                            completedCount -
                                            trainingsActiv;
                                        const coachTrainings = trainings.filter((t) => t.coach && t.coach.id === selectedCoach);

                                        const totalStudents = coachTrainings.reduce((sum, training) => {
                                            if (Array.isArray(training.users)) return sum + training.users.length;
                                            if (typeof training.users_count === 'number') return sum + training.users_count;
                                            return sum;
                                        }, 0);

                                        return (
                                            <>
                                                <h2 className="mb-4 text-lg font-bold text-dark dark:text-light">Trainings Statistics</h2>
                                                <div className="grid grid-cols-4 gap-6">
                                                    {[
                                                        { label: 'Students', value: totalStudents },
                                                        { label: 'Trainings', value: trainingsTotal },
                                                        // { label: 'Pending', value: pendingCount },
                                                        { label: 'Active', value: trainingsActiv },
                                                        { label: 'Done', value: completedCount },
                                                    ].map((stat, i) => (
                                                        <div
                                                            key={i}
                                                            className="group relative rounded-xl border border-yellow-300 bg-white p-5 transition-all hover:-translate-y-1 hover:border-yellow-400 hover:shadow-lg dark:border-yellow-600 dark:bg-[#1f1f1f] dark:hover:border-yellow-500"
                                                        >
                                                            <div className="absolute inset-0 rounded-xl bg-gradient-to-br from-yellow-400/10 to-transparent opacity-0 transition-opacity group-hover:opacity-100"></div>
                                                            <div className="relative text-center">
                                                                <p className="bg-gradient-to-br from-yellow-500 to-yellow-600 bg-clip-text text-4xl font-extrabold text-transparent">
                                                                    {stat.value}
                                                                </p>
                                                                <p className="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">
                                                                    {stat.label}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </>
                                        );
                                    })()}

                                    {/* Buttons */}
                                    <div className="flex gap-3 pt-4">
                                        <button
                                            onClick={() => router.visit(`/admin/users/${filteredCoach?.id}`)}
                                            className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-yellow-400 to-yellow-500 px-6 py-3.5 font-bold text-white shadow-lg transition-all hover:-translate-y-0.5 hover:from-yellow-500 hover:to-yellow-600 hover:shadow-xl active:translate-y-0"
                                        >
                                            <Calendar size={20} />
                                            View profile
                                        </button>
                                        <button
                                            onClick={(e) => {
                                                (setSelectedCoach(''), closeModal());
                                            }}
                                            className="rounded-xl bg-gray-100 px-6 py-3.5 font-semibold text-gray-700 transition-all hover:-translate-y-0.5 hover:bg-gray-200 active:translate-y-0 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                        >
                                            Close
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-center text-gray-600 dark:text-gray-400">No coach selected.</p>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
