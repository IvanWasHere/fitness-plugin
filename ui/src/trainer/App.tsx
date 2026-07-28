import { BrowserRouter, NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { AuthPanel } from '@shared/AuthPanel';
import { Icon } from '@shared/components/Icon';
import { isResetLink } from '@shared/auth-route';
import { useUnreadMessages } from '@shared/api/messages';
import { useSession } from '@shared/session-context';
import { useRequests } from './api/queries';
import { ClientDetail } from './screens/ClientDetail';
import { Clients } from './screens/Clients';
import { Dashboard } from './screens/Dashboard';
import { FoodPlans } from './screens/FoodPlans';
import { Messages } from './screens/Messages';
import { Plans } from './screens/Plans';
import { Profile } from './screens/Profile';
import { Requests } from './screens/Requests';
import { WorkoutBuilder } from './screens/WorkoutBuilder';
import { Workouts } from './screens/Workouts';

/**
 * The trainer SPA (plans/06-trainer-app.md, W3.4 slices 2–3).
 *
 * Same shell as the admin app — standalone at `/{base}`, served by role — and
 * the same nav pattern. Two badges: **Requests**, because 06 notes that
 * "trainers will not check a tab they cannot see is populated" and an unanswered
 * request is somebody waiting for a coach; and **Messages**, for the same reason
 * and from the same cheap unread-count poll the member app uses.
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
            <NavLink to="/messages" className="fc-admin-nav__link">
              <Icon name="mail" size={16} />
              Messages
              <MessageBadge />
            </NavLink>
            <NavLink to="/workouts" className="fc-admin-nav__link">
              <Icon name="dumbbell" size={16} />
              Workouts
            </NavLink>
            <NavLink to="/plans" className="fc-admin-nav__link">
              <Icon name="target" size={16} />
              Plans
            </NavLink>
            <NavLink to="/food-plans" className="fc-admin-nav__link">
              <Icon name="flame" size={16} />
              Food plans
            </NavLink>
            <NavLink to="/profile" className="fc-admin-nav__link">
              <Icon name="user" size={16} />
              Profile
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
            <Route path="/messages" element={<Messages />} />
            <Route path="/workouts" element={<Workouts />} />
            <Route path="/workouts/:id" element={<WorkoutBuilder />} />
            <Route path="/plans" element={<Plans />} />
            <Route path="/food-plans" element={<FoodPlans />} />
            <Route path="/profile" element={<Profile />} />
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

/**
 * Unread messages, on the nav.
 *
 * Unlike the request badge this does *not* mount the thread list: the count
 * comes from `GET /messages/unread-count`, a single indexed SUM, so an idle
 * trainer tab is not refetching every conversation every fifteen seconds to
 * derive one number.
 */
function MessageBadge() {
  const unread = useUnreadMessages();

  if (unread === 0) {
    return null;
  }

  return (
    <span className="fc-nav-badge fc-nav-badge--inline" aria-label={`${unread} unread`}>
      {unread > 9 ? '9+' : unread}
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
