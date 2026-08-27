<?php

namespace App\Http\Controllers;

use App\Models\BodyLog;
use App\Models\MeasurementType;
use App\Services\ComponentBuilder;
use App\Services\ChartService;
use App\Services\RedirectService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class BodyLogController extends Controller
{
    protected $chartService;
    protected $redirectService;

    public function __construct(
        ChartService $chartService,
        RedirectService $redirectService
    ) {
        $this->chartService = $chartService;
        $this->redirectService = $redirectService;
    }

    public function create(Request $request)
    {
        $measurementTypes = MeasurementType::where('user_id', auth()->id())->get();
        $selectedMeasurementTypeId = $request->query('measurement_type_id');
        return view('body-logs.create', compact('measurementTypes', 'selectedMeasurementTypeId'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'measurement_type_id' => 'required|exists:measurement_types,id',
            'value' => 'required|numeric',
            'date' => 'nullable|date',  // Make date nullable to handle missing date
            'logged_at' => 'required|date_format:H:i',
            'comments' => 'nullable|string',
        ]);

        // If no date provided, default to today (this handles the stale page fix)
        $date = $request->date ? $request->date : now()->toDateString();
        $loggedAt = \Carbon\Carbon::parse($date)->setTimeFromTimeString($request->logged_at);

        // Check if entry already exists for this measurement type and date
        $existingLog = BodyLog::where('user_id', auth()->id())
            ->where('measurement_type_id', $request->measurement_type_id)
            ->whereDate('logged_at', $date)
            ->first();

        $measurementType = MeasurementType::findOrFail($request->measurement_type_id);

        if ($existingLog) {
            // Update existing entry
            $existingLog->update([
                'value' => $request->value,
                'logged_at' => $loggedAt,
                'comments' => $request->comments,
                'unit' => $measurementType->default_unit,
            ]);
            $successMessage = 'Measurement updated successfully.';
        } else {
            // Create new entry
            BodyLog::create([
                'measurement_type_id' => $request->measurement_type_id,
                'value' => $request->value,
                'logged_at' => $loggedAt,
                'comments' => $request->comments,
                'user_id' => auth()->id(),
                'unit' => $measurementType->default_unit,
            ]);
            $successMessage = 'Measurement logged successfully.';
        }

        return $this->redirectService->getRedirect(
            'body_logs',
            'store',
            $request,
            [],
            $successMessage
        );
    }

    public function edit(BodyLog $bodyLog)
    {
        if ($bodyLog->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }
        $measurementTypes = MeasurementType::where('user_id', auth()->id())->get();
        return view('body-logs.edit', compact('bodyLog', 'measurementTypes'));
    }

    public function update(Request $request, BodyLog $bodyLog)
    {
        if ($bodyLog->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }
        $request->validate([
            'measurement_type_id' => 'required|exists:measurement_types,id',
            'value' => 'required|numeric',
            'date' => 'required|date',
            'logged_at' => 'required|date_format:H:i',
            'comments' => 'nullable|string',
        ]);

        $loggedAt = \Carbon\Carbon::parse($request->date)->setTimeFromTimeString($request->logged_at);

        $measurementType = MeasurementType::findOrFail($request->measurement_type_id);

        $bodyLog->update([
            'measurement_type_id' => $request->measurement_type_id,
            'value' => $request->value,
            'logged_at' => $loggedAt,
            'comments' => $request->comments,
            'unit' => $measurementType->default_unit,
        ]);

        return $this->redirectService->getRedirect(
            'body_logs',
            'update',
            $request,
            [],
            'Measurement updated successfully.'
        );
    }

    public function destroy(BodyLog $bodyLog)
    {
        if ($bodyLog->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }
        
        $bodyLog->delete();

        return $this->redirectService->getRedirect(
            'body_logs',
            'destroy',
            request(),
            [],
            'Measurement deleted successfully.'
        );
    }

    public function showByType(MeasurementType $measurementType)
    {
        if ($measurementType->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }

        $bodyLogs = BodyLog::where('measurement_type_id', $measurementType->id)
            ->where('user_id', auth()->id())
            ->orderBy('logged_at', 'desc')
            ->get();

        // Build components
        $components = [];

        // Title
        $components[] = ComponentBuilder::title($measurementType->name)->build();

        // Chart (if there's data)
        if ($bodyLogs->count() > 1) {
            $chartData = $this->chartService->generateBodyLogChartData($bodyLogs, $measurementType);
            
            $chartBuilder = ComponentBuilder::chart('bodyLogChart', 'History')
                ->type('line')
                ->datasets($chartData['datasets'])
                ->timeScale('day')
                ->showLegend()
                ->showTimeframeSelector()
                ->ariaLabel($measurementType->name . ' history chart')
                ->containerClass('chart-container-styled')
                ->height(300)
                ->noAspectRatio()
                ->labelColors();

            $components[] = $chartBuilder->build();
        }

        // Table
        $tableBuilder = ComponentBuilder::table()
            ->ariaLabel('Logged body logs')
            ->spacedRows();
        
        foreach ($bodyLogs as $bodyLog) {
            $unit = $bodyLog->unit ?: $bodyLog->measurementType->default_unit;
            $valueText = $bodyLog->value . ' ' . $unit;
            
            $tableBuilder->row($bodyLog->id, $measurementType->name, null, $bodyLog->comments)
                ->badge($valueText, 'info', true)
                ->badge($bodyLog->logged_at->format('m/d'), 'neutral') // Date Badge
                ->linkAction('fa-pencil', route('body-logs.edit', $bodyLog->id), 'Edit', 'btn-transparent')
                ->formAction('fa-trash', route('body-logs.destroy', $bodyLog->id), 'DELETE', [], 'Delete', 'btn-transparent', true)
                ->compact()
                ->add();
        }

        if ($bodyLogs->isEmpty()) {
            $tableBuilder->emptyMessage('No body logs found for ' . $measurementType->name);
        }
        
        $components[] = $tableBuilder->build();
        
        $data = ['components' => $components];

        return view('mobile-entry.flexible', compact('data'));
    }
}