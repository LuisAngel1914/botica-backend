<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'action' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $logs = ActivityLog::with('user:id,name,email')
            ->when($validated['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->latest()
            ->paginate($validated['per_page'] ?? 25);

        return response()->json($logs);
    }
}
