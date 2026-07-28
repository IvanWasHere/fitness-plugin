import { useState } from 'react';
import { Modal } from '@shared/components/Modal';
import { Button, Card, EmptyState, ErrorState, Skeleton, Tag } from '@shared/components/ui';
import { useFoodPlanActions, useFoodPlans } from '../api/queries';
import type { TrainerFoodPlan } from '../api/types';

/**
 * Food plans (plans/06-trainer-app.md §5, W3.4 slice 3).
 *
 * **The meal editor is not here, and that is the planned scope, not an
 * omission.** 06 §5 describes plan → meals → items with live daily totals and a
 * duplicate-day helper; 06's own estimate marks the food-plan builder deferrable
 * to Phase 4 (−3 d) and W3.4 slice 1 deferred it explicitly. The schema for it
 * exists (`fc_food_plan_meals`) and the endpoints do not, so building the meal
 * editor means new routes and a service, not a screen.
 *
 * What this delivers is the whole of what the endpoints do support: the plan
 * record itself, which is what `POST /trainer/clients/{id}/food-plans` assigns.
 * A trainer can create, describe, target, deactivate and delete a plan and hand
 * it to a client today; what they cannot yet do is write the meals into it.
 */
export function FoodPlans() {
  const { data, isLoading, error, refetch } = useFoodPlans();
  const { create, update, remove } = useFoodPlanActions();

  const [naming, setNaming] = useState(false);
  const [name, setName] = useState('');
  const [editing, setEditing] = useState<TrainerFoodPlan | null>(null);
  const [deleting, setDeleting] = useState<TrainerFoodPlan | null>(null);

  if (error) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Food plans</h1>
        </header>
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  const items = data?.items ?? [];

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>Food plans</h1>
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
          New food plan
        </Button>
      </header>

      <Card className="fc-mb-16">
        <p className="fc-text-sm fc-text-muted">
          You can create a plan and assign it to a client from their record. Writing the individual
          meals into a plan is not built yet.
        </p>
      </Card>

      {isLoading && <Skeleton height={200} />}

      {!isLoading && items.length === 0 && (
        <EmptyState title="No food plans yet">
          A food plan carries a daily calorie target you can hand to a client.
        </EmptyState>
      )}

      <div className="fc-flex fc-flex-column fc-gap-8">
        {items.map((plan) => (
          <Card key={plan.id}>
            <div className="fc-client-row">
              <span className="fc-client-row__body">
                <span className="fc-font-bold fc-truncate">
                  {plan.plan_name}
                  {!plan.is_active && <Tag>inactive</Tag>}
                </span>
                <span className="fc-text-xs fc-text-muted fc-truncate">
                  {plan.daily_calories ? `${plan.daily_calories} kcal/day` : 'no target set'}
                  {plan.meal_count ? ` · ${plan.meal_count} meals/day` : ''}
                </span>
              </span>

              <Button onClick={() => setEditing(plan)}>Edit</Button>
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
          title="New food plan"
          variant="form"
          confirmLabel={create.isPending ? 'Creating…' : 'Create'}
          confirmDisabled={create.isPending || name.trim() === ''}
          onCancel={() => setNaming(false)}
          onConfirm={() =>
            create.mutate({ plan_name: name.trim() }, { onSuccess: () => setNaming(false) })
          }
        >
          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-fp-name">
              Name <span aria-hidden="true">*</span>
            </label>
            <input id="fc-fp-name" value={name} onChange={(event) => setName(event.target.value)} />
          </div>

          {create.error && <p className="fc-text-sm fc-text-danger">{create.error.message}</p>}
        </Modal>
      )}

      {editing && (
        <FoodPlanDialog
          plan={editing}
          saving={update.isPending}
          error={update.error?.message ?? null}
          onCancel={() => setEditing(null)}
          onSave={(body) =>
            update.mutate({ id: editing.id, body }, { onSuccess: () => setEditing(null) })
          }
        />
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
            'A plan a client is following cannot be deleted — deactivate it instead.'
          )}
        </Modal>
      )}
    </>
  );
}

function FoodPlanDialog({
  plan,
  saving,
  error,
  onCancel,
  onSave,
}: {
  plan: TrainerFoodPlan;
  saving: boolean;
  error: string | null;
  onCancel: () => void;
  onSave: (body: Record<string, unknown>) => void;
}) {
  const [fields, setFields] = useState<Record<string, unknown>>({});

  const text = (key: keyof TrainerFoodPlan): string =>
    key in fields ? String(fields[key] ?? '') : String(plan[key] ?? '');

  const set = (key: string, next: unknown) => setFields((current) => ({ ...current, [key]: next }));

  return (
    <Modal
      title={`Edit ${plan.plan_name}`}
      variant="form"
      confirmLabel={saving ? 'Saving…' : 'Save'}
      confirmDisabled={saving || text('plan_name').trim() === ''}
      onCancel={onCancel}
      onConfirm={() => onSave({ ...fields, plan_name: text('plan_name') })}
    >
      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-fpe-name">
          Name <span aria-hidden="true">*</span>
        </label>
        <input
          id="fc-fpe-name"
          value={text('plan_name')}
          onChange={(event) => set('plan_name', event.target.value)}
        />
      </div>

      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-fpe-desc">
          Description
        </label>
        <textarea
          id="fc-fpe-desc"
          rows={3}
          value={text('description')}
          onChange={(event) => set('description', event.target.value)}
        />
      </div>

      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-fpe-cals">
          Daily calories
        </label>
        <input
          id="fc-fpe-cals"
          type="number"
          min={0}
          placeholder="No target"
          value={text('daily_calories')}
          // Nullable: no target is a real state, and 0 kcal is not what an empty
          // box means.
          onChange={(event) =>
            set('daily_calories', event.target.value === '' ? null : event.target.value)
          }
        />
      </div>

      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-fpe-meals">
          Meals per day
        </label>
        <input
          id="fc-fpe-meals"
          type="number"
          min={0}
          placeholder="Not specified"
          value={text('meal_count')}
          onChange={(event) =>
            set('meal_count', event.target.value === '' ? null : event.target.value)
          }
        />
      </div>

      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-fpe-active">
          Active
        </label>
        <input
          id="fc-fpe-active"
          type="checkbox"
          checked={'is_active' in fields ? Boolean(fields.is_active) : plan.is_active}
          onChange={(event) => set('is_active', event.target.checked)}
        />
      </div>

      {error && <p className="fc-text-sm fc-text-danger">{error}</p>}
    </Modal>
  );
}
