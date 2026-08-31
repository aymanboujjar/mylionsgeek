import { Award, User, UserCheck, Users, XCircle } from 'lucide-react';
import { useMemo } from 'react';
import { PROGRAM_STATUS } from '@/components/helpers/userDemographics';

export default function TrainingStatsCards({ students = [] }) {
    const stats = useMemo(() => {
        const total = students.length;
        const male = students.filter((s) => s.gender === 'male').length;
        const female = students.filter((s) => s.gender === 'female').length;
        const certified = students.filter((s) => s.program_status === PROGRAM_STATUS.LAUREATE).length;
        const notCertified = total - certified;

        return { total, male, female, certified, notCertified };
    }, [students]);

    const cards = [
        {
            key: 'total',
            label: 'Total Students',
            value: stats.total,
            icon: Users,
        },
        {
            key: 'male',
            label: 'Male',
            value: stats.male,
            icon: User,
        },
        {
            key: 'female',
            label: 'Female',
            value: stats.female,
            icon: User,
        },
        {
            key: 'certified',
            label: 'Certified',
            value: stats.certified,
            icon: Award,
        },
        {
            key: 'notCertified',
            label: 'Not Certified',
            value: stats.notCertified,
            icon: XCircle,
        },
    ];

    return (
        <div className="mb-8">
            <div className="mb-4 flex items-center gap-2">
                <UserCheck className="h-5 w-5 text-alpha" />
                <h2 className="text-xl font-bold text-dark dark:text-light">Student Statistics</h2>
            </div>

            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                {cards.map(({ key, label, value, icon: Icon }) => (
                    <div
                        key={key}
                        className="flex items-center gap-3 rounded-2xl border border-alpha/20 bg-light p-4 text-dark transition-colors dark:bg-dark dark:text-light"
                    >
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-alpha/15">
                            <Icon className="h-6 w-6 text-alpha" />
                        </div>
                        <div className="min-w-0">
                            <p className="text-2xl font-bold tabular-nums text-dark dark:text-light">{value}</p>
                            <p className="truncate text-xs font-semibold text-dark/60 dark:text-light/60">{label}</p>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
