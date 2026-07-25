import { useState, type FormEvent, type ReactNode } from 'react';
import { ApiError } from './api';
import { initialView, resetParams, type AuthView, type ResetParams } from './auth-route';
import { useSession } from './session-context';

/**
 * The authentication screens (gap register §3.1 — no prototype, net new UI).
 *
 * Served by the *user* bundle: an anonymous visitor to `/{base}` always gets
 * that one, and the backend re-resolves the role once the session exists (D9).
 * A trainer or admin signing in here is handed off by SessionProvider.
 *
 * Routing is deliberately hand-rolled: react-router lands with the real screens
 * in W1.5, and until then a four-view panel does not justify the dependency.
 * The one view that must be reachable by URL is `reset` — it is the target of
 * the emailed link, complete with `key` and `login` in the query string.
 */
export function AuthPanel() {
  const { boot } = useSession();
  const [view, setView] = useState<AuthView>(() => initialView(boot.app.basename));

  return (
    <div className="fc-screen">
      <div className="fc-card">
        <h1 className="fc-brand">{boot.brand}</h1>
        <p className="fc-sub">{subtitleFor(view)}</p>

        {(view === 'login' || view === 'register') && boot.flags.registration_open && (
          <div className="fc-tabs" role="tablist">
            <button
              type="button"
              role="tab"
              className="fc-tab"
              aria-selected={view === 'login'}
              onClick={() => setView('login')}
            >
              Sign in
            </button>
            <button
              type="button"
              role="tab"
              className="fc-tab"
              aria-selected={view === 'register'}
              onClick={() => setView('register')}
            >
              Create account
            </button>
          </div>
        )}

        {view === 'login' && <LoginForm onForgot={() => setView('forgot')} />}
        {view === 'register' && <RegisterForm />}
        {view === 'forgot' && <ForgotForm onBack={() => setView('login')} />}
        {view === 'reset' && <ResetForm onDone={() => setView('login')} />}
      </div>
    </div>
  );
}

function subtitleFor(view: AuthView): string {
  switch (view) {
    case 'register':
      return 'Create your account to start training.';
    case 'forgot':
      return 'We will email you a link to choose a new password.';
    case 'reset':
      return 'Choose a new password for your account.';
    default:
      return 'Sign in to continue.';
  }
}

// ---------------------------------------------------------------- forms

function LoginForm({ onForgot }: { onForgot: () => void }) {
  const { login } = useSession();
  const [userLogin, setUserLogin] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);

  const { error, busy, submit } = useSubmit(() =>
    login({ user_login: userLogin, password, remember }),
  );

  return (
    <form onSubmit={submit} noValidate>
      <Alert error={error} />

      <Field label="Email">
        <input
          className="fc-input"
          type="email"
          autoComplete="username"
          required
          value={userLogin}
          onChange={(e) => setUserLogin(e.target.value)}
        />
      </Field>

      <Field label="Password">
        <input
          className="fc-input"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
      </Field>

      <label className="fc-check">
        <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
        Keep me signed in
      </label>

      <button className="fc-button" type="submit" disabled={busy}>
        {busy ? 'Signing in…' : 'Sign in'}
      </button>

      <p className="fc-footnote">
        <button type="button" className="fc-link" onClick={onForgot}>
          Forgot your password?
        </button>
      </p>
    </form>
  );
}

function RegisterForm() {
  const { register } = useSession();
  const [displayName, setDisplayName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const { error, busy, submit } = useSubmit(() =>
    register({ email, password, display_name: displayName }),
  );

  return (
    <form onSubmit={submit} noValidate>
      <Alert error={error} />

      <Field label="Your name">
        <input
          className="fc-input"
          type="text"
          autoComplete="name"
          value={displayName}
          onChange={(e) => setDisplayName(e.target.value)}
        />
      </Field>

      <Field label="Email">
        <input
          className="fc-input"
          type="email"
          autoComplete="username"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
      </Field>

      <Field label="Password">
        <input
          className="fc-input"
          type="password"
          autoComplete="new-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
      </Field>

      <button className="fc-button" type="submit" disabled={busy}>
        {busy ? 'Creating your account…' : 'Create account'}
      </button>
    </form>
  );
}

function ForgotForm({ onBack }: { onBack: () => void }) {
  const { forgotPassword } = useSession();
  const [userLogin, setUserLogin] = useState('');
  const [sent, setSent] = useState('');

  const { error, busy, submit } = useSubmit(async () => {
    setSent(await forgotPassword(userLogin));
  });

  return (
    <form onSubmit={submit} noValidate>
      <Alert error={error} />
      {sent !== '' && <p className="fc-alert fc-alert--ok">{sent}</p>}

      <Field label="Email">
        <input
          className="fc-input"
          type="email"
          autoComplete="username"
          required
          value={userLogin}
          onChange={(e) => setUserLogin(e.target.value)}
        />
      </Field>

      <button className="fc-button" type="submit" disabled={busy}>
        {busy ? 'Sending…' : 'Email me a reset link'}
      </button>

      <p className="fc-footnote">
        <button type="button" className="fc-link" onClick={onBack}>
          Back to sign in
        </button>
      </p>
    </form>
  );
}

function ResetForm({ onDone }: { onDone: () => void }) {
  const { resetPassword } = useSession();
  const [params] = useState<ResetParams>(resetParams);
  const [password, setPassword] = useState('');
  const [done, setDone] = useState('');

  const { error, busy, submit } = useSubmit(async () => {
    setDone(await resetPassword({ key: params.key, login: params.login, password }));
  });

  if (params.key === '' || params.login === '') {
    return (
      <>
        <p className="fc-alert fc-alert--error">
          That reset link is incomplete. Request a new one from the sign-in screen.
        </p>
        <button className="fc-button" type="button" onClick={onDone}>
          Back to sign in
        </button>
      </>
    );
  }

  if (done !== '') {
    return (
      <>
        <p className="fc-alert fc-alert--ok">{done}</p>
        <button className="fc-button" type="button" onClick={onDone}>
          Sign in
        </button>
      </>
    );
  }

  return (
    <form onSubmit={submit} noValidate>
      <Alert error={error} />

      <Field label="New password">
        <input
          className="fc-input"
          type="password"
          autoComplete="new-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
      </Field>

      <button className="fc-button" type="submit" disabled={busy}>
        {busy ? 'Saving…' : 'Save new password'}
      </button>
    </form>
  );
}

// ------------------------------------------------------------- plumbing

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="fc-field">
      <span className="fc-label">{label}</span>
      {children}
    </label>
  );
}

function Alert({ error }: { error: string }) {
  if (error === '') {
    return null;
  }

  return (
    <p className="fc-alert fc-alert--error" role="alert">
      {error}
    </p>
  );
}

/**
 * Submit handling every form here repeats: block the double-submit, turn an
 * ApiError into a sentence a person can act on, and never leave the button
 * spinning after a failure.
 */
function useSubmit(action: () => Promise<void>) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (busy) {
      return;
    }

    setError('');
    setBusy(true);

    try {
      await action();
    } catch (thrown) {
      setError(messageFor(thrown));
    } finally {
      setBusy(false);
    }
  };

  return { error, busy, submit };
}

function messageFor(thrown: unknown): string {
  if (!(thrown instanceof ApiError)) {
    return 'Could not reach the server. Check your connection and try again.';
  }

  // A rate limit is the one case where the server's message is less useful than
  // ours: the user needs the wait, not the bucket name.
  const retry = thrown.retryAfter;
  if (thrown.status === 429 && retry !== null) {
    const minutes = Math.ceil(retry / 60);
    return retry <= 90
      ? `Too many attempts. Try again in ${retry} seconds.`
      : `Too many attempts. Try again in about ${minutes} minutes.`;
  }

  return thrown.message;
}
