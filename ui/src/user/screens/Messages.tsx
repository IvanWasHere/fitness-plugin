import { Messages as SharedMessages } from '@shared/features/Messages';

/**
 * The member's side of messaging (W3.1).
 *
 * The screen itself is `@shared/features/Messages` as of W3.4 slice 3 — the
 * trainer app renders the same component against the same endpoints, which are
 * symmetric server-side. Only the copy differs, because who the other person is
 * is the one thing the two readers do not share.
 */
export function Messages() {
  return (
    <SharedMessages
      counterpart="Trainer"
      emptyTitle="No conversations yet"
      emptyBody="When a trainer is assigned to you, your conversation with them appears here."
    />
  );
}
