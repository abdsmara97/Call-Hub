<?php

namespace App\Http\Controllers;

use App\Models\Emergency;
use App\Models\EmergencyRecipient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the permanent emergency audit trail as CSV: one row per recipient per
 * emergency, so acknowledgement times are auditable per person.
 *
 * Streamed rather than built in memory — the log only ever grows.
 */
class EmergencyLogExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $this->authorize('export', Emergency::class);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $filename = 'emergency-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($validated) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'Emergency ID', 'Sent at (UTC)', 'Sender', 'Sender email',
                'Scope', 'Target', 'Message',
                'Recipient', 'Recipient email', 'Company', 'Administration',
                'Notified at (UTC)', 'Acknowledged at (UTC)', 'Response seconds',
                'Alerts sent', 'Escalations', 'Resolved at (UTC)',
            ]);

            EmergencyRecipient::query()
                ->with([
                    'emergency.sender',
                    'emergency.room',
                    'emergency.company',
                    'emergency.administration',
                    'user.company',
                    'user.administration',
                ])
                ->whereHas('emergency', function ($query) use ($validated) {
                    $query
                        ->when($validated['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
                        ->when($validated['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to));
                })
                ->orderBy('emergency_id')
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($handle) {
                    foreach ($rows as $row) {
                        $emergency = $row->emergency;

                        fputcsv($handle, [
                            $emergency->getKey(),
                            $emergency->created_at?->toDateTimeString(),
                            $emergency->sender?->name,
                            $emergency->sender?->email,
                            $emergency->scope->label(),
                            $emergency->targetLabel(),
                            $emergency->body,
                            $row->user?->name,
                            $row->user?->email,
                            $row->user?->company?->name,
                            $row->user?->administration?->name,
                            $row->notified_at?->toDateTimeString(),
                            $row->acknowledged_at?->toDateTimeString(),
                            $row->responseSeconds(),
                            $row->alert_count,
                            $emergency->escalation_count,
                            $emergency->resolved_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
