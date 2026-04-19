import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SearchPage } from '@/pages/SearchPage'

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: 1,
            refetchOnWindowFocus: false,
        },
    },
})

export default function App() {
    return (
        <QueryClientProvider client={queryClient}>
            <SearchPage />
        </QueryClientProvider>
    )
}
