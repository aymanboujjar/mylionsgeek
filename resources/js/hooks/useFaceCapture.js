import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Webcam capture for attendance face verification.
 * Live getUserMedia only — never a file/library picker.
 *
 * Note: Browsers require a secure context (HTTPS or localhost) for getUserMedia.
 * Plain HTTP on a LAN IP (e.g. http://192.168.x.x) blocks the API until the
 * origin is allowlisted in Chrome for local testing, or the site is served over HTTPS.
 */
export function useFaceCapture() {
    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const captureGenRef = useRef(0);
    const [isReady, setIsReady] = useState(false);
    const [capturedBlob, setCapturedBlob] = useState(null);
    /** @type {[null | 'insecure' | 'permission' | 'unsupported', Function]} */
    const [cameraError, setCameraError] = useState(null);
    const [isCapturing, setIsCapturing] = useState(false);
    const [wantsCamera, setWantsCamera] = useState(false);

    const releaseStream = useCallback(() => {
        if (streamRef.current) {
            streamRef.current.getTracks().forEach((track) => track.stop());
            streamRef.current = null;
        }
        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
        setIsReady(false);
    }, []);

    const stopCamera = useCallback(() => {
        captureGenRef.current += 1;
        releaseStream();
        setWantsCamera(false);
        setCapturedBlob(null);
        setCameraError(null);
        setIsCapturing(false);
    }, [releaseStream]);

    const attachStreamToVideo = useCallback(async () => {
        const video = videoRef.current;
        const stream = streamRef.current;

        if (!video || !stream) return;

        if (video.srcObject !== stream) {
            video.srcObject = stream;
        }
        try {
            await video.play();
        } catch {
            // muted + playsInline usually allows autoplay
        }
        setIsReady(true);
    }, []);

    const classifyCameraError = useCallback((error) => {
        const name = error?.name ?? '';
        const insecure =
            typeof window !== 'undefined' &&
            (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia);

        if (insecure || name === 'NotSupportedError' || name === 'SecurityError') {
            return 'insecure';
        }
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
            return 'permission';
        }

        return 'unsupported';
    }, []);

    const startCamera = useCallback(async () => {
        captureGenRef.current += 1;
        const gen = captureGenRef.current;
        releaseStream();
        setCameraError(null);
        setCapturedBlob(null);
        setIsReady(false);
        setIsCapturing(false);
        setWantsCamera(true);

        try {
            if (!navigator.mediaDevices?.getUserMedia) {
                throw Object.assign(new Error('getUserMedia unavailable'), { name: 'NotSupportedError' });
            }

            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user',
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                },
            });

            if (gen !== captureGenRef.current) {
                stream.getTracks().forEach((track) => track.stop());
                return;
            }

            streamRef.current = stream;
            setCameraError(null);
            await attachStreamToVideo();
        } catch (error) {
            if (gen !== captureGenRef.current) {
                return;
            }
            const reason = classifyCameraError(error);
            console.error('[useFaceCapture] getUserMedia error:', error);
            setCameraError(reason);
            setIsReady(false);
            setWantsCamera(false);
        }
    }, [attachStreamToVideo, classifyCameraError, releaseStream]);

    // Attach stream once the <video> element is mounted (after wantsCamera flips UI).
    useEffect(() => {
        if (!wantsCamera || cameraError || capturedBlob) return undefined;
        if (!streamRef.current) return undefined;

        let cancelled = false;
        const tryAttach = async () => {
            if (cancelled) return;
            await attachStreamToVideo();
        };
        tryAttach();

        return () => {
            cancelled = true;
        };
    }, [wantsCamera, cameraError, capturedBlob, attachStreamToVideo]);

    const capture = useCallback(() => {
        const video = videoRef.current;
        if (!video || !isReady || isCapturing) return;

        const gen = captureGenRef.current;
        setIsCapturing(true);

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 480;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            setIsCapturing(false);
            return;
        }

        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(
            (blob) => {
                if (gen !== captureGenRef.current) {
                    return;
                }

                releaseStream();
                setWantsCamera(false);
                if (blob) {
                    setCapturedBlob(blob);
                }
                setIsCapturing(false);
            },
            'image/jpeg',
            0.85,
        );
    }, [isReady, isCapturing, releaseStream]);

    const retake = useCallback(() => {
        setCapturedBlob(null);
        startCamera();
    }, [startCamera]);

    useEffect(() => {
        return () => stopCamera();
    }, [stopCamera]);

    return {
        videoRef,
        isReady,
        capturedBlob,
        cameraError,
        isCapturing,
        wantsCamera,
        startCamera,
        capture,
        retake,
        stopCamera,
    };
}
