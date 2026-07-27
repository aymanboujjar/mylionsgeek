import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { GENDER_OPTIONS } from '@/components/helpers/userDemographics';
import { useEffect, useMemo, useState } from 'react';

const EXPORT_FIELD_LABELS = {
    name: 'Name',
    email: 'Email',
    cin: 'CIN',
    phone: 'Phone',
    gender: 'Gender',
    has_handicap: 'Handicap',
    formation: 'Training',
    access_studio: 'Access Studio',
    access_cowork: 'Access Cowork',
    access_scan: 'Access Scan',
    role: 'Role',
    status: 'Status',
};

const DEFAULT_EXPORT_FIELDS = {
    name: true,
    email: true,
    cin: true,
    phone: false,
    gender: false,
    has_handicap: false,
    formation: true,
    access_studio: false,
    access_cowork: false,
    role: false,
    status: false,
};

const DEFAULT_EXPORT_FILTERS = {
    gender: '',
    role: '',
    status: '',
    has_handicap: '',
    formation_id: '',
};

const buildExportFields = (hiddenFields = []) => {
    const fields = { ...DEFAULT_EXPORT_FIELDS };

    hiddenFields.forEach((field) => {
        if (field in fields) {
            fields[field] = false;
        }
    });

    return fields;
};

const displayRole = (role) => (role === 'studio_responsable' ? 'Responsable Studio' : role);

export default function ExportStudentsDialog({
    open,
    setOpen,
    hiddenFields = [],
    roles = [],
    statuses = [],
    trainings = [],
}) {
    const [exportFields, setExportFields] = useState(() => buildExportFields(hiddenFields));
    const [exportFilters, setExportFilters] = useState(DEFAULT_EXPORT_FILTERS);

    useEffect(() => {
        if (open) {
            setExportFields(buildExportFields(hiddenFields));
            setExportFilters(DEFAULT_EXPORT_FILTERS);
        }
    }, [open, hiddenFields]);

    const visibleFieldKeys = useMemo(
        () => Object.keys(exportFields).filter((key) => !hiddenFields.includes(key)),
        [exportFields, hiddenFields],
    );

    const exportQuery = useMemo(() => {
        const selected = Object.entries(exportFields)
            .filter(([key, value]) => value && !hiddenFields.includes(key))
            .map(([key]) => key)
            .join(',');

        return selected.length ? selected : 'name,email';
    }, [exportFields, hiddenFields]);

    const updateFilter = (field, value) => {
        setExportFilters((prev) => ({ ...prev, [field]: value }));
    };

    const triggerExport = () => {
        const params = new URLSearchParams();
        params.set('fields', exportQuery);

        if (exportFilters.gender) {
            params.set('gender', exportFilters.gender);
        }
        if (exportFilters.role) {
            params.set('role', exportFilters.role);
        }
        if (exportFilters.status) {
            params.set('status', exportFilters.status);
        }
        if (exportFilters.has_handicap !== '') {
            params.set('has_handicap', exportFilters.has_handicap);
        }
        if (exportFilters.formation_id) {
            params.set('formation_id', exportFilters.formation_id);
        }

        window.open(`/admin/users/export?${params.toString()}`, '_blank');
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Export Students</DialogTitle>
                    <DialogDescription>Choose which columns to include and optionally filter which users are exported.</DialogDescription>
                </DialogHeader>

                <div className="space-y-6 py-2">
                    <section>
                        <h3 className="mb-3 text-sm font-semibold text-beta dark:text-light">Columns</h3>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            {visibleFieldKeys.map((key) => (
                                <div key={key} className="flex items-center space-x-3">
                                    <Checkbox
                                        id={`export-field-${key}`}
                                        checked={exportFields[key]}
                                        onCheckedChange={(checked) =>
                                            setExportFields((prev) => ({
                                                ...prev,
                                                [key]: !!checked,
                                            }))
                                        }
                                    />
                                    <label htmlFor={`export-field-${key}`} className="text-sm">
                                        {EXPORT_FIELD_LABELS[key] ?? key}
                                    </label>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="border-t border-beta/10 pt-4 dark:border-light/10">
                        <h3 className="mb-1 text-sm font-semibold text-beta dark:text-light">Filters</h3>
                        <p className="mb-3 text-xs text-beta/60 dark:text-light/60">Leave as &quot;All&quot; to export every user. Filters only affect rows, not columns.</p>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Gender</Label>
                                <Select
                                    value={exportFilters.gender || 'all'}
                                    onValueChange={(value) => updateFilter('gender', value === 'all' ? '' : value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All genders" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        {GENDER_OPTIONS.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2">
                                <Label>Role</Label>
                                <Select
                                    value={exportFilters.role || 'all'}
                                    onValueChange={(value) => updateFilter('role', value === 'all' ? '' : value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All roles" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        {roles.filter(Boolean).map((role) => (
                                            <SelectItem key={role} value={role}>
                                                {displayRole(role)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2">
                                <Label>Status</Label>
                                <Select
                                    value={exportFilters.status || 'all'}
                                    onValueChange={(value) => updateFilter('status', value === 'all' ? '' : value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        {statuses.map((status) => (
                                            <SelectItem key={status} value={status}>
                                                {status}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2">
                                <Label>Handicap</Label>
                                <Select
                                    value={exportFilters.has_handicap === '' ? 'all' : exportFilters.has_handicap}
                                    onValueChange={(value) => updateFilter('has_handicap', value === 'all' ? '' : value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="1">Oui</SelectItem>
                                        <SelectItem value="0">Non</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2 sm:col-span-2">
                                <Label>Training</Label>
                                <Select
                                    value={exportFilters.formation_id ? String(exportFilters.formation_id) : 'all'}
                                    onValueChange={(value) =>
                                        updateFilter('formation_id', value === 'all' ? '' : Number(value))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All trainings" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        {trainings.map((training) => (
                                            <SelectItem key={training.id} value={String(training.id)}>
                                                {training.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </section>
                </div>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        onClick={triggerExport}
                        className="cursor-pointer border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)]"
                    >
                        Export
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
