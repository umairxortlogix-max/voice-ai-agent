import { useEffect, useRef, useState } from 'react';

type WakeWordOptions = {
    enabled: boolean;
    isBusy: boolean;
    onWake: () => void;
    keywords?: string[];
};

export function useWakeWord({
    enabled,
    isBusy,
    onWake,
    keywords = ['hey assistant', 'assistant', 'hey jarvis', 'jarvis', 'suno', 'aiva', 'hey aiva'],
}: WakeWordOptions) {
    const [isListening, setIsListening] = useState(false);
    const [isSupported, setIsSupported] = useState(true);
    const recognitionRef = useRef<any>(null);
    const isBusyRef = useRef(isBusy);
    const onWakeRef = useRef(onWake);

    isBusyRef.current = isBusy;
    onWakeRef.current = onWake;

    useEffect(() => {
        const SpeechRec =
            typeof window !== 'undefined'
                ? (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition
                : null;

        if (!SpeechRec) {
            setIsSupported(false);
            return;
        }

        if (!enabled) {
            if (recognitionRef.current) {
                try {
                    recognitionRef.current.abort();
                } catch (_) {}
                recognitionRef.current = null;
            }
            setIsListening(false);
            return;
        }

        let recognition: any = null;
        let active = true;

        try {
            recognition = new SpeechRec();
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.lang = 'en-US';

            recognition.onstart = () => {
                if (active) setIsListening(true);
            };

            recognition.onend = () => {
                if (active && enabled && !isBusyRef.current) {
                    try {
                        recognition.start();
                    } catch (_) {}
                } else if (active) {
                    setIsListening(false);
                }
            };

            recognition.onerror = (e: any) => {
                if (e.error === 'not-allowed') {
                    if (active) setIsListening(false);
                }
            };

            recognition.onresult = (event: any) => {
                if (isBusyRef.current) return;

                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const transcript = event.results[i][0].transcript.toLowerCase().trim();
                    const matched = keywords.some((kw) => transcript.includes(kw));

                    if (matched) {
                        try {
                            recognition.abort();
                        } catch (_) {}
                        onWakeRef.current();
                        break;
                    }
                }
            };

            if (!isBusyRef.current) {
                recognition.start();
                recognitionRef.current = recognition;
            }
        } catch (_) {}

        return () => {
            active = false;
            if (recognition) {
                try {
                    recognition.abort();
                } catch (_) {}
            }
            recognitionRef.current = null;
        };
    }, [enabled]);

    useEffect(() => {
        const recognition = recognitionRef.current;
        if (!recognition || !enabled) return;

        if (isBusy) {
            try {
                recognition.abort();
            } catch (_) {}
            setIsListening(false);
        } else {
            try {
                recognition.start();
                setIsListening(true);
            } catch (_) {}
        }
    }, [isBusy, enabled]);

    return { isListening, isSupported };
}
