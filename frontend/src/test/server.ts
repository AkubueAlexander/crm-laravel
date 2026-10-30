import { setupServer } from 'msw/node';

// 15.3: one MSW server for the whole test run. Tests add handlers with server.use(...); setup.ts resets them.
export const server = setupServer();
