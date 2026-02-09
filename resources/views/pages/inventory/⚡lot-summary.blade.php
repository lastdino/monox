<?php

use Flux\Flux;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Exports\LotInventoryExport;
use Livewire\Component;
use Livewire\WithPagination;
use Carbon\Carbon;

new class extends Component
{
    use WithPagination;

    public int $departmentId;
    public string $targetDate;
    public string $search = '';

    public function mount(Department $department): void
    {
        $this->departmentId = $department->id;
        $this->targetDate = now()->toDateString();
    }

    public function downloadExcel()
    {
        $exporter = new LotInventoryExport();
        $callback = $exporter->export($this->departmentId, $this->targetDate);

        $fileName = "inventory_report_".str_replace('-', '', $this->targetDate).".xlsx";

        return response()->streamDownload($callback, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function getProcessesProperty()
    {
        return Process::whereHas('item', fn ($q) => $q->where('department_id', $this->departmentId))
            ->orderBy('sort_order')
            ->get()
            ->unique('name')
            ->values();
    }

    public function getRowsProperty()
    {
        $date = Carbon::parse($this->targetDate)->endOfDay();
        $processes = $this->processes;

        return Lot::with(['item.processes', 'stockMovements', 'productionOrders.productionRecords.process'])
            ->where('department_id', $this->departmentId)
            ->when($this->search, function ($q) {
                $q->where(function ($q) {
                    $q->where('lot_number', 'like', '%'.$this->search.'%')
                      ->orWhereHas('item', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'));
                });
            })
            ->get()
            ->map(function ($lot) use ($date, $processes) {
                $stock = $lot->getStockAtDate($date);
                $wipData = $this->calculateWipAtDate($lot, $date, $processes);
                $total = $stock + array_sum($wipData);

                if ($total == 0 && empty($this->search)) return null;

                return [
                    'item_name' => $lot->item->name,
                    'lot_number' => $lot->lot_number,
                    'stock' => $stock,
                    'wip' => $wipData,
                    'total' => $total,
                ];
            })
            ->filter()
            ->values();
    }

    private function calculateWipAtDate($lot, $date, $processes): array
    {
        $wip = [];
        foreach ($processes as $p) {
            $wip[$p->name] = 0;
        }

        $orders = $lot->productionOrders()
            ->where('created_at', '<=', $date)
            ->where('status', '!=', 'cancelled')
            ->get();

        foreach ($orders as $order) {
            $records = $order->productionRecords()
                ->where('work_finished_at', '<=', $date)
                ->with('process')
                ->get();

            $allProcesses = $order->item->processes()->orderBy('sort_order')->get();
            $lastProcess = $allProcesses->last();
            $finishedQty = 0;
            if ($lastProcess) {
                $finishedQty = $records->where('process_id', $lastProcess->id)->sum('good_quantity');
            }

            $totalDefectiveQty = $records->sum('defective_quantity');

            $orderWipQty = $order->target_quantity - $finishedQty - $totalDefectiveQty;
            if ($orderWipQty <= 0) {
                continue;
            }

            $lastCompletedRecord = $records->sortByDesc(fn ($r) => $r->process->sort_order)->first();

            $currentProcess = null;
            if ($lastCompletedRecord) {
                // 最後に完了した工程よりも後の工程を探す
                $currentProcess = $allProcesses
                    ->where('sort_order', '>', $lastCompletedRecord->process->sort_order)
                    ->sortBy('sort_order')
                    ->first();

                // もし次の工程が見つからないが、まだ最終良品が出ていない場合は、最後に完了した工程に滞留しているとみなす（異常系回避）
                if (! $currentProcess) {
                    $currentProcess = $lastCompletedRecord->process;
                }
            } else {
                $currentProcess = $allProcesses->sortBy('sort_order')->first();
            }

            if ($currentProcess) {
                if (! isset($wip[$currentProcess->name])) {
                    $wip[$currentProcess->name] = 0;
                }
                $wip[$currentProcess->name] += $orderWipQty;
            }
        }

        return $wip;
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">ロット別在庫・仕掛一覧</flux:heading>

        <div class="flex flex-col sm:flex-row items-end gap-4">
            <div><flux:input type="date" wire:model.live="targetDate" label="基準日" /></div>

            <flux:input wire:model.live.debounce.300ms="search" placeholder="品目・ロット検索..." icon="magnifying-glass" />
            <div class="flex-none">
                <flux:button wire:click="downloadExcel" icon="document-text">エクセル出力</flux:button>
            </div>
        </div>
    </div>

    <flux:card class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>品目名</flux:table.column>
                <flux:table.column>ロット番号</flux:table.column>
                <flux:table.column align="end">在庫数</flux:table.column>
                @foreach ($this->processes as $process)
                    <flux:table.column align="end">{{ $process->name }} (仕掛)</flux:table.column>
                @endforeach
                <flux:table.column align="end">合計</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->rows as $row)
                    <flux:table.row>
                        <flux:table.cell class="whitespace-nowrap">{{ $row['item_name'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row['lot_number'] }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($row['stock'], 2) }}</flux:table.cell>
                        @foreach ($this->processes as $process)
                            <flux:table.cell align="end">
                                <span class="{{ ($row['wip'][$process->name] ?? 0) > 0 ? 'font-bold text-blue-600' : 'text-gray-400' }}">
                                    {{ number_format($row['wip'][$process->name] ?? 0, 2) }}
                                </span>
                            </flux:table.cell>
                        @endforeach
                        <flux:table.cell align="end" class="font-bold">{{ number_format($row['total'], 2) }}</flux:cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
