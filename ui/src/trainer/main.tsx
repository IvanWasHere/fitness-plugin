import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import '@shared/styles/app.css';
import { ToastProvider } from '@shared/ToastProvider';
import { ThemeProvider } from '@shared/theme';
import { SessionProvider } from '@shared/session';
import { bootOrDefaults, mountNode } from '@shared/boot';
import { App } from './App';

// The trainer SPA entry (D10). Served to signed-in trainers at the configured
// URL (D9).
//
// The provider stack matches the other two entries, and has to: every screen
// uses TanStack Query, and mounting without QueryClientProvider throws
// "No QueryClient set" on first render — a blank page with the error only in
// the console, which is exactly how the admin entry was found to be missing it
// in W2.5.
const boot = bootOrDefaults('trainer');

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 2,
      // A trainer works from a desk on a stable connection, like an
      // administrator — not from a gym floor like a member.
      staleTime: 60_000,
      refetchOnWindowFocus: false,
    },
    mutations: {
      retry: 0,
    },
  },
});

createRoot(mountNode()).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <ThemeProvider theme={boot.theme}>
        <SessionProvider spa="trainer">
          <ToastProvider>
            <App />
          </ToastProvider>
        </SessionProvider>
      </ThemeProvider>
    </QueryClientProvider>
  </StrictMode>,
);
