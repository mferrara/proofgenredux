@php
    $rows = [
        ['label' => 'Imports', 'pending' => is_array($photos_pending_import) ? count($photos_pending_import) : 0, 'complete' => $photos_imported->count()],
        ['label' => 'Proofs generated', 'pending' => $photos_pending_proofs->count(), 'complete' => $photos_proofed->count(), 'indent' => false],
        ['label' => 'Proofs uploaded', 'pending' => $photos_pending_proof_uploads->count(), 'complete' => $photos_proofs_uploaded->count(), 'indent' => true],
        ['label' => 'Web images generated', 'pending' => $photos_pending_web_images->count(), 'complete' => $photos_web_images_generated->count(), 'indent' => false],
        ['label' => 'Web images uploaded', 'pending' => $photos_pending_web_image_uploads->count(), 'complete' => $photos_web_images_uploaded->count(), 'indent' => true],
        ['label' => 'Highres generated', 'pending' => $photos_pending_highres_images->count(), 'complete' => $photos_highres_images_generated->count(), 'indent' => false],
        ['label' => 'Highres uploaded', 'pending' => $photos_pending_highres_image_uploads->count(), 'complete' => $photos_highres_images_uploaded->count(), 'indent' => true],
    ];
@endphp

<div class="col-span-12 lg:col-span-5">
    <flux:card class="!p-0 overflow-hidden">
        <div class="px-5 py-3 border-b border-zinc-200 dark:border-white/10 bg-zinc-50/50 dark:bg-white/[0.02]">
            <flux:heading size="base">Processing Snapshot</flux:heading>
        </div>

        <div class="divide-y divide-zinc-200 dark:divide-white/10">
            <div class="px-5 py-2 grid grid-cols-12 gap-2 text-xs uppercase tracking-wider font-medium text-zinc-500 dark:text-zinc-400">
                <div class="col-span-6">Stage</div>
                <div class="col-span-3 text-right">Pending</div>
                <div class="col-span-3 text-right">Complete</div>
            </div>

            @foreach($rows as $row)
                <div class="px-5 py-2 grid grid-cols-12 gap-2 items-center text-sm">
                    <div class="col-span-6 {{ ($row['indent'] ?? false) ? 'pl-4 text-zinc-600 dark:text-zinc-400' : 'font-medium text-zinc-900 dark:text-white' }}">
                        {{ $row['label'] }}
                    </div>
                    <div class="col-span-3 text-right">
                        @if($row['pending'] > 0)
                            <flux:badge color="amber" size="sm">{{ number_format($row['pending']) }}</flux:badge>
                        @else
                            <span class="text-zinc-400 dark:text-zinc-600">—</span>
                        @endif
                    </div>
                    <div class="col-span-3 text-right">
                        @if($row['complete'] > 0)
                            <flux:badge color="emerald" size="sm">{{ number_format($row['complete']) }}</flux:badge>
                        @else
                            <span class="text-zinc-400 dark:text-zinc-600">—</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </flux:card>
</div>
