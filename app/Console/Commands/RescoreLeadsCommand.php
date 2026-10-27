<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Leads\Scoring\LeadScoring;
use Illuminate\Console\Command;

/** 9.7: keeps stored lead scores current when campaign engagement changes, and backfills leads never scored. */
final class RescoreLeadsCommand extends Command
{
    protected $signature = 'leads:rescore {--all : Rescore every lead, not only the ones that need it}';

    protected $description = 'Recompute the score of leads that are unscored or had recent campaign engagement.';

    public function handle(): int
    {
        $count = LeadScoring::rescoreDue((bool) $this->option('all'));

        $this->info("Rescored {$count} lead(s).");

        return self::SUCCESS;
    }
}