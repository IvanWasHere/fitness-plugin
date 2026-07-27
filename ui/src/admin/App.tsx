import { BrowserRouter, NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { AuthPanel } from '@shared/AuthPanel';
import { Icon, type IconName } from '@shared/components/Icon';
import { isResetLink } from '@shared/auth-route';
import { useSession } from '@shared/session-context';
import { CrudTable } from './components/CrudTable';
import { RESOURCES } from './resources';
import { Dashboard } from './screens/Dashboard';
import { Settings } from './screens/Settings';

/**
 * The admin SPA (plans/05-admin-app.md, W2.5).
 *
 * **Standalone, not a wp-admin page.** The roadmap's W2.5 line still says
 * "menu registration, mount, style scoping (option A from 05)"; that predates
 * the D9/D10 reset, and 05 says the A/B/C style-scoping decision is now moot
 * because this renders full-page at `/{base}` with no host CSS to fight. wp-admin
 * keeps only the thin launcher built in W1.3R — one menu entry, the app URL, and
 * the generated accounts.
 *
 * Navigation is built from the same `RESOURCES` configs the tables are, so a new
 * resource is one file and appears in the sidebar without touching this one.
 */
const NAV_ICONS: Record<string, IconName> = {
  users: 'user',
  trainers: 'trophy',
  workouts: 'dumbbell',
  foods: 'flame',
  meals: 'list',
  'health-entries': 'heart',
};

export function App() {
  const { boot, isAuthenticated } = useSession();

  if (!isAuthenticated || isResetLink(boot.app.basename)) {
    return <AuthPanel />;
  }

  return (
    <BrowserRouter basename={boot.app.basename}>
      <div className="fc-admin">
        <aside className="fc-admin-nav">
          <div className="fc-logo" aria-hidden="true">
            {boot.brand.slice(0, 1).toUpperCase()}
          </div>

          <nav aria-label="Admin">
            <NavLink to="/" end className="fc-admin-nav__link">
              <Icon name="grid" size={16} />
              Dashboard
            </NavLink>

            {RESOURCES.map((resource) => (
              <NavLink
                key={resource.resource}
                to={`/${resource.resource}`}
                className="fc-admin-nav__link"
              >
                <Icon name={NAV_ICONS[resource.resource] ?? 'list'} size={16} />
                {resource.title}
              </NavLink>
            ))}

            <NavLink to="/settings" className="fc-admin-nav__link">
              <Icon name="target" size={16} />
              Settings
            </NavLink>
          </nav>

          <SignOut />
        </aside>

        <main className="fc-admin-main">
          <Routes>
            <Route path="/" element={<Dashboard />} />

            {RESOURCES.map((resource) => (
              <Route
                key={resource.resource}
                path={`/${resource.resource}`}
                element={<CrudTable config={resource} />}
              />
            ))}

            <Route path="/settings" element={<Settings />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </main>
      </div>
    </BrowserRouter>
  );
}

function SignOut() {
  const { boot, logout } = useSession();

  return (
    <div className="fc-admin-nav__foot">
      <span className="fc-text-xs fc-text-muted fc-truncate">{boot.user?.display_name}</span>
      <button type="button" className="fc-btn fc-btn--icon" onClick={() => void logout()}>
        <Icon name="logOut" size={16} title="Sign out" />
      </button>
    </div>
  );
}
