@php
    /** @var string $statePath */
    /** @var int $minLength */
    /** @var array<int, array{key: string, label: string}> $requirements */
@endphp

<div
    x-data="{
        password: $wire.entangle(@js($statePath)),
        checks: {
            min: (v) => (v ?? '').length >= @js($minLength),
            mixed: (v) => /\p{Lu}/u.test(v ?? '') && /\p{Ll}/u.test(v ?? ''),
            number: (v) => /\d/.test(v ?? ''),
            symbol: (v) => /[^\p{L}\p{N}]/u.test(v ?? ''),
        },
    }"
    class="mt-1"
>
    <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">
        Password must include:
    </p>

    <ul class="space-y-1">
        @foreach ($requirements as $requirement)
            <li
                x-data="{ met: false }"
                x-effect="met = checks[@js($requirement['key'])](password)"
                class="flex items-center gap-1.5 text-xs transition-colors"
                :class="met
                    ? 'text-success-600 dark:text-success-400'
                    : 'text-warning-600 dark:text-warning-500'"
            >
                <svg x-show="met" x-cloak class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                <svg x-show="!met" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <circle cx="12" cy="12" r="8" />
                </svg>

                <span x-text="@js($requirement['label'])"></span>
                <span class="sr-only" x-text="met ? '(met)' : '(not met)'"></span>
            </li>
        @endforeach
    </ul>
</div>
