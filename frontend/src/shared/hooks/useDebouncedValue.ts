import { useEffect, useState } from 'react';

export function useDebouncedValue<T>(value: T, delay = 300): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const id = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(id); // cancels if value changes again before the delay
    }, [value, delay]);

    return debounced;
}
