import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { format, isToday, isYesterday } from 'date-fns';
import { Check, CheckCheck, Clock, Download, FileIcon, MoreVertical, Pause, Play, Trash2, Video as VideoIcon } from 'lucide-react';

// Component dial message wahda
export default function MessageItem({
    message,
    isCurrentUser,
    currentUser,
    otherUser,
    showDateSeparator,
    groupWithPrev = false,
    groupWithNext = false,
    isPlayingAudio,
    audioProgress,
    audioDuration,
    showMenuForMessage,
    onPlayAudio,
    onDeleteMessage,
    onMenuToggle,
    onPreviewAttachment,
    onDownloadAttachment,
    formatMessageTime,
    formatSeenTime,
}) {
    const postShare = (() => {
        const raw = message?.body;
        if (!raw || typeof raw !== 'string') return null;
        if (!raw.trim().startsWith('{')) return null;
        try {
            const parsed = JSON.parse(raw);
            if (parsed?.type !== 'post_share' || !parsed?.post_id) return null;
            return parsed;
        } catch {
            return null;
        }
    })();

    // Format file size
    const formatFileSize = (bytes) => {
        if (!bytes) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let size = bytes;
        let unitIndex = 0;

        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex++;
        }

        return `${size.toFixed(1)} ${units[unitIndex]}`;
    };

    // Format audio duration
    const formatAudioDuration = (seconds) => {
        if (!seconds) return '';
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    };

    const showLeftAvatar = !isCurrentUser && !groupWithPrev;
    const showRightAvatar = isCurrentUser && !groupWithPrev;

    const bubbleRadius = isCurrentUser
        ? cn('rounded-2xl', groupWithPrev && 'rounded-tr-md', groupWithNext && 'rounded-br-md')
        : cn('rounded-2xl', groupWithPrev && 'rounded-tl-md', groupWithNext && 'rounded-bl-md');

    return (
        <>
            {showDateSeparator && (
                <div className="my-4 flex justify-center">
                    <span className="rounded-full bg-alpha/10 px-3 py-1 text-xs text-alpha">
                        {isToday(new Date(message.created_at))
                            ? 'Today'
                            : isYesterday(new Date(message.created_at))
                              ? 'Yesterday'
                              : format(new Date(message.created_at), 'MMMM d, yyyy')}
                    </span>
                </div>
            )}
            <div className={cn('group relative flex', groupWithNext ? 'mb-1' : 'mb-3', isCurrentUser ? 'justify-end' : 'justify-start')}>
                {!isCurrentUser && (
                    <div className="mr-2 w-8 flex-shrink-0">
                        {showLeftAvatar ? (
                            <button
                                type="button"
                                onClick={() => router.visit(`/students/${otherUser.id}`)}
                                className="flex-shrink-0 transition-opacity hover:opacity-80"
                                title={otherUser?.name}
                            >
                                <Avatar className="h-8 w-8 cursor-pointer" image={otherUser.image} name={otherUser.name} />
                            </button>
                        ) : null}
                    </div>
                )}
                <div
                    className={cn(
                        'group/message relative max-w-[78%] px-4 py-2.5 text-sm shadow-sm',
                        bubbleRadius,
                        isCurrentUser ? 'bg-alpha text-beta' : 'bg-muted text-foreground',
                    )}
                >
                    {!postShare && message.body && (
                        <p className={cn('leading-relaxed break-words whitespace-pre-wrap')}>{message.body}</p>
                    )}

                    {postShare && (
                        <button
                            type="button"
                            onClick={() => router.visit(`/students/feed#post-${postShare.post_id}`)}
                            className={cn(
                                'mt-1 w-full overflow-hidden rounded-xl border text-left transition hover:opacity-95',
                                isCurrentUser ? 'border-beta/20 bg-beta/10' : 'border-border bg-background',
                            )}
                        >
                            <div className="flex items-center gap-3 p-3">
                                <Avatar className="h-9 w-9" image={postShare.author_image} name={postShare.author_name} />
                                <div className="min-w-0 flex-1">
                                    <div className={cn('truncate text-sm font-semibold', isCurrentUser ? 'text-beta' : 'text-foreground')}>
                                        {postShare.author_name || 'Post'}
                                    </div>
                                    {postShare.caption ? (
                                        <div className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{postShare.caption}</div>
                                    ) : null}
                                </div>
                            </div>

                            {postShare.image ? (
                                <div className="px-3 pb-3">
                                    <img
                                        src={
                                            String(postShare.image).startsWith('/storage/') || String(postShare.image).startsWith('http')
                                                ? postShare.image
                                                : `/storage/img/posts/${postShare.image}`
                                        }
                                        alt={postShare.author_name ? `Post by ${postShare.author_name}` : 'Shared post'}
                                        className="h-44 w-full rounded-lg object-cover"
                                    />
                                </div>
                            ) : null}

                            {postShare.description ? (
                                <div className="px-3 pb-3">
                                    <div className="line-clamp-3 text-sm text-foreground/90 dark:text-light/90">{postShare.description}</div>
                                </div>
                            ) : null}
                        </button>
                    )}

                    {message.attachment_type === 'image' && message.attachment_path && (
                        <div
                            className="mt-1 cursor-pointer overflow-hidden rounded-lg"
                            onClick={() => onPreviewAttachment({ type: 'image', path: message.attachment_url || message.attachment_path, name: message.attachment_name })}
                        >
                            <img
                                src={
                                    message.attachment_url ||
                                    (message.attachment_path.startsWith('/storage/') || message.attachment_path.startsWith('blob:')
                                        ? message.attachment_path
                                        : `/storage/${message.attachment_path}`)
                                }
                                alt="Attachment"
                                className="max-h-64 max-w-full rounded-lg object-cover transition-opacity hover:opacity-90"
                            />
                            {message.attachment_size && (
                                <div className="mt-1 text-xs text-muted-foreground">{formatFileSize(message.attachment_size)}</div>
                            )}
                        </div>
                    )}

                    {message.attachment_type === 'video' && message.attachment_path && (
                        <div
                            className="group/video relative mt-1 cursor-pointer overflow-hidden rounded-lg"
                            onClick={() => onPreviewAttachment({ type: 'video', path: message.attachment_url || message.attachment_path, name: message.attachment_name })}
                        >
                            <video
                                src={
                                    message.attachment_url ||
                                    (message.attachment_path.startsWith('/storage/') || message.attachment_path.startsWith('blob:')
                                        ? message.attachment_path
                                        : `/storage/${message.attachment_path}`)
                                }
                                className="max-h-64 max-w-full rounded-lg object-contain"
                            />
                            <div className="absolute inset-0 flex items-center justify-center bg-black/30 opacity-0 transition-opacity group-hover/video:opacity-100">
                                <VideoIcon className="h-12 w-12 text-white" />
                            </div>
                            {message.attachment_size && (
                                <div className="mt-1 text-xs text-muted-foreground">{formatFileSize(message.attachment_size)}</div>
                            )}
                        </div>
                    )}

                    {message.attachment_type === 'file' && message.attachment_path && (
                        <button
                            onClick={() => onDownloadAttachment(message.attachment_url || message.attachment_path, message.attachment_name)}
                            className={cn(
                                'mt-2 flex w-full items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-alpha/10',
                                isCurrentUser ? 'border-beta/20 bg-beta/10 text-beta' : 'border-border bg-background',
                            )}
                        >
                            <FileIcon className={cn('h-5 w-5 flex-shrink-0', isCurrentUser ? 'text-alpha' : '')} />
                            <div className="min-w-0 flex-1 text-left">
                                <span className="block truncate text-xs">{message.attachment_name || 'Attachment'}</span>
                                {message.attachment_size && (
                                    <span className="text-xs text-muted-foreground">{formatFileSize(message.attachment_size)}</span>
                                )}
                            </div>
                            <Download className={cn('h-4 w-4 flex-shrink-0', isCurrentUser ? 'text-alpha' : '')} />
                        </button>
                    )}

                    {message.attachment_type === 'audio' && message.attachment_path && (
                        <div className={cn('mt-2 flex items-center gap-3 rounded-lg p-3', isCurrentUser ? 'bg-beta/10' : 'bg-background')}>
                            <button
                                onClick={() => onPlayAudio(message.attachment_url || message.attachment_path, message.id)}
                                className={cn(
                                    'rounded-full p-2.5 transition-all hover:scale-110',
                                    isCurrentUser ? 'bg-alpha text-black hover:bg-alpha/90' : 'bg-muted hover:bg-accent',
                                )}
                            >
                                {isPlayingAudio === message.id ? <Pause className="h-4 w-4" /> : <Play className="ml-0.5 h-4 w-4" />}
                            </button>
                            <audio
                                id={`audio-${message.id}`}
                                src={
                                    message.attachment_url ||
                                    (message.attachment_path.startsWith('/storage/') || message.attachment_path.startsWith('blob:')
                                        ? message.attachment_path
                                        : `/storage/${message.attachment_path}`)
                                }
                                className="hidden"
                            />
                            <div className="flex-1">
                                <div className="h-2 overflow-hidden rounded-full bg-beta/20">
                                    <div
                                        className={cn(
                                            'h-full rounded-full transition-all duration-300',
                                            isCurrentUser ? 'bg-dark' : 'bg-primary',
                                            isPlayingAudio === message.id ? '' : '',
                                        )}
                                        style={{ width: `${audioProgress[message.id] || 0}%` }}
                                    />
                                </div>
                                <div className="mt-1.5 flex items-center justify-between">
                                    <span className={cn('text-xs', isCurrentUser ? 'text-beta/70' : 'opacity-70')}>Voice message</span>
                                    {(audioDuration[message.id] || message.audio_duration) && (
                                        <span className={cn('text-xs tabular-nums', isCurrentUser ? 'text-beta/70' : 'opacity-70')}>
                                            {formatAudioDuration(audioDuration[message.id] || message.audio_duration)}
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    <div
                        className={cn(
                            'mt-1.5 flex items-center justify-end gap-1.5 text-[11px] opacity-70 transition-opacity group-hover/message:opacity-100',
                            isCurrentUser ? 'text-beta' : 'text-muted-foreground',
                        )}
                    >
                        <span>{formatMessageTime(message.created_at)}</span>
                        {isCurrentUser && (
                            <span className="ml-1">
                                {message.pending ? (
                                    <Clock className="inline h-3 w-3" />
                                ) : message.is_read && message.read_at ? (
                                    <CheckCheck className="inline h-3.5 w-3.5 text-blue-400" title={formatSeenTime(message.read_at)} />
                                ) : (
                                    <Check className="inline h-3.5 w-3.5" />
                                )}
                            </span>
                        )}
                    </div>

                    {isCurrentUser && showMenuForMessage === message.id && (
                        <div
                            className={cn(
                                'absolute top-2 right-2 z-10 rounded-lg border p-1 shadow-lg',
                                'bg-background dark:bg-dark_gray',
                                'border-border dark:border-border',
                            )}
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    onDeleteMessage(message.id);
                                    onMenuToggle(null);
                                }}
                                className={cn('w-full justify-start text-xs text-error', 'hover:bg-error/10 dark:hover:bg-error/20')}
                            >
                                <Trash2 className="mr-2 h-3 w-3" />
                                Delete
                            </Button>
                        </div>
                    )}

                    {isCurrentUser && (
                        <button
                            onClick={() => onMenuToggle(showMenuForMessage === message.id ? null : message.id)}
                            className="absolute top-2 right-2 rounded p-1 opacity-0 transition-opacity group-hover/message:opacity-100 hover:bg-black/10 dark:hover:bg-white/10"
                        >
                            <MoreVertical className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>
                {isCurrentUser && (
                    <div className="ml-2 w-8 flex-shrink-0">
                        {showRightAvatar ? (
                            <button
                                type="button"
                                onClick={() => router.visit(`/students/${currentUser.id}`)}
                                className="flex-shrink-0 transition-opacity hover:opacity-80"
                                title="Your profile"
                            >
                                <Avatar className="h-8 w-8 cursor-pointer" image={currentUser.image} name={currentUser.name} />
                            </button>
                        ) : null}
                    </div>
                )}
            </div>
        </>
    );
}
