<div class="w-full mt-4">
    <flux:table class="!text-gray-300" hover>
        <thead>
            <tr>
                <th class="@if(isset($display_thumbnail) && $display_thumbnail) w-24 @else w-8 @endif"></th>
                <th>Proof Number</th>
                <th class="text-right pr-1">Metadata</th>
                <th class="text-right pr-1">Timestamps</th>
                @if(is_array($actions) && count($actions) > 0)
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
                $modified = $photo->updated_at;
                $shot_at = $photo->metadata?->exif_timestamp;
                $thumbnail_path = '';
                if(isset($display_thumbnail) && $display_thumbnail) {
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
                            $display_thumbnail = false;
                        }

                        // If we have a valid thumbnail, cache it for 1 hour
                        if ($valid_thumbnail) {
                            Cache::put($thumbnail_cache_key, $thumbnail_base64, now()->addMinutes(60));
                        }
                    }
                } else {
                    $display_thumbnail = false;
                }
            @endphp
            <tr wire:key="{{ 'image-row-'.$photo->id }}" class="@if( ! $photo->metadata) bg-red-800/40 @endif">
                <td>
                    @if(isset($display_thumbnail) && $display_thumbnail)
                        <div class="p-1">
                            <img src="{{ $thumbnail_base64 }}" alt="{{ $filename }}" class="rounded size-24 object-cover">
                        </div>
                    @else
                        <flux:icon name="photo" variant="outline" class="text-gray-400" />
                    @endif
                </td>
                <td>
                    <div class="ml-2 text-indigo-400 font-medium">
                        {{ $photo->proof_number }}
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
                                <flux:button wire:click="fixMissingMetadataOnPhoto('{{ $photo->id }}')" size="xs" class="ml-3 hover:cursor-pointer">
                                    Fix Metadata
                                </flux:button>
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
                                Created: {{ $photo->metadata->exif_timestamp->format('m/d/Y H:i:s') }}
                            </div>
                        @endif
                        <div>
                            Imported: {{ $photo->created_at->format('m/d/Y H:i:s') }}
                        </div>
                        <div>
                            Modified: {{ $photo->updated_at->format('m/d/Y H:i:s') }}
                        </div>
                    </div>
                </td>
                @if(is_array($actions) && count($actions) > 0)
                    <td class="text-right pr-1">
                        <div class="my-0.5">
                            @if(is_array($actions) && in_array('proof', $actions))
                                <flux:button
                                    wire:click="proofImage('{{ $image_path }}')"
                                    size="xs"
                                >
                                    Proof
                                </flux:button>
                            @endif
                            @if(is_array($actions) && in_array('import', $actions))
                                <flux:button
                                    wire:click="processImage('{{ $image_path }}')"
                                    size="xs"
                                >
                                    Import
                                </flux:button>
                            @endif
                        </div>
                    </td>
                @endif
                @if(isset($details) && $details)
                    @php
                    $image_obj = new \App\Proofgen\Image($image_path);
                    $image_obj->checkForProofs();
                    @endphp
                    <td>
                        <div class="flex flex-row justify-center items-center gap-x-2">
                            @if($image_obj->is_original && ! $image_obj->is_proofed)
                                <flux:badge variant="solid" color="sky" size="sm">Imported</flux:badge>
                            @endif
                            @if($image_obj->is_proofed)
                                <flux:badge variant="solid" color="green" size="sm">Proofed</flux:badge>
                            @else
                                <flux:badge variant="solid" color="amber" size="sm">Not Proofed</flux:badge>
                            @endif
                        </div>
                    </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </flux:table>
</div>
