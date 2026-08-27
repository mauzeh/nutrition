<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\Meal;
use App\Models\Unit;
use App\Services\IngredientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IngredientController extends Controller
{
    protected IngredientService $ingredientService;
    
    public function __construct(IngredientService $ingredientService)
    {
        $this->ingredientService = $ingredientService;
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $ingredients = Ingredient::with('baseUnit')->where('user_id', auth()->id())->orderBy('name')->get();
        
        // Build table rows
        $rows = [];
        foreach ($ingredients as $ingredient) {
            $rows[] = [
                'id' => $ingredient->id,
                'line1' => $ingredient->name,
                'line2' => sprintf(
                    '%s%s • %d cal • P:%sg C:%sg F:%sg',
                    $ingredient->base_quantity,
                    $ingredient->baseUnit->abbreviation,
                    round($ingredient->calories),
                    $ingredient->protein,
                    $ingredient->carbs,
                    $ingredient->fats
                ),
                'line3' => sprintf('Cost: $%s per unit', number_format($ingredient->cost_per_unit, 2)),
                'compact' => true,
                'actions' => [
                    [
                        'type' => 'link',
                        'icon' => 'fa-pencil',
                        'url' => route('ingredients.edit', $ingredient->id),
                        'ariaLabel' => 'Edit ' . $ingredient->name,
                        'cssClass' => 'btn-transparent'
                    ]
                ]
            ];
        }
        
        // Build table component
        $tableBuilder = \App\Services\ComponentBuilder::table()
            ->rows($rows)
            ->emptyMessage('No ingredients found. Please add some!')
            ->confirmMessage('deleteItem', 'Are you sure you want to delete this ingredient?')
            ->ariaLabel('Ingredients list');
        
        // Build components array
        $components = [
            \App\Services\ComponentBuilder::title('Ingredients List')->build(),
        ];
        
        // Add success/error messages if present
        if ($sessionMessages = \App\Services\ComponentBuilder::messagesFromSession()) {
            $components[] = $sessionMessages;
        }
        
        // Add button to create new ingredient
        $components[] = \App\Services\ComponentBuilder::button('Add New Ingredient')
            ->asLink(route('ingredients.create'))
            ->cssClass('btn-primary')
            ->build();
        
        $components[] = $tableBuilder->build();
        
        $data = ['components' => $components];
        
        return view('mobile-entry.flexible', compact('data'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $prefilledName = $request->query('name', '');
        $redirectTo = $request->query('redirect_to', '');
        $mealId = $request->query('meal_id', '');
        
        $units = Unit::all();
        $unitOptions = $this->ingredientService->buildUnitOptions($units);
        
        $data = [
            'components' => [
                $this->ingredientService->generateCreateFormComponent($prefilledName),
                $this->ingredientService->buildFormComponent($unitOptions, null, $prefilledName, $redirectTo, $mealId),
            ],
        ];
        
        return view('mobile-entry.flexible', compact('data'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'protein' => 'required|numeric|min:0',
            'carbs' => 'required|numeric|min:0',
            'added_sugars' => 'nullable|numeric|min:0',
            'fats' => 'required|numeric|min:0',
            'sodium' => 'nullable|numeric|min:0',
            'iron' => 'nullable|numeric|min:0',
            'potassium' => 'nullable|numeric|min:0',
            'fiber' => 'nullable|numeric|min:0',
            'calcium' => 'nullable|numeric|min:0',
            'caffeine' => 'nullable|numeric|min:0',
            'base_quantity' => 'required|numeric|min:0.01',
            'base_unit_id' => 'required|exists:units,id',
            'cost_per_unit' => 'required|numeric|min:0',
        ]);

        $data = $request->except('calories', 'user_id');
        $ingredient = Ingredient::create(array_merge($data, ['user_id' => auth()->id()]));

        // Handle redirect parameters from meal ingredient selection
        if ($request->has('redirect_to') && $request->has('meal_id')) {
            $redirectTo = $request->input('redirect_to');
            $mealId = $request->input('meal_id');
            
            if ($redirectTo === 'meals.edit') {
                // Verify the meal exists and belongs to the user
                $meal = Meal::where('id', $mealId)->where('user_id', auth()->id())->first();
                
                if ($meal) {
                    // Redirect to the quantity form to add the ingredient to the meal
                    return redirect()->route('meals.add-ingredient', [
                        'meal' => $meal->id,
                        'ingredient' => $ingredient->id
                    ])->with('success', 'Ingredient created successfully! Now specify the quantity to add to your meal.');
                }
            }
        }

        return redirect()->route('ingredients.index')
                         ->with('success', 'Ingredient created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Ingredient $ingredient)
    {
        if ($ingredient->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }
        
        $units = Unit::all();
        $unitOptions = $this->ingredientService->buildUnitOptions($units);
        
        $data = [
            'components' => [
                $this->ingredientService->generateEditFormComponent($ingredient),
                $this->ingredientService->buildFormComponent($unitOptions, $ingredient),
            ],
        ];
        
        return view('mobile-entry.flexible', compact('data'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Ingredient $ingredient)
    {
        if ($ingredient->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }
        $request->validate([
            'name' => 'required|string|max:255',
            'protein' => 'required|numeric|min:0',
            'carbs' => 'required|numeric|min:0',
            'added_sugars' => 'nullable|numeric|min:0',
            'fats' => 'required|numeric|min:0',
            'sodium' => 'nullable|numeric|min:0',
            'iron' => 'nullable|numeric|min:0',
            'potassium' => 'nullable|numeric|min:0',
            'fiber' => 'nullable|numeric|min:0',
            'calcium' => 'nullable|numeric|min:0',
            'caffeine' => 'nullable|numeric|min:0',
            'base_quantity' => 'required|numeric|min:0.01',
            'base_unit_id' => 'required|exists:units,id',
            'cost_per_unit' => 'required|numeric|min:0',
        ]);

        $data = $request->except('calories', 'user_id');
        $ingredient->update($data);

        return redirect()->route('ingredients.index')
                         ->with('success', 'Ingredient updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ingredient $ingredient)
    {
        if ($ingredient->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }
        $ingredient->delete();

        return redirect()->route('ingredients.index')
                         ->with('success', 'Ingredient deleted successfully.');
    }

}