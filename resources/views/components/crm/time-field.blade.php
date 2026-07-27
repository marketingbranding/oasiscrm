@props(['name', 'value' => null, 'required' => false, 'accent' => '#fcc20f'])
<div class="time-wrapper" data-accent="{{ $accent }}">
    <div class="time-display w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0" role="button" aria-haspopup="dialog" aria-expanded="false">
        <span class="time-text">Pilih Jam</span><span class="time-arrow">▼</span>
    </div>
    <input type="time" name="{{ $name }}" value="{{ $value }}" @required($required)
           {{ $attributes->except(['name', 'value', 'required', 'accent', 'class']) }}
           style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
</div>
