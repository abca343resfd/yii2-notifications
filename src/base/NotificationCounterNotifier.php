<?php

namespace webzop\notifications\base;

/**
 * Receives the notification "the unseen-notifications counter of this user changed, and this is its
 * new value".
 *
 * ## Why the extension does not push anything itself
 *
 * The badge shown by `widgets\Notifications` is polled: the browser asks for the number every
 * `pollInterval` milliseconds, so nothing in here ever had to know *when* the number changed.
 * Pushing it instead requires two very different pieces of knowledge:
 *
 * - **when it changed, and what it now is** — that is notification knowledge, it lives in this
 *   extension, and it is what {@see \webzop\notifications\Module::notifyCounter()} works out;
 * - **how to reach the browser** — that is infrastructure the host application owns (a WebSocket
 *   daemon, a Redis Pub/Sub channel, a mercure hub, an SSE endpoint…). It is deliberately absent from
 *   this extension, which requires neither Redis nor any transport package.
 *
 * This interface is the seam between the two. An application that wants the push implements it and
 * declares the implementation in `Module::$counter_notifier`; an application that does not leaves the
 * parameter unset and the widget keeps polling exactly as before — no new dependency, no new
 * configuration, no behaviour change.
 *
 * ## What an implementation may assume
 *
 * - `$count` is an **absolute** value, never a delta. A consumer that missed a notification is
 *   corrected by the next one instead of drifting, and one that receives the same notification twice
 *   is idempotent. Keep that property when designing the payload.
 * - `$user_id` is never `0`. The base `Notification` class allows `userId = 0` as a "broadcast to
 *   every user" convention (`getCountUnseen()`/`countUnseenFor()` OR it into the query), but there is
 *   no single per-user topic a broadcast can publish to without enumerating every eligible user, so
 *   the caller never invokes this method for a broadcast row — polling remains the only delivery path
 *   for that case. An implementation must not assume it has to handle `0` itself.
 * - It is called after the row has been written, from whatever context wrote it: a web request, a
 *   console command or a queue worker. It must therefore not depend on there being a current user, a
 *   session or a response.
 * - It may be called while a database transaction is still open, since a notification can be written
 *   inside one. A rollback would leave the notified value stale until the next change; that is
 *   acceptable precisely because the value is absolute, and it is the reason this interface must not
 *   be used for anything that has to be transactionally consistent.
 * - Exceptions are caught and logged by the caller. A notification-about-a-notification is not part
 *   of the work that triggered it, so a broken transport must never fail whatever wrote the row.
 */
interface NotificationCounterNotifier
{
    /**
     * @param integer $user_id The user whose badge has to change. Never the user who happens to be
     *                         logged in: a console command or a queue worker sends on behalf of
     *                         somebody who is not present at all.
     * @param integer $count The number that user's badge must now show, i.e. their unseen
     *                       notifications across every channel.
     * @return void
     */
    public function notify($user_id, $count);
}
