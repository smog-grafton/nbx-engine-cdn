<x-filament-panels::page>
    <div class="space-y-6">
        @if ($loadError)
            <div role="alert" class="rounded-xl bg-danger-50 p-5 text-danger-800 ring-1 ring-danger-600/20 dark:bg-danger-950/40 dark:text-danger-200 dark:ring-danger-500/30">
                <h2 class="font-semibold">Contabo storage is unavailable</h2>
                <p class="mt-1 text-sm">{{ $loadError }}</p>
                <p class="mt-2 text-xs opacity-80">
                    Configure the S3 key pair (or Contabo API fallback), set <code>AWS_EC2_METADATA_DISABLED=true</code>, then run <code>php artisan optimize:clear</code>.
                </p>
            </div>
        @endif

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <label class="text-sm font-medium text-gray-950 dark:text-white">
                    Prefix
                    <input wire:model="prefix" type="text" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-950" />
                </label>
                <label class="text-sm font-medium text-gray-950 dark:text-white">
                    Filename
                    <input wire:model="search" type="search" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-950" />
                </label>
                <label class="text-sm font-medium text-gray-950 dark:text-white">
                    Media role
                    <select wire:model="role" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-950">
                        <option value="all">All roles</option>
                        <option value="source_original">Original</option>
                        <option value="faststart_mp4">Fast Start MP4</option>
                        <option value="hls_master">HLS master</option>
                        <option value="hls_variant">HLS variant</option>
                        <option value="hls_segment">HLS segment</option>
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
                    Association
                    <select wire:model="association" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-950">
                        <option value="all">All objects</option>
                        <option value="portal">Portal-linked</option>
                        <option value="nbx">NBX-linked</option>
                        <option value="orphan">Orphan candidates</option>
                    </select>
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button wire:click="applyFilters">Apply filters</x-filament::button>
                <x-filament::button wire:click="refreshObjects" color="gray">Refresh</x-filament::button>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                Results are cursor-paginated directly from Contabo. Deletions are blocked while processing or when a verified replacement is required.
            </p>
        </div>

        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-left">Object</th>
                            <th class="px-4 py-3 text-left">Role</th>
                            <th class="px-4 py-3 text-left">Size</th>
                            <th class="px-4 py-3 text-left">Associations</th>
                            <th class="px-4 py-3 text-left">Modified</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($objects as $object)
                            <tr wire:key="storage-object-{{ sha1($object['key']) }}">
                                <td class="max-w-xl px-4 py-3">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $object['filename'] }}</div>
                                    <div class="break-all text-xs text-gray-500">{{ $object['key'] }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ str_replace('_', ' ', $object['media_role']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ number_format($object['size'] / 1048576, 2) }} MB</td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($object['orphaned'])
                                        <span class="font-semibold text-danger-600">Orphan candidate</span>
                                    @else
                                        <div>Portal: {{ implode(', ', $object['associated_portal_source_ids']) ?: '—' }}</div>
                                        <div>NBX source: {{ implode(', ', $object['associated_media_source_ids']) ?: '—' }}</div>
                                        <div>Job: {{ implode(', ', $object['processing_jobs']) ?: '—' }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs">{{ $object['last_modified'] ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <x-filament::button
                                        size="sm"
                                        color="danger"
                                        wire:click="deleteObject(@js($object['key']))"
                                        wire:confirm="Delete {{ $object['filename'] }}? NBX will verify replacements, reconcile Portal, disable downloads when this is the Fast Start MP4, and audit the action."
                                    >
                                        Delete safely
                                    </x-filament::button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-gray-500">No objects matched this page and filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                <x-filament::button wire:click="previousPage" color="gray" :disabled="empty($cursorHistory)">Previous</x-filament::button>
                <span class="text-xs text-gray-500">{{ count($objects) }} objects on this page</span>
                <x-filament::button wire:click="nextPage" color="gray" :disabled="!$nextCursor">Next</x-filament::button>
            </div>
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Recent storage actions</h2>
            <div class="mt-3 space-y-2 text-sm">
                @forelse ($audits as $audit)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 last:border-0 dark:border-gray-800">
                        <span>{{ str_replace('_', ' ', $audit['action']) }} · {{ $audit['status'] }}</span>
                        <span class="text-xs text-gray-500">
                            {{ number_format(($audit['bytes_freed'] ?? 0) / 1048576, 2) }} MB
                            @if ($audit['failure_reason'])
                                · {{ $audit['failure_reason'] }}
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="text-gray-500">No storage actions have been recorded.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
