import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { ArrowRight, Medal, Trophy } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function GeekoWaiting({ session, participant, leaderboard = [] }) {
    const [liveData, setLiveData] = useState({
        session_status: session.status,
        current_question_index: session.current_question_index,
        total_questions: session.geeko?.questions?.length || 0,
        leaderboard: leaderboard,
    });

    useEffect(() => {
        const interval = setInterval(async () => {
            try {
                const res = await fetch(`/geeko/play/${session.id}/live-data`);
                const data = await res.json();
                setLiveData((prev) => ({ ...prev, ...data }));

                if (data.session_status === 'in_progress' && data.current_question_index !== session.current_question_index) {
                    router.visit(`/geeko/play/${session.id}/question`);
                }
                if (data.session_status === 'completed') {
                    router.visit(`/geeko/play/${session.id}/completed`);
                }
            } catch (e) {
                // silent
            }
        }, 2000);

        return () => clearInterval(interval);
    }, [session.id, session.current_question_index]);

    const top = (liveData.leaderboard || []).slice(0, 10);

    return (
        <AppLayout>
            <Head title="Between Rounds" />
            <div className="min-h-screen bg-gradient-to-b from-alpha/5 to-transparent p-6 dark:from-alpha/10 dark:to-transparent">
                <div className="mx-auto max-w-4xl text-center">
                    <h1 className="mb-2 text-2xl font-extrabold text-dark md:text-3xl dark:text-light">Round Results</h1>
                    <p className="mb-8 text-dark/60 dark:text-light/60">Waiting for the instructor to move to the next question...</p>

                    <div className="rounded-2xl border border-white/20 bg-white/60 p-6 shadow-xl backdrop-blur-xl dark:bg-dark/50">
                        <div className="mb-4 flex items-center justify-between">
                            <div className="text-left">
                                <div className="text-sm text-dark/60 dark:text-light/60">Progress</div>
                                <div className="text-lg font-semibold text-dark dark:text-light">
                                    Q{liveData.current_question_index + 1}/{liveData.total_questions}
                                </div>
                            </div>
                            <div className="text-right">
                                <div className="text-sm text-dark/60 dark:text-light/60">Players</div>
                                <div className="text-lg font-semibold text-dark dark:text-light">{liveData.participants_count ?? '-'}</div>
                            </div>
                        </div>

                        <div className="space-y-3">
                            {top.length === 0 ? (
                                <div className="py-8 text-dark/60 dark:text-light/60">Waiting for scores...</div>
                            ) : (
                                top.map((p, idx) => (
                                    <div
                                        key={p.id || idx}
                                        className={`flex items-center justify-between rounded-xl border transition-all duration-300 ${idx === 0 ? 'scale-[1.01] border-alpha/40 bg-alpha/30 shadow-md' : 'border-white/20 bg-dark_gray0 dark:bg-dark/40'} animate-[fadeIn_400ms_ease] p-4`}
                                        style={{ animationDelay: `${idx * 70}ms` }}
                                    >
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`flex h-10 w-10 items-center justify-center rounded-lg ${idx === 0 ? 'bg-alpha text-black' : 'bg-white/70 text-black/80 dark:bg-dark/60 dark:text-black/80'}`}
                                            >
                                                {idx === 0 ? (
                                                    <Trophy size={20} />
                                                ) : idx === 1 ? (
                                                    <Medal size={20} />
                                                ) : idx === 2 ? (
                                                    <Medal size={20} />
                                                ) : (
                                                    <span className="font-bold">{idx + 1}</span>
                                                )}
                                            </div>
                                            <div className="text-left">
                                                <div className="text-sm font-semibold text-dark dark:text-light">{p.user?.name || p.nickname}</div>
                                                <div className="text-xs text-dark/60 dark:text-light/60">
                                                    Correct: {p.correct_answers} • Wrong: {p.wrong_answers}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <div className="text-xl font-extrabold text-dark dark:text-light">{p.total_score}</div>
                                            <div className="text-xs text-dark/60 dark:text-light/60">points</div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>

                        <div className="mt-6 flex items-center justify-center gap-2 text-dark/60 dark:text-light/60">
                            <span>Next question will start soon</span>
                            <ArrowRight size={16} />
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
