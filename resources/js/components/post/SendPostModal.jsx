import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { useEffect, useMemo, useState } from 'react';

function clampText(value, maxLen) {
    const str = value == null ? '' : String(value);
    if (maxLen <= 0) return '';
    return str.length > maxLen ? str.slice(0, maxLen) : str;
}

function buildShareBodyString(post, caption = '') {
    const interactionPost = post?.repost_of ?? null;
    const source = interactionPost ?? post;
    const interactionPostId = post?.interaction_post_id ?? post?.id;

    const payload = {
        type: 'post_share',
        post_id: interactionPostId,
        // Keep caption small to avoid server rejection (ChatController validates body length)
        caption: clampText(caption || '', 300),
        // Include minimal author/meta for preview without hydration
        author_name: clampText(source?.user_name ?? '', 80) || null,
        author_image: source?.user_image ?? null,
        // Include a short snippet only (full post can be opened from feed)
        description: clampText(source?.description ?? '', 400),
        image: Array.isArray(source?.images) && source.images.length > 0 ? source.images[0] : null,
    };

    // Extra safety: ensure JSON stays under a conservative limit.
    // If it grows too much, progressively drop description then image.
    let body = JSON.stringify(payload);
    if (body.length > 4500) {
        payload.description = clampText(payload.description, 120);
        body = JSON.stringify(payload);
    }
    if (body.length > 4500) {
        payload.description = '';
        body = JSON.stringify(payload);
    }
    if (body.length > 4500) {
        payload.image = null;
        body = JSON.stringify(payload);
    }
    if (body.length > 4500) {
        payload.author_image = null;
        body = JSON.stringify(payload);
    }

    return body;
}

function buildSharePayload(post, caption = '') {
    const interactionPost = post?.repost_of ?? null;
    const source = interactionPost ?? post;
    const interactionPostId = post?.interaction_post_id ?? post?.id;

    return {
        type: 'post_share',
        post_id: interactionPostId,
        caption: caption || '',
        author_name: source?.user_name ?? null,
        author_image: source?.user_image ?? null,
        description: source?.description ?? '',
        image: Array.isArray(source?.images) && source.images.length > 0 ? source.images[0] : null,
    };
}

export default function SendPostModal({ open, onOpenChange, post, defaultMessage = '' }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [users, setUsers] = useState([]);
    const [query, setQuery] = useState('');
    const [selectedUserId, setSelectedUserId] = useState(null);
    const [sending, setSending] = useState(false);
    const [message, setMessage] = useState(defaultMessage);

    useEffect(() => {
        if (!open) {
            setLoading(false);
            setError(null);
            setUsers([]);
            setQuery('');
            setSelectedUserId(null);
            setSending(false);
            setMessage(defaultMessage);
            return;
        }

        const fetchUsers = async () => {
            setLoading(true);
            setError(null);
            try {
                const response = await fetch('/chat/following-users', {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to load followers');
                }

                const data = await response.json();
                setUsers(Array.isArray(data?.users) ? data.users : []);
            } catch (err) {
                setError(err?.message || 'Failed to load followers');
            } finally {
                setLoading(false);
            }
        };

        fetchUsers();
    }, [open, defaultMessage]);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return users;
        return users.filter((u) => (u?.name || '').toLowerCase().includes(q) || (u?.email || '').toLowerCase().includes(q));
    }, [users, query]);

    const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const handleSend = async () => {
        if (!selectedUserId || sending) return;
        setSending(true);
        setError(null);

        const body = buildShareBodyString(post, (message || '').trim());

        try {
            const conversationRes = await fetch(`/chat/conversation/${selectedUserId}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!conversationRes.ok) {
                const data = await conversationRes.json().catch(() => ({}));
                throw new Error(data?.error || data?.message || 'Failed to open conversation');
            }

            const conversationData = await conversationRes.json();
            const conversationId = conversationData?.conversation?.id;
            if (!conversationId) {
                throw new Error('Conversation not available');
            }

            const response = await fetch(`/chat/conversation/${conversationId}/send`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ body }),
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data?.error || data?.message || 'Failed to send post');
            }

            onOpenChange(false);
        } catch (err) {
            setError(err?.message || 'Failed to send post');
        } finally {
            setSending(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle className="text-beta dark:text-light">Send post</DialogTitle>
                </DialogHeader>

                <div className="space-y-3">
                    <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search conversations..." />

                    <div className="max-h-64 overflow-auto rounded-lg border border-border/70 dark:border-white/10">
                        {loading ? (
                            <div className="p-4 text-sm text-muted-foreground">Loading followers...</div>
                        ) : filtered.length === 0 ? (
                            <div className="p-4 text-sm text-muted-foreground">No users found.</div>
                        ) : (
                            <div className="divide-y divide-border/60 dark:divide-white/10">
                                {filtered.map((u) => {
                                    const selected = Number(selectedUserId) === Number(u.id);
                                    return (
                                        <button
                                            key={u.id}
                                            type="button"
                                            onClick={() => setSelectedUserId(u.id)}
                                            className={cn(
                                                'flex w-full items-center gap-3 p-3 text-left transition hover:bg-muted/40 dark:hover:bg-dark_gray',
                                                selected && 'bg-muted/50 dark:bg-white/10',
                                            )}
                                        >
                                            <Avatar className="h-9 w-9" image={u?.image} name={u?.name} />
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-sm font-semibold text-beta dark:text-light">{u?.name}</div>
                                                <div className="truncate text-xs text-muted-foreground">{u?.email}</div>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    {/* <div className="space-y-2">
                        <div className="text-xs text-muted-foreground">Message (optional)</div>
                        <textarea
                            value={message}
                            onChange={(e) => setMessage(e.target.value)}
                            className="min-h-20 w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground dark:border-white/10"
                            placeholder="Add a message..."
                            maxLength={1000}
                        />
                    </div> */}

                    {error && <div className="text-sm text-red-600 dark:text-red-400">{error}</div>}

                    <div className="flex items-center justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)} disabled={sending}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={handleSend} disabled={!selectedUserId || sending}>
                            {sending ? 'Sending...' : 'Send'}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

