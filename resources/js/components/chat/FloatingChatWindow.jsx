import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { Loader2, Maximize2, Mic, Minimize2, Paperclip, Pause, Play, Send, Square, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export default function FloatingChatWindow({ conversation, onClose, onMinimize, onExpand, isMinimized, isExpanded }) {
    const { auth } = usePage().props;
    const currentUser = auth.user;
    const [messages, setMessages] = useState(conversation.messages || []);
    const [newMessage, setNewMessage] = useState('');
    const [sending, setSending] = useState(false);
    const [loading, setLoading] = useState(false);
    const [attachment, setAttachment] = useState(null);
    const [isRecording, setIsRecording] = useState(false);
    const [audioBlob, setAudioBlob] = useState(null);
    const [audioURL, setAudioURL] = useState(null);
    const [isPlayingAudio, setIsPlayingAudio] = useState(null);
    const messagesEndRef = useRef(null);
    const mediaRecorderRef = useRef(null);
    const fileInputRef = useRef(null);
    const draftsByConversationRef = useRef(new Map());
    const previousConversationIdRef = useRef(conversation.id);

    useEffect(() => {
        if (!isMinimized && !isExpanded) {
            fetchMessages();
            const interval = setInterval(fetchMessages, 5000);
            return () => clearInterval(interval);
        } else if (isExpanded) {
            fetchMessages();
            const interval = setInterval(fetchMessages, 3000);
            return () => clearInterval(interval);
        }
    }, [conversation.id, isMinimized, isExpanded]);

    useEffect(() => {
        const previousConversationId = previousConversationIdRef.current;
        if (previousConversationId && previousConversationId !== conversation.id) {
            draftsByConversationRef.current.set(previousConversationId, {
                message: newMessage,
                attachment,
                audioBlob,
                audioURL,
            });
        }

        const nextDraft = draftsByConversationRef.current.get(conversation.id);
        setNewMessage(nextDraft?.message ?? '');
        setAttachment(nextDraft?.attachment ?? null);
        setAudioBlob(nextDraft?.audioBlob ?? null);
        setAudioURL(nextDraft?.audioURL ?? null);

        setIsRecording(false);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }

        previousConversationIdRef.current = conversation.id;
    }, [conversation.id]);

    useEffect(() => {
        draftsByConversationRef.current.set(conversation.id, {
            message: newMessage,
            attachment,
            audioBlob,
            audioURL,
        });
    }, [conversation.id, newMessage, attachment, audioBlob, audioURL]);

    useEffect(() => {
        if (!isMinimized) {
            scrollToBottom();
        }
    }, [messages, isMinimized]);

    const fetchMessages = async () => {
        try {
            setLoading(true);
            const response = await fetch(`/chat/conversation/${conversation.id}/messages`);
            if (response.ok) {
                const data = await response.json();
                setMessages(data.messages || []);

                await fetch(`/chat/conversation/${conversation.id}/read`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                });
            }
        } catch (error) {
            console.error('Failed to fetch messages:', error);
        } finally {
            setLoading(false);
        }
    };

    const scrollToBottom = () => {
        if (messagesEndRef.current) {
            messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    };

    const handleFileSelect = (e) => {
        const file = e.target.files[0];
        if (file) {
            setAttachment(file);
        }
    };

    const startRecording = async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const mediaRecorder = new MediaRecorder(stream, {
                mimeType: MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/mp4',
            });
            const chunks = [];

            mediaRecorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) {
                    chunks.push(e.data);
                }
            };

            mediaRecorder.onstop = () => {
                if (chunks.length > 0) {
                    const blob = new Blob(chunks, {
                        type: MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/mp4',
                    });
                    setAudioBlob(blob);
                    const url = URL.createObjectURL(blob);
                    setAudioURL(url);
                }
                stream.getTracks().forEach((track) => track.stop());
            };

            mediaRecorder.onerror = (e) => {
                console.error('MediaRecorder error:', e);
                setIsRecording(false);
                stream.getTracks().forEach((track) => track.stop());
            };

            mediaRecorder.start();
            mediaRecorderRef.current = mediaRecorder;
            setIsRecording(true);
        } catch (error) {
            console.error('Error accessing microphone:', error);
            alert('Microphone access denied. Please allow microphone access to record audio.');
            setIsRecording(false);
        }
    };

    const stopRecording = () => {
        if (mediaRecorderRef.current && isRecording) {
            mediaRecorderRef.current.stop();
            setIsRecording(false);
        }
    };

    const cancelRecording = () => {
        if (mediaRecorderRef.current && isRecording) {
            mediaRecorderRef.current.stop();
            mediaRecorderRef.current.stream.getTracks().forEach((track) => track.stop());
            setIsRecording(false);
            setAudioBlob(null);
            setAudioURL(null);
        }
    };

    const handleSendMessage = async (e) => {
        e.preventDefault();
        if ((!newMessage.trim() && !attachment && !audioBlob) || sending) return;

        setSending(true);
        const messageBody = newMessage.trim();
        const formData = new FormData();

        formData.append('body', messageBody);

        if (attachment) {
            formData.append('attachment', attachment);
            formData.append('attachment_type', attachment.type.startsWith('image/') ? 'image' : 'file');
        }

        if (audioBlob) {
            formData.append('attachment', audioBlob, 'audio.webm');
            formData.append('attachment_type', 'audio');
        }

        // Reset form
        setNewMessage('');
        setAttachment(null);
        setAudioBlob(null);
        setAudioURL(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
        draftsByConversationRef.current.delete(conversation.id);

        try {
            const response = await fetch(`/chat/conversation/${conversation.id}/send`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    Accept: 'application/json',
                },
                body: formData,
            });

            if (response.ok) {
                const data = await response.json();
                setMessages((prev) => [...prev, data.message]);
                setTimeout(() => fetchMessages(), 500);
            } else {
                const error = await response.json();
                alert(error.message || 'Failed to send message. Please try again.');
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            alert('Failed to send message. Please try again.');
        } finally {
            setSending(false);
        }
    };

    const isCurrentUserMessage = (senderId) => {
        return String(senderId) === String(currentUser.id);
    };

    const handlePlayAudio = (audioPath, messageId) => {
        if (isPlayingAudio === messageId) {
            // Stop current audio
            const audio = document.getElementById(`audio-${messageId}`);
            if (audio) {
                audio.pause();
                audio.currentTime = 0;
                setIsPlayingAudio(null);
            }
        } else {
            // Stop any currently playing audio
            if (isPlayingAudio) {
                const currentAudio = document.getElementById(`audio-${isPlayingAudio}`);
                if (currentAudio) {
                    currentAudio.pause();
                    currentAudio.currentTime = 0;
                }
            }

            // Play new audio
            setIsPlayingAudio(messageId);
            setTimeout(() => {
                const audio = document.getElementById(`audio-${messageId}`);
                if (audio) {
                    audio.play();
                    audio.onended = () => setIsPlayingAudio(null);
                    audio.onerror = () => {
                        setIsPlayingAudio(null);
                        alert('Failed to play audio');
                    };
                }
            }, 100);
        }
    };

    const renderMessage = (message) => {
        const isCurrentUser = isCurrentUserMessage(message.sender_id);

        return (
            <div key={message.id} className={cn('mb-3 flex', isCurrentUser ? 'justify-end' : 'justify-start')}>
                <div className={cn('max-w-[75%] rounded-lg px-3 py-2 text-sm', isCurrentUser ? 'bg-primary text-primary-foreground' : 'bg-muted')}>
                    {message.body && <p className="break-words whitespace-pre-wrap">{message.body}</p>}

                    {message.attachment_type === 'image' && message.attachment_path && (
                        <img
                            src={message.attachment_url || `/storage/${message.attachment_path}`}
                            alt="Attachment"
                            className="mt-2 max-h-64 max-w-full rounded object-contain"
                        />
                    )}

                    {message.attachment_type === 'file' && message.attachment_path && (
                        <a
                            href={message.attachment_url || `/storage/${message.attachment_path}`}
                            download={message.attachment_name}
                            className={cn(
                                'mt-2 flex items-center gap-2 rounded border p-2',
                                isCurrentUser ? 'border-primary-foreground/20 bg-primary-foreground/10' : 'border-border bg-background',
                            )}
                        >
                            <Paperclip className="h-4 w-4" />
                            <span className="truncate text-xs">{message.attachment_name || 'Attachment'}</span>
                        </a>
                    )}

                    {message.attachment_type === 'audio' && message.attachment_path && (
                        <div className={cn('mt-2 flex items-center gap-2 rounded p-2', isCurrentUser ? 'bg-primary-foreground/10' : 'bg-background')}>
                            <button
                                onClick={() => handlePlayAudio(message.attachment_url || message.attachment_path, message.id)}
                                className="rounded-full p-1.5 transition-colors hover:bg-black/10 dark:hover:bg-white/10"
                            >
                                {isPlayingAudio === message.id ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                            </button>
                            <audio id={`audio-${message.id}`} src={message.attachment_url || `/storage/${message.attachment_path}`} className="hidden" />
                            <span className="text-xs">Voice message</span>
                        </div>
                    )}

                    <p className={cn('mt-1 text-xs', isCurrentUser ? 'text-primary-foreground/70' : 'text-muted-foreground')}>
                        {format(new Date(message.created_at), 'h:mm a')}
                    </p>
                </div>
            </div>
        );
    };

    if (isMinimized) {
        const lastMessage = messages[messages.length - 1];
        const unreadCount = messages.filter((m) => !m.is_read && !isCurrentUserMessage(m.sender_id)).length;

        return (
            <div className="cursor-pointer overflow-hidden rounded-t-lg border border-border bg-white shadow-lg dark:bg-dark">
                <div onClick={onMinimize} className="flex items-center gap-2 p-3 transition-colors hover:bg-accent">
                    <Avatar className="h-8 w-8" image={conversation.other_user.image} name={conversation.other_user.name} />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{conversation.other_user.name}</p>
                        {lastMessage && (
                            <p className="truncate text-xs text-muted-foreground">
                                {lastMessage.attachment_type === 'audio'
                                    ? '🎤 Voice message'
                                    : lastMessage.attachment_type === 'image'
                                      ? '📷 Image'
                                      : lastMessage.attachment_type === 'file'
                                        ? '📎 File'
                                        : lastMessage.body}
                            </p>
                        )}
                    </div>
                    {unreadCount > 0 && (
                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white">
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </span>
                    )}
                </div>
            </div>
        );
    }

    const windowSize = isExpanded ? { width: '100%', height: '100%' } : { width: '320px', height: '500px' };

    return (
        <div
            className={cn('flex flex-col rounded-lg border border-border bg-white shadow-xl dark:bg-dark', isExpanded && 'rounded-lg')}
            style={windowSize}
        >
            {/* Header */}
            <div className="flex shrink-0 items-center gap-2 border-b p-3">
                <Avatar className="h-8 w-8 flex-shrink-0" image={conversation.other_user.image} name={conversation.other_user.name} />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{conversation.other_user.name}</p>
                    <p className="truncate text-xs text-muted-foreground">Online</p>
                </div>
                {onExpand && (
                    <Button variant="ghost" size="icon" onClick={onExpand} className="h-7 w-7">
                        {isExpanded ? <Minimize2 className="h-3.5 w-3.5" /> : <Maximize2 className="h-3.5 w-3.5" />}
                    </Button>
                )}
                <Button variant="ghost" size="icon" onClick={onMinimize} className="h-7 w-7">
                    <Minimize2 className="h-3.5 w-3.5" />
                </Button>
                <Button variant="ghost" size="icon" onClick={onClose} className="h-7 w-7">
                    <X className="h-3.5 w-3.5" />
                </Button>
            </div>

            {/* Messages */}
            <ScrollArea className={cn('min-h-0 flex-1', isExpanded ? 'p-6' : 'p-3')}>
                {loading && messages.length === 0 ? (
                    <div className="flex h-full items-center justify-center">
                        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                    </div>
                ) : messages.length === 0 ? (
                    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                        <p>No messages yet. Start the conversation!</p>
                    </div>
                ) : (
                    <div className={cn('space-y-1', isExpanded && 'mx-auto max-w-4xl')}>
                        {messages.map(renderMessage)}
                        <div ref={messagesEndRef} />
                    </div>
                )}
            </ScrollArea>

            {/* Audio Preview */}
            {audioURL && !audioBlob && (
                <div className="flex shrink-0 items-center gap-2 border-t bg-muted/50 p-3">
                    <audio src={audioURL} controls className="h-8 flex-1" />
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => {
                            setAudioBlob(null);
                            setAudioURL(null);
                        }}
                        className="h-8 w-8"
                    >
                        <X className="h-4 w-4" />
                    </Button>
                </div>
            )}

            {/* Attachment Preview */}
            {attachment && (
                <div className="flex shrink-0 items-center gap-2 border-t bg-muted/50 p-3">
                    <Paperclip className="h-4 w-4" />
                    <span className="flex-1 truncate text-sm">{attachment.name}</span>
                    <Button variant="ghost" size="icon" onClick={() => setAttachment(null)} className="h-8 w-8">
                        <X className="h-4 w-4" />
                    </Button>
                </div>
            )}

            {/* Message Input */}
            <form onSubmit={handleSendMessage} className="shrink-0 border-t p-3">
                <div className="flex items-end gap-2">
                    <input
                        ref={fileInputRef}
                        type="file"
                        onChange={handleFileSelect}
                        className="hidden"
                        accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.txt"
                    />
                    <Button type="button" variant="ghost" size="icon" onClick={() => fileInputRef.current?.click()} className="h-9 w-9 shrink-0">
                        <Paperclip className="h-4 w-4" />
                    </Button>

                    <div className="relative flex-1">
                        {isRecording ? (
                            <div className="flex items-center gap-2 rounded border border-red-200 bg-red-50 p-2 dark:border-red-900 dark:bg-red-950/20">
                                <div className="flex flex-1 items-center gap-2">
                                    <div className="h-2 w-2 animate-pulse rounded-full bg-red-500" />
                                    <span className="text-sm text-red-600 dark:text-red-400">Recording...</span>
                                </div>
                                <Button type="button" variant="ghost" size="icon" onClick={stopRecording} className="h-7 w-7">
                                    <Square className="h-4 w-4 fill-red-500 text-red-500" />
                                </Button>
                                <Button type="button" variant="ghost" size="icon" onClick={cancelRecording} className="h-7 w-7">
                                    <X className="h-4 w-4" />
                                </Button>
                            </div>
                        ) : (
                            <>
                                <Input
                                    value={newMessage}
                                    onChange={(e) => setNewMessage(e.target.value)}
                                    placeholder="Type a message..."
                                    className={cn('h-9 text-sm', isExpanded && 'h-10 text-base')}
                                    disabled={sending}
                                />
                                {audioBlob && !audioURL && (
                                    <div className="absolute -top-10 right-0 left-0 rounded border bg-muted p-2">
                                        <div className="flex items-center gap-2">
                                            <Mic className="h-4 w-4" />
                                            <span className="text-xs">Audio ready to send</span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                onClick={() => {
                                                    setAudioBlob(null);
                                                    if (mediaRecorderRef.current) {
                                                        mediaRecorderRef.current.stream.getTracks().forEach((track) => track.stop());
                                                    }
                                                }}
                                                className="ml-auto h-6 w-6"
                                            >
                                                <X className="h-3 w-3" />
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </div>

                    {!isRecording ? (
                        <>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onMouseDown={startRecording}
                                onMouseUp={stopRecording}
                                onMouseLeave={stopRecording}
                                className="h-9 w-9 shrink-0"
                            >
                                <Mic className="h-4 w-4" />
                            </Button>
                            <Button
                                type="submit"
                                size="icon"
                                disabled={sending || (!newMessage.trim() && !attachment && !audioBlob)}
                                className="h-9 w-9 shrink-0"
                            >
                                {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                            </Button>
                        </>
                    ) : null}
                </div>
            </form>
        </div>
    );
}
