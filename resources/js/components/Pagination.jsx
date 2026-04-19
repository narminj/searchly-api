export function Pagination({ currentPage, lastPage, onPageChange }) {
    if (lastPage <= 1) return null

    const pages = buildPageList(currentPage, lastPage)

    return (
        <nav className="flex items-center justify-center gap-1 mt-8" aria-label="Pagination">
            {/* Previous */}
            <button
                onClick={() => onPageChange(currentPage - 1)}
                disabled={currentPage === 1}
                className="px-3 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                ← Əvvəlki
            </button>

            {pages.map((p, i) =>
                p === '...' ? (
                    <span key={`ellipsis-${i}`} className="px-2 py-2 text-gray-400 text-sm">…</span>
                ) : (
                    <button
                        key={p}
                        onClick={() => onPageChange(p)}
                        className={`w-9 h-9 rounded-lg text-sm font-medium transition-colors ${
                            p === currentPage
                                ? 'bg-blue-600 text-white shadow-sm'
                                : 'text-gray-600 hover:bg-gray-100'
                        }`}>
                        {p}
                    </button>
                )
            )}

            {/* Next */}
            <button
                onClick={() => onPageChange(currentPage + 1)}
                disabled={currentPage === lastPage}
                className="px-3 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                Növbəti →
            </button>
        </nav>
    )
}

function buildPageList(current, last) {
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)

    const pages = new Set([1, 2, current - 1, current, current + 1, last - 1, last])
    const sorted = [...pages].filter(p => p >= 1 && p <= last).sort((a, b) => a - b)

    const result = []
    let prev = 0
    for (const p of sorted) {
        if (p - prev > 1) result.push('...')
        result.push(p)
        prev = p
    }
    return result
}
