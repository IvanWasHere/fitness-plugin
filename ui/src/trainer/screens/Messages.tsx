import { Messages as SharedMessages } from '@shared/features/Messages';

/**
 * The trainer's side of messaging (plans/06-trainer-app.md §6, W3.4 slice 3).
 *
 * 06 describes this as the user app's screen "inverted: thread list is
 * *clients*". The inversion is entirely server-side — `MessageService` resolves
 * the caller to a member or a trainer and returns the counterpart accordingly —
 * so this is the same component with the copy that names the other person
 * changed. A trainer is never quota-limited and does not have to be told so
 * here: the wire sends them `quota: null` and the note renders nothing.
 *
 * **Canned responses and attaching a workout to a message are not built.** 06 §6
 * lists both; neither has an endpoint (`POST /messages/attachments` uploads an
 * image, not a workout reference), so both need contract work rather than a
 * screen.
 */
export function Messages() {
  return (
    <SharedMessages
      counterpart="Client"
      emptyTitle="No conversations yet"
      emptyBody="When you accept a client, your conversation with them appears here."
    />
  );
}
