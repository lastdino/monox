<?php

use Flux\Flux;
use Illuminate\Validation\Rule;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Models\ProductionAnnotationField;
use Livewire\Component;

new class extends Component
{
    public Process $process;

    public $fields = [];

    public ?int $editingFieldId = null;

    // Form fields
    public string $label = '';

    public string $field_key = '';

    public string $type = 'number';

    public bool $is_optional = false;

    public float $x_percent = 50;

    public float $y_percent = 50;

    public float $width_percent = 10;

    public float $height_percent = 5;

    public ?float $target_value = null;

    public ?float $min_value = null;

    public ?float $max_value = null;

    public ?int $linked_item_id = null;

    public ?int $related_field_id = null;

    public function mount(Process $process): void
    {
        $this->process = $process->load(['annotationFields', 'item.components']);
        $this->fields = $this->process->annotationFields;
    }

    public function addFieldAt(float $x, float $y, float $width = 10, float $height = 5): void
    {
        $this->resetForm();
        $this->x_percent = round($x, 2);
        $this->y_percent = round($y, 2);
        $this->width_percent = round($width, 2);
        $this->height_percent = round($height, 2);
        $this->editingFieldId = null;

        Flux::modal('field-editor')->show();

    }

    public function editField(int $id): void
    {
        $field = ProductionAnnotationField::findOrFail($id);
        $this->editingFieldId = $id;
        $this->label = $field->label;
        $this->field_key = $field->field_key;
        $this->type = $field->type;
        $this->is_optional = $field->is_optional;
        $this->x_percent = $field->x_percent;
        $this->y_percent = $field->y_percent;
        $this->width_percent = $field->width_percent;
        $this->height_percent = $field->height_percent;
        $this->target_value = $field->target_value;
        $this->min_value = $field->min_value;
        $this->max_value = $field->max_value;
        $this->linked_item_id = $field->linked_item_id;
        $this->related_field_id = $field->related_field_id;

        Flux::modal('field-editor')->show();

    }

    public function saveField(): void
    {
        $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'field_key' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:number,text,boolean,signature,timestamp,material,material_lot,material_quantity,input_quantity,good_quantity,defective_quantity,photo,production_lot'],
            'is_optional' => ['required', 'boolean'],
            'x_percent' => ['required', 'numeric', 'between:0,100'],
            'y_percent' => ['required', 'numeric', 'between:0,100'],
            'width_percent' => ['required', 'numeric', 'between:1,100'],
            'height_percent' => ['required', 'numeric', 'between:1,100'],
            'target_value' => ['nullable', 'numeric'],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric'],
            'linked_item_id' => [
                Rule::requiredIf(in_array($this->type, ['material', 'material_lot'])),
                'nullable',
                'integer',
                'exists:monox_items,id',
            ],
            'related_field_id' => ['nullable', 'integer', 'exists:monox_production_annotation_fields,id'],
        ]);

        if ($this->type === 'material_quantity') {
            $this->linked_item_id = null;
        }

        $data = [
            'process_id' => $this->process->id,
            'label' => $this->label,
            'field_key' => $this->field_key,
            'type' => $this->type,
            'is_optional' => $this->is_optional,
            'x_percent' => $this->x_percent,
            'y_percent' => $this->y_percent,
            'width_percent' => $this->width_percent,
            'height_percent' => $this->height_percent,
            'target_value' => $this->target_value,
            'min_value' => $this->min_value,
            'max_value' => $this->max_value,
            'linked_item_id' => $this->linked_item_id,
            'related_field_id' => $this->related_field_id,
        ];

        if ($this->editingFieldId) {
            $field = ProductionAnnotationField::findOrFail($this->editingFieldId);
            $field->update($data);

            // 双方向リンクの更新
            if ($this->related_field_id) {
                ProductionAnnotationField::findOrFail($this->related_field_id)->update(['related_field_id' => $field->id]);
            }

            Flux::toast('フィールドを更新しました。');
        } else {
            $field = ProductionAnnotationField::create($data);

            // 双方向リンクの更新
            if ($this->related_field_id) {
                ProductionAnnotationField::findOrFail($this->related_field_id)->update(['related_field_id' => $field->id]);
            }

            Flux::toast('フィールドを追加しました。');
        }
        Flux::modal('field-editor')->close();

        $this->process->load('annotationFields');
        $this->fields = $this->process->annotationFields;
    }

    public function deleteField(int $id): void
    {
        ProductionAnnotationField::destroy($id);
        Flux::modal('field-editor')->close();

        $this->process->load('annotationFields');
        $this->fields = $this->process->annotationFields;
        Flux::toast('フィールドを削除しました。');
    }

    private function resetForm(): void
    {
        $this->reset(['label', 'field_key', 'type', 'is_optional', 'width_percent', 'height_percent', 'target_value', 'min_value', 'max_value', 'linked_item_id', 'related_field_id', 'editingFieldId']);
        $this->type = 'number';
        $this->is_optional = false;
        $this->width_percent = 10;
        $this->height_percent = 5;
    }

    public function getEffectiveTemplateMediaProperty()
    {
        if ($this->process->share_template_with_previous) {
            $curr = $this->process;
            while ($curr && $curr->share_template_with_previous) {
                $prev = $this->process->item->processes
                    ->where('sort_order', '<', $curr->sort_order)
                    ->last();

                if (! $prev) {
                    break;
                }

                if ($prev->template_media) {
                    return $prev->template_media;
                }

                $curr = $prev;
            }
        }

        return $this->process->template_media;
    }
};
?><div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ $process->name }} - アノテーション設定</flux:heading>
            <flux:subheading>画像上をドラッグして入力エリアを指定してください。クリックでも追加できます。</flux:subheading>
        </div>
        <flux:button href="{{ route('monox.items.index', ['department' => $process->item->department_id]) }}" variant="ghost" icon="chevron-left">工程一覧に戻る</flux:button>
    </div>

    <div class="relative inline-block w-full border rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-900"
         x-data="{
            isDragging: false,
            startX: 0,
            startY: 0,
            currentX: 0,
            currentY: 0,

            get dragStyle() {
                const x = Math.min(this.startX, this.currentX);
                const y = Math.min(this.startY, this.currentY);
                const w = Math.abs(this.startX - this.currentX);
                const h = Math.abs(this.startY - this.currentY);
                return `left: ${x}%; top: ${y}%; width: ${w}%; height: ${h}%;`;
            },

            startDragging(e) {
                if (e.button !== 0) return; // Left click only
                const rect = e.currentTarget.getBoundingClientRect();
                this.startX = ((e.clientX - rect.left) / rect.width) * 100;
                this.startY = ((e.clientY - rect.top) / rect.height) * 100;
                this.currentX = this.startX;
                this.currentY = this.startY;
                this.isDragging = true;

                const moveHandler = (e2) => {
                    const rect2 = rect;
                    this.currentX = Math.max(0, Math.min(100, ((e2.clientX - rect2.left) / rect2.width) * 100));
                    this.currentY = Math.max(0, Math.min(100, ((e2.clientY - rect2.top) / rect2.height) * 100));
                };

                const upHandler = (e2) => {
                    this.isDragging = false;
                    window.removeEventListener('mousemove', moveHandler);
                    window.removeEventListener('mouseup', upHandler);

                    const finalX = Math.min(this.startX, this.currentX);
                    const finalY = Math.min(this.startY, this.currentY);
                    const finalW = Math.abs(this.startX - this.currentX);
                    const finalH = Math.abs(this.startY - this.currentY);

                    // If the box is tiny, treat it as a click and use default size
                    if (finalW < 0.5 && finalH < 0.5) {
                        $wire.addFieldAt(finalX, finalY);
                    } else {
                        $wire.addFieldAt(finalX, finalY, finalW, finalH);
                    }
                };

                window.addEventListener('mousemove', moveHandler);
                window.addEventListener('mouseup', upHandler);
            }
         }"
         @mousedown="startDragging($event)">
        @php
            $effectiveMedia = $this->effectiveTemplateMedia;
        @endphp

        @if($effectiveMedia)
            <div class="relative inline-block w-full">
                <img src="{{ route('monox.media.show', $effectiveMedia) }}"
                     class="w-full h-auto cursor-crosshair select-none"
                     draggable="false" />
            </div>
        @else
            <div class="flex items-center justify-center h-64 text-zinc-400">
                画像が設定されていません
            </div>
        @endif

        {{-- Visual feedback while dragging --}}
        <div x-show="isDragging"
             class="absolute border-2 border-blue-500 bg-blue-400/20 pointer-events-none"
             :style="dragStyle">
        </div>

        @foreach($fields as $field)
            <div
                class="absolute border-2 border-blue-600 bg-blue-500/20 shadow-[0_0_8px_rgba(37,99,235,0.4)] hover:bg-blue-500/40 cursor-pointer flex items-center justify-center group overflow-hidden transition-all hover:scale-105 z-10"
                style="left: {{ $field->x_percent }}%; top: {{ $field->y_percent }}%; width: {{ $field->width_percent }}%; height: {{ $field->height_percent }}%;"
                wire:click.stop="editField({{ $field->id }})"
            >
                <span class="leading-none font-bold text-blue-900 bg-white/80 px-1 rounded truncate max-w-full" style="font-size: clamp(8px, {{ $field->height_percent * 0.6 }}cqh, 100px);">
                    {{ $field->label }}
                </span>

                <button wire:click.stop="deleteField({{ $field->id }})" wire:confirm="削除しますか？" class="absolute -top-2 -right-2 hidden group-hover:flex bg-red-500 text-white rounded-full p-0.5 shadow transition-all hover:scale-110 z-20">
                    <flux:icon icon="x-mark" variant="micro" />
                </button>
            </div>
        @endforeach
    </div>

    <flux:modal name="field-editor" class="md:w-120">
        <form wire:submit="saveField" class="space-y-4">
            <flux:heading size="lg">{{ $editingFieldId ? 'フィールド編集' : '新規フィールド追加' }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="label" label="ラベル" placeholder="例：内径、外観チェック" />
                <flux:input wire:model="field_key" label="キー (半角英数)" placeholder="例：inner_dia" />
            </div>

            <flux:select wire:model="type" label="入力タイプ">
                <flux:select.option value="number">数値</flux:select.option>
                <flux:select.option value="text">テキスト</flux:select.option>
                <flux:select.option value="boolean">チェックボックス</flux:select.option>
                <flux:select.option value="timestamp">タイムスタンプ</flux:select.option>
                <flux:select.option value="signature">署名</flux:select.option>
                <flux:select.option value="material">材料 (ロット/数量を一括入力)</flux:select.option>
                <flux:select.option value="material_lot">材料ロット (単独)</flux:select.option>
                <flux:select.option value="material_quantity">材料使用数量 (単独)</flux:select.option>
                <flux:select.option value="input_quantity">投入数</flux:select.option>
                <flux:select.option value="good_quantity">良品数</flux:select.option>
                <flux:select.option value="defective_quantity">不良数</flux:select.option>
                <flux:select.option value="production_lot">製造Lot</flux:select.option>
                <flux:select.option value="photo">写真</flux:select.option>
            </flux:select>

            <flux:checkbox wire:model="is_optional" label="任意入力にする" description="未入力でも作業を終了できるようにします。" />

            <div x-show="['material', 'material_lot', 'material_quantity'].includes($wire.type)" class="space-y-4">
                <div x-show="['material', 'material_lot'].includes($wire.type)">
                    <flux:select wire:model="linked_item_id" label="紐付ける品目 (BOM 構成品から選択)">
                        <flux:select.option value="">品目を選択...</flux:select.option>
                        @foreach($process->item->components as $comp)
                            <flux:select.option :value="$comp->id">{{ $comp->name }} ({{ $comp->code }})</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:subheading>※ 在庫を連動させるため、BOM に登録されている材料を指定してください。</flux:subheading>
                </div>

                <div x-show="$wire.type === 'material_quantity'">
                    <flux:select wire:model="related_field_id" label="関連付けるフィールド (ロットとのペア)">
                        <flux:select.option value="">なし</flux:select.option>
                        @foreach($process->annotationFields as $otherField)
                            @if($otherField->id !== $editingFieldId && $otherField->type === 'material_lot')
                                <flux:select.option :value="$otherField->id">{{ $otherField->label }} (ロット)</flux:select.option>
                            @endif
                        @endforeach
                    </flux:select>
                    <flux:subheading>※ ロット入力フィールドと紐付けることで、連動して在庫を減らすことができます。</flux:subheading>
                </div>
            </div>

            <div x-show="['number', 'input_quantity', 'good_quantity', 'defective_quantity'].includes($wire.type)" class="space-y-4">
                <div class="grid grid-cols-3 gap-4">
                    <flux:input wire:model="target_value" type="number" step="0.0001" label="基準値" />
                    <flux:input wire:model="min_value" type="number" step="0.0001" label="最小値" />
                    <flux:input wire:model="max_value" type="number" step="0.0001" label="最大値" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="width_percent" type="number" step="0.01" label="幅 (%)" />
                <flux:input wire:model="height_percent" type="number" step="0.01" label="高さ (%)" />
            </div>

            <div class="flex justify-between gap-2">
                <div>
                    @if ($editingFieldId)
                        <flux:button wire:click="deleteField({{ $editingFieldId }})" wire:confirm="このフィールドを削除しますか？" variant="danger">削除</flux:button>
                    @endif
                </div>
                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">キャンセル</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">保存</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
