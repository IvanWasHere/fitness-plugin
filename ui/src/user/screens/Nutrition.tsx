import { useState } from 'react';
import { Icon } from '@shared/components/Icon';
import { MacroBar, MacroRing } from '@shared/components/MacroRing';
import { Modal } from '@shared/components/Modal';
import { WaterDots } from '@shared/components/WaterDots';
import { Button, Card, EmptyState, ErrorState, PageHeader, Skeleton } from '@shared/components/ui';
import { useNutritionActions, useNutritionDay } from '../api/queries';
import type { Meal, MealItemInput, MealType } from '../api/types';
import { AddMealModal } from '../features/nutrition/AddMealModal';

/**
 * The Nutrition screen (W2.1).
 *
 * One request for the whole day (`GET /nutrition/day`) — meals, totals, goals
 * and water together, for the same reason the dashboard is one call: four
 * round-trips before the first paint is what the prototype did.
 *
 * **Nothing here invents a goal.** A member who has not set a calorie target
 * gets the total and no ring, not a ring drawn against a plausible 2 000. The
 * empty state offers to set one, which is the honest version of the same nudge.
 */
const MEAL_LABELS: Record<MealType, string> = {
  breakfast: 'Breakfast',
  lunch: 'Lunch',
  dinner: 'Dinner',
  snack: 'Snack',
  other: 'Other',
};

export function Nutrition() {
  const { data, isLoading, error, refetch } = useNutritionDay();
  const { logMeal, deleteMeal, setWater, setGoals } = useNutritionActions();

  const [adding, setAdding] = useState(false);
  const [editingGoals, setEditingGoals] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<Meal | null>(null);

  if (error) {
    return (
      <>
        <PageHeader title="Nutrition" />
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return (
      <>
        <PageHeader title="Nutrition" />
        <Skeleton height={220} />
        <div className="fc-mt-16">
          <Skeleton height={140} />
        </div>
      </>
    );
  }

  const { totals, goals, water, meals } = data;

  const submitMeal = (mealType: MealType, items: MealItemInput[], notes: string) => {
    logMeal.mutate(
      { meal_type: mealType, items, notes: notes || null },
      { onSuccess: () => setAdding(false) },
    );
  };

  return (
    <>
      <PageHeader
        title="Nutrition"
        subtitle={friendlyDate(data.date)}
        actions={
          <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>
            Add meal
          </Button>
        }
      />

      <div className="fc-grid fc-grid-2 fc-gap-16 fc-mb-24">
        <Card>
          <div className="fc-flex fc-flex-c fc-gap-24 fc-flex-wrap">
            <MacroRing value={totals.calories} goal={goals.calories} />

            <div style={{ flex: 1, minWidth: '12rem' }}>
              <MacroBar label="Protein" value={totals.protein_g} goal={goals.protein_g} />
              <MacroBar
                label="Carbs"
                value={totals.carbs_g}
                goal={goals.carbs_g}
                tone="var(--info)"
              />
              <MacroBar label="Fat" value={totals.fat_g} goal={goals.fat_g} tone="var(--accent2)" />

              <button
                type="button"
                className="fc-link fc-text-xs fc-mt-8"
                onClick={() => setEditingGoals(true)}
              >
                {goals.calories === null ? 'Set your goals' : 'Edit goals'}
              </button>
            </div>
          </div>
        </Card>

        <Card>
          <div className="fc-flex fc-flex-between fc-flex-c fc-mb-16">
            <h3 className="fc-text-lg">Water</h3>
            <span className="fc-text-sm fc-text-muted">
              {water.consumed_ml} / {water.goal_ml} ml
            </span>
          </div>

          <WaterDots
            glasses={water.glasses}
            glassesGoal={water.glasses_goal}
            glassMl={water.glass_ml}
            busy={setWater.isPending}
            onDelta={(delta_ml) => setWater.mutate({ delta_ml })}
            onSetTotal={(total_ml) => setWater.mutate({ total_ml })}
          />
        </Card>
      </div>

      <h2 className="fc-text-lg fc-mb-16">Today&rsquo;s meals</h2>

      {meals.length === 0 && (
        <EmptyState
          title="Nothing logged yet"
          action={
            <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>
              Add your first meal
            </Button>
          }
        >
          Meals you log appear here, and count towards the ring above.
        </EmptyState>
      )}

      {meals.length > 0 && (
        <div className="fc-flex fc-flex-column fc-gap-8">
          {meals.map((meal) => (
            <MealRow key={meal.id} meal={meal} onDelete={() => setConfirmDelete(meal)} />
          ))}
        </div>
      )}

      {adding && (
        <AddMealModal
          onClose={() => setAdding(false)}
          onSubmit={submitMeal}
          saving={logMeal.isPending}
          error={logMeal.error?.message ?? null}
        />
      )}

      {editingGoals && (
        <GoalsModal
          goals={goals}
          saving={setGoals.isPending}
          onClose={() => setEditingGoals(false)}
          onSave={(next) => setGoals.mutate(next, { onSuccess: () => setEditingGoals(false) })}
        />
      )}

      {confirmDelete && (
        <Modal
          title="Delete this meal?"
          confirmLabel="Delete"
          tone="danger"
          onCancel={() => setConfirmDelete(null)}
          onConfirm={() =>
            deleteMeal.mutate(confirmDelete.id, { onSuccess: () => setConfirmDelete(null) })
          }
        >
          {MEAL_LABELS[confirmDelete.meal_type]} · {confirmDelete.calories} kcal. This cannot be
          undone.
        </Modal>
      )}
    </>
  );
}

function MealRow({ meal, onDelete }: { meal: Meal; onDelete: () => void }) {
  const [open, setOpen] = useState(false);

  return (
    <Card>
      <div className="fc-flex fc-flex-between fc-flex-c fc-gap-12">
        <button
          type="button"
          className="fc-meal-toggle"
          onClick={() => setOpen((value) => !value)}
          aria-expanded={open}
        >
          <span className="fc-font-bold">{MEAL_LABELS[meal.meal_type]}</span>
          <span className="fc-text-xs fc-text-muted">
            {meal.items.length} item{meal.items.length === 1 ? '' : 's'}
            {/* Q10: an entry made by a trainer or admin says so, rather than
                leaving the member wondering where it came from. */}
            {meal.source === 'admin' ? ' · added for you' : ''}
          </span>
        </button>

        <div className="fc-flex fc-flex-c fc-gap-12">
          <div className="fc-text-right">
            <div className="fc-font-bold">{meal.calories} kcal</div>
            <div className="fc-text-xs fc-text-muted">
              {Math.round(meal.protein_g)}p / {Math.round(meal.carbs_g)}c / {Math.round(meal.fat_g)}
              f
            </div>
          </div>

          <button
            type="button"
            className="fc-btn fc-btn--icon"
            onClick={onDelete}
            aria-label={`Delete ${MEAL_LABELS[meal.meal_type]}`}
          >
            <Icon name="x" size={14} />
          </button>
        </div>
      </div>

      {open && (
        <ul className="fc-meal-items">
          {meal.items.map((item) => (
            <li key={item.id}>
              <span className="fc-truncate">
                {item.name}
                {item.quantity !== 1 ? ` × ${item.quantity}` : ''}
              </span>
              <span className="fc-text-muted">{item.calories} kcal</span>
            </li>
          ))}
          {meal.notes && <li className="fc-text-muted fc-text-xs">{meal.notes}</li>}
        </ul>
      )}
    </Card>
  );
}

/**
 * The goal editor the prototype never had — it displayed goals with no way to
 * change them, which made every "of 2 400" a number nobody chose.
 */
function GoalsModal({
  goals,
  saving,
  onClose,
  onSave,
}: {
  goals: {
    calories: number | null;
    protein_g: number | null;
    carbs_g: number | null;
    fat_g: number | null;
    water_ml: number | null;
  };
  saving: boolean;
  onClose: () => void;
  onSave: (goals: Record<string, number>) => void;
}) {
  const [values, setValues] = useState({
    calories: goals.calories ?? 2000,
    protein_g: goals.protein_g ?? 150,
    carbs_g: goals.carbs_g ?? 200,
    fat_g: goals.fat_g ?? 65,
    water_ml: goals.water_ml ?? 2000,
  });

  const field = (key: keyof typeof values, label: string, step = 1) => (
    <div className="fc-field">
      <label className="fc-label" htmlFor={`fc-goal-${key}`}>
        {label}
      </label>
      <input
        id={`fc-goal-${key}`}
        type="number"
        min={0}
        step={step}
        value={values[key]}
        onChange={(event) =>
          setValues((current) => ({
            ...current,
            [key]: Math.max(0, Number(event.target.value) || 0),
          }))
        }
      />
    </div>
  );

  return (
    <Modal
      title="Daily goals"
      variant="form"
      confirmLabel={saving ? 'Saving…' : 'Save goals'}
      confirmDisabled={saving}
      onConfirm={() => onSave(values)}
      onCancel={onClose}
    >
      {field('calories', 'Calories (kcal)', 10)}
      {field('protein_g', 'Protein (g)')}
      {field('carbs_g', 'Carbs (g)')}
      {field('fat_g', 'Fat (g)')}
      {field('water_ml', 'Water (ml)', 50)}
    </Modal>
  );
}

/** "Today", "Yesterday", or the date. */
function friendlyDate(date: string): string {
  const today = new Date().toISOString().slice(0, 10);
  const yesterday = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10);

  if (date === today) {
    return 'Today';
  }
  if (date === yesterday) {
    return 'Yesterday';
  }

  return date;
}
