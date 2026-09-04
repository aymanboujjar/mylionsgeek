import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, useForm } from '@inertiajs/react';
import {
    Download,
    ExternalLink,
    File,
    FileArchive,
    FileAudio,
    FileCode,
    FileSpreadsheet,
    FileText,
    FileVideo,
    Image,
    MoreHorizontal,
    Paperclip,
    Search,
    Trash,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
const Files = ({ projectAttachments = [], taskAttachments = [], projectId }) => {
    const [searchTerm, setSearchTerm] = useState('');
    const [isUploadModalOpen, setIsUploadModalOpen] = useState(false);
    const [uploadFile, setUploadFile] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [fileFilter, setFileFilter] = useState('all');

    // Combine all attachments (project + task attachments)
    const allAttachments = [
        ...projectAttachments.map((att) => ({ ...att, source: 'project' })),
        ...taskAttachments.map((att) => ({ ...att, source: 'task' })),
    ];

    const filteredFiles = allAttachments.filter((file) => {
        const matchesSearch =
            file.original_name?.toLowerCase()?.includes(searchTerm?.toLowerCase()) || file.name?.toLowerCase()?.includes(searchTerm?.toLowerCase());

        if (!matchesSearch) return false;

        if (fileFilter === 'all') return true;

        const mimeType = file.mime_type || file.type || '';
        switch (fileFilter) {
            case 'images':
                return mimeType.startsWith('image/');
            case 'documents':
                return mimeType.includes('pdf') || mimeType.includes('document') || mimeType.includes('text');
            case 'videos':
                return mimeType.startsWith('video/');
            case 'audio':
                return mimeType.startsWith('audio/');
            case 'archives':
                return mimeType.includes('zip') || mimeType.includes('archive') || mimeType.includes('rar');
            default:
                return true;
        }
    });

    const getFileIcon = (mimeType, fileName) => {
        if (mimeType?.startsWith('image/')) return <Image className="h-12 w-12 text-pink-500" />;
        if (mimeType?.startsWith('video/')) return <FileVideo className="h-12 w-12 text-purple-500" />;
        if (mimeType?.startsWith('audio/')) return <FileAudio className="h-12 w-12 text-blue-500" />;
        if (mimeType?.includes('pdf')) return <FileText className="h-12 w-12 text-rose-500" />;
        if (mimeType?.includes('spreadsheet') || mimeType?.includes('excel')) return <FileSpreadsheet className="h-12 w-12 text-green-500" />;
        if (mimeType?.includes('text')) return <FileCode className="h-12 w-12 text-amber-500" />;
        if (mimeType?.includes('zip') || mimeType?.includes('archive')) return <FileArchive className="h-12 w-12 text-gray-500" />;

        const extension = fileName.split('.').pop()?.toLowerCase();
        switch (extension) {
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'bmp':
                return <Image className="h-12 w-12 text-pink-500" />;
            case 'mp4':
            case 'avi':
            case 'mov':
                return <FileVideo className="h-12 w-12 text-purple-500" />;
            case 'mp3':
            case 'wav':
                return <FileAudio className="h-12 w-12 text-blue-500" />;
            case 'pdf':
                return <FileText className="h-12 w-12 text-rose-500" />;
            case 'xls':
            case 'xlsx':
            case 'csv':
                return <FileSpreadsheet className="h-12 w-12 text-green-500" />;
            case 'js':
            case 'ts':
            case 'jsx':
            case 'tsx':
            case 'html':
            case 'css':
            case 'json':
            case 'php':
                return <FileCode className="h-12 w-12 text-amber-500" />;
            case 'zip':
            case 'rar':
            case '7z':
                return <FileArchive className="h-12 w-12 text-gray-500" />;
            default:
                return <File className="h-12 w-12 text-gray-500" />;
        }
    };

    const { data, setData, post, processing, reset } = useForm({
        file: null,
        project_id: '',
    });

    const handleFileChange = (e) => {
        const file = e.target.files[0];
        setUploadFile(file);
        setData('file', file);
        setData('project_id', projectId);
    };

    const handleUpload = () => {
        if (!data.file) {
            alert('Please select a file first.');
            return;
        }

        post('/admin/projects/attachments', {
            forceFormData: true,
            onSuccess: () => {
                reset();
                setUploadFile(null);
                setIsUploadModalOpen(false);
                alert('hh!');
            },
            onError: (errors) => {
                console.error('Upload error:', errors);
                alert('hhhhhhhh');
            },
        });
    };

    const handleDelete = (attachmentId, source) => {
        if (source === 'project') {
            router.delete(`/admin/projects/attachments/${attachmentId}`, {
                onSuccess: () => {
                    // The page will reload with the attachment removed
                },
                onError: (errors) => {
                    console.error('Delete failed:', errors);
                },
            });
        } else {
            // For task attachments, we need to find the task and remove the attachment
            // This would require additional backend logic
            //('Task attachment deletion not implemented yet');
        }
    };

    return (
        <div className="space-y-6">
            {/* Header and Search */}
            <div className="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative">
                        <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            type="search"
                            placeholder="Search files..."
                            className="w-[200px] pl-8 md:w-[300px]"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                        />
                    </div>

                    {/* File Type Filter */}
                    <Select value={fileFilter} onValueChange={setFileFilter}>
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="Filter by type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Files</SelectItem>
                            <SelectItem value="images">Images</SelectItem>
                            <SelectItem value="documents">Documents</SelectItem>
                            <SelectItem value="videos">Videos</SelectItem>
                            <SelectItem value="audio">Audio</SelectItem>
                            <SelectItem value="archives">Archives</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <Button onClick={() => setIsUploadModalOpen(true)}>
                    <Upload className="mr-2 h-4 w-4" />
                    Upload File
                </Button>
            </div>

            {/* Files Grid */}
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                {filteredFiles.length === 0 ? (
                    <div className="group col-span-full rounded-xl border-2 border-dashed border-alpha/30 bg-gradient-to-br from-light/40 to-light/20 py-16 text-center transition-all duration-300 hover:from-alpha/10 hover:to-alpha/5 dark:from-dark/40 dark:to-dark/20">
                        <Paperclip className="mx-auto mb-6 h-20 w-20 text-alpha/60 transition-colors group-hover:text-alpha" />
                        <p className="mb-2 text-lg font-semibold text-dark/80 transition-colors group-hover:text-dark dark:text-light/80 dark:group-hover:text-light">
                            {searchTerm ? 'No files match your search' : 'No files uploaded yet'}
                        </p>
                        <p className="mb-8 text-sm text-dark/50 transition-colors group-hover:text-dark/70 dark:text-light/50 dark:group-hover:text-light/70">
                            Upload files to get started
                        </p>
                    </div>
                ) : (
                    filteredFiles.map((file) => (
                        <Card
                            key={`${file.source}-${file.id}`}
                            className="overflow-hidden border shadow-sm transition-shadow duration-200 hover:shadow-md"
                        >
                            <CardContent className="p-0">
                                <div className="relative flex items-center justify-center bg-muted/30 p-6">
                                    {getFileIcon(file.mime_type, file.original_name || file.name)}
                                    <Badge variant="outline" className="absolute top-2 right-2 text-xs">
                                        {file.source}
                                    </Badge>
                                </div>
                                <div className="p-4">
                                    <div className="mb-2 flex items-center justify-between">
                                        <div className="min-w-0 flex-1">
                                            {file.type && file.type?.startsWith('image/') ? (
                                                <Popover>
                                                    <PopoverTrigger asChild>
                                                        <h3 className="cursor-pointer truncate font-medium transition-colors hover:text-alpha">
                                                            {file.original_name || file.name}
                                                        </h3>
                                                    </PopoverTrigger>
                                                    <PopoverContent className="w-auto rounded-lg border border-gray-200 bg-white p-4 shadow-xl">
                                                        <img
                                                            src={route('admin.attachments.download', file.id)}
                                                            alt={file.original_name || file.name}
                                                            className="max-h-sm max-w-sm rounded-lg shadow-sm"
                                                        />
                                                    </PopoverContent>
                                                </Popover>
                                            ) : (
                                                <h3 className="truncate font-medium">{file.original_name || file.name}</h3>
                                            )}
                                        </div>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" className="h-8 w-8">
                                                    <MoreHorizontal className="h-4 w-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem asChild>
                                                    <a
                                                        href={route('admin.attachments.download', file.id)}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="flex w-full items-center"
                                                    >
                                                        <ExternalLink className="mr-2 h-4 w-4" />
                                                        <span>Open</span>
                                                    </a>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem asChild>
                                                    <a
                                                        href={route('admin.attachments.download', file.id)}
                                                        download={file.original_name || file.name}
                                                        className="flex w-full items-center"
                                                    >
                                                        <Download className="mr-2 h-4 w-4" />
                                                        <span>Download</span>
                                                    </a>
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                {file.source === 'project' && (
                                                    <DropdownMenuItem className="text-destructive" onClick={() => handleDelete(file.id, file.source)}>
                                                        <Trash className="mr-2 h-4 w-4" />
                                                        <span>Delete</span>
                                                    </DropdownMenuItem>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <Avatar
                                                className="h-16 w-16"
                                                image={file.uploader?.image}
                                                name={file.uploader?.name}
                                                lastActivity={file.uploader?.last_login ?? file.uploader?.last_online ?? file.uploader?.last_activity ?? null}
                                                onlineCircleClass="hidden"
                                                edit={false}
                                            />
                                            <div className="flex flex-col">
                                                <span className="text-xs font-medium text-foreground">{file.uploader?.name || 'Unknown User'}</span>
                                                <span className="text-xs text-muted-foreground">
                                                    {file.size ? `${(file.size / 1024).toFixed(1)} KB` : 'Unknown size'}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <span className="block text-xs text-muted-foreground">
                                                {new Date(file.uploaded_at).toLocaleDateString()}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {new Date(file.uploaded_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>

            {/* Upload File Modal */}
            <Dialog open={isUploadModalOpen} onOpenChange={setIsUploadModalOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Upload File</DialogTitle>
                        <DialogDescription>Choose a file to upload to this project</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label htmlFor="file">Select File</Label>
                            <Input id="file" type="file" onChange={handleFileChange} />
                        </div>
                        {uploadFile && (
                            <div className="rounded-lg bg-muted p-3">
                                <div className="flex items-center gap-2">
                                    <FileText className="h-4 w-4" />
                                    <span className="text-sm font-medium">{uploadFile.name}</span>
                                    <span className="text-xs text-muted-foreground">({(uploadFile.size / 1024 / 1024).toFixed(2)} MB)</span>
                                </div>
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsUploadModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={handleUpload} disabled={!uploadFile}>
                            Upload File
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
};

export default Files;
