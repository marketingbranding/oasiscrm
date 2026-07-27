@props(['name', 'value' => null, 'required' => false, 'accent' => '#fcc20f'])
<div class="date-wrapper" data-accent="{{ $accent }}" style="position:relative">
    <div class="date-display w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0">
        <span class="date-text">Pilih Tanggal</span><span class="date-arrow">▼</span>
    </div>
    <div class="date-calendar" style="display:none;position:absolute;top:100%;left:0;z-index:9999;border:2px solid #000;background:#fff;width:280px">
        <div class="cal-header" style="background:#000;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:6px 10px;font-family:'Times New Roman';font-size:14px;font-weight:bold;user-select:none">
            <button class="cal-prev" type="button" style="padding:2px 8px">◀</button><span class="cal-title">Bulan Tahun</span><button class="cal-next" type="button" style="padding:2px 8px">▶</button>
        </div>
        <div class="cal-weekdays" style="display:grid;grid-template-columns:repeat(7,1fr);border-bottom:2px solid #000;font-family:'Times New Roman';font-size:11px;font-weight:bold;text-align:center;background:#f5f5f5">
            @foreach(['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'] as $day)<span style="padding:5px 0">{{ $day }}</span>@endforeach
        </div>
        <div class="cal-grid" style="display:grid;grid-template-columns:repeat(7,1fr);font-family:'Times New Roman';font-size:13px"></div>
    </div>
    <input type="date" name="{{ $name }}" value="{{ $value }}" @required($required) {{ $attributes->except(['name', 'value', 'required', 'accent', 'class']) }}
           style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
</div>
