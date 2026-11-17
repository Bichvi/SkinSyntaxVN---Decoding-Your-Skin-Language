(() => {
    'use strict';

    // A single published pair stays attached while the producer switches sources.
    window.LiveStudioMedia = class {
        constructor(preview, onSourceEnded) {
            this.preview = preview;
            this.onSourceEnded = onSourceEnded;
            this.canvas = document.createElement('canvas');
            this.canvas.width = 1280;
            this.canvas.height = 720;
            this.context = this.canvas.getContext('2d', {alpha: false});
            this.audio = new AudioContext();
            this.destination = this.audio.createMediaStreamDestination();
            this.gain = this.audio.createGain();
            this.gain.connect(this.destination);
            this.videoOutput = this.canvas.captureStream(30).getVideoTracks()[0];
            this.audioOutput = this.destination.stream.getAudioTracks()[0];
            this.output = new MediaStream([this.videoOutput, this.audioOutput]);
            this.preview.srcObject = this.output;
            this.timer = setInterval(() => this.draw(), 1000 / 30);
            this.draw();
        }

        async select(kind, file, loop, cameraId = '') {
            // The user gesture opens the Web Audio graph before requesting permissions.
            await this.audio.resume();
            if (kind === 'camera' && this.source?.kind === kind && this.source.cameraId === cameraId &&
                this.source.stream.getVideoTracks()[0]?.readyState === 'live') return kind;
            const source = {kind, video: document.createElement('video')};
            const video = source.video;
            video.playsInline = true;
            try {
                if (kind === 'camera' || kind === 'screen') {
                    source.cameraId = cameraId;
                    source.stream = kind === 'camera'
                        ? await navigator.mediaDevices.getUserMedia({video: cameraId ? {deviceId: {exact: cameraId}} : true})
                        : await navigator.mediaDevices.getDisplayMedia({video: true, audio: true});
                    video.srcObject = source.stream;
                    video.muted = true;
                    if (source.stream.getAudioTracks().length) source.audio = this.audio.createMediaStreamSource(source.stream);
                    source.stream.getVideoTracks()[0].addEventListener('ended', () => this.sourceEnded(source), {once: true});
                } else {
                    if (!file) throw new Error('Hãy chọn video MP4.');
                    source.url = file instanceof Blob ? URL.createObjectURL(file) : '';
                    video.crossOrigin = 'anonymous';
                    video.src = source.url || file;
                    video.loop = loop;
                    source.audio = this.audio.createMediaElementSource(video);
                    video.addEventListener('ended', () => this.sourceEnded(source));
                }
                await video.play();
                if (video.ended || source.stream?.getVideoTracks()[0]?.readyState === 'ended') {
                    throw new Error('Nguồn video đã dừng.');
                }
            } catch (error) {
                this.release(source);
                throw error;
            }
            const previous = this.source;
            this.source = source;
            this.release(previous);
            if (source.audio) source.audio.connect(this.gain);
            this.draw();
            this.preview.hidden = false;
            return kind;
        }

        async toggleMicrophone(deviceId = '') {
            const source = this.source;
            if (source?.kind !== 'camera') throw new Error('Micrô chỉ bật trong chế độ camera.');
            if (source.mic) {
                source.audio.disconnect();
                source.mic.getTracks().forEach(track => track.stop());
                source.mic = source.audio = null;
                return false;
            }
            const mic = await navigator.mediaDevices.getUserMedia({audio: deviceId ? {deviceId: {exact: deviceId}} : true});
            if (this.source !== source) {
                mic.getTracks().forEach(track => track.stop());
                return false;
            }
            source.mic = mic;
            source.audio = this.audio.createMediaStreamSource(mic);
            source.audio.connect(this.gain);
            return true;
        }

        setMuted(value) {
            this.gain.gain.value = value ? 0 : 1;
        }

        sourceEnded(source) {
            if (source === this.source) this.onSourceEnded(source.kind);
        }

        draw() {
            const video = this.source?.video;
            if (video?.readyState < 2) return;
            const {width, height} = this.canvas;
            this.context.fillStyle = '#000';
            this.context.fillRect(0, 0, width, height);
            if (video?.videoWidth) {
                const scale = Math.min(width / video.videoWidth, height / video.videoHeight);
                const w = video.videoWidth * scale, h = video.videoHeight * scale;
                this.context.drawImage(video, (width - w) / 2, (height - h) / 2, w, h);
            }
        }

        release(source) {
            if (!source) return;
            source.audio?.disconnect();
            source.video.pause();
            source.stream?.getTracks().forEach(track => track.stop());
            source.mic?.getTracks().forEach(track => track.stop());
            source.video.removeAttribute('src');
            source.video.srcObject = null;
            if (source.url) URL.revokeObjectURL(source.url);
        }

        close() {
            this.release(this.source);
            clearInterval(this.timer);
            this.output.getTracks().forEach(track => track.stop());
            this.audio.close();
        }
    };
})();
