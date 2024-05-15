<table class="w-full mt-4">
    <thead>
    <tr>
        <th class="@if(isset($display_thumbnail) && $display_thumbnail) w-24 @else w-8 @endif"></th>
        <th class="text-left">Filename</th>
        <th class="text-left">Size</th>
        <th class="text-right">Modified</th>
        @if(is_array($actions) && count($actions) > 0)
            <th class="text-right">Actions</th>
        @endif
        @if(isset($details) && $details)
            <th></th>
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
                $thumbnail_path = str_replace('originals', 'proofs', $thumbnail_path);
                $thumbnail_path = str_replace('.jpg', '_thm.jpg', $thumbnail_path);
                try{
                    $thumbnail_data = \Illuminate\Support\Facades\Storage::disk('fullsize')->get($thumbnail_path);
                    $thumbnail_base64 = \Intervention\Image\Laravel\Facades\Image::read($thumbnail_data)->toJpeg(90);
                    $thumbnail_base64 = 'data:image/jpeg;base64,'.base64_encode($thumbnail_base64);
                }catch(\Exception $e){
                    $display_thumbnail = false;
                }
            } else {
                $display_thumbnail = false;
            }
        @endphp
        <tr wire:key="{{ 'image-row-'.md5($filename) }}">
            <td>
                @if(isset($display_thumbnail) && $display_thumbnail)
                    <div class="p-1">
                        <img src="{{ $thumbnail_base64 }}" alt="{{ $filename }}" class="rounded-sm">
                    </div>
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-gray-700">
                        <path fill-rule="evenodd" d="M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0l-1.91 1.909.47.47a.75.75 0 1 1-1.06 1.06L6.53 8.091a.75.75 0 0 0-1.06 0l-2.97 2.97ZM12 7a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z" clip-rule="evenodd" />
                    </svg>
                @endif
            </td>
            <td>
                <div class="text-indigo-600">
                    {{ $filename }}
                </div>
            </td>
            <td class="text-sm">{{ $this->humanReadableFilesize($image_filesize) }}</td>
            <td class="text-right text-sm">{{ $modified->format('m/d/Y H:i:s') }}</td>
            @if(is_array($actions) && count($actions) > 0)
            <td class="text-right">
                @if(is_array($actions) && in_array('proof', $actions))
                    <button class="px-1 py-0.5 text-xs font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300 hover:bg-gray-300 hover:text-gray-800 hover:border-gray-400"
                            wire:click="proofImage('{{ $image_path }}')"
                    >Proof</button>
                @endif
                @if(is_array($actions) && in_array('import', $actions))
                    <button class="px-1 py-0.5 text-xs font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300 hover:bg-gray-300 hover:text-gray-800 hover:border-gray-400"
                            wire:click="processImage('{{ $image_path }}')"
                    >Import</button>
                @endif
            </td>
            @endif
            @if(isset($details) && $details)
                @php
                $image_obj = new \App\Proofgen\Image($image_path);
                $image_obj->checkForProofs();
                @endphp
                <td>
                    <div class="ml-2 flex flex-row justify-start items-center gap-x-2">
                        @if($image_obj->is_original)
                            <div class="text-xs font-semibold bg-blue-200 text-blue-800 rounded-sm border border-blue-300 px-1">Imported</div>
                        @endif
                        @if($image_obj->is_proofed)
                            <div class="text-xs font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300 px-1">Proofed</div>
                        @else
                            <div class="text-xs font-semibold bg-red-200 text-red-800 rounded-sm border border-red-300 px-1">Not Proofed</div>
                        @endif
                    </div>
                </td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table>
