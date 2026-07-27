import { useState } from 'react';
import { BrowserRouter, NavLink, Navigate, Route, Routes, useNavigate } from 'react-router-dom';
import { AuthPanel } from '@shared/AuthPanel';
import { Icon, type IconName } from '@shared/components/Icon';
import { Button } from '@shared/components/ui';
import { isResetLink } from '@shared/auth-route';
import { useSession } from '@shared/session-context';
import { Celebration } from './features/player/Celebration';
import { Player } from './features/player/Player';
import { Dashboard } from './screens/Dashboard';
import { WorkoutDetail } from './screens/WorkoutDetail';
import { Nutrition } from './screens/Nutrition';
import { Workouts } from './screens/Workouts';
import { PlayerProvider } from './state/PlayerProvider';
import { usePlayer } from './state/player-context';

/**
 * The member app: router, chrome, and the two full-screen overlays
 * (plans/04-user-app.md).
 *
 * `basename` comes from the boot payload, never from a constant — the front-end
 * URL is configurable (D9), so an install serving the app at `/gym` must route
 * correctly without a rebuild.
 *
 * Navigation is data-driven from `NAV` below; screens that arrive in later work
 * packages (progress, nutrition, health, messages…) are one line each.
 */
interface NavItem {
  to: string;
  label: string;
  icon: IconName;
}

const NAV: NavItem[] = [
  { to: '/', label: 'Home', icon: 'home' },
  { to: '/workouts', label: 'Workouts', icon: 'dumbbell' },
  { to: '/nutrition', label: 'Nutrition', icon: 'flame' },
];

export function App() {
  const { boot, isAuthenticated } = useSession();

  // An emailed reset link outranks an existing session — see AppShell.
  if (!isAuthenticated || isResetLink(boot.app.basename)) {
    return <AuthPanel />;
  }

  return (
    <BrowserRouter basename={boot.app.basename}>
      <PlayerProvider>
        <Chrome />
      </PlayerProvider>
    </BrowserRouter>
  );
}

function Chrome() {
  const { boot, logout } = useSession();
  const player = usePlayer();
  const [menuOpen, setMenuOpen] = useState(false);

  return (
    <div className="fc-wrap">
      <Sidebar
        open={menuOpen}
        onNavigate={() => setMenuOpen(false)}
        onSignOut={() => void logout()}
      />

      <main className="fc-main">
        {/* The prototype had no way to open its off-canvas sidebar on mobile:
            `sidebarOpen` was initialised and set to false, never to true, so the
            entire primary navigation was unreachable below 768px. */}
        <div className="fc-topbar">
          <button
            type="button"
            className="fc-btn fc-btn--icon"
            aria-label={menuOpen ? 'Close menu' : 'Open menu'}
            aria-expanded={menuOpen}
            onClick={() => setMenuOpen((open) => !open)}
          >
            <Icon name={menuOpen ? 'x' : 'menu'} />
          </button>
          <strong>{boot.brand}</strong>
        </div>

        {player.session && !player.isOpen && <ResumeBanner />}

        <Routes>
          <Route path="/" element={<Dashboard />} />
          <Route path="/workouts" element={<Workouts />} />
          <Route path="/workouts/:id" element={<WorkoutDetail />} />
          <Route path="/nutrition" element={<Nutrition />} />
          {/* Auth routes are rendered by the panel above when signed out; a
              signed-in user landing on one belongs on the dashboard. */}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>

      <BottomNav />
      <Player />
      <CelebrationOutlet />
    </div>
  );
}

function Sidebar({
  open,
  onNavigate,
  onSignOut,
}: {
  open: boolean;
  onNavigate: () => void;
  onSignOut: () => void;
}) {
  const { boot } = useSession();

  return (
    <aside className={`fc-sidebar ${open ? 'fc-sidebar--open' : ''}`.trim()}>
      <div className="fc-logo" aria-hidden="true">
        {boot.brand.slice(0, 1).toUpperCase()}
      </div>

      <nav aria-label="Main">
        {NAV.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
            className="fc-nav-btn"
            onClick={onNavigate}
          >
            <Icon name={item.icon} size={20} title={item.label} />
          </NavLink>
        ))}
      </nav>

      <button type="button" className="fc-nav-btn" onClick={onSignOut}>
        <Icon name="logOut" size={20} title="Sign out" />
      </button>
    </aside>
  );
}

/**
 * The mobile bar. The prototype's "More" button navigated straight to Messages,
 * which left Subscription, Profile, Notifications and Support unreachable on a
 * phone; this lists the real destinations and grows with them.
 */
function BottomNav() {
  return (
    <nav className="fc-bottom-nav" aria-label="Main">
      <div className="fc-bottom-nav__row">
        {NAV.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.to === '/'} className="fc-bn-btn">
            <Icon name={item.icon} size={18} />
            {item.label}
          </NavLink>
        ))}
      </div>
    </nav>
  );
}

/** A workout is running but the player is minimised — offer the way back in. */
function ResumeBanner() {
  const player = usePlayer();

  if (!player.session) {
    return null;
  }

  return (
    <div className="fc-list-row fc-mb-24">
      <div
        className="fc-stat__icon"
        style={{ background: 'var(--accent-d)', color: 'var(--accent)' }}
      >
        <Icon name="play" />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="fc-font-bold fc-truncate">{player.session.workout_name}</div>
        <div className="fc-text-xs fc-text-muted">
          {player.session.status === 'paused' ? 'Paused' : 'In progress'} ·{' '}
          {Math.round(player.session.completion_percentage)}% done
        </div>
      </div>
      <Button variant="primary" size="sm" onClick={player.open}>
        Resume
      </Button>
    </div>
  );
}

function CelebrationOutlet() {
  const player = usePlayer();
  const navigate = useNavigate();

  if (!player.celebration) {
    return null;
  }

  return (
    <Celebration
      payload={player.celebration}
      onDone={() => {
        player.dismissCelebration();
        navigate('/');
      }}
    />
  );
}
