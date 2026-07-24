import type { BootPayload } from './boot';

/**
 * Shared placeholder shell for all three SPAs (Phase 0.3 scaffold).
 *
 * Living in `src/shared/`, this is imported by every entry, so the build proves
 * shared code is deduplicated across the user/trainer/admin bundles. Real screens
 * replace it per work package.
 */
export function AppShell({ role, boot }: { role: 'user' | 'trainer' | 'admin'; boot: BootPayload | null }) {
  const brand = boot?.brand ?? 'FitForge';
  return (
    <main
      style={{
        minHeight: '100vh',
        display: 'grid',
        placeItems: 'center',
        fontFamily: 'system-ui, sans-serif',
        background: '#0B0E13',
        color: '#E8ECF1',
      }}
    >
      <div style={{ textAlign: 'center' }}>
        <h1 style={{ margin: 0, fontSize: 28 }}>{brand}</h1>
        <p style={{ opacity: 0.7 }}>
          {role} SPA — scaffold ready
        </p>
        <code style={{ fontSize: 12, opacity: 0.5 }}>
          {boot ? `booted as ${boot.user?.display_name ?? 'guest'} · base ${boot.app.base}` : 'no boot payload (dev)'}
        </code>
      </div>
    </main>
  );
}
