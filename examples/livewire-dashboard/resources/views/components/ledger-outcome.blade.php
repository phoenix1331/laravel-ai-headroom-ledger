@props(['entry', 'tone'])

{{-- Outcome badge: a status colour always paired with its label, never colour alone. --}}
<span {{ $attributes->class(['badge']) }}>
    <span class="swatch tone-{{ $tone }}" aria-hidden="true"></span>{{ ucfirst($entry->outcome->value) }}
</span>
