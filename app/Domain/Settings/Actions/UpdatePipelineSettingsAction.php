<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Forecasting\PipelineProbabilities;
use App\Domain\Settings\PipelineSettings;
use App\Models\PipelineStageSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdatePipelineSettingsAction
{
    public function __construct(private readonly PipelineSettings $settings)
    {
    }

    /**
     * Stores a probability override per stage; a value equal to the default deletes the override,
     * so "reset to default" is simply typing the default back. The BelongsToTenant scope keeps
     * every lookup and write inside the actor's tenant, and stamps tenant_id on new rows (1.3).
     *
     * @param array<string, int|float|string> $stages stage slug => 0.0-1.0
     * @return array<string, mixed> the settings as the screen reads them
     */
    public function execute(User $actor, array $stages): array
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('settings.manage');

        DB::transaction(function () use ($stages): void {
            foreach (PipelineProbabilities::DEFAULTS as $slug => $default) {
                if (! array_key_exists($slug, $stages)) {
                    continue;
                }

                $probability = round((float) $stages[$slug], 2);

                if (abs($probability - $default) < 0.0001) {
                    PipelineStageSetting::query()->where('stage', $slug)->delete();
                } else {
                    PipelineStageSetting::query()->updateOrCreate(
                        ['stage' => $slug],
                        ['probability' => $probability],
                    );
                }
            }
        });

        return $this->settings->forTenant((int) $actor->tenant_id);
    }
}
