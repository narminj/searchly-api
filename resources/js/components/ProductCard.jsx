function HighlightedText({ html, fallback }) {
    if (html) {
        return <span dangerouslySetInnerHTML={{ __html: html }} />
    }
    return <span>{fallback}</span>
}

const CATEGORY_COLORS = {
    electronics: 'bg-blue-100 text-blue-700',
    clothing:    'bg-pink-100 text-pink-700',
    books:       'bg-yellow-100 text-yellow-700',
    home:        'bg-green-100 text-green-700',
    sports:      'bg-orange-100 text-orange-700',
    beauty:      'bg-purple-100 text-purple-700',
}

export function ProductCard({ product, onClick }) {
    const categoryColor = CATEGORY_COLORS[product.category] ?? 'bg-gray-100 text-gray-700'

    return (
        <article
            onClick={() => onClick?.(product)}
            className="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all cursor-pointer overflow-hidden group">

            {/* Color band by category */}
            <div className={`h-1 w-full ${categoryColor.split(' ')[0]}`} />

            <div className="p-4">
                {/* Header */}
                <div className="flex items-start justify-between gap-2 mb-2">
                    <h2 className="font-semibold text-gray-900 text-sm leading-snug group-hover:text-blue-600 transition-colors line-clamp-2">
                        <HighlightedText
                            html={product.highlighted_name}
                            fallback={product.name}
                        />
                    </h2>
                    <span className={`flex-shrink-0 text-xs font-medium px-2 py-0.5 rounded-full capitalize ${categoryColor}`}>
                        {product.category}
                    </span>
                </div>

                {/* Brand */}
                <p className="text-xs text-gray-500 mb-2">{product.brand}</p>

                {/* Description highlight */}
                {product.highlighted_description && (
                    <p className="text-xs text-gray-600 mb-3 line-clamp-2 leading-relaxed"
                        dangerouslySetInnerHTML={{ __html: product.highlighted_description }} />
                )}

                {/* Tags */}
                {product.tags?.length > 0 && (
                    <div className="flex flex-wrap gap-1 mb-3">
                        {product.tags.slice(0, 4).map(tag => (
                            <span key={tag} className="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">
                                {tag}
                            </span>
                        ))}
                    </div>
                )}

                {/* Footer */}
                <div className="flex items-center justify-between mt-2 pt-3 border-t border-gray-100">
                    <span className="text-lg font-bold text-gray-900">
                        ${product.price?.toFixed(2)}
                    </span>
                    <div className="flex items-center gap-2">
                        {product.stock > 0 ? (
                            <span className="text-xs text-green-600 font-medium flex items-center gap-1">
                                <span className="w-1.5 h-1.5 bg-green-500 rounded-full inline-block" />
                                {product.stock} stok
                            </span>
                        ) : (
                            <span className="text-xs text-red-500 font-medium flex items-center gap-1">
                                <span className="w-1.5 h-1.5 bg-red-400 rounded-full inline-block" />
                                Stok yox
                            </span>
                        )}
                    </div>
                </div>

                {/* ES relevance score (dev hint) */}
                {product._score != null && (
                    <div className="mt-2 text-xs text-gray-300 text-right">
                        score: {product._score.toFixed(2)}
                    </div>
                )}
            </div>
        </article>
    )
}
