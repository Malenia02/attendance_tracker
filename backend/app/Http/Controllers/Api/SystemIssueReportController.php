<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueReportIndexRequest;
use App\Http\Requests\ReviewSystemIssueReportRequest;
use App\Http\Requests\StoreSystemIssueReportRequest;
use App\Models\SystemIssueReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class SystemIssueReportController extends Controller
{
    private const CATEGORIES = [
        'Attendance',
        'DTR',
        'Account Access',
        'Performance',
        'Incorrect Data',
        'Other',
    ];

    private const STATUSES = ['Open', 'In Progress', 'Resolved', 'Closed'];

    public function index(IssueReportIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $isAdministrator = $user->user_role === 'Administrator';
        $accessible = SystemIssueReport::query()
            ->when(! $isAdministrator, fn (Builder $query) => $query->where('reported_by', $user->user_id));

        $summary = (clone $accessible)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) as open_count")
            ->selectRaw("SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_count")
            ->selectRaw("SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count")
            ->first();

        $query = (clone $accessible)
            ->select([
                'issue_report_id',
                'reported_by',
                'reporter_name',
                'category',
                'subject',
                'description',
                'page_url',
                'status',
                'admin_notes',
                'reviewed_by',
                'reviewed_at',
                'created_at',
                'updated_at',
            ])
            ->with(['reviewer:user_id,username'])
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('subject', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('reporter_name', 'like', "%{$search}%");
                });
            })
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category));

        if (($validated['sort'] ?? 'newest') === 'oldest') {
            $query->orderBy('created_at')->orderBy('issue_report_id');
        } else {
            $query->orderByDesc('created_at')->orderByDesc('issue_report_id');
        }

        $paginator = $query->paginate(
            $validated['per_page'] ?? 15,
            ['*'],
            'page',
            $validated['page'] ?? 1
        );

        return $this->privateResponse([
            'data' => collect($paginator->items())->map(fn (SystemIssueReport $report) => $this->formatReport($report)),
            'summary' => [
                'total' => (int) ($summary?->total ?? 0),
                'open' => (int) ($summary?->open_count ?? 0),
                'in_progress' => (int) ($summary?->in_progress_count ?? 0),
                'resolved' => (int) ($summary?->resolved_count ?? 0),
            ],
            'options' => [
                'categories' => self::CATEGORIES,
                'statuses' => self::STATUSES,
            ],
            'meta' => [
                'can_review' => $isAdministrator,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    public function store(StoreSystemIssueReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        /** @var User $user */
        $user = $request->user()->loadMissing('personnel');
        $reporterName = trim((string) ($user->personnel?->full_name ?? '')) ?: $user->username;

        $report = SystemIssueReport::query()->create([
            'reported_by' => $user->user_id,
            'reporter_name' => $reporterName,
            'category' => $validated['category'],
            'subject' => trim($validated['subject']),
            'description' => trim($validated['description']),
            'page_url' => isset($validated['page_url']) ? trim($validated['page_url']) : null,
            'status' => 'Open',
        ]);

        return $this->privateResponse([
            'message' => 'Your issue report was submitted. An administrator can now review it.',
            'issue_report' => $this->formatReport($report),
        ], 201);
    }

    public function update(
        ReviewSystemIssueReportRequest $request,
        SystemIssueReport $issueReport
    ): JsonResponse {
        $validated = $request->validated();
        $reviewed = DB::transaction(function () use ($request, $issueReport, $validated): SystemIssueReport {
            $locked = SystemIssueReport::query()
                ->whereKey($issueReport->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'status' => $validated['status'],
                'admin_notes' => trim((string) ($validated['admin_notes'] ?? '')) ?: null,
                'reviewed_by' => $request->user()->user_id,
                'reviewed_at' => now(),
            ])->save();

            return $locked;
        });

        return $this->privateResponse([
            'message' => 'The issue report status was updated.',
            'issue_report' => $this->formatReport($reviewed->fresh(['reviewer:user_id,username'])),
        ]);
    }

    private function formatReport(SystemIssueReport $report): array
    {
        return [
            'id' => $report->issue_report_id,
            'category' => $report->category,
            'subject' => $report->subject,
            'description' => $report->description,
            'page_url' => $report->page_url,
            'status' => $report->status,
            'reporter_name' => $report->reporter_name,
            'admin_notes' => $report->admin_notes,
            'reviewed_by' => $report->reviewer?->username,
            'reviewed_at' => $report->reviewed_at?->toISOString(),
            'created_at' => $report->created_at?->toISOString(),
            'updated_at' => $report->updated_at?->toISOString(),
        ];
    }

    private function privateResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
