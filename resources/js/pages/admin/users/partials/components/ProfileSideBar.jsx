import { IdCard, Mail, Monitor, Phone } from 'lucide-react';
import FaceEnrollmentCard from './FaceEnrollmentCard';

const ProfileSidebar = ({ user, assignedComputer, canEnrollFace = false, faceEnrollment = null }) => {
    return (
        <div className="space-y-6">
            {/* About Card */}
            <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-950/40">
                <div className="border-b border-yellow-400 p-6 dark:border-yellow-500">
                    <h3 className="text-lg font-bold text-neutral-800 dark:text-neutral-100">About</h3>
                </div>
                <div className="space-y-4 p-6">
                    <p className="text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">
                        {user?.about && user.about.trim() !== '' ? user.about : 'No about information available.'}
                    </p>
                    <div className="space-y-3 border-t border-neutral-100 pt-4 dark:border-neutral-800">
                        <div className="flex items-center gap-3 text-sm">
                            <Phone className="h-4 w-4 text-yellow-500" />
                            <span className="text-neutral-700 dark:text-neutral-200">
                                {user?.phone && user.phone.trim() !== '' ? user.phone : 'No phone number provided'}
                            </span>
                        </div>
                        <div className="flex items-center gap-3 text-sm">
                            <IdCard className="h-4 w-4 text-yellow-500" />
                            <span className="text-neutral-700 dark:text-neutral-200">
                                {user?.cin && user.cin.trim() !== '' ? user.cin : 'No CIN available'}
                            </span>
                        </div>
                        <div className="flex items-center gap-3 text-sm">
                            <Mail className="h-4 w-4 text-yellow-500" />
                            <span className="text-neutral-700 dark:text-neutral-200">
                                {user?.email && user.email.trim() !== '' ? user.email : 'No email available'}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {assignedComputer && (
                <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-950/40">
                    <div className="border-b border-yellow-400 p-6 dark:border-yellow-500">
                        <h3 className="text-lg font-bold text-neutral-800 dark:text-neutral-100">Assigned Computer</h3>
                    </div>
                    <div className="space-y-4 p-6">
                        <div className="space-y-2">
                            <div className="flex items-start gap-4">
                                <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-yellow-100 dark:bg-yellow-800/40">
                                    <Monitor className="h-6 w-6 text-yellow-500" />
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{assignedComputer.reference}</p>
                                    <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-300">
                                        {assignedComputer.mark || '—'} • {assignedComputer.cpu || '—'} • {assignedComputer.gpu || '—'}
                                    </p>
                                </div>
                            </div>
                            <div className="space-y-1 border-t border-neutral-100 pt-4 text-xs text-neutral-600 dark:border-neutral-800 dark:text-neutral-300">
                                <p>
                                    <span className="font-medium">Assigned:</span> {assignedComputer.start || '—'}
                                </p>
                                {assignedComputer.end && (
                                    <p>
                                        <span className="font-medium">Returned:</span> {assignedComputer.end}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            <FaceEnrollmentCard user={user} canEnrollFace={canEnrollFace} faceEnrollment={faceEnrollment} />
        </div>
    );
};

export default ProfileSidebar;
