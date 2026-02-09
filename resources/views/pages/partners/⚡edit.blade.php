<?php

use Flux\Flux;
use Lastdino\Monox\Models\Partner;
use Livewire\Component;

new class extends Component
{
    public ?Partner $partner = null;

    public string $code = '';

    public string $name = '';

    public string $type = 'supplier';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public function mount(?Partner $partner = null): void
    {
        if ($partner) {
            $this->setPartner($partner);
        }
    }

    public function setPartner(Partner $partner): void
    {
        $this->partner = $partner;
        $this->code = $partner->code;
        $this->name = $partner->name;
        $this->type = $partner->type;
        $this->email = $partner->email ?? '';
        $this->phone = $partner->phone ?? '';
        $this->address = $partner->address ?? '';
    }

    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'unique:monox_partners,code,'.$this->partner?->id],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:supplier,customer,both'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();

        $this->partner->update($validated);

        Flux::modal('edit-partner')->close();

        $this->dispatch('partner-updated');
        Flux::toast('取引先を更新しました。');
    }
};
?>

<flux:modal name="edit-partner" class="md:w-[30rem]">
    <form wire:submit="save">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">取引先編集</flux:heading>
                <flux:subheading>取引先情報を更新します。</flux:subheading>
            </div>

            <flux:input wire:model="code" label="取引先コード" placeholder="PARTNER-001" />

            <flux:input wire:model="name" label="取引先名" placeholder="株式会社モノックス" />

            <flux:select wire:model="type" label="種別">
                <flux:select.option value="supplier">仕入先</flux:select.option>
                <flux:select.option value="customer">販売先</flux:select.option>
                <flux:select.option value="both">両方</flux:select.option>
            </flux:select>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="email" type="email" label="メールアドレス" icon="envelope" />
                <flux:input wire:model="phone" label="電話番号" icon="phone" />
            </div>

            <flux:textarea wire:model="address" label="住所" />

            <div class="flex">
                <flux:spacer />

                <flux:button type="submit" variant="primary">更新</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
