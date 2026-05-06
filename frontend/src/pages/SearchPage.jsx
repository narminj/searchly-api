import { useState } from 'react'
import { SearchBar }    from '@/components/SearchBar'
import { Filters }      from '@/components/Filters'
import { ProductCard }  from '@/components/ProductCard'
import { Pagination }   from '@/components/Pagination'
import { PriceStats }   from '@/components/PriceStats'
import { ProductModal } from '@/components/ProductModal'
import { useSearch }    from '@/hooks/useSearch'

export function SearchPage() {
    const { query, setQuery, filters, setFilter, setPage, resetFilters, results, isLoading, isError } = useSearch()
    const [selectedId, setSelectedId] = useState(null)

    const aggs     = results?.aggregations ?? null
    const products = results?.data         ?? []
    const total    = results?.total        ?? 0

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Header */}
            <header className="bg-white border-b border-gray-200 shadow-sm sticky top-0 z-40">
                <div className="max-w-7xl mx-auto px-4 py-4 flex items-center gap-4">
                    <div className="flex-shrink-0 flex items-center gap-2">
                        <div className="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                            <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <span className="font-bold text-gray-900 hidden sm:block">ES Search</span>
                    </div>
                    <div className="flex-1">
                        <SearchBar value={query} onChange={setQuery} />
                    </div>
                </div>
            </header>

            <main className="max-w-7xl mx-auto px-4 py-6">
                {/* Stats */}
                {results && (
                    <div className="mb-5">
                        <PriceStats
                            stats={aggs?.price_stats}
                            totalStock={aggs?.total_stock}
                            uniqueBrands={aggs?.unique_brands}
                            tookMs={results.took_ms}
                            total={total}
                        />
                    </div>
                )}

                <div className="flex gap-6">
                    <Filters
                        filters={filters}
                        onFilterChange={setFilter}
                        onReset={resetFilters}
                        aggregations={aggs}
                    />

                    <div className="flex-1 min-w-0">
                        {/* Result count + active filter badges */}
                        <div className="flex items-center justify-between mb-4 flex-wrap gap-2">
                            <p className="text-sm text-gray-500">
                                {isLoading
                                    ? <span className="animate-pulse">Axtarılır...</span>
                                    : <><span className="font-semibold text-gray-800">{total.toLocaleString()}</span> nəticə tapıldı</>
                                }
                            </p>
                            <ActiveFilters filters={filters} onRemove={setFilter} />
                        </div>

                        {/* Error */}
                        {isError && (
                            <div className="bg-red-50 border border-red-200 text-red-700 rounded-xl p-4 mb-4 text-sm">
                                Xəta baş verdi. Elasticsearch serverini yoxlayın.
                            </div>
                        )}

                        {/* Loading skeleton */}
                        {isLoading && (
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                {Array.from({ length: 6 }).map((_, i) => (
                                    <div key={i} className="bg-white rounded-xl border border-gray-200 h-48 animate-pulse">
                                        <div className="h-1 bg-gray-200 rounded-t-xl" />
                                        <div className="p-4 space-y-3">
                                            <div className="h-4 bg-gray-200 rounded w-3/4" />
                                            <div className="h-3 bg-gray-100 rounded w-1/2" />
                                            <div className="h-3 bg-gray-100 rounded w-full" />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Empty */}
                        {!isLoading && products.length === 0 && (
                            <div className="text-center py-20">
                                <svg className="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1} d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p className="text-gray-500 font-medium">Nəticə tapılmadı</p>
                                <button onClick={resetFilters} className="mt-4 text-sm text-blue-600 hover:underline">Sıfırla</button>
                            </div>
                        )}

                        {/* Products */}
                        {!isLoading && products.length > 0 && (
                            <>
                                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                    {products.map((p, i) => (
                                        <ProductCard key={p.id ?? i} product={p} onClick={p => setSelectedId(p.id)} />
                                    ))}
                                </div>
                                <Pagination
                                    currentPage={results.current_page}
                                    lastPage={results.last_page}
                                    onPageChange={setPage}
                                />
                            </>
                        )}
                    </div>
                </div>
            </main>

            {selectedId != null && (
                <ProductModal productId={selectedId} onClose={() => setSelectedId(null)} />
            )}
        </div>
    )
}

function ActiveFilters({ filters, onRemove }) {
    const active = []
    if (filters.category)  active.push({ label: filters.category,        key: 'category',  val: '' })
    if (filters.brand)     active.push({ label: filters.brand,           key: 'brand',     val: '' })
    if (filters.price_min) active.push({ label: `min $${filters.price_min}`, key: 'price_min', val: '' })
    if (filters.price_max) active.push({ label: `max $${filters.price_max}`, key: 'price_max', val: '' })
    if (filters.in_stock)  active.push({ label: 'Stokda var',            key: 'in_stock',  val: false })
    if (!active.length) return null

    return (
        <div className="flex flex-wrap gap-1.5">
            {active.map(f => (
                <button key={f.key} onClick={() => onRemove(f.key, f.val)}
                    className="flex items-center gap-1 text-xs bg-blue-50 text-blue-700 border border-blue-200 px-2 py-1 rounded-full hover:bg-blue-100 transition-colors">
                    {f.label}
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            ))}
        </div>
    )
}
