<?php

use Livewire\Component;
use Livewire\Attributes\Modelable;

new class extends Component
{
    #[Modelable]
    public ?string $value = '';

    public function openModal(): void
    {
        Flux::modal('qr-'.$this->getId())->show();
    }

    public function closeModal(): void
    {
        Flux::modal('qr-'.$this->getId())->close();
    }
    public function CameraStop(): void
    {
        $this->dispatch('qr-reader-stop')->self();
    }

    public function read(string $code): void
    {
        $d = $code;
        $this->value = $d;
        $this->dispatch('qr-scanned', $d);
        $this->closeModal();
    }
};
?>

<div x-data="qr{{$this->getId()}}">
    <flux:button icon="qr-code" variant="subtle" @click="$wire.openModal(); reader()"/>
    {{--QR--}}
    <flux:modal name="qr-{{$this->getId()}}" class="md:w-96" @close="CameraStop()">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg" id="loading-{{$this->getId()}}">ブラウザのカメラの使用を許可してください。</flux:heading>
                <input type="hidden" wire:model="value">
                <canvas class="w-75" id="canvas-{{$this->getId()}}" hidden></canvas>
            </div>
        </div>
    </flux:modal>
</div>

@script
<script>
    const video = document.createElement('video');
    const canvasElement = document.getElementById('canvas-{{$this->getId()}}');
    const canvas = canvasElement.getContext('2d', { willReadFrequently: true });
    const loading = document.getElementById('loading-{{$this->getId()}}');
    let previousData = '';
    let vid = '';
    let streamRef = null;
    let running = false;

    Alpine.data('qr{{$this->getId()}}', () => ({
        status: 'カメラを起動中...',
        init(){
            $wire.on('qr-reader-stop', () => {
                this.CameraStop();
            });
        },
        CameraStop() {
            // Livewire のメソッドを呼び出してサーバー側で停止イベントを発火
            running = false;
            if (streamRef) {
                streamRef.getTracks().forEach(function(track) {
                    track.stop();
                });
                streamRef = null;
            }
            if (vid) { cancelAnimationFrame(vid); }
            canvasElement.hidden = true;
            previousData = '';
            loading.hidden = false;
            loading.innerText = 'ブラウザのカメラの使用を許可してください。';
        },
        reader() {
            navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'environment',
                }
            })
                .then((stream) => {
                    streamRef = stream;
                    video.srcObject = stream;
                    video.setAttribute('playsinline', true);
                    video.play();
                    running = true;
                    const loop = () => { if (!running) { return; } tick(); vid = requestAnimationFrame(loop); };
                    vid = requestAnimationFrame(loop);
                })
                .catch((err) => {
                    loading.innerText = 'カメラにアクセスできません: ' + ((err && err.message) ? err.message : '不明なエラー');
                });
        }
    }))



    function drawLine(begin, end, color) {
        canvas.beginPath();
        canvas.moveTo(begin.x, begin.y);
        canvas.lineTo(end.x, end.y);
        canvas.lineWidth = 4;
        canvas.strokeStyle = color;
        canvas.stroke();
    }

    function tick() {
        loading.innerText = 'ロード中...';
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            loading.hidden = true;
            canvasElement.hidden = false;
            canvasElement.height = video.videoHeight;
            canvasElement.width = video.videoWidth;
            canvas.drawImage(video, 0, 0, canvasElement.width, canvasElement.height);
            var imageData = canvas.getImageData(0, 0, canvasElement.width, canvasElement.height);
            var code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: 'dontInvert',
            });

            if (code) {
                drawLine(code.location.topLeftCorner, code.location.topRightCorner, "#FF3B58");
                drawLine(code.location.topRightCorner, code.location.bottomRightCorner, "#FF3B58");
                drawLine(code.location.bottomRightCorner, code.location.bottomLeftCorner, "#FF3B58");
                drawLine(code.location.bottomLeftCorner, code.location.topLeftCorner, "#FF3B58");
            }


            // 直前に読み込んだQRコードの会員ならスキップさせる。そうしないと同じQRコードを常にリクエストしちゃう
            if (code && code.data !== previousData) {
                console.log(code.data);
                previousData = code.data; // いま読み込んだデータをチェックに使うために変数に退避しておく
                $wire.read(code.data);
            }
        }
    }
</script>
@endscript
