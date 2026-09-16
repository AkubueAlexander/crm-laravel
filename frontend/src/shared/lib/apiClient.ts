import axios, { type AxiosError } from 'axios';


export const apiClient = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    headers: {
        Accept: 'application/json',
    },
});

export async function ensureCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export type ApiErrorShape = {
    message: string;
    errors: Record<string, string[]>;
    code: string;
};


export function isApiError(error: unknown): error is AxiosError<ApiErrorShape> {
    return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string';
}
