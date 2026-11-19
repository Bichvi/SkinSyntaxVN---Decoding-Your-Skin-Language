(() => {
    'use strict';

    /**
     * SkinSyntax LiveStudioMedia
     * Unified canvas compositor & WebAudio graph for seamless source switching
     * (Camera ↔ Screen ↔ MP4 Video ↔ AI Video) without breaking WebRTC peer connections.
     */
    window.LiveStudioMedia = class {
        constructor(previewElement, onSourceEnded) {
            this.preview = previewElement;
            this.onSourceEnded = onSourceEnded;

            // 1280x720 30fps canvas compositor
            this.canvas = document.createElement('canvas');
            this.canvas.width = 1280;
            this.canvas.height = 720;
            this.context = this.canvas.getContext('2d', {alpha: false});

            // WebAudio Graph
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            this.audio = new AudioCtx();
            this.destination = this.audio.createMediaStreamDestination();
            this.masterGain = this.audio.createGain();
            this.masterGain.connect(this.destination);

            // Analyser for VU meter
            this.analyser = this.audio.createAnalyser();
            this.analyser.fftSize = 64;
            this.masterGain.connect(this.analyser);
            this.frequencyData = new Uint8Array(this.analyser.frequencyBinCount);

            // Capture stream
            const canvasStream = this.canvas.captureStream(30);
            this.videoOutput = canvasStream.getVideoTracks()[0];
            this.audioOutput = this.destination.stream.getAudioTracks()[0];

            this.output = new MediaStream([this.videoOutput, this.audioOutput]);
            if (this.preview) {
                this.preview.srcObject = this.output;
            }

            this.source = null;
            this.isCamMuted = false;
            this.isMicMuted = false;
            this.audioFallback = false;

            // Render loop at 30fps
            this.timer = setInterval(() => this.draw(), 1000 / 30);
            this.draw();
        }

        async select(kind, file, loop = false, cameraId = '', micId = '') {
            if (this.audio.state === 'suspended') {
                await this.audio.resume().catch(() => {});
            }

            const source = {
                kind,
                video: document.createElement('video'),
                stream: null,
                audioNode: null,
                micStream: null,
                micNode: null,
                cameraId,
                micId,
                // Keep the legacy property while the unified live page migrates
                // from source.mic to source.micStream.
                mic: null,
                audio_fallback: false,
                audio_track_count: 0
            };
            const video = source.video;
            video.playsInline = true;
            video.muted = true; // prevent local acoustic feedback loop

            try {
                if (kind === 'camera') {
                    // 1. Video constraints
                    const videoConstraints = cameraId
                        ? {deviceId: {exact: cameraId}, width: {ideal: 1280}, height: {ideal: 720}}
                        : {width: {ideal: 1280}, height: {ideal: 720}, facingMode: 'user'};

                    // 2. Audio constraints
                    const audioConstraints = micId
                        ? {deviceId: {exact: micId}, echoCancellation: true, noiseSuppression: true}
                        : {echoCancellation: true, noiseSuppression: true};

                    let userStream;
                    let audioFallback = false;
                    try {
                        userStream = await navigator.mediaDevices.getUserMedia({
                            video: videoConstraints,
                            audio: audioConstraints
                        });
                    } catch (mediaErr) {
                        // Fallback to video only if mic fails or permission denied for audio
                        console.warn('getUserMedia with audio failed, attempting video-only:', mediaErr);
                        audioFallback = true;
                        userStream = await navigator.mediaDevices.getUserMedia({video: videoConstraints});
                    }

                    source.stream = userStream;
                    video.srcObject = userStream;

                    // Setup audio track from mic
                    const audioTracks = userStream.getAudioTracks();
                    source.audio_track_count = audioTracks.length;
                    source.audio_fallback = audioFallback || audioTracks.length === 0;
                    this.audioFallback = source.audio_fallback;
                    if (audioTracks.length > 0) {
                        source.micStream = new MediaStream([audioTracks[0]]);
                        source.micNode = this.audio.createMediaStreamSource(source.micStream);
                        source.micNode.connect(this.masterGain);
                        source.mic = source.micStream;
                    }

                    const videoTrack = userStream.getVideoTracks()[0];
                    if (!videoTrack) throw new Error('Camera chưa trả về luồng hình ảnh thật.');
                    if (videoTrack) {
                        videoTrack.addEventListener('ended', () => this.handleSourceEnded(source), {once: true});
                    }
                } else if (kind === 'screen') {
                    const screenStream = await navigator.mediaDevices.getDisplayMedia({
                        video: {width: {ideal: 1920}, height: {ideal: 1080}},
                        audio: true
                    });

                    source.stream = screenStream;
                    video.srcObject = screenStream;

                    const screenAudio = screenStream.getAudioTracks();
                    source.audio_track_count = screenAudio.length;
                    source.audio_fallback = screenAudio.length === 0;
                    this.audioFallback = source.audio_fallback;
                    if (screenAudio.length > 0) {
                        source.audioNode = this.audio.createMediaStreamSource(new MediaStream([screenAudio[0]]));
                        source.audioNode.connect(this.masterGain);
                    }

                    const screenVideo = screenStream.getVideoTracks()[0];
                    if (screenVideo) {
                        screenVideo.addEventListener('ended', () => this.handleSourceEnded(source), {once: true});
                    }
                } else {
                    // Pre-recorded MP4 video or AI video
                    if (!file) throw new Error('Vui lòng chọn hoặc tải lên tệp video MP4.');
                    source.url = file instanceof Blob ? URL.createObjectURL(file) : (typeof file === 'string' ? file : '');
                    video.crossOrigin = 'anonymous';
                    video.src = source.url;
                    video.loop = Boolean(loop);

                    // Connect media element audio to destination
                    try {
                        source.audioNode = this.audio.createMediaElementSource(video);
                        source.audioNode.connect(this.masterGain);
                    } catch (e) {
                        console.warn('Could not connect media element source to WebAudio:', e);
                    }
                    source.audio_fallback = false;
                    this.audioFallback = false;

                    video.addEventListener('ended', () => this.handleSourceEnded(source));
                }

                console.info('[SkinSyntax Live] media_source', {kind: source.kind, audioTracks: source.audio_track_count, audioFallback: source.audio_fallback});
                await video.play();
            } catch (err) {
                this.releaseSource(source);
                let message = err.message || 'Không thể mở nguồn phát.';
                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    message = 'Trình duyệt chưa được cấp quyền truy cập Camera/Micro. Vui lòng cho phép quyền trong cài đặt trình duyệt.';
                } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                    message = 'Không tìm thấy thiết bị Camera hoặc Micro phù hợp trên máy tính.';
                } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
                    message = 'Camera hoặc Micro đang bị một ứng dụng khác (Zoom, Teams, v.v.) chiếm dụng.';
                }
                const error = new Error(message);
                error.original = err;
                throw error;
            }

            const prev = this.source;
            this.source = source;
            this.releaseSource(prev);

            if (this.preview) {
                this.preview.hidden = false;
            }
            this.draw();
            return kind;
        }

        async setMicrophoneDevice(micId) {
            if (!this.source || this.source.kind !== 'camera') return;
            try {
                if (this.source.micNode) {
                    this.source.micNode.disconnect();
                }
                if (this.source.micStream) {
                    this.source.micStream.getTracks().forEach(t => t.stop());
                }

                const newAudioStream = await navigator.mediaDevices.getUserMedia({
                    audio: micId ? {deviceId: {exact: micId}} : true
                });
                this.source.micStream = newAudioStream;
                this.source.micNode = this.audio.createMediaStreamSource(newAudioStream);
                this.source.micNode.connect(this.masterGain);
                this.source.micId = micId;
                this.source.mic = newAudioStream;
                this.source.audio_track_count = newAudioStream.getAudioTracks().length;
                this.source.audio_fallback = this.source.audio_track_count === 0;
                this.audioFallback = this.source.audio_fallback;
                this.isMicMuted = false;
                this.masterGain.gain.value = 1;
                return true;
            } catch (e) {
                console.error('Failed to change mic device:', e);
                return false;
            }
        }

        toggleCameraMute() {
            this.isCamMuted = !this.isCamMuted;
            if (this.source?.stream) {
                this.source.stream.getVideoTracks().forEach(t => {
                    t.enabled = !this.isCamMuted;
                });
            }
            return this.isCamMuted;
        }

        toggleMicMute() {
            this.isMicMuted = !this.isMicMuted;
            this.masterGain.gain.value = this.isMicMuted ? 0 : 1;
            if (this.source?.micStream) {
                this.source.micStream.getAudioTracks().forEach(t => {
                    t.enabled = !this.isMicMuted;
                });
            }
            return this.isMicMuted;
        }

        // Compatibility with the unified live page's original media contract.
        async toggleMicrophone(deviceId = '') {
            if (this.source?.kind !== 'camera') throw new Error('Micrô chỉ bật trong chế độ camera.');
            if (this.source.micStream?.getAudioTracks().length) {
                this.source.micNode?.disconnect();
                this.source.micStream.getTracks().forEach(track => track.stop());
                this.source.micStream = null;
                this.source.mic = null;
                this.isMicMuted = false;
                this.masterGain.gain.value = 1;
                return false;
            }
            return Boolean(await this.setMicrophoneDevice(deviceId));
        }

        setMuted(value) {
            this.isMicMuted = Boolean(value);
            this.masterGain.gain.value = this.isMicMuted ? 0 : 1;
            if (this.source?.micStream) {
                this.source.micStream.getAudioTracks().forEach(track => {
                    track.enabled = !this.isMicMuted;
                });
            }
        }

        getAudioLevel() {
            if (!this.analyser || this.isMicMuted) return 0;
            this.analyser.getByteFrequencyData(this.frequencyData);
            let sum = 0;
            for (let i = 0; i < this.frequencyData.length; i++) {
                sum += this.frequencyData[i];
            }
            const avg = sum / this.frequencyData.length;
            return Math.min(100, Math.round((avg / 128) * 100));
        }

        handleSourceEnded(source) {
            if (source === this.source && typeof this.onSourceEnded === 'function') {
                this.onSourceEnded(source.kind);
            }
        }

        draw() {
            const {width, height} = this.canvas;
            this.context.fillStyle = '#0a1711';
            this.context.fillRect(0, 0, width, height);

            const video = this.source?.video;
            if (!video || video.readyState < 2 || this.isCamMuted) {
                // If camera is muted or video not ready, render standby frame
                this.context.fillStyle = '#183b2b';
                this.context.font = 'bold 36px sans-serif';
                this.context.textAlign = 'center';
                this.context.textBaseline = 'middle';
                const label = this.isCamMuted ? 'Camera đang tắt' : 'Đang tải nguồn phát...';
                this.context.fillStyle = '#a3c2b0';
                this.context.fillText(label, width / 2, height / 2);
                return;
            }

            // Aspect ratio preserving fit
            const vw = video.videoWidth || 1280;
            const vh = video.videoHeight || 720;
            const scale = Math.min(width / vw, height / vh);
            const w = vw * scale;
            const h = vh * scale;
            const x = (width - w) / 2;
            const y = (height - h) / 2;

            try {
                this.context.drawImage(video, x, y, w, h);
            } catch (e) {}
        }

        releaseSource(source) {
            if (!source) return;
            if (source.audioNode) {
                source.audioNode.disconnect();
            }
            if (source.micNode) {
                source.micNode.disconnect();
            }
            if (source.stream) {
                source.stream.getTracks().forEach(t => t.stop());
            }
            if (source.micStream) {
                source.micStream.getTracks().forEach(t => t.stop());
            }
            if (source.video) {
                source.video.pause();
                source.video.removeAttribute('src');
                source.video.srcObject = null;
            }
            if (source.url && source.url.startsWith('blob:')) {
                URL.revokeObjectURL(source.url);
            }
        }

        release(source) {
            this.releaseSource(source);
        }

        close() {
            this.releaseSource(this.source);
            this.source = null;
            clearInterval(this.timer);
            if (this.videoOutput) this.videoOutput.stop();
            if (this.audioOutput) this.audioOutput.stop();
            if (this.audio && this.audio.state !== 'closed') {
                this.audio.close().catch(() => {});
            }
        }
    };
})();
