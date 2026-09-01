import Banner from '@/components/banner';
import { matchesProgramStatusFilter } from '@/components/helpers/userDemographics';
import { ADMIN_USER_STATUSES } from '@/components/helpers/userStatuses';
import AppLayout from '@/layouts/app-layout';
import { useMemo, useState } from 'react';
import students from '../../../../../public/assets/images/banner/students.png';
import FilterPart from './partials/FilterPart';
import Header from './partials/Header';
import UsersTable from './partials/UsersTable';

const defaultFilters = {
    search: '',
    training: null,
    promo: null,
    role: '',
    status: '',
    date: '',
    field: null,
    gender: '',
    has_handicap: '',
    program_status: 'all',
};

const Users = ({ users, trainings }) => {
    const [filters, setFilters] = useState(defaultFilters);

    // Flatten roles array for all users
    const allRoles = [...new Set(users.flatMap((user) => (Array.isArray(user.role) ? user.role : [user.role])))];

    const normalizeValue = (value) => {
        if (typeof value === 'string') return value.trim();
        if (value === null || value === undefined) return '';
        return String(value).trim();
    };

    const resolveUserPromo = (user) => {
        const direct = normalizeValue(user.promo);
        if (direct) {
            return direct.toLowerCase();
        }

        const formation = trainings.find((training) => training.id === user.formation_id);
        const fromFormation = normalizeValue(formation?.promo);

        return fromFormation ? fromFormation.toLowerCase() : '';
    };

    const allPromo = useMemo(() => {
        const fromUsers = users.map((user) => normalizeValue(user.promo)).filter(Boolean);
        const fromTrainings = trainings.map((training) => normalizeValue(training.promo)).filter(Boolean);

        return [...new Set([...fromUsers, ...fromTrainings])].sort((a, b) => a.localeCompare(b));
    }, [users, trainings]);

    const allFields = useMemo(() => {
        return [...new Set(users.map((user) => normalizeValue(user.field)).filter(Boolean))].sort((a, b) => a.localeCompare(b));
    }, [users]);

    const filteredUsers = useMemo(() => {
        const search = filters.search.toLowerCase();
        const roleFilter = filters.role?.toLowerCase() ?? '';
        const statusFilter = filters.status?.toLowerCase() ?? '';
        const promoFilter = filters.promo ? normalizeValue(filters.promo).toLowerCase() : null;
        const fieldFilter = filters.field ? normalizeValue(filters.field).toLowerCase() : null;
        const list = users
            .filter((user) => {
                if (!search) return true;
                return (user.name || '').toLowerCase().includes(search);
            })
            .filter((user) => (filters.training === null ? true : user.formation_id === filters.training))
            .filter((user) => {
                if (!promoFilter) return true;

                return resolveUserPromo(user) === promoFilter;
            })
            .filter((user) => {
                if (!fieldFilter) return true;
                const userField = normalizeValue(user.field).toLowerCase();
                return userField === fieldFilter;
            })
            .filter((user) => {
                if (!filters.role) return true;
                const roles = Array.isArray(user.role) ? user.role : [user.role];
                return roles.some((r) => r?.toLowerCase().includes(roleFilter));
            })
            .filter((user) => {
                if (!statusFilter) return true;
                return (user.status || '').toLowerCase() === statusFilter;
            })
            .filter((user) => {
                if (!filters.gender) return true;
                return (user.gender || '') === filters.gender;
            })
            .filter((user) => {
                if (filters.has_handicap === '' || filters.has_handicap === null || filters.has_handicap === undefined) {
                    return true;
                }
                const wantsHandicap = String(filters.has_handicap) === '1';
                const userHasHandicap = user.has_handicap === true || user.has_handicap === 1 || user.has_handicap === '1';
                return wantsHandicap ? userHasHandicap : user.has_handicap === false || user.has_handicap === 0 || user.has_handicap === '0';
            })
            .filter((user) => matchesProgramStatusFilter(user.program_status, filters.program_status))
            .sort((a, b) => {
                if (filters.date === 'oldest') {
                    return new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
                }
                return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
            });
        return list;
    }, [users, filters, trainings]);

    const allStatus = ADMIN_USER_STATUSES;

    return (
        <AppLayout>
            <div className="flex flex-col gap-10 p-6">
                <Banner illustration={students} />
                <Header trainings={trainings} filteredUsers={filteredUsers} />
                <FilterPart
                    filters={filters}
                    setFilters={setFilters}
                    trainings={trainings}
                    status={allStatus}
                    roles={allRoles}
                    allPromo={allPromo}
                    fields={allFields}
                    filteredUsers={filteredUsers}
                    initialFilters={defaultFilters}
                />
                <UsersTable users={filteredUsers} filters={filters} roles={allRoles} trainings={trainings} status={allStatus} />
            </div>
        </AppLayout>
    );
};

export default Users;
