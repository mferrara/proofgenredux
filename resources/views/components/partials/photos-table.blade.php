@php
    $allPhotoIds = $photos->pluck('id')->toArray();
    $photoCount = $photos->count();
    /** @var App\Services\PhotoThumbnailService $thumbnailService */
    $thumbnailService = app(App\Services\PhotoThumbnailService::class);
@endphp
<div class="w-full mt-4" x-data="{
    get selectAll() {
        return $wire.selectedPhotos.length === {{ $photoCount }};
    },
    toggleAll() {
        if ($wire.selectedPhotos.length === {{ $photoCount }}) {
            $wire.set('selectedPhotos', []);
        } else {
            $wire.set('selectedPhotos', @js($allPhotoIds));
        }
    },
    togglePhoto(id) {
        const selectedPhotos = [...$wire.selectedPhotos];
        const index = selectedPhotos.indexOf(id);
        if (index > -1) {
            selectedPhotos.splice(index, 1);
        } else {
            selectedPhotos.push(id);
        }
        $wire.set('selectedPhotos', selectedPhotos);
    }
}">
    {{-- Action bar --}}
    <div x-show="$wire.selectedPhotos.length > 0"
         x-transition
         x-cloak
         class="mb-4 px-4 py-3 rounded-lg flex flex-wrap items-center gap-3
                bg-zinc-100 dark:bg-white/[0.06]
                border border-zinc-200 dark:border-white/10">
        <span class="text-sm whitespace-nowrap text-zinc-900 dark:text-white">
            <span x-text="$wire.selectedPhotos.length"></span>
            <span x-text="$wire.selectedPhotos.length === 1 ? 'photo' : 'photos'"></span>
            selected
        </span>
        <div class="flex items-center gap-2">
            <flux:select wire:model="selectedAction" size="sm" class="w-44">
                <flux:select.option value="">Choose action…</flux:select.option>
                <flux:select.option value="move">Move to class…</flux:select.option>
                <flux:select.option value="delete">Delete photos</flux:select.option>
            </flux:select>
            <flux:button wire:click="performBulkAction" size="sm" variant="primary">Apply</flux:button>
        </div>
        <flux:button variant="ghost" size="sm" @click="$wire.set('selectedPhotos', [])" class="ml-auto">
            Clear selection
        </flux:button>
    </div>

    <flux:table hover>
        <thead>
            <tr>
                <th class="w-10 pl-4">
                    <input type="checkbox"
                           :checked="selectAll"
                           @change="toggleAll()"
                           class="rounded">
                </th>
                <th class="@if(isset($display_thumbnail) && $display_thumbnail) w-24 @else w-8 @endif"></th>
                <th>Proof Number</th>
                <th class="text-right pr-1">Metadata</th>
                <th class="text-right pr-1">Timestamps</th>
                @if(false)
                    <th class="text-right pr-1">Actions</th>
                @endif
                @if(isset($details) && $details)
                    <th>Status</th>
                @endif
            </tr>
        </thead>
        <tbody>
        @foreach($photos as $photo)
            @php
                $image_path = $photo->relative_path;
                /** @var App\Models\Photo $photo */
                $filename = explode('/', $image_path);
                $filename = end($filename);
                $file_modified = null;
                $file_not_found = false;
                try{
                    $file_modified = \Carbon\Carbon::createFromTimestamp(filemtime($photo->full_path))->format('m/d/Y H:i:s');
                }catch(\Exception $e) {
                    $file_not_found = true;
                }
                $shot_at = $photo->metadata?->exif_timestamp;
                $thumbnail_base64 = null;
                if(isset($display_thumbnail) && $display_thumbnail && $photo->proofs_generated_at) {
                    // Shared service reads the existing JPEG bytes and returns a cached
                    // data URI. It returns null (and caches nothing) when the file is
                    // missing, without mutating $display_thumbnail for later rows.
                    $thumbnail_base64 = $thumbnailService->dataUri($photo);
                }
            @endphp
            <tr wire:key="{{ 'image-row-'.$photo->id }}"
                :class="{
                    'bg-blue-50 dark:bg-blue-500/10': $wire.selectedPhotos.includes(@js($photo->id)),
                    'bg-rose-50 dark:bg-rose-500/10': {{ ! $photo->metadata || $file_not_found ? 'true' : 'false' }} && !$wire.selectedPhotos.includes(@js($photo->id))
                }">
                <td class="pl-4">
                    <input type="checkbox"
                           value="{{ $photo->id }}"
                           :checked="$wire.selectedPhotos.includes(@js($photo->id))"
                           @change="togglePhoto(@js($photo->id))"
                           class="rounded">
                </td>
                <td>
                    @if(isset($display_thumbnail) && $display_thumbnail && $thumbnail_base64 !== null)
                        @php
                            $sizeClass = 'size-44';
                            $wrapperWidth = 'w-48';
                            if(isset($thumbnailSize)) {
                                switch($thumbnailSize) {
                                    case 'medium':
                                        $sizeClass = 'size-56';
                                        $wrapperWidth = 'w-60';
                                        break;
                                    case 'large':
                                        $sizeClass = 'size-72';
                                        $wrapperWidth = 'w-76';
                                        break;
                                }
                            }
                        @endphp
                        <div class="p-1 {{ $wrapperWidth }}">
                            <button type="button"
                                    @click="$wire.showPhotoModal(@js($photo->id))"
                                    class="block hover:opacity-80 transition-opacity cursor-pointer">
                                <img src="{{ $thumbnail_base64 }}" alt="{{ $filename }}" class="rounded {{ $sizeClass }} object-cover">
                            </button>
                        </div>
                    @else
                        <button type="button"
                                @click="$wire.showPhotoModal(@js($photo->id))"
                                class="block hover:opacity-80 transition-opacity cursor-pointer">
                            <flux:icon name="photo" variant="outline" class="text-zinc-400 dark:text-zinc-600 size-32 mx-auto" />
                        </button>
                    @endif
                </td>
                <td>
                    <div class="ml-2 flex items-center gap-2">
                        <button type="button"
                                @click="$wire.showPhotoModal(@js($photo->id))"
                                class="font-mono font-medium text-zinc-900 dark:text-white hover:underline underline-offset-2 cursor-pointer">
                            {{ $photo->proof_number }}
                        </button>
                        @if($file_not_found)
                            <flux:badge color="rose" size="sm">File not found</flux:badge>
                        @endif
                    </div>
                </td>
                <td class="text-right pr-1 text-sm">
                    <div class="flex flex-col gap-y-1">
                        <div>
                            @if($photo->metadata)
                                <flux:badge color="zinc" size="sm">
                                    {{ $this->humanReadableFilesize($photo->metadata?->file_size) }}
                                </flux:badge>
                                <flux:badge color="zinc" size="sm">
                                    {{ $photo->metadata?->megapixels }}MP
                                </flux:badge>
                            @else
                                <flux:badge color="rose" size="sm">
                                    No Metadata
                                </flux:badge>
                                @if($file_not_found === false)
                                    <flux:button wire:click="fixMissingMetadataOnPhoto({{ \Illuminate\Support\Js::from($photo->id) }})" size="xs" class="ml-3 hover:cursor-pointer">
                                        Fix Metadata
                                    </flux:button>
                                @endif
                            @endif
                        </div>
                        <div>
                            @if($photo->metadata?->camera_model)
                                <flux:badge color="indigo" size="sm">
                                    {{ $photo->metadata->camera_model }}
                                </flux:badge>
                            @endif
                            @if($photo->metadata?->artist)
                                <flux:badge color="cyan" size="sm">
                                    {{ $photo->metadata->artist }}
                                </flux:badge>
                            @endif
                        </div>
                        <div>
                            @if($photo->metadata?->shutter_speed)
                                @if($photo->metadata->shutter_speed === '1/200')
                                    <flux:badge color="yellow" size="sm">
                                        {{ $photo->metadata->shutter_speed }}s
                                    </flux:badge>
                                @elseif($photo->metadata?->shutter_speed === '1/250')
                                    <flux:badge color="lime" size="sm">
                                        {{ $photo->metadata->shutter_speed }}s
                                    </flux:badge>
                                @elseif($photo->metadata?->shutter_speed === '1/320')
                                    <flux:badge color="green" size="sm">
                                        {{ $photo->metadata->shutter_speed }}s
                                    </flux:badge>
                                @elseif($photo->metadata?->shutter_speed === '1/400')
                                    <flux:badge color="emerald" size="sm">
                                        {{ $photo->metadata->shutter_speed }}s
                                    </flux:badge>
                                @elseif($photo->metadata?->shutter_speed === '1/500')
                                    <flux:badge color="teal" size="sm">
                                        {{ $photo->metadata->shutter_speed }}s
                                    </flux:badge>
                                @elseif(str_contains($photo->metadata?->shutter_speed, '/')
                                        && explode('/', $photo->metadata->shutter_speed)[1] < 200)
                                    <flux:badge color="rose" size="sm">
                                        {{ $photo->metadata->shutter_speed }}s
                                    </flux:badge>
                                @elseif(str_contains($photo->metadata?->shutter_speed, '/')
                                        && explode('/', $photo->metadata->shutter_speed)[1] > 500)
                                    <flux:badge color="cyan" size="sm">
                                        {{ $photo->metadata->shutter_speed }}s
                                    </flux:badge>
                                @endif
                            @endif
                            @if($photo->metadata?->aperture)
                                @if((float)$photo->metadata->aperture < 4)
                                    <flux:badge color="orange" size="sm">
                                        f/{{ $photo->metadata->aperture }}
                                    </flux:badge>
                                @elseif((float)$photo?->metadata->aperture < 4.5)
                                    <flux:badge color="yellow" size="sm">
                                        f/{{ $photo->metadata->aperture }}
                                    </flux:badge>
                                @elseif((float)$photo?->metadata->aperture < 8)
                                    <flux:badge color="green" size="sm">
                                        f/{{ $photo->metadata->aperture }}
                                    </flux:badge>
                                @endif
                            @endif
                            @if($photo->metadata?->aspect_ratio)
                                <flux:badge color="purple" size="sm">
                                    {{ $photo->metadata->aspect_ratio }}
                                </flux:badge>
                                <flux:badge color="pink" size="sm">
                                    {{ $photo->metadata->height }} x {{ $photo->metadata->width }}
                                </flux:badge>
                                <flux:badge color="blue" size="sm">
                                    @if($photo->metadata->orientation === 'la')
                                        Landscape
                                    @elseif($photo->metadata->orientation === 'po')
                                        Portrait
                                    @else
                                        Square
                                    @endif
                                </flux:badge>
                            @endif
                        </div>
                    </div>
                </td>
                <td class="text-right pr-1 text-sm">
                    <div class="flex flex-col gap-y-1 text-zinc-500 dark:text-zinc-500 text-xs">
                        @if($shot_at)
                            <div class="text-zinc-700 dark:text-zinc-300">
                                Photo Taken: {{ $photo->metadata->exif_timestamp->format('m/d/Y H:i:s') }}
                            </div>
                        @endif
                        <div>
                            Imported: {{ $photo->created_at->format('m/d/Y H:i:s') }}
                        </div>
                        <div>
                            @if( ! str_contains($photo->full_path, '.jpg'))
                                No ext on file
                            @else
                                @if($file_modified)
                                    Modified: {{ $file_modified }}
                                @endif
                            @endif
                        </div>
                        @if($photo->proofs_generated_at)
                            <div>
                                Proofs: {{ $photo->proofs_generated_at->format('m/d/Y H:i:s') }}
                            </div>
                        @endif
                        @if($photo->proofs_uploaded_at)
                            <div>
                                Proofs Uploaded: {{ $photo->proofs_uploaded_at->format('m/d/Y H:i:s') }}
                            </div>
                        @endif
                        @if($photo->web_image_generated_at)
                            <div>
                                Web: {{ $photo->web_image_generated_at->format('m/d/Y H:i:s') }}
                            </div>
                        @endif
                        @if($photo->web_image_uploaded_at)
                            <div>
                                Web Uploaded: {{ $photo->web_image_uploaded_at->format('m/d/Y H:i:s') }}
                            </div>
                        @endif
                        @if($photo->highres_image_generated_at)
                            <div>
                                Highres Generated: {{ $photo->highres_image_generated_at->format('m/d/Y H:i:s') }}
                            </div>
                        @endif
                        @if($photo->highres_image_uploaded_at)
                            <div>
                                Highres Uploaded: {{ $photo->highres_image_uploaded_at->format('m/d/Y H:i:s') }}
                            </div>
                        @endif
                    </div>
                </td>
                @if(false)
                    <td class="text-right pr-1">
                        <div class="my-0.5 grid grid-cols-1 gap-1">
                            @if(is_array($actions) && in_array('deletePhotoRecord', $actions) && (isset($show_delete) && $show_delete))
                                <div>
                                    <flux:button
                                        variant="danger"
                                        wire:click="deletePhotoRecord({{ \Illuminate\Support\Js::from($photo->id) }})"
                                        size="xs"
                                    >
                                        Delete
                                    </flux:button>
                                </div>
                            @endif
                            @if(is_array($actions) && in_array('deleteLocalProofs', $actions) && (isset($show_delete) && $show_delete && $photo->proofs_generated_at))
                                <div>
                                    <flux:button
                                        variant="danger"
                                        wire:click="deleteLocalProofs({{ \Illuminate\Support\Js::from($photo->id) }})"
                                        size="xs"
                                    >
                                        Delete Proofs
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    </td>
                @endif
                @if(isset($details) && $details)
                    <td>
                        <div class="flex flex-col justify-center items-center gap-y-1">
                            @if($photo->proofs_generated_at)
                                @if($photo->proofs_uploaded_at)
                                    <flux:badge color="emerald" size="sm">Proofs</flux:badge>
                                @else
                                    <flux:badge color="yellow" size="sm">Proofs Not Uploaded</flux:badge>
                                @endif
                            @else
                                <div class="flex flex-row items-center justify-start gap-x-1">
                                    <flux:badge color="rose" size="sm">No Proofs</flux:badge>
                                    <flux:button
                                        wire:click="proofPhoto({{ \Illuminate\Support\Js::from($photo->id) }})"
                                        :disabled="$queued_work['busy'] ?? false"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ \App\Services\QueuedWorkStatus::ACTION_TARGETS }}"
                                        size="xs"
                                        class="!px-0 hover:cursor-pointer"
                                    >
                                        <flux:badge color="cyan" size="sm">
                                            <flux:icon.play variant="micro"/>
                                        </flux:badge>
                                    </flux:button>
                                </div>
                            @endif
                            @if($photo->web_image_generated_at)
                                @if($photo->web_image_uploaded_at)
                                    <flux:badge color="emerald" size="sm">Web Image</flux:badge>
                                @else
                                    <flux:badge color="yellow" size="sm">Web Not Uploaded</flux:badge>
                                @endif
                            @else
                                <div class="flex flex-row items-center justify-start gap-x-1">
                                    <flux:badge color="rose" size="sm">No Web</flux:badge>
                                    <flux:button
                                        wire:click="generateWebImage({{ \Illuminate\Support\Js::from($photo->id) }})"
                                        :disabled="$queued_work['busy'] ?? false"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ \App\Services\QueuedWorkStatus::ACTION_TARGETS }}"
                                        size="xs"
                                        class="!px-0 hover:cursor-pointer"
                                    >
                                        <flux:badge color="cyan" size="sm">
                                            <flux:icon.play variant="micro"/>
                                        </flux:badge>
                                    </flux:button>
                                </div>
                            @endif
                            @if($photo->highres_image_generated_at)
                                @if($photo->highres_image_uploaded_at)
                                    <flux:badge color="emerald" size="sm">Highres Image</flux:badge>
                                @else
                                    <flux:badge color="yellow" size="sm">Highres Not Uploaded</flux:badge>
                                @endif
                            @elseif(config('proofgen.generate_highres_images.enabled', true))
                                <div class="flex flex-row items-center justify-start gap-x-1">
                                    <flux:badge color="rose" size="sm">No Highres</flux:badge>
                                    <flux:button
                                        wire:click="generateHighresImage({{ \Illuminate\Support\Js::from($photo->id) }})"
                                        :disabled="$queued_work['busy'] ?? false"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ \App\Services\QueuedWorkStatus::ACTION_TARGETS }}"
                                        size="xs"
                                        class="!px-0 hover:cursor-pointer"
                                    >
                                        <flux:badge color="cyan" size="sm">
                                            <flux:icon.play variant="micro"/>
                                        </flux:badge>
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </flux:table>
</div>
