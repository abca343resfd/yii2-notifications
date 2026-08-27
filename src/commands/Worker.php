<?php

namespace webzop\notifications\commands;

use webzop\notifications\model\Notifications;
use webzop\notifications\Module;
use webzop\notifications\Notification;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\db\Query;

class Worker extends \yii\queue\cli\Queue
{
    /**
     * @var string Unique ID of the worker
     */
    protected $_wId;

    /**
     * @var Module
     */
    public $module;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        $this->_wId = uniqid();
        parent::init();
    }

    /**
     * Method not allowed here since it's not a real queue and it will be handled via the notifications table.
     * {@inheritdoc}
     */
    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        throw new \yii\console\Exception("Method not allowed");
    }

    /**
     * @inheritDoc
     */
    public function status($id)
    {
        $payload = Notifications::findOne($id);

        if (!$payload) {
            throw new InvalidArgumentException("Unknown message ID: $id.");
        }

        if (!$payload->send_at > date('Y-m-d H:i:s')) {
            return self::STATUS_WAITING;
        }

        return self::STATUS_DONE;
    }

    /**
     * Listens worker and runs each job.
     *
     * @param bool $repeat whether to continue listening when queue is empty.
     * @param int $timeout number of seconds to sleep before next iteration.
     * @return null|int exit code.
     */
    public function run($repeat, $timeout = 0)
    {
        return $this->runWorker(function (callable $canContinue) use ($repeat, $timeout) {
            while ($canContinue()) {
                if ($payload = $this->reserve()) {
                    // Reserved rows just crossed their `send_at`, so they only now start counting as
                    // unseen for Notifications::countUnseenFor() — nothing else republishes the badge
                    // for them, since their original insert (in Module::send()) skipped the publish
                    // while they were still in the future. Collected here and flushed once per user
                    // after the loop so a scheduled notification sent over several channels still
                    // triggers a single publish, the same as an immediate send (see
                    // Module::withCounterNotifyBatched()).
                    $dueUserIds = [];
                    foreach ($payload as $notification) {
                        $channel = $this->module->getChannel($notification->channel, true);
                        $tempNotification = Notification::create($notification->key, [
                            'userId' => $notification->user_id,
                            'title' => $notification->message,
                            'description' => $notification->content,
                            'attachments' => $notification->attachments,
                            'language' => $notification->language,
                            'route' => unserialize($notification->route),
                        ]);
                        if($channel->send($tempNotification)) {
                            $notification->updateAttributes(['sent' => true]);
                            foreach ($notification->attachments as $attachment) {
                                unlink($attachment['path']);
                            }
                        }
                        if (!empty($notification->user_id)) {
                            $dueUserIds[(int) $notification->user_id] = true;
                        }
                    }
                    foreach (array_keys($dueUserIds) as $user_id) {
                        $this->module->notifyCounter($user_id);
                    }
                } elseif (!$repeat) {
                    break;
                } elseif ($timeout) {
                    sleep($timeout);
                }
            }
        });
    }

    /**
     * Takes one message from waiting list and reserves it for handling.
     *
     * @return Notifications[]|false payload
     * @throws Exception in case it hasn't waited the lock
     */
    protected function reserve()
    {
        return Notifications::getDb()->useMaster(function () {
            if ($this->module->mutex && !$this->module->mutex->acquire(__CLASS__ . $this->_wId)) {
                throw new Exception('Has not waited the lock.');
            }
            try {
                // Reserve one message
                $payload = Notifications::find()
                    ->andWhere(['sent' => false])
                    ->andWhere(['<=', 'send_at', date('Y-m-d H:i:s')])
                    ->all();
            } finally {
                if($this->module->mutex) {
                    $this->module->mutex->release(__CLASS__ . $this->_wId);
                }
            }

            return $payload;
        });
    }
}