@php
    $isContained = $isContained();
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div
        {{ $attributes->merge(
                [
                    'id' => $getId(),
                ],
                escape: false,
            )->merge($getExtraAttributes(), escape: false)->class(['fi-in-repeatable', 'fi-contained' => $isContained]) }}>
        @if (count($childComponentContainers = $getChildComponentContainers()))
            <ol class="relative border-gray-200 border-s dark:border-gray-700">
                <div
                    class="grid gap-2
                        grid-cols-{{ $getGridColumns('default') }}
                        sm:grid-cols-{{ $getGridColumns('sm') }}
                        md:grid-cols-{{ $getGridColumns('md') }}
                        lg:grid-cols-{{ $getGridColumns('lg') }}
                        xl:grid-cols-{{ $getGridColumns('xl') }}
                        2xl:grid-cols-{{ $getGridColumns('2xl') }}">
                    @foreach ($childComponentContainers as $container)
                        <li @class([
                            'mb-4 ms-6',
                            'fi-in-repeatable-item block',
                            'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10' => $isContained,
                        ])>
                            {{ $container }}
                        </li>
                    @endforeach
                </div>
            </ol>
        @elseif (($placeholder = $getPlaceholder()) !== null)
            <div class="text-sm text-gray-500 dark:text-gray-400 italic">
                {{ $placeholder }}
            </div>
        @endif
    </div>
</x-dynamic-component>
