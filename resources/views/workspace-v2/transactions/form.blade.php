@extends('workspace-v2.layouts.app')
@section('title', $title.' | Workspace OASIS')
@section('content')
    @include('workspace-v2.components.page-header', ['eyebrow' => 'TRANSAKSI KONSUMEN / INPUT', 'title' => $title, 'description' => $description])
    @isset($application)
        <div class="workspace-v2-context-bar"><strong>{{ $application->customer?->name ?: 'Konsumen' }}</strong><span>{{ $application->id_transaksi }}</span><span>{{ $application->project?->project_name ?: 'Proyek belum diisi' }}</span><span>{{ $application->kavling?->kavling_code ?: 'Kavling belum diisi' }}</span></div>
    @endisset
    <form method="POST" action="{{ $action }}" class="workspace-v2-form">
        @csrf
        @if($method !== 'POST') @method($method) @endif
        <div class="workspace-v2-form-grid">
            @foreach($fields as $field)
                <label class="workspace-v2-field {{ ($field['wide'] ?? false) ? 'workspace-v2-field--wide' : '' }}">
                    <span>{{ $field['label'] }} @if($field['required'] ?? false)<b>*</b>@endif</span>
                    @if(($field['type'] ?? 'text') === 'textarea')
                        <textarea name="{{ $field['name'] }}" rows="4" @required($field['required'] ?? false)>{{ old($field['name']) }}</textarea>
                    @elseif(($field['type'] ?? 'text') === 'select')
                        <select name="{{ $field['name'] }}" @required($field['required'] ?? false)>
                            <option value="">Pilih {{ strtolower($field['label']) }}</option>
                            @if(($field['options'] ?? null) === 'branches')
                                @foreach($branches as $option)<option value="{{ $option->id }}" @selected(old($field['name']) == $option->id)>{{ $option->name }}</option>@endforeach
                            @elseif(($field['options'] ?? null) === 'projects')
                                @foreach($projects as $option)<option value="{{ $option->id }}" @selected(old($field['name']) == $option->id)>{{ $option->project_name }}{{ $option->branch?->name ? ' · '.$option->branch->name : '' }}</option>@endforeach
                            @else
                                @foreach(($field['values'] ?? []) as $value => $label)<option value="{{ $value }}" @selected(old($field['name']) === $value)>{{ $label }}</option>@endforeach
                            @endif
                        </select>
                    @else
                        <input type="{{ $field['type'] ?? 'text' }}" name="{{ $field['name'] }}" value="{{ old($field['name']) }}" @required($field['required'] ?? false)>
                    @endif
                    @error($field['name'])<small class="workspace-v2-error">{{ $message }}</small>@enderror
                </label>
            @endforeach
        </div>
        <div class="workspace-v2-form-actions"><a class="workspace-v2-button workspace-v2-button--quiet" href="{{ route('workspace-v2.transactions') }}">Batal</a><button class="workspace-v2-button workspace-v2-button--primary" type="submit">Simpan {{ $title }}</button></div>
    </form>
@endsection
