import { useState, useCallback } from 'react'
import { useQuery } from '@tanstack/react-query'
import { searchProducts } from '@/api/products'
import { useDebounce } from './useDebounce'

const DEFAULT_FILTERS = {
    category: '',
    brand: '',
    price_min: '',
    price_max: '',
    in_stock: false,
    sort: 'relevance',
    page: 1,
    per_page: 12,
}

export function useSearch() {
    const [query, setQuery]     = useState('')
    const [filters, setFilters] = useState(DEFAULT_FILTERS)

    const debouncedQuery   = useDebounce(query, 350)
    const debouncedFilters = useDebounce(filters, 200)

    const params = {
        q: debouncedQuery,
        ...debouncedFilters,
        in_stock: debouncedFilters.in_stock ? 1 : undefined,
    }

    const { data, isLoading, isError } = useQuery({
        queryKey: ['products', params],
        queryFn: () => searchProducts(params),
        keepPreviousData: true,
        staleTime: 30_000,
    })

    const setFilter = useCallback((key, value) => {
        setFilters(prev => ({ ...prev, [key]: value, page: 1 }))
    }, [])

    const setPage = useCallback((page) => {
        setFilters(prev => ({ ...prev, page }))
    }, [])

    const resetFilters = useCallback(() => {
        setFilters(DEFAULT_FILTERS)
        setQuery('')
    }, [])

    return {
        query, setQuery,
        filters, setFilter, setPage, resetFilters,
        results: data ?? null,
        isLoading, isError,
    }
}
