import { useState } from 'react';
import { Button, Card, ErrorState, Skeleton } from '@shared/components/ui';
import { useAdminSettings, useUpdateSettings } from '../api/queries';

/**
 * Settings, including the App URL (plans/05-admin-app.md#settings--app-url, W2.5).
 *
 * The App URL is the one setting that can take the whole front-end offline —
 * including this screen, which is served from it. So:
 *
 *   - it previews the resulting URL as you type, because a slug is abstract and
 *     a URL is not;
 *   - the server validates it against reserved paths and existing pages and
 *     refuses with a reason, rather than accepting a value that silently loses
 *     to a rewrite rule;
 *   - it is **also** editable from the thin wp-admin launcher, which is the
 *     break-glass route back if it is ever set wrong. That duplication is
 *     deliberate and is the only setting that has it.
 *
 * Saving a moved slug warns that existing links break, because they do.
 */
const FEATURE_LABELS: Record<string, string> = {
  messaging_enabled: 'Messaging between members and trainers',
  nutrition_enabled: 'Nutrition logging',
  health_enabled: 'Health and measurement tracking',
  support_enabled: 'Support tickets',
  trainer_directory: 'Trainer directory and requests',
  jwt_api_enabled: 'JWT API for mobile clients',
  registration_open: 'Open registration',
};

export function Settings() {
  const { data, isLoading, error, refetch } = useAdminSettings();
  const save = useUpdateSettings();

  const [draft, setDraft] = useState<Record<string, Record<string, unknown>>>({});

  if (error) {
    return <ErrorState message={error.message} onRetry={() => void refetch()} />;
  }

  if (isLoading || !data) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Settings</h1>
        </header>
        <Skeleton height={320} />
      </>
    );
  }

  const value = (group: 'general' | 'email', key: string): string =>
    String(draft[group]?.[key] ?? (data[group] as Record<string, unknown>)[key] ?? '');

  const feature = (key: string): boolean => Boolean(draft.features?.[key] ?? data.features[key]);

  const set = (group: string, key: string, next: unknown) =>
    setDraft((d) => ({ ...d, [group]: { ...(d[group] ?? {}), [key]: next } }));

  const slug = value('general', 'app_base');
  const slugMoved = slug !== data.general.app_base;
  const previewUrl = `${data.context.site_url.replace(/\/$/, '')}/${slug}/`;
  const slugReserved = data.context.reserved.includes(slug);

  const submit = (group: string) => {
    save.mutate({ [group]: draft[group] ?? {} });
  };

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>Settings</h1>
          <p className="fc-text-sm fc-text-muted">The app lives at {data.context.app_url}</p>
        </div>
      </header>

      <Card className="fc-mb-24">
        <h3 className="fc-text-lg fc-mb-16">General</h3>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-brand">
            Brand name
          </label>
          <input
            id="fc-brand"
            value={value('general', 'name')}
            onChange={(e) => set('general', 'name', e.target.value)}
          />
        </div>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-slug">
            App URL
          </label>
          <div className="fc-slug-input">
            <span className="fc-text-sm fc-text-muted">
              {data.context.site_url.replace(/^https?:\/\//, '').replace(/\/$/, '')}/
            </span>
            <input
              id="fc-slug"
              value={slug}
              onChange={(e) => set('general', 'app_base', e.target.value)}
            />
          </div>
          <p className="fc-text-xs fc-text-muted">
            The whole front-end will be served from <code>{previewUrl}</code>
          </p>

          {slugReserved && (
            <p className="fc-text-sm fc-text-danger">
              &ldquo;{slug}&rdquo; is reserved by WordPress. Pick another slug.
            </p>
          )}

          {slugMoved && !slugReserved && (
            <p className="fc-text-sm fc-admin-notice">
              <span aria-hidden="true">⚠</span> Saving moves the app. Existing links to{' '}
              <code>{data.context.app_url}</code> will stop working, and this screen will move with
              it. If it goes wrong, the same setting is editable from the FitnessClub menu in
              wp-admin.
            </p>
          )}
        </div>

        {save.error && <p className="fc-text-sm fc-text-danger">{save.error.message}</p>}
        {data.rewrites_flushed && (
          <p className="fc-text-sm fc-text-accent">
            Saved. The app now lives at {data.context.app_url}
          </p>
        )}

        <Button
          variant="primary"
          disabled={save.isPending || slugReserved || slug.trim() === ''}
          onClick={() => submit('general')}
        >
          {save.isPending ? 'Saving…' : 'Save general'}
        </Button>
      </Card>

      <Card className="fc-mb-24">
        <h3 className="fc-text-lg fc-mb-16">Email</h3>

        <div className="fc-admin-form-grid">
          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-from-name">
              From name
            </label>
            <input
              id="fc-from-name"
              value={value('email', 'from_name')}
              onChange={(e) => set('email', 'from_name', e.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-from-address">
              From address
            </label>
            <input
              id="fc-from-address"
              type="email"
              value={value('email', 'from_address')}
              onChange={(e) => set('email', 'from_address', e.target.value)}
            />
          </div>
        </div>

        <Button variant="primary" disabled={save.isPending} onClick={() => submit('email')}>
          Save email
        </Button>
      </Card>

      <Card>
        <h3 className="fc-text-lg fc-mb-16">Features</h3>
        <p className="fc-text-sm fc-text-muted fc-mb-16">
          Switching a feature off hides it across every app and refuses its endpoints.
        </p>

        <ul className="fc-switch-list">
          {Object.keys(data.features).map((key) => (
            <li key={key}>
              <label htmlFor={`fc-feat-${key}`}>{FEATURE_LABELS[key] ?? key}</label>
              <input
                id={`fc-feat-${key}`}
                type="checkbox"
                role="switch"
                checked={feature(key)}
                onChange={(e) => set('features', key, e.target.checked)}
              />
            </li>
          ))}
        </ul>

        <div className="fc-mt-16">
          <Button variant="primary" disabled={save.isPending} onClick={() => submit('features')}>
            Save features
          </Button>
        </div>
      </Card>
    </>
  );
}
