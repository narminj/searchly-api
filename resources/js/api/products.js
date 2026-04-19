import axios from 'axios'

const client = axios.create({
    baseURL: '/api',
    headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    },
})

/**
 * Search products with all supported filters.
 *
 * @param {Object} params
 * @param {string}   params.q            - Free-text query
 * @param {string}   params.category     - Single category
 * @param {string[]} params.categories   - Multiple categories (OR)
 * @param {string}   params.brand        - Single brand
 * @param {string[]} params.brands       - Multiple brands (OR)
 * @param {string[]} params.tags         - Tags (AND)
 * @param {number}   params.price_min    - Min price
 * @param {number}   params.price_max    - Max price
 * @param {boolean}  params.in_stock     - In-stock only
 * @param {string}   params.sort         - Sort order
 * @param {number}   params.page         - Page number
 * @param {number}   params.per_page     - Results per page
 * @param {string}   params.from_date    - From date (Y-m-d)
 * @param {string}   params.to_date      - To date (Y-m-d)
 * @param {number}   params.geo_lat      - Latitude
 * @param {number}   params.geo_lon      - Longitude
 * @param {string}   params.geo_distance - Distance radius (e.g. "50km")
 */
export async function searchProducts(params = {}) {
    // Remove null/undefined/empty string values before sending
    const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')
    )
    const { data } = await client.get('/products/search', { params: clean })
    return data
}

/**
 * Fetch a single product document by ID.
 */
export async function getProduct(id) {
    const { data } = await client.get(`/products/${id}`)
    return data.data
}

/**
 * Autocomplete suggestions for a prefix string.
 * Returns an array of name strings.
 */
export async function suggestProducts(q) {
    if (!q || q.length < 2) return []
    const { data } = await client.get('/products/suggest', { params: { q } })
    return data.suggestions ?? []
}
