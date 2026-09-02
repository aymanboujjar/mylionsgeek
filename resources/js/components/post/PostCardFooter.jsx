import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { subscribeToChannel } from '../../lib/ablyManager';
import LikesModal from './LikesModal';
import RepostModal from './RepostModal';
import SendPostModal from './SendPostModal';

const POST_LIKE_TOGGLED_EVENT = 'post-like-toggled';
const POST_REPOST_TOGGLED_EVENT = 'post-repost-toggled';

const LionsGeekLogoIcon = ({ className, size = 20 }) => {
    return (
        <svg
            className={className}
            version="1.0"
            xmlns="http://www.w3.org/2000/svg"
            width={size}
            height={size}
            viewBox="0 0 301.000000 302.000000"
            preserveAspectRatio="xMidYMid meet"
        >
            <g transform="translate(0.000000,302.000000) scale(0.100000,-0.100000)" fill="currentColor" stroke="none">
                <path d="M705 3008 c-41 -120 -475 -1467 -475 -1474 1 -9 1238 -910 1257 -916 6 -2 294 203 640 454 l631 458 -84 257 c-46 142 -154 477 -241 745 l-158 488 -783 0 c-617 0 -784 -3 -787 -12z m1265 -412 c0 -3 65 -205 145 -451 80 -245 145 -448 145 -450 0 -2 -173 -130 -384 -283 l-384 -280 -384 279 c-283 207 -382 284 -380 297 5 22 283 875 289 885 4 7 953 10 953 3z" />
                <path d="M1176 1661 c21 -15 101 -74 178 -130 l139 -101 31 23 c17 13 92 68 166 122 74 54 139 102 144 106 6 5 -145 9 -344 9 l-354 0 40 -29z" />
            </g>
        </svg>
    );
};

const PostCardFooter = ({ user, post, takeToUserProfile, PostModal = true, onCommentPress, variant = 'default' }) => {
    const { auth } = usePage().props;
    const isFacebook = variant === 'facebook';
    const [likesCountMap, setLikesCountMap] = useState({});
    const [commentsCountMap, setCommentsCountMap] = useState({});
    const [repostsCountMap, setRepostsCountMap] = useState({});
    const [likedInteractionPostIds, setLikedInteractionPostIds] = useState([]);
    const [likesOpenFor, setLikesOpenFor] = useState(null);
    const [repostOpenFor, setRepostOpenFor] = useState(null);
    const [sendOpenFor, setSendOpenFor] = useState(null);
    const [isReposted, setIsReposted] = useState(false);

    // Initialize counts and liked state on mount
    useEffect(() => {
        setLikesCountMap((prev) => ({ ...prev, [post.id]: post.likes_count }));
        setCommentsCountMap((prev) => ({ ...prev, [post.id]: post.comments_count }));
        setRepostsCountMap((prev) => ({ ...prev, [post.id]: post.reposts_count }));
        setIsReposted(Boolean(post.is_reposted_by_current_user));

        if (post.is_liked_by_current_user) {
            const interactionPostId = post?.interaction_post_id ?? post?.id;
            setLikedInteractionPostIds((prev) => [...new Set([...prev, interactionPostId])]);
        }
    }, [post.id, post.likes_count, post.comments_count, post.reposts_count, post.is_liked_by_current_user, post.is_reposted_by_current_user]);

    // Keep liked state in sync across multiple cards that reference the same interaction post
    // (e.g. a repost card + the original card).
    useEffect(() => {
        const handler = (event) => {
            const detail = event?.detail || {};
            const interactionId = Number(detail?.interaction_post_id);
            const liked = Boolean(detail?.liked);
            const myInteractionId = Number(post?.interaction_post_id ?? post?.id);

            if (!interactionId || interactionId !== myInteractionId) return;

            setLikedInteractionPostIds((prev) => (liked ? [...new Set([...prev, interactionId])] : prev.filter((id) => Number(id) !== interactionId)));
        };

        window.addEventListener(POST_LIKE_TOGGLED_EVENT, handler);
        return () => window.removeEventListener(POST_LIKE_TOGGLED_EVENT, handler);
    }, [post?.interaction_post_id, post?.id]);

    useEffect(() => {
        const handler = (event) => {
            const detail = event?.detail || {};
            const interactionId = Number(detail?.interaction_post_id);
            const reposted = Boolean(detail?.reposted);
            const myInteractionId = Number(post?.interaction_post_id ?? post?.id);

            if (!interactionId || interactionId !== myInteractionId) return;

            setIsReposted(reposted);
        };

        window.addEventListener(POST_REPOST_TOGGLED_EVENT, handler);
        return () => window.removeEventListener(POST_REPOST_TOGGLED_EVENT, handler);
    }, [post?.interaction_post_id, post?.id]);

    useEffect(() => {
        if (!post?.id) return;

        let mounted = true;
        let unsubscribe = null;

        const setup = async () => {
            // Only use Ably real-time updates, no polling
            unsubscribe = await subscribeToChannel('feed:global', 'post-stats-updated', (data) => {
                if (!mounted) return;
                const interactionPostId = post?.interaction_post_id ?? post?.id;
                if (!data || Number(data.post_id) !== Number(interactionPostId)) return;

                if (typeof data.likes_count === 'number') {
                    setLikesCountMap((prev) => ({ ...prev, [post.id]: data.likes_count }));
                }
                if (typeof data.comments_count === 'number') {
                    setCommentsCountMap((prev) => ({ ...prev, [post.id]: data.comments_count }));
                }
                if (typeof data.reposts_count === 'number') {
                    setRepostsCountMap((prev) => ({ ...prev, [post.id]: data.reposts_count }));
                }
            });
        };

        setup();

        return () => {
            mounted = false;
            if (unsubscribe) {
                unsubscribe();
            }
        };
    }, [post.id]);

    const toggleLike = async (postId) => {
        try {
            const response = await axios.post(`/posts/likes/${postId}`);
            const { liked, likes_count } = response.data;

            // Update likedPostIds based on backend response
            setLikedInteractionPostIds((prev) => (liked ? [...new Set([...prev, postId])] : prev.filter((id) => id !== postId)));
            window.dispatchEvent(new CustomEvent(POST_LIKE_TOGGLED_EVENT, { detail: { interaction_post_id: postId, liked } }));

            setLikesCountMap((prev) => ({
                ...prev,
                [postId]: likes_count,
            }));
        } catch (error) {
            console.error('Error toggling like:', error);
        }
    };

    const handleCommentAdded = (postId) => {
        setCommentsCountMap((prev) => ({
            ...prev,
            [postId]: (prev[postId] || 0) + 1,
        }));
    };

    const handleCommentRemoved = (postId) => {
        setCommentsCountMap((prev) => ({
            ...prev,
            [postId]: Math.max((prev[postId] || 1) - 1, 0),
        }));
    };

    const interactionPostId = post?.interaction_post_id ?? post?.id;
    const isLiked = likedInteractionPostIds.includes(interactionPostId);
    const likeCount = likesCountMap[post?.id] ?? 0;
    const commentCount = commentsCountMap[post?.id] ?? 0;
    const repostCount = repostsCountMap[post?.id] ?? 0;
    const canRepost = post?.can_repost !== false && post?.type !== 'repost';

    const handleRepostStateChange = (reposted) => {
        setIsReposted(reposted);
        window.dispatchEvent(
            new CustomEvent(POST_REPOST_TOGGLED_EVENT, {
                detail: { interaction_post_id: interactionPostId, reposted },
            }),
        );
    };

    return (
        <>
            {/* Counts */}
            <div
                className={
                    isFacebook
                        ? 'flex items-center justify-between border-b border-border/60 px-4 py-2 text-[13px] text-muted-foreground dark:border-white/10 dark:text-light/60'
                        : `flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-dark_gray/70 ${!PostModal && 'dark:bg-dark'}`
                }
            >
                <div
                    className={`cursor-pointer hover:underline ${isFacebook ? '' : 'text-xs text-gray-600 dark:text-gray-400'}`}
                    onClick={() => setLikesOpenFor(interactionPostId)}
                >
                    {likeCount} {isFacebook ? 'likes' : 'Likes'}
                </div>
                <div className="flex items-center gap-2">
                    <div
                        onClick={() => onCommentPress?.()}
                        className="cursor-pointer text-xs text-gray-600 hover:underline dark:text-gray-400"
                        role="button"
                        tabIndex={0}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                onCommentPress?.();
                            }
                        }}
                    >
                        {commentCount} {isFacebook ? 'comments' : 'Comments'}
                    </div>
                    <div className="text-xs text-gray-600 dark:text-gray-400">
                        <span className="text-xs text-gray-600 dark:text-gray-400">  • </span> {repostCount} {isFacebook ? 'reposts' : 'Reposts'}
                    </div>

                </div>

            </div>

            {/* Buttons */}
            {!auth?.user?.role?.includes('recruiter') && (
                <div
                    className={
                        isFacebook
                            ? 'flex items-stretch'
                            : `flex items-center justify-around rounded-b-lg px-2 py-2 shadow-sm ${!PostModal ? 'bg-light dark:bg-dark' : ''}`
                    }
                >
                    {/* Like Button */}
                    <button
                        type="button"
                        onClick={() => toggleLike(interactionPostId)}
                        className={
                            isFacebook
                                ? `flex flex-1 cursor-pointer items-center justify-center gap-1 py-2.5 text-[15px] font-semibold transition-colors hover:bg-muted/50 dark:hover:bg-dark_gray ${isLiked ? 'text-alpha' : 'text-beta dark:text-light'
                                }`
                                : `flex cursor-pointer items-center gap-1 rounded-lg px-4 py-2 transition-colors duration-200 ${isLiked ? 'text-alpha' : 'text-beta hover:text-alpha dark:text-light'
                                }`
                        }
                        aria-pressed={isLiked}
                    >
                        <LionsGeekLogoIcon className="h-5 w-5 mt-1" />
                        <span className={isFacebook ? 'font-semibold' : 'text-sm font-semibold'}>
                            {isLiked ? 'Geeked' : 'Geek'}
                        </span>
                    </button>

                    {/* Comment Button */}
                    <button
                        type="button"
                        className={
                            isFacebook
                                ? 'flex flex-1 cursor-pointer items-center justify-center gap-2 py-2.5 text-[15px] font-semibold text-beta transition-colors hover:bg-muted/50 dark:text-light dark:hover:bg-dark_gray'
                                : 'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 text-beta transition-colors duration-200 hover:bg-dark_gray/10 hover:text-beta dark:text-light dark:hover:bg-light/10'
                        }
                        onClick={() => onCommentPress?.()}
                    >
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                            />
                        </svg>
                        <span className={isFacebook ? 'font-semibold' : 'text-sm font-semibold'}>Comment</span>
                    </button>

                    {/* Repost Button — only on original posts, not repost feed items */}
                    {canRepost && (
                        <button
                            type="button"
                            className={
                                isFacebook
                                    ? `flex flex-1 cursor-pointer items-center justify-center gap-2 py-2.5 text-[15px] font-semibold transition-colors hover:bg-muted/50 dark:hover:bg-dark_gray ${isReposted ? 'text-alpha' : 'text-beta dark:text-light'}`
                                    : `flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 transition-colors duration-200 ${isReposted ? 'text-alpha' : 'text-beta hover:text-alpha dark:text-light'}`
                            }
                            onClick={() => setRepostOpenFor(post?.id)}
                            aria-pressed={isReposted}
                        >
                            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth={2}
                                    d="M17 1l4 4-4 4M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4m14-2v2a4 4 0 01-4 4H3"
                                />
                            </svg>
                            <span className={isFacebook ? 'font-semibold' : 'text-sm font-semibold'}>
                                {isReposted ? 'Reposted' : 'Repost'}
                            </span>
                        </button>
                    )}

                    {/* Send Button */}
                    <button
                        type="button"
                        className={
                            isFacebook
                                ? 'flex flex-1 cursor-pointer items-center justify-center gap-2 py-2.5 text-[15px] font-semibold text-beta transition-colors hover:bg-muted/50 dark:text-light dark:hover:bg-dark_gray'
                                : 'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 text-beta transition-colors duration-200 hover:bg-dark_gray/10 hover:text-beta dark:text-light dark:hover:bg-light/10'
                        }
                        onClick={() => setSendOpenFor(post?.id)}
                    >
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M22 2L11 13" />
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M22 2l-7 20-4-9-9-4 20-7z" />
                        </svg>
                        <span className={isFacebook ? 'font-semibold' : 'text-sm font-semibold'}>Send</span>
                    </button>
                </div>
            )}

            {/* Likes modal */}
            <LikesModal postId={likesOpenFor} open={!!likesOpenFor} onClose={() => setLikesOpenFor(null)} takeToUserProfile={takeToUserProfile} />

            <RepostModal
                open={!!repostOpenFor}
                onOpenChange={(open) => setRepostOpenFor(open ? post?.id : null)}
                user={user}
                post={post}
                isReposted={isReposted}
                onRepostStateChange={handleRepostStateChange}
            />

            <SendPostModal open={!!sendOpenFor} onOpenChange={(open) => setSendOpenFor(open ? post?.id : null)} post={post} />
        </>
    );
};

export default PostCardFooter;
