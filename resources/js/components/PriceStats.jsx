export function PriceStats({ stats, totalStock, uniqueBrands, tookMs, total }) {
    if (!stats) return null

    const items = [
        { label: 'Orta Qiymət', value: `$${stats.avg?.toFixed(2) ?? '—'}`, color: 'text-blue-600' },
        { label: 'Min Qiymət',  value: `$${stats.min?.toFixed(2) ?? '—'}`, color: 'text-green-600' },
        { label: 'Max Qiymət',  value: `$${stats.max?.toFixed(2) ?? '—'}`, color: 'text-red-500' },
        { label: 'Ümumi Stok',  value: totalStock?.toLocaleString() ?? '—', color: 'text-purple-600' },
        { label: 'Brend Sayı',  value: uniqueBrands ?? '—',                 color: 'text-orange-600' },
    ]

    return (
        <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                    Axtarış Statistikası
                </h3>
                <span className="text-xs text-gray-400">{total?.toLocaleString()} nəticə · {tookMs}ms</span>
            </div>
            <div className="grid grid-cols-5 gap-3">
                {items.map(item => (
                    <div key={item.label} className="text-center">
                        <div className={`text-base font-bold ${item.color}`}>{item.value}</div>
                        <div className="text-xs text-gray-400 mt-0.5">{item.label}</div>
                    </div>
                ))}
            </div>
        </div>
    )
}
