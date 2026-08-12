import AttendanceCheckInPanel from '@/components/AttendanceCheckInPanel';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { WifiOff } from 'lucide-react';

export default function StudentAttendanceIndex({ formation, attendance_day, slot_status }) {
    return (
        <AppLayout>
            <Head title="Attendance" />

            <div className="mx-auto max-w-lg px-4 py-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold text-dark dark:text-light">Attendance</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {formation?.name ? formation.name : 'No formation assigned'}
                        {attendance_day ? ` · ${attendance_day}` : ''}
                    </p>
                </div>

                {!formation && (
                    <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                        <WifiOff className="mt-0.5 h-5 w-5 shrink-0" />
                        <p className="text-sm">You are not enrolled in a formation. Contact your coach if this is unexpected.</p>
                    </div>
                )}

                <AttendanceCheckInPanel formation={formation} slot_status={slot_status} attendance_day={attendance_day} />
            </div>
        </AppLayout>
    );
}
