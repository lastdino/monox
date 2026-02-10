<?php

use Livewire\Component;
use Livewire\Attributes\Modelable;

new class extends Component
{
    public ?string $value = '';

    public function openModal(): void
    {
        Flux::modal('camera-'.$this->getId())->show();
    }

    public function closeModal(): void
    {
        Flux::modal('camera-'.$this->getId())->close();
    }

    public function CameraStop(): void
    {
        $this->dispatch('camera-stop')->self();
    }
};
?>

<div x-data="camera{{$this->getId()}}">
    <flux:button icon="camera" variant="subtle" @click="$wire.openModal(); startCamera()">写真を撮影</flux:button>

    <flux:modal name="camera-{{$this->getId()}}" class="md:w-full max-w-lg" @close="CameraStop()">
        <div class="space-y-4">
            <flux:heading size="lg" id="loading-camera-{{$this->getId()}}" x-show="!previewMode">カメラを準備中...</flux:heading>
            <flux:heading size="lg" x-show="previewMode">撮影された写真の確認</flux:heading>

            <div class="relative overflow-hidden rounded-lg bg-black aspect-square flex items-center justify-center">
                <video id="video-{{$this->getId()}}" x-show="!previewMode" class="w-full h-full object-cover" autoplay playsinline muted></video>
                <img :src="capturedImage" x-show="previewMode" class="w-full h-full object-contain">
                <canvas id="canvas-{{$this->getId()}}" class="hidden"></canvas>
            </div>

            <div class="flex justify-center gap-2">
                <template x-if="!previewMode">
                    <flux:button icon="camera" variant="primary" @click="takePhoto()">撮影</flux:button>
                </template>
                <template x-if="previewMode">
                    <div class="flex gap-2">
                        <flux:button variant="ghost" @click="retake()">撮り直し</flux:button>
                        <flux:button variant="primary" @click="usePhoto()">この写真を使用</flux:button>
                    </div>
                </template>
            </div>
        </div>
    </flux:modal>
</div>

@script
<script>
    Alpine.data('camera{{$this->getId()}}', () => ({
        video: null,
        canvas: null,
        stream: null,
        loading: null,
        value: @entangle('value'),
        previewMode: false,
        capturedImage: null,

        init() {
            this.video = document.getElementById('video-{{$this->getId()}}');
            this.canvas = document.getElementById('canvas-{{$this->getId()}}');
            this.loading = document.getElementById('loading-camera-{{$this->getId()}}');

            $wire.on('camera-stop', () => {
                this.stopCamera();
                this.previewMode = false;
                this.capturedImage = null;
            });
        },

        async startCamera() {
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 1280 },
                        height: { ideal: 1280 }
                    }
                });
                this.video.srcObject = this.stream;
                this.loading.hidden = true;
            } catch (err) {
                console.error('Camera access error:', err);
                this.loading.innerText = 'カメラにアクセスできませんでした。';
            }
        },

        stopCamera() {
            if (this.stream) {
                this.stream.getTracks().forEach(track => track.stop());
                this.stream = null;
            }
            this.video.srcObject = null;
            this.loading.hidden = false;
        },

        takePhoto() {
            const context = this.canvas.getContext('2d');
            this.canvas.width = this.video.videoWidth;
            this.canvas.height = this.video.videoHeight;

            context.drawImage(this.video, 0, 0, this.canvas.width, this.canvas.height);

            const dataUrl = this.canvas.toDataURL('image/jpeg', 0.8);
            this.capturedImage = dataUrl;
            this.previewMode = true;
            this.stopCamera();
        },

        retake() {
            this.previewMode = false;
            this.capturedImage = null;
            this.startCamera();
        },

        usePhoto() {
            this.value = this.capturedImage;
            this.$dispatch('photo-captured', this.capturedImage);
            this.previewMode = false;
            this.capturedImage = null;
            $wire.closeModal();
        }
    }))
</script>
@endscript
