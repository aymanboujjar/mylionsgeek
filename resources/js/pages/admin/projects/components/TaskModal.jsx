import ConfirmationModal from '@/components/ConfirmationModal';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Progress } from '@/components/ui/progress';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
import { format, formatDistanceToNow, isToday, isYesterday, parseISO } from 'date-fns';
import {
    AlertCircle,
    Archive,
    CheckCircle,
    CheckSquare,
    Clock,
    Download,
    Edit,
    File,
    FileArchive,
    FileAudio,
    FileCode,
    FileSpreadsheet,
    FileText,
    FileVideo,
    Flag,
    Image,
    MoreHorizontal,
    Paperclip,
    Pin,
    PinOff,
    Plus,
    Star,
    Trash2,
    UploadCloud,
    X,
    Zap,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

// Helper to get file icon based on type
const getFileIcon = (mimeType, fileName) => {
    if (mimeType.startsWith('image/')) return <Image className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
    if (mimeType.startsWith('video/')) return <FileVideo className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
    if (mimeType.startsWith('audio/')) return <FileAudio className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
    if (mimeType.includes('pdf')) return <FileText className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
    if (mimeType.includes('spreadsheet') || mimeType.includes('excel'))
        return <FileSpreadsheet className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
    if (mimeType.includes('text')) return <FileCode className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
    if (mimeType.includes('zip') || mimeType.includes('archive')) return <FileArchive className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;

    const extension = fileName.split('.').pop()?.toLowerCase();
    switch (extension) {
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'bmp':
            return <Image className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
        case 'mp4':
        case 'avi':
        case 'mov':
            return <FileVideo className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
        case 'mp3':
        case 'wav':
            return <FileAudio className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
        case 'pdf':
            return <FileText className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
        case 'xls':
        case 'xlsx':
        case 'csv':
            return <FileSpreadsheet className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
        case 'js':
        case 'ts':
        case 'jsx':
        case 'tsx':
        case 'html':
        case 'css':
        case 'json':
        case 'php':
            return <FileCode className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
        case 'zip':
        case 'rar':
        case '7z':
            return <FileArchive className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
        default:
            return <File className="h-5 w-5 text-dark/60 dark:text-zinc-400" />;
    }
};

// Helper to format relative time
const formatRelativeTime = (dateString) => {
    const date = parseISO(dateString);
    if (isToday(date)) {
        return formatDistanceToNow(date, { addSuffix: true });
    } else if (isYesterday(date)) {
        return 'Yesterday at ' + format(date, 'HH:mm');
    } else if (Date.now() - date.getTime() < 2 * 24 * 60 * 60 * 1000) {
        // Less than 2 days ago
        return formatDistanceToNow(date, { addSuffix: true });
    } else {
        return format(date, 'd/M/y HH:mm');
    }
};

// Helper component for sidebar buttons
const SidebarButton = ({ icon: Icon, label, onClick, isActive = false }) => (
    <Button
        onClick={onClick}
        variant="ghost"
        className={`w-full justify-start text-sm ${isActive ? 'bg-alpha/20 text-alpha hover:bg-alpha/30' : 'bg-zinc-700/50 text-zinc-300 hover:bg-zinc-700 hover:text-white'}`}
    >
        <Icon className="mr-2 h-4 w-4" />
        {label}
    </Button>
);

// Helper component for status popover
const StatusPopover = ({ currentStatus, onStatusChange, onClose, getStatusIcon, getStatusBadgeClass, canCompleteTask = false }) => {
    const statuses = [
        { value: 'todo', label: 'To Do' },
        { value: 'in_progress', label: 'In Progress' },
        { value: 'review', label: 'Review' },
        { value: 'completed', label: 'Completed' },
    ];

    return (
        <div className="absolute top-12 left-0 z-20 w-64 rounded-xl border-2 border-border bg-background p-3 shadow-xl">
            <div className="mb-3 flex items-center justify-between">
                <span className="text-sm font-semibold text-foreground">Change Status</span>
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={onClose}
                    className="h-7 w-7 rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <X className="h-4 w-4" />
                </Button>
            </div>
            <div className="space-y-1">
                {statuses
                    .filter((status) => status.value !== 'completed' || canCompleteTask)
                    .map((status) => {
                        const isSelected = currentStatus === status.value;
                        return (
                            <div
                                key={status.value}
                                onClick={() => {
                                    onStatusChange(status.value);
                                    onClose();
                                }}
                                className={`flex cursor-pointer items-center justify-between rounded-lg p-3 transition-colors hover:bg-accent ${
                                    isSelected ? 'border-2 border-primary/20 bg-primary/10' : 'border-2 border-transparent'
                                }`}
                            >
                                <div className="flex items-center gap-2">
                                    <div
                                        className={`flex items-center gap-1.5 rounded-lg px-3 py-1 text-xs font-semibold ${getStatusBadgeClass(status.value)}`}
                                    >
                                        {getStatusIcon(status.value)}
                                        {status.label}
                                    </div>
                                </div>
                                {isSelected && <CheckCircle className="h-4 w-4 text-primary" />}
                            </div>
                        );
                    })}
            </div>
        </div>
    );
};

// Helper component for member popover
const MemberPopover = ({ teamMembers = [], selectedAssigneeIds = [], onToggleAssignee, onClose }) => {
    const [search, setSearch] = useState('');
    const [hoveredId, setHoveredId] = useState(null);

    const filteredMembers = teamMembers.filter((m) => m.name.toLowerCase().includes(search.toLowerCase()));

    // Normalize ID comparison - handle both string and number IDs
    const isIdEqual = (id1, id2) => {
        if (id1 == null || id2 == null) return false;
        return String(id1) === String(id2);
    };

    const isSelected = (memberId) => {
        return selectedAssigneeIds.some((assigneeId) => isIdEqual(memberId, assigneeId));
    };

    const handleMemberClick = (memberId, e) => {
        // Allow clicks anywhere on the div
        e.preventDefault();
        e.stopPropagation();
        onToggleAssignee(memberId);
    };

    return (
        <div
            className="absolute top-0 right-0 z-[100] w-80 overflow-hidden rounded-lg border border-border bg-light p-0 shadow-xl dark:bg-dark"
            style={{ marginTop: '0px' }}
        >
            {/* Header */}
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Members</h3>
                <Button variant="ghost" size="icon" onClick={onClose} className="h-7 w-7">
                    <X className="h-4 w-4" />
                </Button>
            </div>

            {/* Search */}
            <div className="px-3 pt-3 pb-2">
                <Input placeholder="Search members" value={search} onChange={(e) => setSearch(e.target.value)} className="h-9 text-sm" autoFocus />
            </div>

            {/* Members List */}
            <ScrollArea className="h-64">
                <div className="px-2 py-2">
                    <div className="mb-1 px-2 py-1.5">
                        <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Board members</span>
                    </div>

                    <div className="space-y-0.5">
                        {filteredMembers.length === 0 ? (
                            <div className="px-3 py-8 text-center">
                                <p className="text-sm text-muted-foreground">No members found</p>
                            </div>
                        ) : (
                            filteredMembers.map((member) => {
                                const selected = isSelected(member.id);
                                const isHovered = hoveredId === member.id;

                                return (
                                    <div
                                        key={member.id}
                                        onClick={(e) => handleMemberClick(member.id, e)}
                                        onMouseEnter={() => setHoveredId(member.id)}
                                        onMouseLeave={() => setHoveredId(null)}
                                        className={`group relative flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-2 transition-colors ${
                                            selected ? 'bg-alpha/10' : isHovered ? 'bg-muted/50' : 'hover:bg-muted/30'
                                        } `}
                                    >
                                        {/* Avatar */}
                                        <div className="flex-shrink-0">
                                            <Avatar
                                                className="h-7 w-7"
                                                image={member.image}
                                                name={member.name}
                                                lastActivity={member.last_login ?? member.last_online ?? member.last_activity ?? null}
                                                onlineCircleClass="hidden"
                                            />
                                        </div>

                                        {/* Name */}
                                        <div className="min-w-0 flex-1">
                                            <span
                                                className={`block truncate text-sm ${selected ? 'font-medium text-foreground' : 'text-foreground'} `}
                                            >
                                                {member.name}
                                            </span>
                                        </div>

                                        {/* Selection Indicator */}
                                        <div className="flex-shrink-0">
                                            {selected ? (
                                                <div className="flex h-4 w-4 items-center justify-center rounded-full border-2 border-alpha bg-light dark:bg-dark">
                                                    <CheckCircle className="h-3 w-3 text-alpha" strokeWidth={2.5} fill="currentColor" />
                                                </div>
                                            ) : (
                                                <div className="h-4 w-4 rounded-full border-2 border-input transition-colors group-hover:border-alpha/50" />
                                            )}
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </div>
                </div>
            </ScrollArea>
        </div>
    );
};

// Helper component for attachment popover
const AttachPopover = ({ onClose, onFileUpload }) => {
    const [fileInput, setFileInput] = useState(null);

    const handleFileChange = (e) => {
        const files = Array.from(e.target.files);
        if (files.length > 0) {
            onFileUpload(files);
            onClose();
        }
    };

    return (
        <div className="absolute top-0 left-full z-20 ml-2 w-72 rounded-md border border-zinc-700 bg-zinc-800 p-3 shadow-lg">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-sm font-medium text-zinc-400">Attach</span>
                <Button onClick={onClose} variant="ghost" size="icon" className="h-7 w-7 text-zinc-400 hover:bg-zinc-700 hover:text-white">
                    <X className="h-4 w-4" />
                </Button>
            </div>
            <div className="space-y-2">
                <Button
                    variant="ghost"
                    onClick={() => fileInput?.click()}
                    className="w-full justify-start bg-zinc-700/50 text-sm text-zinc-300 hover:bg-zinc-700 hover:text-white"
                >
                    <UploadCloud className="mr-2 h-4 w-4" />
                    Attach a file from your computer
                </Button>
                <input ref={setFileInput} type="file" multiple onChange={handleFileChange} className="hidden" />
                <Input
                    placeholder="Search or paste a link"
                    className="border-border bg-light text-foreground placeholder:text-muted-foreground dark:bg-dark"
                />
            </div>
        </div>
    );
};

const TaskModal = ({
    projectId,
    setSelectedTask,
    isOpen,
    onClose,
    selectedTask,
    teamMembers = [],
    onUpdateTask,
    focusCommentInput = false,
    isProjectOwner = false,
    canEditTask = false,
    canDeleteTask = false,
}) => {
    const commentInputRef = useRef(null);
    const [isEditingTitle, setIsEditingTitle] = useState(false);
    const [isEditingDescription, setIsEditingDescription] = useState(false);
    const [isEditingPriority, setIsEditingPriority] = useState(false);
    const [isEditingStatus, setIsEditingStatus] = useState(false);
    const [newComment, setNewComment] = useState('');
    const [newSubtask, setNewSubtask] = useState('');
    const [newTag, setNewTag] = useState('');
    const [showSubtasks, setShowSubtasks] = useState(true);
    const [showAttachments, setShowAttachments] = useState(true);
    const [showMembersPopover, setShowMembersPopover] = useState(false);
    const [showAttachPopover, setShowAttachPopover] = useState(false);
    const [showStatusPopover, setShowStatusPopover] = useState(false);
    const [selectedFiles, setSelectedFiles] = useState([]);
    const [selectedUser, setSelectedUser] = useState(null);
    const [showUserModal, setShowUserModal] = useState(false);
    const [editingComment, setEditingComment] = useState(null);
    const [editingSubtask, setEditingSubtask] = useState(null);
    const [editingSubtaskTitle, setEditingSubtaskTitle] = useState('');
    const [isConfirmCommentDeleteModalOpen, setIsConfirmCommentDeleteModalOpen] = useState(false);
    const [commentToDelete, setCommentToDelete] = useState(null);
    const [isConfirmTaskArchiveModalOpen, setIsConfirmTaskArchiveModalOpen] = useState(false);
    const [isConfirmTaskDeleteModalOpen, setIsConfirmTaskDeleteModalOpen] = useState(false);
    const [taskToActOn, setTaskToActOn] = useState(null);

    const {
        data: taskData,
        setData: setTaskData,
        put: updateTask,
        processing: isUpdating,
        reset,
    } = useForm({
        title: '',
        description: '',
        priority: 'medium',
        status: 'todo',
        assigned_to: null,
        subtasks: [],
        tags: [],
        attachments: [],
        comments: [],
        assignees: [],
        is_pinned: false,
        progress: 0,
        due_date: '',
    });

    const { auth } = usePage().props;
    const normalizeAssigneeIds = (assignees = []) =>
        (assignees || [])
            .map((assignee) => (typeof assignee === 'object' && assignee !== null ? assignee.id : assignee))
            .filter((assigneeId) => assigneeId != null)
            .map((assigneeId) => Number(assigneeId));

    const primaryAssignedToId = selectedTask?.assigned_to?.id || selectedTask?.assigned_to || null;
    const selectedAssigneeIds = normalizeAssigneeIds(taskData.assignees);
    const visibleAssignees = selectedAssigneeIds
        .filter((assigneeId) => String(assigneeId) !== String(primaryAssignedToId))
        .map((assigneeId) => teamMembers.find((member) => String(member.id) === String(assigneeId)))
        .filter(Boolean);

    // Update form data when selectedTask changes
    useEffect(() => {
        if (selectedTask) {
            setTaskData({
                title: selectedTask.title || '',
                description: selectedTask.description || '',
                priority: selectedTask.priority || 'medium',
                status: selectedTask.status || 'todo',
                assigned_to: selectedTask.assigned_to?.id || selectedTask.assigned_to || null,
                subtasks: selectedTask.subtasks || [],
                tags: selectedTask.tags || [],
                attachments: selectedTask.attachments || [],
                comments: selectedTask.comments || [],
                assignees: selectedTask.assignees || [],
                is_pinned: selectedTask.is_pinned || false,
                progress: selectedTask.progress || 0,
                due_date: selectedTask.due_date || '',
            });
            // Ensure subtasks are shown if they exist
            if (selectedTask.subtasks?.length > 0) {
                setShowSubtasks(true);
            }
        }
    }, [selectedTask]);

    useEffect(() => {
        if (isOpen && focusCommentInput && commentInputRef.current) {
            commentInputRef.current.focus();
        }
    }, [isOpen, focusCommentInput]);

    const updateTaskData = (data) => {
        setTaskData(data);
    };

    const updateSelectedTask = (data) => {
        setSelectedTask(data);
    };

    if (!selectedTask) return null;

    // Priority and Status helpers
    const getPriorityColor = (priority) => {
        switch (priority) {
            case 'urgent':
                return 'bg-purple-500/20 text-purple-400 border-purple-500/30';
            case 'high':
                return 'bg-red-500/20 text-red-400 border-red-500/30';
            case 'medium':
                return 'bg-orange-500/20 text-orange-400 border-orange-500/30';
            case 'low':
                return 'bg-green-500/20 text-green-400 border-green-500/30';
            default:
                return 'bg-zinc-500/20 text-zinc-400 border-zinc-500/30';
        }
    };

    const getPriorityIcon = (priority) => {
        switch (priority) {
            case 'urgent':
                return <Zap className="h-3 w-3" />;
            case 'high':
                return <Flag className="h-3 w-3" />;
            case 'medium':
                return <Star className="h-3 w-3" />;
            case 'low':
                return <CheckCircle className="h-3 w-3" />;
            default:
                return <Star className="h-3 w-3" />;
        }
    };

    const getStatusBadgeClass = (status) => {
        switch (status) {
            case 'completed':
                return 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300';
            case 'in_progress':
                return 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300';
            case 'review':
                return 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300';
            case 'todo':
                return 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300';
            default:
                return 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300';
        }
    };

    const getPriorityBadgeClass = (priority) => {
        switch (priority) {
            case 'urgent':
                return 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300';
            case 'high':
                return 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300';
            case 'medium':
                return 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-300';
            case 'low':
                return 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300';
            default:
                return 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-300';
        }
    };

    const getStatusIcon = (status) => {
        switch (status) {
            case 'completed':
                return <CheckCircle className="h-3 w-3" />;
            case 'in_progress':
                return <Clock className="h-3 w-3" />;
            case 'review':
                return <AlertCircle className="h-3 w-3" />;
            case 'todo':
                return <CheckSquare className="h-3 w-3" />;
            default:
                return <CheckSquare className="h-3 w-3" />;
        }
    };

    // Update handlers
    const handleUpdateTitle = () => {
        if (taskData.title === selectedTask.title) {
            setIsEditingTitle(false);
            return;
        }
        router.patch(
            `/admin/tasks/${selectedTask.id}/title`,
            {
                title: taskData.title,
            },
            {
                onSuccess: () => {
                    updateTask();
                    setIsEditingTitle(false);
                    onUpdateTask?.();
                },
            },
        );
    };

    const handleUpdateDescription = () => {
        router.patch(
            `/admin/tasks/${selectedTask.id}/description`,
            {
                description: taskData.description,
            },
            {
                onSuccess: () => {
                    updateTask();
                    setIsEditingDescription(false);
                    onUpdateTask?.();
                },
            },
        );
    };

    const handleUpdatePriority = (priority) => {
        setTaskData('priority', priority);
        router.patch(
            `/admin/tasks/${selectedTask.id}/priority`,
            {
                priority: priority,
            },
            {
                onSuccess: () => {
                    updateTask();
                    setIsEditingPriority(false);
                    onUpdateTask?.();
                },
            },
        );
    };

    const handleUpdateStatus = (status) => {
        if (status === 'completed' && !isProjectOwner) {
            return;
        }

        if (status === 'completed') {
            const hasIncompleteSubtasks = (taskData.subtasks || []).some((subtask) => !subtask.completed);
            if (hasIncompleteSubtasks) {
                //alert('Cannot mark task as complete. Please complete all subtasks first.'); // Will be replaced by modal
                return;
            }
        }

        setTaskData('status', status);
        router.patch(
            `/admin/tasks/${selectedTask.id}/status`,
            {
                status: status,
            },
            {
                onSuccess: () => {
                    updateTask();
                    setIsEditingStatus(false);
                    onUpdateTask?.();
                },
            },
        );
    };

    const handleToggleAssignee = (memberId) => {
        if (String(memberId) === String(primaryAssignedToId)) {
            return;
        }

        const currentAssignees = normalizeAssigneeIds(taskData.assignees);
        const isSelected = currentAssignees.some((assigneeId) => String(assigneeId) === String(memberId));
        const newAssignees = isSelected
            ? currentAssignees.filter((assigneeId) => String(assigneeId) !== String(memberId))
            : [...currentAssignees, Number(memberId)];

        setTaskData('assignees', newAssignees);

        router.patch(
            `/admin/tasks/${selectedTask.id}/assigned-to`,
            {
                assigned_to: memberId,
            },
            {
                onSuccess: () => {
                    setTaskData('assignees', newAssignees);
                    updateTask();
                    onUpdateTask?.();
                },
                onError: () => {
                    setTaskData('assignees', currentAssignees);
                },
            },
        );
    };

    const handleTogglePin = () => {
        const newPinnedState = !taskData.is_pinned;
        setTaskData('is_pinned', newPinnedState);

        router.post(
            `/admin/tasks/${selectedTask.id}/pin`,
            {},
            {
                onSuccess: () => {
                    updateTask();
                    onUpdateTask?.();
                },
            },
        );
    };

    // Comment handlers
    const handleAddComment = () => {
        if (!newComment.trim()) return;

        if (editingComment) {
            // Update existing comment
            const updatedComments = (taskData.comments || []).map((comment) =>
                comment.id === editingComment.id ? { ...comment, content: newComment, updated_at: new Date().toISOString() } : comment,
            );
            setTaskData('comments', updatedComments);
            setNewComment('');
            setEditingComment(null);

            // Send to backend
            router.put(
                `/admin/tasks/${selectedTask.id}/comments/${editingComment.id}`,
                {
                    content: newComment,
                },
                {
                    onSuccess: () => {
                        updateTask();
                    },
                },
            );
        } else {
            // Add new comment
            const newCommentItem = {
                id: Date.now().toString() + Math.random().toString(36).substr(2, 9),
                content: newComment,
                user_id: auth.user.id,
                user: { id: auth.user.id, name: auth.user.name, email: auth.user.email, image: auth.user.image },
                created_at: new Date().toISOString(),
            };

            // Update UI instantly
            setTaskData('comments', [...(taskData.comments || []), newCommentItem]);
            setNewComment('');

            // Send to backend
            router.post(
                `/admin/tasks/${selectedTask.id}/comments`,
                {
                    content: newComment,
                },
                {
                    onSuccess: () => {
                        updateTask();
                    },
                },
            );
        }
    };

    // Subtask handlers
    const handleAddSubtask = () => {
        if (!newSubtask.trim()) return;

        const newSubtaskItem = {
            id: Date.now().toString() + Math.random().toString(36).substr(2, 9),
            title: newSubtask,
            completed: false,
        };

        // Update UI instantly
        setTaskData('subtasks', [...(taskData.subtasks || []), newSubtaskItem]);
        setNewSubtask('');

        // Send to backend
        router.post(
            `/admin/tasks/${selectedTask.id}/subtasks`,
            {
                title: newSubtask,
            },
            {
                onSuccess: () => {
                    updateTask();
                },
            },
        );
    };

    const handleToggleSubtask = (subtaskId) => {
        // Update UI instantly
        const updatedSubtasks = (taskData.subtasks || []).map((subtask) =>
            subtask.id === subtaskId ? { ...subtask, completed: !subtask.completed } : subtask,
        );
        setTaskData('subtasks', updatedSubtasks);

        // Send to backend
        router.put(
            `/admin/tasks/${selectedTask.id}/subtasks`,
            {
                subtask_id: subtaskId,
                completed: updatedSubtasks.find((s) => s.id === subtaskId)?.completed,
            },
            {
                onSuccess: () => {
                    updateTask();
                },
            },
        );
    };

    const handleDeleteSubtask = (subtaskId) => {
        // Update UI instantly
        const updatedSubtasks = (taskData.subtasks || []).filter((subtask) => subtask.id !== subtaskId);
        setTaskData('subtasks', updatedSubtasks);

        // Send to backend
        router.delete(
            `/admin/tasks/${selectedTask.id}/subtasks`,
            {
                data: { subtask_id: subtaskId },
            },
            {
                onSuccess: () => {
                    updateTask();
                },
            },
        );
    };

    const handleEditSubtask = (subtask) => {
        setEditingSubtask(subtask);
        setEditingSubtaskTitle(subtask.title);
    };

    const handleUpdateSubtask = () => {
        if (!editingSubtaskTitle.trim()) return;

        // Update UI instantly
        const updatedSubtasks = (taskData.subtasks || []).map((subtask) =>
            subtask.id === editingSubtask.id ? { ...subtask, title: editingSubtaskTitle } : subtask,
        );
        setTaskData('subtasks', updatedSubtasks);
        setEditingSubtask(null);
        setEditingSubtaskTitle('');

        // Send to backend
        router.put(
            `/admin/tasks/${selectedTask.id}/subtasks`,
            {
                subtask_id: editingSubtask.id,
                title: editingSubtaskTitle,
            },
            {
                onSuccess: () => {
                    updateTask();
                },
            },
        );
    };

    const getSubtaskProgress = () => {
        if (!taskData.subtasks || taskData.subtasks.length === 0) return 0;
        const completed = taskData.subtasks.filter((s) => s.completed).length;
        return Math.round((completed / taskData.subtasks.length) * 100);
    };

    // Tag handlers
    const handleAddTag = () => {
        if (!newTag.trim() || taskData.tags.includes(newTag.trim())) return;

        const newTags = [...taskData.tags, newTag.trim()];
        setTaskData('tags', newTags);

        router.put(
            `/admin/tasks/${selectedTask.id}`,
            {
                tags: newTags,
            },
            {
                onSuccess: () => {
                    updateTask();
                    setNewTag('');
                    onUpdateTask?.();
                },
            },
        );
    };

    const handleRemoveTag = (tagToRemove) => {
        const newTags = taskData.tags.filter((tag) => tag !== tagToRemove);
        setTaskData('tags', newTags);

        router.put(
            `/admin/tasks/${selectedTask.id}`,
            {
                tags: newTags,
            },
            {
                onSuccess: () => {
                    updateTask();
                    onUpdateTask?.();
                },
            },
        );
    };

    // File upload handlers
    const handleFileUpload = (files) => {
        const newAttachments = Array.from(files).map((file) => ({
            id: Date.now().toString() + Math.random().toString(36).substr(2, 9),
            name: file.name,
            size: file.size,
            type: file.type,
            path: URL.createObjectURL(file), // Temporary URL for preview
            uploaded_by: 1, // Current user ID
            uploaded_at: new Date().toISOString(),
        }));

        // Update UI instantly
        setTaskData('attachments', [...(taskData.attachments || []), ...newAttachments]);

        // Send to backend
        const formData = new FormData();
        files.forEach((file) => {
            formData.append('file', file);
        });

        router.post(`/admin/tasks/${selectedTask.id}/attachments`, formData, {
            forceFormData: true,
            onSuccess: () => {
                updateTask();
            },
        });
    };

    const handleRemoveAttachment = (attachmentId) => {
        // Update UI instantly
        const updatedAttachments = (taskData.attachments || []).filter((attachment) => attachment.id !== attachmentId);
        setTaskData('attachments', updatedAttachments);

        // Send to backend
        router.delete(
            `/admin/tasks/${selectedTask.id}/attachments`,
            {
                data: { attachment_id: attachmentId },
            },
            {
                onSuccess: () => {
                    updateTask();
                },
            },
        );
    };

    const handleUserClick = (user) => {
        if (user) {
            setSelectedUser(user);
            setShowUserModal(true);
        }
    };

    const handleEditComment = (comment) => {
        setEditingComment(comment);
        setNewComment(comment.content);
    };

    const handleDeleteComment = (comment) => {
        setCommentToDelete(comment);
        setIsConfirmCommentDeleteModalOpen(true);
    };

    const confirmDeleteComment = () => {
        if (commentToDelete) {
            // Update UI instantly
            const updatedComments = (taskData.comments || []).filter((comment) => comment.id !== commentToDelete.id);
            setTaskData('comments', updatedComments);

            // Send to backend
            router.delete(
                `/admin/tasks/${selectedTask.id}/comments/${commentToDelete.id}`,
                {},
                {
                    onSuccess: () => {
                        updateTask();
                        setIsConfirmCommentDeleteModalOpen(false);
                        setCommentToDelete(null);
                    },
                    onError: () => {
                        setIsConfirmCommentDeleteModalOpen(false);
                        setCommentToDelete(null);
                        //alert('Failed to delete comment.');
                    },
                },
            );
        }
    };

    const handleArchiveTask = () => {
        setTaskToActOn(selectedTask);
        setIsConfirmTaskArchiveModalOpen(true);
    };
    const handleProgression = () => {
        setShowStatusPopover(true);
    };

    const confirmArchiveTask = () => {
        if (taskToActOn) {
            router.patch(
                `/admin/tasks/${taskToActOn.id}/status`,
                { status: 'archived' },
                {
                    onSuccess: () => {
                        updateTask();
                        onUpdateTask?.();
                        setIsConfirmTaskArchiveModalOpen(false);
                        setTaskToActOn(null);
                        onClose();
                    },
                    onError: () => {
                        setIsConfirmTaskArchiveModalOpen(false);
                        setTaskToActOn(null);
                        //alert('Failed to archive task.');
                    },
                },
            );
        }
    };

    const handleDeleteTask = () => {
        setTaskToActOn(selectedTask);
        setIsConfirmTaskDeleteModalOpen(true);
    };

    const confirmDeleteTask = () => {
        if (taskToActOn) {
            router.delete(`/admin/tasks/${taskToActOn.id}`, {
                onSuccess: () => {
                    updateTask();
                    onUpdateTask?.();
                    setIsConfirmTaskDeleteModalOpen(false);
                    setTaskToActOn(null);
                    onClose();
                },
                onError: () => {
                    setIsConfirmTaskDeleteModalOpen(false);
                    setTaskToActOn(null);
                    //alert('Failed to delete task.');
                },
            });
        }
    };

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent
                showCloseButton={false}
                className="max-h-[96vh] !w-[96vw] !max-w-6xl overflow-hidden rounded-lg border bg-light p-0 shadow-xl dark:bg-dark"
            >
                {/* Clean Minimal Header */}
                <div className="flex items-center justify-between border-b border-border px-6 py-4">
                    <div className="flex items-center gap-3">
                        <div className="relative">
                            <button
                                onClick={() => canEditTask && setShowStatusPopover(true)}
                                className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${canEditTask ? 'cursor-pointer' : 'cursor-default'} ${getStatusBadgeClass(taskData.status)}`}
                            >
                                {getStatusIcon(taskData.status)}
                                <span className="capitalize">{taskData.status.replace('_', ' ')}</span>
                            </button>
                            {canEditTask && showStatusPopover && (
                                <StatusPopover
                                    currentStatus={taskData.status}
                                    onStatusChange={handleUpdateStatus}
                                    onClose={() => setShowStatusPopover(false)}
                                    getStatusIcon={getStatusIcon}
                                    getStatusBadgeClass={getStatusBadgeClass}
                                    canCompleteTask={isProjectOwner}
                                />
                            )}
                        </div>
                        <div
                            className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium ${getPriorityBadgeClass(taskData.priority)}`}
                        >
                            {getPriorityIcon(taskData.priority)}
                            <span className="capitalize">{taskData.priority}</span>
                        </div>
                    </div>

                    <div className="flex items-center gap-1">
                        {isProjectOwner && (
                            <Button variant="ghost" size="icon" onClick={handleTogglePin} className="h-8 w-8">
                                {taskData.is_pinned ? <PinOff className="h-4 w-4" /> : <Pin className="h-4 w-4" />}
                            </Button>
                        )}

                        {(isProjectOwner || canEditTask) && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" className="h-8 w-8">
                                        <MoreHorizontal className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {isProjectOwner && (
                                        <DropdownMenuItem onClick={() => handleArchiveTask()}>
                                            <Archive className="mr-2 h-4 w-4" />
                                            Archive Task
                                        </DropdownMenuItem>
                                    )}
                                    {canEditTask && (
                                        <DropdownMenuItem onClick={() => handleProgression()}>
                                            <Progress className="mr-2 h-4 w-4" />
                                            Progression
                                        </DropdownMenuItem>
                                    )}
                                    {canDeleteTask && (
                                        <DropdownMenuItem onClick={() => handleDeleteTask()} className="text-destructive">
                                            <Trash2 className="mr-2 h-4 w-4" />
                                            Delete Task
                                        </DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                        <Button variant="ghost" size="icon" onClick={onClose} className="h-8 w-8">
                            <X className="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                {/* Main Content */}
                <div className="flex h-[calc(96vh-80px)]">
                    {/* Left Column */}
                    <div className="flex-1 overflow-y-auto px-6 py-5">
                        <div className="max-w-4xl space-y-6">
                            {/* Task Title */}
                            <div>
                                {isEditingTitle ? (
                                    <div className="space-y-3">
                                        <Input
                                            value={taskData.title}
                                            onChange={(e) => setTaskData('title', e.target.value)}
                                            onBlur={handleUpdateTitle}
                                            onKeyPress={(e) => e.key === 'Enter' && handleUpdateTitle()}
                                            autoFocus
                                            className="rounded-none border-0 border-b-2 border-primary px-0 py-2 text-2xl font-semibold focus-visible:border-primary focus-visible:ring-0"
                                        />
                                        <div className="flex items-center gap-2">
                                            <Button onClick={handleUpdateTitle} disabled={isUpdating} size="sm" className="h-8">
                                                {isUpdating ? 'Saving...' : 'Save'}
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => setIsEditingTitle(false)} className="h-8">
                                                Cancel
                                            </Button>
                                        </div>
                                    </div>
                                ) : (
                                    <h1
                                        onClick={() => canEditTask && setIsEditingTitle(true)}
                                        className={`text-2xl font-semibold text-foreground transition-opacity ${canEditTask ? 'cursor-pointer hover:opacity-70' : ''}`}
                                    >
                                        {selectedTask.title || 'Untitled Task'}
                                    </h1>
                                )}
                            </div>

                            {/* Description */}
                            <div>
                                <label className="mb-2 block text-xs font-medium tracking-wide text-muted-foreground uppercase">Description</label>
                                {isEditingDescription ? (
                                    <div className="space-y-3">
                                        <Textarea
                                            value={taskData.description}
                                            onChange={(e) => setTaskData('description', e.target.value)}
                                            placeholder="Add a detailed description..."
                                            rows={5}
                                            className="min-h-[100px] resize-none"
                                        />
                                        <div className="flex items-center gap-2">
                                            <Button onClick={handleUpdateDescription} disabled={isUpdating} size="sm" className="h-8">
                                                {isUpdating ? 'Saving...' : 'Save'}
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => setIsEditingDescription(false)} className="h-8">
                                                Cancel
                                            </Button>
                                        </div>
                                    </div>
                                ) : (
                                    <div
                                        onClick={() => canEditTask && setIsEditingDescription(true)}
                                        className={`min-h-[100px] rounded-md border border-border p-3 transition-colors ${canEditTask ? 'cursor-pointer hover:border-primary/50' : ''}`}
                                    >
                                        {selectedTask.description ? (
                                            <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">{selectedTask.description}</p>
                                        ) : (
                                            <p className="text-sm text-muted-foreground italic">Add a detailed description...</p>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* Checklist */}
                            <div>
                                <div className="mb-3 flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <CheckSquare className="h-4 w-4 text-muted-foreground" />
                                        <h3 className="text-sm font-semibold text-foreground">Checklist</h3>
                                        <span className="rounded bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                            {getSubtaskProgress()}%
                                        </span>
                                    </div>
                                    <Button variant="ghost" size="sm" onClick={() => setShowSubtasks(!showSubtasks)} className="h-7 text-xs">
                                        {showSubtasks ? 'Hide' : 'Show'}
                                    </Button>
                                </div>

                                {showSubtasks && (
                                    <div className="space-y-3">
                                        {/* Add New Subtask */}
                                        {canEditTask && (
                                            <div className="flex gap-2">
                                                <Input
                                                    value={newSubtask}
                                                    onChange={(e) => setNewSubtask(e.target.value)}
                                                    onKeyPress={(e) => e.key === 'Enter' && handleAddSubtask()}
                                                    placeholder="Add item..."
                                                    className="h-9 text-sm"
                                                />
                                                <Button onClick={handleAddSubtask} size="sm" className="h-9">
                                                    <Plus className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        )}

                                        {/* Progress Bar */}
                                        <div>
                                            <div className="mb-1.5 flex items-center justify-between">
                                                <span className="text-xs text-muted-foreground">Progress</span>
                                                <span className="text-xs font-medium text-foreground">{getSubtaskProgress()}%</span>
                                            </div>
                                            <Progress value={getSubtaskProgress()} className="h-1.5" />
                                        </div>

                                        {/* Subtasks List */}
                                        <div className="space-y-1.5">
                                            {(taskData.subtasks || []).map((subtask) => (
                                                <div key={subtask.id} className="group flex items-center gap-2 rounded-md p-2 hover:bg-muted/50">
                                                    <Checkbox
                                                        id={`subtask-${subtask.id}`}
                                                        checked={subtask.completed}
                                                        onCheckedChange={() => canEditTask && handleToggleSubtask(subtask.id)}
                                                        disabled={!canEditTask}
                                                        className="h-4 w-4"
                                                    />
                                                    {editingSubtask?.id === subtask.id ? (
                                                        <div className="flex flex-1 items-center gap-2">
                                                            <Input
                                                                value={editingSubtaskTitle}
                                                                onChange={(e) => setEditingSubtaskTitle(e.target.value)}
                                                                onKeyPress={(e) => e.key === 'Enter' && handleUpdateSubtask()}
                                                                onBlur={handleUpdateSubtask}
                                                                autoFocus
                                                                className="h-8 flex-1 text-sm"
                                                            />
                                                            <Button size="sm" onClick={handleUpdateSubtask} className="h-8">
                                                                Save
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setEditingSubtask(null);
                                                                    setEditingSubtaskTitle('');
                                                                }}
                                                                className="h-8"
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </div>
                                                    ) : (
                                                        <>
                                                            <label
                                                                htmlFor={`subtask-${subtask.id}`}
                                                                className={`flex-1 cursor-pointer text-sm ${
                                                                    subtask.completed ? 'text-muted-foreground line-through' : 'text-foreground'
                                                                }`}
                                                            >
                                                                {subtask.title}
                                                            </label>
                                                            {canEditTask && (
                                                                <div className="flex items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        onClick={() => handleEditSubtask(subtask)}
                                                                        className="h-7 w-7"
                                                                    >
                                                                        <Edit className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        onClick={() => handleDeleteSubtask(subtask.id)}
                                                                        className="h-7 w-7 text-destructive hover:text-destructive"
                                                                    >
                                                                        <Trash2 className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                </div>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Attachments */}
                            <div>
                                <div className="mb-3 flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <Paperclip className="h-4 w-4 text-muted-foreground" />
                                        <h3 className="text-sm font-semibold text-foreground">Attachments</h3>
                                        <span className="rounded bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                            {(taskData.attachments || []).length}
                                        </span>
                                    </div>
                                </div>

                                {showAttachments && (
                                    <div className="space-y-2">
                                        {/* Upload Button */}
                                        {canEditTask && (
                                            <div className="rounded-md border border-dashed border-border p-3 transition-colors hover:border-primary/50">
                                                <input
                                                    type="file"
                                                    multiple
                                                    onChange={(e) => handleFileUpload(Array.from(e.target.files))}
                                                    className="hidden"
                                                    id="file-upload"
                                                />
                                                <label
                                                    htmlFor="file-upload"
                                                    className="flex cursor-pointer items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                                                >
                                                    <UploadCloud className="h-4 w-4" />
                                                    <span>Upload files</span>
                                                </label>
                                            </div>
                                        )}

                                        {/* Attachments List */}
                                        {(taskData.attachments || []).length > 0
                                            ? (taskData.attachments || []).map((attachment) => (
                                                  <div
                                                      key={attachment.id}
                                                      className="group flex items-center justify-between rounded-md p-2 hover:bg-muted/50"
                                                  >
                                                      <div className="flex min-w-0 flex-1 items-center gap-2">
                                                          <div className="rounded bg-muted p-1.5">
                                                              {getFileIcon(attachment.type, attachment.name)}
                                                          </div>
                                                          <div className="min-w-0 flex-1">
                                                              {attachment.type && attachment.type.startsWith('image/') ? (
                                                                  <Popover>
                                                                      <PopoverTrigger asChild>
                                                                          <p className="cursor-pointer truncate text-sm font-medium hover:text-primary">
                                                                              {attachment.name}
                                                                          </p>
                                                                      </PopoverTrigger>
                                                                      <PopoverContent className="w-auto p-0">
                                                                          <img
                                                                              src={route('admin.tasks.download-attachment', {
                                                                                  task: taskData.id,
                                                                                  attachmentId: attachment.id,
                                                                              })}
                                                                              alt={attachment.name}
                                                                              className="max-h-xs max-w-xs rounded"
                                                                          />
                                                                      </PopoverContent>
                                                                  </Popover>
                                                              ) : (
                                                                  <p className="truncate text-sm font-medium">{attachment.name}</p>
                                                              )}
                                                              <p className="text-xs text-muted-foreground">
                                                                  {attachment.size ? `${(attachment.size / 1024).toFixed(1)} KB` : 'Unknown size'}
                                                              </p>
                                                          </div>
                                                      </div>
                                                      <div className="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                                                          <a
                                                              className="rounded p-1.5 hover:bg-muted"
                                                              download={true}
                                                              href={route('admin.tasks.download-attachment', {
                                                                  task: taskData.id,
                                                                  attachmentId: attachment.id,
                                                              })}
                                                              target="_blank"
                                                              rel="noopener noreferrer"
                                                          >
                                                              <Download className="h-4 w-4" />
                                                          </a>
                                                          {canEditTask && (
                                                              <Button
                                                                  variant="ghost"
                                                                  size="icon"
                                                                  onClick={() => handleRemoveAttachment(attachment.id)}
                                                                  className="h-7 w-7 text-destructive hover:text-destructive"
                                                              >
                                                                  <Trash2 className="h-4 w-4" />
                                                              </Button>
                                                          )}
                                                      </div>
                                                  </div>
                                              ))
                                            : null}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Right Column - Sidebar */}
                    <div className="flex w-80 flex-col border-l border-border">
                        <div className="flex-1 space-y-5 overflow-y-auto p-5">
                            {/* Assigned To */}
                            <div>
                                <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Assigned To</h3>
                                <div className="flex flex-wrap items-center gap-2">
                                    {selectedTask.assigned_to && (
                                        <Avatar
                                            key={selectedTask.assigned_to.id || selectedTask.assigned_to}
                                            className="h-8 w-8"
                                            image={selectedTask.assigned_to.image}
                                            name={selectedTask.assigned_to.name}
                                            lastActivity={selectedTask.assigned_to.last_login ?? selectedTask.assigned_to.last_online ?? selectedTask.assigned_to.last_activity ?? null}
                                            onlineCircleClass="hidden"
                                        />
                                    )}
                                    {visibleAssignees.map((member) => (
                                        <Avatar
                                            key={member.id}
                                            className="h-8 w-8"
                                            image={member.image}
                                            name={member.name}
                                            lastActivity={member.last_login ?? member.last_online ?? member.last_activity ?? null}
                                            onlineCircleClass="hidden"
                                        />
                                    ))}
                                    {isProjectOwner && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => setShowMembersPopover(true)}
                                            className="h-8 w-8 border border-dashed"
                                        >
                                            <Plus className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>
                            </div>

                            {/* MemberPopover */}
                            {isProjectOwner && showMembersPopover && (
                                <MemberPopover
                                    key={`members-${selectedAssigneeIds.join('-') || 'none'}`}
                                    teamMembers={teamMembers}
                                    selectedAssigneeIds={[...selectedAssigneeIds, primaryAssignedToId].filter(Boolean)}
                                    onToggleAssignee={handleToggleAssignee}
                                    onClose={() => setShowMembersPopover(false)}
                                />
                            )}

                            {/* Comments */}
                            <div className="flex flex-1 flex-col">
                                <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Comments</h3>

                                {/* New Comment Input */}
                                <div className="mb-3">
                                    <Textarea
                                        ref={commentInputRef}
                                        value={newComment}
                                        onChange={(e) => setNewComment(e.target.value)}
                                        placeholder="Write a comment..."
                                        rows={2}
                                        className="resize-none text-sm"
                                    />
                                    {newComment && (
                                        <div className="mt-2 flex items-center gap-2">
                                            <Button onClick={handleAddComment} size="sm" className="h-7 text-xs">
                                                {editingComment ? 'Update' : 'Post'}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => {
                                                    setNewComment('');
                                                    setEditingComment(null);
                                                }}
                                                className="h-7 text-xs"
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    )}
                                </div>

                                {/* Comments List */}
                                <ScrollArea className="flex-1 pr-2">
                                    <div className="space-y-2">
                                        {(taskData.comments || []).map((comment) => (
                                            <div key={comment.id} className="flex gap-2 rounded-md p-2 hover:bg-muted/50">
                                                <Avatar
                                                    className="h-7 w-7 flex-shrink-0 cursor-pointer"
                                                    image={comment.user?.image}
                                                    name={comment.user?.name}
                                                    lastActivity={comment.user?.last_login ?? comment.user?.last_online ?? comment.user?.last_activity ?? null}
                                                    onlineCircleClass="hidden"
                                                    onClick={() => handleUserClick(comment.user)}
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <div className="mb-0.5 flex items-center justify-between">
                                                        <div className="flex items-baseline gap-1.5">
                                                            <span
                                                                className="cursor-pointer text-xs font-medium text-foreground hover:text-primary"
                                                                onClick={() => handleUserClick(comment.user)}
                                                            >
                                                                {comment.user?.name === auth.user.name ? 'You' : comment.user?.name || 'Unknown User'}
                                                            </span>
                                                            <span className="text-xs text-muted-foreground">
                                                                {formatRelativeTime(comment.created_at)}
                                                            </span>
                                                        </div>
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button variant="ghost" size="icon" className="h-6 w-6">
                                                                    <MoreHorizontal className="h-3.5 w-3.5" />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem onClick={() => handleEditComment(comment)}>
                                                                    <Edit className="mr-2 h-4 w-4" />
                                                                    Edit
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    onClick={() => handleDeleteComment(comment)}
                                                                    className="text-destructive"
                                                                >
                                                                    <Trash2 className="mr-2 h-4 w-4" />
                                                                    Delete
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </div>
                                                    <p className="text-xs leading-relaxed break-words text-foreground">{comment.content}</p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </ScrollArea>
                            </div>
                        </div>
                    </div>
                </div>
            </DialogContent>

            {/* User Profile Dialog */}
            {showUserModal && selectedUser && (
                <Dialog open={showUserModal} onOpenChange={setShowUserModal}>
                    <DialogContent className="max-w-md rounded-lg border-border bg-light shadow-lg dark:bg-dark">
                        <div className="mb-6 flex items-center justify-between">
                            <h2 className="text-xl font-semibold text-foreground">User Profile</h2>
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => setShowUserModal(false)}
                                className="text-muted-foreground hover:bg-accent hover:text-foreground"
                            >
                                <X className="h-5 w-5" />
                            </Button>
                        </div>

                        <div className="space-y-6">
                            <div className="flex items-center gap-4">
                                <Avatar
                                    className="relative z-50 h-16 w-16 overflow-hidden"
                                    image={selectedUser.image}
                                    name={selectedUser.name}
                                    lastActivity={selectedUser.last_login ?? selectedUser.last_online ?? selectedUser.last_activity ?? null}
                                    onlineCircleClass="hidden"
                                />
                                <div>
                                    <h3 className="text-lg font-semibold text-foreground">{selectedUser.name}</h3>
                                    <p className="text-sm text-muted-foreground">{selectedUser.email}</p>
                                    {selectedUser.role && (
                                        <Badge className="mt-1 border-primary/30 bg-primary/10 text-primary">{selectedUser.role}</Badge>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-4">
                                <div>
                                    <label className="text-sm font-medium text-foreground">Email</label>
                                    <p className="text-sm text-muted-foreground">{selectedUser.email}</p>
                                </div>

                                {selectedUser.phone && (
                                    <div>
                                        <label className="text-sm font-medium text-foreground">Phone</label>
                                        <p className="text-sm text-muted-foreground">{selectedUser.phone}</p>
                                    </div>
                                )}

                                {selectedUser.cin && (
                                    <div>
                                        <label className="text-sm font-medium text-foreground">CIN</label>
                                        <p className="text-sm text-muted-foreground">{selectedUser.cin}</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </DialogContent>
                </Dialog>
            )}

            {/* Confirmation Modal for Comment Deletion */}
            <ConfirmationModal
                isOpen={isConfirmCommentDeleteModalOpen}
                onClose={() => setIsConfirmCommentDeleteModalOpen(false)}
                onConfirm={confirmDeleteComment}
                title="Confirm Comment Deletion"
                description="Are you sure you want to delete this comment? This action cannot be undone."
                isDestructive={true}
            />

            {/* Confirmation Modal for Task Archive */}
            <ConfirmationModal
                isOpen={isConfirmTaskArchiveModalOpen}
                onClose={() => setIsConfirmTaskArchiveModalOpen(false)}
                onConfirm={confirmArchiveTask}
                title="Confirm Task Archive"
                description="Are you sure you want to archive this task? This action cannot be undone."
                isDestructive={true}
            />

            {/* Confirmation Modal for Task Deletion */}
            <ConfirmationModal
                isOpen={isConfirmTaskDeleteModalOpen}
                onClose={() => setIsConfirmTaskDeleteModalOpen(false)}
                onConfirm={confirmDeleteTask}
                title="Confirm Task Deletion"
                description="Are you sure you want to delete this task? This action cannot be undone."
                isDestructive={true}
            />
        </Dialog>
    );
};

export default TaskModal;
