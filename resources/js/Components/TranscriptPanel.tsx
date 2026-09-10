import { AnimatePresence, motion } from 'framer-motion';
import type { ChatMessage } from '../hooks/useVoiceAgent';

type Props = {
    messages: ChatMessage[];
};

export default function TranscriptPanel({ messages }: Props) {
    if (messages.length === 0) {
        return (
            <div className="flex h-full min-h-[220px] items-center justify-center p-4">
                <p className="max-w-xs text-center text-sm text-cyan-100/60">
                    Your conversation will appear here once you start talking.
                </p>
            </div>
        );
    }

    return (
        <div className="flex w-full flex-col gap-3 pb-2">
            <AnimatePresence initial={false}>
                {messages.map((m, i) => (
                    <motion.div
                        key={`${m.role}-${i}-${m.content.slice(0, 12)}`}
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.25 }}
                        className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}
                    >
                        <div
                            className={`max-w-[85%] rounded-2xl border px-3.5 py-2.5 text-sm leading-relaxed break-words shadow-[0_0_24px_rgba(15,23,42,0.18)] ${
                                m.role === 'user'
                                    ? 'border-cyan-400/30 bg-cyan-500/10 text-cyan-50 shadow-[0_0_18px_rgba(34,211,238,0.18)]'
                                    : 'border-slate-700/60 bg-slate-800/35 text-slate-200'
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
