<?php

namespace App\Livewire;

use App\Models\Photo;
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
            $this->results = Photo::where('proof_number', 'like', '%'.$this->query . '%')
                ->select('id', 'proof_number', 'show_class_id')
                ->limit(10)
                ->get()
                ->toArray();

            $this->showDropdown = count($this->results) > 0;
        } else {
            $this->results = [];
            $this->showDropdown = false;
        }
    }

    /**
     * Select a proof number from the results.
     *
     * @param string $id
     * @return void
     */
    public function selectProof($id)
    {
        $photo = Photo::find($id);

        if ($photo) {
            $this->selectedProofNumber = $photo->proof_number;
            $this->query = $photo->proof_number;

            // Redirect to the class view page containing this proof
            $showParts = explode('_', $photo->show_class_id);
            if (count($showParts) === 2) {
                return redirect()->to('/show/' . $showParts[0] . '/class/' . $showParts[1]);
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
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('livewire.proof-search-component');
    }
}
