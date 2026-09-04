import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, useForm } from '@inertiajs/react';
import axios from 'axios';
import { AnimatePresence, motion } from 'framer-motion';
import { Building2, KeyRound, MapPin, Phone, User } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const SECTOR_OPTIONS = [
    'Technology',
    'Finance',
    'Healthcare',
    'Education',
    'Retail',
    'Manufacturing',
    'Consulting',
    'Media',
    'Other',
];

const STEPS = [
    { number: 1, label: 'About you' },
    { number: 2, label: 'Contact details' },
    { number: 3, label: 'Secure account' },
];

function Field({ id, label, error, icon: Icon, children }) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.2 }}
            className="space-y-1.5"
        >
            <Label htmlFor={id} className="text-xs font-semibold uppercase tracking-wide text-beta/50">
                {label}
            </Label>
            <motion.div
                initial={{ opacity: 0, scale: 0.98 }}
                animate={{ opacity: 1, scale: 1 }}
                transition={{ duration: 0.2, delay: 0.05 }}
                className="relative flex items-center"
            >
                {Icon && (
                    <span className="pointer-events-none absolute left-3 z-10 text-beta/35">
                        <Icon size={14} />
                    </span>
                )}
                {children}
            </motion.div>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </motion.div>
    );
}

function PasswordField({ id, label, error, value, onChange, placeholder, show, onToggleShow, icon: Icon }) {
    const inputClass =
        'h-11 pl-9 pr-12 bg-white dark:bg-white border-beta/15 text-beta placeholder:text-beta/35 focus-visible:border-alpha/60 focus-visible:ring-alpha/20 select-text';

    return (
        <Field id={id} label={label} error={error} icon={Icon}>
            <Input
                id={id}
                type={show ? 'text' : 'password'}
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                className={inputClass}
                autoComplete={id === 'current_password' ? 'current-password' : 'new-password'}
            />
            <button
                type="button"
                onClick={onToggleShow}
                className="absolute right-3 z-10 text-xs font-medium text-beta/45 transition-colors hover:text-beta"
            >
                {show ? 'Hide' : 'Show'}
            </button>
        </Field>
    );
}

const VALIDATE_STEP_URL = '/organisation/onboarding/validate-step';

function firstValidationMessage(messages) {
    if (!messages) return undefined;
    return Array.isArray(messages) ? messages[0] : messages;
}

export default function OrganisationOnboarding({ organization, passwordChangeOnly = false }) {
    const [step, setStep] = useState(passwordChangeOnly ? 3 : 1);
    const [stepErrors, setStepErrors] = useState({});
    const [validatingStep, setValidatingStep] = useState(false);
    const [showCurrentPassword, setShowCurrentPassword] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);

    const form = useForm({
        contact_name: organization?.contact_name ?? '',
        enterprise_name: organization?.enterprise_name ?? '',
        sector: organization?.sector ?? '',
        location: organization?.location ?? '',
        phone: organization?.phone ?? '',
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const fieldError = (key) => form.errors[key] ?? firstValidationMessage(stepErrors[key]);

    const updateField = (key, value) => {
        form.setData(key, value);
        setStepErrors((prev) => {
            if (!prev[key]) return prev;
            const next = { ...prev };
            delete next[key];
            return next;
        });
        form.clearErrors(key);
    };

    const passwordStrength = useMemo(() => {
        const pwd = form.data.password;
        if (!pwd) return { score: 0, label: 'Too weak', color: 'bg-red-500' };
        let score = 0;
        if (pwd.length >= 8) score++;
        if (/[A-Z]/.test(pwd)) score++;
        if (/[a-z]/.test(pwd)) score++;
        if (/\d/.test(pwd)) score++;
        if (/[^A-Za-z0-9]/.test(pwd)) score++;
        const labels = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong', 'Excellent'];
        const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-blue-500', 'bg-green-500', 'bg-emerald-600'];
        return { score, label: labels[score], color: colors[score] };
    }, [form.data.password]);

    const validateStepOneClient = () => {
        const errs = {};
        if (!form.data.contact_name.trim()) {
            errs.contact_name = 'The contact name field is required.';
        }
        if (!form.data.enterprise_name.trim()) {
            errs.enterprise_name = 'The company name field is required.';
        }
        if (!form.data.sector.trim()) {
            errs.sector = 'The industry sector field is required.';
        }
        return errs;
    };

    const validateStepTwoClient = () => {
        const errs = {};
        if (!form.data.location.trim()) {
            errs.location = 'The office location field is required.';
        }
        if (!form.data.phone.trim()) {
            errs.phone = 'The phone number field is required.';
        }
        return errs;
    };

    const mergeServerValidationErrors = (errorBag) => {
        const out = {};
        if (!errorBag || typeof errorBag !== 'object') return out;
        for (const [key, messages] of Object.entries(errorBag)) {
            const msg = firstValidationMessage(messages);
            if (msg) out[key] = msg;
        }
        return out;
    };

    const continueStepOne = async () => {
        const clientErrs = validateStepOneClient();
        if (Object.keys(clientErrs).length > 0) {
            setStepErrors((prev) => ({ ...prev, ...clientErrs }));
            return;
        }

        setValidatingStep(true);
        try {
            await axios.post(VALIDATE_STEP_URL, {
                step: 1,
                contact_name: form.data.contact_name,
                enterprise_name: form.data.enterprise_name,
                sector: form.data.sector,
            });
            setStepErrors((prev) => {
                const next = { ...prev };
                delete next.contact_name;
                delete next.enterprise_name;
                delete next.sector;
                return next;
            });
            setStep(2);
        } catch (err) {
            if (err.response?.status === 422) {
                setStepErrors((prev) => ({
                    ...prev,
                    ...mergeServerValidationErrors(err.response.data?.errors),
                }));
            } else {
                console.error(err);
            }
        } finally {
            setValidatingStep(false);
        }
    };

    const continueStepTwo = async () => {
        const clientErrs = validateStepTwoClient();
        if (Object.keys(clientErrs).length > 0) {
            setStepErrors((prev) => ({ ...prev, ...clientErrs }));
            return;
        }

        setValidatingStep(true);
        try {
            await axios.post(VALIDATE_STEP_URL, {
                step: 2,
                location: form.data.location,
                phone: form.data.phone,
            });
            setStepErrors((prev) => {
                const next = { ...prev };
                delete next.location;
                delete next.phone;
                return next;
            });
            setStep(3);
        } catch (err) {
            if (err.response?.status === 422) {
                setStepErrors((prev) => ({
                    ...prev,
                    ...mergeServerValidationErrors(err.response.data?.errors),
                }));
            } else {
                console.error(err);
            }
        } finally {
            setValidatingStep(false);
        }
    };

    const validatePasswordsClient = () => {
        const errs = {};
        if (!form.data.current_password.trim()) {
            errs.current_password = 'The current password field is required.';
        }
        if (!form.data.password.trim()) {
            errs.password = 'The password field is required.';
        } else if (form.data.password.length < 8) {
            errs.password = 'The password must be at least 8 characters.';
        }
        if (!form.data.password_confirmation.trim()) {
            errs.password_confirmation = 'The password confirmation field is required.';
        } else if (form.data.password !== form.data.password_confirmation) {
            errs.password_confirmation = 'The password confirmation does not match.';
        }
        return errs;
    };

    const submit = (e) => {
        e.preventDefault();

        if (passwordChangeOnly) {
            const pwErrs = validatePasswordsClient();
            if (Object.keys(pwErrs).length > 0) {
                setStepErrors((prev) => ({ ...prev, ...pwErrs }));
                return;
            }
            setStepErrors((prev) => {
                const next = { ...prev };
                delete next.current_password;
                delete next.password;
                delete next.password_confirmation;
                return next;
            });
            form.post('/organisation/onboarding', { preserveScroll: true });
            return;
        }

        const pwErrs = validatePasswordsClient();
        if (Object.keys(pwErrs).length > 0) {
            setStepErrors((prev) => ({ ...prev, ...pwErrs }));
            return;
        }

        setStepErrors((prev) => {
            const next = { ...prev };
            delete next.current_password;
            delete next.password;
            delete next.password_confirmation;
            return next;
        });

        form.post('/organisation/onboarding', { preserveScroll: true });
    };

    useEffect(() => {
        const { errors } = form;
        if (errors.contact_name || errors.enterprise_name || errors.sector) {
            setStep(1);
        } else if (errors.location || errors.phone) {
            setStep(2);
        } else if (errors.current_password || errors.password || errors.password_confirmation) {
            setStep(3);
        }
    }, [form.errors]);

    const inputClass =
        'h-11 pl-9 bg-white dark:bg-white border-beta/15 text-beta placeholder:text-beta/35 focus-visible:border-alpha/60 focus-visible:ring-alpha/20 select-text';

    const stepTitle =
        step === 1
            ? 'Tell us about your company'
            : step === 2
                ? 'How can we reach you?'
                : 'Choose a new password';

    const stepDescription =
        step === 3
            ? passwordChangeOnly
                ? 'For your security, you must set a new password before continuing.'
                : 'Replace the temporary password from your invitation email with one only you know.'
            : null;

    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.35 }}
            className="min-h-svh bg-[#f8f8f5]"
        >
            <Head title={passwordChangeOnly ? 'Change your password' : 'Complete organisation profile'} />

            <main className="mx-auto flex max-w-xl flex-col gap-8 px-4 py-12">
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.35, delay: 0.05 }}
                    className="space-y-5"
                >
                    <motion.div
                        key={`header-${step}`}
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.25 }}
                        className="space-y-1 flex items-center gap-4"
                    >
                        <AppLogoIcon size={80} color="#212529" />
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-widest text-alpha">
                                Step {step} of {STEPS.length}
                            </p>
                            <h1 className="text-2xl font-bold text-beta">{stepTitle}</h1>
                            {stepDescription ? (
                                <p className="text-sm text-beta/50">{stepDescription}</p>
                            ) : (
                                <p className="text-sm text-beta/50">
                                    Signed in as <span className="font-medium text-beta/70">{organization?.email}</span>
                                </p>
                            )}
                        </div>
                    </motion.div>

                    {!passwordChangeOnly && (
                        <div className="flex gap-2">
                            {STEPS.map((s) => (
                                <div key={s.number} className="flex flex-1 flex-col gap-1.5">
                                    <div
                                        className={`h-1 rounded-full transition-all duration-500 ${s.number <= step ? 'bg-alpha' : 'bg-beta/12'
                                            }`}
                                    />
                                    <span
                                        className={`text-[11px] font-medium transition-colors ${s.number <= step ? 'text-alpha' : 'text-beta/30'
                                            }`}
                                    >
                                        {s.label}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </motion.div>

                <motion.div
                    initial={{ opacity: 0, y: 16 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.35, delay: 0.1 }}
                    className="rounded-2xl border border-beta/8 bg-white p-7 shadow-sm"
                >
                    <form onSubmit={submit} noValidate>
                        <AnimatePresence mode="wait">
                            {step === 1 ? (
                                <motion.div
                                    key="step1"
                                    initial={{ opacity: 0, x: 24 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    exit={{ opacity: 0, x: -24 }}
                                    transition={{ duration: 0.25 }}
                                    className="space-y-5"
                                >
                                    <Field id="contact_name" label="Contact name" error={fieldError('contact_name')} icon={User}>
                                        <Input
                                            id="contact_name"
                                            value={form.data.contact_name}
                                            onChange={(e) => updateField('contact_name', e.target.value)}
                                            placeholder="Jane Smith"
                                            className={inputClass}
                                        />
                                    </Field>

                                    <Field
                                        id="enterprise_name"
                                        label="Company name"
                                        error={fieldError('enterprise_name')}
                                        icon={Building2}
                                    >
                                        <Input
                                            id="enterprise_name"
                                            value={form.data.enterprise_name}
                                            onChange={(e) => updateField('enterprise_name', e.target.value)}
                                            placeholder="Acme Corp"
                                            className={inputClass}
                                        />
                                    </Field>

                                    <Field id="sector" label="Industry sector" error={fieldError('sector')} icon={Building2}>
                                        <Select
                                            value={form.data.sector}
                                            onValueChange={(val) => updateField('sector', val)}
                                        >
                                            <SelectTrigger
                                                id="sector"
                                                className="h-11 w-full border-beta/15 bg-white pl-9 text-sm text-black focus:border-alpha/60 focus:ring-alpha/20 data-[placeholder]:text-beta/35 dark:bg-white dark:text-black"
                                            >
                                                <SelectValue placeholder="Select a sector" />
                                            </SelectTrigger>
                                            <SelectContent className="bg-white text-black dark:bg-white dark:text-black">
                                                {SECTOR_OPTIONS.map((sector) => (
                                                    <SelectItem key={sector} value={sector}>
                                                        {sector}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                </motion.div>
                            ) : step === 2 ? (
                                <motion.div
                                    key="step2"
                                    initial={{ opacity: 0, x: 24 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    exit={{ opacity: 0, x: -24 }}
                                    transition={{ duration: 0.25 }}
                                    className="space-y-5"
                                >
                                    <Field id="location" label="Office location" error={fieldError('location')} icon={MapPin}>
                                        <Input
                                            id="location"
                                            value={form.data.location}
                                            onChange={(e) => updateField('location', e.target.value)}
                                            placeholder="Casablanca, Morocco"
                                            className={inputClass}
                                        />
                                    </Field>

                                    <Field id="phone" label="Phone number" error={fieldError('phone')} icon={Phone}>
                                        <Input
                                            id="phone"
                                            value={form.data.phone}
                                            onChange={(e) => updateField('phone', e.target.value)}
                                            placeholder="+212 6 00 00 00 00"
                                            className={inputClass}
                                        />
                                    </Field>
                                </motion.div>
                            ) : (
                                <motion.div
                                    key="step3"
                                    initial={{ opacity: 0, x: 24 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    exit={{ opacity: 0, x: -24 }}
                                    transition={{ duration: 0.25 }}
                                    className="space-y-5"
                                >
                                    <PasswordField
                                        id="current_password"
                                        label="Current password"
                                        error={fieldError('current_password')}
                                        value={form.data.current_password}
                                        onChange={(e) => updateField('current_password', e.target.value)}
                                        placeholder="Password from your invitation email"
                                        show={showCurrentPassword}
                                        onToggleShow={() => setShowCurrentPassword((v) => !v)}
                                        icon={KeyRound}
                                    />

                                    <PasswordField
                                        id="password"
                                        label="New password"
                                        error={fieldError('password')}
                                        value={form.data.password}
                                        onChange={(e) => updateField('password', e.target.value)}
                                        placeholder="Choose a strong password"
                                        show={showPassword}
                                        onToggleShow={() => setShowPassword((v) => !v)}
                                        icon={KeyRound}
                                    />

                                    {form.data.password && (
                                        <motion.div
                                            initial={{ opacity: 0, height: 0 }}
                                            animate={{ opacity: 1, height: 'auto' }}
                                            transition={{ duration: 0.2 }}
                                            className="space-y-2"
                                        >
                                            <motion.div
                                                initial={{ scaleX: 0 }}
                                                animate={{ scaleX: 1 }}
                                                transition={{ duration: 0.4 }}
                                                style={{ transformOrigin: 'left' }}
                                                className="h-1.5 w-full overflow-hidden rounded-full bg-beta/10"
                                            >
                                                <div
                                                    className={`h-full rounded-full transition-all duration-300 ${passwordStrength.color}`}
                                                    style={{ width: `${(passwordStrength.score / 5) * 100}%` }}
                                                />
                                            </motion.div>
                                            <p className="text-xs text-beta/45">Strength: {passwordStrength.label}</p>
                                        </motion.div>
                                    )}

                                    <PasswordField
                                        id="password_confirmation"
                                        label="Confirm new password"
                                        error={fieldError('password_confirmation')}
                                        value={form.data.password_confirmation}
                                        onChange={(e) => updateField('password_confirmation', e.target.value)}
                                        placeholder="Repeat your new password"
                                        show={showPasswordConfirmation}
                                        onToggleShow={() => setShowPasswordConfirmation((v) => !v)}
                                        icon={KeyRound}
                                    />
                                </motion.div>
                            )}
                        </AnimatePresence>

                        <div className="mt-7 flex items-center justify-between gap-3">
                            {step > 1 && !passwordChangeOnly ? (
                                <button
                                    type="button"
                                    onClick={() => setStep(step - 1)}
                                    className="text-sm font-medium text-beta/50 transition-colors hover:text-beta"
                                >
                                    ← Back
                                </button>
                            ) : (
                                <span />
                            )}

                            {step === 1 ? (
                                <Button
                                    type="button"
                                    disabled={validatingStep}
                                    onClick={continueStepOne}
                                    className="h-11 min-w-36 bg-alpha font-semibold text-beta shadow-none hover:bg-alpha/90 disabled:opacity-60"
                                >
                                    {validatingStep ? (
                                        <span className="flex items-center gap-2">
                                            <span className="h-4 w-4 animate-spin rounded-full border-2 border-beta/30 border-t-beta" />
                                            Checking…
                                        </span>
                                    ) : (
                                        'Continue →'
                                    )}
                                </Button>
                            ) : step === 2 ? (
                                <Button
                                    type="button"
                                    disabled={validatingStep}
                                    onClick={continueStepTwo}
                                    className="h-11 min-w-36 bg-alpha font-semibold text-beta shadow-none hover:bg-alpha/90 disabled:opacity-60"
                                >
                                    {validatingStep ? (
                                        <span className="flex items-center gap-2">
                                            <span className="h-4 w-4 animate-spin rounded-full border-2 border-beta/30 border-t-beta" />
                                            Checking…
                                        </span>
                                    ) : (
                                        'Continue →'
                                    )}
                                </Button>
                            ) : (
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="h-11 min-w-44 bg-alpha font-semibold text-beta shadow-none hover:bg-alpha/90 disabled:opacity-60"
                                >
                                    {form.processing ? (
                                        <span className="flex items-center gap-2">
                                            <span className="h-4 w-4 animate-spin rounded-full border-2 border-beta/30 border-t-beta" />
                                            Saving…
                                        </span>
                                    ) : passwordChangeOnly ? (
                                        'Update password →'
                                    ) : (
                                        'Complete setup →'
                                    )}
                                </Button>
                            )}
                        </div>
                    </form>
                </motion.div>

                {!passwordChangeOnly && (
                    <p className="text-center text-xs text-beta/35">
                        You can update these details later from your organisation settings.
                    </p>
                )}
            </main>
        </motion.div>
    );
}
