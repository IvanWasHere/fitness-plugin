import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { AppShell } from '@shared/AppShell';
import { SessionProvider } from '@shared/session';
import { mountNode } from '@shared/boot';

// The user SPA entry (D10). The backend serves this bundle to logged-in members
// at the configured URL (D9) — and to anonymous visitors, who get the login
// panel from it. Real screens arrive per work package.
createRoot(mountNode()).render(
  <StrictMode>
    <SessionProvider spa="user">
      <AppShell role="user" />
    </SessionProvider>
  </StrictMode>,
);
