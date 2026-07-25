import { useEffect, type ReactNode } from 'react';
import type { BootTheme } from './boot';
import { applyThemeTokens } from './theme-tokens';

/**
 * Theme tokens → CSS custom properties (plans/07-theming.md).
 *
 * In production the PHP shell has *already* emitted these into
 * `<style id="fc-theme-vars">`, inline and before first paint, so there is no
 * flash of unthemed app. This provider is the client-side counterpart for the
 * two cases the shell cannot cover:
 *
 *   - `npm run dev` against the bare Vite server, where there is no PHP shell at
 *     all and the app would otherwise render on the fallback colours;
 *   - a theme that changes *after* boot (an admin saving settings in another
 *     tab, later work packages re-fetching `/auth/me`).
 */
export function ThemeProvider({ theme, children }: { theme: BootTheme; children: ReactNode }) {
  useEffect(() => {
    applyThemeTokens(theme, document.getElementById('fc-app'));
  }, [theme]);

  return <>{children}</>;
}
