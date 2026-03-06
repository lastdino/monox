<?php

namespace Lastdino\Monox\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Models\ProductionAnnotationField;
use Lastdino\Monox\Models\ProductionAnnotationValue;
use Lastdino\Monox\Models\ProductionOrder;
use Lastdino\Monox\Models\ProductionRecord;

class InspectionController extends Controller
{
    public function sync(Request $request)
    {
        $validated = $request->validate([
            'lot_number' => 'required|string',
            'process_name' => 'required|string',
            'inspections' => 'required|array',
            'inspections.*.sn' => 'required|string',
            'inspections.*.measurements' => 'required|array',
            'inspections.*.is_good' => 'nullable|boolean',
            'inspections.*.note' => 'nullable|string',
        ]);

        $department = $request->attributes->get('current_department');

        $lotQuery = Lot::query()->where('lot_number', $validated['lot_number']);
        if ($department) {
            $lotQuery->where('department_id', $department->id);
        }
        $lot = $lotQuery->first();

        if (! $lot) {
            $message = $department ? 'Lot not found in your department.' : 'Lot not found in Monox.';

            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], 404);
        }

        $process = Process::where('item_id', $lot->item_id)
            ->where('name', $validated['process_name'])
            ->first();

        if (! $process) {
            return response()->json([
                'status' => 'error',
                'message' => 'Process not found for this item.',
            ], 404);
        }

        // 対象工程のアノテーション項目を取得 (field_key をキーにしたマップ)
        $fields = ProductionAnnotationField::where('process_id', $process->id)
            ->get()
            ->keyBy('field_key');

        // 製造指図の取得
        $productionOrder = ProductionOrder::where('lot_id', $lot->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        if (! $productionOrder) {
            return response()->json([
                'status' => 'error',
                'message' => 'Production order not found for this lot.',
            ], 404);
        }

        // 製造実績の取得
        $productionRecord = ProductionRecord::where('production_order_id', $productionOrder->id)
            ->where('process_id', $process->id)
            ->first();

        // 既に完了している場合はエラー
        if ($productionRecord && $productionRecord->status === 'completed') {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot update data. The production record is already completed.',
            ], 422);
        }

        // 製造実績の作成（存在しない場合）
        if (! $productionRecord) {
            $productionRecord = ProductionRecord::create([
                'production_order_id' => $productionOrder->id,
                'process_id' => $process->id,
                'status' => 'in_progress',
                'work_started_at' => now(),
            ]);
        }

        DB::transaction(function () use ($validated, $fields, $productionRecord) {
            foreach ($validated['inspections'] as $inspectionData) {
                $sn = $inspectionData['sn'];
                $measurements = $inspectionData['measurements'];
                $isGood = $inspectionData['is_good'] ?? true;
                $note = $inspectionData['note'] ?? null;

                foreach ($measurements as $key => $value) {
                    if (! isset($fields[$key])) {
                        continue;
                    }

                    $field = $fields[$key];

                    // SNを識別子として既存データを検索（note に SN を保持する仕様）
                    // note の形式: "SN: [sn_value]" または単に [sn_value]
                    // ここではシンプルに note フィールドの先頭に SN を入れるか、
                    // あるいは将来的な拡張性を考えて JSON 形式などで保持することも検討できるが、
                    // 「noteにSNを保持」という要件に基づき、検索可能な形式にする。

                    $existingValue = ProductionAnnotationValue::where('production_record_id', $productionRecord->id)
                        ->where('field_id', $field->id)
                        ->where('note', 'like', "SN:{$sn}%")
                        ->first();

                    if ($existingValue) {
                        $existingValue->update([
                            'value' => $value,
                            'is_within_tolerance' => $this->checkTolerance($field, $value),
                            // note は更新しない（SN情報を維持）か、新しい note があれば追記
                            'note' => "SN:{$sn}".($note ? " | {$note}" : ''),
                        ]);
                    } else {
                        ProductionAnnotationValue::create([
                            'production_record_id' => $productionRecord->id,
                            'field_id' => $field->id,
                            'value' => $value,
                            'is_within_tolerance' => $this->checkTolerance($field, $value),
                            'note' => "SN:{$sn}".($note ? " | {$note}" : ''),
                        ]);
                    }
                }
            }

            // 実績の良品数・不良品数を更新（必要に応じて）
            // 全数検査の場合、SNごとの is_good を集計して反映することも考えられる
            $this->updateRecordQuantities($productionRecord);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Inspection data synced successfully',
            'production_record_id' => $productionRecord->id,
        ]);
    }

    private function checkTolerance(ProductionAnnotationField $field, $value): bool
    {
        if ($field->type === 'pass_fail') {
            return $value === '合格' || $value === true || $value === 1 || $value === 'true';
        }

        if (! is_numeric($value)) {
            return true;
        }

        if ($field->min_value !== null && $value < $field->min_value) {
            return false;
        }

        if ($field->max_value !== null && $value > $field->max_value) {
            return false;
        }

        return true;
    }

    private function updateRecordQuantities(ProductionRecord $record): void
    {
        // $record->status を completed に変更する処理はここには記述しないでください
        // ステータスは手動で変更される運用です
    }
}
