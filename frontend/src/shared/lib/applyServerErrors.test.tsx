import { act, renderHook } from '@testing-library/react';
import { AxiosError, type InternalAxiosRequestConfig } from 'axios';
import { useForm } from 'react-hook-form';
import { describe, expect, it } from 'vitest';
import { applyServerErrors } from './applyServerErrors';

type Values = { name: string; stages: { won: number } };

function apiError(status: number, data: unknown): AxiosError {
    const config = { headers: {} } as InternalAxiosRequestConfig;
    return new AxiosError('Request failed', 'ERR_BAD_REQUEST', config, null, {
        status,
        statusText: '',
        headers: {},
        config,
        data,
    });
}

function setup() {
    return renderHook(() => useForm<Values>({ defaultValues: { name: '', stages: { won: 0 } } }));
}

describe('applyServerErrors (14.2)', () => {
    it('maps a 422 errors payload onto fields, including dotted paths, first message wins', () => {
        const { result } = setup();
        let mapped = false;

        act(() => {
            mapped = applyServerErrors(
                result.current,
                apiError(422, {
                    message: 'The given data was invalid.',
                    errors: { name: ['Name is taken', 'second message'], 'stages.won': ['Too high'] },
                    code: 'validation_failed',
                }),
            );
        });

        expect(mapped).toBe(true);
        expect(result.current.getFieldState('name').error?.message).toBe('Name is taken');
        expect(result.current.getFieldState('stages.won').error?.message).toBe('Too high');
    });

    it('returns false and sets nothing for non-validation failures', () => {
        const { result } = setup();

        expect(applyServerErrors(result.current, new Error('boom'))).toBe(false);
        expect(
            applyServerErrors(result.current, apiError(403, { message: 'Forbidden', errors: [], code: 'http_403' })),
        ).toBe(false);
        expect(result.current.getFieldState('name').error).toBeUndefined();
    });

    it('skips fields whose message list is empty', () => {
        const { result } = setup();

        expect(
            applyServerErrors(result.current, apiError(422, { message: 'Invalid', errors: { name: [] }, code: 'validation_failed' })),
        ).toBe(false);
    });
});
