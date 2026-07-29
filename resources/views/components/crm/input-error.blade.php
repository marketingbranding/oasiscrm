@props(['message' => null])

@if($message || $slot->isNotEmpty())
    <p role="alert" {{ $attributes->class(['crm-input-error']) }}>{{ $message ?? $slot }}</p>
@endif
