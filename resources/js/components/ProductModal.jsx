import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getProduct } from '@/api/products'

const CATEGORY_COLORS = {
    electronics: 'bg-blue-100 text-blue-700',
    clothing:    'bg-pink-100 text-pink-700',
    books:       'bg-yellow-100 text-yellow-700',
    home:        'bg-green-100 text-green-700',
    sports:      'bg-orange-100 text-orange-700',
    beauty:      'bg-purple-100 text-purple-700',
}

export function ProductModal({ productId, onClose }) {
    const { data: product, isLoading } = useQuery({
        queryKey: ['product', productId],
        queryFn: () => getProduct(productId),
        enabled: productId != null,
        staleTime: 60_000,
    })

    // Close on Escape key
    useEffect(() => {
        const handler = (e) => { if (e.key === 'Escape') onClose() }
        document.addEventListener('keydown', handler)
        return () => document.removeEventListener('keydown', handler)
    }, [onClose])

    // Prevent background scroll
    useEffect(() => {
        document.body.style.overflow = 'hidden'
        return () => { document.body.style.overflow = '' }
    }, [])

    const categoryColor = product ? (CATEGORY_COLORS[product.category] ?? 'bg-gray-100 text-gray-700') : ''

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            {/* Backdrop */}
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />

            {/* Panel */}
            <div className="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                {/* Close button */}
                <button onClick={onClose}
                    className="absolute top-4 right-4 p-2 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-colors z-10">
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                {isLoading ? (
                    <div className="flex items-center justify-center h-48">
                        <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin" />
                    </div>
                ) : product ? (
                    <div className="p-6">
                        {/* Category badge */}
                        <span className={`inline-block text-xs font-medium px-2.5 py-1 rounded-full capitalize mb-3 ${categoryColor}`}>
                            {product.category}
                        </span>

                        <h2 className="text-xl font-bold text-gray-900 mb-1">{product.name}</h2>
                        <p className="text-sm text-gray-500 mb-4">{product.brand}</p>

                        <p className="text-gray-600 text-sm leading-relaxed mb-5">{product.description}</p>

                        {/* Tags */}
                        {product.tags?.length > 0 && (
                            <div className="flex flex-wrap gap-1.5 mb-5">
                                {product.tags.map(tag => (
                                    <span key={tag} className="text-xs bg-gray-100 text-gray-600 px-2.5 py-1 rounded-full">{tag}</span>
                                ))}
                            </div>
                        )}

                        {/* Price + stock */}
                        <div className="flex items-center justify-between p-4 bg-gray-50 rounded-xl">
                            <div>
                                <div className="text-3xl font-bold text-gray-900">${product.price?.toFixed(2)}</div>
                                <div className="text-xs text-gray-400 mt-0.5">vergisiz qiymət</div>
                            </div>
                            <div className="text-right">
                                {product.stock > 0 ? (
                                    <>
                                        <div className="text-green-600 font-semibold text-sm">Stokda var</div>
                                        <div className="text-xs text-gray-400">{product.stock} ədəd</div>
                                    </>
                                ) : (
                                    <div className="text-red-500 font-semibold text-sm">Stok yoxdur</div>
                                )}
                            </div>
                        </div>

                        {/* Location */}
                        {product.location && (
                            <div className="mt-3 text-xs text-gray-400 flex items-center gap-1">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                {product.location.lat?.toFixed(4)}, {product.location.lon?.toFixed(4)}
                            </div>
                        )}

                        {/* ES metadata */}
                        <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-300 space-y-1">
                            <div>ES ID: {product.id}</div>
                            <div>Yaradılıb: {product.created_at}</div>
                        </div>
                    </div>
                ) : (
                    <div className="p-6 text-center text-gray-500">Məhsul tapılmadı.</div>
                )}
            </div>
        </div>
    )
}
