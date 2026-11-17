<?php

namespace App\Branding;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

/**
 * Per-organization white-label branding: a display name, a brand color and a logo.
 * Colors must keep white text readable (WCAG AA, 4.5:1) because they replace the accent color on buttons.
 * Logos are raster images only (no SVG, which can carry scripts) and live on the private disk.
 */
final class Branding
{
    public const LOGO_TYPES = ['png', 'jpg', 'jpeg', 'webp'];

    public const LOGO_MAX_KB = 512;

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'brand_name' => ['nullable', 'string', 'max:80'],
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/', self::readableOnWhite()],
        ];
    }

    public static function logoRule(): array
    {
        return ['required', File::types(self::LOGO_TYPES)->max(self::LOGO_MAX_KB), 'mimetypes:image/png,image/jpeg,image/webp', 'dimensions:max_width=2048,max_height=2048'];
    }

    public static function readableOnWhite(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) && self::contrastWithWhite($value) < 4.5) {
                $fail(__('Choose a darker color so white text on it stays readable.'));
            }
        };
    }

    public static function contrastWithWhite(string $hex): float
    {
        $channel = function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $hex = ltrim($hex, '#');
        $l = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));

        return 1.05 / ($l + 0.05);
    }

    public static function storeLogo(Tenant $tenant, UploadedFile $upload): void
    {
        $detected = strtolower((string) $upload->guessExtension());
        $claimed = strtolower($upload->getClientOriginalExtension());
        $same = fn (string $e) => $e === 'jpeg' ? 'jpg' : $e;
        if (! in_array($detected, self::LOGO_TYPES, true) || $same($claimed) !== $same($detected)) {
            throw ValidationException::withMessages(['logo' => __('The file content does not match its extension.')]);
        }

        $disk = Storage::disk(config('security.files.disk'));
        $path = $disk->putFileAs('tenants/'.$tenant->public_id.'/branding', $upload, 'logo-'.Str::ulid().'.'.$same($detected));
        $old = $tenant->logo_path;
        $tenant->forceFill(['logo_path' => $path, 'logo_mime' => $upload->getMimeType(), 'logo_updated_at' => now()])->save();
        if ($old) {
            $disk->delete($old);
        }
    }

    public static function deleteLogo(Tenant $tenant): void
    {
        if ($tenant->logo_path) {
            Storage::disk(config('security.files.disk'))->delete($tenant->logo_path);
        }
        $tenant->forceFill(['logo_path' => null, 'logo_mime' => null, 'logo_updated_at' => null])->save();
    }

    /** Public, non-sensitive branding shown before and after sign-in. */
    public static function present(Tenant $tenant): array
    {
        return [
            'name' => $tenant->brand_name,
            'color' => $tenant->brand_color,
            'logo_url' => $tenant->logo_path
                ? '/api/v1/public/organizations/'.$tenant->slug.'/logo?v='.$tenant->logo_updated_at?->getTimestamp()
                : null,
        ];
    }
}
