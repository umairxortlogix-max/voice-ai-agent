import { Head } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Mic, Square, AlertCircle } from 'lucide-react';
import VoiceOrb from '../Components/VoiceOrb';
import TranscriptPanel from '../Components/TranscriptPanel';
import { useVoiceAgent } from '../hooks/useVoiceAgent';

export default function VoiceAgent() {
    const { state, level, messages, errorMessage, startListening, stopListeningAndSend, cancel } =
        useVoiceAgent();

    const handleMicClick = () => {
        if (state === 'idle' || state === 'error') startListening();
        else if (state === 'listening') stopListeningAndSend();
        else cancel();
    };

    const busy = state === 'thinking' || state === 'speaking';

    return (
        <>
            <Head title="Voice Agent" />

            <div className="flex min-h-screen flex-col items-center px-6 py-10 text-fg">
                <header className="flex w-full max-w-3xl items-center justify-between">
                    <span className="font-display text-lg font-semibold tracking-tight">
                        Ava
                    </span>
                    <span className="text-xs text-muted">
                        {state === 'idle' && 'Ready'}
                        {state === 'listening' && 'Recording your voice'}
                        {state === 'thinking' && 'Working on a reply'}
                        {state === 'speaking' && 'Playing reply'}
                        {state === 'error' && 'Needs attention'}
                    </span>
                </header>

                <main className="flex w-full max-w-3xl flex-1 flex-col items-center justify-center gap-10">
                    <VoiceOrb state={state} level={level} />

                    <div className="flex flex-col items-center gap-3">
                        <motion.button
                            onClick={handleMicClick}
                            disabled={busy}
                            whileTap={{ scale: 0.94 }}
                            className={`flex h-16 w-16 items-center justify-center rounded-full border transition-colors ${
                                state === 'listening'
                                    ? 'border-teal bg-teal/20 text-teal'
                                    : 'border-edge bg-surface text-fg hover:border-violet/60'
                            } ${busy ? 'cursor-not-allowed opacity-40' : ''}`}
                        >
                            {state === 'listening' ? <Square size={22} /> : <Mic size={22} />}
                        </motion.button>
                        <p className="text-sm text-muted">
                            {state === 'listening' ? 'Tap to stop and send' : 'Tap to talk'}
                        </p>
                    </div>

                    {errorMessage && (
                        <div className="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">
                            <AlertCircle size={16} />
                            {errorMessage}
                        </div>
                    )}
                </main>

                <footer className="w-full max-w-md">
                    <TranscriptPanel messages={messages} />
                </footer>
            </div>
        </>
    );
}
