import { useCallback, useRef, useState } from 'react';

export type AgentState = 'idle' | 'listening' | 'thinking' | 'speaking' | 'error';

export type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
};

const MAX_HISTORY_TURNS = 12;

export function useVoiceAgent() {
    const [state, setState] = useState<AgentState>('idle');
    const [level, setLevel] = useState(0);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const mediaRecorderRef = useRef<MediaRecorder | null>(null);
    const chunksRef = useRef<Blob[]>([]);
    const streamRef = useRef<MediaStream | null>(null);
    const audioCtxRef = useRef<AudioContext | null>(null);
    const rafRef = useRef<number | null>(null);

    const stopLevelLoop = useCallback(() => {
        if (rafRef.current) cancelAnimationFrame(rafRef.current);
        rafRef.current = null;
        setLevel(0);
    }, []);

    const watchLevel = useCallback((analyser: AnalyserNode) => {
        const data = new Uint8Array(analyser.frequencyBinCount);

        const tick = () => {
            analyser.getByteTimeDomainData(data);
            let sumSquares = 0;
            for (let i = 0; i < data.length; i++) {
                const centered = (data[i] - 128) / 128;
                sumSquares += centered * centered;
            }
            const rms = Math.sqrt(sumSquares / data.length);
            setLevel(Math.min(1, rms * 4));
            rafRef.current = requestAnimationFrame(tick);
        };

        tick();
    }, []);

    const startListening = useCallback(async () => {
        setErrorMessage(null);

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            streamRef.current = stream;

            const audioCtx = new AudioContext();
            audioCtxRef.current = audioCtx;
            const source = audioCtx.createMediaStreamSource(stream);
            const analyser = audioCtx.createAnalyser();
            analyser.fftSize = 512;
            source.connect(analyser);
            watchLevel(analyser);

            const recorder = new MediaRecorder(stream, {
                mimeType: MediaRecorder.isTypeSupported('audio/webm')
                    ? 'audio/webm'
                    : 'audio/mp4',
            });
            chunksRef.current = [];
            recorder.ondataavailable = (e) => {
                if (e.data.size > 0) chunksRef.current.push(e.data);
            };
            recorder.start();
            mediaRecorderRef.current = recorder;

            setState('listening');
        } catch (err) {
            setErrorMessage('Microphone access was denied or unavailable.');
            setState('error');
        }
    }, [watchLevel]);

    const cleanupStream = useCallback(() => {
        streamRef.current?.getTracks().forEach((t) => t.stop());
        streamRef.current = null;
        audioCtxRef.current?.close().catch(() => {});
        audioCtxRef.current = null;
        stopLevelLoop();
    }, [stopLevelLoop]);

    const playReply = useCallback(
        (audioBase64: string) =>
            new Promise<void>((resolve) => {
                const audio = new Audio(`data:audio/mp3;base64,${audioBase64}`);
                const audioCtx = new AudioContext();
                const source = audioCtx.createMediaElementSource(audio);
                const analyser = audioCtx.createAnalyser();
                analyser.fftSize = 512;
                source.connect(analyser);
                analyser.connect(audioCtx.destination);
                watchLevel(analyser);

                audio.onended = () => {
                    stopLevelLoop();
                    audioCtx.close().catch(() => {});
                    resolve();
                };
                audio.play().catch(() => resolve());
            }),
        [watchLevel, stopLevelLoop],
    );

    const stopListeningAndSend = useCallback(async () => {
        const recorder = mediaRecorderRef.current;
        if (!recorder) return;

        setState('thinking');
        stopLevelLoop();

        const audioBlob: Blob = await new Promise((resolve) => {
            recorder.onstop = () => {
                resolve(new Blob(chunksRef.current, { type: recorder.mimeType }));
            };
            recorder.stop();
        });

        cleanupStream();

        try {
            const history = messages.slice(-MAX_HISTORY_TURNS * 2);

            const formData = new FormData();
            formData.append('audio', audioBlob, 'speech.webm');
            formData.append('history', JSON.stringify(history));

            const csrf = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const res = await fetch('/api/voice/converse', {
                method: 'POST',
                headers: csrf ? { 'X-CSRF-TOKEN': csrf } : undefined,
                body: formData,
            });

            const data = await res.json();

            if (!res.ok) {
                setErrorMessage(data.error ?? 'Something went wrong.');
                setState('error');
                return;
            }

            setMessages((prev) => [
                ...prev,
                { role: 'user', content: data.transcript },
                { role: 'assistant', content: data.reply },
            ]);

            setState('speaking');
            await playReply(data.audio_base64);
            setState('idle');
        } catch (err) {
            setErrorMessage('Could not reach the voice agent. Is the server running?');
            setState('error');
        }
    }, [cleanupStream, messages, playReply, stopLevelLoop]);

    const sendTextMessage = useCallback(
        async (text: string) => {
            const trimmed = text.trim();
            if (!trimmed) return;

            setState('thinking');
            stopLevelLoop();

            try {
                const history = messages.slice(-MAX_HISTORY_TURNS * 2);

                const formData = new FormData();
                formData.append('text', trimmed);
                formData.append('history', JSON.stringify(history));

                const csrf = document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content');

                const res = await fetch('/api/voice/converse', {
                    method: 'POST',
                    headers: csrf ? { 'X-CSRF-TOKEN': csrf } : undefined,
                    body: formData,
                });

                const data = await res.json();

                if (!res.ok) {
                    setErrorMessage(data.error ?? 'Something went wrong.');
                    setState('error');
                    return;
                }

                setMessages((prev) => [
                    ...prev,
                    { role: 'user', content: data.transcript },
                    { role: 'assistant', content: data.reply },
                ]);

                setState('speaking');
                await playReply(data.audio_base64);
                setState('idle');
            } catch (err) {
                setErrorMessage('Could not reach the voice agent. Is the server running?');
                setState('error');
            }
        },
        [messages, playReply, stopLevelLoop],
    );

    const cancel = useCallback(() => {
        mediaRecorderRef.current?.stop();
        cleanupStream();
        setState('idle');
    }, [cleanupStream]);

    return {
        state,
        level,
        messages,
        errorMessage,
        startListening,
        stopListeningAndSend,
        sendTextMessage,
        cancel,
    };
}
