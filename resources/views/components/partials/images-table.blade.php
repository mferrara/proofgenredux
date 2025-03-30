<div class="w-full mt-4">
    <flux:table class="!text-gray-300" hover>
        <thead>
            <tr>
                <th class="@if(isset($display_thumbnail) && $display_thumbnail) w-24 @else w-8 @endif"></th>
                <th>Filename</th>
                <th class="text-right pr-1">Size</th>
                <th class="text-right pr-1">Modified</th>
                @if(is_array($actions) && count($actions) > 0)
                    <th class="text-right pr-1">Actions</th>
                @endif
                @if(isset($details) && $details)
                    <th>Status</th>
                @endif
            </tr>
        </thead>
        <tbody>
        @foreach($images as $image)
            @php
                if(is_string($image)) {
                    $image_path = \Illuminate\Support\Facades\Storage::disk('fullsize')->path($image);
                    $image_modified = \Illuminate\Support\Facades\Storage::disk('fullsize')->lastModified($image);
                    $image_filesize = \Illuminate\Support\Facades\Storage::disk('fullsize')->size($image);
                } else {
                    $image_path = $image->path();
                    $image_modified = $image->lastModified();
                    $image_filesize = $image->filesize();
                }
                $filename = explode('/', $image_path);
                $filename = end($filename);
                $modified = $image_modified;
                $modified = \Carbon\Carbon::createFromTimestamp($modified);
                $thumbnail_path = '';
                if(isset($display_thumbnail) && $display_thumbnail) {
                    $display_thumbnail = true;
                    $thumbnail_path = $image_path;
                    $thumbnail_path = str_replace('originals/', '', $thumbnail_path);
                    $thumbnail_path = str_replace('.jpg', '_thm.jpg', $thumbnail_path);
                    $thumbnail_path = 'proofs/'.$thumbnail_path;

                    // Cache the base64 encoded thumbnail using the path and modified time as the
                    // cache key so that it's busted anytime the image is updated
                    $thumbnail_cache_key = 'thumbnails3-'.md5($thumbnail_path.$image_modified);
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
            <tr wire:key="{{ 'image-row-'.md5($filename) }}">
                <td>
                    @if(isset($display_thumbnail) && $display_thumbnail)
                        <div class="p-1">
                            <img src="{{ $thumbnail_base64 }}" alt="{{ $filename }}" class="rounded size-16 object-cover">
                        </div>
                    @else
                        <flux:icon name="photo" variant="outline" class="text-gray-400" />
                    @endif
                </td>
                <td>
                    <span class="text-indigo-400 font-medium">
                        {{ $filename }}
                    </span>
                </td>
                <td class="text-right pr-1 text-sm">{{ $this->humanReadableFilesize($image_filesize) }}</td>
                <td class="text-right pr-1 text-sm">{{ $modified->format('m/d/Y H:i:s') }}</td>
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
