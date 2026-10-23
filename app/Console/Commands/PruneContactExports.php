<?php

namespace App\Console\Commands;

use App\Models\ContactExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneContactExports extends Command
{
    protected $signature = 'contacts:prune-exports';

    protected $description = 'Delete expired contact export files and old export rows (8b.4).';

    public function handle(): int
    {
        $files = 0;

        // Console process: the tenant scope is skipped, so this is explicitly cross-tenant system work.
        ContactExport::withoutTenantScope()
            ->whereNotNull('path')
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($exports) use (&$files) {
                foreach ($exports as $export) {
                    try {
                        Storage::disk(ContactExport::DISK)->delete($export->path);
                        // Query-level update: no model events, no tenant guard involved.
                        ContactExport::withoutTenantScope()->whereKey($export->id)->update(['path' => null]);
                        $files++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        $rows = ContactExport::withoutTenantScope()
            ->whereNull('path')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Deleted {$files} expired export file(s) and {$rows} old export row(s).");

        return self::SUCCESS;
    }
}
