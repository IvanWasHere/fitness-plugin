import { useState } from 'react';
import { Button, Card, ErrorState, ProgressBar, Skeleton, Tag } from '@shared/components/ui';
import { useProfileActions, useTrainerProfile } from '../api/queries';
import type { TrainerProfile } from '../api/types';

/**
 * The trainer's own profile (plans/06-trainer-app.md §8, W3.4 slice 3).
 *
 * This is **not** a settings screen. Since Q4 it is the record a user reads in
 * the directory before asking to be coached, so it is an acquisition surface —
 * which is why the bio and specialization sit at the top rather than under the
 * operational switches.
 *
 * ## Capacity is stated, not implied
 *
 * 06 asks for a visible indicator — "18 of 20 clients" — so a trainer
 * understands *why* the directory has stopped sending them requests. Without it
 * the queue simply goes quiet and looks like a platform fault.
 *
 * `max_clients = 0` reads as **unconfigured**, never as "full": a fresh trainer
 * whose profile has never been touched must not be locked out of accepting their
 * first client. The server enforces the same reading at accept time.
 *
 * ## What cannot be edited here, and why
 *
 * `rating` / `rating_count` — a rating a trainer can set is not a rating.
 * `status` — account standing is an administrator's decision.
 * `hourly_rate` **is** editable but is display-only metadata (Q2: trainers are
 * traced, not paid); it must never reach a calculation, and nothing in the app
 * multiplies by it.
 */
export function Profile() {
  const { data, isLoading, error, refetch } = useTrainerProfile();
  const { save } = useProfileActions();

  const [fields, setFields] = useState<Record<string, unknown>>({});
  const [saved, setSaved] = useState(false);

  if (error) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Profile</h1>
        </header>
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return <Skeleton height={400} />;
  }

  const text = (key: keyof TrainerProfile): string =>
    key in fields ? String(fields[key] ?? '') : String(data[key] ?? '');

  const set = (key: string, next: unknown) => {
    setSaved(false);
    setFields((current) => ({ ...current, [key]: next }));
  };

  const accepting =
    'accepting_clients' in fields ? Boolean(fields.accepting_clients) : data.accepting_clients;

  const dirty = Object.keys(fields).length > 0;
  const nameMissing = text('display_name').trim() === '';

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>Profile</h1>
          <p className="fc-text-sm fc-text-muted">
            This is what a member sees before asking you to coach them.
          </p>
        </div>

        <Button
          variant="primary"
          disabled={nameMissing || !dirty || save.isPending}
          onClick={() =>
            save.mutate(
              { ...fields, display_name: text('display_name') },
              {
                onSuccess: () => {
                  setFields({});
                  setSaved(true);
                },
              },
            )
          }
        >
          {save.isPending ? 'Saving…' : 'Save profile'}
        </Button>
      </header>

      {/* Deliberately reads the *saved* profile, not the unsaved form state:
          this card describes what the directory is doing right now, and an
          unticked box that has not been saved has not changed that yet. */}
      <Capacity profile={data} />

      <Card>
        <div className="fc-admin-form-grid">
          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-pr-name">
              Display name <span aria-hidden="true">*</span>
            </label>
            <input
              id="fc-pr-name"
              value={text('display_name')}
              onChange={(event) => set('display_name', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-pr-spec">
              Specialization
            </label>
            <input
              id="fc-pr-spec"
              placeholder="Strength &amp; conditioning"
              value={text('specialization')}
              onChange={(event) => set('specialization', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-pr-phone">
              Phone
            </label>
            <input
              id="fc-pr-phone"
              type="tel"
              value={text('phone')}
              onChange={(event) => set('phone', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-pr-avatar">
              Avatar URL
            </label>
            <input
              id="fc-pr-avatar"
              type="url"
              value={text('avatar_url')}
              onChange={(event) => set('avatar_url', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-pr-rate">
              Hourly rate
            </label>
            <input
              id="fc-pr-rate"
              type="number"
              min={0}
              step="0.01"
              placeholder="Not shown"
              value={text('hourly_rate')}
              aria-describedby="fc-pr-rate-note"
              onChange={(event) =>
                set('hourly_rate', event.target.value === '' ? null : event.target.value)
              }
            />
            <span id="fc-pr-rate-note" className="fc-text-xs fc-text-muted">
              Shown on your public profile. The platform bills subscriptions — this is not used to
              calculate anything.
            </span>
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-pr-max">
              Client limit
            </label>
            <input
              id="fc-pr-max"
              type="number"
              min={0}
              value={text('max_clients')}
              aria-describedby="fc-pr-max-note"
              onChange={(event) => set('max_clients', event.target.value)}
            />
            <span id="fc-pr-max-note" className="fc-text-xs fc-text-muted">
              0 means no limit.
            </span>
          </div>
        </div>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-pr-bio">
            Bio
          </label>
          <textarea
            id="fc-pr-bio"
            rows={5}
            placeholder="How you coach, who you work best with, what you have done."
            value={text('bio')}
            onChange={(event) => set('bio', event.target.value)}
          />
        </div>

        <div className="fc-field">
          <label className="fc-label fc-flex fc-flex-c fc-gap-8" htmlFor="fc-pr-accepting">
            <input
              id="fc-pr-accepting"
              type="checkbox"
              checked={accepting}
              onChange={(event) => set('accepting_clients', event.target.checked)}
            />
            Accepting new clients
          </label>
          <span className="fc-text-xs fc-text-muted">
            Turn this off and you stay in the directory for your current clients but stop receiving
            new requests. Requests already waiting are unaffected.
          </span>
        </div>

        {save.error && <p className="fc-text-sm fc-text-danger fc-mt-8">{save.error.message}</p>}

        {saved && !dirty && (
          <p className="fc-text-sm fc-text-muted fc-mt-8" role="status">
            Saved.
          </p>
        )}
      </Card>
    </>
  );
}

/**
 * The capacity indicator, plus the two read-only facts.
 *
 * The rating is shown with its count, because "5.0" from one client and "4.6"
 * from ninety are not the same claim and a bare average implies the second.
 */
function Capacity({ profile }: { profile: TrainerProfile }) {
  // 0 is unconfigured, not zero capacity.
  const limit = profile.max_clients > 0 ? profile.max_clients : null;
  const full = limit !== null && profile.client_count >= limit;
  const accepting = profile.accepting_clients;

  return (
    <Card className="fc-mb-16">
      <div className="fc-flex fc-flex-c fc-gap-12 fc-mb-12">
        <strong>
          {limit === null
            ? `${profile.client_count} clients · no limit set`
            : `${profile.client_count} of ${limit} clients`}
        </strong>

        {!accepting && <Tag tone="orange">not accepting requests</Tag>}
        {accepting && full && <Tag tone="red">full</Tag>}
        {accepting && !full && <Tag tone="green">open to requests</Tag>}
      </div>

      {limit !== null && (
        <ProgressBar value={Math.min(100, (profile.client_count / limit) * 100)} />
      )}

      <p className="fc-text-xs fc-text-muted fc-mt-8">
        {!accepting
          ? 'The directory is not sending you new requests because you have turned them off.'
          : full
            ? 'The directory has stopped sending you requests because you are at your limit. Raise it to receive more.'
            : 'Members can find you in the directory and ask to be coached.'}
      </p>

      <div className="fc-flex fc-gap-8 fc-mt-12 fc-flex-wrap">
        <Tag tone="blue">
          {profile.rating === null
            ? 'No rating yet'
            : `${profile.rating} from ${profile.rating_count} client${profile.rating_count === 1 ? '' : 's'}`}
        </Tag>
        <Tag tone={profile.status === 'active' ? 'green' : 'orange'}>account {profile.status}</Tag>
      </div>
    </Card>
  );
}
