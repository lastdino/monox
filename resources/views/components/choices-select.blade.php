<?php

use Livewire\Component;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\On;

new class extends Component
{
    public $options;

    #[Modelable]
    public $selectedOption;

    public $multiple = 0;
    public $column = 'name';
    public $placeholder = '';
    public $sortBy ;
    public $sortByDesc ;

    public function getOptions(){
        if($this->sortBy){
            return $this->options->sortBy($this->sortBy)->values();
        }elseif ($this->sortByDesc){
            return $this->options->sortByDesc($this->sortByDesc)->values();
        }else{
            return $this->options;
        }
    }

    #[On('modal-show')]
    #[On('choices-refresh')]
    public function Reception()
    {
        $this->dispatch('refresh')->self();
    }
};
?>

@php
    $classes = Flux::classes()
        ->add('appearance-none') // Strip the browser's default <select> styles...
        ->add('[:where(&)]:w-full ')
        ;
@endphp


<div {{ $attributes->class($classes) }}>
    <div x-data="choice" wire:ignore
         @if($placeholder)
        style="--placeholder-length: {{ mb_strlen($placeholder) + 4 }}ch;"
        @endif
    >
        <select data-placeholder="{{$placeholder}}" id="my-select{{$this->__id}}" wire:model="selectedOption" x-ref="select" :multiple="multiple" ></select>
    </div>
</div>
@assets
<script src="https://cdn.jsdelivr.net/npm/choices.js@11.1.0/public/assets/scripts/choices.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js@11.1.0/public/assets/styles/choices.min.css">
@endassets
@script
<script>
    Alpine.data('choice', () => ({
        multiple: false,
        value: null,
        choicesInstance: null,
        isUpdating: false,
        init() {
            this.multiple = Boolean(this.$wire.multiple);
            this.value = this.$wire.selectedOption;

            this.$nextTick(() => {
                if (this.choicesInstance) {
                    this.choicesInstance.removeActiveItems();
                }
                this.initChoices();

                this.$wire.on('refresh', () => {
                    //this.value =this.$wire.selectedOption;
                    this.updateOptions();
                });

                this.$watch('$wire.selectedOption', (val) => {
                    if (this.isUpdating) return;
                    this.value = val;
                    this.updateOptions();
                });
            });
        },
        initChoices() {
            const containerInnerClasses = [
                'choices__inner','!rounded-lg', '!bg-white', '!dark:bg-white/10',
                '!text-zinc-700', '!dark:text-zinc-300', '!min-h-10', 'shadow-xs', 'border', 'appearance-none',
                'border-zinc-200', 'border-b-zinc-300/80', 'dark:border-white/10',
                'focus-within:ring-2', 'focus-within:ring-accent','!p-1','flex'
            ];

            this.choicesInstance = new Choices(this.$refs.select, {
                allowHTML: false,
                removeItemButton: true,
                noChoicesText: '選択されていません',
                itemSelectText: '選択してください',
                searchPlaceholderValue:'ID又は名前を入れて検索できます。',
                silent: false,
                shouldSort: false,
                classNames: {
                    containerInner: containerInnerClasses,
                    input: ['choices__input','!text-zinc-700', 'dark:!text-zinc-300','!bg-white','dark:!bg-white/10','!mb-0','flex-1','choices-input-custom'],
                    list: ['choices__list','[&[aria-expanded]]:!bg-white','[&[aria-expanded]]:dark:!bg-white/10','[&[aria-expanded]]:dark:disabled:!bg-white/[7%]'],
                    item: ['choices__item'],
                }
            });

            this.updateOptions();

            this.$refs.select.addEventListener('change', () => {
                this.isUpdating = true;
                this.value = this.choicesInstance.getValue(true);
                this.$wire.selectedOption = this.value ?? (this.multiple ? [] : null);
                this.$nextTick(() => { this.isUpdating = false; });
            });
        },
        async updateOptions() {
            const options = await this.$wire.getOptions();
            const currentSelected = this.value;

            let vals = this.multiple
                ? (Array.isArray(currentSelected) ? currentSelected : (currentSelected ? [currentSelected] : []))
                : (currentSelected ? [currentSelected] : []);

            vals = vals.map(x => String(x));

            const formattedOptions = options.map(item => ({
                label: item[this.$wire.column || 'name'],
                value: String(item.id),
                selected: vals.includes(String(item.id)),
            }));

            this.choicesInstance.setChoices(formattedOptions, 'value', 'label', true);
        },

        destroy() {
            if (this.choicesInstance) {
                this.choicesInstance.destroy();
            }
        }
    }));
</script>
@endscript
