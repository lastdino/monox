<?php

namespace Lastdino\Monox\Services;

use Lastdino\Monox\Models\ProductionAnnotationField;
use Lastdino\Monox\Models\ProductionAnnotationValue;

class DistributionService
{
    /**
     * Build histogram data for a specific production record and annotation field.
     */
    public static function buildDistributionData(int $productionRecordId, int $fieldId, ?int $bins = null, ?float $min = null, ?float $max = null): array
    {
        $field = ProductionAnnotationField::findOrFail($fieldId);
        $values = ProductionAnnotationValue::where('production_record_id', $productionRecordId)
            ->where('field_id', $fieldId)
            ->pluck('value')
            ->map(fn ($v) => (float) $v)
            ->filter()
            ->values();

        if ($values->isEmpty()) {
            return [
                'labels' => [],
                'datasets' => [],
                'stats' => [],
            ];
        }

        $dataMin = $values->min();
        $dataMax = $values->max();
        $count = $values->count();
        $avg = $values->avg();

        // Use Sturges' formula if bins is not provided: k = 1 + log2(n)
        if ($bins === null) {
            $bins = (int) ceil(1 + log($count, 2));
        }

        // Default min/max: Use field spec limits if available, otherwise data limits
        $min = $min ?? $field->min_value ?? $dataMin;
        $max = $max ?? $field->max_value ?? $dataMax;

        $stats = [
            'count' => $count,
            'min' => round($dataMin, 4),
            'max' => round($dataMax, 4),
            'avg' => round($avg, 4),
            'stdDev' => null,
            'cp' => null,
            'cpk' => null,
        ];

        // Standard Deviation
        if ($count >= 2) {
            $variance = $values->map(fn ($x) => ($x - $avg) ** 2)->sum() / ($count - 1);
            $stdDev = sqrt($variance);
            $stats['stdDev'] = round($stdDev, 4);

            if ($stdDev > 0) {
                $lsl = $field->min_value;
                $usl = $field->max_value;

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

        // Create histogram bins
        if ($min === $max) {
            $labels = [round($min, 4)];
            $data = [$count];
        } else {
            $range = $max - $min;
            $binWidth = $range / $bins;
            $data = array_fill(0, $bins, 0);
            $labels = [];

            for ($i = 0; $i < $bins; $i++) {
                $binStart = $min + ($i * $binWidth);
                $binEnd = $binStart + $binWidth;
                $labels[] = round($binStart + ($binWidth / 2), 4);

                foreach ($values as $val) {
                    if ($i === $bins - 1) {
                        if ($val >= $binStart && $val <= $max) {
                            $data[$i]++;
                        }
                    } else {
                        if ($val >= $binStart && $val < $binEnd) {
                            $data[$i]++;
                        }
                    }
                }
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => '頻度',
                    'data' => $data,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.5)',
                    'borderColor' => 'rgb(59, 130, 246)',
                    'borderWidth' => 1,
                ],
            ],
            'stats' => $stats,
            'field' => [
                'label' => $field->label,
                'min' => $field->min_value,
                'max' => $field->max_value,
                'target' => $field->target_value,
            ],
            'range' => [
                'min' => $min,
                'max' => $max,
                'bins' => $bins,
            ],
        ];
    }
}
