import './styles/app.css';
import { AuthPanel } from './AuthPanel';
import { isResetLink } from './auth-route';
import { useSession } from './session-context';
import type { BootUser, SpaName } from './boot';

/**
 * The shell all three SPAs mount (W1.3).
 *
 * Two states, and W1.3's exit criterion is exactly this fork: no session → the
 * login panel; a session → an empty role shell showing the signed-in name with
 * the active theme applied. The role's real screens replace the second branch
 * per work package (user W1.5, admin W2.x, trainer W3.x).
 */
const ROLE_LABEL: Record<SpaName, string> = {
  user: 'Member',
  trainer: 'Trainer',
  admin: 'Administrator',
};

/** Tokens rendered as swatches — proof the theme reached the browser. */
const SWATCHES = ['primary', 'secondary', 'info', 'warning', 'danger'];

export function AppShell({ role }: { role: SpaName }) {
  const { boot, isAuthenticated } = useSession();

  // An emailed reset link wins over an existing session: a user who is still
  // signed in on this browser and asked for a reset from their phone came here
  // to change their password, not to see a dashboard.
  if (!isAuthenticated || isResetLink(boot.app.basename)) {
    return <AuthPanel />;
  }

  return <RoleShell role={role} />;
}

function RoleShell({ role }: { role: SpaName }) {
  const { boot, logout } = useSession();
  const user = boot.user as BootUser;

  return (
    <div className="fc-screen">
      <div className="fc-auth-card fc-text-center">
        {user.avatar_url && <img className="fc-avatar" src={user.avatar_url} alt="" />}
        <span className="fc-role">{ROLE_LABEL[role]}</span>
        <h1 className="fc-brand">{user.display_name}</h1>
        <p className="fc-sub">
          Signed in to {boot.brand}. The {role} screens arrive in a later work package.
        </p>

        <div className="fc-swatches" aria-hidden="true">
          {SWATCHES.map((name) => (
            <span
              key={name}
              className="fc-swatch"
              style={{ background: `var(--fc-color-${name})` }}
            />
          ))}
        </div>

        <p className="fc-meta">
          {boot.theme.theme_name ?? 'default'} theme · {boot.counts.unread_notifications}{' '}
          notifications · {boot.trainers.length} trainer{boot.trainers.length === 1 ? '' : 's'}
        </p>

        <button
          className="fc-btn fc-btn--primary fc-btn--block"
          type="button"
          onClick={() => void logout()}
        >
          Sign out
        </button>
      </div>
    </div>
  );
}
