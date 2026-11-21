<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\PlatformAuditQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PlatformAuditController extends Controller
{
    public function __construct(private readonly PlatformAuditQuery $audit) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'actor' => ['nullable', 'ulid'],
            'tenant' => ['nullable', 'ulid'],
            'action' => ['nullable', 'string', 'max:120', 'regex:/\A[a-z0-9_.-]+\z/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'result' => ['nullable', 'in:failed,succeeded'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $events = $this->audit->search($filters);
        if ($request->expectsJson()) {
            return response()->json(['data' => $events->items(), 'meta' => [
                'current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total(),
            ]])->header('Cache-Control', 'no-store');
        }

        return response()->view('platform.audit.index', ['events' => $events, 'filters' => $filters])
            ->header('Cache-Control', 'no-store');
    }
}
