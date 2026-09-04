/** Resolve chat/project attachment path to a gated or legacy URL. */
export function resolveAttachmentUrl(path) {
    if (!path) return '';
    if (
        path.startsWith('http://') ||
        path.startsWith('https://') ||
        path.startsWith('/api/') ||
        path.startsWith('/chat/') ||
        path.startsWith('/admin/') ||
        path.startsWith('/storage/') ||
        path.startsWith('blob:')
    ) {
        return path;
    }

    return `/storage/${path}`;
}
