import { Avatar } from '@/components/ui/avatar';
import { router } from '@inertiajs/react';
import { Award, Calendar, Camera, Edit3, Github, Globe, Linkedin, Twitter } from 'lucide-react';
import { useState } from 'react';
import { ADMIN_USER_STATUSES } from '@/components/helpers/userStatuses';
import { genderLabel, programStatusLabel } from '@/components/helpers/userDemographics';
import EditUserModal from '../EditModal';
import DownloadCertificateDialog, { resolveCertificateTrack } from './DownloadCertificateDialog';

const ProfileHeader = ({ user, trainings, roles }) => {
    const [open, setOpen] = useState(false);
    const [certDialogOpen, setCertDialogOpen] = useState(false);
    const hasCertificateTrack = resolveCertificateTrack(user?.field) !== null;
    const onlineColor = user?.is_online ? 'bg-green-500' : 'bg-neutral-500';
    const lastLoginRaw = user?.last_login ?? user?.last_online ?? user?.last_activity ?? null;
    const lastLogin = lastLoginRaw ? new Date(lastLoginRaw).toLocaleString() : 'No last login available';
    const socials = user?.socials || {};

    const changeCover = (event, userId) => {
        const file = event.target.files[0]; // Get the first file
        if (!file) return; // If no file is selected, don't proceed

        const formData = new FormData();
        formData.append('cover', file); // Append the selected file to FormData

        // Send the POST request with the form data
        router.post(`/changeCover/${userId}`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data', // Make sure the request is sent as multipart
            },
            onSuccess: () => {
                //('Cover changed successfully');
            },
            onError: (error) => {
                //('Cover not changed', error);
            },
        });
    };

    return (
        <>
            <div className="bg-gradient-to-b from-neutral-50 to-white dark:from-neutral-950 dark:to-neutral-900">
                <div className="mx-auto max-w-6xl">
                    {/* Cover Photo with Overlay Pattern */}
                    <div className="relative h-64 overflow-hidden md:h-80">
                        <img src={`/storage/${user.cover}`} alt="Cover" className="h-full w-full object-cover" />
                        {/* Mesh gradient overlay */}
                        <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-black/20 to-transparent" />

                        {/* Change Cover Icon */}
                        <label
                            className="absolute top-5 right-5 flex h-12 w-12 items-center justify-center rounded-full bg-dark_gray"
                            aria-label="Change cover image"
                        >
                            <Camera size={24} className="cursor-pointer text-white" />
                            <input
                                type="file"
                                accept="image/*"
                                className="absolute inset-0 cursor-pointer opacity-0"
                                onChange={(e) => changeCover(e, user.id)}
                            />
                        </label>
                    </div>

                    {/* Profile Content */}
                    <div className="px-6 pb-8 md:px-8">
                        <div className="relative -mt-20 md:-mt-10">
                            <div className="flex flex-col gap-6 md:flex-row md:gap-8">
                                {/* Profile Picture */}
                                <div className="relative h-36 w-36 flex-shrink-0 overflow-hidden rounded-full border-5 border-dark_gray bg-white shadow-2xl md:h-44 md:w-44 dark:bg-neutral-900">
                                    <Avatar
                                        className="h-36 w-36 overflow-hidden rounded-full ring-4 ring-alpha/20 md:h-44 md:w-44"
                                        image={user.image}
                                        name={user?.name}
                                        lastActivity={lastLoginRaw}
                                        onlineCircleClass="hidden" // hide the built-in circle since we’re using a custom one
                                    />

                                    {/* Custom status indicator with pulse */}
                                    {/* <span className="absolute bottom-2 right-2 flex items-center justify-center z-20">
                    <span
                      className={`absolute w-5 h-5 ${onlineColor} rounded-full animate-ping opacity-75`}
                    />
                    <span
                      className={`relative w-5 h-5 ${onlineColor} rounded-full ring-4 ring-white dark:ring-neutral-900`}
                    />
                  </span> */}
                                </div>

                                {/* User Info */}
                                <div className="flex flex-1 flex-col justify-end space-y-4">
                                    {/* Name and Training */}
                                    <div>
                                        <h1 className="mb-2 text-3xl font-bold tracking-tight text-neutral-900 md:text-4xl dark:text-white">
                                            {user.name || '—'}
                                        </h1>
                                        <div className="flex flex-wrap items-center gap-3">
                                            <span className="text-base font-medium text-neutral-700 md:text-lg dark:text-neutral-300">
                                                {user.formation_name || 'No training assigned'}
                                            </span>
                                            <span className="h-1.5 w-1.5 rounded-full bg-neutral-300 dark:bg-neutral-600" />
                                            <span className="inline-flex items-center rounded-full bg-alpha px-3 py-1 text-xs font-semibold text-black">
                                                {user.status || 'No status'}
                                            </span>
                                            {genderLabel(user.gender) && (
                                                <span className="inline-flex items-center rounded-full border border-beta/20 bg-beta/5 px-3 py-1 text-xs font-semibold text-beta dark:border-light/20 dark:bg-light/5 dark:text-light">
                                                    {genderLabel(user.gender)}
                                                </span>
                                            )}
                                            {programStatusLabel(user.program_status) && (
                                                <span className="inline-flex items-center rounded-full border border-beta/20 bg-beta/5 px-3 py-1 text-xs font-semibold text-beta dark:border-light/20 dark:bg-light/5 dark:text-light">
                                                    {programStatusLabel(user.program_status)}
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {/* Bottom row: Last seen & Actions */}
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                        {/* Last online */}
                                        <div className="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-400">
                                            <Calendar className="h-4 w-4" />
                                            <span>Last login: {lastLogin}</span>
                                        </div>

                                        {/* Social links & Edit button */}
                                        <div className="flex items-center gap-3">
                                            {/* Social icons */}
                                            <div className="flex items-center gap-2">
                                                {socials.github && (
                                                    <a
                                                        href={socials.github}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-100 text-neutral-700 transition-all hover:scale-110 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                                    >
                                                        <Github className="h-4 w-4" />
                                                    </a>
                                                )}
                                                {socials.linkedin && (
                                                    <a
                                                        href={socials.linkedin}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-100 text-neutral-700 transition-all hover:scale-110 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                                    >
                                                        <Linkedin className="h-4 w-4" />
                                                    </a>
                                                )}
                                                {socials.twitter && (
                                                    <a
                                                        href={socials.twitter}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-100 text-neutral-700 transition-all hover:scale-110 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                                    >
                                                        <Twitter className="h-4 w-4" />
                                                    </a>
                                                )}
                                                {socials.website && (
                                                    <a
                                                        href={socials.website}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-100 text-neutral-700 transition-all hover:scale-110 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                                    >
                                                        <Globe className="h-4 w-4" />
                                                    </a>
                                                )}
                                            </div>

                                            {/* Divider */}
                                            {(socials.github || socials.linkedin || socials.twitter || socials.website) && (
                                                <div className="h-6 w-px bg-neutral-200 dark:bg-neutral-700" />
                                            )}

                                            {/* Certificate download button — visible only for students with a recognised field */}
                                            {hasCertificateTrack && (
                                                <button
                                                    type="button"
                                                    onClick={() => setCertDialogOpen(true)}
                                                    className="flex items-center gap-2 rounded-lg border border-alpha/40 px-4 py-2 text-sm font-semibold text-dark shadow transition-all hover:border-alpha hover:bg-alpha/10 dark:text-light"
                                                >
                                                    <Award className="h-4 w-4 text-alpha" />
                                                    Certificat
                                                </button>
                                            )}

                                            {/* Edit button */}
                                            <button
                                                onClick={() => setOpen(true)}
                                                className="flex items-center gap-2 rounded-lg bg-alpha px-4 py-2 text-sm font-semibold text-black shadow-lg transition-all hover:shadow-xl"
                                            >
                                                <Edit3 className="h-4 w-4" />
                                                Edit Profile
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <EditUserModal open={!!open} onClose={() => setOpen(false)} editedUser={user} roles={roles} status={ADMIN_USER_STATUSES} trainings={trainings} />
            {hasCertificateTrack && (
                <DownloadCertificateDialog open={certDialogOpen} onOpenChange={setCertDialogOpen} user={user} />
            )}
        </>
    );
};

export default ProfileHeader;
