<?php

namespace App\Logging;

use App\Domain\Tenant\TenantContext;
use Monolog\LogRecord;

/**
 * 14.1: Monolog tap that enriches every record with tenant_id, user_id and request_id
 * (extra fields), so a backend log line can be correlated with a frontend exception report.
 * Never throws: logging must not break the request that is being logged.
 */
class TenantContextTap
{
    public function __invoke($logger): void
    {
        $logger->pushProcessor(static function (LogRecord $record): LogRecord {
            $extra = [];

            $tenantId = TenantContext::id();
            if ($tenantId !== null) {
                $extra['tenant_id'] = $tenantId;
            }

            try {
                if (app()->bound('request')) {
                    $request = app('request');

                    $requestId = $request->attributes->get('request_id');
                    if ($requestId !== null) {
                        $extra['request_id'] = $requestId;
                    }

                    $user = $request->user();
                    if ($user !== null) {
                        $extra['user_id'] = $user->getAuthIdentifier();
                    }
                }
            } catch (\Throwable) {
                // No session / guard available (console, early boot): skip user context.
            }

            return $record->with(extra: array_merge($record->extra, $extra));
        });
    }
}
