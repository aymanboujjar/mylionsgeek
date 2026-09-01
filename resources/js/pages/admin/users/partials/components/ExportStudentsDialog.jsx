import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useEffect, useMemo, useState } from 'react';

const DEFAULT_EXPORT_FIELDS = {
    name: true,
    email: true,
    cin: true,
    phone: false,
    gender: false,
    program_status: false,
    status: false,
    has_handicap: false,
    formation: true,
    access_studio: false,
    access_cowork: false,
    role: false,
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

export default function ExportStudentsDialog({ open, setOpen, hiddenFields = [] }) {
    const [exportFields, setExportFields] = useState(() => buildExportFields(hiddenFields));

    useEffect(() => {
        if (open) {
            setExportFields(buildExportFields(hiddenFields));
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

    const triggerExport = () => {
        window.open(`/admin/users/export?fields=${encodeURIComponent(exportQuery)}`, '_blank');
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Export Students</DialogTitle>
                    <DialogDescription>Select columns to include.</DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-2 gap-4 py-4">
                    {visibleFieldKeys.map((key) => (
                        <div key={key} className="flex items-center space-x-3">
                            <Checkbox
                                checked={exportFields[key]}
                                onCheckedChange={(checked) =>
                                    setExportFields((prev) => ({
                                        ...prev,
                                        [key]: !!checked,
                                    }))
                                }
                            />
                            <label className="capitalize">{key.replace(/_/g, ' ')}</label>
                        </div>
                    ))}
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
