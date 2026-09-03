import { Camera } from 'lucide-react';
import { useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

export default function FaceEnrollmentCard({ user, canEnrollFace, faceEnrollment }) {
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    const [enrolledAt, setEnrolledAt] = useState(faceEnrollment?.enrolled_at ?? null);

    if (!canEnrollFace || !user?.id) {
        return null;
    }

    const handleSubmit = async (event) => {
        event.preventDefault();
        const file = event.currentTarget.elements.namedItem('reference_photo')?.files?.[0];
        if (!file) {
            setError('Choose a JPEG, PNG, or WebP photo of the student.');
            return;
        }

        const formData = new FormData();
        formData.append('reference_photo', file);

        setSubmitting(true);
        setError(null);
        setSuccess(null);

        try {
            const response = await fetch(`/admin/users/${user.id}/face-enrollment`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: formData,
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                setError(data.message || 'Unable to enroll this photo.');
                return;
            }

            setEnrolledAt(data.enrolled_at ?? new Date().toISOString());
            setSuccess('Face enrollment saved. This is identity matching, not liveness detection.');
            event.currentTarget.reset();
        } catch {
            setError('Network error. Please try again.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-950/40">
            <div className="border-b border-yellow-400 p-6 dark:border-yellow-500">
                <h3 className="text-lg font-bold text-neutral-800 dark:text-neutral-100">Face enrollment</h3>
            </div>
            <div className="space-y-4 p-6">
                <p className="text-sm text-neutral-600 dark:text-neutral-300">
                    Staff-only private reference used for attendance check-in. Do not use the public profile photo.
                </p>
                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                    {enrolledAt
                        ? `Enrolled ${new Date(enrolledAt).toLocaleString()}`
                        : 'No face enrollment on file.'}
                </p>
                <form className="space-y-3" onSubmit={handleSubmit}>
                    <input
                        name="reference_photo"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="block w-full text-sm text-neutral-700 dark:text-neutral-200"
                    />
                    <button
                        type="submit"
                        disabled={submitting}
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-yellow-400 px-4 text-sm font-semibold text-neutral-900 disabled:opacity-60"
                    >
                        <Camera className="h-4 w-4" />
                        {submitting ? 'Enrolling…' : enrolledAt ? 'Replace enrollment' : 'Enroll face'}
                    </button>
                </form>
                {error && <p className="text-sm text-red-700 dark:text-red-400">{error}</p>}
                {success && <p className="text-sm text-green-700 dark:text-green-400">{success}</p>}
            </div>
        </div>
    );
}
