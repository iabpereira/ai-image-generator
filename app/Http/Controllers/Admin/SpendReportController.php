<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SpendReportRequest;
use App\Services\Reports\SpendReport;
use Inertia\Inertia;
use Inertia\Response;

class SpendReportController extends Controller
{
    /**
     * Operators' view of credit spend, provider cost and profit per day and user.
     */
    public function __invoke(SpendReportRequest $request, SpendReport $report): Response
    {
        return Inertia::render('admin/SpendReport', [
            'filters' => [
                'start_date' => $request->startDate()->toDateString(),
                'end_date' => $request->endDate()->toDateString(),
            ],
            'report' => $report->build($request->startDate(), $request->endDate()),
        ]);
    }
}
