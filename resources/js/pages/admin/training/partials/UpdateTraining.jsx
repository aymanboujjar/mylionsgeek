import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, useForm, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';

function buildTrainingFormData(training) {
    return {
        name: training.name || '',
        category: training.category || '',
        start_time: training.start_time || '',
        user_id: training.user_id || '',
        promo: training.promo || '',
        certificate_template: null,
        is_active: !!training.is_active,
    };
}

export default function UpdateTraining({ training, coaches }) {
    const [open, setOpen] = useState(false);
    const page = usePage();
    const userRoles = Array.isArray(page.props?.auth?.user?.role) ? page.props.auth.user.role : [page.props?.auth?.user?.role].filter(Boolean);
    const canManageCertificateTemplate = userRoles.includes('admin') || userRoles.includes('super_admin');

    const { data, setData, setDefaults, put, processing, reset, errors, transform } = useForm(buildTrainingFormData(training));

    // FormData drops false booleans; send explicit 0/1
    transform((form) => ({
        ...form,
        is_active: form.is_active ? 1 : 0,
    }));

    const syncFormFromTraining = () => {
        const payload = buildTrainingFormData(training);
        setDefaults(payload);
        setData(payload);
    };

    const handleOpenChange = (newOpen) => {
        if (newOpen) {
            syncFormFromTraining();
        } else {
            reset();
        }
        setOpen(newOpen);
    };

    function handleSubmit(e) {
        e.preventDefault();

        put(`/trainings/${training.id}`, {
            forceFormData: true,
            onSuccess: () => {
                setOpen(false);
                router.reload({ only: ['trainings'], preserveState: false });
            },
            onError: () => {
                // Form errors surface via errors prop
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>
                <Button className="flex cursor-pointer items-center gap-2 rounded-lg border border-transparent bg-transparent px-3 text-yellow-600 hover:border-yellow-600 hover:bg-transparent active:scale-95">
                    <Pencil size={1} />
                </Button>
            </DialogTrigger>

            <DialogContent className="border border-alpha/20 bg-light text-dark sm:max-w-lg dark:bg-dark dark:text-light">
                <DialogHeader>
                    <DialogTitle>Edit Training</DialogTitle>
                    <DialogDescription>Update the fields below to edit training session.</DialogDescription>
                </DialogHeader>

                <form className="mt-4 space-y-4" onSubmit={handleSubmit}>
                    <div>
                        <Label htmlFor="title">Training Name</Label>
                        <Input id="title" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Enter training name" />
                        {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    </div>

                    <div>
                        <Label>Category</Label>
                        <Select value={data.category} onValueChange={(value) => setData('category', value)}>
                            <SelectTrigger>
                                <SelectValue placeholder="Select category" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="coding">Coding</SelectItem>
                                <SelectItem value="media">Media</SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.category && <p className="text-sm text-red-600">{errors.category}</p>}
                    </div>

                    <div>
                        <Label htmlFor="startDay">Starting Day</Label>
                        <Input id="startDay" type="date" value={data.start_time} onChange={(e) => setData('start_time', e.target.value)} />
                        {errors.start_time && <p className="text-sm text-red-600">{errors.start_time}</p>}
                    </div>

                    <div>
                        <Label>Coach</Label>
                        <Select value={data.user_id?.toString()} onValueChange={(value) => setData('user_id', Number(value))}>
                            <SelectTrigger>
                                <SelectValue placeholder="Select coach" />
                            </SelectTrigger>
                            <SelectContent>
                                {coaches?.map((coach) => (
                                    <SelectItem key={coach.id} value={coach.id.toString()}>
                                        {coach.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.user_id && <p className="text-sm text-red-600">{errors.user_id}</p>}
                    </div>

                    <div>
                        <Label htmlFor="promo">Promo</Label>
                        <Input
                            id="promo"
                            value={data.promo}
                            onChange={(e) => setData('promo', e.target.value)}
                            placeholder="Enter promo name/number"
                        />
                        {errors.promo && <p className="text-sm text-red-600">{errors.promo}</p>}
                    </div>

                    {/* Manual gate for slot check-in reminders — separate from date-based "Active" badge */}
                    <div className="rounded-xl border border-alpha/20 bg-alpha/5 p-4">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="is_active"
                                checked={!!data.is_active}
                                onCheckedChange={(checked) => setData('is_active', checked === true)}
                                className="mt-0.5"
                            />
                            <div className="flex-1">
                                <Label htmlFor="is_active" className="cursor-pointer font-semibold">
                                    Active for attendance reminders
                                </Label>
                                <p className="mt-1 text-xs text-dark/60 dark:text-light/60">
                                    When on, enrolled students can receive slot check-in reminder pushes. Off by default until staff enables it.
                                </p>
                            </div>
                            <span
                                className={`shrink-0 rounded-lg px-2.5 py-1 text-xs font-medium ${
                                    data.is_active
                                        ? 'bg-alpha/20 text-alpha'
                                        : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'
                                }`}
                            >
                                {data.is_active ? 'Active' : 'Inactive'}
                            </span>
                        </div>
                        {errors.is_active && <p className="mt-2 text-sm text-red-600">{errors.is_active}</p>}
                    </div>

                    {canManageCertificateTemplate && (
                        <div>
                            <Label htmlFor="certificate_template">Certificate template (global default)</Label>
                            <Input
                                id="certificate_template"
                                type="file"
                                accept="image/png,image/jpeg"
                                onChange={(e) => setData('certificate_template', e.target.files?.[0] ?? null)}
                            />
                            {errors.certificate_template && <p className="text-sm text-red-600">{errors.certificate_template}</p>}
                            <p className="mt-1 text-xs text-dark/60 dark:text-light/60">
                                {/* Uploading here replaces the global default certificate template at <code>/public/assets/images/certif.jpg</code>. */}
                            </p>
                        </div>
                    )}

                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            className="cursor-pointer border border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black hover:bg-transparent hover:text-[var(--color-alpha)]"
                            disabled={processing}
                        >
                            {processing ? 'Updating...' : 'Update'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
