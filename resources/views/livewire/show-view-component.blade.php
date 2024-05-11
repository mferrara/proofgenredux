<div class="bg-gray-100 px-8 py-4">
    <div class="mt-4 flex flex-row justify-start items-center">
        <a href="/"
           class="mt-2 underline text-indigo-400 hover:cursor-pointer mb-2 ml-2"
           title="Back to Home"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                <path fill-rule="evenodd" d="M4.72 9.47a.75.75 0 0 0 0 1.06l4.25 4.25a.75.75 0 1 0 1.06-1.06L6.31 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L4.72 9.47Zm9.25-4.25L9.72 9.47a.75.75 0 0 0 0 1.06l4.25 4.25a.75.75 0 1 0 1.06-1.06L11.31 10l3.72-3.72a.75.75 0 0 0-1.06-1.06Z" clip-rule="evenodd" />
            </svg>
        </a>
        <div class="ml-2 text-2xl font-semibold">Show: <a href="/show/{{ $show }}" class="text-indigo-600">{{ $show }}</a></div>
    </div>
    <div class="mt-4 ml-8 flex flex-col justify-start">
        @if(count($current_path_directories) === 0)
            <div class="text-lg font-semibold">No directories in this path</div>
        @else
            <p class="mb-2 text-lg font-semibold">Class folders:</p>

            @foreach($class_folders as $class_folder_data)
                <div class="pl-4 flex flex-row items-center gap-x-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-yellow-600">
                        <path d="M3.75 3A1.75 1.75 0 0 0 2 4.75v3.26a3.235 3.235 0 0 1 1.75-.51h12.5c.644 0 1.245.188 1.75.51V6.75A1.75 1.75 0 0 0 16.25 5h-4.836a.25.25 0 0 1-.177-.073L9.823 3.513A1.75 1.75 0 0 0 8.586 3H3.75ZM3.75 9A1.75 1.75 0 0 0 2 10.75v4.5c0 .966.784 1.75 1.75 1.75h12.5A1.75 1.75 0 0 0 18 15.25v-4.5A1.75 1.75 0 0 0 16.25 9H3.75Z" />
                    </svg>
                    <a href="/show/{{ $show }}/class/{{ $class_folder_data['path'] }}"
                       class="underline text-indigo-600 hover:cursor-pointer"
                    >{{ $class_folder_data['path'] }}</a>
                    @if($class_folder_data['images_pending_processing_count'])<div> {{ $class_folder_data['images_pending_processing_count'] }} images to process</div> @endif
                    @if($class_folder_data['images_pending_proofing_count'])<div> {{ $class_folder_data['images_pending_proofing_count'] }} images pending proofing</div> @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
