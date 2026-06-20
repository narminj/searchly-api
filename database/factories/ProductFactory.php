<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    private static array $categoryData = [
        'electronics' => [
            'brands'   => ['Samsung', 'Apple', 'Sony', 'LG', 'Xiaomi', 'Huawei', 'Dell', 'HP', 'Lenovo', 'Asus'],
            'products' => ['Smartphone', 'Laptop', 'Tablet', 'Smartwatch', 'Headphones', 'Speaker', 'Camera', 'TV', 'Monitor', 'Keyboard'],
            'tags'     => ['tech', 'gadget', 'wireless', 'smart', 'digital', 'portable', 'bluetooth', 'wifi', '4K', 'HD'],
            'priceMin' => 50,
            'priceMax' => 2500,
        ],
        'clothing' => [
            'brands'   => ['Nike', 'Adidas', 'Zara', 'H&M', 'Levi\'s', 'Gucci', 'Puma', 'Uniqlo', 'Ralph Lauren', 'Tommy Hilfiger'],
            'products' => ['T-Shirt', 'Jeans', 'Jacket', 'Dress', 'Sneakers', 'Hoodie', 'Shorts', 'Blazer', 'Coat', 'Sweater'],
            'tags'     => ['fashion', 'casual', 'sport', 'summer', 'winter', 'slim-fit', 'organic', 'cotton', 'waterproof', 'luxury'],
            'priceMin' => 10,
            'priceMax' => 800,
        ],
        'books' => [
            'brands'   => ['Penguin', 'HarperCollins', 'Random House', 'Simon & Schuster', 'Macmillan', 'Oxford Press', 'Cambridge', 'Wiley', 'Springer', 'MIT Press'],
            'products' => ['Novel', 'Textbook', 'Biography', 'Guide', 'Handbook', 'Encyclopedia', 'Manual', 'Journal', 'Workbook', 'Reference Book'],
            'tags'     => ['bestseller', 'fiction', 'non-fiction', 'educational', 'science', 'history', 'programming', 'self-help', 'business', 'classic'],
            'priceMin' => 5,
            'priceMax' => 150,
        ],
        'home' => [
            'brands'   => ['IKEA', 'Dyson', 'Philips', 'Bosch', 'KitchenAid', 'Cuisinart', 'Instant Pot', 'Roomba', 'Nespresso', 'Weber'],
            'products' => ['Sofa', 'Coffee Maker', 'Vacuum Cleaner', 'Blender', 'Air Purifier', 'Lamp', 'Bed Frame', 'Cookware Set', 'Toaster', 'Robot Vacuum'],
            'tags'     => ['home', 'kitchen', 'furniture', 'appliance', 'decor', 'energy-saving', 'quiet', 'easy-clean', 'modern', 'durable'],
            'priceMin' => 20,
            'priceMax' => 1500,
        ],
        'sports' => [
            'brands'   => ['Nike', 'Adidas', 'Under Armour', 'Reebok', 'New Balance', 'Garmin', 'Fitbit', 'Wilson', 'Callaway', 'Yeti'],
            'products' => ['Running Shoes', 'Yoga Mat', 'Dumbbells', 'Resistance Bands', 'Bicycle', 'Tennis Racket', 'Golf Club', 'Treadmill', 'Protein Powder', 'Water Bottle'],
            'tags'     => ['fitness', 'outdoor', 'running', 'training', 'yoga', 'cycling', 'swimming', 'hiking', 'performance', 'recovery'],
            'priceMin' => 15,
            'priceMax' => 1200,
        ],
        'beauty' => [
            'brands'   => ['L\'Oreal', 'Maybelline', 'MAC', 'Clinique', 'Estee Lauder', 'NYX', 'Fenty Beauty', 'NARS', 'Urban Decay', 'Charlotte Tilbury'],
            'products' => ['Foundation', 'Lipstick', 'Mascara', 'Moisturizer', 'Serum', 'Sunscreen', 'Eye Shadow', 'Blush', 'Highlighter', 'Perfume'],
            'tags'     => ['skincare', 'makeup', 'vegan', 'cruelty-free', 'natural', 'anti-aging', 'spf', 'hydrating', 'organic', 'dermatologist-tested'],
            'priceMin' => 8,
            'priceMax' => 400,
        ],
    ];

    public function definition(): array
    {
        $category = $this->faker->randomElement(array_keys(self::$categoryData));
        $data     = self::$categoryData[$category];

        $brand   = $this->faker->randomElement($data['brands']);
        $product = $this->faker->randomElement($data['products']);
        $name    = "{$brand} {$product} " . $this->faker->bothify('??-###');

        return [
            'tenant_id'   => 'default',
            'name'        => $name,
            'description' => $this->faker->paragraphs(2, true),
            'category'    => $category,
            'brand'       => $brand,
            'price'       => $this->faker->randomFloat(2, $data['priceMin'], $data['priceMax']),
            'stock'       => $this->faker->numberBetween(0, 500),
            'tags'        => $this->faker->randomElements($data['tags'], $this->faker->numberBetween(2, 5)),
            'is_active'   => $this->faker->boolean(85), // 85% active
            // Skewed: most products barely clicked, a few are hits
            'popularity'  => $this->faker->boolean(70) ? $this->faker->numberBetween(0, 20) : $this->faker->numberBetween(50, 500),
            'latitude'    => $this->faker->latitude(35, 55),
            'longitude'   => $this->faker->longitude(25, 65),
        ];
    }

    public function electronics(): static
    {
        return $this->state(['category' => 'electronics']);
    }

    public function inStock(): static
    {
        return $this->state(['stock' => $this->faker->numberBetween(1, 500)]);
    }

    public function outOfStock(): static
    {
        return $this->state(['stock' => 0]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function forTenant(string $tenant): static
    {
        return $this->state(['tenant_id' => $tenant]);
    }
}
