@php
    $allPhotoIds = $photos->pluck('id')->toArray();
    $photoCount = $photos->count();
@endphp
<div class="w-full mt-4" x-data="{ 
    localSelectedPhotos: @js($selectedPhotos ?? []),
    get selectAll() {
        return this.localSelectedPhotos.length === {{ $photoCount }};
    },
    toggleAll() {
        if (this.localSelectedPhotos.length === {{ $photoCount }}) {
            this.localSelectedPhotos = [];
        } else {
            this.localSelectedPhotos = @js($allPhotoIds);
        }
        $wire.set('selectedPhotos', this.localSelectedPhotos);
    },
    togglePhoto(id) {
        const index = this.localSelectedPhotos.indexOf(id);
        if (index > -1) {
            this.localSelectedPhotos.splice(index, 1);
        } else {
            this.localSelectedPhotos.push(id);
        }
        $wire.set('selectedPhotos', this.localSelectedPhotos);
    }
}">
    {{-- Action bar --}}
    <div x-show="localSelectedPhotos.length > 0" 
         x-transition
         x-cloak
         class="mb-4 p-4 bg-gray-800 rounded-lg flex items-center gap-4">
        <span class="text-sm">
            <span x-text="localSelectedPhotos.length"></span> photos selected
        </span>
        <flux:select wire:model="selectedAction" size="sm">
            <flux:select.option value="">Choose action...</flux:select.option>
            <flux:select.option value="move">Move to class...</flux:select.option>
            <flux:select.option value="delete">Delete photos</flux:select.option>
        </flux:select>
        <flux:button 
            wire:click="performBulkAction" 
            size="sm"
        >
            Apply
        </flux:button>
        <flux:button 
            variant="ghost" 
            size="sm"
            @click="localSelectedPhotos = []; $wire.set('selectedPhotos', [])"
        >
            Clear selection
        </flux:button>
    </div>

    <flux:table class="!text-gray-300" hover>
        <thead>
            <tr>
                <th class="w-10">
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
                $modified = $photo->updated_at;
                $shot_at = $photo->metadata?->exif_timestamp;
                $thumbnail_path = '';
                $thumbnail_base64 = null;
                if(isset($display_thumbnail) && $display_thumbnail && $photo->proofs_generated_at) {
                    $display_thumbnail = true;
                    $thumbnail_path = $image_path;
                    $thumbnail_path = str_replace('originals/', '', $thumbnail_path);
                    $thumbnail_path = str_replace('.jpg', '_thm.jpg', $thumbnail_path);
                    $thumbnail_path = 'proofs/'.$thumbnail_path;

                    // Cache the base64 encoded thumbnail using the path and modified time as the
                    // cache key so that it's busted anytime the image is updated
                    $thumbnail_cache_key = 'thumbnails3-'.md5($thumbnail_path.$modified->timestamp);
                    if( ! $thumbnail_base64 = \Cache::get($thumbnail_cache_key, null)) {
                        $valid_thumbnail = false;
                        try{
                            $thumbnail_data = \Illuminate\Support\Facades\Storage::disk('fullsize')->get($thumbnail_path);
                            $thumbnail_base64 = \Intervention\Image\Laravel\Facades\Image::read($thumbnail_data)->toJpeg(90);
                            $thumbnail_base64 = 'data:image/jpeg;base64,'.base64_encode($thumbnail_base64);
                            $valid_thumbnail = true;
                        }catch(\Exception $e) {
                            Log::debug('Error with thumbnail: '.$e->getMessage());
                            $display_thumbnail = false;
                        }

                        // If we have a valid thumbnail, cache it for 1 hour
                        if ($valid_thumbnail) {
                            Cache::put($thumbnail_cache_key, $thumbnail_base64, now()->addMinutes(60));
                        }
                    }
                } else {
                    $thumbnail_base64 = null;
                }
            @endphp
            <tr wire:key="{{ 'image-row-'.$photo->id }}" class="@if( ! $photo->metadata || $file_not_found) bg-red-800/40 @endif">
                <td>
                    <input type="checkbox" 
                           value="{{ $photo->id }}"
                           :checked="localSelectedPhotos.includes('{{ $photo->id }}')"
                           @change="togglePhoto('{{ $photo->id }}')"
                           class="rounded">
                </td>
                <td>
                    @if(isset($display_thumbnail) && $display_thumbnail && $thumbnail_base64 !== null)
                        <div class="p-1 w-48">
                            <img src="{{ $thumbnail_base64 }}" alt="{{ $filename }}" class="rounded size-44 object-cover">
                        </div>
                    @else
                        <flux:icon name="photo" variant="outline" class="text-gray-400 size-32 mx-auto" />
                    @endif
                </td>
                <td>
                    <div class="ml-2 text-indigo-400 font-medium">
                        {{ $photo->proof_number }}
                        @if($file_not_found)
                            <flux:badge color="rose" size="sm">
                                File Not Found
                            </flux:badge>
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
                                    <flux:button wire:click="fixMissingMetadataOnPhoto('{{ $photo->id }}')" size="xs" class="ml-3 hover:cursor-pointer">
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
                    <div class="flex flex-col gap-y-1 text-gray-500 text-xs">
                        @if($shot_at)
                            <div class="text-gray-400">
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
                                        wire:click="deletePhotoRecord('{{ $photo->id }}')"
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
                                        wire:click="deleteLocalProofs('{{ $photo->id }}')"
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
                                        wire:click="proofPhoto('{{ $photo->id }}')"
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
                                        wire:click="generateWebImage('{{ $photo->id }}')"
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
                            @else
                                <div class="flex flex-row items-center justify-start gap-x-1">
                                    <flux:badge color="rose" size="sm">No Highres</flux:badge>
                                    <flux:button
                                        wire:click="generateHighresImage('{{ $photo->id }}')"
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
