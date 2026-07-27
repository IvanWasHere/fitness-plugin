import { BrowserRouter, NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { AuthPanel } from '@shared/AuthPanel';
import { Icon } from '@shared/components/Icon';
import { isResetLink } from '@shared/auth-route';
import { useSession } from '@shared/session-context';
import { useRequests } from './api/queries';
import { ClientDetail } from './screens/ClientDetail';
import { Clients } from './screens/Clients';
import { Dashboard } from './screens/Dashboard';
import { Requests } from './screens/Requests';

/**
 * The trainer SPA (plans/06-trainer-app.md, W3.4 slice 2).
 *
 * Same shell as the admin app — standalone at `/{base}`, served by role — and
 * the same nav pattern. The one addition is a **badge on Requests**: 06 notes
 * that "trainers will not check a tab they cannot see is populated", and an
 * inbound client request that sits unanswered is somebody waiting for a coach.
 */
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

          <nav aria-label="Trainer">
            <NavLink to="/" end className="fc-admin-nav__link">
              <Icon name="grid" size={16} />
              Dashboard
            </NavLink>
            <NavLink to="/clients" className="fc-admin-nav__link">
              <Icon name="user" size={16} />
              Clients
            </NavLink>
            <NavLink to="/requests" className="fc-admin-nav__link">
              <Icon name="bell" size={16} />
              Requests
              <RequestBadge />
            </NavLink>
          </nav>

          <SignOut />
        </aside>

        <main className="fc-admin-main">
          <Routes>
            <Route path="/" element={<Dashboard />} />
            <Route path="/clients" element={<Clients />} />
            <Route path="/clients/:id" element={<ClientDetail />} />
            <Route path="/requests" element={<Requests />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </main>
      </div>
    </BrowserRouter>
  );
}

/**
 * Pending requests, on the nav.
 *
 * Reuses the requests query rather than adding a count endpoint: the queue is
 * small by nature — it is people waiting for an answer — so the list *is* the
 * count.
 */
function RequestBadge() {
  const { data } = useRequests();
  const pending = data?.items.length ?? 0;

  if (pending === 0) {
    return null;
  }

  return (
    <span className="fc-nav-badge fc-nav-badge--inline" aria-label={`${pending} pending`}>
      {pending > 9 ? '9+' : pending}
    </span>
  );
}

function SignOut() {
  const { boot, logout } = useSession();

  return (
    <div className="fc-admin-nav__foot">
      <span className="fc-text-xs fc-text-muted fc-truncate">{boot.user?.display_name}</span>
      <button
        type="button"
        className="fc-btn fc-btn--icon"
        aria-label="Sign out"
        onClick={() => void logout()}
      >
        <Icon name="logOut" size={16} />
      </button>
    </div>
  );
}
