<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Lastdino\Monox\Exports\WorksheetExport;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Models\ProductionAnnotationField;
use Lastdino\Monox\Models\ProductionAnnotationValue;
use Lastdino\Monox\Models\ProductionOrder;
use Lastdino\Monox\Models\ProductionRecord;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public ProductionOrder $order;

    public $currentProcessId;

    public function getUrlProcessParameterName(): string
    {
        return config('monox.production.worksheet_process_parameter', 'process');
    }

    public function queryString(): array
    {
        return [
            'currentProcessId' => ['as' => $this->getUrlProcessParameterName()],
        ];
    }

    public $records = [];

    // Worker ID scan
    public $worker_code = '';

    public $currentWorker = null;

    // Annotation input
    public ?int $activeFieldId = null;

    public $fieldValue = '';

    public $fieldNote = '';

    public $selectedLotId = null;

    public $consumedQuantity = 0;

    // Inventory update
    public $shouldUpdateInventory = false;

    public $producedQuantity = 0;

    public $input_quantity = 0;

    public $good_quantity = 0;

    public $defective_quantity = 0;

    public $departmentId;

    public function mount($order): void
    {
        if ($order instanceof ProductionOrder) {
            $this->order = $order;
        } else {
            $this->order = ProductionOrder::findOrFail($order);
        }

        $this->departmentId = request()->route('department_id') instanceof \Illuminate\Database\Eloquent\Model
            ? request()->route('department_id')->getKey()
            : (int) request()->route('department_id');

        $this->order->load(['item.processes', 'productionRecords.annotationValues', 'lot']);
        $this->records = $this->order->productionRecords->keyBy('process_id');

        if ($this->currentProcessId && $this->order->item->processes->contains('id', $this->currentProcessId)) {
            // Already set by #[Url]
        } elseif ($this->order->item->processes->isNotEmpty()) {
            $this->currentProcessId = $this->order->item->processes->first()->id;
        }

        $this->currentWorker = Auth::user();
        $this->producedQuantity = $this->order->target_quantity;
        $this->input_quantity = $this->order->target_quantity;
        $this->good_quantity = $this->order->target_quantity;
        $this->defective_quantity = 0;
        $this->shouldUpdateInventory = $this->order->item->auto_inventory_update;
    }

    public function selectProcess(int $processId): void
    {
        $this->currentProcessId = $processId;
    }

    public function getProcessProperty()
    {
        return Process::find($this->currentProcessId);
    }

    public function getPreviousProcessProperty()
    {
        if (! $this->process) {
            return null;
        }

        return $this->order->item->processes
            ->where('sort_order', '<', $this->process->sort_order)
            ->last();
    }

    public function getSharedProcessesChainProperty()
    {
        if (! $this->process) {
            return collect();
        }

        $chain = collect();
        $curr = $this->process;

        // 現在の工程が共有設定なら、遡ってグループを作る
        while ($curr && $curr->share_template_with_previous) {
            $prev = $this->order->item->processes
                ->where('sort_order', '<', $curr->sort_order)
                ->last();

            if (! $prev) {
                break;
            }

            $chain->prepend($prev);
            $curr = $prev;
        }

        return $chain;
    }

    public function getEffectiveTemplateMediaProperty()
    {
        if (! $this->process) {
            return null;
        }

        if ($this->process->share_template_with_previous) {
            $chain = $this->sharedProcessesChain;
            $root = $chain->first();

            return $root ? $root->template_media : null;
        }

        return $this->process->template_media;
    }

    public function getIsFinalProcessProperty(): bool
    {
        $lastProcess = $this->order->item->processes->last();

        return $lastProcess && $lastProcess->id === $this->currentProcessId;
    }

    public function getRecordProperty()
    {
        return $this->records[$this->currentProcessId] ?? null;
    }

    public function exportExcel(WorksheetExport $export)
    {
        if (! auth()->user()->can('production.download.'.$this->departmentId)) {
            Flux::toast('データをダウンロードする権限がありません。', variant: 'danger');

            return null;
        }
        $result = $export->export($this->order);
        $writer = $result['writer'];
        $fileName = $result['fileName'];

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName);
    }

    public function stamp(string $type): void
    {
        if (! $this->currentWorker) {
            Flux::toast('作業者を特定してください。', variant: 'danger');

            return;
        }

        $record = $this->record;

        if (! $record) {
            $record = ProductionRecord::create([
                'production_order_id' => $this->order->id,
                'process_id' => $this->currentProcessId,
                'worker_id' => $this->currentWorker->id,
                'status' => 'in_progress',
            ]);
            $this->records[$this->currentProcessId] = $record;

            if ($this->order->status === 'pending') {
                $this->order->update(['status' => 'in_progress']);
            }
        }

        $now = now();
        switch ($type) {
            case 'setup_start':
                $record->update(['setup_started_at' => $now]);
                break;
            case 'setup_end':
                $record->update(['setup_finished_at' => $now]);
                break;
            case 'work_start':
                $record->update(['work_started_at' => $now]);
                break;
            case 'work_end':
                // 必須アノテーション項目のみを取得
                $requiredFields = $this->process->annotationFields->where('is_optional', false);
                $requiredFieldIds = $requiredFields->pluck('id');

                // 入力済みの必須項目の数をカウント
                $filledRequiredValuesCount = $record->annotationValues()
                    ->whereIn('field_id', $requiredFieldIds)
                    ->whereNotNull('value')
                    ->where('value', '!=', '')
                    ->count();

                if ($filledRequiredValuesCount < $requiredFields->count()) {
                    Flux::toast('未入力の必須アノテーション項目があります。全ての必須項目を記録してください。', variant: 'danger');

                    return;
                }

                // 投入数、良品数、不良数がアノテーションとして存在しない場合、モーダルを表示して入力させる
                $fields = $this->process->annotationFields;
                $hasInputQty = $fields->where('type', 'input_quantity')->isNotEmpty();
                $hasGoodQty = $fields->where('type', 'good_quantity')->isNotEmpty();
                $hasDefectiveQty = $fields->where('type', 'defective_quantity')->isNotEmpty();

                // 常にレコードの現在値をコンポーネントのプロパティに同期する
                $this->input_quantity = $record->input_quantity ?? $this->input_quantity;
                $this->good_quantity = $record->good_quantity ?? $this->good_quantity;
                $this->defective_quantity = $record->defective_quantity ?? $this->defective_quantity;

                if (! $hasInputQty || ! $hasGoodQty) {
                    Flux::modal('record-quantities-modal')->show();

                    return;
                }

                if ($this->isFinalProcess && $this->order->item->auto_inventory_update) {
                    Flux::modal('complete-final-process-modal')->show();

                    return;
                }
                $this->completeWork();
                break;
            case 'pause':
                $record->update([
                    'paused_at' => $now,
                    'status' => 'paused',
                ]);
                break;
            case 'resume':
                $pausedSeconds = $record->paused_at ? $now->diffInSeconds($record->paused_at) : 0;
                $record->update([
                    'paused_at' => null,
                    'total_paused_seconds' => $record->total_paused_seconds + $pausedSeconds,
                    'status' => 'in_progress',
                ]);
                break;
            case 'stop':
                $record->update(['status' => 'stopped']);

                // もし全ての工程が完了または中止なら、指図自体も完了とする
                $allFinished = $this->order->item->processes->every(function ($p) {
                    $r = $this->records[$p->id] ?? null;

                    return $r && in_array($r->status, ['completed', 'stopped']);
                });

                if ($allFinished) {
                    $this->order->update(['status' => 'completed']);
                }
                break;
        }

        Flux::toast('打刻しました。');
    }

    public function submitQuantities(): void
    {
        $this->validate([
            'input_quantity' => ['required', 'numeric', 'min:0'],
            'good_quantity' => ['required', 'numeric', 'min:0'],
            'defective_quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $this->record->update([
            'input_quantity' => $this->input_quantity,
            'good_quantity' => $this->good_quantity,
            'defective_quantity' => $this->defective_quantity,
        ]);

        Flux::modal('record-quantities-modal')->close();

        if ($this->isFinalProcess && $this->order->item->auto_inventory_update) {
            $this->producedQuantity = $this->good_quantity;
            Flux::modal('complete-final-process-modal')->show();

            return;
        }

        $this->completeWork();
    }

    public function completeWork(): void
    {
        if (! $this->currentWorker) {
            Flux::toast('作業者を特定してください。', variant: 'danger');

            return;
        }

        $this->validate([
            'input_quantity' => ['required', 'numeric', 'min:0'],
            'good_quantity' => ['required', 'numeric', 'min:0'],
            'defective_quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $record = $this->record;
        $now = now();

        // 実績数を更新
        $record->update([
            'input_quantity' => $this->input_quantity,
            'good_quantity' => $this->good_quantity,
            'defective_quantity' => $this->defective_quantity,
        ]);

        $record->update(['work_finished_at' => $now, 'status' => 'completed']);

        if ($this->isFinalProcess && $this->shouldUpdateInventory) {
            \Lastdino\Monox\Models\StockMovement::create([
                'item_id' => $this->order->item_id,
                'lot_id' => $this->order->lot_id,
                'quantity' => $this->good_quantity,
                'type' => 'in',
                'reason' => '製造完了による入庫 (指図 ID: '.$this->order->id.')',
                'moved_at' => $now,
                'department_id' => $this->order->department_id,
            ]);
            Flux::toast('在庫を更新しました。');
        }

        // もし全ての工程が完了または中止なら、指図自体も完了とする
        $allFinished = $this->order->item->processes->every(function ($p) {
            $r = $this->records[$p->id] ?? null;

            return $r && in_array($r->status, ['completed', 'stopped']);
        });

        if ($allFinished) {
            $this->order->update(['status' => 'completed']);
        }

        Flux::modal('complete-final-process-modal')->close();
        Flux::toast('作業を完了しました。');
    }

    public function openAnnotation(int $fieldId): void
    {
        if (! $this->record) {
            Flux::toast('先に作業を開始してください。', variant: 'danger');

            return;
        }

        if (in_array($this->record->status, ['completed', 'stopped'])) {
            Flux::toast('完了または中止された工程の記録は変更できません。', variant: 'danger');

            return;
        }

        if ($this->record->status === 'paused') {
            Flux::toast('一時停止中は記録できません。再開してください。', variant: 'danger');

            return;
        }

        $field = ProductionAnnotationField::find($fieldId);
        $this->activeFieldId = $fieldId;

        $valueModel = ProductionAnnotationValue::where('production_record_id', $this->record->id)
            ->where('field_id', $fieldId)
            ->first();

        $this->fieldValue = $valueModel->value ?? '';
        $this->fieldNote = $valueModel->note ?? '';
        $this->selectedLotId = $valueModel->lot_id ?? null;
        $this->consumedQuantity = $valueModel->quantity ?? 0;

        // 特殊な型の初期値設定
        if ($field->type === 'timestamp' && empty($this->fieldValue)) {
            $this->fieldValue = now()->format(config('monox.datetime.formats.short_datetime', 'Y-m-d H:i'));
        }

        if ($field->type === 'signature' && empty($this->fieldValue) && $this->currentWorker) {
            $this->fieldValue = $this->currentWorker->{config('monox.display.worker_column', 'name')};
        }

        if ($field->type === 'material' && $this->consumedQuantity == 0 && empty($this->fieldValue)) {
            // 予定数があればセットするなどの工夫も可能だが、まずは空で
        }

        if ($field->type === 'material_lot' && $this->selectedLotId) {
            // 既存のロットがあればセット（基本的には fieldValue にロット番号が入っているはず）
        }

        if ($field->type === 'material_quantity' && $this->consumedQuantity == 0) {
            // 既存の数量があればセット
        }

        if ($field->type === 'photo' && ! empty($this->fieldValue)) {
            // 撮影済みの画像URLが入っているはず
        }

        Flux::modal('annotation-modal')->show();
    }

    public function setPhoto(string $data): void
    {
        $this->fieldValue = $data;
    }

    public function saveAnnotation(?string $capturedPhoto = null): void
    {
        if ($capturedPhoto) {
            $this->fieldValue = $capturedPhoto;
        }

        $field = ProductionAnnotationField::find($this->activeFieldId);

        $isWithinTolerance = true;
        if (in_array($field->type, ['number', 'input_quantity', 'good_quantity', 'defective_quantity']) && is_numeric($this->fieldValue)) {
            $val = (float) $this->fieldValue;
            if (($field->min_value !== null && $val < $field->min_value) ||
                ($field->max_value !== null && $val > $field->max_value)) {
                $isWithinTolerance = false;
            }
        }

        if ($field->type === 'material') {
            $this->validate([
                'selectedLotId' => ['required', 'exists:monox_lots,id'],
                'consumedQuantity' => ['required', 'numeric', 'min:0.0001'],
            ]);

            $lot = \Lastdino\Monox\Models\Lot::find($this->selectedLotId);

            // 在庫チェック
            if ($this->consumedQuantity > $lot->current_stock) {
                $this->addError('consumedQuantity', '在庫数（'.number_format($lot->current_stock, 2).'）を超える数量は指定できません。');

                return;
            }

            $this->fieldValue = $lot->lot_number.' ('.$this->consumedQuantity.')';
        }

        if ($field->type === 'material_lot') {
            $this->validate([
                'selectedLotId' => ['required', 'exists:monox_lots,id'],
            ]);
            $lot = \Lastdino\Monox\Models\Lot::find($this->selectedLotId);

            // 数量フィールドを探して在庫チェック
            if ($field->related_field_id) {
                $qtyValue = ProductionAnnotationValue::where('production_record_id', $this->record->id)
                    ->where('field_id', $field->related_field_id)
                    ->first();

                if ($qtyValue && $qtyValue->quantity) {
                    if ($qtyValue->quantity > $lot->current_stock) {
                        $this->addError('selectedLotId', '在庫数（'.number_format($lot->current_stock, 2).'）が不足しています（必要数: '.number_format($qtyValue->quantity, 2).'）。');

                        return;
                    }
                }
            }

            $this->fieldValue = $lot->lot_number;
        }

        if ($field->type === 'material_quantity') {
            $this->validate([
                'consumedQuantity' => ['required', 'numeric', 'min:0.0001'],
            ]);

            // ロットフィールドを探して在庫チェック
            if ($field->related_field_id) {
                $lotValue = ProductionAnnotationValue::where('production_record_id', $this->record->id)
                    ->where('field_id', $field->related_field_id)
                    ->first();

                if ($lotValue && $lotValue->lot_id) {
                    $lot = \Lastdino\Monox\Models\Lot::find($lotValue->lot_id);
                    if ($this->consumedQuantity > $lot->current_stock) {
                        $this->addError('consumedQuantity', '在庫数（'.number_format($lot->current_stock, 2).'）を超える数量は指定できません。');

                        return;
                    }
                }
            }

            $this->fieldValue = (string) $this->consumedQuantity;
        }

        $valueModel = ProductionAnnotationValue::updateOrCreate(
            [
                'production_record_id' => $this->record->id,
                'field_id' => $this->activeFieldId,
            ],
            [
                'value' => $this->fieldValue,
                'note' => $this->fieldNote,
                'is_within_tolerance' => $isWithinTolerance,
                'lot_id' => in_array($field->type, ['material', 'material_lot']) ? $this->selectedLotId : null,
                'quantity' => in_array($field->type, ['material', 'material_quantity']) ? $this->consumedQuantity : null,
            ]
        );

        if ($field->type === 'photo' && str_starts_with($this->fieldValue, 'data:image')) {
            $media = $valueModel->addMediaFromBase64($this->fieldValue)
                ->usingFileName('photo_'.now()->format('Ymd_His').'.jpg')
                ->toMediaCollection('photo');

            // value カラムにはメディアの ID を保持（URL ではなく route で表示するため）
            $this->fieldValue = (string) $media->id;
            $valueModel->update(['value' => $this->fieldValue]);
        }

        // input_quantity, good_quantity, defective_quantity の場合は record も更新する
        if (in_array($field->type, ['input_quantity', 'good_quantity', 'defective_quantity']) && is_numeric($this->fieldValue)) {
            $val = (float) $this->fieldValue;
            $this->record->update([
                $field->type => $val,
            ]);
            // コンポーネントのプロパティも同期する
            $this->{$field->type} = $val;
        }

        // 既存の在庫移動があれば削除（再調整のため）
        if (in_array($field->type, ['material', 'material_lot', 'material_quantity'])) {
            $valueModel->stockMovements()->delete();

            // 分割入力の場合、相方のフィールドに関連付けられた在庫移動も削除する
            if ($field->related_field_id) {
                $relatedValue = ProductionAnnotationValue::where('production_record_id', $this->record->id)
                    ->where('field_id', $field->related_field_id)
                    ->first();

                if ($relatedValue) {
                    $relatedValue->stockMovements()->delete();
                }
            }
        }

        if ($field->type === 'material') {
            // 在庫を減らす (出庫)
            \Lastdino\Monox\Models\StockMovement::create([
                'item_id' => $lot->item_id,
                'lot_id' => $lot->id,
                'quantity' => -$this->consumedQuantity,
                'type' => 'out',
                'reason' => '製造工程での使用 (指図 ID: '.$this->order->id.', 工程: '.$this->process->name.')',
                'moved_at' => now(),
                'department_id' => $this->order->department_id,
                'production_annotation_value_id' => $valueModel->id,
            ]);
            Flux::toast('在庫を更新（出庫）しました。');
        }

        if ($field->type === 'material_quantity' && $field->related_field_id) {
            // ロットフィールドを探す
            $lotValue = ProductionAnnotationValue::where('production_record_id', $this->record->id)
                ->where('field_id', $field->related_field_id)
                ->first();

            if ($lotValue && $lotValue->lot_id) {
                $lot = \Lastdino\Monox\Models\Lot::find($lotValue->lot_id);
                // 在庫を減らす (出庫)
                \Lastdino\Monox\Models\StockMovement::create([
                    'item_id' => $lot->item_id,
                    'lot_id' => $lot->id,
                    'quantity' => -$this->consumedQuantity,
                    'type' => 'out',
                    'reason' => '製造工程での使用 (指図 ID: '.$this->order->id.', 工程: '.$this->process->name.', 分割入力)',
                    'moved_at' => now(),
                    'department_id' => $this->order->department_id,
                    'production_annotation_value_id' => $valueModel->id,
                ]);
                Flux::toast('在庫を更新（出庫）しました。');
            } else {
                Flux::toast('ロットが未入力のため、在庫は更新されませんでした。', variant: 'warning');
            }
        }

        if ($field->type === 'material_lot' && $field->related_field_id) {
            // 数量フィールドを探す
            $qtyValue = ProductionAnnotationValue::where('production_record_id', $this->record->id)
                ->where('field_id', $field->related_field_id)
                ->first();

            if ($qtyValue && $qtyValue->quantity) {
                $lot = \Lastdino\Monox\Models\Lot::find($this->selectedLotId);
                // 在庫を減らす (出庫)
                \Lastdino\Monox\Models\StockMovement::create([
                    'item_id' => $lot->item_id,
                    'lot_id' => $lot->id,
                    'quantity' => -$qtyValue->quantity,
                    'type' => 'out',
                    'reason' => '製造工程での使用 (指図 ID: '.$this->order->id.', 工程: '.$this->process->name.', 分割入力)',
                    'moved_at' => now(),
                    'department_id' => $this->order->department_id,
                    'production_annotation_value_id' => $valueModel->id,
                ]);
                Flux::toast('在庫を更新（出庫）しました。');
            }
        }

        if (! $isWithinTolerance) {
            Flux::toast('許容範囲外の数値が入力されました。', variant: 'warning');
        } else {
            Flux::toast('記録しました。');
        }

        Flux::modal('annotation-modal')->close();
        $this->record->load('annotationValues');
    }

    #[\Livewire\Attributes\On('qr-scanned')]
    public function scanWorker(): void
    {
        $columns = config('monox.production.worker_scan_columns', ['id', 'email']);

        $query = \App\Models\User::query();

        foreach ($columns as $index => $column) {
            if ($index === 0) {
                $query->where($column, $this->worker_code);
            } else {
                $query->orWhere($column, $this->worker_code);
            }
        }

        $user = $query->first();

        if ($user) {
            $this->currentWorker = $user;
            $this->worker_code = '';
            Flux::toast($user->{config('monox.display.worker_column', 'name')}.' がログインしました。');
        } else {
            Flux::toast('作業者が見つかりません。', variant: 'danger');
        }
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ $order->item->name }} - 製造ワークシート</flux:heading>
            <flux:subheading>ロット: {{ $order->lot->lot_number ?? '-' }} | 予定数: {{ number_format($order->target_quantity, 2) }} {{ $order->item->unit }}</flux:subheading>
        </div>
        <div class="flex items-center gap-4">
            @can('production.download.'.$this->departmentId)
                <flux:button wire:click="exportExcel" icon="document-arrow-down" variant="outline">Excel出力</flux:button>
            @endcan
            <div x-data="{ scanning: false }" class="flex items-center gap-2">
                @if($currentWorker)
                    <flux:badge color="green" icon="user" variant="outline">{{ $currentWorker->{config('monox.display.worker_column', 'name')} }}</flux:badge>
                @endif
                <flux:input wire:model.live="worker_code" wire:keydown.enter="scanWorker" placeholder="作業者コードをスキャン..." class="w-48" />
                <livewire:monox_component::qr-scanner wire:model.live="worker_code"/>

            </div>
            <flux:button href="{{ route('monox.production.index', ['department' => $order->department_id]) }}" variant="ghost" icon="chevron-left">一覧へ</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- 工程リスト -->
        <div class="lg:col-span-1 space-y-2">
            @foreach($order->item->processes as $p)
                @php
                    $rec = $records[$p->id] ?? null;
                    $isActive = $currentProcessId === $p->id;
                @endphp
                <div wire:click="selectProcess({{ $p->id }})"
                     class="p-4 rounded-lg border cursor-pointer transition-colors {{ $isActive ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'bg-white dark:bg-zinc-800' }}">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ $p->name }}</span>
                        @if($rec?->status === 'completed')
                            <flux:badge color="green" size="sm">完了</flux:badge>
                        @elseif($rec?->status === 'paused')
                            <flux:badge color="orange" size="sm">一時停止中</flux:badge>
                        @elseif($rec?->status === 'stopped')
                            <flux:badge color="red" size="sm">中止</flux:badge>
                        @elseif($rec?->status === 'in_progress')
                            <flux:badge color="blue" size="sm">進行中</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">未着手</flux:badge>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- 作業エリア -->
        <div class="lg:col-span-3 space-y-6">
            @if($this->process)
                <div class="p-6 bg-white dark:bg-zinc-800 rounded-lg border space-y-6">
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">{{ $this->process->name }}</flux:heading>
                        <div class="flex gap-2">
                            @if($this->record?->status === 'paused')
                                <flux:button wire:click="stamp('resume')" size="sm" variant="primary" icon="play">再開</flux:button>
                            @elseif($this->record?->status === 'stopped')
                                <flux:button disabled size="sm">段取開始</flux:button>
                                <flux:button disabled size="sm">段取終了</flux:button>
                                <flux:button disabled size="sm" variant="primary">作業開始</flux:button>
                                <flux:button disabled size="sm" variant="primary">作業終了</flux:button>
                            @else
                                <flux:button wire:click="stamp('setup_start')" :disabled="$this->record?->setup_started_at" size="sm">段取開始</flux:button>
                                <flux:button wire:click="stamp('setup_end')" :disabled="!$this->record?->setup_started_at || $this->record?->setup_finished_at" size="sm">段取終了</flux:button>
                                <flux:button wire:click="stamp('work_start')" :disabled="!$this->record?->setup_finished_at || $this->record?->work_started_at" size="sm" variant="primary">作業開始</flux:button>
                                <flux:button wire:click="stamp('work_end')" :disabled="!$this->record?->work_started_at || $this->record?->work_finished_at || $this->record?->status === 'completed'" size="sm" variant="primary">作業終了</flux:button>

                                @if($this->record?->status === 'in_progress')
                                    <flux:button wire:click="stamp('pause')" size="sm" icon="pause">一時停止</flux:button>
                                @endif
                            @endif

                            @if($this->record && !in_array($this->record->status, ['completed', 'stopped']))
                                <flux:button wire:click="stamp('stop')" wire:confirm="作業を中止しますか？" size="sm" variant="danger" icon="stop">中止</flux:button>
                            @endif
                        </div>
                    </div>

                    @php
                        $effectiveMedia = $this->effectiveTemplateMedia;
                    @endphp

                    @if($effectiveMedia)
                        <div class="relative inline-block w-full border rounded overflow-hidden">
                            <img src="{{ route('monox.media.show', $effectiveMedia) }}" class="w-full h-auto" alt=""/>

                            {{-- 共有されている前工程すべてのアノテーションを表示 --}}
                            @if($this->process->share_template_with_previous)
                                @foreach($this->sharedProcessesChain as $prevProc)
                                    @php
                                        $prevRecord = $this->records[$prevProc->id] ?? null;
                                    @endphp
                                    @foreach($prevProc->annotationFields as $field)
                                        @php
                                            $valModel = $prevRecord?->annotationValues->where('field_id', $field->id)->first();
                                            $hasValue = !empty($valModel?->value);
                                            $outOfTolerance = $hasValue && !$valModel->is_within_tolerance;
                                        @endphp
                                        <div
                                            class="absolute border-2 {{ $outOfTolerance ? 'border-red-500 bg-red-100/30' : ($hasValue ? 'border-green-500 bg-green-100/30' : 'border-zinc-400 bg-zinc-400/10') }} flex items-center justify-center overflow-hidden z-0 opacity-70"
                                            style="left: {{ $field->x_percent }}%; top: {{ $field->y_percent }}%; width: {{ $field->width_percent }}%; height: {{ $field->height_percent }}%;"
                                        >
                                            @if($hasValue)
                                                @if($field->type === 'photo')
                                                    <img src="{{ is_numeric($valModel->value) ? route('monox.media.show', $valModel->value) : $valModel->value }}" class="w-full h-full object-cover">
                                                @else
                                                    <span class="font-bold bg-white/70 px-1 rounded truncate max-w-full {{ $outOfTolerance ? 'text-red-600' : 'text-zinc-600' }}" style="font-size: clamp(8px, {{ $field->height_percent * 0.5 }}cqh, 100px);">
                                                        {{ $field->type === 'boolean' && $valModel->value ? '✓' : $valModel->value }}
                                                    </span>
                                                @endif
                                            @endif
                                        </div>
                                    @endforeach
                                @endforeach
                            @endif

                            {{-- 現在の工程のアノテーションを表示 --}}
                            @foreach($this->process->annotationFields as $field)
                                @php
                                    $valModel = $this->record?->annotationValues->where('field_id', $field->id)->first();
                                    $hasValue = !empty($valModel?->value);
                                    $outOfTolerance = $hasValue && !$valModel->is_within_tolerance;
                                @endphp
                                <div
                                    class="absolute border-2 {{ $outOfTolerance ? 'border-red-500 bg-red-100/50' : ($hasValue ? 'border-green-500 bg-green-100/50' : 'border-blue-600 bg-blue-500/20 shadow-[0_0_8px_rgba(37,99,235,0.4)]') }} cursor-pointer flex items-center justify-center overflow-hidden hover:scale-105 transition-transform z-10"
                                    style="left: {{ $field->x_percent }}%; top: {{ $field->y_percent }}%; width: {{ $field->width_percent }}%; height: {{ $field->height_percent }}%;"
                                    wire:click="openAnnotation({{ $field->id }})"
                                >
                                    @if($hasValue)
                                        @if($field->type === 'photo')
                                            <img src="{{ is_numeric($valModel->value) ? route('monox.media.show', $valModel->value) : $valModel->value }}" class="w-full h-full object-cover">
                                        @else
                                            <span class="font-bold bg-white/90 px-1 rounded truncate max-w-full {{ $outOfTolerance ? 'text-red-600' : 'text-zinc-800' }}" style="font-size: clamp(8px, {{ $field->height_percent * 0.6 }}cqh, 100px);">
                                                @if($field->type === 'boolean' && $valModel->value)
                                                    ✓
                                                @else
                                                    {{ $valModel->value }}
                                                @endif
                                            </span>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="py-12 text-center text-zinc-500 border-2 border-dashed rounded">
                            画像テンプレートが設定されていません。
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <flux:modal name="annotation-modal" class="md:w-100">
        <form wire:submit="saveAnnotation" class="space-y-4">
            @if($activeFieldId)
                @php $f = ProductionAnnotationField::find($activeFieldId); @endphp
                <flux:heading size="lg">{{ $f->label }} の入力</flux:heading>

                @if($f->type === 'number')
                    <flux:input wire:model="fieldValue" type="number" step="0.0001" label="測定値" />
                    @if($f->min_value !== null || $f->max_value !== null)
                        <div class="text-xs text-zinc-500">許容範囲: {{ $f->min_value ?? '-∞' }} ～ {{ $f->max_value ?? '+∞' }}</div>
                    @endif
                @elseif($f->type === 'boolean')
                    <flux:checkbox wire:model="fieldValue" label="チェック" />
                @elseif($f->type === 'timestamp')
                    <flux:input wire:model="fieldValue" type="datetime-local" label="日時" />
                @elseif($f->type === 'signature')
                    <flux:input wire:model="fieldValue" label="署名 (お名前をご記入ください)" placeholder="例：山田 太郎" />
                @elseif($f->type === 'material')
                    <div class="space-y-4">
                        @php
                            $lots = [];
                            if ($f->linked_item_id) {
                                $lots = \Lastdino\Monox\Models\Lot::where('item_id', $f->linked_item_id)->get();
                            } else {
                                // 紐付けがない場合は全ロット（運用に合わせて要検討）
                                $lots = \Lastdino\Monox\Models\Lot::all();
                            }
                        @endphp

                        <div class="p-3 bg-zinc-50 dark:bg-white/5 rounded-lg border border-zinc-200 dark:border-zinc-700 space-y-4">
                            <flux:select wire:model="selectedLotId" label="使用ロット">
                                <flux:select.option value="">ロットを選択してください...</flux:select.option>
                                @foreach($lots as $lot)
                                    <flux:select.option :value="$lot->id">
                                        {{ $lot->item->name }} : {{ $lot->lot_number }} (在庫: {{ $lot->current_stock }})
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input wire:model="consumedQuantity" type="number" step="0.0001" label="使用数量" />
                        </div>
                    </div>
                @elseif($f->type === 'material_lot')
                    <div class="space-y-4">
                        @php
                            $lots = [];
                            if ($f->linked_item_id) {
                                $lots = \Lastdino\Monox\Models\Lot::where('item_id', $f->linked_item_id)->get();
                            } else {
                                $lots = \Lastdino\Monox\Models\Lot::all();
                            }
                        @endphp
                        <flux:select wire:model="selectedLotId" label="使用ロット">
                            <flux:select.option value="">ロットを選択してください...</flux:select.option>
                            @foreach($lots as $lot)
                                <flux:select.option :value="$lot->id">
                                    {{ $lot->item->name }} : {{ $lot->lot_number }} (在庫: {{ $lot->current_stock }})
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        @if($f->related_field_id)
                            <flux:subheading>※ 数量は別途「{{ $f->relatedField?->label }}」で入力してください。</flux:subheading>
                        @endif
                    </div>
                @elseif($f->type === 'material_quantity')
                    <div class="space-y-4">
                        <flux:input wire:model="consumedQuantity" type="number" step="0.0001" label="使用数量" />
                        @if($f->related_field_id)
                            <flux:subheading>※ ロットは別途「{{ $f->relatedField?->label }}」で入力してください。</flux:subheading>
                        @endif
                    </div>
                @elseif($f->type === 'photo')
                    <div class="space-y-2">
                        @if($fieldValue)
                            <img src="{{ is_numeric($fieldValue) ? route('monox.media.show', $fieldValue) : $fieldValue }}" class="w-full rounded border">
                            <flux:button wire:click="$set('fieldValue', null)" variant="danger" size="sm">再撮影</flux:button>
                        @else
                            <div @photo-captured="$wire.setPhoto($event.detail)">
                                <livewire:monox_component::camera-capture />
                            </div>
                        @endif
                    </div>
                @else
                    <flux:input wire:model="fieldValue" label="入力値" />
                @endif

                <flux:textarea wire:model="fieldNote" label="備考・メモ" rows="2" placeholder="気づいた点があれば記入してください" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">キャンセル</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">記録</flux:button>
                </div>
            @endif
        </form>
    </flux:modal>

    <flux:modal name="record-quantities-modal" class="md:w-96">
        <form wire:submit="submitQuantities" class="space-y-6">
            <div>
                <flux:heading size="lg">実績数の入力</flux:heading>
                <flux:subheading>投入数、良品数、不良数を入力してください。</flux:subheading>
            </div>

            <flux:input wire:model="input_quantity" type="number" step="0.0001" label="投入数" />
            <flux:input wire:model="good_quantity" type="number" step="0.0001" label="良品数" />
            <flux:input wire:model="defective_quantity" type="number" step="0.0001" label="不良数" />

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">キャンセル</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">記録して完了</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="complete-final-process-modal" class="md:w-100">
        <div class="space-y-4">
            <flux:heading size="lg">最終工程の完了確認</flux:heading>
            <flux:subheading>実績数を確認し、作業を完了してください。</flux:subheading>

            <div class="space-y-4">
                <div class="grid grid-cols-3 gap-4">
                    <flux:input wire:model="input_quantity" type="number" step="0.0001" label="投入数" />
                    <flux:input wire:model="good_quantity" type="number" step="0.0001" label="良品数" />
                    <flux:input wire:model="defective_quantity" type="number" step="0.0001" label="不良数" />
                </div>

                <div class="p-4 bg-zinc-50 dark:bg-white/5 rounded-lg space-y-4">
                    <flux:checkbox wire:model.live="shouldUpdateInventory" label="良品数を在庫に反映する (入庫記録を作成)" />
                    @if($shouldUpdateInventory)
                        <div class="text-sm text-zinc-500">
                            入庫数: <strong>{{ number_format((float)$good_quantity, 4) }}</strong> {{ $order->item->unit }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">キャンセル</flux:button>
                </flux:modal.close>
                <flux:button wire:click="completeWork" variant="primary">完了</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
