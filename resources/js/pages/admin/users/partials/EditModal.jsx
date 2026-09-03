import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { router, usePage } from '@inertiajs/react';
import { Briefcase, ExternalLink, Facebook, Github, ImagePlus, Instagram, Linkedin, MessageCircle, Send, Trash, Twitter, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { normalizeStatusForSelect, resolveStatusOptions } from '@/components/helpers/userStatuses';
import { GENDER_OPTIONS, HANDICAP_OPTIONS, handicapSelectValue, PROGRAM_STATUS_OPTIONS } from '@/components/helpers/userDemographics';
import Rolegard from '../../../../components/rolegard';
import RolesMultiSelect from './RolesMultiSelect';

const platformIcons = {
    instagram: Instagram,
    facebook: Facebook,
    twitter: Twitter,
    github: Github,
    linkedin: Linkedin,
    behance: ExternalLink,
    pinterest: ExternalLink,
    discord: MessageCircle,
    threads: Send,
    reddit: Users,
    portfolio: Briefcase,
};

const platforms = [
    { value: 'instagram', label: 'Instagram', domains: ['instagram.com', 'instagr.am'] },
    { value: 'github', label: 'GitHub', domains: ['github.com'] },
    { value: 'linkedin', label: 'LinkedIn', domains: ['linkedin.com'] },
    { value: 'behance', label: 'Behance', domains: ['behance.net'] },
    { value: 'portfolio', label: 'Portfolio', domains: [] }, // Portfolio doesn't require specific domains
];

const EditUserModal = ({ open, editedUser, onClose, roles = [], status = [], trainings = [] }) => {
    const getInitials = useInitials();
    const { auth } = usePage().props;
    const userRoles = Array.isArray(auth?.user?.role) ? auth.user.role : [auth?.user?.role];
    const userRolesLower = userRoles.map((r) => String(r).toLowerCase());
    const canEditOthers = userRolesLower.some((r) =>
        ['admin', 'super_admin', 'moderateur', 'coach', 'studio_responsable', 'responsable_studio'].includes(r),
    );
    const canGrantStaffRoles = userRolesLower.includes('admin') || userRolesLower.includes('super_admin');
    const isAdminOrStudioResponsable =
        userRolesLower.includes('admin') ||
        userRolesLower.includes('super_admin') ||
        userRolesLower.includes('moderateur') ||
        userRolesLower.includes('studio_responsable') ||
        userRolesLower.includes('responsable_studio');
    const [errors, setErrors] = useState({});
    const [newSocialPlatform, setNewSocialPlatform] = useState('');
    const [newSocialUrl, setNewSocialUrl] = useState('');
    const [socialValidationError, setSocialValidationError] = useState('');
    const [socialLinks, setSocialLinks] = useState(editedUser?.social_links || []);
    const canManageSocials = auth?.user?.id === editedUser?.id;
    const isEditingSelf = auth?.user?.id === editedUser?.id;
    const isStudying = editedUser?.status?.toLowerCase() === 'studying';
    const showStatusField = canEditOthers || !isEditingSelf || !isStudying;

    const statusOptions = useMemo(
        () =>
            resolveStatusOptions({
                isStaff: canEditOthers,
                passedOptions: status,
                currentStatus: editedUser?.status,
            }),
        [canEditOthers, status, editedUser?.status],
    );

    // Filter out platforms that are already added
    const availablePlatforms = platforms.filter((platform) => !socialLinks.some((link) => link.title === platform.value));

    const [formData, setFormData] = useState({
        name: editedUser?.name || '',
        email: editedUser?.email || '',
        roles: [],
        status: editedUser?.status || '',
        formation_id: editedUser?.formation_id || '',
        phone: editedUser?.phone ?? '',
        cin: editedUser?.cin ?? '',
        gender: editedUser?.gender || 'none',
        has_handicap: handicapSelectValue(editedUser?.has_handicap),
        program_status: editedUser?.program_status || 'none',
        speciality: editedUser?.speciality ?? '',
        image: editedUser?.image || null,
        resumeFile: null,
        access_studio: editedUser?.access_studio === 1 ? 'Yes' : 'No',
        access_cowork: editedUser?.access_cowork === 1 ? 'Yes' : 'No',
        access_scan: editedUser?.access_scan === 1 ? 'Yes' : 'No',
    });

    // Load user data into form when modal opens or user changes
    useEffect(() => {
        if (editedUser) {
            let rolesArray = [];
            if (Array.isArray(editedUser.role)) {
                rolesArray = editedUser.role;
            } else if (typeof editedUser.role === 'string' && editedUser.role.length > 0) {
                try {
                    const parsed = JSON.parse(editedUser.role);
                    if (Array.isArray(parsed)) rolesArray = parsed;
                    else
                        rolesArray = editedUser.role
                            .split(',')
                            .map((r) => r.trim())
                            .filter(Boolean);
                } catch {
                    rolesArray = editedUser.role
                        .split(',')
                        .map((r) => r.trim())
                        .filter(Boolean);
                }
            }
            rolesArray = rolesArray.map((r) => String(r).toLowerCase());
            const optionsForNormalize = resolveStatusOptions({
                isStaff: canEditOthers,
                passedOptions: status,
                currentStatus: editedUser.status,
            });
            setFormData({
                name: editedUser.name || '',
                email: editedUser.email || '',
                roles: rolesArray,
                status: normalizeStatusForSelect(editedUser.status, optionsForNormalize),
                formation_id: editedUser.formation_id || '',
                phone: editedUser.phone ?? '',
                cin: editedUser.cin ?? '',
                gender: editedUser.gender || 'none',
                has_handicap: handicapSelectValue(editedUser.has_handicap),
                program_status: editedUser.program_status || 'none',
                speciality: editedUser.speciality ?? '',
                image: editedUser?.image || null,
                resumeFile: null,
                access_studio: editedUser.access_studio === 1 ? 'Yes' : 'No',
                access_cowork: editedUser.access_cowork === 1 ? 'Yes' : 'No',
                access_scan: editedUser.access_scan === 1 ? 'Yes' : 'No',
            });
            setSocialLinks(editedUser?.social_links || []);
        }
    }, [editedUser, canEditOthers, status]);

    const validateSocialUrl = () => {
        if (!newSocialPlatform || !newSocialUrl) return false;

        const platform = platforms.find((p) => p.value === newSocialPlatform);
        if (!platform) return false;

        // Portfolio doesn't require domain validation
        if (platform.value === 'portfolio') {
            // Just check if it's a valid URL format
            try {
                new URL(newSocialUrl);
                setSocialValidationError('');
                return true;
            } catch {
                setSocialValidationError('Please enter a valid URL');
                return false;
            }
        }

        const urlLower = newSocialUrl.toLowerCase();
        const isValidDomain = platform.domains.some((domain) => urlLower.includes(domain));

        if (!isValidDomain) {
            setSocialValidationError(`URL must contain ${platform.domains.join(' or ')}`);
            return false;
        }

        setSocialValidationError('');
        return true;
    };

    const addSocialLink = () => {
        if (!validateSocialUrl()) return;

        router.post(
            '/studets/social-links',
            {
                title: newSocialPlatform,
                url: newSocialUrl,
            },
            {
                onSuccess: () => {
                    setNewSocialPlatform('');
                    setNewSocialUrl('');
                    setSocialValidationError('');
                },
                onError: (errors) => {
                    setSocialValidationError(errors.url || 'Failed to add social link');
                },
            },
        );
    };

    const deleteSocialLink = (linkId) => {
        router.delete(`/students/social-links/${linkId}`, {
            onSuccess: () => {
                // Social links will be updated via Inertia
            },
        });
    };

    const submitEdit = (e) => {
        e.preventDefault();

        if (!editedUser) return;

        const form = new FormData();

        form.append('_method', 'put');
        form.append('name', formData.name);
        form.append('email', formData.email);
        formData.roles.forEach((r) => form.append('roles[]', r));
        if (showStatusField && formData.status) {
            form.append('status', formData.status);
        }
        // Only admin/super_admin receive cin/phone/has_handicap on the index DTO.
        // Never POST empty values for missing keys — that would wipe student records.
        if (canGrantStaffRoles || Object.prototype.hasOwnProperty.call(editedUser, 'phone')) {
            form.append('phone', formData.phone ?? '');
        }
        if (canGrantStaffRoles || Object.prototype.hasOwnProperty.call(editedUser, 'cin')) {
            form.append('cin', formData.cin ?? '');
        }
        form.append('speciality', formData.speciality ?? '');
        form.append('formation_id', formData.formation_id || '');

        if (canEditOthers) {
            form.append('gender', formData.gender === 'none' ? '' : formData.gender);
            if (canGrantStaffRoles || Object.prototype.hasOwnProperty.call(editedUser, 'has_handicap')) {
                form.append('has_handicap', formData.has_handicap === 'none' ? '' : formData.has_handicap);
            }
            form.append('program_status', formData.program_status === 'none' ? '' : formData.program_status);
        }

        form.append('access_studio', formData.access_studio === 'Yes' ? 1 : 0);
        form.append('access_cowork', formData.access_cowork === 'Yes' ? 1 : 0);
        form.append('access_scan', formData.access_scan === 'Yes' ? 1 : 0);

        if (formData?.image instanceof File) {
            form.append('image', formData?.image);
        }

        if (formData?.resumeFile instanceof File) {
            form.append('resume', formData.resumeFile);
        }

        router.post(`/students/update/${editedUser.id}`, form, {
            forceFormData: true,
            onSuccess: () => {
                setErrors({});
                onClose();
            },
            onError: (err) => {
                setErrors(err);
                console.error('Form submission error:', err);
            },
        });
    };

    function resendLink(userId) {
        router.post(`/admin/users/${userId}/resend-link`);
    }

    const resetPassword = (id) => {
        router.post(`/admin/users/${id}/reset-password`, {
            onSuccess: () => {
                //alert('Password reset successfully');
            },
            onError: () => {
                //alert('Error resetting password');
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(val) => !val && onClose()}>
            <DialogContent className="max-h-[80vh] overflow-y-auto bg-light text-dark sm:max-w-[720px] dark:bg-dark dark:text-light">
                <DialogHeader>
                    <DialogTitle>{editedUser ? getInitials(editedUser.name) : 'Modify user'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submitEdit} className="mt-2 grid grid-cols-1 gap-4 md:grid-cols-2">
                    {/* Avatar */}
                    <div className="col-span-1 mb-4 flex flex-col items-center gap-4 md:col-span-2">
                        <div className="relative h-24 w-24">
                            <Avatar
                                image={formData?.image instanceof File ? URL.createObjectURL(formData?.image) : formData?.image || editedUser?.image}
                                name={formData?.name}
                                lastActivity={editedUser?.last_login ?? editedUser?.last_online ?? editedUser?.last_activity ?? null}
                                className="h-24 w-24 overflow-hidden rounded-full"
                                onlineCircleClass="hidden"
                            />

                            <label className="absolute right-0 bottom-0 flex h-8 w-8 cursor-pointer items-center justify-center rounded-full border-2 border-white bg-alpha hover:bg-alpha/80">
                                <ImagePlus size={18} className="text-white" />
                                <input
                                    type="file"
                                    accept="image/*"
                                    className="absolute inset-0 cursor-pointer opacity-0"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] || null;
                                        setFormData({ ...formData, image: file });
                                    }}
                                />
                            </label>
                        </div>
                    </div>

                    {/* Form Fields - Left Column */}
                    <div className="col-span-1">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} />
                    </div>
                    {/* Right Column - Email */}
                    <div className="col-span-1">
                        <Label htmlFor="email">Email</Label>
                        <Input id="email" type="email" value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} />
                    </div>
                    {/* Phone — only when index DTO includes it (admin/super_admin) */}
                    {canGrantStaffRoles && (
                        <div className="col-span-1">
                            <Label htmlFor="phone">Phone</Label>
                            <Input id="phone" value={formData.phone} onChange={(e) => setFormData({ ...formData, phone: e.target.value })} />
                        </div>
                    )}
                    {canEditOthers && (
                        <div className="col-span-1">
                            <Label>Gender</Label>
                            <Select value={formData.gender} onValueChange={(v) => setFormData({ ...formData, gender: v })}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select gender" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Not set</SelectItem>
                                    {GENDER_OPTIONS.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    {canEditOthers && (
                        <div className="col-span-1">
                            <Label>Program status</Label>
                            <Select
                                value={formData.program_status}
                                onValueChange={(v) => setFormData({ ...formData, program_status: v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select program status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Not set</SelectItem>
                                    {PROGRAM_STATUS_OPTIONS.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    {showStatusField && (
                        <div className="col-span-1">
                            <Label>Status</Label>
                            <Select value={formData.status} onValueChange={(v) => setFormData({ ...formData, status: v })}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select status" />
                                </SelectTrigger>
                                <SelectContent>
                                    {statusOptions.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {s}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    {canGrantStaffRoles && (
                        <div className="col-span-1">
                            <Label htmlFor="cin">CIN</Label>
                            <Input id="cin" value={formData.cin || ''} onChange={(e) => setFormData({ ...formData, cin: e.target.value })} />
                        </div>
                    )}
                    {canGrantStaffRoles && (
                        <div className="col-span-1">
                            <Label>Handicap</Label>
                            <Select value={formData.has_handicap} onValueChange={(v) => setFormData({ ...formData, has_handicap: v })}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select handicap" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Not set</SelectItem>
                                    {HANDICAP_OPTIONS.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <div className="lg:col-span-1 md:col-span-2">
                        <Label htmlFor="speciality">Speciality</Label>
                        <Input
                            id="speciality"
                            value={formData.speciality}
                            onChange={(e) => setFormData({ ...formData, speciality: e.target.value })}
                            placeholder="e.g. Full stack developer, Mobile developer"
                        />
                        {errors.speciality && (
                            <p className="mt-1 text-xs text-red-500">{Array.isArray(errors.speciality) ? errors.speciality[0] : errors.speciality}</p>
                        )}
                    </div>
                    <div className="col-span-1">
                        <Label htmlFor="resume" className="mb-2 flex items-center gap-2">
                            CV (PDF or Word)
                        </Label>
                        <Input
                            id="resume"
                            type="file"
                            accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                            className="h-fit border-beta/30 py-1 file:rounded-lg file:bg-beta/10 file:px-3 file:py-0.5 focus:border-alpha focus:ring-alpha dark:border-light/10 dark:file:bg-light/10"
                            onChange={(e) => {
                                const file = e.target.files?.[0] || null;
                                setFormData({ ...formData, resumeFile: file });
                            }}
                        />
                        {errors.resume && (
                            <p className="mt-1 text-xs text-red-500">{Array.isArray(errors.resume) ? errors.resume[0] : errors.resume}</p>
                        )}
                        {!formData.resumeFile && editedUser?.resume_url && (
                            <>
                                <a
                                    href={editedUser.resume_view_url || editedUser.resume_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="mt-2 inline-block text-sm text-alpha underline hover:text-alpha/90"
                                >
                                    View current CV
                                </a>
                                {!String(editedUser.resume).toLowerCase().endsWith('.pdf') && (
                                    <p className="mt-1 text-xs text-beta/60 dark:text-light/60">
                                        Word files cannot preview in the browser and may download. Use a PDF to open in a new tab.
                                    </p>
                                )}
                            </>
                        )}
                        {formData.resumeFile && <p className="mt-1 text-sm text-beta/70 dark:text-light/70">Selected: {formData.resumeFile.name}</p>}
                    </div>

                    {/* Socials Section */}
                    {canManageSocials && (
                        <div className="col-span-1 md:col-span-2">
                            <Label>Socials</Label>

                            <div className="mt-3">
                                <div className="flex w-full items-center gap-3">
                                    <div className="w-[40%]">
                                        <Select
                                            value={newSocialPlatform}
                                            onValueChange={(value) => {
                                                setNewSocialPlatform(value);
                                                setSocialValidationError('');
                                            }}
                                        >
                                            <SelectTrigger className="border-beta/30 focus:border-alpha focus:ring-alpha dark:border-light/20">
                                                <SelectValue placeholder="Select platform" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {availablePlatforms.map((platform) => (
                                                    <SelectItem key={platform.value} value={platform.value}>
                                                        {platform.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="w-[40%]">
                                        <Input
                                            type="url"
                                            placeholder="https://example.com/username"
                                            value={newSocialUrl}
                                            onChange={(e) => {
                                                setNewSocialUrl(e.target.value);
                                                setSocialValidationError('');
                                            }}
                                            className="border-beta/30 focus:border-alpha focus:ring-alpha dark:border-light/20"
                                        />
                                    </div>
                                    <div className="w-[20%]">
                                        <button
                                            type="button"
                                            onClick={addSocialLink}
                                            disabled={!newSocialPlatform || !newSocialUrl}
                                            className="w-full rounded-full bg-alpha px-3 py-2 text-sm font-medium text-black transition-colors hover:bg-alpha/90 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            Save
                                        </button>
                                    </div>
                                </div>
                                {socialValidationError && <p className="mt-1 text-xs text-red-500">{socialValidationError}</p>}
                            </div>

                            {/* Display existing social links */}
                            <div className="mt-4 space-y-2">
                                {socialLinks.length === 0 ? (
                                    <p className="text-sm text-beta/60 dark:text-light/60">No socials added.</p>
                                ) : (
                                    socialLinks.map((link) => {
                                        const IconComponent = platformIcons[link.title] || ExternalLink;
                                        return (
                                            <div
                                                key={link.id}
                                                className="flex items-center justify-between gap-3 rounded-lg border border-beta/10 p-3 transition hover:bg-beta/5 dark:border-light/10 dark:hover:bg-light/5"
                                            >
                                                <div className="flex min-w-0 items-center gap-3">
                                                    <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-beta/5 dark:bg-light/5">
                                                        <IconComponent className="h-4 w-4 text-beta/70 dark:text-light/70" />
                                                    </div>
                                                    <div className="min-w-0">
                                                        <div className="text-sm font-semibold text-beta dark:text-light">{link.title}</div>
                                                        <a
                                                            href={link.url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="block truncate text-xs text-beta/60 hover:underline dark:text-light/60"
                                                        >
                                                            {link.url}
                                                        </a>
                                                    </div>
                                                </div>
                                                <button type="button" onClick={() => deleteSocialLink(link.id)} className="text-error">
                                                    <Trash size={16} />
                                                </button>
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        </div>
                    )}
                    {/* Right Column - Roles */}
                    <Rolegard authorized={'admin'}>
                        {isAdminOrStudioResponsable && (
                            <div className="col-span-1">
                                <Label htmlFor="roles">Roles</Label>
                                <RolesMultiSelect
                                    roles={formData.roles}
                                    onChange={(newRoles) => setFormData({ ...formData, roles: newRoles })}
                                    canGrantStaffRoles={canGrantStaffRoles}
                                />
                            </div>
                        )}
                    </Rolegard>
                    {/* Left Column - Access Studio */}
                    {isAdminOrStudioResponsable && (
                        <div className="col-span-1">
                            <Label htmlFor="access-studio">Access Studio</Label>
                            <Select
                                id="access-studio"
                                value={formData.access_studio}
                                onValueChange={(v) => setFormData({ ...formData, access_studio: v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select Access Studio" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={'Yes'}>Yes</SelectItem>
                                    <SelectItem value={'No'}>No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    {/* Right Column - Access Cowork */}
                    {isAdminOrStudioResponsable && (
                        <div className="col-span-1">
                            <Label htmlFor="access-cowork">Access Cowork</Label>
                            <Select
                                id="access-cowork"
                                value={formData.access_cowork}
                                onValueChange={(v) => setFormData({ ...formData, access_cowork: v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select Access Cowork" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={'Yes'}>Yes</SelectItem>
                                    <SelectItem value={'No'}>No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    {isAdminOrStudioResponsable && (
                        <div className="col-span-1">
                            <Label htmlFor="access-scan">Access Scan</Label>
                            <Select
                                id="access-scan"
                                value={formData.access_scan}
                                onValueChange={(v) => setFormData({ ...formData, access_scan: v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select Access Scan" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={'Yes'}>Yes</SelectItem>
                                    <SelectItem value={'No'}>No</SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="mt-1 text-xs text-neutral-500">
                                Mobile Scan tab (events and info sessions)
                            </p>
                        </div>
                    )}
                    {/* Left Column - Training */}
                    {isAdminOrStudioResponsable && (
                        <div className="md:col-span-1 lg:col-span-1 col-span-2">
                            <Label>Training</Label>
                            <Select
                                value={formData.formation_id ? String(formData.formation_id) : ''}
                                onValueChange={(v) => setFormData({ ...formData, formation_id: Number(v) })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select training" />
                                </SelectTrigger>
                                <SelectContent>
                                    {trainings.map((t) => (
                                        <SelectItem key={t.id} value={String(t.id)}>
                                            {t.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {/* Footer */}
                    <div className="col-span-1 mt-6 md:col-span-2">
                        <div className="flex flex-col items-center justify-between gap-4 border-t pt-4 md:flex-row">
                            {/* Left side - Resend Link */}
                            {editedUser?.activation_token != null ? (
                                <Button
                                    onClick={() => resendLink(editedUser.id)}
                                    type="button"
                                    className="flex w-fit cursor-pointer items-center gap-2 rounded-lg bg-[#e5e5e5] px-2 py-1 text-[#0a0a0a] hover:bg-[#e5e5e5] hover:text-[#0a0a0a] dark:bg-[#262626] dark:text-white"
                                >
                                    Resend Link
                                </Button>
                            ) : (
                                <Button
                                    onClick={() => resetPassword(editedUser.id)}
                                    type="button"
                                    className="flex w-fit cursor-pointer items-center gap-2 rounded-lg bg-[#e5e5e5] px-2 py-1 text-[#0a0a0a] hover:bg-[#e5e5e5] hover:text-[#0a0a0a] dark:bg-[#262626] dark:text-white"
                                >
                                    Reset Password
                                </Button>
                            )}

                            {/* Right side - Action buttons */}
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    className="flex w-fit cursor-pointer items-center gap-2 rounded-lg bg-[#e5e5e5] px-2 py-1 text-[#0a0a0a] hover:bg-[#e5e5e5] hover:text-[#0a0a0a] dark:bg-[#262626] dark:text-white"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit">Save changes</Button>
                            </div>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
};

export default EditUserModal;
