<?php

namespace App\Livewire;

use App\Support\NepaliDate;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class DateFormatToggle extends Component
{
    public string $dateFormat = 'AD';

    public function mount(): void
    {
        $setting = DB::table('settings')->where('id', 1)->first();
        $this->dateFormat = $setting->date_format ?? 'AD';
    }

    public function toggle(): void
    {
        $this->dateFormat = $this->dateFormat === 'AD' ? 'BS' : 'AD';

        DB::table('settings')->where('id', 1)->update([
            'date_format' => $this->dateFormat,
            'updated_at' => now(),
        ]);

        NepaliDate::resetCache();

        $this->dispatch('dateFormatChanged', format: $this->dateFormat);
        $this->dispatch('toast', message: "Date format changed to {$this->dateFormat}", type: 'success');
    }

    public function render()
    {
        return view('livewire.date-format-toggle');
    }
}
