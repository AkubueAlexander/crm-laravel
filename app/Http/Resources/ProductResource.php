<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'unit_price' => $this->unit_price,
            'tax_category' => $this->tax_category,
            'is_active' => (bool) $this->is_active,
            'variants_count' => $this->whenCounted('variants'),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'name' => $v->name,
                'unit_price' => $v->unit_price,
                'is_active' => (bool) $v->is_active,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
