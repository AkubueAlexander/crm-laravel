<?php

namespace App\Domain\Settings\Actions;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * 8c.1: tenant branding (logo + accent color), stored in tenants.branding. The uploaded
 * image is never stored as received: it is decoded and re-encoded as a small PNG, which
 * strips anything embedded in the original (polyglots, metadata) and keeps the shell light.
 */
final class UpdateTenantBrandingAction
{
    /** Private disk: the logo is only reachable through the authenticated tenant/logo route. */
    public const DISK = 'local';

    public const MAX_LOGO_PX = 256;

    public static function logoPath(int $tenantId): string
    {
        return "tenant-branding/{$tenantId}/logo.png";
    }

    /** @param array{primary_color?: ?string, remove_logo?: mixed} $input */
    public function execute(User $actor, array $input, ?UploadedFile $logo): Tenant
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('settings.manage');

        // Decode first: a bad image must fail with nothing changed.
        $png = $logo !== null ? $this->reencode($logo) : null;

        // The tenant is always the actor's own (1.3); nothing here reads a client-supplied id.
        return DB::transaction(function () use ($actor, $input, $png): Tenant {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($actor->tenant_id);
            $branding = $tenant->branding ?? [];
            $path = self::logoPath((int) $tenant->id);
            $disk = Storage::disk(self::DISK);

            if (array_key_exists('primary_color', $input)) {
                $color = $input['primary_color'];

                if ($color === null || $color === '') {
                    unset($branding['primary_color']);
                } else {
                    $branding['primary_color'] = strtolower((string) $color);
                }
            }

            if ($png !== null) {
                if ($disk->put($path, $png) !== true) {
                    throw new RuntimeException('The logo could not be stored.');
                }

                // Content hash as the cache-busting version: a new logo is a new URL.
                $branding['logo_url'] = URL::route('tenant.logo', ['v' => substr(sha1($png), 0, 12)], absolute: false);
            } elseif (filter_var($input['remove_logo'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $disk->delete($path);
                unset($branding['logo_url']);
            }

            $tenant->branding = $branding === [] ? null : $branding;
            $tenant->save();

            return $tenant;
        });
    }

    private function reencode(UploadedFile $file): string
    {
        $raw = @file_get_contents((string) $file->getRealPath());
        $source = $raw === false ? false : @imagecreatefromstring($raw);

        if ($source === false) {
            throw ValidationException::withMessages(['logo' => ['The image could not be read.']]);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_LOGO_PX / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagepng($target);
        $png = (string) ob_get_clean();

        if ($png === '') {
            throw new RuntimeException('The logo could not be encoded.');
        }

        return $png;
    }
}
