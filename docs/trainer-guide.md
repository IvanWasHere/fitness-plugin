# Trainer guide

Coaching in FitnessClub. Written for trainers, not for developers.

---

## Signing in

Your account is created by the site administrator. **It is not a WordPress
account** — you sign in at the app's own address (usually `yoursite.com/fitness`),
and the app you get is decided by your role.

---

## Your profile decides whether clients can find you

**Set it up before wondering why nobody is requesting you.** Members browse a
directory, and the directory reads your profile:

| | |
|---|---|
| **Specialisations** | What members filter by |
| **Bio** | The only thing telling them what you are like to work with |
| **Max clients** | Your cap |
| **Open to requests** | Whether you appear as available |

⚠️ **Max clients = 0 means "not set", not "full".** You will still receive
requests. Set a real number if you want a limit.

Two fields you will not find, deliberately: **your rating** and your **account
status**. A rating you can set is not a rating.

The capacity line on your profile reads the **saved** profile, not the form — it
describes what the directory is doing right now, so an unticked box you have not
saved has not changed anything.

---

## Taking on clients

Members request you; you accept or decline from the **Requests** queue.

- **Capacity is checked when you accept**, not when they asked — it can fill in
  between. The queue states your capacity up front so you find out before
  clicking rather than after.
- **Declining asks for a reason.** The member sees it. A queue that just goes
  quiet reads as a platform fault.
- **Accepting opens your message thread** with them automatically. There is no
  "start a conversation" button because there does not need to be.

---

## Clients you share with another coach

A member may have several trainers. When they do, their row is tagged **"+1
coach"** in your list — before you open the record, because it changes the
programming conversation.

What that means in practice:

- ✅ You see **everything**, including the other coach's assignments, so you are
  never programming blind.
- ✅ Every assignment says **who made it** and when.
- ❌ You **cannot edit or remove** their work. Those controls are visible but
  disabled, and say why — hiding them would hide that the client already has that
  work.
- 🔒 **Private notes are genuinely private.** You cannot see theirs; they cannot
  see yours. Not even that one exists.

### The conflict warning

Assigning into a week another coach has already programmed shows a warning naming
who, what and when. **It does not block you** — refusing would let one coach's
programme silently constrain another's.

It exists because two coaches independently programming heavy compounds in the
same week is a genuine injury risk, and the only fix is that you both know.

Your *own* prior assignment in that week is not a conflict. Programming twice in a
week is programming.

---

## The client record

Seven tabs. They load when opened, not all at once.

| | |
|---|---|
| **Overview** | Goal, current stats, recent activity |
| **Workouts** | Assigned workouts and their progress, each attributed |
| **Nutrition** | Their diary and adherence |
| **Progress** | The same charts they see |
| **Health** | Weight and body fat |
| **Notes** | Yours, private |
| **Messages** | Your conversation, inline |

Trends show **direction only**, never a verdict — no green "good" arrow on falling
weight, because whether that is progress depends on what they are training for and
the app does not know.

Numbers are shown only when real. A member who has logged nothing sees empty
states, not zeroes dressed as data.

---

## Building workouts

Your library holds your own workouts plus the **platform library**, which every
trainer may assign but nobody may edit. A platform workout offers **Duplicate**
instead of Edit — the copy is yours to change.

**Reordering is drag-and-drop and saves with everything else.** Metadata and
exercises save in one action, because two saves can half-succeed and leave a
workout whose name says one thing and whose contents say another.

Editing an existing exercise **keeps its identity**, so every past session that
recorded it stays linked. Reordering is not deleting and re-adding.

Deleting a workout with logged sessions is refused — that history belongs to your
clients.

---

## Coaching plans

Your own priced plans, with weekly / monthly / quarterly / yearly tiers.

**Empty is not zero.** An empty price tier means *not offered*; `0` means *free*.
The form says so, because the difference is somebody's money.

**Feature flags and trainer slots are shown but read-only.** Those decide
platform-wide entitlements, and granting yourself video workouts would be selling
something the platform never agreed to. They are visible so you know what your
plan includes.

---

## Messaging

One thread per client, opened when you accept them.

Your replies **never spend their message quota** — that is drawn from their plan
and counts only their own sends. A chatty coach cannot exhaust their client's
allowance.

Attachments are images. Attaching a workout or a food plan by reference is not
built yet.

---

## Money

There is none in this app, deliberately. **Trainers are attributed, not paid** by
the platform: your contribution is tracked for reporting, but billing between you
and your clients is not something FitnessClub processes. A figure you cannot act
on and are not owed invites exactly the wrong conversation.

---

## Not built yet

Named rather than left to be discovered:

- **Food plan meal editor** — you can create and assign a food plan, but not
  build its per-meal contents.
- **Exercise library browser.**
- **Full health record** — the Health tab shows weight and body fat only. The
  fuller record needs access logging first.
- **Canned responses** and attaching a workout to a message.
- **Client-view workout preview.**
- **Support view** for trainers.

---

## Questions that come up

**A client vanished from my list.** The roster shows *active* relationships. If
they ended it, or their subscription lapsed, they drop off. Check the status
filter.

**I cannot remove a workout from a client.** Only the coach who assigned it can.
The control says so on hover.

**They say they never got my message.** Check whether they have muted that
notification category — muted notifications are never created, so there is nothing
waiting for them. The message itself is still in the thread.

**Nobody is requesting me.** Check your profile is open to requests, has
specialisations, and has a bio. And that you are not at your max.
