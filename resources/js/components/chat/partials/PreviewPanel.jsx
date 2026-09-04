import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight, Download, FileIcon, X } from 'lucide-react';
import { resolveAttachmentUrl } from '../resolveAttachmentUrl';

// Panel dial preview f right side dial chatbox
export default function PreviewPanel({ attachment, onClose, onPrevious, onNext, hasMultiple, currentIndex, totalCount }) {
    if (!attachment) return null;

    const isImage = attachment.type === 'image' || attachment.path?.match(/\.(jpg|jpeg|png|gif|webp|svg)$/i);
    const isVideo = attachment.type === 'video' || attachment.path?.match(/\.(mp4|webm|mov|avi)$/i);
    const mediaUrl = resolveAttachmentUrl(attachment.path);

    const handleDownload = () => {
        const link = document.createElement('a');
        link.href = mediaUrl;
        link.download = attachment.name || 'attachment';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    return (
        <div className="flex h-full w-full flex-col bg-dark_gray dark:bg-dark">
            {/* Controls Header */}
            <div className="flex shrink-0 items-center justify-between border-b bg-background p-4">
                <h3 className="text-sm font-semibold">Preview</h3>
                <div className="flex items-center gap-2">
                    {hasMultiple && (
                        <>
                            <Button variant="ghost" size="icon" onClick={onPrevious} className="h-8 w-8">
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            <span className="text-xs text-muted-foreground">
                                {currentIndex + 1} / {totalCount}
                            </span>
                            <Button variant="ghost" size="icon" onClick={onNext} className="h-8 w-8">
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    <Button variant="ghost" size="icon" onClick={handleDownload} className="h-8 w-8" title="Download">
                        <Download className="h-4 w-4" />
                    </Button>
                    <Button variant="ghost" size="icon" onClick={onClose} className="h-8 w-8">
                        <X className="h-4 w-4" />
                    </Button>
                </div>
            </div>

            {/* Content - Full Height */}
            <div className="flex flex-1 items-center justify-center overflow-auto bg-black/90 p-4">
                {isImage && attachment.path && (
                    <img
                        src={mediaUrl}
                        alt={attachment.name || 'Attachment'}
                        className="h-full w-full rounded-lg object-contain"
                    />
                )}

                {isVideo && attachment.path && (
                    <video
                        src={mediaUrl}
                        controls
                        className="h-full w-full rounded-lg object-contain"
                    />
                )}

                {!isImage && !isVideo && attachment.path && (
                    <div className="flex max-w-md flex-col items-center gap-4 rounded-lg bg-beta p-12 dark:bg-dark_gray">
                        <FileIcon className="h-24 w-24 text-alpha" />
                        <p className="text-center font-medium text-light">{attachment.name || 'Attachment'}</p>
                        <Button onClick={handleDownload} className="bg-alpha text-black hover:bg-alpha/90">
                            <Download className="mr-2 h-4 w-4" />
                            Download
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}
