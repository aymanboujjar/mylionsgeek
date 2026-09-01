import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { GENDER_OPTIONS, HANDICAP_OPTIONS, PROGRAM_STATUS_OPTIONS } from '@/components/helpers/userDemographics';
import { Filter, RotateCw, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

const displayRole = (role) => (role === 'studio_responsable' ? 'Responsable Studio' : role);

const FALLBACK_FILTERS = {
    search: '',
    training: null,
    promo: null,
    role: '',
    status: '',
    date: '',
    field: null,
    gender: '',
    has_handicap: '',
    program_status: '',
};

const FilterPart = ({ filters, setFilters, allPromo, trainings, roles, status, fields = [], initialFilters }) => {
    const [open, setOpen] = useState(false);
    const resetValues = initialFilters ? { ...initialFilters } : FALLBACK_FILTERS;

    const handleChange = (field, value) => {
        setFilters((prev) => ({ ...prev, [field]: value }));
    };

    const activeFilterCount = useMemo(() => {
        let count = 0;
        if (filters.training !== null && filters.training !== undefined) count += 1;
        if (filters.promo) count += 1;
        if (filters.field) count += 1;
        if (filters.role) count += 1;
        if (filters.status) count += 1;
        if (filters.gender) count += 1;
        if (filters.has_handicap !== '' && filters.has_handicap !== null && filters.has_handicap !== undefined) count += 1;
        if (filters.program_status) count += 1;
        return count;
    }, [filters]);

    const handleReset = () => {
        setFilters({ ...resetValues, search: filters.search });
    };

    const handleResetAll = () => {
        setFilters({ ...resetValues });
        setOpen(false);
    };

    return (
        <>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="relative w-full sm:max-w-sm">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-500" />
                    <Input
                        type="text"
                        placeholder="Search"
                        className="bg-[#e5e5e5] pl-10 text-[#0a0a0a] placeholder-[#0a0a0a]/50 dark:bg-[#262626] dark:text-white dark:placeholder-white"
                        value={filters.search}
                        onChange={(e) => handleChange('search', e.target.value)}
                    />
                </div>

                <div className="flex items-center gap-2 self-end sm:self-auto">
                    <Button
                        type="button"
                        variant="outline"
                        className="cursor-pointer gap-2 border-beta/20 bg-[#e5e5e5] text-[#0a0a0a] hover:bg-[#dcdcdc] dark:border-light/10 dark:bg-[#262626] dark:text-white dark:hover:bg-[#333]"
                        onClick={() => setOpen(true)}
                    >
                        <Filter size={16} />
                        Filter
                        {activeFilterCount > 0 && (
                            <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-alpha px-1.5 text-xs font-semibold text-black">
                                {activeFilterCount}
                            </span>
                        )}
                    </Button>

                    <Button
                        type="button"
                        variant="outline"
                        className="cursor-pointer gap-2 border-beta/20 bg-[#e5e5e5] text-[#0a0a0a] hover:bg-[#dcdcdc] dark:border-light/10 dark:bg-[#262626] dark:text-white dark:hover:bg-[#333]"
                        onClick={handleResetAll}
                    >
                        <RotateCw size={15} />
                        Reset
                    </Button>
                </div>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Filters</DialogTitle>
                        <DialogDescription>Filter users by training, role, gender, handicap, and more.</DialogDescription>
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-4 py-2 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Training</Label>
                            <Select
                                value={filters.training === null ? 'all' : String(filters.training)}
                                onValueChange={(e) => handleChange('training', e === 'all' ? null : Number(e))}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select By Training" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {trainings.map((training) => (
                                        <SelectItem key={training.id} value={training.id?.toString?.() ?? String(training.id)}>
                                            <div className="flex flex-col">
                                                <span>{training.name}</span>
                                                <span className="text-xs text-muted-foreground">Coach: {training.coach?.name ?? '—'}</span>
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Promo</Label>
                            <Select
                                value={filters.promo ?? 'all'}
                                onValueChange={(e) => handleChange('promo', e === 'all' ? null : e)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select By Promo" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {allPromo.map((p) => (
                                        <SelectItem key={p} value={p}>
                                            {p}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Field</Label>
                            <Select
                                value={filters.field ?? 'all'}
                                onValueChange={(value) => handleChange('field', value === 'all' ? null : value)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select By Field" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {fields.map((field) => (
                                        <SelectItem key={field} value={field}>
                                            {field}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Role</Label>
                            <Select
                                value={filters.role || 'all'}
                                onValueChange={(e) => handleChange('role', e === 'all' ? '' : e)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select By Role" />
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
                                value={filters.status || 'all'}
                                onValueChange={(e) => handleChange('status', e === 'all' ? '' : e)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select By Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {status.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {s}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Gender</Label>
                            <Select
                                value={filters.gender || 'all'}
                                onValueChange={(e) => handleChange('gender', e === 'all' ? '' : e)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select By Gender" />
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
                            <Label>Handicap</Label>
                            <Select
                                value={filters.has_handicap === '' || filters.has_handicap === null || filters.has_handicap === undefined ? 'all' : String(filters.has_handicap)}
                                onValueChange={(e) => handleChange('has_handicap', e === 'all' ? '' : e)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select By Handicap" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {HANDICAP_OPTIONS.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Program status</Label>
                            <Select
                                value={filters.program_status || 'all'}
                                onValueChange={(e) => handleChange('program_status', e === 'all' ? '' : e)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select By Program status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {PROGRAM_STATUS_OPTIONS.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button type="button" variant="outline" onClick={handleReset}>
                            Clear filters
                        </Button>
                        <Button
                            type="button"
                            className="border border-alpha bg-alpha text-black hover:bg-transparent hover:text-alpha"
                            onClick={() => setOpen(false)}
                        >
                            Apply
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
};

export default FilterPart;
