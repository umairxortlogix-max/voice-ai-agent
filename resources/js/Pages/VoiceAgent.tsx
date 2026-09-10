import { Head } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Activity,
    AlertCircle,
    BarChart3,
    CircleUserRound,
    Home,
    MessageSquareText,
    Mic,
    Settings,
    ShieldCheck,
    Sparkles,
    Square,
    Zap,
} from 'lucide-react';
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

    const navItems = [
        { label: 'Home', icon: Home, active: true },
        { label: 'Chat', icon: MessageSquareText, active: false },
        { label: 'Analytics', icon: BarChart3, active: false },
        { label: 'Settings', icon: Settings, active: false },
    ];

    const contextTiles = [
        { label: '16 MODES', icon: Sparkles },
        { label: 'ACTIVE', icon: Activity },
        { label: 'REAL-TIME', icon: Zap },
        { label: 'SECURE', icon: ShieldCheck },
    ];

    return (
        <>
            <Head title="Voice Agent" />

            <div className="command-scene flex min-h-screen flex-col items-center px-4 py-5 text-fg sm:px-6 lg:px-8">
                <div className="scene-backdrop" />
                <div className="tech-ambient tech-ambient-one" />
                <div className="tech-ambient tech-ambient-two" />
                <div className="scene-grid" />
                <div className="city-silhouette city-silhouette-left" />
                <div className="city-silhouette city-silhouette-right" />
                <div className="rack rack-left">
                    <span />
                    <span />
                    <span />
                    <span />
                    <span />
                    <span />
                </div>
                <div className="rack rack-right">
                    <span />
                    <span />
                    <span />
                    <span />
                    <span />
                    <span />
                </div>
                <div className="ceiling-lights" />
                <div className="operator operator-1" />
                <div className="operator operator-2" />
                <div className="operator operator-3" />

                <header className="relative z-30 flex w-full max-w-7xl items-center justify-between pb-5">
                    <div className="flex items-center gap-4">
                        <div className="aiva-mark">A</div>
                        <div className="flex items-center gap-8 text-sm text-cyan-100/75">
                            {navItems.map(({ label, icon: Icon, active }) => (
                                <button
                                    key={label}
                                    type="button"
                                    className={`nav-item ${active ? 'active' : ''}`}
                                >
                                    <Icon size={14} />
                                    <span>{label}</span>
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-2 rounded-full border border-cyan-400/15 bg-slate-900/30 px-3 py-1.5 backdrop-blur-sm">
                            <span
                                className={`status-dot ${
                                    state === 'error'
                                        ? 'status-error'
                                        : state === 'thinking' || state === 'speaking' || state === 'listening'
                                          ? 'status-processing'
                                          : 'status-ready'
                                }`}
                            />
                            <span className="text-[9px] uppercase tracking-[0.24em] text-cyan-200/80">
                                {state === 'idle' && 'Online'}
                                {state === 'listening' && 'Listening'}
                                {state === 'thinking' && 'Processing'}
                                {state === 'speaking' && 'Speaking'}
                                {state === 'error' && 'Offline'}
                            </span>
                        </div>
                        <button type="button" className="profile-pill" aria-label="User profile">
                            <CircleUserRound size={16} />
                        </button>
                    </div>
                </header>

                <div className="relative z-20 flex w-full max-w-7xl flex-1 flex-col gap-7 lg:flex-row lg:items-stretch lg:gap-8">
                    <aside className="relative hidden w-[250px] lg:block">
                        <div className="floating-panel metrics-card metrics-top">
                            <div className="panel-header">
                                <span>System Status</span>
                                <span className="signal-dot" />
                            </div>
                            <div className="metrics-row">
                                <span className="metric-label">Performance</span>
                                <span className="metric-value">87%</span>
                            </div>
                            <div className="metrics-row lower">
                                <span className="metric-label">Latency</span>
                                <span className="metric-value">145ms</span>
                            </div>
                            <div className="panel-chart chart-line" />
                        </div>

                        <div className="floating-panel metrics-card metrics-bottom">
                            <div className="panel-header">
                                <span>Throughput</span>
                                <span className="signal-dot secondary" />
                            </div>
                            <div className="metrics-row">
                                <span className="metric-label">Load</span>
                                <span className="metric-value">2.4K req/min</span>
                            </div>
                            <div className="metrics-row lower">
                                <span className="metric-label">Growth</span>
                                <span className="metric-value positive">+12%</span>
                            </div>
                            <div className="panel-chart chart-bars" />
                        </div>
                    </aside>

                    <main className="command-panel flex flex-1 flex-col items-center justify-center gap-6 rounded-[2rem] border border-cyan-400/20 bg-slate-950/25 p-6 shadow-[0_0_35px_rgba(34,211,238,0.12)] backdrop-blur-xl">
                        <div className="holo-platform" />
                        <div className="ai-core-label text-center">
                            <h1>Hello, I&apos;m AIVA</h1>
                            <p>Your AI assistant is ready. Ask me anything.</p>
                        </div>
                        <VoiceOrb state={state} level={level} />

                        <div className="flex flex-col items-center gap-3">
                            <div className={`mic-button-wrap ${state === 'listening' ? 'active' : ''}`}>
                                <motion.button
                                    onClick={handleMicClick}
                                    disabled={busy}
                                    whileTap={{ scale: 0.94 }}
                                    className={`relative flex h-20 w-20 items-center justify-center rounded-full border transition-all duration-200 ${
                                        state === 'listening'
                                            ? 'border-cyan-200/50 bg-cyan-500/20 text-cyan-100 shadow-[0_0_28px_rgba(45,212,191,0.5)]'
                                            : 'border-cyan-400/40 bg-slate-900/60 text-cyan-100 hover:border-cyan-300/70 hover:bg-slate-900/80'
                                    } ${busy ? 'cursor-not-allowed opacity-40' : ''}`}
                                >
                                    {state === 'listening' ? <Square size={24} /> : <Mic size={24} />}
                                </motion.button>
                            </div>
                            <p className="text-sm font-medium tracking-[0.18em] text-cyan-100/75 uppercase">
                                {state === 'listening' ? 'Tap to stop and send' : 'Tap to talk'}
                            </p>
                        </div>

                        {errorMessage && (
                            <div className="flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-300 shadow-[0_0_20px_rgba(239,68,68,0.25)]">
                                <AlertCircle size={16} />
                                {errorMessage}
                            </div>
                        )}
                    </main>

                    <aside className="w-full lg:max-w-[360px]">
                        <div className="flex h-full min-h-[320px] flex-col gap-4">
                            <div className="flex h-full min-h-[320px] flex-col rounded-[2rem] border border-cyan-400/20 bg-slate-950/25 p-4 shadow-[0_0_30px_rgba(34,211,238,0.08)] backdrop-blur-xl">
                                <div className="mb-3 flex items-center justify-between border-b border-cyan-400/15 pb-3">
                                    <div className="flex items-center gap-2">
                                        <div className="rounded-md border border-cyan-400/20 bg-cyan-400/5 p-1.5 text-cyan-100/80">
                                            <MessageSquareText size={12} />
                                        </div>
                                        <h2 className="text-[10px] font-medium uppercase tracking-[0.2em] text-cyan-100/70">
                                            Chat
                                        </h2>
                                    </div>
                                    <span className="text-[10px] uppercase tracking-[0.16em] text-cyan-100/60">
                                        {messages.length} turns
                                    </span>
                                </div>
                                <div className="min-h-0 flex-1 overflow-y-auto pr-2">
                                    {messages.length === 0 ? (
                                        <div className="flex h-full min-h-[220px] flex-col items-center justify-center gap-3 p-4 text-center">
                                            <div className="flex h-12 w-12 items-center justify-center rounded-full border border-cyan-400/20 bg-cyan-400/5 text-cyan-100/80">
                                                <MessageSquareText size={18} />
                                            </div>
                                            <p className="max-w-xs text-sm text-cyan-100/60">
                                                Your conversation will appear here once you start talking.
                                            </p>
                                        </div>
                                    ) : (
                                        <TranscriptPanel messages={messages} />
                                    )}
                                </div>
                            </div>

                            <div className="floating-panel context-panel">
                                <div className="panel-header">
                                    <span>Context</span>
                                    <span className="signal-dot tertiary" />
                                </div>
                                <div className="context-grid">
                                    {contextTiles.map(({ label, icon: Icon }) => (
                                        <div key={label} className="context-tile">
                                            <div className="tile-icon">
                                                <Icon size={12} />
                                            </div>
                                            <span>{label}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}
