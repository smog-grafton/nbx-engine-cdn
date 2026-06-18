<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Optimization Controls
        </x-slot>

        <x-slot name="description">
            Safe controls for clearing stale queued work and handing a small batch of pending media sources back to the existing optimization worker.
        </x-slot>

        <div class="flex flex-wrap gap-3">
            {{ $this->clearOptimizationQueueAction }}
            {{ $this->runPendingOptimizationsAction }}
        </div>

        <x-filament-actions::modals />
    </x-filament::section>
</x-filament-widgets::widget>
