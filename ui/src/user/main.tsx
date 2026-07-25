import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import '@shared/styles/app.css';
import { ToastProvider } from '@shared/ToastProvider';
import { ThemeProvider } from '@shared/theme';
import { SessionProvider } from '@shared/session';
import { bootOrDefaults, mountNode } from '@shared/boot';
import { App } from './App';

// The user SPA entry (D10). The backend serves this bundle to logged-in members
// at the configured URL (D9) — and to anonymous visitors, who get the login
// panel from it.
const boot = bootOrDefaults('user');

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Gym wifi drops constantly; two retries with backoff turns most of those
      // into a delay the user never notices.
      retry: 2,
      staleTime: 30_000,
      refetchOnWindowFocus: true,
    },
    mutations: {
      // Writes are not retried blindly — the set queue owns retry for the one
      // write that must survive a bad connection, and retrying anything else
      // risks repeating a state transition.
      retry: 0,
    },
  },
});

createRoot(mountNode()).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <ThemeProvider theme={boot.theme}>
        <SessionProvider spa="user">
          <ToastProvider>
            <App />
          </ToastProvider>
        </SessionProvider>
      </ThemeProvider>
    </QueryClientProvider>
  </StrictMode>,
);
