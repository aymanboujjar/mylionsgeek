const LoadingOverlay = ({ message = 'Loading...', fullscreen = false }) => {
    const baseClasses = fullscreen ? 'fixed inset-0 z-[60]' : 'absolute inset-0 z-[60]';

    return (
        <div className={`${baseClasses} flex items-center justify-center bg-black/50 backdrop-blur-sm`}>
            <div className="flex flex-col items-center gap-3 text-white">
                <span className="h-10 w-10 animate-spin rounded-full border-4 border-dark_gray0 border-t-white" />
                {message && <p className="text-sm font-medium">{message}</p>}
            </div>
        </div>
    );
};

export default LoadingOverlay;
