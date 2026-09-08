@extends('layouts.app')

@section('title', 'SmartHome AI')

@section('content')

{{-- ════════════════════════════════════════════════════════════════════
     ROOT: Alpine.js Component Wrapper
     x-data → inisialisasi komponen smarthomeChat dari resources/js/app.js
     ════════════════════════════════════════════════════════════════════ --}}
<div
    x-data="smarthomeChat"
    x-init="init()"
    class="h-screen flex overflow-hidden select-none"
    style="background: #0A0B1A;"
>

    {{-- ── Animated Background Orbs ─────────────────────────────────── --}}
    <div class="fixed inset-0 pointer-events-none overflow-hidden" aria-hidden="true">
        <div class="orb absolute w-96 h-96 rounded-full opacity-30 -top-20 -left-20"
             style="background: radial-gradient(circle, rgba(108,99,255,0.5), transparent 70%); filter: blur(70px);"></div>
        <div class="orb absolute w-80 h-80 rounded-full opacity-20 top-1/2 -right-10"
             style="background: radial-gradient(circle, rgba(0,212,170,0.4), transparent 70%); filter: blur(60px);"></div>
        <div class="orb absolute w-72 h-72 rounded-full opacity-15 bottom-0 left-1/3"
             style="background: radial-gradient(circle, rgba(108,99,255,0.3), transparent 70%); filter: blur(80px);"></div>
        {{-- Grid overlay --}}
        <div class="absolute inset-0"
             style="background-image: linear-gradient(rgba(108,99,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(108,99,255,0.03) 1px, transparent 1px); background-size: 48px 48px; mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black, transparent);"></div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         LEFT SIDEBAR — Stats & Device Status
         ════════════════════════════════════════════════════════════════ --}}
    <aside
        class="relative z-20 flex flex-col w-72 shrink-0 h-full border-r transition-transform duration-300"
        style="background: rgba(14,15,35,0.85); backdrop-filter: blur(20px); border-color: rgba(108,99,255,0.12);"
        :class="{ '-translate-x-full lg:translate-x-0': !sidebarOpen, 'translate-x-0': sidebarOpen }"
    >
        {{-- ── Sidebar Header ──────────────────────────────────────── --}}
        <div class="flex items-center gap-3 px-5 py-5 border-b" style="border-color: rgba(108,99,255,0.12);">
            {{-- Logo Icon --}}
            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                 style="background: rgba(108,99,255,0.15); border: 1px solid rgba(108,99,255,0.3);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="url(#grad-logo)" stroke-width="1.75">
                    <defs>
                        <linearGradient id="grad-logo" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#6C63FF"/>
                            <stop offset="100%" stop-color="#00D4AA"/>
                        </linearGradient>
                    </defs>
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9,22 9,12 15,12 15,22"/>
                </svg>
            </div>
            <div>
                <div class="font-display font-bold text-sm" style="color: #F0F0FF;">SmartHome <span class="gradient-text">AI</span></div>
                <div class="text-xs" style="color: #6060A0;">Control Center</div>
            </div>

            {{-- ESP32 Status Dot --}}
            <div class="ml-auto flex items-center gap-1.5" title="Status ESP32">
                <div class="w-2 h-2 rounded-full transition-colors"
                     :class="esp32Online === true ? 'bg-emerald-400 animate-pulse-border' : (esp32Online === false ? 'bg-red-400' : 'bg-yellow-400 animate-pulse')"
                ></div>
                <span class="text-xs" style="color: #6060A0;"
                      x-text="esp32Online === true ? 'Online' : (esp32Online === false ? 'Offline' : '...')"></span>
            </div>
        </div>

        {{-- ── Stats Cards ─────────────────────────────────────────── --}}
        <div class="px-4 py-4 grid grid-cols-2 gap-2.5">
            {{-- Total --}}
            <div class="rounded-xl p-3 text-center" style="background: rgba(108,99,255,0.07); border: 1px solid rgba(108,99,255,0.12);">
                <div class="text-xl font-bold font-display gradient-text" x-text="stats.total ?? '—'"></div>
                <div class="text-xs mt-0.5" style="color: #6060A0;">Total Perintah</div>
            </div>
            {{-- Success --}}
            <div class="rounded-xl p-3 text-center" style="background: rgba(0,212,170,0.07); border: 1px solid rgba(0,212,170,0.12);">
                <div class="text-xl font-bold font-display" style="color: #00D4AA;" x-text="stats.success ?? '—'"></div>
                <div class="text-xs mt-0.5" style="color: #6060A0;">Berhasil</div>
            </div>
            {{-- Today --}}
            <div class="rounded-xl p-3 text-center" style="background: rgba(108,99,255,0.07); border: 1px solid rgba(108,99,255,0.12);">
                <div class="text-xl font-bold font-display gradient-text" x-text="stats.today ?? '—'"></div>
                <div class="text-xs mt-0.5" style="color: #6060A0;">Hari Ini</div>
            </div>
            {{-- Avg Latency --}}
            <div class="rounded-xl p-3 text-center" style="background: rgba(255,179,71,0.07); border: 1px solid rgba(255,179,71,0.12);">
                <div class="text-xl font-bold font-display" style="color: #FFB347;"
                     x-text="stats.avg_latency_ms ? stats.avg_latency_ms + 'ms' : '—'"></div>
                <div class="text-xs mt-0.5" style="color: #6060A0;">Avg Latensi</div>
            </div>
        </div>

        {{-- ── Device Quick Status ──────────────────────────────────── --}}
        <div class="px-4 pb-3">
            <div class="text-xs font-semibold uppercase tracking-widest mb-2.5" style="color: #4040A0;">Perangkat</div>
            <div class="space-y-1.5">
                @foreach([
                    ['relay_1',            '💡', 'Lampu Ruang Tamu'],
                    ['ac_ruang_tamu',      '❄️', 'AC Ruang Tamu'],
                    ['kunci_pintu_depan',  '🔐', 'Kunci Pintu'],
                    ['kipas_dapur',        '🌀', 'Kipas Dapur'],
                ] as [$id, $icon, $label])
                <div class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all duration-200"
                     :class="currentDevice?.name === '{{ $id }}'
                        ? 'border'
                        : 'border border-transparent hover:border-indigo-500/20 hover:bg-indigo-500/5'"
                     :style="currentDevice?.name === '{{ $id }}'
                        ? 'background: rgba(108,99,255,0.12); border-color: rgba(108,99,255,0.3);'
                        : ''">
                    <span class="text-base">{{ $icon }}</span>
                    <span class="text-xs font-medium flex-1" style="color: #A0A0C0;">{{ $label }}</span>
                    {{-- Animasi active state --}}
                    <div x-show="currentDevice?.name === '{{ $id }}'" x-transition
                         class="flex items-center gap-0.5 h-4">
                        <div class="wave-bar w-0.5 rounded-full" style="background: #6C63FF; height: 8px;"></div>
                        <div class="wave-bar w-0.5 rounded-full" style="background: #6C63FF; height: 14px;"></div>
                        <div class="wave-bar w-0.5 rounded-full" style="background: #6C63FF; height: 10px;"></div>
                        <div class="wave-bar w-0.5 rounded-full" style="background: #6C63FF; height: 14px;"></div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        {{-- ── Recent History ───────────────────────────────────────── --}}
        <div class="px-4 flex-1 overflow-hidden flex flex-col mt-2">
            <div class="flex items-center justify-between mb-2.5">
                <div class="text-xs font-semibold uppercase tracking-widest" style="color: #4040A0;">Riwayat</div>
                <button @click="loadSidebarHistory()" class="text-xs transition-colors" style="color: #4040A0;"
                        onmouseover="this.style.color='#6C63FF'" onmouseout="this.style.color='#4040A0'">
                    Refresh
                </button>
            </div>
            <div class="flex-1 overflow-y-auto space-y-1.5 pr-1">
                <template x-if="sidebarHistory.length === 0">
                    <div class="text-center py-6">
                        <div class="text-2xl mb-1">📭</div>
                        <div class="text-xs" style="color: #4040A0;">Belum ada riwayat</div>
                    </div>
                </template>
                <template x-for="item in sidebarHistory" :key="item.id">
                    <div class="px-2.5 py-2 rounded-lg cursor-default transition-colors hover:bg-white/[0.03]"
                         style="border: 1px solid rgba(255,255,255,0.04);">
                        <div class="flex items-start gap-1.5">
                            <div class="text-xs flex-1 leading-relaxed truncate" style="color: #8080A0;"
                                 x-text="item.perintah"></div>
                            <span class="text-xs px-1.5 py-0.5 rounded-full shrink-0"
                                  :class="{
                                      'bg-emerald-500/15 text-emerald-400': item.status === 'success',
                                      'bg-red-500/15 text-red-400': item.status === 'failed',
                                      'bg-yellow-500/15 text-yellow-400': item.status === 'pending',
                                  }"
                                  x-text="item.status === 'success' ? '✓' : (item.status === 'failed' ? '✗' : '…')">
                            </span>
                        </div>
                        <div class="flex items-center gap-1.5 mt-1">
                            <span class="text-xs" style="color: #4040A0;" x-text="item.waktu"></span>
                            <span x-show="item.latency" class="text-xs" style="color: #3030A0;"
                                  x-text="'· ' + item.latency"></span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- ── Sidebar Footer ───────────────────────────────────────── --}}
        <div class="px-4 py-4 border-t" style="border-color: rgba(108,99,255,0.08);">
            <button @click="checkEsp32Status()" id="btn-check-esp32"
                    class="w-full text-xs py-2 rounded-lg font-medium transition-all"
                    style="background: rgba(108,99,255,0.08); border: 1px solid rgba(108,99,255,0.15); color: #8080C0;"
                    onmouseover="this.style.background='rgba(108,99,255,0.15)'"
                    onmouseout="this.style.background='rgba(108,99,255,0.08)'">
                🔄 Cek Koneksi ESP32
            </button>
        </div>
    </aside>

    {{-- ════════════════════════════════════════════════════════════════
         MAIN AREA — Chat Interface
         ════════════════════════════════════════════════════════════════ --}}
    <main class="relative z-10 flex flex-col flex-1 min-w-0 h-full">

        {{-- ── Top Bar ─────────────────────────────────────────────── --}}
        <header class="flex items-center gap-4 px-6 py-4 shrink-0 border-b"
                style="background: rgba(14,15,35,0.7); backdrop-filter: blur(20px); border-color: rgba(108,99,255,0.1);">
            {{-- Mobile sidebar toggle --}}
            <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden p-2 rounded-lg transition-colors"
                    style="color: #6060A0;" id="btn-toggle-sidebar">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>

            <div>
                <h1 class="font-display font-bold text-base" style="color: #F0F0FF;">
                    Asisten Smart Home <span class="gradient-text">AI</span>
                </h1>
                <p class="text-xs" style="color: #5050A0;">Ketik atau ucapkan perintah untuk mengontrol rumah Anda</p>
            </div>

            {{-- Speaking indicator --}}
            <div x-show="isSpeaking" x-transition class="ml-auto flex items-center gap-2 px-3 py-1.5 rounded-full"
                 style="background: rgba(0,212,170,0.1); border: 1px solid rgba(0,212,170,0.25);">
                <div class="flex items-end gap-0.5 h-4">
                    <div class="wave-bar w-0.5 rounded-full" style="background: #00D4AA; height: 6px;"></div>
                    <div class="wave-bar w-0.5 rounded-full" style="background: #00D4AA; height: 12px;"></div>
                    <div class="wave-bar w-0.5 rounded-full" style="background: #00D4AA; height: 8px;"></div>
                    <div class="wave-bar w-0.5 rounded-full" style="background: #00D4AA; height: 12px;"></div>
                </div>
                <span class="text-xs font-medium" style="color: #00D4AA;">AI berbicara...</span>
                <button @click="stopSpeaking()" class="ml-1 text-xs transition-opacity hover:opacity-70" style="color: #00D4AA;">✕</button>
            </div>

            {{-- Normal right spacer --}}
            <div x-show="!isSpeaking" class="ml-auto"></div>
        </header>

        {{-- ── Chat Messages Area ───────────────────────────────────── --}}
        <div x-ref="chatContainer"
             class="flex-1 overflow-y-auto px-4 py-6 space-y-5"
             style="scroll-behavior: smooth;">

            {{-- Empty state --}}
            <template x-if="messages.length === 0">
                <div class="flex flex-col items-center justify-center h-full gap-4 py-20">
                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center animate-pulse-border"
                         style="background: rgba(108,99,255,0.12); border: 1px solid rgba(108,99,255,0.3);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#6C63FF" stroke-width="1.75">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            <polyline points="9,22 9,12 15,12 15,22"/>
                        </svg>
                    </div>
                    <p class="text-sm" style="color: #4040A0;">Memuat percakapan...</p>
                </div>
            </template>

            {{-- Message Loop --}}
            <template x-for="msg in messages" :key="msg.id">
                <div class="flex w-full"
                     :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">

                    {{-- ── AI Avatar (left) ──────────────────────────── --}}
                    <template x-if="msg.role === 'ai'">
                        <div class="flex items-end gap-3 max-w-[85%] sm:max-w-[75%] animate-fade-in-left">
                            {{-- Avatar --}}
                            <div class="w-8 h-8 rounded-xl shrink-0 flex items-center justify-center mb-1"
                                 style="background: linear-gradient(135deg, #6C63FF, #00D4AA);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
                                    <circle cx="12" cy="12" r="3"/>
                                    <path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83" stroke-width="2"/>
                                </svg>
                            </div>

                            {{-- AI Bubble --}}
                            <div class="flex flex-col gap-1.5">
                                <div class="px-4 py-3 rounded-2xl rounded-bl-sm"
                                     :class="msg.type === 'error'
                                        ? ''
                                        : ''"
                                     :style="msg.type === 'error'
                                        ? 'background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2);'
                                        : 'background: rgba(20,21,48,0.9); border: 1px solid rgba(108,99,255,0.15);'">

                                    {{-- Text Content --}}
                                    <p class="text-sm leading-relaxed"
                                       :class="msg.type === 'error' ? 'text-red-300' : ''"
                                       :style="msg.type !== 'error' ? 'color: #D0D0F0;' : ''"
                                       x-html="msg.text">
                                    </p>

                                    {{-- Button Putar Suara Edge-TTS --}}
                                    <template x-if="msg.data && msg.data.audio_url">
                                        <button @click="playAudioUrl(msg.data.audio_url)"
                                                class="flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full transition hover:scale-105 active:scale-95 mt-2 font-medium cursor-pointer"
                                                style="background: rgba(0,212,170,0.15); color: #00D4AA; border: 1px solid rgba(0,212,170,0.3);"
                                                title="Putar ulang suara Edge-TTS (id-ID-GadisNeural)">
                                            🔊 Putar Suara Edge-TTS
                                        </button>
                                    </template>

                                    {{-- Metadata badges (device/action/status) --}}
                                    <div x-show="msg.data && msg.data.device"
                                         x-transition
                                         class="flex flex-wrap items-center gap-1.5 mt-2.5 pt-2.5"
                                         style="border-top: 1px solid rgba(108,99,255,0.1);">

                                        {{-- Intent badge --}}
                                        <template x-if="getBadgeIntent(msg.data)">
                                            <span class="text-xs px-2 py-0.5 rounded-full border font-medium"
                                                  :class="getBadgeIntent(msg.data)?.color"
                                                  x-text="getBadgeIntent(msg.data)?.label"></span>
                                        </template>

                                        {{-- Device --}}
                                        <span x-show="msg.data?.device"
                                              class="text-xs px-2 py-0.5 rounded-full font-mono"
                                              style="background: rgba(108,99,255,0.1); color: #9090D0; border: 1px solid rgba(108,99,255,0.2);"
                                              x-text="msg.data?.device"></span>

                                        {{-- Action --}}
                                        <span x-show="msg.data?.action"
                                              class="text-xs px-2 py-0.5 rounded-full font-semibold"
                                              :class="msg.data?.action === 'ON' || msg.data?.action === 'UNLOCK'
                                                  ? 'bg-emerald-500/15 text-emerald-400'
                                                  : (msg.data?.action === 'OFF' || msg.data?.action === 'LOCK'
                                                      ? 'bg-red-500/15 text-red-400'
                                                      : 'bg-blue-500/15 text-blue-400')"
                                              x-text="msg.data?.action"></span>

                                        {{-- Latency --}}
                                        <span x-show="msg.data?.esp_status?.latency_ms"
                                              class="text-xs ml-auto"
                                              style="color: #4040A0;"
                                              x-text="msg.data?.esp_status?.latency_ms + 'ms'"></span>
                                    </div>
                                </div>

                                {{-- Timestamp --}}
                                <div class="text-xs px-1" style="color: #3030A0;" x-text="msg.time"></div>
                            </div>
                        </div>
                    </template>

                    {{-- ── User Bubble (right) ───────────────────────── --}}
                    <template x-if="msg.role === 'user'">
                        <div class="flex items-end gap-3 max-w-[85%] sm:max-w-[75%] animate-fade-in-right">
                            <div class="flex flex-col items-end gap-1.5">
                                {{-- User Bubble --}}
                                <div class="px-4 py-3 rounded-2xl rounded-br-sm"
                                     style="background: linear-gradient(135deg, #6C63FF, #5248e8); box-shadow: 0 4px 20px rgba(108,99,255,0.3);">
                                    <p class="text-sm leading-relaxed text-white" x-text="msg.text"></p>
                                </div>
                                {{-- Metadata row --}}
                                <div class="flex items-center gap-2 px-1">
                                    {{-- Voice badge --}}
                                    <span x-show="msg.source === 'voice'"
                                          class="text-xs px-1.5 py-0.5 rounded-full"
                                          style="background: rgba(108,99,255,0.12); color: #8080C0; border: 1px solid rgba(108,99,255,0.2);">
                                        🎤 Suara
                                    </span>
                                    <div class="text-xs" style="color: #3030A0;" x-text="msg.time"></div>
                                </div>
                            </div>

                            {{-- User Avatar --}}
                            <div class="w-8 h-8 rounded-xl shrink-0 flex items-center justify-center mb-5"
                                 style="background: rgba(108,99,255,0.2); border: 1px solid rgba(108,99,255,0.3);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#A0A0D0" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- ── AI Typing Indicator ─────────────────────────────── --}}
            <div x-show="isLoading" x-transition class="flex items-end gap-3 animate-fade-in-left">
                <div class="w-8 h-8 rounded-xl shrink-0 flex items-center justify-center"
                     style="background: linear-gradient(135deg, #6C63FF, #00D4AA);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" class="animate-spin-slow">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83" stroke-width="2"/>
                    </svg>
                </div>
                <div class="px-4 py-3.5 rounded-2xl rounded-bl-sm flex items-center gap-1.5"
                     style="background: rgba(20,21,48,0.9); border: 1px solid rgba(108,99,255,0.15);">
                    <div class="w-1.5 h-1.5 rounded-full animate-bounce" style="background: #6C63FF; animation-delay:0s;"></div>
                    <div class="w-1.5 h-1.5 rounded-full animate-bounce" style="background: #6C63FF; animation-delay:0.15s;"></div>
                    <div class="w-1.5 h-1.5 rounded-full animate-bounce" style="background: #6C63FF; animation-delay:0.3s;"></div>
                </div>
            </div>

            {{-- ── Scroll Anchor ───────────────────────────────────── --}}
            <div id="chat-bottom" class="h-1"></div>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             BOTTOM INPUT BAR
             ════════════════════════════════════════════════════════════ --}}
        <div class="shrink-0 px-4 pb-5 pt-3 border-t"
             style="background: rgba(10,11,26,0.95); backdrop-filter: blur(20px); border-color: rgba(108,99,255,0.1);">

            {{-- ── Voice Listening Overlay ────────────────────────── --}}
            <div x-show="isListening" x-transition class="flex items-center justify-center mb-3 py-3 rounded-xl"
                 style="background: rgba(108,99,255,0.08); border: 1px solid rgba(108,99,255,0.2);">
                <div class="flex items-center gap-3">
                    {{-- Animated wave bars --}}
                    <div class="flex items-end gap-1 h-6">
                        <div class="wave-bar w-1 rounded-full" style="background: #6C63FF; height: 8px;"></div>
                        <div class="wave-bar w-1 rounded-full" style="background: #6C63FF; height: 20px;"></div>
                        <div class="wave-bar w-1 rounded-full" style="background: #6C63FF; height: 14px;"></div>
                        <div class="wave-bar w-1 rounded-full" style="background: #6C63FF; height: 22px;"></div>
                        <div class="wave-bar w-1 rounded-full" style="background: #6C63FF; height: 10px;"></div>
                    </div>
                    <span class="text-sm font-medium" style="color: #A0A0D0;">Mendengarkan... berbicara sekarang</span>
                </div>
            </div>

            {{-- ── Input Row ──────────────────────────────────────── --}}
            <div class="flex items-end gap-2.5">

                {{-- Text Input Area --}}
                <div class="relative flex-1">
                    <textarea
                        id="chat-input"
                        x-model="inputText"
                        @keydown="handleKeydown($event)"
                        :disabled="isLoading || isListening"
                        rows="1"
                        placeholder="Ketik perintah... (cth: nyalakan lampu kamar)"
                        class="w-full resize-none text-sm leading-relaxed px-4 py-3.5 pr-4 rounded-2xl outline-none transition-all duration-200 placeholder:text-opacity-40"
                        style="
                            background: rgba(20,21,48,0.9);
                            border: 1px solid rgba(108,99,255,0.15);
                            color: #E0E0F8;
                            max-height: 120px;
                            field-sizing: content;
                        "
                        onfocus="this.style.borderColor='rgba(108,99,255,0.5)'; this.style.boxShadow='0 0 0 3px rgba(108,99,255,0.12)';"
                        onblur="this.style.borderColor='rgba(108,99,255,0.15)'; this.style.boxShadow='none';"
                        :style="isListening ? 'border-color: rgba(108,99,255,0.4); box-shadow: 0 0 0 3px rgba(108,99,255,0.1);' : ''"
                    ></textarea>

                    {{-- Hint text --}}
                    <div x-show="!inputText && !isListening"
                         class="absolute bottom-2 right-3 text-xs pointer-events-none hidden sm:block"
                         style="color: #3030A0;">
                        Enter ↵
                    </div>
                </div>

                {{-- ── Microphone Button ──────────────────────────── --}}
                <button
                    id="btn-microphone"
                    @click="toggleListening()"
                    :disabled="isLoading"
                    :title="isListening ? 'Berhenti mendengarkan' : 'Input suara (klik untuk bicara)'"
                    class="relative w-12 h-12 rounded-xl flex items-center justify-center shrink-0 transition-all duration-200 focus:outline-none"
                    :style="isListening
                        ? 'background: rgba(108,99,255,0.3); border: 1px solid rgba(108,99,255,0.6); box-shadow: 0 0 20px rgba(108,99,255,0.4);'
                        : 'background: rgba(108,99,255,0.1); border: 1px solid rgba(108,99,255,0.2);'"
                    onmouseover="if(!this.disabled) { this.style.background='rgba(108,99,255,0.2)'; this.style.transform='scale(1.05)'; }"
                    onmouseout="this.style.transform='scale(1)';"
                >
                    {{-- Pulse ring saat listening --}}
                    <div x-show="isListening"
                         class="absolute inset-0 rounded-xl animate-pulse-border"
                         style="border: 2px solid rgba(108,99,255,0.5);"></div>

                    {{-- Mic icon --}}
                    <svg x-show="!isListening" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8080D0" stroke-width="2" stroke-linecap="round">
                        <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                        <line x1="12" y1="19" x2="12" y2="23"/>
                        <line x1="8" y1="23" x2="16" y2="23"/>
                    </svg>

                    {{-- Stop icon saat listening --}}
                    <svg x-show="isListening" width="16" height="16" viewBox="0 0 24 24" fill="#6C63FF" stroke="none">
                        <rect x="4" y="4" width="16" height="16" rx="2"/>
                    </svg>
                </button>

                {{-- ── Send Button ────────────────────────────────── --}}
                <button
                    id="btn-send"
                    @click="sendCommand()"
                    :disabled="!inputText.trim() || isLoading"
                    class="w-12 h-12 rounded-xl flex items-center justify-center shrink-0 transition-all duration-200 focus:outline-none"
                    :style="inputText.trim() && !isLoading
                        ? 'background: linear-gradient(135deg, #6C63FF, #5248e8); box-shadow: 0 4px 20px rgba(108,99,255,0.4); cursor: pointer;'
                        : 'background: rgba(108,99,255,0.06); border: 1px solid rgba(108,99,255,0.1); cursor: not-allowed; opacity: 0.5;'"
                    onmouseover="if(!this.disabled){ this.style.transform='scale(1.05)'; }"
                    onmouseout="this.style.transform='scale(1)';"
                >
                    {{-- Loading spinner --}}
                    <svg x-show="isLoading" class="animate-spin-slow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
                        <circle cx="12" cy="12" r="10" stroke-opacity="0.3"/>
                        <path d="M12 2a10 10 0 0 1 10 10"/>
                    </svg>

                    {{-- Send icon --}}
                    <svg x-show="!isLoading" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22,2 15,22 11,13 2,9"/>
                    </svg>
                </button>
            </div>

            {{-- ── Quick Command Chips ─────────────────────────────── --}}
            <div class="flex flex-wrap gap-2 mt-3">
                @foreach([
                    ['💡 Lampu ON',   'nyalakan lampu ruang tamu'],
                    ['💡 Lampu OFF',  'matikan lampu ruang tamu'],
                    ['❄️ AC ON',      'nyalakan AC'],
                    ['❄️ 24°C',       'atur suhu AC 24 derajat'],
                    ['🔐 Kunci',      'kunci pintu depan'],
                    ['🔓 Buka',       'buka pintu depan'],
                ] as [$label, $command])
                <button
                    @click="inputText = '{{ $command }}'; sendCommand();"
                    :disabled="isLoading"
                    class="text-xs px-3 py-1.5 rounded-full font-medium transition-all duration-150 focus:outline-none"
                    style="background: rgba(108,99,255,0.07); border: 1px solid rgba(108,99,255,0.15); color: #7070B0;"
                    onmouseover="if(!this.disabled){ this.style.background='rgba(108,99,255,0.15)'; this.style.borderColor='rgba(108,99,255,0.35)'; this.style.color='#A0A0D0'; }"
                    onmouseout="this.style.background='rgba(108,99,255,0.07)'; this.style.borderColor='rgba(108,99,255,0.15)'; this.style.color='#7070B0';"
                >
                    {{ $label }}
                </button>
                @endforeach
            </div>
        </div>
    </main>

</div>{{-- end x-data --}}

@endsection
