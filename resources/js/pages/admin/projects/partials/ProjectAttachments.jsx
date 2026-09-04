import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Download, File, FileArchive, FileAudio, FileCode, FileSpreadsheet, FileText, FileVideo, Image, Paperclip } from 'lucide-react';

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

const ProjectAttachments = ({ attachments = [] }) => {
    return (
        <div className="space-y-4">
            <h2 className="text-xl font-semibold text-dark dark:text-light">Project Attachments</h2>
            {attachments.length > 0 ? (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {attachments.map((attachment) => (
                        <div
                            key={attachment.id}
                            className="flex items-center justify-between rounded-xl border border-alpha/20 bg-light/80 p-4 shadow-sm dark:bg-neutral-800"
                        >
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-light/30 p-3 dark:bg-zinc-700">
                                    {getFileIcon(attachment.mime_type, attachment.original_name)}
                                </div>
                                <div>
                                    {attachment.mime_type && attachment.mime_type.startsWith('image/') ? (
                                        <Popover>
                                            <PopoverTrigger asChild>
                                                <p className="cursor-pointer font-medium text-dark transition-colors hover:text-alpha dark:text-light">
                                                    {attachment.original_name}
                                                </p>
                                            </PopoverTrigger>
                                            <PopoverContent className="w-auto border-none bg-white p-0 shadow-none">
                                                <img
                                                    src={route('admin.attachments.download', attachment.id)}
                                                    alt={attachment.original_name}
                                                    className="max-h-xs max-w-xs rounded-lg shadow-xl"
                                                />
                                            </PopoverContent>
                                        </Popover>
                                    ) : (
                                        <p className="font-medium text-dark dark:text-light">{attachment.original_name}</p>
                                    )}
                                    <p className="text-xs text-dark/50 dark:text-zinc-400">
                                        {attachment.size ? `${(attachment.size / 1024).toFixed(1)} KB` : 'Unknown size'}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <a
                                    className="rounded-md p-2 text-dark/60 hover:bg-alpha/20 hover:text-alpha dark:text-alpha dark:hover:text-alpha"
                                    download={true}
                                    href={route('admin.attachments.download', attachment.id)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Download className="h-5 w-5" />
                                </a>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="group rounded-xl border-2 border-dashed border-alpha/30 bg-gradient-to-br from-light/40 to-light/20 py-16 text-center transition-all duration-300 hover:from-alpha/10 hover:to-alpha/5 dark:from-dark/40 dark:to-dark/20">
                    <Paperclip className="mx-auto mb-6 h-20 w-20 text-alpha/60 transition-colors group-hover:text-alpha" />
                    <p className="mb-2 text-lg font-semibold text-dark/80 transition-colors group-hover:text-dark dark:text-light/80 dark:group-hover:text-light">
                        No attachments yet
                    </p>
                    <p className="mb-8 text-sm text-dark/50 transition-colors group-hover:text-dark/70 dark:text-light/50 dark:group-hover:text-light/70">
                        Project has no attachments.
                    </p>
                </div>
            )}
        </div>
    );
};

export default ProjectAttachments;
