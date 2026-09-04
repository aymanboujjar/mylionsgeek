import logo from '../../../../../../public/assets/images/logolionsgeek.png';

const Footer = () => {
    return (
        <>
            <div className="border-t border-neutral-200 bg-white py-12 transition-colors duration-300 dark:border-neutral-800 dark:bg-neutral-900">
                <div className="mx-auto w-full px-4 md:max-w-7xl">
                    {/* Footer Content */}
                    <div className="flex flex-col items-center justify-between gap-6 md:flex-row">
                        {/* Left Section */}
                        <div className="flex flex-col items-center gap-3 lg:flex-row">
                            <div className="flex flex-col items-center gap-2 lg:flex-row">
                                <img src={logo} alt="LionsGeek Logo" className="h-8 w-8 rounded-md object-contain p-1 dark:invert" />
                                <span className="text-lg font-semibold tracking-tight text-neutral-900 dark:text-white">LionsGeek</span>
                            </div>
                            <span className="ml-3 text-sm text-neutral-600 dark:text-neutral-400">
                                © {new Date().getFullYear()} LionsGeek. All rights reserved.
                            </span>
                        </div>

                        {/* Social Links */}
                        <div className="flex gap-6">
                            {[
                                { name: 'Instagram', href: 'https://www.instagram.com/lions_geek/' },
                                { name: 'YouTube', href: 'https://www.youtube.com/@lionsgeek_MA' },
                            ].map((link) => (
                                <a
                                    key={link.name}
                                    href={link.href}
                                    target="_blank"
                                    className="text-sm font-medium text-neutral-600 transition-colors duration-300 hover:text-alpha dark:text-neutral-400 dark:hover:text-amber-300"
                                >
                                    {link.name}
                                </a>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
};

export default Footer;
