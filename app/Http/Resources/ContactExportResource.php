<?php

namespace App\Http\Resources;

use App\Models\ContactExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin ContactExport */
class ContactExportResource extends JsonResource
{
    /** A signed link is short-lived; the client asks again (re-reads the export) when it needs a fresh one. */
    private const LINK_MINUTES = 10;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $filters = $this->filters ?? [];

        return [
            'id' => $this->id,
            'format' => $this->format,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'sort' => $filters['sort'] ?? 'last_name',
                'direction' => $filters['direction'] ?? 'asc',
            ],
            'status' => $this->status,
            'row_count' => $this->row_count,
            'error' => $this->error,
            'expired' => $this->isExpired(),
            // Relative signed URL: valid whatever host the SPA reaches the API through.
            'download_url' => $this->isDownloadable()
                ? URL::temporarySignedRoute('contacts.exports.download', now()->addMinutes(self::LINK_MINUTES), ['export' => $this->id], absolute: false)
                : null,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
