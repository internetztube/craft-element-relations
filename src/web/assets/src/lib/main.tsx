import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import Pagination from './Pagination.tsx'
import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';

const init = (element: HTMLElement, endpoint: string) => {
    const queryClient = new QueryClient();
    createRoot(element).render(
        <StrictMode>
            <QueryClientProvider client={queryClient}>
                <Pagination endpoint={endpoint} />
            </QueryClientProvider>
        </StrictMode>,
    )
}

export default init

