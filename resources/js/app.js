import Alpine from 'alpinejs';
import './echo';

window.Alpine = Alpine;

// ── SmartHome Chat Application ─────────────────────────────────────────
Alpine.data('smarthomeChat', () => ({

    // ── State ────────────────────────────────────────────────────────
    messages: [],
    inputText: '',
    inputSource: 'text',
    isLoading: false,
    isListening: false,
    isSpeaking: false,
    currentAudio: null,
    esp32Online: null,
    stats: {},
    sidebarHistory: [],
    sidebarOpen: false,
    currentDevice: null,
    recognition: null,
    voices: [],

    // ── Lifecycle ────────────────────────────────────────────────────
    init() {
        this.initSpeechRecognition();
        this.loadStats();
        this.checkEsp32Status();
        this.initWebSocket();
        this.addAiMessage(
            'Halo! 👋 Saya adalah asisten Smart Home Anda yang didukung AI. ' +
            'Anda bisa mengetik atau menekan tombol 🎤 untuk berbicara. ' +
            'Contoh: <strong>"nyalakan lampu"</strong>, <strong>"matikan AC"</strong>, ' +
            'atau <strong>"kunci pintu"</strong>.',
            null,
            true // isHtml
        );
        this.scrollToBottom();
    },

    // ── Core: Send Command ───────────────────────────────────────────
    async sendCommand() {
        const command = this.inputText.trim();
        if (!command || this.isLoading) return;

        // Tambah pesan user ke chat
        this.addUserMessage(command, this.inputSource);
        const source = this.inputSource;

        // Reset input
        this.inputText = '';
        this.inputSource = 'text';
        this.isLoading = true;
        this.scrollToBottom();

        try {
            const response = await fetch('/api/command', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    text_command: command,
                    input_source: source,
                }),
            });

            const data = await response.json();

            if (data.success) {
                this.addAiMessage(data.ai_reply, data);

                if (data.audio_url) {
                    this.speakText(data.ai_reply, data.audio_url);
                }
                // Audio TTS dikirimkan secara real-time via WebSocket broadcast (AudioReady) dari Reverb

                // Update device indicator jika ada
                if (data.device) {
                    this.currentDevice = {
                        name: data.device,
                        action: data.action,
                    };
                    setTimeout(() => { this.currentDevice = null; }, 4000);
                }
            } else {
                this.addAiMessage(data.ai_reply || 'Maaf, terjadi kesalahan.', data, false, 'error');
                if (data.audio_url) {
                    this.speakText(data.ai_reply, data.audio_url);
                } else if (data.log_id) {
                    this.pollAudioUrl(data.log_id, data.ai_reply);
                }
            }

            // Refresh stats & history sidebar
            this.loadStats();
            this.loadSidebarHistory();

        } catch (err) {
            console.error('[SmartHome] Fetch error:', err);
            this.addAiMessage(
                'Tidak dapat terhubung ke server. Pastikan server Laravel berjalan.',
                null, false, 'error'
            );
        } finally {
            this.isLoading = false;
            this.scrollToBottom();
        }
    },

    // ── Message Helpers ──────────────────────────────────────────────
    addUserMessage(text, source = 'text') {
        this.messages.push({
            id: Date.now(),
            role: 'user',
            text,
            source, // 'text' | 'voice'
            time: this.formatTime(),
        });
    },

    addAiMessage(text, data = null, isHtml = false, type = 'default') {
        this.messages.push({
            id: Date.now(),
            role: 'ai',
            text,
            isHtml,
            type,   // 'default' | 'error'
            data,   // Full server response untuk badge info
            time: this.formatTime(),
        });
    },

    // ── Speech Recognition (Input Suara) ─────────────────────────────
    initSpeechRecognition() {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

        if (!SpeechRecognition) {
            console.warn('[SmartHome] SpeechRecognition tidak didukung browser ini.');
            return;
        }

        this.recognition = new SpeechRecognition();
        this.recognition.lang = 'id-ID'; // Bahasa Indonesia
        this.recognition.continuous = false;
        this.recognition.interimResults = true;
        this.recognition.maxAlternatives = 1;

        // Hasil interim (preview saat berbicara)
        this.recognition.onresult = (event) => {
            const transcript = Array.from(event.results)
                .map(r => r[0].transcript)
                .join('');

            this.inputText = transcript;

            // Jika final result → langsung submit
            if (event.results[event.results.length - 1].isFinal) {
                this.inputSource = 'voice';
                this.stopListening();
                this.$nextTick(() => this.sendCommand());
            }
        };

        this.recognition.onerror = (event) => {
            console.error('[Speech] Error:', event.error);
            this.isListening = false;

            const isHttpDomain = !window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1';
            const errorMessages = {
                'no-speech':          'Tidak ada suara terdeteksi. Coba lagi.',
                'audio-capture':      'Mikrofon tidak dapat diakses.',
                'not-allowed':        isHttpDomain
                                        ? 'Izin mikrofon diblokir browser pada domain HTTP (' + location.hostname + '). Silakan akses via http://localhost:8000 atau aktifkan SSL HTTPS.'
                                        : 'Izin mikrofon ditolak. Aktifkan izin mikrofon di pengaturan browser Anda.',
                'network':            'Koneksi diperlukan untuk pengenalan suara.',
                'service-not-allowed':'Layanan Speech tidak tersedia.',
            };
            const msg = errorMessages[event.error] || `Error mikrofon: ${event.error}`;
            this.addAiMessage(msg, null, false, 'error');
        };

        this.recognition.onend = () => {
            this.isListening = false;
        };
    },

    toggleListening() {
        if (this.isListening) {
            this.stopListening();
        } else {
            this.startListening();
        }
    },

    startListening() {
        if (!this.recognition) {
            this.addAiMessage(
                'Browser Anda tidak mendukung Speech Recognition. Gunakan Chrome.',
                null, false, 'error'
            );
            return;
        }
        // Stop speech synthesis jika sedang berbicara
        if (this.isSpeaking) window.speechSynthesis.cancel();

        this.isListening = true;
        this.inputText = '';
        this.recognition.start();
    },

    stopListening() {
        if (this.recognition && this.isListening) {
            this.recognition.stop();
        }
        this.isListening = false;
    },

    // ── Speech Synthesis (Edge-TTS only) ─────────────────────────────
    // Browser SpeechSynthesis TIDAK digunakan. Semua audio dari Edge-TTS MP3.

    playAudioUrl(url) {
        if (this.currentAudio) {
            this.currentAudio.pause();
            this.currentAudio = null;
        }


        const audio = new Audio(url);
        this.currentAudio = audio;
        this.isSpeaking = true;

        audio.onplay = () => { this.isSpeaking = true; };
        audio.onended = () => { this.isSpeaking = false; this.currentAudio = null; };
        audio.onerror = (e) => {
            console.error('[Audio] Error playing audio MP3:', e);
            this.isSpeaking = false;
            this.currentAudio = null;
        };

        audio.play().catch(err => {
            console.warn('[Audio] Playback blocked or error:', err);
            this.isSpeaking = false;
        });
    },

    speakText(text, audioUrl = null) {
        // Hanya putar Edge-TTS MP3. Tidak ada fallback ke browser SpeechSynthesis.
        if (audioUrl) {
            this.playAudioUrl(audioUrl);
        } else {
            console.warn('[TTS] Tidak ada audio_url dari server. Pastikan Edge-TTS berjalan dengan benar.');
        }
    },

    stopSpeaking() {
        if (this.currentAudio) {
            this.currentAudio.pause();
            this.currentAudio = null;
        }
        this.isSpeaking = false;
    },

    // ── WebSocket Real-Time Broadcast (Laravel Reverb) ───────────────
    initWebSocket() {
        if (!window.Echo) {
            console.warn('[WebSocket] Laravel Echo belum diinisialisasi.');
            return;
        }

        window.Echo.channel('smarthome.global')
            .listen('.AudioReady', (event) => {
                console.log('[WebSocket] Event AudioReady diterima via Reverb:', event);
                if (event && event.audio_url) {
                    const msg = this.messages.find(m => m.data && m.data.log_id === event.log_id);
                    if (msg && msg.data) {
                        msg.data.audio_url = event.audio_url;
                    }
                    this.speakText(msg ? msg.text : '', event.audio_url);
                }
            });
    },

    // ── Asynchronous Audio Polling ───────────────────────────────────
    async pollAudioUrl(logId, aiReply, attempt = 1) {
        const maxAttempts = 20; // 20 x 600ms = 12 detik batas polling
        if (attempt > maxAttempts) {
            console.warn(`[TTS] Audio polling timeout untuk log_id: ${logId}`);
            return;
        }

        try {
            const res = await fetch(`/api/command/${logId}/audio`, {
                headers: { 'Accept': 'application/json' }
            });
            if (res.ok) {
                const result = await res.json();
                if (result.tts_status === 'done' && result.audio_url) {
                    // Update metadata pesan di daftar chat
                    const msg = this.messages.find(m => m.data && m.data.log_id === logId);
                    if (msg && msg.data) {
                        msg.data.audio_url = result.audio_url;
                    }
                    this.speakText(aiReply, result.audio_url);
                    return;
                }
                if (result.tts_status === 'failed') {
                    console.warn(`[TTS] Audio generation gagal di server untuk log_id: ${logId}`);
                    return;
                }
            }
        } catch (err) {
            console.debug('[TTS] Polling exception:', err);
        }

        // Coba lagi setelah 600ms
        setTimeout(() => {
            this.pollAudioUrl(logId, aiReply, attempt + 1);
        }, 600);
    },

    // ── Sidebar Data ─────────────────────────────────────────────────
    async loadStats() {
        try {
            const r = await fetch('/api/command/stats', {
                headers: { 'Accept': 'application/json' }
            });
            const json = await r.json();
            if (json.success) this.stats = json.data;
        } catch { /* silently fail */ }
    },

    async loadSidebarHistory() {
        try {
            const r = await fetch('/api/command/history', {
                headers: { 'Accept': 'application/json' }
            });
            const json = await r.json();
            if (json.success) this.sidebarHistory = json.data;
        } catch { /* silently fail */ }
    },

    async checkEsp32Status() {
        try {
            const r = await fetch('/api/esp32/status', {
                headers: { 'Accept': 'application/json' }
            });
            const json = await r.json();
            this.esp32Online = json.online;
        } catch {
            this.esp32Online = false;
        }
    },

    // ── Utility ──────────────────────────────────────────────────────
    scrollToBottom() {
        this.$nextTick(() => {
            const el = this.$refs.chatContainer;
            if (el) el.scrollTop = el.scrollHeight;
        });
    },

    formatTime() {
        return new Date().toLocaleTimeString('id-ID', {
            hour: '2-digit', minute: '2-digit'
        });
    },

    handleKeydown(e) {
        // Enter = submit, Shift+Enter = newline
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.sendCommand();
        }
    },

    // Badge label untuk badge status command di chat bubble
    getBadgeIntent(data) {
        if (!data) return null;
        const labels = {
            control: { label: 'Kontrol', color: 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30' },
            query:   { label: 'Query',   color: 'bg-teal-500/20 text-teal-300 border-teal-500/30' },
            unknown: { label: 'Unknown', color: 'bg-red-500/20 text-red-300 border-red-500/30' },
        };
        return labels[data.intent] || null;
    },
}));

Alpine.start();

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
