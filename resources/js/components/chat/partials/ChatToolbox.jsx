import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { Eye, FileIcon, Image as ImageIcon, Link as LinkIcon, Paperclip, Video as VideoIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { resolveAttachmentUrl } from '../resolveAttachmentUrl';

// Toolbox component dial attachments w posts
export default function ChatToolbox({ conversationId, otherUserId, onPreviewAttachment, messages = [] }) {
    const [attachments, setAttachments] = useState([]);
    const [posts, setPosts] = useState([]);
    const [loading, setLoading] = useState(false);

    // Get CSRF token
    const getCsrfToken = () => {
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        return metaTag ? metaTag.getAttribute('content') : '';
    };

    useEffect(() => {
        // Filter attachments from messages prop - pas audios
        if (messages && messages.length > 0) {
            const allAttachments = messages
                .filter((msg) => msg.attachment_path && msg.attachment_type !== 'audio')
                .map((msg) => ({
                    id: msg.id,
                    type: msg.attachment_type,
                    path: msg.attachment_url || msg.attachment_path,
                    name: msg.attachment_name,
                    created_at: msg.created_at,
                }));
            setAttachments(allAttachments);
        }
        fetchPosts();
    }, [messages, otherUserId]);

    // Jib posts dial user b fetch
    const fetchPosts = async () => {
        try {
            setLoading(true);
            const response = await fetch(`/chat/user/${otherUserId}/posts`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to fetch posts');
            }

            const data = await response.json();
            setPosts(data.posts || []);
        } catch (error) {
            console.error('Failed to fetch posts:', error);
        } finally {
            setLoading(false);
        }
    };

    const handlePreview = (attachment, onPreviewAttachment) => {
        if (attachment.type === 'image' || attachment.type === 'video') {
            onPreviewAttachment({
                type: attachment.type,
                path: attachment.path,
                name: attachment.name,
            });
        }
    };

    const getAttachmentIcon = (type) => {
        switch (type) {
            case 'image':
                return <ImageIcon className="h-5 w-5 text-alpha" />;
            case 'video':
                return <VideoIcon className="h-5 w-5 text-alpha" />;
            case 'audio':
                return <Paperclip className="h-5 w-5 text-alpha" />;
            default:
                return <FileIcon className="h-5 w-5 text-alpha" />;
        }
    };

    return (
        <div className="flex h-full w-full flex-col bg-background">
            <Tabs defaultValue="attachments" className="flex h-full flex-col">
                <div className="shrink-0 border-b p-4">
                    <TabsList className="grid w-full grid-cols-2">
                        <TabsTrigger value="attachments">Attachments</TabsTrigger>
                        <TabsTrigger value="posts">Posts</TabsTrigger>
                    </TabsList>
                </div>

                <TabsContent value="attachments" className="m-0 min-h-0 flex-1">
                    <ScrollArea className="h-full p-4">
                        {loading ? (
                            <div className="flex h-full items-center justify-center">
                                <span className="text-sm text-muted-foreground">Loading...</span>
                            </div>
                        ) : attachments.length === 0 ? (
                            <div className="flex h-full flex-col items-center justify-center text-muted-foreground">
                                <Paperclip className="mb-2 h-12 w-12 opacity-20" />
                                <p className="text-sm">No attachments yet</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-2">
                                {attachments.map((attachment) => (
                                    <div
                                        key={attachment.id}
                                        className={cn(
                                            'group relative cursor-pointer overflow-hidden rounded-lg',
                                            attachment.type === 'image' ? 'aspect-square' : 'aspect-video',
                                        )}
                                        onClick={() => onPreviewAttachment && handlePreview(attachment, onPreviewAttachment)}
                                    >
                                        {attachment.type === 'image' ? (
                                            <img
                                                src={resolveAttachmentUrl(attachment.path)}
                                                alt={attachment.name}
                                                className="h-full w-full object-cover"
                                            />
                                        ) : attachment.type === 'video' ? (
                                            <video
                                                src={resolveAttachmentUrl(attachment.path)}
                                                className="h-full w-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex h-full w-full items-center justify-center bg-muted">
                                                {getAttachmentIcon(attachment.type)}
                                            </div>
                                        )}
                                        <div className="absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition-colors group-hover:bg-black/50 group-hover:opacity-100">
                                            <Eye className="h-6 w-6 text-white" />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </ScrollArea>
                </TabsContent>

                <TabsContent value="posts" className="m-0 min-h-0 flex-1">
                    <ScrollArea className="h-full p-4">
                        {posts.length === 0 ? (
                            <div className="flex h-full flex-col items-center justify-center text-muted-foreground">
                                <LinkIcon className="mb-2 h-12 w-12 opacity-20" />
                                <p className="text-sm">No posts shared yet</p>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {posts.map((post) => (
                                    <div key={post.id} className="cursor-pointer rounded-lg border p-4 transition-colors hover:bg-accent/50">
                                        {post.images && post.images.length > 0 && (
                                            <img
                                                src={`/storage/img/posts/${post.images[0]}`}
                                                alt="Post"
                                                className="mb-2 h-48 w-full rounded-lg object-cover"
                                            />
                                        )}
                                        <p className="line-clamp-2 text-sm">{post.description}</p>
                                        <div className="mt-2 flex items-center gap-4 text-xs text-muted-foreground">
                                            <span>❤️ {post.likes_count}</span>
                                            <span>💬 {post.comments_count}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </ScrollArea>
                </TabsContent>
            </Tabs>
        </div>
    );
}
