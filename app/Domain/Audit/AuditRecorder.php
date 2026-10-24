<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;

/**
 * 8c.3: the single way to write an audit event. Call it from inside the Action's transaction,
 * so the event commits (or rolls back) together with the change it describes.
 */
final class AuditRecorder
{
    /** @param array<string, mixed> $properties small, non-sensitive facts (never tokens, passwords or emails of third parties) */
    public static function record(
        int $tenantId,
        ?int $actorId,
        string $event,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $subjectLabel = null,
        array $properties = [],
        string $source = 'users',
    ): void {
        (new AuditEvent())->forceFill([
            'tenant_id' => $tenantId,
            'user_id' => $actorId,
            'source' => $source,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'properties' => $properties === [] ? null : $properties,
            'created_at' => now(),
        ])->save();
    }
}
