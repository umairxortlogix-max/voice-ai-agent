import { AnimatePresence, motion } from 'framer-motion';
import type { ChatMessage } from '../hooks/useVoiceAgent';

type Props = {
    messages: ChatMessage[];
};

export default function TranscriptPanel({ messages }: Props) {
    if (messages.length === 0) {
        return (
            <p className="mx-auto max-w-xs text-center text-sm text-muted">
                Your conversation will appear here once you start talking.
            </p>
        );
    }

    return (
        <div className="flex w-full flex-col gap-3">
            <AnimatePresence initial={false}>
                {messages.map((m, i) => (
                    <motion.div
                        key={i}
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.25 }}
                        className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}
                    >
                        <div
                            className={`max-w-[80%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed ${
                                m.role === 'user'
                                    ? 'bg-violet/20 text-fg'
                                    : 'border border-edge bg-surface text-fg'
                            }`}
                        >
                            {m.content}
                        </div>
                    </motion.div>
                ))}
            </AnimatePresence>
        </div>
    );
}
