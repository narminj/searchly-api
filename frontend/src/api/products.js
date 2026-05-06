import axios from 'axios'

// In dev: Vite proxy forwards /api → Laravel (no CORS needed)
// In prod: set VITE_API_URL in .env to the Laravel server URL
const client = axios.create({
    baseURL: import.meta.env.VITE_API_URL ? `${import.meta.env.VITE_API_URL}/api` : '/api',
    headers: { Accept: 'application/json' },
})

export async function searchProducts(params = {}) {
    const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')
    )
    const { data } = await client.get('/products/search', { params: clean })
    return data
}

export async function getProduct(id) {
    const { data } = await client.get(`/products/${id}`)
    return data.data
}

export async function suggestProducts(q) {
    if (!q || q.length < 2) return []
    const { data } = await client.get('/products/suggest', { params: { q } })
    return data.suggestions ?? []
}
