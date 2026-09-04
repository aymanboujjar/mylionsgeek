import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight, Download, FileIcon, X } from 'lucide-react';
import { useState } from 'react';
import { resolveAttachmentUrl } from './resolveAttachmentUrl';

export default function AttachmentPreview({ attachment, onClose }) {
    const [currentIndex, setCurrentIndex] = useState(0);

    if (!attachment) return null;

    const isImage = attachment.type === 'image' || attachment.path?.match(/\.(jpg|jpeg|png|gif|webp|svg)$/i);
    const isVideo = attachment.type === 'video' || attachment.path?.match(/\.(mp4|webm|mov|avi)$/i);
    const attachments = Array.isArray(attachment) ? attachment : [attachment];

    const currentAttachment = attachments[currentIndex];
    const hasMultiple = attachments.length > 1;
    const mediaUrl = resolveAttachmentUrl(currentAttachment.path);

    const goToPrevious = () => {
        setCurrentIndex((prev) => (prev === 0 ? attachments.length - 1 : prev - 1));
    };

    const goToNext = () => {
        setCurrentIndex((prev) => (prev === attachments.length - 1 ? 0 : prev + 1));
    };

    const handleDownload = () => {
        const link = document.createElement('a');
        link.href = mediaUrl;
        link.download = currentAttachment.name || 'attachment';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    return (
        <div className="fixed inset-0 z-[200] flex items-center justify-center bg-black/90">
            <div className="relative flex h-full w-full items-center justify-center p-4">
                {/* Close Button */}
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={onClose}
                    className="absolute top-4 right-4 z-10 h-10 w-10 bg-beta/80 text-light hover:bg-beta"
                >
                    <X className="h-5 w-5" />
                </Button>

                {/* Previous Button */}
                {hasMultiple && (
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={goToPrevious}
                        className="absolute left-4 z-10 h-12 w-12 bg-beta/80 text-light opacity-0 transition-opacity group-hover:opacity-100 hover:bg-beta"
                    >
                        <ChevronLeft className="h-6 w-6" />
                    </Button>
                )}

                {/* Next Button */}
                {hasMultiple && (
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={goToNext}
                        className="absolute right-4 z-10 h-12 w-12 bg-beta/80 text-light opacity-0 transition-opacity group-hover:opacity-100 hover:bg-beta"
                    >
                        <ChevronRight className="h-6 w-6" />
                    </Button>
                )}

                {/* Main Content */}
                <div className="flex max-h-[90vh] max-w-[90vw] items-center justify-center">
                    {isImage && currentAttachment.path && (
                        <img
                            src={mediaUrl}
                            alt={currentAttachment.name || 'Attachment'}
                            className="max-h-[90vh] max-w-full rounded-lg object-contain"
                        />
                    )}

                    {isVideo && currentAttachment.path && (
                        <video src={mediaUrl} controls className="max-h-[90vh] max-w-full rounded-lg" />
                    )}

                    {!isImage && !isVideo && currentAttachment.path && (
                        <div className="flex max-w-md flex-col items-center gap-4 rounded-lg bg-beta p-12 dark:bg-dark_gray">
                            <FileIcon className="h-24 w-24 text-alpha" />
                            <p className="text-center font-medium text-light">{currentAttachment.name || 'Attachment'}</p>
                            <Button onClick={handleDownload} className="bg-alpha text-black hover:bg-alpha/90">
                                <Download className="mr-2 h-4 w-4" />
                                Download
                            </Button>
                        </div>
                    )}
                </div>

                {/* Download Button */}
                {currentAttachment.path && (
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={handleDownload}
                        className="absolute right-4 bottom-4 z-10 h-12 w-12 bg-beta/80 text-light hover:bg-beta"
                    >
                        <Download className="h-5 w-5" />
                    </Button>
                )}

                {/* Counter */}
                {hasMultiple && (
                    <div className="absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full bg-beta/80 px-4 py-2 text-sm text-light">
                        {currentIndex + 1} / {attachments.length}
                    </div>
                )}
            </div>
        </div>
    );
}
