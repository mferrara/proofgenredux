<div class="px-6 lg:px-10 py-6 max-w-[1400px] mx-auto">
    @php
        $hasImageSettings = false;
        $allImageConfigs = [];
        foreach(['thumbnails', 'web_images', 'highres_images'] as $cat) {
            if(isset($configurationsByCategory[$cat])) {
                $hasImageSettings = true;
                $allImageConfigs[$cat] = $configurationsByCategory[$cat];
            }
        }
        $hasEnhancement = isset($configurationsByCategory['enhancement']);
        $tailCategories = collect($configurationsByCategory)
            ->reject(fn ($_, $cat) => in_array($cat, ['thumbnails', 'web_images', 'highres_images', 'enhancement']))
            ->all();
    @endphp

    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="!text-3xl !font-semibold tracking-tight">Settings</flux:heading>
            <flux:text class="mt-1 max-w-xl">
                Configure how images are processed, enhanced, and uploaded — and manage system services.
            </flux:text>
        </div>
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" size="sm">
            <flux:radio value="light" icon="sun" />
            <flux:radio value="dark" icon="moon" />
            <flux:radio value="system" icon="computer-desktop" />
        </flux:radio.group>
    </div>

    <form wire:submit="save">
        {{-- Sticky section navigation --}}
        <nav x-data="{
                active: 'images',
                scrollTo(id) {
                    const el = document.getElementById(id);
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        this.active = id;
                    }
                },
                init() {
                    const sections = Array.from(document.querySelectorAll('section[data-section]'));
                    if (!sections.length) return;
                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach((entry) => {
                            if (entry.isIntersecting) {
                                this.active = entry.target.id;
                            }
                        });
                    }, { rootMargin: '-30% 0px -60% 0px', threshold: 0 });
                    sections.forEach((s) => observer.observe(s));
                }
             }"
             x-init="init()"
             class="sticky top-0 z-20 -mx-6 lg:-mx-10 px-6 lg:px-10 py-3 mb-8
                    bg-white/85 dark:bg-zinc-950/85 backdrop-blur-md
                    border-b border-zinc-200 dark:border-zinc-800">
            <div class="flex flex-wrap items-center gap-1.5 text-sm">
                @php
                    $navItems = collect();
                    if ($hasImageSettings) $navItems->push(['id' => 'images', 'label' => 'Images']);
                    if ($hasEnhancement) $navItems->push(['id' => 'enhancement', 'label' => 'Enhancement']);
                    if (PHP_OS_FAMILY === 'Darwin') $navItems->push(['id' => 'swift', 'label' => 'Swift Binaries']);
                    foreach ($tailCategories as $category => $_) {
                        $navItems->push([
                            'id' => 'cat-' . $category,
                            'label' => $categoryLabels[$category] ?? ucfirst($category),
                        ]);
                    }
                    $navItems->push(['id' => 'connector', 'label' => 'Website Connector']);
                    $navItems->push(['id' => 'services', 'label' => 'Services']);
                @endphp
                @foreach($navItems as $item)
                    <button type="button"
                            @click="scrollTo('{{ $item['id'] }}')"
                            :class="active === '{{ $item['id'] }}'
                                ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                                : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:text-white dark:hover:bg-zinc-800'"
                            class="px-3 py-1.5 rounded-full font-medium transition-colors">
                        {{ $item['label'] }}
                    </button>
                @endforeach
            </div>
        </nav>

        {{-- Image Settings --}}
        @if($hasImageSettings)
            @php
                $largeConfigs = collect($allImageConfigs['thumbnails'] ?? [])->filter(fn($c) => str_starts_with($c->key, 'thumbnails.large.'))->values();
                $smallConfigs = collect($allImageConfigs['thumbnails'] ?? [])->filter(fn($c) => str_starts_with($c->key, 'thumbnails.small.'))->values();
                $webConfigs = $allImageConfigs['web_images'] ?? [];
                $highresConfigs = $allImageConfigs['highres_images'] ?? [];
                $webEnabledConfig = collect($webConfigs)->firstWhere('key', 'generate_web_images.enabled');
                $highresEnabledConfig = collect($highresConfigs)->firstWhere('key', 'generate_highres_images.enabled');
            @endphp

            <section id="images" data-section class="scroll-mt-20 mb-14" x-data="{ activeTab: @entangle('activeTab') }">
                <div class="mb-4 flex items-center justify-between gap-4">
                    <div>
                        <flux:heading size="lg" level="2">Image Settings</flux:heading>
                        <flux:text class="mt-1">
                            Tweak dimensions and quality. Live previews regenerate as you change values — save to persist.
                        </flux:text>
                    </div>
                    <flux:button
                        type="button"
                        variant="ghost"
                        icon="arrow-down-tray"
                        wire:click="downloadSampleImages"
                        wire:loading.attr="disabled"
                        wire:target="downloadSampleImages"
                    >
                        <span wire:loading.remove wire:target="downloadSampleImages">Download Sample Images</span>
                        <span wire:loading wire:target="downloadSampleImages">Downloading…</span>
                    </flux:button>
                </div>

                @if(!$sampleImagePath)
                    <div class="mb-4 flex items-start justify-between gap-4 rounded-lg border border-amber-300/60 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10 px-4 py-3">
                        <div class="flex gap-3">
                            <flux:icon name="photo" class="size-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                            <div class="text-sm">
                                <div class="font-medium text-amber-900 dark:text-amber-200">No sample image available</div>
                                <div class="mt-0.5 text-amber-800/90 dark:text-amber-300/80">
                                    Add images to <code class="font-mono text-xs px-1 py-0.5 rounded bg-amber-100 dark:bg-amber-500/20">storage/sample_images</code>
                                    or click <span class="font-medium">Download Sample Images</span> above to fetch them from the bucket.
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Tabs --}}
                <div class="mb-6">
                    <flux:tabs variant="segmented">
                        <flux:tab name="large" wire:click="updateActiveTab('large')" x-bind:selected="activeTab === 'large'">Large Thumbnails</flux:tab>
                        <flux:tab name="small" wire:click="updateActiveTab('small')" x-bind:selected="activeTab === 'small'">Small Thumbnails</flux:tab>
                        <flux:tab name="web" wire:click="updateActiveTab('web')" x-bind:selected="activeTab === 'web'">Web Images</flux:tab>
                        <flux:tab name="highres" wire:click="updateActiveTab('highres')" x-bind:selected="activeTab === 'highres'">High Resolution</flux:tab>
                    </flux:tabs>
                </div>

                {{-- Watermark toggle (thumbnails only) --}}
                @if(in_array($activeTab, ['large', 'small']))
                    <div class="mb-4 flex items-center gap-3">
                        <flux:checkbox
                            wire:model.live="previewWatermarkEnabled"
                            id="preview-watermark"
                            label="Show watermarks on preview images"
                        />
                    </div>
                @endif

                {{-- Large --}}
                <div x-show="activeTab === 'large'" x-transition>
                    @include('livewire.partials.image-preview-card', [
                        'title' => 'Large Thumbnail Preview',
                        'previewError' => $previewErrors['large'] ?? null,
                        'preview' => $largeThumbnailPreview,
                        'unenhanced' => $largeThumbnailPreviewUnenhanced,
                        'info' => $largeThumbnailInfo,
                        'inputSettings' => $largeThumbnailInputSettings,
                        'enhancementInfo' => $largeThumbnailEnhancementInfo,
                        'processingTime' => $largeThumbnailProcessingTime,
                        'tempPath' => 'thumbnails.large',
                        'placeholderSize' => 'w-[600px] h-[600px]',
                        'showRegenerate' => true,
                        'sampleImagePath' => $sampleImagePath,
                        'enhancementOn' => $this->isEnhancementEnabledForCurrentTab(),
                    ])
                    @include('livewire.partials.settings-grid', [
                        'sectionTitle' => 'Large Thumbnail Settings',
                        'configs' => $largeConfigs,
                    ])
                </div>

                {{-- Small --}}
                <div x-show="activeTab === 'small'" x-transition>
                    @include('livewire.partials.image-preview-card', [
                        'title' => 'Small Thumbnail Preview',
                        'previewError' => $previewErrors['small'] ?? null,
                        'preview' => $smallThumbnailPreview,
                        'unenhanced' => $smallThumbnailPreviewUnenhanced,
                        'info' => $smallThumbnailInfo,
                        'inputSettings' => $smallThumbnailInputSettings,
                        'enhancementInfo' => $smallThumbnailEnhancementInfo,
                        'processingTime' => $smallThumbnailProcessingTime,
                        'tempPath' => 'thumbnails.small',
                        'placeholderSize' => 'w-[250px] h-[250px]',
                        'showRegenerate' => true,
                        'sampleImagePath' => $sampleImagePath,
                        'enhancementOn' => $this->isEnhancementEnabledForCurrentTab(),
                    ])
                    @include('livewire.partials.settings-grid', [
                        'sectionTitle' => 'Small Thumbnail Settings',
                        'configs' => $smallConfigs,
                    ])
                </div>

                {{-- Web --}}
                <div x-show="activeTab === 'web'" x-transition>
                    @include('livewire.partials.image-preview-card', [
                        'title' => 'Web Image Preview',
                        'previewError' => $previewErrors['web'] ?? null,
                        'preview' => $webImagePreview,
                        'unenhanced' => $webImagePreviewUnenhanced,
                        'info' => $webImageInfo,
                        'inputSettings' => $webImageInputSettings,
                        'enhancementInfo' => $webImageEnhancementInfo,
                        'processingTime' => $webImageProcessingTime,
                        'tempPath' => 'web_images',
                        'placeholderSize' => 'w-[600px] h-[600px]',
                        'showRegenerate' => false,
                        'sampleImagePath' => $sampleImagePath,
                        'enhancementOn' => $this->isEnhancementEnabledForCurrentTab(),
                    ])
                    @include('livewire.partials.settings-grid', [
                        'sectionTitle' => 'Web Image Settings',
                        'configs' => collect($webConfigs)->reject(fn($c) => $c->key === 'generate_web_images.enabled')->values(),
                        'enabledConfig' => $webEnabledConfig,
                    ])
                </div>

                {{-- Highres --}}
                <div x-show="activeTab === 'highres'" x-transition>
                    @include('livewire.partials.image-preview-card', [
                        'title' => 'High Resolution Image Preview',
                        'previewError' => $previewErrors['highres'] ?? null,
                        'preview' => $highresImagePreview,
                        'unenhanced' => $highresImagePreviewUnenhanced,
                        'info' => $highresImageInfo,
                        'inputSettings' => $highresImageInputSettings,
                        'enhancementInfo' => $highresImageEnhancementInfo,
                        'processingTime' => $highresImageProcessingTime,
                        'tempPath' => 'highres_images',
                        'placeholderSize' => 'w-[800px] h-[800px]',
                        'showRegenerate' => false,
                        'sampleImagePath' => $sampleImagePath,
                        'enhancementOn' => $this->isEnhancementEnabledForCurrentTab(),
                    ])
                    @include('livewire.partials.settings-grid', [
                        'sectionTitle' => 'High Resolution Image Settings',
                        'configs' => collect($highresConfigs)->reject(fn($c) => $c->key === 'generate_highres_images.enabled')->values(),
                        'enabledConfig' => $highresEnabledConfig,
                    ])
                </div>
            </section>
        @endif

        {{-- Enhancement --}}
        @if($hasEnhancement)
            <section id="enhancement" data-section class="scroll-mt-20 mb-14">
                @include('livewire.partials.enhancement-settings')
            </section>
        @endif

        {{-- Swift Binaries --}}
        @if(PHP_OS_FAMILY === 'Darwin')
            <section id="swift" data-section class="scroll-mt-20 mb-14">
                @include('livewire.partials.swift-binaries-settings')
            </section>
        @endif

        {{-- Other categories --}}
        @foreach($tailCategories as $category => $configurations)
            <section id="cat-{{ $category }}" data-section class="scroll-mt-20 mb-14">
                <flux:heading size="lg" level="2" class="mb-4">
                    {{ $categoryLabels[$category] ?? ucfirst($category) }}
                </flux:heading>

                <flux:card class="!p-0 overflow-hidden">
                    <div class="divide-y divide-zinc-200 dark:divide-white/10">
                        @foreach($configurations as $config)
                            <div class="px-5 py-4 grid grid-cols-12 gap-4 items-start">
                                <div class="col-span-12 sm:col-span-5">
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ $config->label ?? $config->key }}
                                    </div>
                                    @if($config->description)
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                            {{ $config->description }}
                                        </div>
                                    @endif
                                    @if(isset($configSources[$config->id]))
                                        <div class="mt-2">
                                            @if($configSources[$config->id] === 'database_override')
                                                <flux:badge color="indigo" size="sm" icon="bolt">Overrides .env</flux:badge>
                                            @elseif($configSources[$config->id] === 'same_in_both')
                                                <flux:badge color="emerald" size="sm" icon="check">Matches .env</flux:badge>
                                            @elseif($configSources[$config->id] === 'database_only')
                                                <flux:badge color="amber" size="sm" icon="exclamation-triangle">Database only</flux:badge>
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                <div class="col-span-12 sm:col-span-7">
                                    @if($config->is_private)
                                        <div class="text-sm text-zinc-500 italic">Hidden</div>
                                    @elseif($config->type === 'boolean')
                                        <flux:switch
                                            wire:model.live="configValues.{{ $config->id }}"
                                            wire:key="{{ $config->key }}-switch"
                                            :label="$config->value ? 'Enabled' : 'Disabled'"
                                        />
                                    @elseif($config->type === 'path')
                                        @php $pickerKind = $this->pathPickerKindFor($config->key); @endphp
                                        <div class="flex items-stretch gap-2 max-w-2xl">
                                            <flux:input
                                                type="text"
                                                wire:model.defer="configValues.{{ $config->id }}"
                                                wire:key="{{ $config->key }}-input"
                                                wire:dirty.class="!border-amber-400 dark:!border-amber-500"
                                                class="font-mono !text-sm flex-1"
                                                placeholder="{{ $pickerKind === 'file' ? '/path/to/file' : '/path/to/folder' }}"
                                            />
                                            @if($pickerKind === 'folder')
                                                <flux:button
                                                    type="button"
                                                    variant="ghost"
                                                    icon="folder-open"
                                                    wire:click="pickFolderForConfig({{ $config->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="pickFolderForConfig({{ $config->id }})"
                                                    title="Browse for folder…"
                                                >Browse…</flux:button>
                                            @else
                                                <flux:button
                                                    type="button"
                                                    variant="ghost"
                                                    icon="document"
                                                    wire:click="pickFileForConfig({{ $config->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="pickFileForConfig({{ $config->id }})"
                                                    title="Browse for file…"
                                                >Browse…</flux:button>
                                            @endif
                                        </div>
                                        @error('configValues.'.$config->id)
                                            <flux:error class="mt-1">{{ $message }}</flux:error>
                                        @enderror
                                    @elseif($config->type === 'integer' || $config->type === 'float')
                                        <flux:input
                                            type="number"
                                            step="{{ $config->type === 'integer' ? '1' : '0.01' }}"
                                            wire:model.defer="configValues.{{ $config->id }}"
                                            wire:key="{{ $config->key }}-input"
                                            wire:dirty.class="!border-amber-400 dark:!border-amber-500"
                                            class="font-mono w-32"
                                        />
                                        @error('configValues.'.$config->id)
                                            <flux:error class="mt-1">{{ $message }}</flux:error>
                                        @enderror
                                    @else
                                        @php $isRemotePath = $this->isRemotePathLike($config->key); @endphp
                                        <flux:input
                                            type="text"
                                            wire:model.defer="configValues.{{ $config->id }}"
                                            wire:key="{{ $config->key }}-input"
                                            wire:dirty.class="!border-amber-400 dark:!border-amber-500"
                                            class="{{ $isRemotePath ? 'font-mono !text-sm max-w-2xl' : 'max-w-md' }}"
                                            :placeholder="$isRemotePath ? '/home/forge/host/path' : null"
                                        />
                                        @if($isRemotePath)
                                            <flux:text class="!text-xs mt-1 text-zinc-500">Remote path on the ferraraphoto host — can't be browsed locally.</flux:text>
                                        @endif
                                        @error('configValues.'.$config->id)
                                            <flux:error class="mt-1">{{ $message }}</flux:error>
                                        @enderror
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            </section>
        @endforeach

        {{-- Website Connector: ferraraphoto integration health + per-show verifier --}}
        <section id="connector" data-section class="scroll-mt-20 mb-14">
            <div class="flex items-end justify-between gap-4 mb-4">
                <div>
                    <flux:heading size="lg" level="2">Website Connector</flux:heading>
                    <flux:text class="!text-sm mt-1 max-w-2xl">
                        Live health check for the ferraraphoto integration — uses the SFTP credentials configured above. See
                        <flux:link href="{{ route('server-connection') }}">/config/server</flux:link> for the standalone version.
                    </flux:text>
                </div>
                <flux:badge color="{{ config('proofgen.sftp.driver', 'sftp') === 'local' ? 'amber' : 'sky' }}" size="sm" icon="signal">
                    {{ strtoupper(config('proofgen.sftp.driver', 'sftp')) }} mode
                </flux:badge>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                {{-- Connection test --}}
                <flux:card>
                    <div class="flex items-center justify-between mb-3">
                        <flux:heading size="base">Connection</flux:heading>
                        @if($connectorTestResult === true)
                            <flux:badge color="emerald" size="sm" icon="check">Healthy</flux:badge>
                        @elseif($connectorTestResult === false)
                            <flux:badge color="rose" size="sm" icon="x-mark">Failed</flux:badge>
                        @endif
                    </div>

                    <dl class="space-y-1.5 text-sm mb-4">
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Driver</dt>
                            <dd class="font-mono text-zinc-700 dark:text-zinc-300">{{ config('proofgen.sftp.driver', 'sftp') }}</dd>
                        </div>
                        @if(config('proofgen.sftp.driver', 'sftp') !== 'local')
                            <div class="flex justify-between gap-3">
                                <dt class="text-zinc-500 dark:text-zinc-400">Host</dt>
                                <dd class="font-mono text-zinc-700 dark:text-zinc-300 truncate">{{ config('proofgen.sftp.host') ?: '—' }}<span class="text-zinc-400">:{{ config('proofgen.sftp.port', 22) }}</span></dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-zinc-500 dark:text-zinc-400">User</dt>
                                <dd class="font-mono text-zinc-700 dark:text-zinc-300">{{ config('proofgen.sftp.username') ?: '—' }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Proofs root</dt>
                            <dd class="font-mono !text-xs text-zinc-700 dark:text-zinc-300 truncate" title="{{ config('proofgen.sftp.path') ?: '—' }}">{{ config('proofgen.sftp.path') ?: '—' }}</dd>
                        </div>
                    </dl>

                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button
                            type="button"
                            variant="primary"
                            size="sm"
                            icon="signal"
                            wire:click="testConnectorConnection"
                            wire:loading.attr="disabled"
                            wire:target="testConnectorConnection"
                        >
                            <span wire:loading.remove wire:target="testConnectorConnection">Test connection</span>
                            <span wire:loading wire:target="testConnectorConnection">Testing…</span>
                        </flux:button>
                    </div>

                    @if($connectorTestOutput !== '')
                        <div class="mt-3 text-xs {{ $connectorTestResult ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                            {{ $connectorTestOutput }}
                        </div>
                    @endif

                    @if($connectorTestResult && count($connectorPathsFound) > 0)
                        <div class="mt-3">
                            <flux:text class="!text-xs mb-1.5 text-zinc-500 dark:text-zinc-400">Show directories found at proofs root:</flux:text>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($connectorPathsFound as $path)
                                    <flux:badge color="zinc" size="sm" icon="folder">{{ basename($path) }}</flux:badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </flux:card>

                {{-- Per-show verifier --}}
                <flux:card>
                    <div class="flex items-center justify-between mb-3">
                        <flux:heading size="base">Per-show check</flux:heading>
                    </div>

                    <flux:text class="!text-sm mb-3">
                        Verify a specific show has all three target directories (<code class="font-mono text-xs">proofs/</code>, <code class="font-mono text-xs">web_images/</code>, <code class="font-mono text-xs">highres_images/</code>) on the ferraraphoto host.
                    </flux:text>

                    <div class="flex items-stretch gap-2 mb-3">
                        <flux:input
                            type="text"
                            wire:model.defer="connectorShowToCheck"
                            placeholder="Show id (e.g. 22Buck)"
                            class="font-mono !text-sm flex-1"
                        />
                        <flux:button
                            type="button"
                            variant="primary"
                            size="sm"
                            icon="magnifying-glass"
                            wire:click="checkConnectorShow"
                            wire:loading.attr="disabled"
                            wire:target="checkConnectorShow"
                        >
                            <span wire:loading.remove wire:target="checkConnectorShow">Check</span>
                            <span wire:loading wire:target="checkConnectorShow">Checking…</span>
                        </flux:button>
                    </div>

                    @if($connectorShowStatus)
                        <dl class="space-y-1.5">
                            @foreach (['proofs' => 'Proofs', 'web_images' => 'Web', 'highres_images' => 'Highres'] as $key => $label)
                                @php $entry = $connectorShowStatus[$key]; @endphp
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-sm text-zinc-700 dark:text-zinc-300">{{ $label }}</dt>
                                    <dd>
                                        @if($entry['error'])
                                            <flux:badge color="rose" size="sm" title="{{ $entry['error'] }}">unreachable</flux:badge>
                                        @elseif($entry['exists'])
                                            <flux:badge color="emerald" size="sm" icon="check">ready</flux:badge>
                                        @else
                                            <flux:badge color="amber" size="sm">missing</flux:badge>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                        @if(! $connectorShowStatus['all_exist'] && ! $connectorShowStatus['any_errored'])
                            <flux:text class="!text-xs mt-3 text-amber-600 dark:text-amber-400">
                                Missing directories will be created by rsync on first upload, but the ferraraphoto admin won't see this show in the public site until they run "Import Classes."
                            </flux:text>
                        @endif
                    @endif
                </flux:card>
            </div>
        </section>

        {{-- Services: Updates + Background workers --}}
        <section id="services" wire:poll.5s="updateHorizonStatus" data-section class="scroll-mt-20 mb-14">
            <flux:heading size="lg" level="2" class="mb-4">Services</flux:heading>

            <div class="grid gap-6 md:grid-cols-2">
                {{-- Updates --}}
                <flux:card>
                    <div class="flex items-center justify-between mb-3">
                        <flux:heading size="base">Updates</flux:heading>
                        @if($checkingForUpdates)
                            <flux:icon.loading class="w-4 h-4 text-zinc-400" />
                        @elseif($updateInfo && $updateInfo['update_available'])
                            <flux:badge color="amber" size="sm">Update available</flux:badge>
                        @elseif($updateInfo)
                            <flux:badge color="emerald" size="sm" icon="check">Up to date</flux:badge>
                        @endif
                    </div>

                    @if($updateInfo)
                        <div class="text-sm text-zinc-600 dark:text-zinc-400 space-y-1">
                            <div>Current: <span class="font-mono text-zinc-900 dark:text-white">{{ $updateInfo['current_version'] ?? 'Unknown' }}</span></div>
                            @if($updateInfo['update_available'])
                                <div>Latest: <span class="font-mono text-amber-600 dark:text-amber-400">{{ $updateInfo['latest_version'] }}</span></div>
                            @endif
                        </div>

                        @if($updateInfo['update_available'])
                            <div class="mt-4 flex gap-2">
                                <flux:button
                                    type="button"
                                    variant="primary"
                                    icon="arrow-down-tray"
                                    wire:click="performUpdate"
                                    wire:loading.attr="disabled"
                                    wire:target="performUpdate"
                                >
                                    Update now
                                </flux:button>
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    icon="arrow-path"
                                    wire:click="checkForUpdates"
                                    wire:loading.attr="disabled"
                                    wire:target="checkForUpdates"
                                >
                                    Re-check
                                </flux:button>
                            </div>
                        @else
                            <div class="mt-4">
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    icon="arrow-path"
                                    wire:click="checkForUpdates"
                                    wire:loading.attr="disabled"
                                    wire:target="checkForUpdates"
                                >
                                    Check for updates
                                </flux:button>
                            </div>
                        @endif
                    @else
                        <flux:text>Checking for updates…</flux:text>
                    @endif
                </flux:card>

                {{-- Background workers (Horizon) --}}
                <flux:card>
                    <div class="flex items-center justify-between mb-3">
                        <flux:heading size="base">Background Workers</flux:heading>
                        @if($isHorizonRunning)
                            <flux:badge color="emerald" size="sm" icon="bolt">Running</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">Stopped</flux:badge>
                        @endif
                    </div>

                    @if($isHorizonRunning && isset($horizonProcessInfo['main_process']))
                        <dl class="grid grid-cols-3 gap-3 text-xs mb-4">
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-500">PID</dt>
                                <dd class="font-mono text-zinc-900 dark:text-white">{{ $horizonProcessInfo['main_process']['pid'] ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-500">CPU</dt>
                                <dd class="font-mono text-zinc-900 dark:text-white">{{ $horizonProcessInfo['main_process']['cpu'] ?? '—' }}%</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-500">Memory</dt>
                                <dd class="font-mono text-zinc-900 dark:text-white">{{ $horizonProcessInfo['main_process']['memory'] ?? '—' }}%</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-500">Supervisors</dt>
                                <dd class="font-mono text-zinc-900 dark:text-white">{{ $horizonProcessInfo['supervisor_count'] ?? 0 }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-500">Workers</dt>
                                <dd class="font-mono text-zinc-900 dark:text-white">{{ $horizonProcessInfo['worker_count'] ?? 0 }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-500">Total</dt>
                                <dd class="font-mono text-zinc-900 dark:text-white">{{ $horizonProcessInfo['total_processes'] ?? 0 }}</dd>
                            </div>
                        </dl>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        @if($isHorizonRunning)
                            <flux:button type="button" variant="ghost" icon="stop" wire:click="stopHorizon" wire:loading.attr="disabled" wire:target="stopHorizon">Stop</flux:button>
                            <flux:button type="button" variant="ghost" icon="arrow-path" wire:click="restartHorizon" wire:loading.attr="disabled" wire:target="restartHorizon">Restart</flux:button>
                            <flux:dropdown>
                                <flux:button type="button" variant="ghost" icon="exclamation-triangle">Force…</flux:button>
                                <flux:menu>
                                    <flux:menu.item
                                        wire:click="forceKillHorizon"
                                        wire:confirm="Force kill all Horizon processes? Use only if normal stop didn't work."
                                        icon="x-circle"
                                        variant="danger"
                                    >
                                        Force kill all processes
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        @else
                            <flux:button type="button" variant="primary" icon="play" wire:click="startHorizon" wire:loading.attr="disabled" wire:target="startHorizon">Start</flux:button>
                        @endif
                    </div>

                    <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-white/10">
                        <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400 cursor-pointer">
                            <flux:checkbox
                                id="auto_restart_horizon"
                                wire:model.live="configValues.{{ $this->getConfigId('auto_restart_horizon') }}"
                            />
                            <span>Auto-restart when settings change</span>
                        </label>
                    </div>
                </flux:card>

                {{-- Card access: why Herd has Full Disk Access, and whether it is working. --}}
                <flux:card class="!p-5 md:col-span-2">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <flux:heading size="base">Card access</flux:heading>
                            <flux:text class="mt-1 !text-sm">
                                macOS only lets an app read camera cards (and other drives plugged in later) when it has permission.
                                Proofgen runs inside <strong>Herd</strong>, so the permission belongs to Herd:
                                System Settings → Privacy &amp; Security → Full Disk Access → Herd. That is the only reason Herd is on that list.
                                After changing it, restart the background workers above. A Herd update can switch it off again.
                            </flux:text>
                        </div>
                        <a href="x-apple.systempreferences:com.apple.preference.security?Privacy_AllFiles">
                            <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square">Open the setting</flux:button>
                        </a>
                    </div>

                    <div class="mt-4 space-y-2">
                        @forelse ($cardAccess as $drive)
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="font-mono text-xs">{{ $drive['label'] }}</span>
                                @if ($drive['readable'])
                                    <flux:badge color="emerald" size="sm" icon="check">Proofgen can read it</flux:badge>
                                @else
                                    <flux:badge color="rose" size="sm" icon="lock-closed">Blocked by macOS — give Herd Full Disk Access</flux:badge>
                                @endif
                            </div>
                        @empty
                            <flux:text class="!text-sm text-zinc-500">
                                No card or external drive is plugged in right now. Put a card in a reader and reload this page to check.
                            </flux:text>
                        @endforelse
                    </div>
                </flux:card>
            </div>
        </section>

        {{-- Floating save bar. `wire:dirty` reveals it the instant something is
             typed; $hasUnsavedChanges keeps it up after a background request
             (the worker-status poll) has carried the typed values to the server,
             which is when Livewire stops calling the fields dirty. --}}
        <div wire:dirty.class.remove="translate-y-full opacity-0 pointer-events-none"
             wire:target="configValues"
             class="fixed bottom-0 left-0 right-0 z-30 transition-all duration-300
                    {{ $hasUnsavedChanges ? '' : 'translate-y-full opacity-0 pointer-events-none' }}">
            <div class="mx-auto max-w-3xl m-4 rounded-xl
                        bg-white/95 dark:bg-zinc-900/95 backdrop-blur
                        border border-zinc-200 dark:border-white/10
                        shadow-2xl shadow-black/20
                        px-5 py-3 flex items-center justify-between gap-4">
                <div class="flex items-center gap-2 text-sm">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                    </span>
                    <span class="font-medium text-zinc-900 dark:text-white">Unsaved changes</span>
                </div>
                <div class="flex items-center gap-2">
                    <flux:button type="button" variant="ghost" wire:click="cancel" wire:target="configValues">
                        Discard
                    </flux:button>
                    <flux:button type="submit" variant="primary" icon="check">
                        Save changes
                    </flux:button>
                </div>
            </div>
        </div>
    </form>

    {{-- Update Progress Modal --}}
    <flux:modal name="update-progress" class="max-w-2xl">
        <div class="space-y-6">
            <flux:heading size="lg">Application Update in Progress</flux:heading>

            @if($performingUpdate)
                <div class="flex items-center gap-3">
                    <flux:icon.loading class="w-5 h-5 text-blue-500" />
                    <flux:text>Updating application…</flux:text>
                </div>
            @endif

            <div class="bg-zinc-50 dark:bg-zinc-800 rounded-md p-4 max-h-96 overflow-y-auto border border-zinc-200 dark:border-white/10">
                <pre class="text-xs text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap font-mono">@foreach($updateSteps as $step){{ $step }}
@endforeach</pre>
            </div>

            @if(!$performingUpdate)
                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="primary">Close</flux:button>
                    </flux:modal.close>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- Rollback Instructions Modal --}}
    <flux:modal name="rollback-instructions" class="max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Update Failed — Rollback Instructions</flux:heading>
                <flux:text class="mt-2">A backup was created before the update. To restore:</flux:text>
            </div>

            <ol class="list-decimal list-inside space-y-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                <li>Navigate to your application directory</li>
                <li>Delete all contents EXCEPT the <code class="bg-zinc-100 dark:bg-zinc-800 px-1 py-0.5 rounded font-mono text-xs">/backups</code> directory</li>
                <li>Copy the contents of the most recent backup folder back</li>
                <li>Restart the application</li>
            </ol>

            @php $backups = $this->getBackups(); @endphp
            @if(count($backups) > 0)
                <div>
                    <flux:heading size="base" class="mb-2">Available Backups</flux:heading>
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-md p-3 space-y-1 border border-zinc-200 dark:border-white/10">
                        @foreach($backups as $backup)
                            <div class="text-xs text-zinc-600 dark:text-zinc-400 font-mono">
                                <span class="text-zinc-900 dark:text-white">{{ $backup['name'] }}</span>
                                — {{ $backup['date'] }} ({{ $backup['size'] }})
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary">Close</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>

<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('reload-page-delayed', () => {
            setTimeout(() => window.location.reload(), 5000);
        });
    });
</script>
