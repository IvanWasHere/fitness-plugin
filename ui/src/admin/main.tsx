import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import '@shared/styles/app.css';
import { ToastProvider } from '@shared/ToastProvider';
import { ThemeProvider } from '@shared/theme';
import { SessionProvider } from '@shared/session';
import { bootOrDefaults, mountNode } from '@shared/boot';
import { App } from './App';

// The admin SPA entry (D10). The backend serves this bundle to signed-in
// administrators at the configured URL (D9) — it is a standalone front-end app,
// not a wp-admin page, so there is no host stylesheet to scope against.
//
// The provider stack mirrors the member entry, and has to: every screen here
// uses TanStack Query, and mounting without QueryClientProvider throws
// "No QueryClient set" on first render — a blank page with the error only in the
// console, which is exactly how this was found.
const boot = bootOrDefaults('admin');

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 2,
      // Longer than the member app's: admin tables are read from a desk on a
      // stable connection, and refetching a 50-row table every thirty seconds
      // while someone reads it is churn for nothing.
      staleTime: 60_000,
      refetchOnWindowFocus: false,
    },
    mutations: {
      // Admin writes are not idempotent — a retried create makes two records.
      retry: 0,
    },
  },
});

createRoot(mountNode()).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <ThemeProvider theme={boot.theme}>
        <SessionProvider spa="admin">
          <ToastProvider>
            <App />
          </ToastProvider>
        </SessionProvider>
      </ThemeProvider>
    </QueryClientProvider>
  </StrictMode>,
);
