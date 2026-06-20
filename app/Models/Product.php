<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'category',
        'brand',
        'price',
        'stock',
        'tags',
        'is_active',
        'popularity',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'tags'       => 'array',
            'price'      => 'float',
            'stock'      => 'integer',
            'is_active'  => 'boolean',
            'popularity' => 'integer',
            'latitude'   => 'float',
            'longitude'  => 'float',
        ];
    }

    /**
     * Returns the document body for Elasticsearch indexing.
     * Single source of truth for ES document shape.
     */
    public function toSearchArray(): array
    {
        return [
            'id'          => $this->id,
            // Multi-tenancy: drives the tenant_id term filter and the completion
            // suggester's tenant context (mapping reads it via path)
            'tenant_id'   => $this->tenant_id ?? 'default',
            'name'        => $this->name,
            'description' => $this->description,
            'category'    => $this->category,
            'brand'       => $this->brand,
            'price'       => $this->price,
            'stock'       => $this->stock,
            'tags'        => $this->tags ?? [],
            'is_active'   => $this->is_active,
            'location'    => ($this->latitude && $this->longitude)
                ? ['lat' => $this->latitude, 'lon' => $this->longitude]
                : null,
            // Completion suggester inputs: the product name and its brand
            'suggest'     => ['input' => array_values(array_unique(array_filter([$this->name, $this->brand])))],
            // rank_feature rejects non-positive values — omit via null instead
            'popularity'  => $this->popularity > 0 ? (int) $this->popularity : null,
            'created_at'  => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'  => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function getSearchIndex(): string
    {
        return config('elasticsearch.indices.products.name');
    }
}
