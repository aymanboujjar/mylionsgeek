import { Avatar } from '@/components/ui/avatar';
import { Link } from '@inertiajs/react';
import { Image } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import CreatePostModal from '../../../../../components/post/CreatePostModal';
import PostCard from '../../../../../components/post/PostCard';

function parseFeedPostIdFromHash() {
    const raw = window.location.hash.replace(/^#/, '');
    if (!raw) {
        return null;
    }
    if (raw.startsWith('post-')) {
        const id = parseInt(raw.slice('post-'.length), 10);
        return Number.isNaN(id) ? null : id;
    }
    if (/^\d+$/.test(raw)) {
        const id = parseInt(raw, 10);
        return Number.isNaN(id) ? null : id;
    }
    return null;
}

export default function CenterFeed({
    user,
    posts,
    showComposer = true,
    lead = null,
    displayAddPost = true,
    profileBackHref = null,
    feedNextCursor = null,
    feedHasMore = false,
    enableInfiniteScroll = false,
}) {
    const profileHref = profileBackHref ?? `/students/${user.id}`;
    const [openAddPost, setOpenAddPost] = useState(false);
    const [openModalPostIdFromHash, setOpenModalPostIdFromHash] = useState(null);

    useEffect(() => {
        const syncFromHash = () => {
            setOpenModalPostIdFromHash(parseFeedPostIdFromHash());
        };
        syncFromHash();
        window.addEventListener('hashchange', syncFromHash);
        return () => window.removeEventListener('hashchange', syncFromHash);
    }, []);

    const clearFeedPostHash = useCallback(() => {
        setOpenModalPostIdFromHash(null);
        if (window.location.hash) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    }, []);
    return (
        <>
            {/* Center Feed - Scrollable */}
            <div className="space-y-4 lg:col-span-9">
                {lead}
                {showComposer && displayAddPost && (
                    <div className="rounded-lg bg-white p-4 shadow shadow-alpha/10 dark:bg-dark_gray">
                        <div className="mb-4 flex items-center gap-3">
                            <Link href={profileHref}>
                                <Avatar
                                    className="h-12 w-12 overflow-hidden rounded-full"
                                    image={user?.image}
                                    name={user?.name}
                                    lastActivity={user?.last_login ?? user?.last_online ?? user?.last_activity ?? null}
                                    onlineCircleClass="hidden"
                                />
                            </Link>
                            <button
                                type="button"
                                onClick={() => setOpenAddPost(true)}
                                className="flex-1 cursor-pointer rounded-lg border-2 border-beta bg-transparent px-4 py-2 text-left text-foreground/70 opacity-80 transition-all duration-300 hover:bg-beta/5 dark:border-alpha/80 dark:hover:bg-light/5"
                            >
                                Whats on your mind today ....
                            </button>
                        </div>
                        <div className="flex justify-around">
                            <button
                                type="button"
                                onClick={() => setOpenAddPost(true)}
                                className="flex items-center gap-2 rounded-full px-4 py-2 hover:bg-dark/5 dark:hover:bg-light/5"
                            >
                                <Image className="h-5 w-5 text-beta dark:text-alpha" />
                                <span className="text-sm text-beta dark:text-alpha">Media</span>
                            </button>
                        </div>
                    </div>
                )}

                {/* Post Card */}

                <PostCard
                    user={user}
                    posts={posts}
                    openModalPostId={openModalPostIdFromHash}
                    onConsumedHashModal={clearFeedPostHash}
                    feedNextCursor={feedNextCursor}
                    feedHasMore={feedHasMore}
                    enableInfiniteScroll={enableInfiniteScroll}
                />
                {/* <div className="bg-white dark:bg-gray-800 rounded-lg shadow"> */}
                {/* Post Header */}
                {/* <div className="p-4">
                        <div className="flex items-start justify-between">
                            <div className="flex items-start gap-3">
                                <div className="w-12 h-12 rounded-full bg-gradient-to-br from-orange-400 to-pink-500 flex items-center justify-center text-white text-lg font-bold">
                                    MC
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h3 className="font-semibold text-gray-900 dark:text-white">Mohamed Camara</h3>
                                        <span className="text-gray-500 dark:text-gray-400">• 3rd</span>
                                    </div>
                                    <p className="text-xs text-gray-600 dark:text-gray-400">
                                        Full Stack Developer | Software Engineer | Entrepreneur
                                    </p>
                                    <p className="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                        2d • 🌍
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <button className="text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded">
                                    <MoreHorizontal className="w-5 h-5" />
                                </button>
                                <button className="text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded">
                                    <X className="w-5 h-5" />
                                </button>
                            </div>
                        </div> */}

                {/* Post Content */}
                {/* <div className="mt-3">
                            <p className="text-gray-800 dark:text-gray-200 text-sm leading-relaxed">
                                Développeur web junior sans emploi 😔 ! <span className="text-blue-600">#Recrutement</span> <span className="text-blue-600">#Stage</span> <span className="text-blue-600">#Freelance</span> <span className="text-blue-600">#EmploiMaroc</span> <span className="text-blue-600">#DéveloppeurWeb</span> <span className="text-blue-600">#CodeurMaroc</span> <span className="text-blue-600">#FullStack</span> <span className="text-blue-600">#ReactJS</span> <span className="text-blue-600">#NodeJS</span> <span className="text-blue-600">#RechercheEmploi</span> <span className="text-blue-600">#TechMaroc</span>
                            </p>
                            <button className="text-blue-600 dark:text-blue-400 text-sm font-medium mt-2">
                                Show translation
                            </button>
                        </div> */}
                {/* </div> */}

                {/* Embedded Document/Resume */}
                {/* <div className="px-4 pb-4">
                        <div className="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <div className="bg-gray-50 dark:bg-gray-900 p-3 border-b border-gray-200 dark:border-gray-700">
                                <p className="text-sm text-gray-700 dark:text-gray-300">
                                    Développeur junior sans emploi • 2 pages
                                </p>
                            </div>
                            <div className="bg-white dark:bg-gray-800 p-6 max-h-96 overflow-y-auto">
                                <div className="text-center mb-6">
                                    <h2 className="text-2xl font-bold text-gray-900 dark:text-white">MOHAMED CAMARA</h2>
                                    <div className="text-sm text-gray-600 dark:text-gray-400 mt-2 space-y-1">
                                        <p>Développeur Full Stack Junior en recherche active</p>
                                        <p>📧 mohamed.camara@email.com • 📱 +212 6XX XX XX XX</p>
                                        <p>📍 Casablanca, Maroc • GitHub • Portfolio</p>
                                    </div>
                                </div>

                                <div className="space-y-6 text-left">
                                    <div>
                                        <h3 className="font-bold text-gray-900 dark:text-white mb-2 text-sm">PROFIL</h3>
                                        <p className="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                                            Jeune diplômé passionné par le développement web avec une expertise en React.js, Node.js et bases de données modernes. Je recherche activement une opportunité pour débuter ma carrière professionnelle dans des équipes dynamiques où je pourrai contribuer avec mes compétences techniques tout en continuant à apprendre et évoluer.
                                        </p>
                                    </div>

                                    <div>
                                        <h3 className="font-bold text-gray-900 dark:text-white mb-2 text-sm">COMPÉTENCES TECHNIQUES</h3>
                                        <div className="space-y-2 text-xs text-gray-700 dark:text-gray-300">
                                            <p><strong>Langages:</strong> JavaScript (ES6+), TypeScript, HTML5, CSS3, Python, Node.js, Express</p>
                                            <p><strong>Frameworks & Bibliothèques:</strong> React.js, Next.js, Vue.js, Tailwind CSS, Bootstrap, Redux</p>
                                            <p><strong>Bases de données:</strong> MongoDB, MySQL, PostgreSQL, Firebase</p>
                                            <p><strong>Outils:</strong> Git, GitHub, VS Code, Figma, Postman, npm, Webpack</p>
                                        </div>
                                    </div>

                                    <div>
                                        <h3 className="font-bold text-gray-900 dark:text-white mb-2 text-sm">PROJETS ACADÉMIQUES</h3>
                                        <div className="space-y-3 text-xs">
                                            <div>
                                                <p className="font-semibold text-gray-900 dark:text-white">Plateforme E-commerce (Développement complet full stack)</p>
                                                <p className="text-gray-700 dark:text-gray-300">Application web permettant de gérer un catalogue de produits avec panier, authentification et paiement en ligne. Technologies: React.js, Node.js, Express, MongoDB, Stripe API</p>
                                            </div>
                                            <div>
                                                <p className="font-semibold text-gray-900 dark:text-white">Application de Gestion de Tâches (Task Manager)</p>
                                                <p className="text-gray-700 dark:text-gray-300">Interface moderne pour la gestion de projets avec système d'authentification JWT.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="bg-gray-50 dark:bg-gray-900 px-4 py-3 text-right">
                                <span className="text-xs text-gray-500 dark:text-gray-400">1 / 2 pages</span>
                            </div>
                        </div>
                    </div> */}

                {/* Post Stats */}
                {/* <div className="px-4 pb-3">
                        <div className="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                            <div className="flex items-center gap-1">
                                <div className="flex -space-x-1">
                                    <div className="w-4 h-4 rounded-full bg-blue-500 border border-white dark:border-gray-800 flex items-center justify-center">
                                        <ThumbsUp className="w-2.5 h-2.5 text-white" />
                                    </div>
                                </div>
                                <span className="ml-1">18</span>
                            </div>
                            <div className="flex gap-3">
                                <span>5 comments</span>
                                <span>3 reposts</span>
                            </div>
                        </div>
                    </div> */}

                {/* Action Buttons */}
                {/* <div className="border-t border-gray-200 dark:border-gray-700 px-2 py-1">
                        <div className="flex justify-around">
                            <button className="flex items-center gap-2 px-4 py-2 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition-colors">
                                <ThumbsUp className="w-5 h-5" />
                                <span className="text-sm font-medium">Like</span>
                            </button>
                            <button className="flex items-center gap-2 px-4 py-2 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition-colors">
                                <MessageSquare className="w-5 h-5" />
                                <span className="text-sm font-medium">Comment</span>
                            </button>
                            <button className="flex items-center gap-2 px-4 py-2 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition-colors">
                                <Repeat2 className="w-5 h-5" />
                                <span className="text-sm font-medium">Repost</span>
                            </button>
                            <button className="flex items-center gap-2 px-4 py-2 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition-colors">
                                <Send className="w-5 h-5" />
                                <span className="text-sm font-medium">Send</span>
                            </button>
                        </div>
                    </div> */}
                {/* </div> */}

                {/* Additional Posts Placeholder */}
                {/* <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6 text-center text-gray-500 dark:text-gray-400">
                    <p>More posts would appear here...</p>
                </div> */}
                {showComposer && openAddPost && <CreatePostModal user={user} onOpen={openAddPost} onOpenChange={setOpenAddPost} />}
            </div>
        </>
    );
}
