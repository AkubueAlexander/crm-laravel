<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * 14.1: the SPA reports uncaught render/window/promise errors here. Any authenticated user may
 * report (it only writes a log line); the log processor adds tenant_id, user_id and request_id.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'component_stack' => ['nullable', 'string', 'max:4000'],
            'url' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'in:render,window,promise'],
        ]);

        Log::warning('client_error', [
            'message' => $data['message'],
            'source' => $data['source'] ?? null,
            'url' => $data['url'] ?? null,
            'stack' => $data['stack'] ?? null,
            'component_stack' => $data['component_stack'] ?? null,
            'user_agent' => substr((string) $request->userAgent(), 0, 300),
        ]);

        return response()->noContent();
    }
}
