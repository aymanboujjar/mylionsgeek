import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@headlessui/react';
import { RotateCw, Search } from 'lucide-react';

const displayRole = (role) => (role === 'studio_responsable' ? 'Responsable Studio' : role);

const FilterPart = ({ filters, setFilters, allPromo, trainings, roles, filteredUsers = [], status, fields = [], initialFilters }) => {
    //! handel change selects and saerch
    const handleChange = (field, e) => {
        setFilters((prev) => ({ ...prev, [field]: e }));
    };

    return (
        <>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-8">
                {/* Search Input */}
                <div className="relative">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-500" />
                    <Input
                        type="text"
                        placeholder="Search"
                        className="bg-[#e5e5e5] pl-10 text-[#0a0a0a] placeholder-[#0a0a0a]/50 dark:bg-[#262626] dark:text-white dark:placeholder-white"
                        value={filters.search}
                        onChange={(e) => handleChange('search', e.target.value)}
                    />
                </div>

                {/* Select by Training */}
                <Select
                    value={filters.training === null ? undefined : String(filters.training)}
                    onValueChange={(e) => handleChange('training', e === 'all' ? null : Number(e))}
                >
                    <SelectTrigger className="bg-[#e5e5e5] px-2 text-[#0a0a0a] data-[placeholder]:text-[#0a0a0a]/50 dark:bg-[#262626] dark:text-white dark:data-[placeholder]:text-white">
                        <SelectValue placeholder="Select By Training" />
                    </SelectTrigger>
                    <SelectContent className="bg-[#e5e5e5] text-[#0a0a0a] dark:bg-[#262626] dark:text-white">
                        <SelectItem value="all" className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                            All
                        </SelectItem>
                        {trainings.map((training) => (
                            <SelectItem
                                key={training.id}
                                value={training.id?.toString?.() ?? String(training.id)}
                                className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700"
                            >
                                <div className="flex flex-col">
                                    <span>{training.name}</span>
                                    <span className="text-xs text-muted-foreground">Coach: {training.coach?.name ?? '—'}</span>
                                </div>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Select by Promo */}
                <Select value={filters.promo ?? undefined} onValueChange={(e) => handleChange('promo', e === 'all' ? null : e)}>
                    <SelectTrigger className="bg-[#e5e5e5] text-[#0a0a0a] data-[placeholder]:text-[#0a0a0a]/50 dark:bg-[#262626] dark:text-white dark:data-[placeholder]:text-white">
                        <SelectValue placeholder="Select By Promo" />
                    </SelectTrigger>
                    <SelectContent className="bg-[#e5e5e5] text-[#0a0a0a] dark:bg-[#262626] dark:text-white">
                        <SelectItem value="all" className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                            All
                        </SelectItem>
                        {allPromo.map((p) => (
                            <SelectItem key={p} value={p} className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                                {p}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Select by Field */}
                <Select value={filters.field ?? undefined} onValueChange={(value) => handleChange('field', value === 'all' ? null : value)}>
                    <SelectTrigger className="bg-[#e5e5e5] text-[#0a0a0a] data-[placeholder]:text-[#0a0a0a]/50 dark:bg-[#262626] dark:text-white dark:data-[placeholder]:text-white">
                        <SelectValue placeholder="Select By Field" />
                    </SelectTrigger>
                    <SelectContent className="bg-[#e5e5e5] text-[#0a0a0a] dark:bg-[#262626] dark:text-white">
                        <SelectItem value="all" className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                            All
                        </SelectItem>
                        {fields.map((field) => (
                            <SelectItem
                                key={field}
                                value={field}
                                className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700"
                            >
                                {field}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Select by Role */}
                <Select value={filters.role ?? undefined} onValueChange={(e) => handleChange('role', e === 'all' ? '' : e)}>
                    <SelectTrigger className="bg-[#e5e5e5] text-[#0a0a0a] data-[placeholder]:text-[#0a0a0a]/50 dark:bg-[#262626] dark:text-white dark:data-[placeholder]:text-white">
                        <SelectValue placeholder="Select By Role" />
                    </SelectTrigger>
                    <SelectContent className="bg-[#e5e5e5] text-[#0a0a0a] dark:bg-[#262626] dark:text-white">
                        <SelectItem value="all" className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                            All
                        </SelectItem>
                        {roles.map((role, index) => (
                            <SelectItem
                                key={index}
                                value={role}
                                className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700"
                            >
                                {displayRole(role)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Select by Status */}
                <Select value={filters.status ?? undefined} onValueChange={(e) => handleChange('status', e === 'all' ? '' : e)}>
                    <SelectTrigger className="bg-[#e5e5e5] text-[#0a0a0a] data-[placeholder]:text-[#0a0a0a]/50 dark:bg-[#262626] dark:text-white dark:data-[placeholder]:text-white">
                        <SelectValue placeholder="Select By Status" />
                    </SelectTrigger>
                    <SelectContent className="bg-[#e5e5e5] text-[#0a0a0a] dark:bg-[#262626] dark:text-white">
                        <SelectItem value="all" className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                            All
                        </SelectItem>
                        {status.map((s, index) => (
                            <SelectItem key={index} value={s} className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                                {s}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {/* Select by Gender */}
                <Select value={filters.gender || undefined} onValueChange={(e) => handleChange('gender', e === 'all' ? '' : e)}>
                    <SelectTrigger className="bg-[#e5e5e5] text-[#0a0a0a] data-[placeholder]:text-[#0a0a0a]/50 dark:bg-[#262626] dark:text-white dark:data-[placeholder]:text-white">
                        <SelectValue placeholder="Select By Genre" />
                    </SelectTrigger>
                    <SelectContent className="bg-[#e5e5e5] text-[#0a0a0a] dark:bg-[#262626] dark:text-white">
                        <SelectItem value="all" className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                            All
                        </SelectItem>
                        <SelectItem value="male" className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                            Male
                        </SelectItem>
                        <SelectItem value="female" className="text-[#0a0a0a] focus:bg-gray-200 dark:text-white dark:focus:bg-neutral-700">
                            Female
                        </SelectItem>
                    </SelectContent>
                </Select>

                {/* Reset Button */}
                <Button
                    className="flex w-fit cursor-pointer items-center gap-2 rounded-lg bg-[#e5e5e5] px-2 py-1 text-[#0a0a0a] dark:bg-[#262626] dark:text-white"
                    onClick={() =>
                        setFilters(
                            initialFilters
                                ? { ...initialFilters }
                                : {
                                      search: '',
                                      training: null,
                                      promo: null,
                                      role: '',
                                      status: '',
                                      date: '',
                                      field: null,
                                      gender: '',
                                  },
                        )
                    }
                >
                    <RotateCw size={15} /> Reset
                </Button>
            </div>
        </>
    );
};

export default FilterPart;
