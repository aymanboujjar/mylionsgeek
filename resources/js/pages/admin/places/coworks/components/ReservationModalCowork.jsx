import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const ReservationModalCowork = ({ isOpen, onClose, cowork, selectedRange, onSuccess, coworks = [], allowMultiple = false, blockedTableIds = [] }) => {
    const { data, setData, post, processing, errors, reset } = useForm({
        table: cowork?.id || '',
        seats: 1,
        day: selectedRange?.day || '',
        start: selectedRange?.start || '',
        end: selectedRange?.end || '',
    });

    const [timeError, setTimeError] = useState('');

    useEffect(() => {
        if (selectedRange) {
            setData({
                ...data,
                day: selectedRange.day,
                start: selectedRange.start,
                end: selectedRange.end,
            });
        }
    }, [selectedRange]);

    useEffect(() => {
        if (cowork && cowork.id) {
            setData('table', cowork.id);
        }
    }, [cowork?.id]);

    useEffect(() => {
        if (blockedTableIds.length && blockedTableIds.includes(data.table)) {
            setData('table', '');
        }
    }, [blockedTableIds, data.table]);

    const handleClose = () => {
        reset();
        setTimeError('');
        onClose();
    };

    const isDateTimeInPast = (dayValue, timeValue) => {
        if (!dayValue || !timeValue) return false;
        const composed = new Date(`${dayValue}T${timeValue}`);
        return composed < new Date();
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        const toMinutes = (time) => {
            const [h, m] = time.split(':').map(Number);
            return h * 60 + m;
        };

        const startMinutes = data.start ? toMinutes(data.start) : null;
        const endMinutes = data.end ? toMinutes(data.end) : null;

        const minMinutes = 8 * 60;
        const maxMinutes = 18 * 60;

        if (!data.start || !data.end) {
            setTimeError('Please select both start and end times.');
            return;
        }

        // Validate time range
        if (startMinutes < minMinutes || startMinutes > maxMinutes) {
            setTimeError('Start time must be between 08:00 and 18:00.');
            return;
        }

        if (endMinutes < minMinutes || endMinutes > maxMinutes) {
            setTimeError('End time must be between 08:00 and 18:00.');
            return;
        }

        if (endMinutes <= startMinutes) {
            setTimeError('End time must be later than start time.');
            return;
        }

        if (isDateTimeInPast(data.day, data.start)) {
            setTimeError('Reservation start time cannot be in the past.');
            return;
        }

        setTimeError('');

        post('/admin/reservations/storeReservationCowork', {
            onSuccess: () => {
                handleClose();
                onSuccess();
            },
            onError: (errors) => {
                console.error('Cowork Reservation Errors:', errors);
            },
        });
    };

    return (
        <Dialog open={isOpen} onOpenChange={handleClose}>
            <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto border border-white/10 bg-light text-black dark:bg-dark dark:text-white">
                <DialogHeader>
                    <DialogTitle className="text-black dark:text-white">
                        {(() => {
                            const current = coworks.find((t) => String(t.id) === String(data.table)) || cowork;
                            return current
                                ? `Cowork Reservation - ${current.table ? `Table ${current.table}` : current.name || `Table ${current.id}`}`
                                : 'Cowork Reservation';
                        })()}
                    </DialogTitle>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Table Selection */}
                    <div>
                        <Label htmlFor="table" className="text-black dark:text-white">
                            Table
                        </Label>
                        <Select value={String(data.table)} onValueChange={(v) => setData('table', parseInt(v))}>
                            <SelectTrigger className="border-[var(--color-alpha)] bg-light text-black dark:bg-dark dark:text-white">
                                <SelectValue placeholder="Select a table" />
                            </SelectTrigger>
                            <SelectContent>
                                {coworks
                                    .filter((c) => c.state && !blockedTableIds.includes(c.id))
                                    .map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.table ? `Table ${c.table}` : `Table ${c.id}`}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                        {errors.table && <p className="mt-1 text-sm text-red-500">{errors.table}</p>}
                        {coworks.filter((c) => c.state && !blockedTableIds.includes(c.id)).length === 0 && (
                            <p className="mt-1 text-sm text-red-500">No available tables for this time slot</p>
                        )}
                    </div>

                    {/* Number of Seats */}
                    <div>
                        <Label htmlFor="seats" className="text-black dark:text-white">
                            Number of Seats
                        </Label>
                        <Input
                            id="seats"
                            type="number"
                            min="1"
                            max="20"
                            value={data.seats}
                            onChange={(e) => setData('seats', parseInt(e.target.value) || 1)}
                            className="border-[var(--color-alpha)] bg-light text-black focus:border-[var(--color-alpha)] focus:ring-[var(--color-alpha)] dark:bg-dark dark:text-white"
                            required
                        />
                        {errors.seats && <p className="mt-1 text-sm text-red-500">{errors.seats}</p>}
                    </div>

                    {/* Date, Start Time, End Time Row */}
                    <div className="grid grid-cols-3 gap-3">
                        {/* DATE INPUT */}
                        <div>
                            <Label htmlFor="day" className="text-black dark:text-white">
                                Date
                            </Label>
                            <Input
                                id="day"
                                type="date"
                                value={data.day}
                                onChange={(e) => setData('day', e.target.value)}
                                className="border-[var(--color-alpha)] bg-light text-black focus:border-[var(--color-alpha)] focus:ring-[var(--color-alpha)] dark:bg-dark dark:text-white"
                                required
                            />
                            {errors.day && <p className="mt-1 text-sm text-red-500">{errors.day}</p>}
                        </div>

                        {/* START TIME */}
                        <div>
                            <Label htmlFor="start" className="text-black dark:text-white">
                                Start Time
                            </Label>
                            <Input
                                id="start"
                                type="time"
                                value={data.start}
                                onChange={(e) => setData('start', e.target.value)}
                                className="border-[var(--color-alpha)] bg-light text-black focus:border-[var(--color-alpha)] focus:ring-[var(--color-alpha)] dark:bg-dark dark:text-white"
                                required
                                min="08:00"
                                max="18:00"
                            />
                            {errors.start && <p className="mt-1 text-sm text-red-500">{errors.start}</p>}
                        </div>

                        {/* END TIME */}
                        <div>
                            <Label htmlFor="end" className="text-black dark:text-white">
                                End Time
                            </Label>
                            <Input
                                id="end"
                                type="time"
                                value={data.end}
                                onChange={(e) => setData('end', e.target.value)}
                                className="border-[var(--color-alpha)] bg-light text-black focus:border-[var(--color-alpha)] focus:ring-[var(--color-alpha)] dark:bg-dark dark:text-white"
                                required
                                min="08:00"
                                max="18:00"
                            />
                            {errors.end && <p className="mt-1 text-sm text-red-500">{errors.end}</p>}
                        </div>
                    </div>

                    {/* Time Validation Message */}
                    {timeError && <p className="text-sm text-red-500">{timeError}</p>}

                    {/* BUTTONS */}
                    <div className="flex justify-end gap-2 pt-4">
                        <Button
                            type="button"
                            onClick={handleClose}
                            variant="outline"
                            className="border-white/10 bg-dark_gray text-black hover:bg-white/10 dark:text-white"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || !data.table}
                            className="bg-[var(--color-alpha)] font-semibold text-black hover:bg-[var(--color-alpha)]/80 dark:text-white"
                        >
                            {processing ? 'Saving...' : 'Save'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
};

export default ReservationModalCowork;
