<?php

namespace Lastdino\Monox\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\Process;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LotInventoryExport
{
    public function export(int $departmentId, ?string $targetDate = null)
    {
        $date = $targetDate ? Carbon::parse($targetDate)->endOfDay() : now();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // 工程名を動的に取得（部門に紐づく品目の全工程を網羅するのは難しいため、全工程マスターから取得するか、今回の対象ロットに関連するものに絞る）
        // ここではシンプルに、全ての工程名を取得してヘッダーに並べる
        $processes = Process::whereHas('item', fn($q) => $q->where('department_id', $departmentId))
            ->orderBy('sort_order')
            ->get()
            ->unique('name')
            ->values();

        // ヘッダーの設定
        $headers = ['品目名', 'ロット番号', '在庫数'];
        foreach ($processes as $process) {
            $headers[] = $process->name . ' (仕掛)';
        }
        $headers[] = '合計';

        $sheet->fromArray($headers, NULL, 'A1');

        // データの取得
        $lots = Lot::with(['item', 'stockMovements', 'productionOrders.productionRecords.process'])
            ->where('department_id', $departmentId)
            ->get();

        $row = 2;
        foreach ($lots as $lot) {
            $stock = $lot->getStockAtDate($date);
            $wipData = $this->calculateWipAtDate($lot, $date, $processes);

            $wipTotal = array_sum($wipData);
            $total = $stock + $wipTotal;

            // 在庫も仕掛も0の場合はスキップ（必要に応じて調整）
            if ($total == 0) continue;

            $rowData = [
                $lot->item->name,
                $lot->lot_number,
                $stock,
            ];

            foreach ($processes as $process) {
                $rowData[] = $wipData[$process->name] ?? 0;
            }
            $rowData[] = $total;

            $sheet->fromArray($rowData, NULL, 'A' . $row);
            $row++;
        }

        // 列幅の自動調整
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        $fileName = "inventory_report_{$date->format('Ymd')}.xlsx";

        // Livewireから呼び出されることを想定し、一時ファイルに保存してパスを返すか、
        // あるいは直接ストリーム出力する。ここではストリーム出力を想定するが、
        // Livewireの `download()` を使う場合は `php://output` への書き込みが必要。

        return function() use ($writer) {
            $writer->save('php://output');
        };
    }

    private function calculateWipAtDate(Lot $lot, Carbon $date, Collection $processes): array
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
}
