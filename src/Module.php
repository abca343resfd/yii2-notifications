<?php

namespace webzop\notifications;

use webzop\notifications\base\NotificationCounterNotifier;
use webzop\notifications\model\Notifications;
use Throwable;
use Yii;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\helpers\VarDumper;
use yii\mutex\Mutex;

class Module extends \yii\base\Module
{
    public $channels = [];

    protected $_channels = [];

    public $controllerNamespace = 'webzop\notifications\controllers';

    public $attachmentsPath = '@app/documents/notifications';

    public $identityClass;

    public $mutex;

    /**
     * When set to `false`, if the user is blocked, prevents the sending of the notifications
     *
     * @var boolean
     */
    public $sendToBlockedUsers = false;

    /**
     * @var NotificationCounterNotifier|array|string|null Implementation of
     * {@see NotificationCounterNotifier} to be told whenever a user's unseen-notifications counter
     * changes, so the badge rendered by {@see \webzop\notifications\widgets\Notifications} can be
     * pushed instead of polled. Any value {@see \yii\di\Container::get()} accepts: a class name, a DI
     * configuration array, or a ready instance.
     *
     * Left `null` — the default — nothing is observed and nothing is published: the widget polls
     * exactly as it always did, with no new dependency. Resolving and wiring it up is
     * {@see \webzop\notifications\Bootstrap}'s job, since that class (declared via this package's
     * composer.json `extra.bootstrap`) is what Yii runs unconditionally on every request, web or
     * console, before this module would otherwise be instantiated at all.
     */
    public $counter_notifier = null;

    /**
     * @var NotificationCounterNotifier|null Resolved once by {@see resolveCounterNotifier()}, so a
     * misconfigured `counter_notifier` fails loudly at startup rather than being silently swallowed
     * inside {@see notifyCounter()}'s try/catch on every notification.
     */
    private $_counterNotifier;

    /**
     * @var bool Whether {@see resolveCounterNotifier()} already ran, distinct from
     * `$_counterNotifier !== null` because `$counter_notifier` being unset is itself a valid,
     * memoizable outcome (returns `null` every time without re-checking).
     */
    private $_counterNotifierResolved = false;

    /**
     * Resolves and validates {@see $counter_notifier}, memoized after the first call.
     *
     * @return NotificationCounterNotifier|null `null` when `$counter_notifier` is unset.
     * @throws InvalidConfigException if `$counter_notifier` is set but does not implement
     * {@see NotificationCounterNotifier}.
     */
    public function resolveCounterNotifier()
    {
        if ($this->_counterNotifierResolved) {
            return $this->_counterNotifier;
        }
        $this->_counterNotifierResolved = true;
        if ($this->counter_notifier === null) {
            return null;
        }
        // Resolved eagerly on first use rather than lazily inside the try/catch below: a wrong class
        // name has to fail loudly, not be caught and logged once per notification.
        $notifier = Yii::createObject($this->counter_notifier);
        if (!($notifier instanceof NotificationCounterNotifier)) {
            throw new InvalidConfigException(Yii::t(
                'app',
                'The `counter_notifier` module parameter must implement {interface}.',
                ['interface' => NotificationCounterNotifier::class]
            ));
        }
        return $this->_counterNotifier = $notifier;
    }

    /**
     * @var bool True while {@see send()} is inserting one channel row per call for the same logical
     * notification; while set, {@see notifyCounter()} queues the user id instead of recomputing and
     * publishing immediately, so a multi-channel send collapses to a single publish. See
     * {@see withCounterNotifyBatched()}.
     */
    private $_batchingCounterNotify = false;

    /**
     * @var array<int, true> User ids queued by {@see notifyCounter()} while
     * {@see $_batchingCounterNotify} is set, flushed once by {@see withCounterNotifyBatched()}.
     */
    private $_pendingCounterNotifyUserIds = [];

    /**
     * Runs `$fn`, collapsing every {@see notifyCounter()} call made during it into at most one
     * recompute-and-publish per distinct user id, run once `$fn` returns (or throws).
     *
     * {@see send()} inserts one {@see Notifications} row per enabled channel for the same logical
     * notification; each insert fires the AR event that calls `notifyCounter()`, which without this
     * would recompute the same COUNT query and publish the same value once per channel. Nesting is
     * intentionally not supported (an inner call would just re-flush early) since nothing today calls
     * `send()` from inside another batched block.
     *
     * @param callable $fn
     * @return mixed whatever `$fn` returns
     */
    private function withCounterNotifyBatched(callable $fn)
    {
        $this->_batchingCounterNotify = true;
        try {
            return $fn();
        } finally {
            $this->_batchingCounterNotify = false;
            $userIds = array_keys($this->_pendingCounterNotifyUserIds);
            $this->_pendingCounterNotifyUserIds = [];
            foreach ($userIds as $user_id) {
                $this->_notifyCounterNow($user_id);
            }
        }
    }

    /**
     * Recomputes and publishes one user's unseen-notifications count, or — while
     * {@see withCounterNotifyBatched()} is running — queues the user id for a single flush at the end
     * of that batch instead.
     *
     * The single place that calls {@see $counter_notifier}: both the AR event listeners registered
     * by {@see \webzop\notifications\Bootstrap} (for a plain `save()`, e.g. `Module::send()`'s insert)
     * and the two controller actions that clear the badge via raw SQL — see
     * {@see \webzop\notifications\controllers\DefaultController::prepareNotifications()} and
     * `actionReadAll()`, which bypass ActiveRecord entirely and so cannot rely on an AR event — call
     * this directly instead of duplicating the resolve/try-catch dance.
     *
     * @param integer|null $user_id
     */
    public function notifyCounter($user_id)
    {
        // Also the guard against a broadcast (`user_id == 0`) notification: there is no single topic
        // it could be pushed to without enumerating every eligible user, so it is left to polling.
        if (empty($user_id)) {
            return;
        }
        if ($this->_batchingCounterNotify) {
            $this->_pendingCounterNotifyUserIds[(int) $user_id] = true;
            return;
        }
        $this->_notifyCounterNow($user_id);
    }

    /**
     * The actual resolve/count/publish, shared by {@see notifyCounter()}'s immediate path and
     * {@see withCounterNotifyBatched()}'s flush.
     *
     * @param integer $user_id
     */
    private function _notifyCounterNow($user_id)
    {
        $notifier = $this->resolveCounterNotifier();
        if ($notifier === null) {
            return;
        }
        try {
            $notifier->notify($user_id, Notifications::countUnseenFor($user_id));
        } catch (Throwable $e) {
            Yii::error("Could not notify the notification counter of user {$user_id}: ".$e->getMessage(), __METHOD__);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        if ($this->attachmentsPath && !file_exists(Yii::getAlias($this->attachmentsPath))) {
            mkdir(Yii::getAlias($this->attachmentsPath), 0775, true);
        }
        if ($this->mutex) {
            $this->mutex = Instance::ensure($this->mutex, Mutex::class);
        }
        parent::init();
    }


    /**
     * Send a notification to all channels
     *
     * @param Notification $notification
     * @param array|null $channels
     * @return bool If the sending was successful or not.
     */
    public function send($notification, array $channels = null)
    {
        /** @var \Da\User\Model\User $user */
        $user = (Yii::$app->user->identityClass::findOne($notification->userId));
        
        // If required, we need to check if the user is active
        if ($user && !$this->sendToBlockedUsers) {
            // If user is blocked do not send the notification
            if ($user->isBlocked) {
                //warning
                Yii::error([
                    'msg' => 'Tried to send a notification to a blocked user',
                    'user' => $user->toArray(),
                    'notification' => $notification->toArray(),
                ], __METHOD__);
                return false;
            // same if it's yet to be confirmed
            } elseif (!$user->isConfirmed) {
                Yii::warning([
                    'msg' => 'Tried to send a notification to user still not confirmed',
                    'user' => $user->toArray(),
                    'notification' => $notification->toArray(),
                ], __METHOD__);
                return false;
            }
        }
        
        if ($channels === null) {
            $channels = array_keys($this->channels);
        }

        // All channel rows below belong to the same logical notification, so they must collapse to
        // at most one counter publish; see withCounterNotifyBatched().
        return $this->withCounterNotifyBatched(function () use ($notification, $channels) {
            foreach ((array)$channels as $channelId) {
                $channel = $this->getChannel($channelId);
                if (!$notification->shouldSend($channel) || !$channel->shouldSend($notification)) {
                    continue;
                }
                $model = new Notifications([
                    'notification' => $notification,
                    'channel' => $channelId,
                ]);
                if (!$model->save()) {
                    Yii::error('Cannot save notifications: '.VarDumper::dumpAsString($model->errors), __METHOD__);
                    return false;
                }

                // The notification has to be sent in the future
                if ($notification->sendAt > date('Y-m-d H:i:s')) {
                    continue;
                }

                $handle = 'to'.ucfirst($channelId);
                try {
                    if ($notification->hasMethod($handle)) {
                        $success = call_user_func([clone $notification, $handle], $channel);
                    } else {
                        $success = $channel->send(clone $notification);
                    }
                    // Notification was successfully sent with this channel it can be set in the database
                    // using updateAttributes since validation errors could cause the notification to be sent continuously.
                    if ($success) {
                        $model->updateAttributes(['sent' => true]);
                    }
                } catch (\Exception $e) {
                    if (YII_DEBUG) {
                        throw $e;
                    }
                    Yii::warning("Notification sent by channel '$channelId' has failed: " . $e->getMessage(), __METHOD__);
                    Yii::warning($e, __METHOD__);
                }
            }
            return true;
        });
    }

    /**
     * Gets the channel instance
     *
     * @param string $id the id of the channel
     * @param bool $forceReload forces the load of the channel even if it was cached, defaults to `false`
     * @return Channel|null return the channel
     * @throws InvalidArgumentException
     */
    public function getChannel($id, $forceReload = false)
    {
        if (!isset($this->channels[$id])) {
            throw new InvalidArgumentException("Unknown channel '{$id}'.");
        }

        if (!isset($this->_channels[$id]) || $forceReload) {
            $this->_channels[$id] = $this->createChannel($id, $this->channels[$id]);
        }

        return $this->_channels[$id];
    }

    protected function createChannel($id, $config)
    {
        return Yii::createObject($config, [$id]);
    }
}
