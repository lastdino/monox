<?php

use Flux\Flux;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\ProductionOrder;
use Livewire\Component;
use Livewire\Attributes\Layout;

use tbQuar\Facades\Quar;

new #[Layout('monox::layouts.print')] class extends Component
{
    public int $departmentId;
    public ProductionOrder $order;

    public function mount($department_id, $order): void
    {
        if ($department_id instanceof \Illuminate\Database\Eloquent\Model) {
            $this->departmentId = $department_id->getKey();
        } else {
            $this->departmentId = (int) $department_id;
        }

        if ($order instanceof ProductionOrder) {
            $this->order = $order;
        } else {
            $this->order = ProductionOrder::findOrFail($order);
        }

        $this->order->load(['item.processes', 'lot']);
    }

    public function getQrCode(string $data, int $size = 80): string
    {
        return Quar::size($size)->generate($data);
    }
};
?>

<div class="travel-sheet">
    <div class="no-print mb-4 flex justify-end">
        <flux:button icon="printer" variant="primary" onclick="window.print()">印刷する</flux:button>
        <flux:button variant="ghost" href="{{ route('monox.production.index', ['department_id' => $departmentId]) }}" class="ml-2">戻る</flux:button>
    </div>

    <div class="sheet-container bg-white text-black p-4 sm:p-8 border border-gray-300 mx-auto shadow-sm print:shadow-none print:border-none print:p-0">
        <div class="flex justify-between items-start border-b-2 border-black pb-2 mb-6">
            <div>
                <h1 class="text-2xl font-bold">トラベルシート (製造指図書)</h1>
                <p class="text-xs">作成日: {{ $order->created_at->format(config('monox.datetime.formats.datetime', 'Y/m/d H:i')) }}</p>
            </div>
            <div class="text-right">
                <div class="text-base font-bold">指図ID: {{ $order->id }}</div>
                <div class="mt-2 inline-block">
                    {!! $this->getQrCode(route('monox.production.worksheet', [
                        'department' => $departmentId,
                        'order' => $order->id,
                    ]), config('monox.production.travel_sheet.qr_sizes.order_url', 60)) !!}
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="border border-black p-3 flex justify-between items-center">
                <div>
                    <div class="text-xs text-gray-500 uppercase leading-none mb-1">品目名 / Item Name</div>
                    <div class="text-xl font-bold leading-tight">{{ $order->item->name }}</div>
                    <div class="text-sm">{{ $order->item->code }}</div>
                </div>
            </div>
            <div class="border border-black p-3">
                <div class="text-xs text-gray-500 uppercase leading-none mb-1">部門 / Department</div>
                <div class="text-xl font-bold">{{ $order->department->name ?? '-' }}</div>
            </div>
            <div class="border border-black p-3 flex justify-between items-center">
                <div>
                    <div class="text-xs text-gray-500 uppercase leading-none mb-1">ロット番号 / Lot Number</div>
                    <div class="text-xl font-bold leading-tight">{{ $order->lot->lot_number ?? '-' }}</div>
                </div>
                @if($order->lot?->lot_number)
                    <div class="ml-2 shrink-0">
                        {!! $this->getQrCode($order->lot->lot_number, config('monox.production.travel_sheet.qr_sizes.lot_number', 50)) !!}
                    </div>
                @endif
            </div>
            <div class="border border-black p-3">
                <div class="text-xs text-gray-500 uppercase leading-none mb-1">予定数量 / Target Quantity</div>
                <div class="text-xl font-bold">{{ number_format($order->target_quantity, 2) }} {{ $order->item->unit }}</div>
            </div>

        </div>

        <div class="mb-4">
            <h2 class="text-xs font-bold border-b border-black mb-2 uppercase text-gray-700 pb-1">製造工程 / Production Processes</h2>
            <table class="w-full border-collapse border border-black text-sm">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border border-black px-2 py-1 w-12 text-center">#</th>
                        <th class="border border-black px-2 py-1 w-20 text-center">QR</th>
                        <th class="border border-black px-2 py-1">工程名 / Process</th>
                        <th class="border border-black px-2 py-1 w-24 text-center">完品数 / Good</th>
                        <th class="border border-black px-2 py-1 w-28 text-center">着手印</th>
                        <th class="border border-black px-2 py-1 w-28 text-center">完了印</th>
                        <th class="border border-black px-2 py-1">備考 / Note</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $processParam = config('monox.production.worksheet_process_parameter', 'process');
                        $records = $order->productionRecords->keyBy('process_id');
                    @endphp
                    @forelse($order->item->processes as $process)
                        @php
                            $record = $records->get($process->id);
                        @endphp
                        <tr>
                            <td class="border border-black p-2 text-center text-base">{{ $process->sort_order }}</td>
                            <td class="border border-black p-1 text-center">
                                <div class="flex justify-center">
                                    {!! $this->getQrCode(route('monox.production.worksheet', [
                                        'department' => $departmentId,
                                        'order' => $order->id,
                                        $processParam => $process->id,
                                    ]), config('monox.production.travel_sheet.qr_sizes.process_url', 45)) !!}
                                </div>
                            </td>
                            <td class="border border-black p-2 font-medium text-base">{{ $process->name }}</td>
                            <td class="border border-black p-2 text-center text-base font-bold">
                                {{ $record && $record->good_quantity ? number_format($record->good_quantity) : '' }}
                            </td>
                            <td class="border border-black p-2"></td>
                            <td class="border border-black p-2"></td>
                            <td class="border border-black p-2 text-xs"></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="border border-black p-4 text-center text-gray-500">工程が設定されていません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($order->note)
            <div class="border border-black p-3 mt-4">
                <div class="text-xs text-gray-500 uppercase leading-none mb-1">指図備考 / Note</div>
                <div class="text-sm whitespace-pre-wrap">{{ $order->note }}</div>
            </div>
        @endif

        <div class="mt-8 text-xs text-gray-400 text-right">
            MonoX System - Travel Sheet v1.0
        </div>
    </div>
</div>

<style>
    @media screen {
        .sheet-container {
            width: 210mm; /* A4 width */
            min-height: 297mm; /* A4 height */
            margin-top: 2rem;
            margin-bottom: 2rem;
        }
    }

    @media print {
        .no-print {
            display: none !important;
        }

        body {
            background-color: white !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .sheet-container {
            width: 100% !important;
            height: auto !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }

        @page {
            size: A4 portrait;
            margin: 10mm;
        }
    }
</style>
