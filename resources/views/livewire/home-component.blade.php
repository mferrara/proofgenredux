<div class="bg-gray-100 px-8 py-4">
    <div class="mt-4 ml-8 flex flex-col justify-start">
        <p class="mb-4 text-4xl font-semibold">Select Show</p>
        @foreach($current_path_directories as $directory)
            @php
                $images = $this->getImagesOfPath($directory);
                $folder_name = explode('/', $directory);
                $folder_name = end($folder_name);
                $images_to_process = false;
                if($show_directory && count($images)) {
                    $images_to_process = count($images);
                }
            @endphp
            <div class="pl-4 text-xl flex flex-row items-center gap-x-2">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-yellow-600">
                    <path d="M3.75 3A1.75 1.75 0 0 0 2 4.75v3.26a3.235 3.235 0 0 1 1.75-.51h12.5c.644 0 1.245.188 1.75.51V6.75A1.75 1.75 0 0 0 16.25 5h-4.836a.25.25 0 0 1-.177-.073L9.823 3.513A1.75 1.75 0 0 0 8.586 3H3.75ZM3.75 9A1.75 1.75 0 0 0 2 10.75v4.5c0 .966.784 1.75 1.75 1.75h12.5A1.75 1.75 0 0 0 18 15.25v-4.5A1.75 1.75 0 0 0 16.25 9H3.75Z" />
                </svg>
                <a href="/show/{{ $folder_name }}" class="underline text-indigo-600 hover:cursor-pointer">{{ $folder_name }}</a>
                <div>@if(count($images)) {{ count($images) }} images to process @endif</div>
            </div>
        @endforeach
    </div>
</div>
