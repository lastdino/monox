<?php

namespace Lastdino\Monox\Services;

use Illuminate\Support\Arr;
use Lastdino\Monox\Models\ProductionAnnotationField;
use Lastdino\Monox\Models\ProductionAnnotationValue;

class TrendService
{
    /**
     * Build trend chart data for given annotation field IDs and optional process filter.
     * Supports numeric fields and material usage fields (material, material_quantity).
     */
    public static function buildTrendData(array $fieldIds, ?int $processId, string $calcMode, int $limit): array
    {
        if (empty($fieldIds)) {
            return [];
        }

        $query = ProductionAnnotationValue::whereIn('field_id', $fieldIds)
            ->with(['productionRecord.productionOrder.lot', 'field'])
            ->whereHas('productionRecord', function ($q) use ($processId) {
                $q->whereNotNull('work_finished_at');
                if ($processId) {
                    $q->where('process_id', $processId);
                }
            });

        $values = $query->get()
            ->groupBy('production_record_id')
            ->map(function ($group) use ($calcMode) {
                $first = $group->first();
                $record = $first->productionRecord;

                $sum = $group->sum(function ($v) {
                    $type = $v->field?->type;
                    if (in_array($type, ['material', 'material_quantity'], true)) {
                        return (float) ($v->quantity ?? 0);
                    }
                    return (float) ($v->value ?? 0);
                });
                $val = $calcMode === 'sum' ? $sum : ($group->count() > 0 ? $sum / $group->count() : 0);

                return [
                    'record_id' => $record->id,
                    'finished_at' => $record->work_finished_at,
                    'lot_number' => $record->productionOrder->lot?->lot_number,
                    'value' => $val,
                ];
            })
            ->sortByDesc('finished_at')
            ->take($limit)
            ->sortBy('finished_at')
            ->values();

        $labels = $values->map(fn ($v) => $v['lot_number'] ?? $v['finished_at']->format(config('monox.datetime.formats.short_datetime', 'm/d H:i')))->toArray();
        $data = $values->map(fn ($v) => (float) $v['value'])->toArray();

        $firstFieldId = (int) Arr::first($fieldIds);
        $field = ProductionAnnotationField::find($firstFieldId);

        $stats = [
            'avg' => null,
            'cp' => null,
            'cpk' => null,
            'stdDev' => null,
        ];

        $ucl = null;
        $lcl = null;

        if (count($data) >= 2) {
            $n = count($data);
            $avg = array_sum($data) / $n;
            $stats['avg'] = round($avg, 3);

            $variance = array_sum(array_map(fn ($x) => ($x - $avg) ** 2, $data)) / ($n - 1);
            $stdDev = sqrt($variance);
            $stats['stdDev'] = round($stdDev, 3);

            if ($stdDev > 0) {
                $lsl = $field?->min_value;
                $usl = $field?->max_value;

                if ($lsl !== null && $usl !== null) {
                    $stats['cp'] = round(($usl - $lsl) / (6 * $stdDev), 2);
                }

                if ($lsl !== null || $usl !== null) {
                    $cpkUpper = $usl !== null ? ($usl - $avg) / (3 * $stdDev) : INF;
                    $cpkLower = $lsl !== null ? ($avg - $lsl) / (3 * $stdDev) : INF;
                    $stats['cpk'] = round(min($cpkUpper, $cpkLower), 2);
                }
            }
        }

        $label = count($fieldIds) > 1
            ? ($calcMode === 'avg' ? '複数項目の平均' : '複数項目の合計')
            : ($field->label ?? '値');

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $label,
                    'data' => $data,
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.1)',
                    'fill' => true,
                    'tension' => 0.1,
                ],
            ],
            'thresholds' => [
                'min' => $field?->min_value,
                'max' => $field?->max_value,
                'target' => $field?->target_value,
                'ucl' => $ucl,
                'lcl' => $lcl,
            ],
            'stats' => $stats,
        ];
    }
}
