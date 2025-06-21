<div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
    @if(isset($inputSettings))
        <div>
            <span class="font-medium">Settings:</span>
            <span class="font-mono">{{ $inputSettings['width'] }}×{{ $inputSettings['height'] }}px @ {{ $inputSettings['quality'] }}% quality</span>
        </div>
    @endif
    @if(isset($fileInfo))
        <div>
            <span class="font-medium">Output:</span>
            <span class="font-mono">{{ $fileInfo['dimensions'] }} • {{ $fileInfo['size'] }}</span>
        </div>
    @endif
    @if(isset($enhancementInfo) && $enhancementInfo)
        <div>
            <span class="font-medium">Enhanced:</span>
            <span>{{ $enhancementInfo['method_label'] }} {{ $enhancementInfo['parameters'] }}</span>
        </div>
    @endif
    @if(isset($processingTime))
        <div>
            <span class="font-medium">Processing:</span>
            <span class="font-mono">{{ number_format($processingTime * 1000, 0) }}ms</span>
        </div>
    @endif
</div>