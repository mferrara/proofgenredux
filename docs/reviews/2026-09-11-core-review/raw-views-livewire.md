# Proofgen Redux — UI/View/Component Lane Review

Snapshot: `main 11c6e80`. Read-only. No tests, app, or network execution was performed; all observations are from source. Priorities reflect owner/dad horse-show usability, not adversarial security.

## Scope covered
- `app/Livewire/`: `ShowViewComponent`, `ClassViewComponent`, `HomeComponent`, `ConfigComponent`, `AppStatusBar`, `GraveyardComponent`, `ProofSearchComponent`, `PhotoIssuesComponent` (handler tracing only).
- `resources/views/livewire/*` and `resources/views/livewire/partials/*`.
- `resources/views/components/partials/{photo-process-status-table,action-panel,photos-table,photos-grid,images-table}.blade.php`.
- `resources/views/{layouts,components/layouts}/app.blade.php`, `navigation-menu.blade.php`.
- `resources/js/app.js`, `resources/js/bootstrap.js`, `resources/css/app.css`, `config/livewire.php`.
- Followed into `app/Proofgen/ShowClass.php`, `app/Proofgen/Utility.php`, `app/Traits/HasPhotosTrait.php`, `app/Helpers/DirectoryNameValidator.php` only to confirm call cost/semantics.

Not covered (other lanes/parent): processing math, upload/rsync, archive safety, migration/S3, updater. `external-docs/fluxui/index.md` absent; no Flux API claims made.

---

## Findings

### P1 — Show page polls every 5s and re-walks the filesystem + runs ~O(classes) queries each time
- Files: `resources/views/livewire/show-view-component.blade.php:1` (`wire:poll.5s`); `app/Livewire/ShowViewComponent.php:232, 264-266, 295-309`; `resources/views/components/partials/photo-process-status-table.blade.php:3-11`; `resources/views/components/partials/action-panel.blade.php:2-11`.
- Trigger: any user simply leaving a show page open. `render()` is intentionally read-only but not cheap.
- Observed code behavior per render: `Utility::getDirectoriesOfPath()` (`ShowViewComponent.php:232`); `$this->show->getImagesPendingImport()` (`:295`) which calls `Utility::getContentsOfPath($relative_path, true)` — a **recursive** `listContents` over the whole show tree (`Show.php:332-334`, `Utility.php:24-26`); and for each class, `getImagesPendingProcessing()`, `getImagesPendingWeb()`, `getImportedImages()` (`ShowViewComponent.php:264-266`), each of which lists the class/originals/proofs/web dirs (`ShowClass.php:87-196`). The two status partials then call `->count()` on every show-level builder (`photo-process-status-table` 14 counts, `action-panel` re-counts 7 of the same builders) — these are re-executed queries, not memoized — plus the class table runs 7 counts per class in Blade (`show-view-component.blade.php:159-169`).
- Impact (source-only hypothesis): with ~20 classes this is roughly 100 filesystem listings + ~150 SQL COUNTs every 5 seconds per open tab, on the dad's MacBook, exactly while imports are running. This is the single largest continuous UI cost in the lane.
- Smallest fix: keep the live progress but stop recomputing everything each poll. E.g. cache the filesystem-derived counts behind a short keyed cache (5-15s) invalidated by the import/generate/reset actions, compute `getImagesPendingImport()` only in `mount()` and refresh on the explicit Import action, and/or raise `wire:poll` to `.15s`/`.30s`. Also derive per-class status from one aggregate query instead of 7 per class.
- Verification: open a show with several classes and images and compare request time/query count for two consecutive polls (Laravel debugbar or `DB::listen`); confirm the list/recursive calls fire once per poll today.

### P1 — Class page polls every 5s and reloads the entire photo collection, then `filemtime`s every photo
- Files: `resources/views/livewire/class-view-component.blade.php:1` (`wire:poll.5s`); `app/Livewire/ClassViewComponent.php:158` (`->photos()->with('metadata')->get()`); `resources/views/components/partials/photos-table.blade.php:82-88` and `photos-grid.blade.php:101-107` (per-photo `filemtime`).
- Trigger: leaving a class page open.
- Observed behavior: every 5s Livewire re-renders the component, which hydrates all Photo models + metadata for the class into memory, rebuilds all thumbnails' base64 HTML, and executes one `filemtime()` filesystem stat per photo (plus `Cache::get` per thumbnail).
- Impact: for a few thousand imported photos this is heavy on both PHP memory and network payload; the 5s cadence makes it continuous while operators work. The author deliberately converted several counts to SQL, but the largest load (the full `get()`) was left.
- Smallest fix: paginate or lazy-load the imported-photo table (e.g. `->paginate(100)` with a "load more"), and/or only poll the status/counters rather than the photo list (split status into a polled child component, or gate polling with `wire:poll` only when an import/regenerate is in flight).
- Verification: measure render time and response size on a class with ~2k photos; confirm `$photos->count()` drives a full SELECT inside the current request.

### P2 — A single missing thumbnail blanks out thumbnails for every later row
- File: `resources/views/components/partials/photos-table.blade.php:92,110` (same pattern in `resources/views/components/partials/images-table.blade.php:35,52,61`).
- Trigger: a photo has `proofs_generated_at` set but its `proofs/...\_thm.jpg` file is missing/unreadable. The `catch` sets `$display_thumbnail = false`.
- Observed behavior: `$display_thumbnail` is passed by `@include` and then mutated inside the `@foreach`; once `false`, the guard `@if(isset($display_thumbnail) && $display_thumbnail && ...)` at line 135 is false for every subsequent iteration, so the rest of the table renders placeholders even though their thumbnails exist.
- Impact: one bad/orphan original makes an entire class page appear to have no proofs until reload/repair — misleading during a show.
- Smallest fix: use a per-row local (e.g. `$show_thumb = isset($display_thumbnail) && $display_thumbnail && ...`) and never reassign `$display_thumbnail` inside the loop.
- Verification: with one class, delete a single `_thm.jpg` from the proofs tree and confirm only that row loses its thumbnail (today all later rows lose theirs).

### P2 — Proof search redirect splits `show_class_id` on the first underscore; show ids may contain underscores
- File: `app/Livewire/ProofSearchComponent.php:66-73` (`explode('_', $photo->show_class_id, 2)` and `/show/{show}/class/{class}`); mirrored in `resources/views/livewire/proof-search-component.blade.php:45-48`.
- Trigger: show id containing an underscore, e.g. `2023_R41` with class `121` → `show_class_id = 2023_R41_121`. Any show id with `_` passes `HomeComponent::createShow` validation (`HomeComponent.php:125`, pattern `[A-Za-z0-9_\-]+`).
- Observed behavior: `explode(..., 2)` yields `['2023', 'R41_121']`, so the redirect points at show `2023`, class `R41_121` (a non-existent class), instead of the correct page.
- Impact: search result navigation silently lands on a wrong/404 page; degrades a core "find this proof now" workflow. The code comment only considers underscores in class names, not show ids.
- Smallest fix: resolve via the model — `$class = \App\Models\ShowClass::find($photo->show_class_id); return redirect()->to('/show/'.$class->show_id.'/class/'.$class->name);` — and use the same relation in the dropdown labels.
- Verification: create a show id with an underscore, import one photo, search its proof number, and confirm the redirect target.

### P2 — Home page eager-loads every photo of every show just to display counts
- File: `app/Livewire/HomeComponent.php:61` (`Show::with('photos')->find($directory_path)`); consumed at `resources/views/livewire/home-component.blade.php:143-153` (`$show->photos->count()`, `$show->classes()->count()` twice).
- Trigger: opening `/` with a season's worth of shows/photos.
- Observed behavior: `with('photos')` hydrates the full `photos` relation for every top-level show folder into memory, only for the view to call `->count()` on the collection. `$show->classes()->count()` is also executed twice per show in the same Blade block.
- Impact: avoidable peak memory and query time proportional to total photos across all shows; worst on the machine with the largest library.
- Smallest fix: use `Show::withCount(['photos','classes'])->find(...)` and read `$show->photos_count` / `$show->classes_count`, or a single grouped aggregate query for all visible shows.
- Verification: open home with a multi-show library and compare `EXPLAIN`/memory; confirm the current query selects all photo columns.

### P2 — Filesystem names interpolated into Alpine/`wire:click` JS strings without escaping
- Files: `resources/views/livewire/show-view-component.blade.php:112-115` (invalid-name `x-data` `newName: '{{ ... }}'`, `originalName: '{{ ... }}'`) and `:206-209` (valid-name rename `x-data`); `resources/views/livewire/home-component.blade.php:143` uses raw `{!! $folder_name !!}` inside `wire:click="createShow('...')"`; `resources/views/livewire/partials/path-row.blade.php:58` and `class-view-component.blade.php:60` interpolate full paths into `wire:click`.
- Trigger: a class/show folder whose name contains an apostrophe (valid per `DirectoryNameValidator::isValid` — `'` is not in its invalid set) or a space/quote (the invalid-name branch exists precisely for these names).
- Observed behavior: `{{ }}` HTML-escapes `'` to `&#039;`; the HTML parser decodes it back to `'` inside the single-quoted Alpine/`wire:click` expression, terminating the string and producing a JS syntax error for that subtree (rename UI stops working; `{!! !!}` on home is additionally unescaped).
- Impact: rename/import affordances break exactly on the odd folder names the validator is meant to help with; home "Import" button breaks for the same names.
- Smallest fix: emit JS literals with `@js(...)` (already the established pattern in `photos-table.blade.php:15`) for all of these, e.g. `x-data="{ newName: @js($class_folder_data['path']) }"`, and replace `{!! $folder_name !!}` with `@js($folder_name)`.
- Verification: create a class folder `Dad'sClass` (no spaces) and a `Bad Name` folder, open the show page, and confirm no console error and that Rename/Import are usable.

### P3 — Class page dereferences a missing `ShowClass` on boot/hydrate
- File: `app/Livewire/ClassViewComponent.php:114-124` (`ShowClass::with('photos')->find(...)` then immediately `$this->showClass->importExistingPhotosFromOriginalsDirectory()`); also `:158` in `render()`.
- Trigger: opening a stale class URL, or renaming/deleting the class from the show page while a class tab is open (the 5s poll calls `boot()` → `loadShowClass()` again).
- Observed behavior: `find()` returns `null` and the very next method call fatals; there is no 404/guard path.
- Impact: 500 errors and a dead tab instead of a clear "class not found" message during a rename or after cleanup.
- Smallest fix: if `find()` returns null, `abort(404, 'Class not found')` (or set an error state and skip the syncs/render).
- Verification: navigate to `/show/{show}/class/does-not-exist` and confirm a 404 rather than an exception page.

### P3 — `AppStatusBar` registers `config-updated` twice (framework-upgrade leftover)
- File: `app/Livewire/AppStatusBar.php:17-25` — both `protected $listeners = ['config-updated' => 'reload']` and `#[On('config-updated')] public function reload()`.
- Trigger: saving Settings (`ConfigComponent.php:711` dispatches `->to(AppStatusBar::class)`).
- Observed behavior: legacy `$listeners` and the attribute listener target the same event/method. If Livewire 4 still honors `$listeners`, `reload()` runs twice per save (currently only logs, so harmless); if it dropped `$listeners`, only the attribute fires. Marked uncertain — I could not verify Livewire 4's listener behavior in this snapshot (no `vendor/`).
- Impact: low today; confusing for maintenance and a duplicate-call hazard if `reload()` ever does real work.
- Smallest fix: delete the `$listeners` array entry and keep the `#[On]` attribute.
- Verification: with a debug log in `reload()`, save settings and count invocations once vendor is available.

---

## Consolidation opportunities (only where duplicate paths are explicit)

1. **Thumbnail base64 loading is copied three times.** `photos-table.blade.php:91-118`, `photos-grid.blade.php:110-138`, and `images-table.blade.php:34-63` each reimplement the same "build `_thm.jpg` path → cache-key → read from `fullsize` → Intervention `toJpeg` → base64 data URI → 1h cache" block, with drift: the table/grid key on `md5($path.$modified->timestamp)` while images-table keys on `md5($path.$image_modified)` (raw value), and only the tables mutate `$display_thumbnail`. Practical benefit: one helper (Blade component or a small service/`@php` include) fixes the P2 stale-flag bug in all three and makes the cache key consistent, so the same thumbnail isn't decoded twice under different keys.
2. **Two near-duplicate app layouts.** `resources/views/layouts/app.blade.php` (rendered by `App\View\Components\AppLayout`, used by `dashboard`, `api/index`, `profile/show`) and `resources/views/components/layouts/app.blade.php` (Livewire's default layout) are ~90% identical but diverge: only the Livewire one includes `<livewire:app-status-bar />`, footer/version, and `<flux:toast />`. Practical benefit: extracting shared `<head>`/chrome keeps status-bar/footer behavior identical regardless of which layout a route uses; today a new Jetstream-backed page silently misses the status bar/toast host.
3. **`getImagesOfPath()` is byte-identical in `HomeComponent.php:190-200` and `ShowViewComponent.php:544-554`** (and `getDirectoriesOfPath`/`getFilesOfPath` are one-line wrappers around `Utility`). Practical benefit: fold into `Utility` or a shared trait so the extension-matching rule (`str_contains` on `jpg`/`jpeg`, which also matches substrings like `.jpeg` handling) is defined once.

## Gaps / uncertainties
- No execution or tests were run; the polling-cost impacts are source-derived hypotheses about volume, not measured timings.
- Livewire 4 specifics I could not confirm from the snapshot (no `vendor/`, no Livewire docs): whether `$listeners` still works alongside `#[On]` (P3 finding), whether `$this->dispatch('regenerate-previews')`/`'check-for-updates'` reliably triggers the same component's `$listeners` methods on save/mount, and whether `@entangle` in `config-component.blade.php:99` is still supported. If self-dispatch does not trigger self-listeners, post-save preview regeneration (`ConfigComponent.php:703-705`) would leave the preview stale — worth confirming once dependencies are available.
- `@livewire('navigation-menu')` appears in both layouts with no `NavigationMenu` class or `Livewire::component` registration anywhere in the snapshot; resolution mechanism was not verifiable here, so I did not flag it. If it resolves only by convention to a file not present in this snapshot, that would be a P1 wiring break — but the app reportedly runs, so likely a resolution path outside my read-only visibility.
- I did not audit the full 1,828-line `ConfigComponent.php` or the rest of `config-component.blade.php` beyond preview/settings bindings (lines 95-375) and the update/Horizon sections by grep.
- View/component duplication of the Jetstream auth/API/team/profile screens (`resources/views/profile`, `api`, `auth`, `navigation-menu.blade.php` team dropdown) is left as framework-upgrade leftover; not counted as a defect given the trusted single-tenant model.
