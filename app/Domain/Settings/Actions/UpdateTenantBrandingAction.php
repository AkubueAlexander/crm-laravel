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

        Gate::forUser($actor)->authorize('settings.manage');


        $png = $logo !== null ? $this->reencode($logo) : null;


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
