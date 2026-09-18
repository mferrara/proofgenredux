<?php

namespace Tests\Feature;

use App\Livewire\ClassViewComponent;
use App\Livewire\HomeComponent;
use App\Livewire\ProofSearchComponent;
use App\Livewire\ShowViewComponent;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renders the owned views with awkward-but-honest names/paths/IDs and asserts
 * that the emitted JS/Livewire expressions still resolve back to the original
 * values.
 */
class FilenameActionRenderingTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);

        $this->tempPath = storage_path('app/filename_action_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);
        File::makeDirectory($this->tempPath.'/archive', 0755, true);

        config(['proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize']);
        config(['proofgen.archive_home_dir' => $this->tempPath.'/archive']);
        config(['filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/fullsize',
            'throw' => true,
        ]]);
        config(['filesystems.disks.archive' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/archive',
            'throw' => true,
        ]]);

        Storage::forgetDisk('fullsize');
        Storage::forgetDisk('archive');
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_home_create_show_from_directory_escapes_special_characters(): void
    {
        $folder = 'O\'Brien "Q" Show';
        Storage::disk('fullsize')->makeDirectory($folder);

        $html = Livewire::test(HomeComponent::class)->assertSuccessful()->html();

        $this->assertContains(
            $folder,
            $this->decodedStringArgumentsIn($html, 'wire:click', 'createShow')
        );
    }

    public function test_show_view_escapes_rename_state_process_button_and_open_folder(): void
    {
        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']));

        // A valid class folder with a pending image so the Import button renders.
        // (Quotes are no longer possible in a VALID name: the website rejects
        // them, so such a folder is offered a rename instead of an import.)
        Storage::disk('fullsize')->put('SHOW1/OBrien_Class/IMG_0001.jpg', 'pending');
        Storage::disk('fullsize')->makeDirectory("SHOW1/O'Brien_Class");
        // An invalid folder (double quote) so the inline rename x-data renders.
        Storage::disk('fullsize')->makeDirectory('SHOW1/legacy"folder\'name');

        $html = Livewire::test(ShowViewComponent::class, ['show_id' => 'SHOW1'])
            ->assertSuccessful()
            ->html();

        $importable = $this->decodedStringArgumentsIn($html, 'wire:click', 'processPendingClassImages');
        $this->assertContains('OBrien_Class', $importable);
        $this->assertNotContains("O'Brien_Class", $importable);

        $this->assertContains(
            $this->tempPath.'/fullsize/SHOW1',
            $this->decodedStringArgumentsIn($html, 'wire:click', 'openFolder')
        );

        $renameNames = $this->xDataValuesFor($html, 'newName:');

        $this->assertContains("O'Brien_Class", $renameNames);
        $this->assertContains('legacy"folder\'name', $renameNames);
    }

    public function test_path_rows_and_photo_ids_escape_quotes_and_backslashes(): void
    {
        $className = 'O\'Brien "Q" \\Class';
        $showClassId = 'SHOW1_'.$className;

        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']));
        ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => $showClassId,
            'show_id' => 'SHOW1',
            'name' => $className,
        ]));

        $photo = Photo::create([
            'show_class_id' => $showClassId,
            'proof_number' => '12\'3"4\\5',
            'file_type' => 'jpg',
            'proofs_generated_at' => now(),
        ]);

        $expectedClassPath = $this->tempPath.'/fullsize/SHOW1/'.$className;

        $component = Livewire::test(ClassViewComponent::class, [
            'show' => 'SHOW1',
            'class' => $className,
        ])->assertSuccessful();

        $listHtml = $component->html();

        $this->assertContains($expectedClassPath, $this->decodedStringArgumentsIn($listHtml, 'wire:click', 'openFolder'));
        $this->assertContains($expectedClassPath, $this->decodedStringArgumentsIn($listHtml, 'x-data', 'writeText'));
        $this->assertContains($photo->id, $this->decodedStringArgumentsIn($listHtml, '@click', 'showPhotoModal'));
        $this->assertContains($photo->id, $this->decodedStringArgumentsIn($listHtml, '@change', 'togglePhoto'));
        $this->assertContains($photo->id, $this->decodedStringArgumentsIn($listHtml, ':checked', 'includes'));

        $gridHtml = $component->set('viewMode', 'grid')->html();

        $this->assertContains($expectedClassPath, $this->decodedStringArgumentsIn($gridHtml, 'wire:click', 'openFolder'));
        $this->assertContains($photo->id, $this->decodedStringArgumentsIn($gridHtml, '@click', 'showPhotoModal'));
        $this->assertContains($photo->id, $this->decodedStringArgumentsIn($gridHtml, '@change', 'togglePhoto'));
        $this->assertContains($photo->id, $this->decodedStringArgumentsIn($gridHtml, ':checked', 'includes'));
    }

    public function test_search_result_select_id_escapes_quotes_and_backslashes(): void
    {
        $className = 'O\'Brien "Q" \\Class';
        $showClassId = 'SHOW1_'.$className;

        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']));
        ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => $showClassId,
            'show_id' => 'SHOW1',
            'name' => $className,
        ]));

        $photo = Photo::create([
            'show_class_id' => $showClassId,
            'proof_number' => '12\'3"4\\5',
            'file_type' => 'jpg',
        ]);

        $html = Livewire::test(ProofSearchComponent::class)
            ->set('query', '12\'3"4\\5')
            ->assertSuccessful()
            ->html();

        $this->assertContains(
            $photo->id,
            $this->decodedStringArgumentsIn($html, 'wire:click', 'selectProof')
        );
    }

    /**
     * Return every decoded JSON string argument passed to $function across all
     * occurrences of $attribute in the rendered HTML.
     */
    private function decodedStringArgumentsIn(string $html, string $attribute, string $function): array
    {
        $arguments = [];

        foreach ($this->attributeValues($html, $attribute) as $expression) {
            $decoded = $this->jsonStringAfter($expression, $function.'(');

            if ($decoded !== null) {
                $arguments[] = $decoded;
            }
        }

        return $arguments;
    }

    /**
     * Values assigned to `newName:` inside Alpine x-data objects.
     */
    private function xDataValuesFor(string $html, string $key): array
    {
        $values = [];

        foreach ($this->attributeValues($html, 'x-data') as $expression) {
            $decoded = $this->jsonStringAfter($expression, $key);

            if ($decoded !== null) {
                $values[] = $decoded;
            }
        }

        return $values;
    }

    /**
     * @return string[]
     */
    private function attributeValues(string $html, string $attribute): array
    {
        $pattern = '/\s'.preg_quote($attribute, '/').'="([^"]*)"/';

        preg_match_all($pattern, $html, $matches);

        return array_map(
            static fn (string $value) => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $matches[1]
        );
    }

    /**
     * Read the JSON string literal that follows $needle (skipping whitespace).
     */
    private function jsonStringAfter(string $haystack, string $needle): ?string
    {
        $position = strpos($haystack, $needle);

        if ($position === false) {
            return null;
        }

        $start = $position + strlen($needle);
        $length = strlen($haystack);

        while ($start < $length && ctype_space($haystack[$start])) {
            $start++;
        }

        if (! in_array($haystack[$start] ?? '', ["'", '"'], true)) {
            return null;
        }

        $quote = $haystack[$start];
        $index = $start + 1;
        $escaped = false;

        while ($index < $length) {
            $char = $haystack[$index];

            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === $quote) {
                break;
            }

            $index++;
        }

        if ($index >= $length) {
            return null;
        }

        $literal = substr($haystack, $start, $index - $start + 1);
        // Js::from wraps a scalar in single quotes; its body uses JSON escaping.
        if ($quote === "'") {
            $literal = '"'.substr($literal, 1, -1).'"';
        }
        $decoded = json_decode($literal, true);

        return is_string($decoded) ? $decoded : null;
    }
}
