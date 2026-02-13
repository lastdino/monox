<?php

namespace Lastdino\Monox\Exports;

use Lastdino\Monox\Models\ProductionOrder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WorksheetExport
{
    public function export(ProductionOrder $order)
    {
        $order->load(['item.processes.annotationFields', 'lot', 'parent.lot']);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0); // Remove default sheet

        foreach ($order->item->processes as $process) {
            $sheet = $spreadsheet->createSheet();
            $title = mb_substr($process->name, 0, 31); // Excel sheet name limit
            $sheet->setTitle($title);

            $this->fillProcessSheet($sheet, $order, $process);
        }

        if ($spreadsheet->getSheetCount() === 0) {
            $spreadsheet->createSheet()->setTitle('No Processes');
        }

        $writer = new Xlsx($spreadsheet);
        $fileName = "worksheet_{$order->item->code}_{$order->lot->lot_number}.xlsx";

        return [
            'writer' => $writer,
            'fileName' => $fileName,
        ];
    }

    private function fillProcessSheet($sheet, ProductionOrder $order, $process)
    {
        // Header Info
        $sheet->setCellValue('A1', '品目コード');
        $sheet->setCellValue('B1', $order->item->code);
        $sheet->setCellValue('A2', '品目名');
        $sheet->setCellValue('B2', $order->item->name);
        $sheet->setCellValue('A3', 'ロット番号');
        $sheet->setCellValue('B3', $order->lot->lot_number);

        if ($order->parent_order_id) {
            $sheet->setCellValue('A4', '親ロット番号');
            $sheet->setCellValue('B4', $order->parent->lot->lot_number);
            $sheet->setCellValue('A5', '工程名');
            $sheet->setCellValue('B5', $process->name);
            $headerOffset = 1;
        } else {
            $sheet->setCellValue('A4', '工程名');
            $sheet->setCellValue('B4', $process->name);
            $headerOffset = 0;
        }

        // Record Data
        $record = $order->getRecordForProcess($process->id);
        $isInherited = $record && $record->production_order_id !== $order->id;

        $baseRow = 6 + $headerOffset;
        $sheet->setCellValue('A'.$baseRow, '作業者');
        $sheet->setCellValue('B'.$baseRow, 'ステータス');
        $sheet->setCellValue('C'.$baseRow, '投入数量');
        $sheet->setCellValue('D'.$baseRow, '良品数量');
        $sheet->setCellValue('E'.$baseRow, '不良数量');
        $sheet->setCellValue('F'.$baseRow, '段取開始');
        $sheet->setCellValue('G'.$baseRow, '段取終了');
        $sheet->setCellValue('H'.$baseRow, '作業開始');
        $sheet->setCellValue('I'.$baseRow, '作業終了');
        if ($isInherited) {
            $sheet->setCellValue('J'.$baseRow, '備考');
        }

        if ($record) {
            $dataRow = $baseRow + 1;
            $sheet->setCellValue('A'.$dataRow, $record->worker?->name);
            $sheet->setCellValue('B'.$dataRow, $record->status);
            $sheet->setCellValue('C'.$dataRow, $record->input_quantity);
            $sheet->setCellValue('D'.$dataRow, $record->good_quantity);
            $sheet->setCellValue('E'.$dataRow, $record->defective_quantity);
            $sheet->setCellValue('F'.$dataRow, $record->setup_started_at?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('G'.$dataRow, $record->setup_finished_at?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('H'.$dataRow, $record->work_started_at?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('I'.$dataRow, $record->work_finished_at?->format('Y-m-d H:i:s'));
            if ($isInherited) {
                $sheet->setCellValue('J'.$dataRow, '親ロットから継承');
            }
        }

        // Annotation Values
        $annotHeaderRow = $baseRow + 3;
        $sheet->setCellValue('A'.$annotHeaderRow, '項目名');
        $sheet->setCellValue('B'.$annotHeaderRow, '値');
        $sheet->setCellValue('C'.$annotHeaderRow, '備考');
        $sheet->setCellValue('D'.$annotHeaderRow, '判定');

        $row = $annotHeaderRow + 1;
        $fields = $process->annotationFields->sortBy('id');

        foreach ($fields as $field) {
            $valueModel = $record ? $record->annotationValues()->where('field_id', $field->id)->first() : null;

            $sheet->setCellValue('A'.$row, $field->label);
            $sheet->setCellValue('B'.$row, $valueModel?->value);
            $sheet->setCellValue('C'.$row, ($valueModel?->note ?? '').($isInherited && $valueModel ? ' (親から継承)' : ''));

            if ($valueModel && $valueModel->is_within_tolerance !== null) {
                $sheet->setCellValue('D'.$row, $valueModel->is_within_tolerance ? 'OK' : 'NG');
            }

            $row++;
        }

        // Auto size columns
        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
