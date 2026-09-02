import { PodiumSkeleton } from '@/components/LoadingSkeleton';
import { Avatar } from '@/components/ui/avatar';
import { AwardIcon, CrownIcon, MedalIcon } from 'lucide-react';

const BoardPodium = ({ topWinners, isRefreshing, fetchLeaderboardData, getRankBadge, formatTime, handleUserClick }) => {
    const podiumRanks = [1, 0, 2]; // Display order (2nd, 1st, 3rd)

    const podiumConfig = {
        1: {
            baseGradient: 'bg-gradient-to-b from-[#f5cc58] via-[#f3a81c] to-[#8f4f05]',
            glow: 'shadow-[0_20px_45px_rgba(243,168,28,0.55)]',
            accent: 'text-[#ffe3a7]',
            height: 'h-[100px] sm:h-[170px]',
            labelColor: 'text-[#fce19d]',
            pillBg: 'bg-[#2f1a02]/70',
            iconBg: 'bg-[#fddc88]/55',
            avatarRing: 'ring-4 ring-[#f7c55f]/40',
            offset: 'lg:-mt-6',
            bg: 'bg-[#fddc88]',
        },
        2: {
            baseGradient: 'bg-gradient-to-b from-[#56f2b7] via-[#1a936f] to-[#063329]',
            glow: 'shadow-[0_18px_40px_rgba(86,242,183,0.35)]',
            accent: 'text-[#9bfad7]',
            height: 'h-[110px] sm:h-[150px]',
            labelColor: 'text-[#83f0c8]',
            pillBg: 'bg-[#052620]/70',
            iconBg: 'bg-[#83f0c8]/55',
            avatarRing: 'ring-4 ring-[#63f5c5]/30',
            offset: 'lg:mt-0',
            bg: 'bg-[#83f0c8]',
        },
        3: {
            baseGradient: 'bg-gradient-to-b from-[#4fe8ac] via-[#0f7a59] to-[#05251b]',
            glow: 'shadow-[0_16px_35px_rgba(79,232,172,0.3)]',
            accent: 'text-[#8cf7cf]',
            height: 'h-[120px] sm:h-[150px]',
            labelColor: 'text-[#79eabf]',
            pillBg: 'bg-[#041f19]/70',
            iconBg: 'bg-[#8cf7cf]/55',
            avatarRing: 'ring-4 ring-[#4fe8ac]/25',
            offset: 'lg:mt-4',
            bg: 'bg-[#83f0c8]',
        },
    };

    const getIcon = (rank) => {
        if (rank === 1) {
            return <CrownIcon className="h-12 w-12 text-[#fcd575] drop-shadow-[0_0_35px_rgba(252,213,117,0.8)]" />;
        }
        if (rank === 2) {
            return <MedalIcon className="h-10 w-10 text-[#7ef4cc] drop-shadow-[0_0_30px_rgba(126,244,204,0.6)]" />;
        }
        return <AwardIcon className="h-10 w-10 text-[#7ef4cc] drop-shadow-[0_0_30px_rgba(126,244,204,0.6)]" />;
    };

    return (
        <>
            {topWinners.length > 0 && (
                <div className="mb-10">
                    {isRefreshing ? (
                        <PodiumSkeleton fetchLeaderboardData={fetchLeaderboardData} isRefreshing={isRefreshing} />
                    ) : (
                        <div className="relative overflow-hidden bg-transparent px-2 py-20 sm:px-10">
                            {/* Podium */}
                            {/* Podium */}
                            <div className="relative z-10 grid grid-cols-3 place-items-center gap-3 sm:gap-6 lg:gap-8">
                                {podiumRanks.map((rankIndex) => {
                                    const winner = topWinners[rankIndex];
                                    if (!winner) return null;

                                    const actualRank = rankIndex + 1;
                                    const isCenter = actualRank === 1;
                                    const config = podiumConfig[actualRank] || podiumConfig[2];
                                    const formattedScore = formatTime ? formatTime(winner.data?.data?.total_seconds) : '--';
                                    const badge = getRankBadge ? getRankBadge(winner.data?.data?.total_seconds || 0) : null;

                                    return (
                                        <div
                                            key={winner.user?.id || actualRank}
                                            className={`flex cursor-pointer flex-col items-center gap-4 text-center transition-transform duration-300 hover:-translate-y-2 ${isCenter ? 'scale-100' : 'scale-75'} sm:scale-100`}
                                            onClick={() => handleUserClick(winner)}
                                        >
                                            {/* Avatar + Icon */}
                                            <div className="relative flex flex-col items-center">
                                                {/* Icon above avatar */}
                                                <div className="absolute -top-5 z-20 sm:-top-6">
                                                    <div
                                                        className={`flex h-12 w-12 items-center justify-center rounded-full sm:h-16 sm:w-16 ${config.iconBg} backdrop-blur-sm`}
                                                    >
                                                        {getIcon(actualRank)}
                                                    </div>
                                                </div>

                                                {/* Avatar */}
                                                <div
                                                    className={`relative z-10 h-20 w-20 overflow-hidden rounded-full bg-dark_gray ring-offset-4 ring-offset-[#dadee7] sm:h-32 sm:w-32 dark:ring-offset-[#050d1b] ${config.avatarRing} shadow-[0_0_25px_rgba(0,0,0,0.4)]`}
                                                >
                                                    <Avatar
                                                        className="h-full w-full rounded-full object-cover"
                                                        image={winner.user?.image}
                                                        name={winner.user?.name}
                                                        lastActivity={winner.user?.last_login ?? winner.user?.last_online ?? winner.user?.last_activity ?? null}
                                                        onlineCircleClass="hidden"
                                                    />
                                                </div>
                                            </div>

                                            {/* Podium Base */}
                                            <div
                                                className={`relative w-34 sm:w-64 ${config.height} ${config.baseGradient} ${config.glow} flex flex-col items-center justify-center rounded-[26px] px-3 py-2 sm:px-6 sm:py-2`}
                                            >
                                                <div className="absolute inset-x-6 top-4 h-10 bg-white/10 blur-2xl sm:blur-3xl" />
                                                <div className="absolute inset-0 rounded-[26px] border border-dark_gray" />

                                                {/* Rank Circle */}
                                                <div
                                                    className={`absolute -top-8 flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold text-white sm:-top-11 sm:h-12 sm:w-12 sm:text-lg ${config.bg} ${config.rankCircleBg} z-20`}
                                                >
                                                    {actualRank}
                                                </div>

                                                {/* Name */}
                                                <div className="relative z-10 mb-2 sm:mb-4">
                                                    <h3 className="max-w-[5rem] truncate text-sm font-semibold text-white sm:max-w-[12rem] sm:text-lg">
                                                        {winner.user?.name || 'Unknown'}
                                                    </h3>
                                                </div>

                                                {/* Score */}
                                                <div className="relative z-10">
                                                    <p className={`text-xl font-bold text-white sm:text-3xl drop-shadow-xl${config.accent}`}>
                                                        {formattedScore}
                                                    </p>
                                                </div>

                                                {/* Badge */}
                                                {badge && (
                                                    <div
                                                        className={`relative z-10 mt-3 w-full rounded-[20px] sm:mt-5 sm:rounded-[26px] ${config.pillBg} py-1 text-[0.55rem] font-semibold tracking-widest text-white uppercase sm:py-2 sm:text-xs`}
                                                    >
                                                        {badge}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </>
    );
};

export default BoardPodium;
