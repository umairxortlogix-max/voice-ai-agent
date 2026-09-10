import { motion } from 'framer-motion';
import type { AgentState } from '../hooks/useVoiceAgent';

type Props = {
    state: AgentState;
    level: number;
};

const STATE_LABEL: Record<AgentState, string> = {
    idle: 'Tap to speak',
    listening: 'Listening…',
    thinking: 'Thinking…',
    speaking: 'Speaking…',
    error: 'Something went wrong',
};

export default function VoiceOrb({ state, level }: Props) {
    const active = state === 'listening' || state === 'speaking';
    const coreScale = active ? 1 + level * 0.38 : 1;
    const isThinking = state === 'thinking';
    const isListening = state === 'listening';
    const isSpeaking = state === 'speaking';
    const waveformBars = [0.5, 1, 1.4, 0.8, 1.6, 1.2, 0.7];

    return (
        <div className="relative flex h-64 w-64 items-center justify-center sm:h-80 sm:w-80">
            {[0, 1, 2, 3].map((i) => (
                <motion.span
                    key={i}
                    className="absolute rounded-full border border-cyan-300/25"
                    style={{ inset: 0 }}
                    animate={
                        active
                            ? {
                                  scale: 1 + level * (0.4 + i * 0.18),
                                  opacity: 0.32 - i * 0.08,
                              }
                            : isThinking
                              ? { scale: [1, 1.08, 1], opacity: [0.26, 0.1, 0.26] }
                              : { scale: 1, opacity: 0.12 }
                    }
                    transition={
                        isThinking
                            ? { duration: 1.6, repeat: Infinity, delay: i * 0.15, ease: 'easeInOut' }
                            : { type: 'spring', stiffness: 120, damping: 14 }
                    }
                />
            ))}

            <motion.div
                className="absolute bottom-4 h-12 w-52 rounded-full bg-cyan-400/15 blur-2xl"
                animate={
                    state === 'idle'
                        ? { opacity: [0.16, 0.25, 0.16], scaleX: [0.9, 1, 0.9] }
                        : { opacity: active ? 0.38 : 0.22, scaleX: [0.95, 1.08, 0.95] }
                }
                transition={{ duration: 2.6, repeat: Infinity, ease: 'easeInOut' }}
            />

            <div className="absolute flex items-center justify-center gap-2">
                {waveformBars.map((barHeight, index) => (
                    <motion.span
                        key={index}
                        className="block w-1.5 rounded-full bg-cyan-200/80 shadow-[0_0_12px_rgba(34,211,238,0.7)]"
                        animate={
                            isListening || isSpeaking
                                ? {
                                      height: [barHeight * 12, 18 + barHeight * 16, barHeight * 12],
                                      opacity: [0.5, 1, 0.8],
                                  }
                                : { height: 10, opacity: 0.34 }
                        }
                        transition={{
                            duration: isSpeaking ? 0.45 : 0.7,
                            repeat: Infinity,
                            ease: 'easeInOut',
                            delay: index * 0.06,
                        }}
                        style={{ height: 10 }}
                    />
                ))}
            </div>

            <motion.div
                role="button"
                aria-label={STATE_LABEL[state]}
                className="relative flex h-44 w-44 items-center justify-center rounded-full border border-cyan-200/20 bg-slate-950/10 shadow-[0_0_45px_rgba(124,111,255,0.35)] sm:h-52 sm:w-52"
                style={{
                    background:
                        'radial-gradient(circle at 35% 30%, rgba(201,216,255,1) 0%, rgba(134,126,255,0.96) 28%, rgba(94,92,255,0.9) 50%, rgba(11,16,34,0.95) 100%)',
                }}
                animate={{
                    scale: coreScale,
                    boxShadow: active
                        ? '0 0 60px rgba(34, 211, 238, 0.45)'
                        : '0 0 48px rgba(124, 111, 255, 0.38)',
                }}
                transition={{ type: 'spring', stiffness: 170, damping: 16 }}
            >
                <motion.div
                    className="absolute inset-[9%] rounded-full"
                    style={{
                        background:
                            'conic-gradient(from 180deg, rgba(34,211,238,0.58), rgba(125,211,252,0.1), rgba(34,211,238,0.58))',
                    }}
                    animate={{ rotate: active || isThinking ? 360 : 0 }}
                    transition={{ duration: 7, repeat: Infinity, ease: 'linear' }}
                />

                <div className="relative z-10 flex h-[72%] w-[72%] items-center justify-center rounded-full border border-white/10 bg-slate-900/10 backdrop-blur-[2px]">
                    <div className="relative flex h-full w-full items-center justify-center">
                        <motion.div
                            className="absolute left-[26%] top-[38%] h-3.5 w-3.5 rounded-full bg-cyan-100 shadow-[0_0_14px_rgba(255,255,255,0.9)]"
                            animate={{
                                scaleY: active || isThinking ? [1, 1.45, 1] : 1,
                                opacity: state === 'error' ? 0.5 : 1,
                            }}
                            transition={{ duration: 0.5, repeat: Infinity, ease: 'easeInOut' }}
                        />
                        <motion.div
                            className="absolute right-[26%] top-[38%] h-3.5 w-3.5 rounded-full bg-cyan-100 shadow-[0_0_14px_rgba(255,255,255,0.9)]"
                            animate={{
                                scaleY: active || isThinking ? [1, 1.45, 1] : 1,
                                opacity: state === 'error' ? 0.5 : 1,
                            }}
                            transition={{ duration: 0.5, repeat: Infinity, ease: 'easeInOut', delay: 0.12 }}
                        />

                        <motion.div
                            className="absolute bottom-[28%] h-[3px] w-11 rounded-full bg-cyan-100/90"
                            animate={{
                                scaleX: active ? [0.8, 1.15, 0.8] : isThinking ? [0.85, 1.1, 0.85] : 0.82,
                                opacity: state === 'error' ? 0.5 : 1,
                            }}
                            transition={{ duration: 0.8, repeat: Infinity, ease: 'easeInOut' }}
                        />
                    </div>
                </div>
            </motion.div>
        </div>
    );
}
