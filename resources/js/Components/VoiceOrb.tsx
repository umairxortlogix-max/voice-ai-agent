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
    const coreScale = active ? 1 + level * 0.4 : 1;

    return (
        <div className="relative flex h-64 w-64 items-center justify-center sm:h-80 sm:w-80">
            {/* outer rings, driven by live amplitude */}
            {[0, 1, 2].map((i) => (
                <motion.span
                    key={i}
                    className="absolute rounded-full border border-violet/30"
                    style={{ inset: 0 }}
                    animate={
                        active
                            ? {
                                  scale: 1 + level * (0.5 + i * 0.25),
                                  opacity: 0.35 - i * 0.1,
                              }
                            : state === 'thinking'
                              ? { scale: [1, 1.08, 1], opacity: [0.25, 0.1, 0.25] }
                              : { scale: 1, opacity: 0.12 }
                    }
                    transition={
                        state === 'thinking'
                            ? { duration: 1.4, repeat: Infinity, delay: i * 0.15, ease: 'easeInOut' }
                            : { type: 'spring', stiffness: 120, damping: 14 }
                    }
                />
            ))}

            {/* idle breathing halo */}
            <motion.div
                className="absolute h-full w-full rounded-full bg-gradient-to-br from-violet/20 to-teal/10 blur-2xl"
                animate={
                    state === 'idle'
                        ? { scale: [1, 1.06, 1], opacity: [0.5, 0.8, 0.5] }
                        : { scale: 1 + level * 0.5, opacity: 0.7 }
                }
                transition={
                    state === 'idle'
                        ? { duration: 4, repeat: Infinity, ease: 'easeInOut' }
                        : { type: 'spring', stiffness: 100, damping: 12 }
                }
            />

            {/* core orb */}
            <motion.div
                role="button"
                aria-label={STATE_LABEL[state]}
                className="relative flex h-40 w-40 items-center justify-center rounded-full shadow-glow sm:h-48 sm:w-48"
                style={{
                    background:
                        'radial-gradient(circle at 35% 30%, #9C90FF 0%, #7C6FFF 45%, #4A3FCB 100%)',
                }}
                animate={{ scale: coreScale }}
                transition={{ type: 'spring', stiffness: 200, damping: 15 }}
            >
                <motion.div
                    className="h-full w-full rounded-full"
                    style={{
                        background:
                            'conic-gradient(from 180deg, rgba(51,225,201,0.35), rgba(124,111,255,0.05), rgba(51,225,201,0.35))',
                    }}
                    animate={{ rotate: active || state === 'thinking' ? 360 : 0 }}
                    transition={{ duration: 6, repeat: Infinity, ease: 'linear' }}
                />
            </motion.div>
        </div>
    );
}
