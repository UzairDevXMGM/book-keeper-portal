<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Admin → Audit log: who did what, when, from where. */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:40'],
            'company' => ['nullable', 'integer'],
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::with(['user:id,name,email', 'company:id,name'])
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', "{$a}%"))
            ->when($filters['company'] ?? null, fn ($q, $c) => $q->where('company_id', $c))
            ->when($filters['start'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', "{$d} 00:00:00"))
            ->when($filters['end'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', "{$d} 23:59:59"))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('user', fn ($u) => $u->where('email', 'like', "%{$term}%")))
            ->latest('id')
            ->paginate(30)->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'groups' => AuditLog::query()->distinct()->pluck('action')->map(fn ($a) => explode('.', $a)[0])->unique()->sort()->values(),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
