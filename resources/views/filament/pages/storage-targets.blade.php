<x-filament-panels::page>
    @php($targets = $this->getTargets())

    <div class="space-y-6">
        @foreach ($targets as $target)
            <x-filament::section>
                <x-slot name="heading">
                    {{ $target['label'] }}
                    @if (! $target['enabled'])
                        <span class="ml-2 text-xs font-semibold text-gray-400">DISABLED</span>
                    @elseif (! $target['writable'])
                        <span class="ml-2 text-xs font-semibold text-amber-500">READ-ONLY</span>
                    @elseif ($target['past_soft_limit'])
                        <span class="ml-2 text-xs font-semibold text-danger-500">NEAR LIMIT</span>
                    @else
                        <span class="ml-2 text-xs font-semibold text-success-500">READY</span>
                    @endif
                </x-slot>
                <x-slot name="description">
                    Key: <code>{{ $target['key'] }}</code>
                    &middot; Provider: <code>{{ $target['provider'] }}</code>
                    &middot; Disk: <code>{{ $target['disk'] }}</code>
                    &middot; Bucket: <code>{{ $target['bucket'] }}</code>
                    &middot; Priority: {{ $target['priority'] }}
                </x-slot>

                <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <p class="text-gray-500 dark:text-gray-400">Used</p>
                        <p class="font-semibold">{{ number_format($target['used_percent'], 2) }}%</p>
                    </div>
                    <div>
                        <p class="text-gray-500 dark:text-gray-400">Remaining</p>
                        <p class="font-semibold">{{ number_format($target['remaining_bytes'] / 1073741824, 2) }} GB</p>
                    </div>
                    <div>
                        <p class="text-gray-500 dark:text-gray-400">Capacity</p>
                        <p class="font-semibold">{{ number_format($target['capacity_bytes'] / 1073741824, 2) }} GB</p>
                    </div>
                    <div>
                        <p class="text-gray-500 dark:text-gray-400">Reserve</p>
                        <p class="font-semibold">{{ $target['reserve_percent'] }}%</p>
                    </div>
                </div>

                <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                    <div
                        class="h-full {{ $target['past_soft_limit'] ? 'bg-danger-500' : 'bg-primary-500' }}"
                        style="width: {{ min(100, $target['used_percent']) }}%"
                    ></div>
                </div>

                <div class="mt-3 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                    <p>Public URL: <code>{{ $target['public_url'] ?: 'not configured' }}</code></p>
                    <p>
                        Usage source: {{ $target['usage_source'] }}
                        @if ($target['usage_stale'])
                            &middot; <span class="text-amber-500">stale — last checked {{ $target['known_used_at'] ?? 'never' }}</span>
                        @else
                            &middot; last checked {{ $target['known_used_at'] ?? 'never' }}
                        @endif
                    </p>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
