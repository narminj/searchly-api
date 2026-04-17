<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category',
        'brand',
        'price',
        'stock',
        'tags',
        'is_active',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'tags'      => 'array',
            'price'     => 'float',
            'stock'     => 'integer',
            'is_active' => 'boolean',
            'latitude'  => 'float',
            'longitude' => 'float',
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
            'created_at'  => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'  => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function getSearchIndex(): string
    {
        return config('elasticsearch.indices.products.name');
    }
}
