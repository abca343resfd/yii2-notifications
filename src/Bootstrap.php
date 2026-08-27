<?php

namespace webzop\notifications;

use webzop\notifications\model\Notifications;
use Yii;
use yii\base\Event;
use yii\console\Application as ConsoleApplication;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;

/**
 * notifications module bootstrap class.
 */
class Bootstrap implements \yii\base\BootstrapInterface
{
    /**
     * @inheritdoc
     */
    public function bootstrap($app)
    {
        // add module I18N category
        if (!isset($app->i18n->translations['modules/notifications/*'])) {
            $app->i18n->translations['modules/notifications*'] = [
                'class' => 'yii\i18n\PhpMessageSource',
                'sourceLanguage' => 'en-US',
                'basePath' => '@webzop/notifications/messages',
            ];
        }

        if(is_a($app, ConsoleApplication::class)) {
            $app->getModule('notifications')->controllerNamespace = 'webzop\notifications\commands';
        }

        // Forced unconditionally for both web and console: this class (registered via this package's
        // composer.json `extra.bootstrap`) is the only thing Yii runs on every single request without
        // being asked, so it is the only reliable place to wire up the realtime counter regardless of
        // whether anything else on the page/command happens to instantiate the module.
        /** @var Module $module */
        $module = $app->getModule('notifications');
        if ($module->counter_notifier === null) {
            return;
        }
        // Resolved here, not lazily inside the handlers below: a misconfigured `counter_notifier` has
        // to fail loudly at boot, not be caught and logged on the first notification.
        $module->resolveCounterNotifier();

        // Covers a plain AR `save()` on this model, e.g. `Module::send()`'s insert of a new
        // Notifications row. It does NOT cover `DefaultController::prepareNotifications()` or
        // `actionReadAll()`, which clear `seen` via raw `createCommand()->update()` — that bypasses
        // ActiveRecord entirely, so no AR event fires for it no matter which one is listened to here;
        // those two call `Module::notifyCounter()` directly instead (see their own code).
        Event::on(Notifications::class, ActiveRecord::EVENT_AFTER_INSERT, function (AfterSaveEvent $event) use ($module) {
            /** @var Notifications $row */
            $row = $event->sender;
            // Not yet visible to `countUnseenFor()`'s `send_at <= now` filter, so publishing now
            // would just republish the count unchanged.
            if (!empty($row->send_at) && $row->send_at > date('Y-m-d H:i:s')) {
                return;
            }
            if ((int) $row->user_id === 0) {
                Yii::debug("Notification {$row->id} is a broadcast (user_id=0); push skipped, polling still delivers it.", __METHOD__);
                return;
            }
            $module->notifyCounter($row->user_id);
        });
        // Defensive net for any future plain AR `save()` on this model that changes `seen`/`read` —
        // today nothing in this package does one (see the raw-SQL note above), so this listener is
        // currently dormant for `seen`/`read` transitions, but costs nothing to keep registered.
        Event::on(Notifications::class, ActiveRecord::EVENT_AFTER_UPDATE, function (AfterSaveEvent $event) use ($module) {
            if (!array_intersect(['seen', 'read'], array_keys($event->changedAttributes))) {
                return;
            }
            /** @var Notifications $row */
            $row = $event->sender;
            if ((int) $row->user_id === 0) {
                return;
            }
            $module->notifyCounter($row->user_id);
        });
    }
}
