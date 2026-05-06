const SORT_OPTIONS = [
    { value: 'relevance',  label: 'Uyğunluq' },
    { value: 'price_asc',  label: 'Qiymət: Azdan Çoxa' },
    { value: 'price_desc', label: 'Qiymət: Çoxdan Aza' },
    { value: 'newest',     label: 'Ən Yeni' },
    { value: 'name',       label: 'Ad (A-Z)' },
]

export function Filters({ filters, onFilterChange, onReset, aggregations }) {
    const categories = aggregations?.categories ?? []
    const brands     = aggregations?.brands     ?? []

    return (
        <aside className="w-60 flex-shrink-0 space-y-5">
            {/* Sort */}
            <FilterBox title="Sıralama">
                <select value={filters.sort} onChange={e => onFilterChange('sort', e.target.value)}
                    className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    {SORT_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
            </FilterBox>

            {/* Price range */}
            <FilterBox title="Qiymət Aralığı">
                <div className="flex items-center gap-2">
                    <input type="number" min="0" placeholder="Min"
                        value={filters.price_min}
                        onChange={e => onFilterChange('price_min', e.target.value)}
                        className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                    <span className="text-gray-400">—</span>
                    <input type="number" min="0" placeholder="Max"
                        value={filters.price_max}
                        onChange={e => onFilterChange('price_max', e.target.value)}
                        className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
            </FilterBox>

            {/* In stock */}
            <FilterBox>
                <label className="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" checked={filters.in_stock}
                        onChange={e => onFilterChange('in_stock', e.target.checked)}
                        className="w-4 h-4 text-blue-600 rounded focus:ring-blue-500" />
                    <span className="text-sm font-medium text-gray-700">Yalnız stokda olanlar</span>
                </label>
            </FilterBox>

            {/* Categories */}
            {categories.length > 0 && (
                <FilterBox title="Kateqoriya">
                    <FacetList
                        items={categories}
                        active={filters.category}
                        onSelect={v => onFilterChange('category', filters.category === v ? '' : v)}
                    />
                </FilterBox>
            )}

            {/* Brands */}
            {brands.length > 0 && (
                <FilterBox title="Brend">
                    <div className="max-h-48 overflow-y-auto">
                        <FacetList
                            items={brands}
                            active={filters.brand}
                            onSelect={v => onFilterChange('brand', filters.brand === v ? '' : v)}
                        />
                    </div>
                </FilterBox>
            )}

            <button onClick={onReset}
                className="w-full py-2 text-sm text-red-500 hover:text-red-700 hover:bg-red-50 rounded-xl transition-colors border border-red-200">
                Filterləri Sıfırla
            </button>
        </aside>
    )
}

function FilterBox({ title, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
            {title && <h3 className="font-semibold text-gray-700 mb-3 text-xs uppercase tracking-wide">{title}</h3>}
            {children}
        </div>
    )
}

function FacetList({ items, active, onSelect }) {
    return (
        <ul className="space-y-0.5">
            {items.map(item => (
                <li key={item.name}>
                    <button onClick={() => onSelect(item.name)}
                        className={`w-full text-left text-sm px-2 py-1.5 rounded-lg flex justify-between items-center transition-colors ${active === item.name ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>
                        <span className="capitalize">{item.name}</span>
                        <span className="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{item.count}</span>
                    </button>
                </li>
            ))}
        </ul>
    )
}
