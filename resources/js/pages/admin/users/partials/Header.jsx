import { Button } from '@/components/ui/button';
import { Camera, Clipboard, Code, Copy, Plus, Users2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import StatsCard from '../../../../components/StatCard';
import Rolegard from '@/components/rolegard';
import { usePage } from '@inertiajs/react';
import AddUserDialog from './components/AddUserDialog';
import ExportStudentsDialog from './components/ExportStudentsDialog';

const Header = ({ trainings, filteredUsers }) => {
    const { auth } = usePage().props;
    const [isAddUserOpen, setIsAddUserOpen] = useState(false);
    const [isExportOpen, setIsExportOpen] = useState(false);
    const [copy, setCopy] = useState(true);

    const userRoles = Array.isArray(auth?.user?.role) ? auth.user.role : [auth?.user?.role].filter(Boolean);
    const canExportSensitiveFields = userRoles.includes('admin') || userRoles.includes('super_admin');
    const hiddenExportFields = canExportSensitiveFields ? [] : ['cin', 'phone', 'role'];

    const emailsToCopy = useMemo(() => {
        return filteredUsers
            ?.map((u) => u?.email)
            .filter(Boolean)
            .join(', ');
    }, [filteredUsers]);

    const handleCopyEmails = () => {
        if (!emailsToCopy) return;
        navigator.clipboard.writeText(emailsToCopy).then(() => {
            setCopy(false);
            setTimeout(() => setCopy(true), 1500);
        });
    };

    const mediaTraining = trainings.filter(
        (t) => t.name.toLowerCase().includes('création') || t.name.toLowerCase().includes('media') || t.name.toLowerCase().includes('creator'),
    );

    const codingTraining = trainings.filter(
        (t) =>
            t.name.toLowerCase().includes('coding') ||
            t.name.toLowerCase().includes('developpement') ||
            t.name.toLowerCase().includes('developement'),
    );

    const mediaStudents = filteredUsers.filter((u) => mediaTraining.map((t) => t.id).includes(u.formation_id));

    const codingStudents = filteredUsers.filter((u) => codingTraining.map((t) => t.id).includes(u.formation_id));

    const staticCardData = [
        { title: 'Total Users', value: filteredUsers.length, icon: Users2 },
        { title: 'Total Media Students', value: mediaStudents.length, icon: Camera },
        { title: 'Total Coding Students', value: codingStudents.length, icon: Code },
    ];

    return (
        <>
            <StatsCard statsData={staticCardData} />

            <div className="flex items-center justify-between">
                <div></div>

                <div className="flex items-center gap-3">
                    <Rolegard authorized={['admin', 'super_admin', 'coach']}>
                        {/* Copy Emails */}
                        <Button
                            onClick={handleCopyEmails}
                            className="cursor-pointer border bg-light text-dark hover:bg-transparent hover:text-[var(--color-alpha)]"
                        >
                            {copy ? <Copy className="h-4 w-4" /> : <Clipboard className="h-4 w-4" />}
                            {copy ? 'Copy Emails' : 'Copied!'}
                        </Button>

                        {/* Export Dialog */}
                        <ExportStudentsDialog open={isExportOpen} setOpen={setIsExportOpen} hiddenFields={hiddenExportFields} />

                        <Button
                            className="cursor-pointer border border-[var(--color-alpha)] bg-[var(--color-alpha)] px-7 py-4 text-black hover:bg-transparent hover:text-[var(--color-alpha)]"
                            onClick={() => setIsExportOpen(true)}
                        >
                            Export Students
                        </Button>
                    </Rolegard>

                    <Rolegard authorized={['admin', 'super_admin']}>
                        {/* Add User Dialog */}
                        <AddUserDialog open={isAddUserOpen} setOpen={setIsAddUserOpen} trainings={trainings} />

                        <Button
                            className="flex cursor-pointer gap-2 border border-[var(--color-alpha)] bg-[var(--color-alpha)] px-7 py-4 text-black hover:bg-transparent hover:text-[var(--color-alpha)]"
                            onClick={() => setIsAddUserOpen(true)}
                        >
                            <Plus /> Add User
                        </Button>
                    </Rolegard>
                </div>
            </div>
        </>
    );
};

export default Header;
