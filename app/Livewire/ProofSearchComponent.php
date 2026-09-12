<?php

namespace App\Livewire;

use App\Models\Photo;
use Illuminate\View\View;
use Livewire\Component;

class ProofSearchComponent extends Component
{
    /**
     * The search query.
     *
     * @var string
     */
    public $query = '';

    /**
     * Whether the autocomplete dropdown is open.
     *
     * @var bool
     */
    public $showDropdown = false;

    /**
     * The selected proof number.
     *
     * @var string|null
     */
    public $selectedProofNumber = null;

    /**
     * The searched results.
     *
     * @var array
     */
    public $results = [];

    /**
     * Listen for query updates.
     *
     * @return void
     */
    public function updatedQuery()
    {
        $this->validate([
            'query' => 'nullable|string|min:3',
        ]);

        if (strlen($this->query) >= 3) {
            $this->results = Photo::query()
                ->with('showClass:id,show_id,name')
                ->where('proof_number', 'like', '%'.$this->query.'%')
                ->select('id', 'proof_number', 'show_class_id')
                ->limit(10)
                ->get()
                ->map(fn (Photo $photo) => [
                    'id' => $photo->id,
                    'proof_number' => $photo->proof_number,
                    'show_class_id' => $photo->show_class_id,
                    // Derive labels from the relation: show/class ids may both contain
                    // underscores, so splitting show_class_id is not reliable.
                    'show_name' => $photo->showClass?->show_id,
                    'class_name' => $photo->showClass?->name,
                ])
                ->all();

            $this->showDropdown = count($this->results) > 0;
        } else {
            $this->results = [];
            $this->showDropdown = false;
        }
    }

    /**
     * Select a proof number from the results.
     *
     * @param  string  $id
     * @return void
     */
    public function selectProof($id)
    {
        $photo = Photo::with('showClass:id,show_id,name')->find($id);

        if ($photo) {
            $this->selectedProofNumber = $photo->proof_number;
            $this->query = $photo->proof_number;

            $showClass = $photo->showClass;

            if ($showClass && $showClass->show_id !== null && $showClass->name !== null) {
                $this->showDropdown = false;

                // Encode each path segment so ids/names with spaces or punctuation
                // still produce a valid URL.
                return redirect()->to(
                    '/show/'.rawurlencode((string) $showClass->show_id)
                    .'/class/'.rawurlencode((string) $showClass->name)
                );
            }
        }

        $this->showDropdown = false;
    }

    /**
     * Clear the search.
     *
     * @return void
     */
    public function clearSearch()
    {
        $this->query = '';
        $this->selectedProofNumber = null;
        $this->results = [];
        $this->showDropdown = false;
    }

    /**
     * Render the component.
     *
     * @return View
     */
    public function render()
    {
        return view('livewire.proof-search-component');
    }
}
