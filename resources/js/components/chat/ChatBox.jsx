import useAblyChannel from '@/hooks/useAblyChannel';
import { useRecordingIndicator } from '@/hooks/useRecordingIndicator';
import { useTypingIndicator } from '@/hooks/useTypingIndicator';
import { hasNotificationPermission, requestNotificationPermission, showDesktopNotification } from '@/lib/notificationManager';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { format, isToday, isYesterday } from 'date-fns';
import { useEffect, useRef, useState } from 'react';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import ChatHeader from './partials/ChatHeader';
import ChatToolbox from './partials/ChatToolbox';
import MessageInput from './partials/MessageInput';
import MessageList from './partials/MessageList';
import PreviewPanel from './partials/PreviewPanel';

// Main ChatBox component - refactored b components so9or
export default function ChatBox({ conversation, onClose, onBack, isExpanded, onExpand }) {
    const { auth } = usePage().props;
    const currentUser = auth.user;
    const [messages, setMessages] = useState(conversation.messages || []);
    const [newMessage, setNewMessage] = useState('');
    const [sending, setSending] = useState(false);
    const [loading, setLoading] = useState(false);
    const [attachments, setAttachments] = useState([]);
    const [isRecording, setIsRecording] = useState(false);
    const [isPaused, setIsPaused] = useState(false);
    const [recordingTime, setRecordingTime] = useState(0);
    const [audioBlob, setAudioBlob] = useState(null);
    const [audioURL, setAudioURL] = useState(null);
    const [isPlayingAudio, setIsPlayingAudio] = useState(null);
    const [previewAttachment, setPreviewAttachment] = useState(null);
    const [previewIndex, setPreviewIndex] = useState(0);
    const [audioProgress, setAudioProgress] = useState({});
    const [audioDuration, setAudioDuration] = useState({});
    const [showMenuForMessage, setShowMenuForMessage] = useState(null);
    const [showToolbox, setShowToolbox] = useState(false);
    const messagesEndRef = useRef(null);
    const mediaRecorderRef = useRef(null);
    const audioStreamRef = useRef(null);
    const chunksRef = useRef([]);
    const fileInputRef = useRef(null);
    const recordingTimerRef = useRef(null);
    const pendingTempIdsRef = useRef(new Set());
    const draftsByConversationRef = useRef(new Map());
    const previousConversationIdRef = useRef(conversation.id);
    const shouldDiscardRecordingRef = useRef(false);

    const channelName = `chat:conversation:${conversation.id}`;

    // Real-time messaging with Ably - Tssma3 3la channel dial conversation
    const { isConnected, subscribe, publish } = useAblyChannel(channelName, ['new-message', 'message-deleted', 'seen', 'typing', 'recording'], {
        onConnected: () => {
            //console.log('Ably connected for conversation:', conversation.id);
            // Request notification permission on first connection
            requestNotificationPermission();
        },
        onError: (error) => {
            console.error('Ably connection error:', error);
        },
    });

    // Typing indicator
    const { typingUsers, startTyping, stopTyping } = useTypingIndicator(channelName, currentUser.id, isConnected);

    // Recording indicator
    const { recordingUsers, startRecordingIndicator, stopRecordingIndicator } = useRecordingIndicator(channelName, currentUser.id, isConnected);

    // Real-time seen status
    const [seenStatus, setSeenStatus] = useState({});

    // Subscribe to real-time message events
    useEffect(() => {
        if (!isConnected) return;

        // Handle new messages from other users
        const handleNewMessage = (messageData) => {
            // Safety check: only process messages for the current conversation
            // This prevents messages from other conversations from appearing if there's a race condition
            if (messageData.conversation_id && messageData.conversation_id !== conversation.id) {
                return;
            }

            const isFromOtherUser = messageData.sender_id !== currentUser.id;

            // Show notification if chatbox is closed or minimized
            if (isFromOtherUser) {
                // Desktop notification
                if (hasNotificationPermission() && (document.hidden || !document.hasFocus())) {
                    //console.log(messageData);
                    showDesktopNotification(`${messageData.sender?.name || 'New message'}`, {
                        body: messageData.body || '📎 Attachment',
                        icon: 'storage/img/profile/' + messageData.sender?.image || '/favicon.ico',
                        tag: `chat-${conversation.id}`,
                        data: {
                            conversationId: conversation.id,
                            userId: messageData.sender_id,
                        },
                    });
                }

                // Toast notifications are handled globally - no need to show here
                // Global listener will handle showing toasts unconditionally
            }

            // Check if message already exists (avoid duplicates)
            setMessages((prev) => {
                const exists = prev.some((msg) => msg.id === messageData.id);
                if (exists) return prev;

                // Check if it's a pending message that was just sent
                const isPending = prev.some(
                    (msg) => msg.tempId && pendingTempIdsRef.current.has(msg.tempId) && msg.sender_id === messageData.sender_id,
                );

                // If we have a pending message, replace it with the real one
                if (isPending) {
                    const filtered = prev.filter((msg) => !msg.tempId || !pendingTempIdsRef.current.has(msg.tempId));
                    // Clean up temp IDs for this sender
                    pendingTempIdsRef.current.forEach((tempId) => {
                        const msg = prev.find((m) => m.tempId === tempId);
                        if (msg && msg.sender_id === messageData.sender_id) {
                            pendingTempIdsRef.current.delete(tempId);
                        }
                    });
                    return [
                        ...filtered,
                        {
                            ...messageData,
                            sender: messageData.sender || {
                                id: messageData.sender_id,
                                name: '',
                                image: '',
                            },
                        },
                    ];
                }

                // Add new message from other user
                return [
                    ...prev,
                    {
                        ...messageData,
                        sender: messageData.sender || {
                            id: messageData.sender_id,
                            name: '',
                            image: '',
                        },
                    },
                ];
            });

            // Mark as read if message is from other user and chatbox is visible and focused
            // This will broadcast seen status via Ably automatically
            if (isFromOtherUser && !document.hidden && document.hasFocus()) {
                fetch(`/chat/conversation/${conversation.id}/read`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                }).catch((err) => console.error('Failed to mark as read:', err));
            }

            // When other user sends a message, it means they've seen your messages
            // So mark all your sent messages as read immediately
            if (isFromOtherUser) {
                setMessages((prev) =>
                    prev.map((msg) => {
                        if (msg.sender_id === currentUser.id && !msg.is_read) {
                            return {
                                ...msg,
                                is_read: true,
                                read_at: messageData.created_at, // Use the new message's timestamp
                            };
                        }
                        return msg;
                    }),
                );
            }

            scrollToBottom();
        };

        // Handle message deletions
        const handleMessageDeleted = (data) => {
            setMessages((prev) => prev.filter((msg) => msg.id !== data.message_id));
        };

        // Handle seen status updates - updates when ANY user marks messages as read
        const handleSeen = (data) => {
            console.log('📖 Seen event received:', data);

            // Update seen status for the conversation
            setSeenStatus((prev) => ({
                ...prev,
                [conversation.id]: {
                    read_at: data.read_at,
                    user_id: data.user_id,
                },
            }));

            // If the other user marked messages as read, update our sent messages
            if (data.user_id !== currentUser.id) {
                // Update ALL messages sent by current user that were sent before or at the read_at time
                // This ensures read receipts update in real-time
                const readAtTime = new Date(data.read_at).getTime();

                setMessages((prev) => {
                    let updatedCount = 0;
                    const updated = prev.map((msg) => {
                        if (msg.sender_id === currentUser.id) {
                            const msgTime = new Date(msg.created_at).getTime();
                            // Update if message was sent before or at the read time
                            if (msgTime <= readAtTime) {
                                // Always update to the latest read_at timestamp
                                const currentReadAt = msg.read_at ? new Date(msg.read_at).getTime() : 0;
                                if (!msg.is_read || currentReadAt < readAtTime) {
                                    updatedCount++;
                                    return {
                                        ...msg,
                                        is_read: true,
                                        read_at: data.read_at,
                                    };
                                }
                            }
                        }
                        return msg;
                    });

                    if (updatedCount > 0) {
                        console.log(`✅ Updated ${updatedCount} message(s) to read status`);
                    }

                    return updated;
                });
            } else {
                // If current user marked messages as read, update received messages
                setMessages((prev) =>
                    prev.map((msg) => {
                        if (msg.sender_id !== currentUser.id && !msg.is_read) {
                            return {
                                ...msg,
                                is_read: true,
                                read_at: data.read_at,
                            };
                        }
                        return msg;
                    }),
                );
            }
        };

        subscribe('new-message', handleNewMessage);
        subscribe('message-deleted', handleMessageDeleted);
        subscribe('seen', handleSeen);

        return () => {
            // Cleanup handled by useAblyChannel
        };
    }, [isConnected, subscribe, conversation.id, currentUser.id]);

    // Fetch messages - b3d ma y3tiw 3la conversation
    useEffect(() => {
        // Clear messages immediately when switching conversations
        // Only keep pending messages that might be for this conversation
        setMessages((prev) => {
            // Keep only pending messages (they should be for current conversation if recently sent)
            return prev.filter((m) => m.pending && pendingTempIdsRef.current.has(m.tempId));
        });

        // Instagram-like drafts: keep draft for this conversation only
        const previousConversationId = previousConversationIdRef.current;
        if (previousConversationId && previousConversationId !== conversation.id) {
            draftsByConversationRef.current.set(previousConversationId, {
                message: newMessage,
                attachments,
                audioBlob,
                audioURL,
            });
        }

        const nextDraft = draftsByConversationRef.current.get(conversation.id);
        setNewMessage(nextDraft?.message ?? '');
        setAttachments(Array.isArray(nextDraft?.attachments) ? nextDraft.attachments : nextDraft?.attachment ? [nextDraft.attachment] : []);
        setAudioBlob(nextDraft?.audioBlob ?? null);
        setAudioURL(nextDraft?.audioURL ?? null);

        // reset recording UI state on switch (drafts are for typed/attached content only)
        setIsRecording(false);
        setIsPaused(false);
        setRecordingTime(0);
        chunksRef.current = [];
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
        setAudioDuration((prev) => {
            const next = { ...prev };
            delete next['preview'];
            return next;
        });

        previousConversationIdRef.current = conversation.id;

        // Fetch messages for the new conversation
        fetchMessages();
    }, [conversation.id]);

    // Keep the current conversation draft up to date while typing/selecting attachments.
    useEffect(() => {
        draftsByConversationRef.current.set(conversation.id, {
            message: newMessage,
            attachments,
            audioBlob,
            audioURL,
        });
    }, [conversation.id, newMessage, attachments, audioBlob, audioURL]);

    useEffect(() => {
        scrollToBottom();
    }, [messages]);

    // Update recording time timer
    useEffect(() => {
        if (isRecording && !isPaused) {
            recordingTimerRef.current = setInterval(() => {
                setRecordingTime((prev) => prev + 1);
            }, 1000);
        } else {
            if (recordingTimerRef.current) {
                clearInterval(recordingTimerRef.current);
                recordingTimerRef.current = null;
            }
        }

        return () => {
            if (recordingTimerRef.current) {
                clearInterval(recordingTimerRef.current);
            }
        };
    }, [isRecording, isPaused]);

    // Load audio durations when messages change
    useEffect(() => {
        messages.forEach((message) => {
            if (message.attachment_type === 'audio' && message.attachment_path && !audioDuration[message.id]) {
                const audio = document.getElementById(`audio-${message.id}`);
                if (audio) {
                    const loadDuration = () => {
                        if (audio.duration && isFinite(audio.duration)) {
                            setAudioDuration((prev) => ({ ...prev, [message.id]: audio.duration }));
                        }
                    };

                    if (audio.readyState >= 1) {
                        loadDuration();
                    } else {
                        audio.addEventListener('loadedmetadata', loadDuration, { once: true });
                        audio.load();
                    }
                }
            }
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [messages]);

    const getCsrfToken = () => {
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        return metaTag ? metaTag.getAttribute('content') : '';
    };

    const fetchMessages = async () => {
        try {
            setLoading(true);
            const response = await fetch(`/chat/conversation/${conversation.id}/messages`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to fetch messages');
            }

            const data = await response.json();
            const fetchedMessages = data.messages || [];

            // Set messages for current conversation - only merge with pending messages for this conversation
            setMessages((prev) => {
                // Only keep pending messages that are truly pending (not yet confirmed by server)
                const pendingMessages = prev.filter((m) => m.pending && pendingTempIdsRef.current.has(m.tempId));
                const existingIds = new Set(fetchedMessages.map((m) => m.id));

                // Filter out pending messages that have been confirmed (exist in fetchedMessages)
                const stillPending = pendingMessages.filter((m) => !existingIds.has(m.tempId));

                // Return fetched messages plus any still-pending messages
                return [...fetchedMessages, ...stillPending];
            });

            // Mark messages as read when conversation is opened (if chatbox is visible and focused)
            // This will broadcast seen status via Ably automatically
            if (!document.hidden && document.hasFocus()) {
                await fetch(`/chat/conversation/${conversation.id}/read`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                }).catch((err) => console.error('Failed to mark as read:', err));
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
        const selected = Array.from(e.target.files || []);
        if (selected.length === 0) return;
        setAttachments((prev) => [...prev, ...selected]);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const startRecording = async () => {
        try {
            // Check if MediaRecorder is supported
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error('MediaRecorder API is not supported in this browser');
            }

            // Request microphone access
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });

            // Store stream reference
            audioStreamRef.current = stream;

            // Check if MediaRecorder is available
            if (!window.MediaRecorder) {
                throw new Error('MediaRecorder is not supported in this browser');
            }

            // Determine best mime type
            let mimeType = 'audio/webm';
            if (!MediaRecorder.isTypeSupported('audio/webm')) {
                if (MediaRecorder.isTypeSupported('audio/mp4')) {
                    mimeType = 'audio/mp4';
                } else if (MediaRecorder.isTypeSupported('audio/ogg')) {
                    mimeType = 'audio/ogg';
                } else {
                    mimeType = ''; // Use default
                }
            }

            const mediaRecorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);

            chunksRef.current = [];

            mediaRecorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) {
                    chunksRef.current.push(e.data);
                }
            };

            mediaRecorder.onstop = () => {
                if (shouldDiscardRecordingRef.current) {
                    shouldDiscardRecordingRef.current = false;
                    chunksRef.current = [];
                    return;
                }
                if (chunksRef.current.length > 0) {
                    const blobType = mimeType || (MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/mp4');
                    const blob = new Blob(chunksRef.current, { type: blobType });
                    setAudioBlob(blob);
                    const url = URL.createObjectURL(blob);
                    setAudioURL(url);

                    // Calculate audio duration from blob
                    const audio = new Audio(url);
                    audio.addEventListener('loadedmetadata', () => {
                        if (audio.duration && isFinite(audio.duration)) {
                            // Store duration for preview
                            setAudioDuration((prev) => ({ ...prev, preview: audio.duration }));
                        }
                    });
                    audio.load();
                }

                // Stop all tracks
                if (audioStreamRef.current) {
                    audioStreamRef.current.getTracks().forEach((track) => {
                        track.stop();
                    });
                    audioStreamRef.current = null;
                }

                // Stop recording indicator
                if (stopRecordingIndicator) {
                    stopRecordingIndicator();
                }
            };

            mediaRecorder.onerror = (e) => {
                console.error('MediaRecorder error:', e);
                setIsRecording(false);
                setIsPaused(false);

                // Clean up stream
                if (audioStreamRef.current) {
                    audioStreamRef.current.getTracks().forEach((track) => track.stop());
                    audioStreamRef.current = null;
                }

                stopRecordingIndicator();
            };

            // Start recording with timeslice for data chunks
            mediaRecorder.start(1000); // Get data every second
            mediaRecorderRef.current = mediaRecorder;
            setIsRecording(true);
            setIsPaused(false);
            setRecordingTime(0);
            chunksRef.current = [];

            // Start recording indicator
            if (startRecordingIndicator) {
                startRecordingIndicator();
            }
        } catch (error) {
            console.error('Error accessing microphone:', error);

            let errorMessage = 'Failed to access microphone. ';
            if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
                errorMessage += 'Please allow microphone access in your browser settings.';
            } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
                errorMessage += 'No microphone found. Please connect a microphone.';
            } else {
                errorMessage += error.message || 'Unknown error occurred.';
            }

            alert(errorMessage);
            setIsRecording(false);
            setIsPaused(false);

            // Stop recording indicator if function exists
            if (stopRecordingIndicator) {
                stopRecordingIndicator();
            }

            // Clean up any partial stream
            if (audioStreamRef.current) {
                audioStreamRef.current.getTracks().forEach((track) => track.stop());
                audioStreamRef.current = null;
            }
        }
    };

    const pauseRecording = () => {
        if (!mediaRecorderRef.current || !isRecording || isPaused) return;
        try {
            if (mediaRecorderRef.current.state === 'recording') {
                mediaRecorderRef.current.pause();
                setIsPaused(true);
            }
        } catch (error) {
            console.error('Failed to pause recording:', error);
        }
    };

    const resumeRecording = () => {
        if (!mediaRecorderRef.current || !isRecording || !isPaused) return;
        try {
            if (mediaRecorderRef.current.state === 'paused') {
                mediaRecorderRef.current.resume();
                setIsPaused(false);
            }
        } catch (error) {
            console.error('Failed to resume recording:', error);
        }
    };

    const stopRecording = () => {
        if (mediaRecorderRef.current && isRecording) {
            mediaRecorderRef.current.stop();
            setIsRecording(false);
            setIsPaused(false);
            stopRecordingIndicator();
        }
    };

    const cancelRecording = () => {
        if (mediaRecorderRef.current && isRecording) {
            shouldDiscardRecordingRef.current = true;
            mediaRecorderRef.current.stop();
            if (audioStreamRef.current) {
                audioStreamRef.current.getTracks().forEach((track) => track.stop());
                audioStreamRef.current = null;
            }
            setIsRecording(false);
            setIsPaused(false);
            setAudioBlob(null);
            if (audioURL && audioURL.startsWith('blob:')) {
                URL.revokeObjectURL(audioURL);
            }
            setAudioURL(null);
            setRecordingTime(0);
            chunksRef.current = [];
            stopRecordingIndicator();
            setAudioDuration((prev) => {
                const newState = { ...prev };
                delete newState['preview'];
                return newState;
            });
        }
    };

    const handleSendMessage = async (e) => {
        e.preventDefault();
        if ((!newMessage.trim() && attachments.length === 0 && !audioBlob) || sending) return;

        const messageBody = newMessage.trim();
        const formMessageBody = messageBody;
        const formAttachments = attachments;
        const formAudioBlob = audioBlob;
        const prevAudioURL = audioURL;
        let hasSentAnything = false;
        let shouldRevokePrevAudioUrl = Boolean(prevAudioURL && typeof prevAudioURL === 'string' && prevAudioURL.startsWith('blob:'));

        // Clear form (UI should feel instant)
        setNewMessage('');
        setAudioBlob(null);
        setAudioURL(null);
        setRecordingTime(0);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }

        const sendSingle = async ({ body, file, audio }) => {
            const tempId = Date.now() + Math.floor(Math.random() * 10000);
            pendingTempIdsRef.current.add(tempId);

            let attachmentPreviewUrl = null;
            const optimisticMessage = {
                id: tempId,
                tempId,
                pending: true,
                body: body || '',
                sender_id: currentUser.id,
                sender: {
                    id: currentUser.id,
                    name: currentUser.name,
                    image: currentUser.image,
                },
                attachment_path: null,
                attachment_type: null,
                attachment_name: null,
                attachment_size: null,
                is_read: false,
                read_at: null,
                created_at: new Date().toISOString(),
            };

            if (file) {
                attachmentPreviewUrl = URL.createObjectURL(file);
                optimisticMessage.attachment_path = attachmentPreviewUrl;
                optimisticMessage.attachment_name = file.name;
                optimisticMessage.attachment_size = file.size;
                optimisticMessage.attachment_type = file.type.startsWith('image/')
                    ? 'image'
                    : file.type.startsWith('video/')
                      ? 'video'
                      : 'file';
            }

            if (audio) {
                optimisticMessage.attachment_path = prevAudioURL;
                optimisticMessage.attachment_type = 'audio';
                optimisticMessage.attachment_name = 'voice-message.webm';
                optimisticMessage.attachment_size = audio.size;
                if (audioDuration['preview']) {
                    optimisticMessage.audio_duration = audioDuration['preview'];
                }
            }

            setMessages((prev) => [...prev, optimisticMessage]);
            scrollToBottom();

            const formData = new FormData();
            formData.append('body', body || '');
            formData.append('_token', getCsrfToken());

            if (file) {
                formData.append('attachment', file);
                const attachmentType = file.type.startsWith('image/') ? 'image' : file.type.startsWith('video/') ? 'video' : 'file';
                formData.append('attachment_type', attachmentType);
            }

            if (audio) {
                formData.append('attachment', audio, 'audio.webm');
                formData.append('attachment_type', 'audio');
            }

            try {
                const response = await fetch(`/chat/conversation/${conversation.id}/send`, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    body: formData,
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ error: 'Failed to send message' }));
                    throw new Error(errorData.error || 'Failed to send message');
                }

                const data = await response.json();
                const newMessageData = data.message;

                setMessages((prev) => {
                    if (attachmentPreviewUrl && attachmentPreviewUrl.startsWith('blob:')) {
                        URL.revokeObjectURL(attachmentPreviewUrl);
                    }
                    const filtered = prev.filter((msg) => msg.tempId !== tempId);
                    const updated = filtered.map((msg) => {
                        if (msg.sender_id !== currentUser.id && !msg.is_read) {
                            return { ...msg, is_read: true, read_at: new Date().toISOString() };
                        }
                        return msg;
                    });

                    const exists = updated.some((msg) => msg.id === newMessageData.id);
                    if (exists) return updated;

                    return [
                        ...updated,
                        {
                            ...newMessageData,
                            sender: newMessageData.sender || {
                                id: currentUser.id,
                                name: currentUser.name,
                                image: currentUser.image,
                            },
                        },
                    ];
                });

                pendingTempIdsRef.current.delete(tempId);
                scrollToBottom();
                hasSentAnything = true;
            } catch (error) {
                setMessages((prev) => prev.filter((msg) => msg.tempId !== tempId));
                pendingTempIdsRef.current.delete(tempId);
                if (attachmentPreviewUrl && attachmentPreviewUrl.startsWith('blob:')) {
                    URL.revokeObjectURL(attachmentPreviewUrl);
                }
                throw error;
            }
        };

        setSending(true);
        try {
            if (formAudioBlob) {
                await sendSingle({ body: formMessageBody, audio: formAudioBlob });
                // Audio was successfully uploaded; we can revoke the preview URL.
                shouldRevokePrevAudioUrl = true;
            } else if (formAttachments.length > 0) {
                // Send each attachment as its own message (backend currently supports one file per request)
                const remaining = [...formAttachments];
                for (let i = 0; i < formAttachments.length; i++) {
                    const file = remaining[0];
                    await sendSingle({ body: i === 0 ? formMessageBody : '', file });
                    remaining.shift();
                    setAttachments([...remaining]);
                }
                setAttachments([]);
            } else {
                await sendSingle({ body: formMessageBody });
            }

            draftsByConversationRef.current.delete(conversation.id);
        } catch (error) {
            // If sending attachments failed mid-loop, preserve whatever is still in state for retry.
            if (!hasSentAnything && formMessageBody) {
                setNewMessage(formMessageBody);
            }
            if (!hasSentAnything && formAudioBlob) {
                // Restore audio preview so user can retry without re-recording.
                setAudioBlob(formAudioBlob);
                setAudioURL(prevAudioURL);
                // Don't revoke the preview URL if we're restoring it back into state.
                shouldRevokePrevAudioUrl = false;
            }
            alert(error.message || 'Failed to send message. Please try again.');
        } finally {
            if (shouldRevokePrevAudioUrl && prevAudioURL && prevAudioURL.startsWith('blob:')) {
                URL.revokeObjectURL(prevAudioURL);
            }
            setSending(false);
            setAudioDuration((prev) => {
                const newState = { ...prev };
                delete newState['preview'];
                return newState;
            });
        }
    };

    // Format message time - static (no real-time updates)
    const formatMessageTime = (dateString) => {
        if (!dateString) return '';
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffSecs = Math.floor(diffMs / 1000);
        const diffMins = Math.floor(diffSecs / 60);
        const diffHours = Math.floor(diffMins / 60);
        const diffDays = Math.floor(diffHours / 24);

        if (diffSecs < 60) return 'few seconds ago';
        if (diffMins < 60) return diffMins === 1 ? '1 minute ago' : `${diffMins} minutes ago`;
        if (diffHours < 24 && isToday(date)) return format(date, 'h:mm a');
        if (isYesterday(date)) return `Yesterday at ${format(date, 'h:mm a')}`;
        if (diffDays < 7) return format(date, 'EEEE');
        return format(date, 'MMM d, yyyy');
    };

    const formatSeenTime = (dateString) => {
        if (!dateString) return null;
        const date = new Date(dateString);
        if (isToday(date)) return `Seen today at ${format(date, 'h:mm a')}`;
        if (isYesterday(date)) return `Seen yesterday at ${format(date, 'h:mm a')}`;
        return `Seen ${format(date, 'MMM d')}`;
    };

    const handlePlayAudio = (audioPath, messageId) => {
        if (isPlayingAudio === messageId) {
            const audio = document.getElementById(`audio-${messageId}`);
            if (audio) {
                audio.pause();
                audio.currentTime = 0;
                setIsPlayingAudio(null);
                setAudioProgress((prev) => ({ ...prev, [messageId]: 0 }));
            }
        } else {
            if (isPlayingAudio) {
                const currentAudio = document.getElementById(`audio-${isPlayingAudio}`);
                if (currentAudio) {
                    currentAudio.pause();
                    currentAudio.currentTime = 0;
                }
                setAudioProgress((prev) => ({ ...prev, [isPlayingAudio]: 0 }));
            }

            setIsPlayingAudio(messageId);
            setTimeout(() => {
                const audio = document.getElementById(`audio-${messageId}`);
                if (audio) {
                    // Jib duration dyal audio
                    const loadDuration = () => {
                        if (audio.duration && isFinite(audio.duration)) {
                            setAudioDuration((prev) => ({ ...prev, [messageId]: audio.duration }));
                        }
                    };

                    // Check ila duration kayna b9a
                    if (audio.readyState >= 1) {
                        loadDuration();
                    } else {
                        audio.addEventListener('loadedmetadata', loadDuration, { once: true });
                    }

                    audio.play();

                    const updateProgress = () => {
                        if (audio.duration) {
                            const percent = (audio.currentTime / audio.duration) * 100;
                            setAudioProgress((prev) => ({ ...prev, [messageId]: percent }));
                        }
                    };

                    audio.addEventListener('timeupdate', updateProgress);
                    audio.onended = () => {
                        setIsPlayingAudio(null);
                        setAudioProgress((prev) => ({ ...prev, [messageId]: 100 }));
                        audio.removeEventListener('timeupdate', updateProgress);
                    };
                    audio.onerror = () => {
                        setIsPlayingAudio(null);
                        setAudioProgress((prev) => ({ ...prev, [messageId]: 0 }));
                        alert('Failed to play audio');
                        audio.removeEventListener('timeupdate', updateProgress);
                    };
                }
            }, 100);
        }
    };

    const handleDeleteMessage = async (messageId) => {
        if (!confirm('Are you sure you want to delete this message?')) return;

        try {
            const response = await fetch(`/chat/message/${messageId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            });

            if (!response.ok) {
                throw new Error('Failed to delete message');
            }

            setMessages((prev) => prev.filter((msg) => msg.id !== messageId));
        } catch (error) {
            alert(error.message || 'Failed to delete message');
        }
    };

    const handleDownloadAttachment = (attachmentPath, attachmentName) => {
        if (!attachmentPath) return;
        const url =
            attachmentPath.startsWith('http://') ||
            attachmentPath.startsWith('https://') ||
            attachmentPath.startsWith('/api/') ||
            attachmentPath.startsWith('/chat/') ||
            attachmentPath.startsWith('/storage/') ||
            attachmentPath.startsWith('blob:')
                ? attachmentPath
                : `/storage/${attachmentPath}`;
        const link = document.createElement('a');
        link.href = url;
        link.download = attachmentName || 'attachment';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const getAttachmentsForPreview = () => {
        return messages
            .filter((m) => m.attachment_path && ['image', 'video'].includes(m.attachment_type))
            .map((m) => ({
                type: m.attachment_type,
                path: m.attachment_url || m.attachment_path,
                name: m.attachment_name,
            }));
    };

    const handlePreviewAttachment = (att) => {
        const all = getAttachmentsForPreview();
        const idx = all.findIndex((a) => a.path === att.path);
        setPreviewIndex(idx >= 0 ? idx : 0);
        setPreviewAttachment(all[idx >= 0 ? idx : 0]);
        setShowToolbox(false);
    };

    const handleNextPreview = () => {
        const all = getAttachmentsForPreview();
        if (all.length > 0) {
            const nextIndex = (previewIndex + 1) % all.length;
            setPreviewIndex(nextIndex);
            setPreviewAttachment(all[nextIndex]);
        }
    };

    const handlePreviousPreview = () => {
        const all = getAttachmentsForPreview();
        if (all.length > 0) {
            const prevIndex = (previewIndex - 1 + all.length) % all.length;
            setPreviewIndex(prevIndex);
            setPreviewAttachment(all[prevIndex]);
        }
    };

    const allAttachments = getAttachmentsForPreview();
    const hasMultipleAttachments = allAttachments.length > 1;

    return (
        <Sheet open={showToolbox} onOpenChange={setShowToolbox}>
            <div className="relative flex h-full overflow-hidden bg-background">
                {/* Main Chat Area */}
                <div className={cn('flex h-full w-full flex-col transition-all duration-300', previewAttachment && 'pointer-events-none opacity-0')}>
                    <ChatHeader
                        conversation={conversation}
                        onClose={showToolbox ? () => setShowToolbox(false) : onClose}
                        onBack={onBack}
                        onToolboxToggle={() => setShowToolbox(!showToolbox)}
                    />

                    <div className="flex min-h-0 flex-1 overflow-hidden">
                        <div className="relative min-h-0 flex-1">
                            {/* Subtle chat wallpaper */}
                            <div className="pointer-events-none absolute inset-0 bg-gradient-to-b from-alpha/5 via-transparent to-transparent opacity-60" />
                            <MessageList
                                messages={messages}
                                loading={loading}
                                currentUser={currentUser}
                                conversation={conversation}
                            isPlayingAudio={isPlayingAudio}
                            audioProgress={audioProgress}
                            audioDuration={audioDuration}
                            showMenuForMessage={showMenuForMessage}
                            onPlayAudio={handlePlayAudio}
                            onDeleteMessage={handleDeleteMessage}
                            onMenuToggle={setShowMenuForMessage}
                            onPreviewAttachment={handlePreviewAttachment}
                            onDownloadAttachment={handleDownloadAttachment}
                            formatMessageTime={formatMessageTime}
                            formatSeenTime={formatSeenTime}
                            messagesEndRef={messagesEndRef}
                            previewAttachment={previewAttachment}
                            typingUsers={typingUsers}
                            recordingUsers={recordingUsers}
                            />
                        </div>
                    </div>

                    <MessageInput
                        newMessage={newMessage}
                        setNewMessage={setNewMessage}
                        sending={sending}
                        isRecording={isRecording}
                        recordingTime={recordingTime}
                    attachments={attachments}
                    setAttachments={setAttachments}
                        audioBlob={audioBlob}
                        audioURL={audioURL}
                        setAudioBlob={setAudioBlob}
                        setAudioURL={setAudioURL}
                        mediaRecorderRef={mediaRecorderRef}
                        fileInputRef={fileInputRef}
                        handleFileSelect={handleFileSelect}
                        startRecording={startRecording}
                        stopRecording={stopRecording}
                        cancelRecording={cancelRecording}
                        handleSendMessage={handleSendMessage}
                        isExpanded={isExpanded}
                        audioDuration={audioDuration['preview']}
                        onTypingStart={startTyping}
                        onTypingStop={stopTyping}
                    isPaused={isPaused}
                        onPause={pauseRecording}
                        onResume={resumeRecording}
                    />
                </div>

                {/* Preview Panel - Full Width */}
                {previewAttachment && (
                    <div className="absolute inset-0 z-50 flex flex-col bg-background">
                        <PreviewPanel
                            attachment={previewAttachment}
                            onClose={() => setPreviewAttachment(null)}
                            onPrevious={handlePreviousPreview}
                            onNext={handleNextPreview}
                            hasMultiple={hasMultipleAttachments}
                            currentIndex={previewIndex}
                            totalCount={allAttachments.length}
                        />
                    </div>
                )}
            </div>

            {/* Toolbox rendered OUTSIDE chat container via portal */}
            {!previewAttachment && (
                <SheetContent side="right" className="p-0">
                    <ChatToolbox
                        conversationId={conversation.id}
                        otherUserId={conversation.other_user.id}
                        onPreviewAttachment={handlePreviewAttachment}
                        messages={messages}
                    />
                </SheetContent>
            )}
        </Sheet>
    );
}
