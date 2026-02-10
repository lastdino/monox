<?php

namespace Lastdino\Monox\Exports;

use Lastdino\Monox\Models\ProductionOrder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WorksheetExport
{
    public function export(ProductionOrder $order)
    {
        $order->load(['item.processes.annotationFields', 'productionRecords.annotationValues.field', 'lot']);

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
        $sheet->setCellValue('A4', '工程名');
        $sheet->setCellValue('B4', $process->name);

        // Record Data
        $record = $order->productionRecords->where('process_id', $process->id)->first();

        $sheet->setCellValue('A6', '作業者');
        $sheet->setCellValue('B6', 'ステータス');
        $sheet->setCellValue('C6', '投入数量');
        $sheet->setCellValue('D6', '良品数量');
        $sheet->setCellValue('E6', '不良数量');
        $sheet->setCellValue('F6', '段取開始');
        $sheet->setCellValue('G6', '段取終了');
        $sheet->setCellValue('H6', '作業開始');
        $sheet->setCellValue('I6', '作業終了');

        if ($record) {
            $sheet->setCellValue('A7', $record->worker?->name);
            $sheet->setCellValue('B7', $record->status);
            $sheet->setCellValue('C7', $record->input_quantity);
            $sheet->setCellValue('D7', $record->good_quantity);
            $sheet->setCellValue('E7', $record->defective_quantity);
            $sheet->setCellValue('F7', $record->setup_started_at?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('G7', $record->setup_finished_at?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('H7', $record->work_started_at?->format('Y-m-d H:i:s'));
            $sheet->setCellValue('I7', $record->work_finished_at?->format('Y-m-d H:i:s'));
        }

        // Annotation Values
        $sheet->setCellValue('A9', '項目名');
        $sheet->setCellValue('B9', '値');
        $sheet->setCellValue('C9', '備考');
        $sheet->setCellValue('D9', '判定');

        $row = 10;
        $fields = $process->annotationFields->sortBy('id');

        foreach ($fields as $field) {
            $valueModel = $record ? $record->annotationValues->where('field_id', $field->id)->first() : null;

            $sheet->setCellValue('A'.$row, $field->label);
            $sheet->setCellValue('B'.$row, $valueModel?->value);
            $sheet->setCellValue('C'.$row, $valueModel?->note);

            if ($valueModel && $valueModel->is_within_tolerance !== null) {
                $sheet->setCellValue('D'.$row, $valueModel->is_within_tolerance ? 'OK' : 'NG');
            }

            $row++;
        }

        // Auto size columns
        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
