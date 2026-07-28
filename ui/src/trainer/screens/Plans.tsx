import { useState } from 'react';
import { Modal } from '@shared/components/Modal';
import { Button, Card, EmptyState, ErrorState, Skeleton, Tag } from '@shared/components/ui';
import { usePlanActions, useTrainerPlans } from '../api/queries';
import type { TrainerPlan } from '../api/types';

const CYCLES = [
  { key: 'price_weekly', label: 'Weekly' },
  { key: 'price_monthly', label: 'Monthly' },
  { key: 'price_quarterly', label: 'Quarterly' },
  { key: 'price_yearly', label: 'Yearly' },
] as const;

const PLAN_TYPES = ['combined', 'workout', 'nutrition'];
const DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];

/**
 * The plan builder (plans/06-trainer-app.md §3, W3.4 slice 3).
 *
 * ## The pricing matrix treats empty and zero as different things
 *
 * A **null** price means that billing period is not offered; **0** means it is
 * offered free. `01-database.md` draws that distinction in the schema and the
 * form has to preserve it, so an empty box clears the tier rather than writing
 * zero — otherwise every plan a trainer edits quietly starts offering a free
 * yearly subscription.
 *
 * ## The feature-flag editor 06 asks for is not here, deliberately
 *
 * 06 §3 wants `features` rendered as toggles, and W3.4 slice 1 decided the
 * opposite for a reason that outranks it: `features` and `max_trainers` grant
 * platform-wide entitlements, so a trainer switching on `has_video_workouts`
 * would be selling something the platform never agreed to. `planFields()`
 * refuses both. They are shown here read-only instead — a trainer still needs to
 * know what they are selling, and a screen that showed nothing would leave them
 * guessing.
 *
 * ## Attached workouts and food plans are not here either
 *
 * 06 §3 lists them, but there is no join table behind them — nothing in the 29
 * migrations links a plan to a workout — so they would need schema before they
 * could need a screen. Recorded rather than quietly dropped.
 */
export function Plans() {
  const { data, isLoading, error, refetch } = useTrainerPlans();
  const { create, remove } = usePlanActions();

  const [editingId, setEditingId] = useState<number | null>(null);
  const [naming, setNaming] = useState(false);
  const [name, setName] = useState('');
  const [deleting, setDeleting] = useState<TrainerPlan | null>(null);

  if (error) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Plans</h1>
        </header>
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  const items = data?.items ?? [];
  const editing = items.find((plan) => plan.id === editingId) ?? null;

  if (editing) {
    return <PlanEditor plan={editing} onDone={() => setEditingId(null)} />;
  }

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>Plans</h1>
          <p className="fc-text-sm fc-text-muted">
            {isLoading ? 'Loading…' : `${items.length} plan${items.length === 1 ? '' : 's'}`}
          </p>
        </div>

        <Button
          variant="primary"
          icon="plus"
          onClick={() => {
            setName('');
            setNaming(true);
          }}
        >
          New plan
        </Button>
      </header>

      {isLoading && <Skeleton height={200} />}

      {!isLoading && items.length === 0 && (
        <EmptyState title="No plans yet">
          A plan is what a client subscribes to. Create one, price it, and it becomes available to
          them.
        </EmptyState>
      )}

      <div className="fc-flex fc-flex-column fc-gap-8">
        {items.map((plan) => (
          <Card key={plan.id}>
            <div className="fc-client-row">
              <span className="fc-client-row__body">
                <span className="fc-font-bold fc-truncate">
                  {plan.plan_name}
                  {!plan.is_active && <Tag>off sale</Tag>}
                  {plan.active_subscribers > 0 && (
                    <Tag tone="green">
                      {plan.active_subscribers} subscriber
                      {plan.active_subscribers === 1 ? '' : 's'}
                    </Tag>
                  )}
                </span>
                <span className="fc-text-xs fc-text-muted fc-truncate">
                  {plan.plan_type} · {plan.difficulty} · {priceSummary(plan)}
                </span>
              </span>

              <Button onClick={() => setEditingId(plan.id)}>Edit</Button>
              <Button
                variant="danger"
                aria-label={`Delete ${plan.plan_name}`}
                onClick={() => setDeleting(plan)}
              >
                Delete
              </Button>
            </div>
          </Card>
        ))}
      </div>

      {naming && (
        <Modal
          title="New plan"
          variant="form"
          confirmLabel={create.isPending ? 'Creating…' : 'Create'}
          confirmDisabled={create.isPending || name.trim() === ''}
          onCancel={() => setNaming(false)}
          onConfirm={() =>
            create.mutate(
              { plan_name: name.trim() },
              {
                // Straight into the editor: a plan with a name and no price is
                // not a plan yet, and making the trainer find it in the list
                // first is a step with nothing in it.
                onSuccess: (created) => {
                  setNaming(false);
                  setEditingId(created.id);
                },
              },
            )
          }
        >
          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-plan-name">
              Name <span aria-hidden="true">*</span>
            </label>
            <input
              id="fc-plan-name"
              value={name}
              onChange={(event) => setName(event.target.value)}
            />
          </div>

          {create.error && <p className="fc-text-sm fc-text-danger">{create.error.message}</p>}
        </Modal>
      )}

      {deleting && (
        <Modal
          title={`Delete ${deleting.plan_name}?`}
          confirmLabel={remove.isPending ? 'Deleting…' : 'Delete'}
          tone="danger"
          confirmDisabled={remove.isPending}
          onCancel={() => setDeleting(null)}
          onConfirm={() => remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })}
        >
          {remove.error ? (
            <span className="fc-text-danger">{remove.error.message}</span>
          ) : (
            'A plan somebody is subscribed to cannot be deleted — take it off sale instead, and their subscription keeps working.'
          )}
        </Modal>
      )}
    </>
  );
}

function priceSummary(plan: TrainerPlan): string {
  const priced = CYCLES.filter((cycle) => plan[cycle.key] !== null);

  if (priced.length === 0) {
    return 'no price set';
  }

  return priced
    .map((cycle) => `${plan.currency} ${plan[cycle.key]?.toFixed(2)} ${cycle.label.toLowerCase()}`)
    .join(' · ');
}

function PlanEditor({ plan, onDone }: { plan: TrainerPlan; onDone: () => void }) {
  const { update } = usePlanActions();
  const [fields, setFields] = useState<Record<string, unknown>>({});
  const [saved, setSaved] = useState(false);

  const set = (key: string, next: unknown) => {
    setSaved(false);
    setFields((current) => ({ ...current, [key]: next }));
  };

  const text = (key: keyof TrainerPlan): string =>
    key in fields ? String(fields[key] ?? '') : String(plan[key] ?? '');

  const dirty = Object.keys(fields).length > 0;
  const nameMissing = text('plan_name').trim() === '';

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>{plan.plan_name}</h1>
          <p className="fc-text-sm fc-text-muted">
            {plan.active_subscribers > 0
              ? `${plan.active_subscribers} client${plan.active_subscribers === 1 ? '' : 's'} subscribed — changes apply to them too`
              : 'No subscribers yet'}
          </p>
        </div>

        <Button
          variant="primary"
          disabled={nameMissing || !dirty || update.isPending}
          onClick={() =>
            update.mutate(
              { id: plan.id, body: { ...fields, plan_name: text('plan_name') } },
              {
                onSuccess: () => {
                  setFields({});
                  setSaved(true);
                },
              },
            )
          }
        >
          {update.isPending ? 'Saving…' : 'Save plan'}
        </Button>
      </header>

      <Card>
        <div className="fc-admin-form-grid">
          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-p-name">
              Name <span aria-hidden="true">*</span>
            </label>
            <input
              id="fc-p-name"
              value={text('plan_name')}
              onChange={(event) => set('plan_name', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-p-type">
              Covers
            </label>
            <select
              id="fc-p-type"
              value={text('plan_type')}
              onChange={(event) => set('plan_type', event.target.value)}
            >
              {PLAN_TYPES.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-p-diff">
              Difficulty
            </label>
            <select
              id="fc-p-diff"
              value={text('difficulty')}
              onChange={(event) => set('difficulty', event.target.value)}
            >
              {DIFFICULTIES.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-p-sessions">
              Sessions per week
            </label>
            <input
              id="fc-p-sessions"
              type="number"
              min={0}
              placeholder="Not specified"
              value={text('weekly_sessions')}
              // Nullable, like the price tiers: an empty box is "not specified",
              // not "no sessions".
              onChange={(event) =>
                set('weekly_sessions', event.target.value === '' ? null : event.target.value)
              }
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-p-weeks">
              Length (weeks)
            </label>
            <input
              id="fc-p-weeks"
              type="number"
              min={0}
              placeholder="Not specified"
              value={text('duration_weeks')}
              onChange={(event) =>
                set('duration_weeks', event.target.value === '' ? null : event.target.value)
              }
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-p-messages">
              Messages per week
            </label>
            <input
              id="fc-p-messages"
              type="number"
              min={0}
              value={text('max_messages_per_week')}
              onChange={(event) => set('max_messages_per_week', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-p-currency">
              Currency
            </label>
            <input
              id="fc-p-currency"
              maxLength={3}
              value={text('currency')}
              onChange={(event) => set('currency', event.target.value.toUpperCase())}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-p-active">
              On sale
            </label>
            <input
              id="fc-p-active"
              type="checkbox"
              checked={'is_active' in fields ? Boolean(fields.is_active) : plan.is_active}
              onChange={(event) => set('is_active', event.target.checked)}
            />
          </div>
        </div>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-p-desc">
            Description
          </label>
          <textarea
            id="fc-p-desc"
            rows={3}
            value={text('description')}
            onChange={(event) => set('description', event.target.value)}
          />
        </div>

        <h4 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-12">Pricing</h4>

        <div className="fc-admin-form-grid">
          {CYCLES.map((cycle) => (
            <div className="fc-field" key={cycle.key}>
              <label className="fc-label" htmlFor={`fc-p-${cycle.key}`}>
                {cycle.label}
              </label>
              <input
                id={`fc-p-${cycle.key}`}
                type="number"
                min={0}
                step="0.01"
                placeholder="Not offered"
                value={
                  cycle.key in fields ? String(fields[cycle.key] ?? '') : (plan[cycle.key] ?? '')
                }
                // Empty clears the tier rather than writing 0: "not offered" and
                // "free" are different offers, and the column is nullable to say so.
                onChange={(event) =>
                  set(cycle.key, event.target.value === '' ? null : event.target.value)
                }
              />
            </div>
          ))}
        </div>

        <p className="fc-text-xs fc-text-muted">
          Leave a period empty and it is not offered. 0 means it is offered free.
        </p>

        <h4 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-12 fc-mt-16">What it grants</h4>

        <p className="fc-text-xs fc-text-muted fc-mb-12">
          Entitlements are set by the platform, not by you — they decide what a subscriber can reach
          across the whole app. Ask an administrator to change them.
        </p>

        <div className="fc-flex fc-gap-8 fc-flex-wrap">
          <Tag tone="blue">
            up to {plan.max_trainers} trainer{plan.max_trainers === 1 ? '' : 's'}
          </Tag>

          {Object.entries(plan.features).length === 0 ? (
            <span className="fc-text-xs fc-text-muted">No features set on this plan.</span>
          ) : (
            Object.entries(plan.features).map(([key, granted]) => (
              <Tag key={key} tone={granted ? 'green' : 'red'}>
                {key.replace(/_/g, ' ')}
                {granted === true || granted === false ? '' : `: ${String(granted)}`}
              </Tag>
            ))
          )}
        </div>

        {update.error && (
          <p className="fc-text-sm fc-text-danger fc-mt-8">{update.error.message}</p>
        )}

        {saved && !dirty && (
          <p className="fc-text-sm fc-text-muted fc-mt-8" role="status">
            Saved.
          </p>
        )}

        <div className="fc-mt-16">
          <Button onClick={onDone}>Back to plans</Button>
        </div>
      </Card>
    </>
  );
}
