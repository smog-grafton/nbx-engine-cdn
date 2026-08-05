<x-filament-panels::page>
    <div class="space-y-6">
        @if ($loadError)
            <div role="alert" class="rounded-xl bg-danger-50 p-5 text-danger-800 ring-1 ring-danger-600/20 dark:bg-danger-950/40 dark:text-danger-200 dark:ring-danger-500/30">
                <h2 class="font-semibold">Storage inventory needs attention</h2>
                <p class="mt-1 text-sm">{{ $loadError }}</p>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
            @foreach ([
                ['Stored objects', $summary['objects'] ?? 0],
                ['Logical packages', $summary['packages'] ?? 0],
                ['Accounted storage', number_format(($summary['bytes'] ?? 0) / 1073741824, 2).' GB'],
                ['HLS packages', number_format(($summary['hls_bytes'] ?? 0) / 1073741824, 2).' GB · '.number_format($summary['hls_objects'] ?? 0).' objects'],
                ['Duplicate signatures', number_format($summary['duplicate_candidates'] ?? 0).' candidates · '.number_format(($summary['verified_redundant_bytes'] ?? 0) / 1073741824, 3).' GB byte-verified redundancy'],
                ['Approved reclaim', number_format(($summary['approved_reclaim_bytes'] ?? 0) / 1073741824, 3).' GB safe after review'],
            ] as [$label, $value])
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="font-semibold text-gray-950 dark:text-white">Read-only bucket inventory</h2>
                    @if ($latestRun)
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Run #{{ $latestRun['id'] }} · {{ str_replace('_', ' ', $latestRun['status']) }}
                            · prefix {{ $latestRun['prefix'] ?: 'bucket root' }}
                            · {{ number_format($latestRun['object_count']) }} objects
                            · {{ number_format($latestRun['total_bytes'] / 1073741824, 2) }} GB
                            · {{ number_format($latestRun['pages_scanned']) }} S3 pages
                        </p>
                        @if ($latestRun['failure_reason'])
                            <p class="mt-1 text-sm text-danger-600">{{ $latestRun['failure_reason'] }}</p>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-gray-500">No complete inventory exists yet.</p>
                    @endif
                </div>
                <x-filament::button
                    wire:click="startInventory"
                    wire:loading.attr="disabled"
                    wire:target="startInventory"
                    wire:confirm="Start a read-only Contabo inventory? This lists and indexes metadata; it does not modify or delete objects."
                >
                    <span wire:loading.remove wire:target="startInventory">Scan bucket</span>
                    <span wire:loading wire:target="startInventory">Queuing…</span>
                </x-filament::button>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                “Unresolved” means no authoritative link has been found. It does not mean orphaned. HLS segments are counted for billing but grouped below as one media package.
            </p>
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="font-semibold text-gray-950 dark:text-white">Catalog recovery from storage</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        Recreates media_assets/media_sources rows for NBX job folders that exist in Contabo but have
                        no matching database row — for recovering after the NBX database was lost.
                    </p>
                    @if ($latestRebuild)
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            @if (($latestRebuild['status'] ?? null) === 'queued')
                                Rebuild queued — refresh this page shortly for results.
                            @elseif (($latestRebuild['status'] ?? null) === 'running')
                                Rebuild in progress since {{ $latestRebuild['started_at'] ?? '' }}…
                            @elseif (($latestRebuild['status'] ?? null) === 'completed')
                                Last rebuild ({{ $latestRebuild['completed_at'] ?? '' }}): created {{ $latestRebuild['created'] ?? 0 }},
                                skipped {{ $latestRebuild['skipped'] ?? 0 }} already-linked,
                                {{ $latestRebuild['needs_review'] ?? 0 }} need review.
                            @elseif (($latestRebuild['status'] ?? null) === 'failed')
                                <span class="text-danger-600">Last rebuild failed: {{ $latestRebuild['failure_reason'] ?? 'unknown error' }}</span>
                            @endif
                        </p>
                    @endif
                    @if ($rebuildPreview)
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            Preview: {{ collect($rebuildPreview['groups'])->where('outcome', 'would_create')->count() }} job folder(s)
                            would be recreated, {{ collect($rebuildPreview['groups'])->where('outcome', 'already_exists')->count() }}
                            already have a matching source.
                        </p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-filament::button
                        wire:click="previewRebuild"
                        wire:loading.attr="disabled"
                        wire:target="previewRebuild"
                        color="gray"
                    >
                        <span wire:loading.remove wire:target="previewRebuild">Preview rebuild (dry run)</span>
                        <span wire:loading wire:target="previewRebuild">Previewing…</span>
                    </x-filament::button>
                    <x-filament::button
                        wire:click="startRebuild"
                        wire:loading.attr="disabled"
                        wire:target="startRebuild"
                        wire:confirm="Rebuild the NBX catalog from Contabo storage? This creates new media_assets/media_sources rows for every recoverable job folder that has no matching database row. It does not modify or delete storage objects."
                    >
                        <span wire:loading.remove wire:target="startRebuild">Rebuild catalog from storage</span>
                        <span wire:loading wire:target="startRebuild">Queuing…</span>
                    </x-filament::button>
                </div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            @foreach (['role' => 'Storage by media role', 'layout' => 'Storage by key layout', 'lifecycle' => 'Storage by lifecycle'] as $breakdownKey => $heading)
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h2 class="font-semibold text-gray-950 dark:text-white">{{ $heading }}</h2>
                    <div class="mt-3 space-y-2">
                        @forelse ($breakdowns[$breakdownKey] ?? [] as $row)
                            <div class="flex items-center justify-between gap-3 border-b border-gray-100 py-2 text-xs last:border-0 dark:border-gray-800">
                                <span>{{ str_replace('_', ' ', $row['label']) }} <span class="text-gray-400">({{ number_format($row['objects']) }})</span></span>
                                <span class="font-medium">{{ number_format($row['bytes'] / 1073741824, 3) }} GB</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Run an inventory to calculate this breakdown.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <label class="text-sm font-medium text-gray-950 dark:text-white">
                    Prefix
                    <input wire:model="prefix" type="text" placeholder="Root, media/, videos/…" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-950" />
                </label>
                <label class="text-sm font-medium text-gray-950 dark:text-white">
                    Object or package
                    <input wire:model="search" type="search" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-950" />
                </label>
                <label class="text-sm font-medium text-gray-950 dark:text-white">
                    Media role
                    <select wire:model="role" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-950">
                        <option value="all">All roles</option>
                        <option value="source_original">Original</option>
                        <option value="faststart_mp4">Fast Start MP4</option>
                        <option value="hls_package">Any HLS package/object</option>
                        <option value="subtitle">Subtitle</option>
                        <option value="thumbnail">Thumbnail</option>
                        <option value="temporary">Temporary</option>
                        <option value="unknown">Unknown</option>
                    </select>
                </label>
                <label class="text-sm font-medium text-gray-950 dark:text-white">
                    Extension
                    <select wire:model="extension" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-950">
                        <option value="all">All extensions</option>
                        @foreach (['mp4', 'm4v', 'mov', 'mkv', 'webm', 'm3u8', 'ts', 'm4s', 'vtt', 'srt'] as $item)
                            <option value="{{ $item }}">{{ strtoupper($item) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-medium text-gray-950 dark:text-white">
                    Lifecycle
                    <select wire:model="association" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-950">
                        <option value="all">All packages</option>
                        <option value="managed">NBX managed</option>
                        <option value="portal">Portal-linked/candidate</option>
                        <option value="processing">Active processing</option>
                        <option value="failed_residue">Failed residue candidates</option>
                        <option value="duplicates">Duplicate signature candidates</option>
                        <option value="unresolved">Unresolved</option>
                    </select>
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button wire:click="applyFilters" wire:loading.attr="disabled" wire:target="applyFilters">Apply filters</x-filament::button>
                <x-filament::button wire:click="refreshObjects" color="gray">Refresh status</x-filament::button>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-left">Logical package</th>
                            <th class="px-4 py-3 text-left">Contents</th>
                            <th class="px-4 py-3 text-left">Size</th>
                            <th class="px-4 py-3 text-left">Ownership</th>
                            <th class="px-4 py-3 text-left">Lifecycle</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($objects as $object)
                            <tr wire:key="storage-package-{{ sha1($object['logical_asset_key']) }}">
                                <td class="max-w-md px-4 py-3">
                                    <div class="break-all font-medium text-gray-950 dark:text-white">{{ $object['logical_asset_key'] }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ str_replace('_', ' ', $object['storage_layout']) }} · {{ $object['last_modified'] ?: 'unknown date' }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <div>{{ number_format($object['object_count']) }} object(s)</div>
                                    @if ($object['hls_object_count'])
                                        <div>{{ number_format($object['hls_object_count']) }} HLS manifest/segment object(s)</div>
                                    @endif
                                    <div class="mt-1 text-gray-500">{{ implode(', ', array_map(fn ($role) => str_replace('_', ' ', $role), $object['media_roles'])) }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ number_format($object['size_bytes'] / 1073741824, 3) }} GB</td>
                                <td class="px-4 py-3 text-xs">
                                    <div>NBX asset: {{ $object['media_asset_id'] ?: '—' }}</div>
                                    <div>NBX source: {{ $object['media_source_id'] ?: '—' }}</div>
                                    <div>Portal: {{ $object['portal_sourceable_id'] ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <span class="font-semibold {{ in_array($object['classification'], ['unresolved', 'nbx_unresolved']) ? 'text-warning-600' : ($object['classification'] === 'failed_residue_candidate' ? 'text-danger-600' : 'text-success-600') }}">
                                        {{ str_replace('_', ' ', $object['classification']) }}
                                    </span>
                                    <div class="text-gray-500">{{ $object['confidence'] }} confidence</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        wire:click="requestCleanupReview(@js($object['logical_asset_key']))"
                                        wire:loading.attr="disabled"
                                        wire:target="requestCleanupReview"
                                        wire:confirm="Create a cleanup review plan for this entire package? No object will be deleted."
                                    >
                                        Review cleanup
                                    </x-filament::button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-gray-500">No indexed packages matched these filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                <x-filament::button wire:click="previousPage" color="gray" :disabled="$page <= 1">Previous</x-filament::button>
                <span class="text-xs text-gray-500">Page {{ $page }} of {{ $totalPages }} · {{ number_format($totalGroups) }} packages</span>
                <x-filament::button wire:click="nextPage" color="gray" :disabled="$page >= $totalPages">Next</x-filament::button>
            </div>
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Cleanup review plans</h2>
            <p class="mt-1 text-xs text-gray-500">Creating a plan never deletes data. Execution is intentionally unavailable until associations and replacements have been reviewed.</p>
            <div class="mt-3 space-y-2 text-sm">
                @forelse ($plans as $plan)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 last:border-0 dark:border-gray-800">
                        <a href="{{ $plan['url'] }}" class="font-medium text-primary-600 hover:underline">#{{ $plan['id'] }} · {{ $plan['logical_asset_key'] }} · {{ $plan['status'] }} · {{ $plan['risk_level'] }} risk</a>
                        <span class="text-xs text-gray-500">{{ number_format($plan['object_count']) }} objects · {{ number_format($plan['total_bytes'] / 1073741824, 3) }} GB · review after {{ $plan['grace_expires_at'] }}</span>
                    </div>
                @empty
                    <p class="text-gray-500">No cleanup reviews have been created.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
