import { useState, useRef, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { suggestProducts } from '@/api/products'
import { useDebounce } from '@/hooks/useDebounce'

export function SearchBar({ value, onChange }) {
    const [open, setOpen]   = useState(false)
    const [typed, setTyped] = useState(value)
    const inputRef          = useRef(null)
    const listRef           = useRef(null)
    const debouncedTyped    = useDebounce(typed, 300)

    useEffect(() => { setTyped(value) }, [value])

    const { data: suggestions = [] } = useQuery({
        queryKey: ['suggest', debouncedTyped],
        queryFn: () => suggestProducts(debouncedTyped),
        enabled: debouncedTyped.length >= 2,
        staleTime: 60_000,
    })

    const handleSelect = (name) => { setTyped(name); onChange(name); setOpen(false) }
    const handleSubmit = (e)    => { e.preventDefault(); onChange(typed); setOpen(false) }

    useEffect(() => {
        const h = (e) => {
            if (!inputRef.current?.contains(e.target) && !listRef.current?.contains(e.target))
                setOpen(false)
        }
        document.addEventListener('mousedown', h)
        return () => document.removeEventListener('mousedown', h)
    }, [])

    return (
        <form onSubmit={handleSubmit} className="relative w-full">
            <div className="flex items-center gap-2">
                <div className="relative flex-1">
                    <input
                        ref={inputRef}
                        type="text"
                        value={typed}
                        onChange={e => { setTyped(e.target.value); setOpen(true) }}
                        onFocus={() => setOpen(true)}
                        placeholder="Məhsul axtarın... (Samsung, laptop, nike...)"
                        className="w-full px-4 py-3 pl-11 bg-white border border-gray-300 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 text-base"
                    />
                    <svg className="absolute left-3 top-3.5 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    {typed && (
                        <button type="button" onClick={() => { setTyped(''); onChange('') }}
                            className="absolute right-3 top-3.5 text-gray-400 hover:text-gray-600">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    )}
                </div>
                <button type="submit"
                    className="px-6 py-3 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition-colors shadow-sm whitespace-nowrap">
                    Axtar
                </button>
            </div>

            {open && suggestions.length > 0 && (
                <ul ref={listRef}
                    className="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden max-h-60 overflow-y-auto">
                    {suggestions.map((name, i) => (
                        <li key={i}>
                            <button type="button" onMouseDown={() => handleSelect(name)}
                                className="w-full text-left px-4 py-2.5 hover:bg-blue-50 text-sm text-gray-700 flex items-center gap-2">
                                <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                {name}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </form>
    )
}
