const SORT_OPTIONS = [
    { value: 'relevance',  label: 'Uyğunluq' },
    { value: 'price_asc',  label: 'Qiymət: Azdan Çoxa' },
    { value: 'price_desc', label: 'Qiymət: Çoxdan Aza' },
    { value: 'newest',     label: 'Ən Yeni' },
    { value: 'name',       label: 'Ad (A-Z)' },
]

export function Filters({ filters, onFilterChange, onReset, aggregations }) {
    const categories = aggregations?.categories ?? []
    const brands     = aggregations?.brands ?? []

    return (
        <aside className="w-64 flex-shrink-0 space-y-6">
            {/* Sort */}
            <div className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                <h3 className="font-semibold text-gray-800 mb-3 text-sm uppercase tracking-wide">Sıralama</h3>
                <select
                    value={filters.sort}
                    onChange={e => onFilterChange('sort', e.target.value)}
                    className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    {SORT_OPTIONS.map(o => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                </select>
            </div>

            {/* Price Range */}
            <div className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                <h3 className="font-semibold text-gray-800 mb-3 text-sm uppercase tracking-wide">Qiymət Aralığı</h3>
                <div className="flex items-center gap-2">
                    <input
                        type="number" min="0" placeholder="Min"
                        value={filters.price_min}
                        onChange={e => onFilterChange('price_min', e.target.value)}
                        className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    <span className="text-gray-400">—</span>
                    <input
                        type="number" min="0" placeholder="Max"
                        value={filters.price_max}
                        onChange={e => onFilterChange('price_max', e.target.value)}
                        className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                </div>
            </div>

            {/* In Stock */}
            <div className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                <label className="flex items-center gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        checked={filters.in_stock}
                        onChange={e => onFilterChange('in_stock', e.target.checked)}
                        className="w-4 h-4 text-blue-600 rounded focus:ring-blue-500"
                    />
                    <span className="text-sm font-medium text-gray-700">Yalnız stokda olanlar</span>
                </label>
            </div>

            {/* Categories */}
            {categories.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                    <h3 className="font-semibold text-gray-800 mb-3 text-sm uppercase tracking-wide">Kateqoriya</h3>
                    <ul className="space-y-1">
                        <li>
                            <button
                                onClick={() => onFilterChange('category', '')}
                                className={`w-full text-left text-sm px-2 py-1 rounded-lg flex justify-between items-center transition-colors ${!filters.category ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>
                                <span>Hamısı</span>
                            </button>
                        </li>
                        {categories.map(cat => (
                            <li key={cat.name}>
                                <button
                                    onClick={() => onFilterChange('category', filters.category === cat.name ? '' : cat.name)}
                                    className={`w-full text-left text-sm px-2 py-1 rounded-lg flex justify-between items-center transition-colors ${filters.category === cat.name ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>
                                    <span className="capitalize">{cat.name}</span>
                                    <span className="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{cat.count}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Brands */}
            {brands.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                    <h3 className="font-semibold text-gray-800 mb-3 text-sm uppercase tracking-wide">Brend</h3>
                    <ul className="space-y-1 max-h-48 overflow-y-auto">
                        <li>
                            <button
                                onClick={() => onFilterChange('brand', '')}
                                className={`w-full text-left text-sm px-2 py-1 rounded-lg transition-colors ${!filters.brand ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>
                                Hamısı
                            </button>
                        </li>
                        {brands.map(b => (
                            <li key={b.name}>
                                <button
                                    onClick={() => onFilterChange('brand', filters.brand === b.name ? '' : b.name)}
                                    className={`w-full text-left text-sm px-2 py-1 rounded-lg flex justify-between items-center transition-colors ${filters.brand === b.name ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>
                                    <span>{b.name}</span>
                                    <span className="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{b.count}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Reset */}
            <button onClick={onReset}
                className="w-full py-2 text-sm text-red-500 hover:text-red-700 hover:bg-red-50 rounded-xl transition-colors border border-red-200">
                Filterləri Sıfırla
            </button>
        </aside>
    )
}
