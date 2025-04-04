<div class="col-span-7">
    <flux:card>

        <div class="grid grid-cols-3 gap-6 mb-8">
            <div>
                <flux:heading size="xl" class="mb-4">Imports</flux:heading>

                <div class="flex flex-col gap-3 text-gray-400">
                    @if($photos_pending_import && count($photos_pending_import))
                        <div class="flex flex-col justify-start gap-y-4">
                            <div class="p-4 bg-gray-500/10 rounded-md">
                                <flux:badge color="amber" size="lg" inset="top bottom">{{ count($photos_pending_import) }}</flux:badge> pending
                            </div>
                            <div>
                                <flux:button
                                    wire:click="importPendingImages"
                                    size="sm"
                                >
                                    Import
                                </flux:button>
                            </div>
                        </div>
                    @else
                        <div class="p-4 bg-gray-500/10 rounded-md"><flux:badge color="gray" size="lg" inset="top bottom">0</flux:badge> pending</div>
                    @endif
                </div>
            </div>
            <div>
                <flux:heading size="xl" class="mb-4">Proofs</flux:heading>

                <div class="flex flex-col gap-3 text-gray-400">

                    @if($photos_pending_proofs->count())
                        <div class="flex flex-col justify-start gap-y-4">
                            <div class="p-4 bg-gray-500/10 rounded-md">
                                <flux:badge color="amber" size="lg" inset="top bottom">{{ $photos_pending_proofs->count() }}</flux:badge> pending
                            </div>
                            <div>
                                <flux:button
                                    wire:click="proofPendingPhotos"
                                    size="sm"
                                >
                                    Generate
                                </flux:button>
                            </div>
                        </div>
                    @else
                        <div class="p-4 bg-gray-500/10 rounded-md"><flux:badge color="gray" size="lg" inset="top bottom">0</flux:badge> pending</div>
                    @endif
                </div>
            </div>
            <div>
                <flux:heading size="xl" class="mb-4">Web Images</flux:heading>

                <div class="flex flex-col gap-3 text-gray-400">

                    @if($photos_pending_web_images->count())
                        <div class="flex flex-col justify-start gap-y-4">
                            <div class="p-4 bg-gray-500/10 rounded-md">
                                <flux:badge color="amber" size="lg" inset="top bottom">{{ $photos_pending_web_images->count() }}</flux:badge> pending
                            </div>
                            <div><flux:button
                                    wire:click="webImagePendingPhotos"
                                    size="sm"
                                >
                                    Generate
                                </flux:button>
                            </div>
                        </div>
                    @else
                        <div class="p-4 bg-gray-500/10 rounded-md"><flux:badge color="gray" size="lg" inset="top bottom">0</flux:badge> pending</div>
                    @endif
                </div>
            </div>
        </div>

        <flux:heading size="xl" class="my-4">Uploads</flux:heading>

        <div class="flex flex-col gap-3 text-gray-400">

            <div class="flex justify-between items-center ml-2 min-h-12 pl-2 py-1">
                <div x-data="{ expanded: false }"
                     class="flex flex-col gap-y-1">
                    <div class="flex items-center gap-x-2">
                        <div>Check Web Server Uploads (Proofs & Web Images)</div>
                        <div x-on:click="expanded = ! expanded"
                             class="text-gray-500 text-sm underline hover:cursor-pointer">
                            <span x-show="! expanded">(more info)</span>
                            <span x-show="expanded" x-cloak>(hide info)</span>
                        </div>
                    </div>
                    <div x-show="expanded"
                         x-cloak
                         class="pl-2 pr-6 text-sm text-gray-500">
                        Performs a "dry run" of the uploads to the web server and
                        updates the status of the files in the database. Does not upload anything. Fast.
                    </div>
                </div>

                <flux:button
                    wire:click="checkProofAndWebImageUploads"
                    size="sm"
                >
                    Start
                </flux:button>
            </div>

            @if($photos_pending_proof_uploads->count() || $photos_pending_web_image_uploads->count())
                <div class="flex justify-between items-center">
                    <span>
                        @if($photos_pending_proof_uploads->count())
                            <flux:badge color="amber" size="lg" inset="top bottom">{{ $photos_pending_proof_uploads->count() }}</flux:badge> Proofs
                        @endif
                        @if($photos_pending_web_image_uploads->count())
                            @if($photos_pending_proof_uploads->count())
                                and
                            @endif
                            <flux:badge color="amber" size="lg" inset="top bottom">{{ $photos_pending_web_image_uploads->count() }}</flux:badge> Web images
                        @endif
                        pending upload
                    </span>

                    <flux:button
                        wire:click="uploadPendingProofsAndWebImages"
                        size="sm"
                    >
                        Upload
                    </flux:button>
                </div>
            @else
                <div class="flex justify-between items-center ml-2 min-h-12 px-4 py-1 rounded-sm bg-emerald-400/40 text-emerald-200 font-semibold">
                    <div class="">
                        All generated proofs and web images are uploaded
                    </div>
                </div>
            @endif

            <div class="border-t border-gray-700 pt-3 mt-2">
                <flux:subheading>These operations will ignore any existing files and regenerate/force-upload everything that has been imported.</flux:subheading>
            </div>

            @if($photos_imported->count())
                <div class="flex justify-between items-center">
                    <span>Regenerate proofs on <flux:badge color="orange" size="sm" inset="top bottom">{{ $photos_imported->count() }}</flux:badge> photos</span>
                    <flux:button
                        wire:click="regenerateProofs"
                        size="sm"
                    >
                        Regenerate
                    </flux:button>
                </div>
                <div class="flex justify-between items-center">
                    <span>Regenerate web images on <flux:badge color="orange" size="sm" inset="top bottom">{{ $photos_imported->count() }}</flux:badge> photos</span>
                    <flux:button
                        wire:click="regenerateWebImages"
                        size="sm"
                    >
                        Regenerate
                    </flux:button>
                </div>
            @endif

            @if($photos_imported->count() - $photos_pending_proofs->count() > 0)
                <div class="flex justify-between items-center">
                    <span>Upload all <flux:badge color="orange" size="sm" inset="top bottom">{{ $photos_imported->count() - $photos_pending_proofs->count() }}</flux:badge> images</span>
                    <flux:button
                        wire:click="uploadPendingProofsAndWebImages"
                        size="sm"
                    >
                        Upload All
                    </flux:button>
                </div>
            @endif

            @if($photos_imported->count())
                <div class="flex justify-between items-center">
                    <span>Reset all <flux:badge color="orange" size="sm" inset="top bottom">{{ $photos_imported->count() }}</flux:badge> imported photos</span>
                    <flux:button
                        wire:confirm="Are you sure you want to reset all imported photos? This will delete all generated proofs and web images, move images back into the import folder and delete all database records."
                        wire:click="resetPhotos"
                        size="sm"
                    >
                        Reset
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:card>
</div>
