import {useEffect, useState} from 'react'
import usePagination from '@mui/material/usePagination';
import {useQuery} from '@tanstack/react-query'

const Pagination = ({endpoint}: { endpoint: string }) => {
    const [currentPage, setCurrentPage] = useState(1)
    const [totalPages, setTotalPages] = useState(1)
    const [html, setHtml] = useState("")
    const {isFetching, isError, refetch} = useQuery({
        queryKey: [endpoint], async queryFn() {
            const url = `${endpoint}&page=${currentPage - 1}`
            const response = await fetch(url)
            const data = await response.json()
            setHtml(data.html)
            setTotalPages(data.totalPages)
            return true
        }
    })

    const {items} = usePagination({
        count: totalPages,
    });

    useEffect(() => {
        const selectedItem = items.find((item) => item.selected)
        setCurrentPage(selectedItem?.page || 1)
        console.log('useeffect!!', selectedItem?.page)
    }, [items]);

    useEffect(() => {
        refetch()
    }, [currentPage]);

    if (isError) {
        return <p>An unexpected error occured! :(</p>;
    }

    if (isFetching && !html) {
        return <div className="spinner"></div>;
    }

    return (
        <>
            <div dangerouslySetInnerHTML={{__html: html}}/>
            {totalPages > 1 ? (
                <>
                    <br/>
                    <ul className="pagination flex" style={{
                        opacity: isFetching ? 0.5 : 1,
                        pointerEvents: isFetching ? 'none' : 'all'
                    }}>
                        {items.map(({page, type, selected, ...item}, index) => {
                            let children = null;

                            if (type === 'start-ellipsis' || type === 'end-ellipsis') {
                                children = (
                                    <button
                                        type="button"
                                        className={'page-link disabled'}
                                        {...item}
                                    >
                                        …
                                    </button>
                                );
                            } else if (type === 'page') {
                                children = (
                                    <button
                                        type="button"
                                        className={`page-link ${selected ? 'disabled' : ''}`}
                                        style={{
                                            fontWeight: selected ? 'bold' : undefined,
                                        }}
                                        {...item}
                                    >
                                        {page}
                                    </button>
                                );
                            } else if (type === 'next') {

                                children = (
                                    <button
                                        type="button"
                                        className={`page-link next-page ${item.disabled ? 'disabled' : ''}`}
                                        title={type}
                                        {...item}
                                    ></button>
                                );
                            } else if (type === 'previous') {
                                children = (
                                    <button
                                        type="button"
                                        className={`page-link prev-page ${item.disabled ? 'disabled' : ''}`}
                                        title={type}
                                        {...item}
                                    ></button>

                                );
                            }

                            return <li key={index}>{children}</li>;
                        })}
                        <li style={{
                            opacity: isFetching ? 1 : 0
                        }}>
                            <div className="spinner"></div>
                        </li>

                    </ul>
                </>
            ) : ''}
        </>
    )
}

export default Pagination
