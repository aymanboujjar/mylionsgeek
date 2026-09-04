import { TrendingUp } from 'lucide-react';

const RightSideBar = () => {
    return (
        <>
            {/* Right Sidebar - Fixed */}
            <div className="sticky top-0 h-fit space-y-4 lg:col-span-3">
                {/* News Section */}
                <div className="rounded-lg bg-white p-4 shadow dark:bg-[#101112]">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Community News</h3>
                        <TrendingUp className="h-4 w-4 text-gray-500" />
                    </div>
                    <div className="space-y-3">
                        <div>
                            <h4 className="cursor-pointer text-sm font-medium text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400">
                                Tech Industry Updates
                            </h4>
                            <p className="text-xs text-gray-500 dark:text-gray-400">2d ago • 1,234 readers</p>
                        </div>
                        <div>
                            <h4 className="cursor-pointer text-sm font-medium text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400">
                                Web Development Trends
                            </h4>
                            <p className="text-xs text-gray-500 dark:text-gray-400">5d ago • 890 readers</p>
                        </div>
                        <div>
                            <h4 className="cursor-pointer text-sm font-medium text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400">
                                Job Market Insights
                            </h4>
                            <p className="text-xs text-gray-500 dark:text-gray-400">1w ago • 2,456 readers</p>
                        </div>
                    </div>
                </div>

                {/* Suggestions */}
                <div className="hidden rounded-lg bg-white p-4 shadow lg:block dark:bg-[#101112]">
                    <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-white">Add to your feed</h3>
                    <div className="space-y-3">
                        <div className="flex items-start gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-purple-400 to-blue-500 text-sm font-bold text-white">
                                DT
                            </div>
                            <div className="flex-1">
                                <h4 className="text-sm font-medium text-gray-900 dark:text-white">Dr. Tech Insights</h4>
                                <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">Technology & Innovation</p>
                                <button className="rounded-full border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 transition-colors hover:border-gray-900 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-400">
                                    + Follow
                                </button>
                            </div>
                        </div>
                        <div className="flex items-start gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-green-400 to-teal-500 text-sm font-bold text-white">
                                WD
                            </div>
                            <div className="flex-1">
                                <h4 className="text-sm font-medium text-gray-900 dark:text-white">Web Dev Daily</h4>
                                <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">Web Development Tips</p>
                                <button className="rounded-full border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 transition-colors hover:border-gray-900 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-400">
                                    + Follow
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
};

export default RightSideBar;
