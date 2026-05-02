@php
    $allPhotoIds = $photos->pluck('id')->toArray();
    $photoCount = $photos->count();
@endphp
<div class="w-full" x-data="{
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
        <flux:button variant="ghost" size="sm" @click="$wire.set('selectedPhotos', [])">
            Clear selection
        </flux:button>
        <label class="ml-auto inline-flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
            <flux:checkbox :checked="selectAll" @change="toggleAll()" />
            <span>Select all</span>
        </label>
    </div>

    {{-- Photo grid --}}
    @php
        $gridClass = 'grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-4';
        if(isset($thumbnailSize)) {
            switch($thumbnailSize) {
                case 'medium':
                    $gridClass = 'grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-4';
                    break;
                case 'large':
                    $gridClass = 'grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 2xl:grid-cols-4 gap-6';
                    break;
            }
        }
    @endphp
    <div class="{{ $gridClass }}">
        @foreach($photos as $photo)
            @php
                $image_path = $photo->relative_path;
                $filename = explode('/', $image_path);
                $filename = end($filename);
                $file_not_found = false;
                try{
                    $file_modified = \Carbon\Carbon::createFromTimestamp(filemtime($photo->full_path))->format('m/d/Y H:i:s');
                }catch(\Exception $e) {
                    $file_not_found = true;
                }
                $modified = $photo->updated_at;
                $thumbnail_path = '';
                $thumbnail_base64 = null;

                if($photo->proofs_generated_at) {
                    $thumbnail_path = $image_path;
                    $thumbnail_path = str_replace('originals/', '', $thumbnail_path);
                    $thumbnail_path = str_replace('.jpg', '_thm.jpg', $thumbnail_path);
                    $thumbnail_path = 'proofs/'.$thumbnail_path;

                    // Cache the base64 encoded thumbnail
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
                        }

                        // If we have a valid thumbnail, cache it for 1 hour
                        if ($valid_thumbnail) {
                            Cache::put($thumbnail_cache_key, $thumbnail_base64, now()->addMinutes(60));
                        }
                    }
                }
            @endphp
            <div wire:key="{{ 'grid-item-'.$photo->id }}"
                 class="relative group rounded-lg overflow-hidden transition-all duration-200
                        bg-zinc-100 dark:bg-zinc-800
                        border border-zinc-200 dark:border-white/10"
                 :class="{
                     'ring-2 ring-blue-500': $wire.selectedPhotos.includes('{{ $photo->id }}'),
                     'ring-2 ring-rose-500': {{ ! $photo->metadata || $file_not_found ? 'true' : 'false' }} && !$wire.selectedPhotos.includes('{{ $photo->id }}'),
                     'hover:ring-1 hover:ring-zinc-300 dark:hover:ring-zinc-500': !$wire.selectedPhotos.includes('{{ $photo->id }}')
                 }">
                {{-- Selection checkbox overlay --}}
                <div class="absolute top-2.5 left-2.5 z-30">
                    <input type="checkbox"
                           value="{{ $photo->id }}"
                           :checked="$wire.selectedPhotos.includes('{{ $photo->id }}')"
                           @change="togglePhoto('{{ $photo->id }}')"
                           class="rounded shadow-lg bg-white/90 dark:bg-zinc-900/90 border-zinc-300 dark:border-zinc-600 focus:ring-blue-500"
                           @click.stop>
                </div>

                {{-- Proof number overlay --}}
                <div class="absolute top-2.5 right-2.5 z-30">
                    <span class="font-mono text-xs px-2 py-0.5 rounded shadow-lg
                                 bg-zinc-900/85 text-white
                                 backdrop-blur-sm">
                        #{{ $photo->proof_number }}
                    </span>
                </div>

                {{-- Status badges overlay (moved to top area on hover) --}}
                <div class="absolute top-12 left-2 right-2 z-30 flex flex-wrap gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                    @if($photo->proofs_generated_at)
                        <flux:badge color="{{ $photo->proofs_uploaded_at ? 'emerald' : 'yellow' }}" size="xs">
                            P{{ $photo->proofs_uploaded_at ? '✓' : '' }}
                        </flux:badge>
                    @endif
                    @if($photo->web_image_generated_at)
                        <flux:badge color="{{ $photo->web_image_uploaded_at ? 'emerald' : 'yellow' }}" size="xs">
                            W{{ $photo->web_image_uploaded_at ? '✓' : '' }}
                        </flux:badge>
                    @endif
                    @if($photo->highres_image_generated_at)
                        <flux:badge color="{{ $photo->highres_image_uploaded_at ? 'emerald' : 'yellow' }}" size="xs">
                            H{{ $photo->highres_image_uploaded_at ? '✓' : '' }}
                        </flux:badge>
                    @endif
                    @if($file_not_found)
                        <flux:badge color="rose" size="xs">
                            File Not Found
                        </flux:badge>
                    @endif
                    @if(!$photo->metadata && !$file_not_found)
                        <flux:badge color="rose" size="xs">
                            No Metadata
                        </flux:badge>
                    @endif
                </div>

                {{-- Thumbnail image (clickable) --}}
                <button type="button"
                        @click="$wire.showPhotoModal('{{ $photo->id }}')"
                        class="block w-full aspect-square bg-zinc-200 dark:bg-zinc-900 cursor-pointer">
                    @if($thumbnail_base64 !== null)
                        <img src="{{ $thumbnail_base64 }}"
                             alt="{{ $filename }}"
                             class="w-full h-full object-cover">
                    @else
                        <div class="w-full h-full flex items-center justify-center">
                            <flux:icon name="photo" variant="outline" class="text-zinc-400 dark:text-zinc-700 size-16" />
                        </div>
                    @endif
                </button>

                {{-- Hover info overlay --}}
                <div class="absolute inset-0 z-20 opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none">
                    <div class="absolute inset-0 bg-gradient-to-t from-black via-black/70 to-transparent"></div>
                    <div class="absolute bottom-0 left-0 right-0 p-3 text-xs text-zinc-100">
                        @if($photo->metadata)
                            <div class="flex justify-between items-center mb-1">
                                <span class="font-medium">{{ $this->humanReadableFilesize($photo->metadata->file_size) }}</span>
                                <span class="font-medium">{{ $photo->metadata->megapixels }}MP</span>
                            </div>
                            @if($photo->metadata->camera_model)
                                <div class="truncate text-zinc-200">{{ $photo->metadata->camera_model }}</div>
                            @endif
                        @endif
                        <div class="text-zinc-200 mt-1">
                            {{ $photo->created_at->format('M d, H:i') }}
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
