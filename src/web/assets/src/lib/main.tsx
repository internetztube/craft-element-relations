import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import Pagination from './Pagination.tsx'
import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';


const inputs = [...document.querySelectorAll('.element-relations-input')]

inputs.forEach((input) => {
    const endpoint = input.getAttribute('data-endpoint') || ''
    const queryClient = new QueryClient();
    createRoot(input).render(
        <StrictMode>
            <QueryClientProvider client={queryClient}>
                <Pagination endpoint={endpoint} />
            </QueryClientProvider>
        </StrictMode>,
    )
})

