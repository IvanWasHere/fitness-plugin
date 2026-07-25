import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { AppShell } from '@shared/AppShell';
import { SessionProvider } from '@shared/session';
import { mountNode } from '@shared/boot';

// The admin SPA entry (D10). The backend serves this bundle to logged-in
// admins at the configured URL (D9). Real screens arrive per work package.
createRoot(mountNode()).render(
  <StrictMode>
    <SessionProvider spa="admin">
      <AppShell role="admin" />
    </SessionProvider>
  </StrictMode>,
);
