@props(['amount', 'signed' => false, 'colored' => true])

@php
    $amount = round((float) $amount, 2);
    $class = 'tabular-nums';
    if ($colored && $amount < 0) {
        $class .= ' text-red-600 dark:text-red-400';
    } elseif ($colored && $amount > 0 && $signed) {
        $class .= ' text-green-700 dark:text-green-400';
    }
@endphp

<span {{ $attributes->merge(['class' => $class]) }}>{{ money($amount, $signed) }}</span>
