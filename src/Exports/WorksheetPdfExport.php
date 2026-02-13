<?php

namespace Lastdino\Monox\Exports;

use LastDino\ChromeLaravel\Facades\Chrome;
use Lastdino\Monox\Models\ProductionOrder;

class WorksheetPdfExport
{
    public function export(ProductionOrder $order)
    {
        $order->load(['item.processes.annotationFields', 'lot', 'productionRecords.annotationValues']);

        $pages = [];

        foreach ($order->item->processes as $process) {
            $record = $order->getRecordForProcess($process->id);
            if (! $record) {
                continue;
            }

            $templateMedia = $process->getTemplateMediaAttribute();
            if (! $templateMedia || ! file_exists($templateMedia->getPath())) {
                continue;
            }

            $annotations = [];
            $annotationValues = $record->annotationValues()->with('field')->get();

            foreach ($process->annotationFields as $field) {
                $value = $annotationValues->where('field_id', $field->id)->first();
                if (! $value) {
                    continue;
                }

                $annotation = [
                    'type' => $field->type,
                    'x' => $field->x_percent,
                    'y' => $field->y_percent,
                    'width' => $field->width_percent,
                    'height' => $field->height_percent,
                    'value' => $value->value,
                ];

                if ($field->type === 'photo') {
                    $photoMedia = $value->getFirstMedia('photo');
                    if ($photoMedia && file_exists($photoMedia->getPath())) {
                        $photoData = base64_encode(file_get_contents($photoMedia->getPath()));
                        $annotation['photo_base64'] = 'data:'.$photoMedia->mime_type.';base64,'.$photoData;
                    }
                }

                $annotations[] = $annotation;
            }

            $templateData = base64_encode(file_get_contents($templateMedia->getPath()));
            $pages[] = [
                'process' => $process,
                'template_base64' => 'data:'.$templateMedia->mime_type.';base64,'.$templateData,
                'annotations' => $annotations,
            ];
        }

        // Chrome を使用して PDF を生成
        $html = view('monox::pdf.worksheet', [
            'order' => $order,
            'pages' => $pages,
        ])->render();

        $tmpPdfPath = Chrome::pdfFromHtml($html, [
            'printBackground' => true,
            'displayHeaderFooter' => false,
            'paperWidth' => 8.27,   // A4 inches
            'paperHeight' => 11.69, // A4 inches
            'marginTop' => 0,
            'marginBottom' => 0,
            'marginLeft' => 0,
            'marginRight' => 0,
        ]);

        $pdfContent = file_get_contents($tmpPdfPath);

        // 一時PDFファイルを削除
        if (file_exists($tmpPdfPath)) {
            @unlink($tmpPdfPath);
        }

        $fileName = "worksheet_{$order->item->code}_{$order->lot->lot_number}.pdf";

        return [
            'content' => $pdfContent,
            'fileName' => $fileName,
        ];
    }
}
