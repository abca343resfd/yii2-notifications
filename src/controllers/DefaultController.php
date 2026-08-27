<?php

namespace webzop\notifications\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\db\Query;
use yii\data\Pagination;
use yii\helpers\Url;
use webzop\notifications\helpers\TimeElapsed;
use webzop\notifications\widgets\Notifications;
use webzop\notifications\model\Notifications as NotificationModel;

class DefaultController extends Controller
{

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ]
                ]
            ],
        ];
    }

    public $layout = "@app/views/layouts/main";

    /**
     * Displays index page.
     *
     * @return string
     */
    public function actionIndex()
    {
        $userId = Yii::$app->getUser()->getId();
        $query = NotificationModel::find()
            ->andWhere(['or', 'user_id = 0', 'user_id = :user_id'], [':user_id' => $userId]);

        $pagination = new Pagination([
            'pageSize' => 20,
            'totalCount' => $query->count(),
        ]);

        $list = $query
            ->orderBy(['id' => SORT_DESC])
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->asArray()
            ->all();

        $notifs = $this->prepareNotifications($list);

        return $this->render('index', [
            'notifications' => $notifs,
            'pagination' => $pagination,
        ]);
    }

    public function actionList()
    {
        $userId = Yii::$app->getUser()->getId();
        $list = NotificationModel::find()
            ->andWhere(['or', 'user_id = 0', 'user_id = :user_id'], [':user_id' => $userId])
            // NULL-safe on purpose: an ordinary (non-scheduled) notification has `send_at = NULL`, and
            // must show up here just like it counts in `NotificationModel::countUnseenFor()` below —
            // see that method's docblock for why a plain `<=` used to drop every one of them.
            ->andWhere(['or', ['send_at' => null], ['<=', 'send_at', date('Y-m-d H:i:s')]])
            ->orderBy(['id' => SORT_DESC])
            ->limit(10)
            ->asArray()
            ->all();
        $notifs = $this->prepareNotifications($list);
        // The fresh absolute count, alongside the list: `showList()` in notifications.js uses it to
        // set the badge instead of computing a client-side decrement, which used to race the realtime
        // push triggered by prepareNotifications()' own seen-update below.
        $this->ajaxResponse(['list' => $notifs, 'count' => NotificationModel::countUnseenFor($userId)]);
    }

    public function actionCount()
    {
        $count = Notifications::getCountUnseen();
        $this->ajaxResponse(['count' => $count]);
    }

    public function actionRead($id)
    {
        Yii::$app->getDb()->createCommand()->update('{{%notifications}}', ['read' => true], ['id' => $id])->execute();

        if(Yii::$app->getRequest()->getIsAjax()){
            return $this->ajaxResponse(1);
        }

        return Yii::$app->getResponse()->redirect(['/notifications/default/index']);
    }

    public function actionReadAll()
    {
        Yii::$app->getDb()->createCommand()->update(
            '{{%notifications}}',
            ['read' => true, 'seen' => true],
            ['user_id' => Yii::$app->user->id]
            )->execute();
        // Raw SQL above, so no ActiveRecord event fires for it (see Module::notifyCounter()'s
        // docblock) — called directly instead, and unambiguous since the update itself is already
        // scoped to this one user.
        Yii::$app->getModule('notifications')->notifyCounter(Yii::$app->user->id);
        if(Yii::$app->getRequest()->getIsAjax()){
            return $this->ajaxResponse(1);
        }

        Yii::$app->getSession()->setFlash('success', Yii::t('modules/notifications', 'All notifications have been marked as read.'));

        return Yii::$app->getResponse()->redirect(['/notifications/default/index']);
    }

    public function actionDeleteAll()
    {
        Yii::$app->getDb()->createCommand()->delete('{{%notifications}}')->execute();

        if(Yii::$app->getRequest()->getIsAjax()){
            return $this->ajaxResponse(1);
        }

        Yii::$app->getSession()->setFlash('success', Yii::t('modules/notifications', 'All notifications have been deleted.'));

        return Yii::$app->getResponse()->redirect(['/notifications/default/index']);
    }

    private function prepareNotifications($list){
        $notifs = [];
        $seen = [];
        foreach($list as $notif){
            if(!$notif['seen']){
                $seen[] = $notif['id'];
            }
            $route = @unserialize($notif['route']);
            $notif['url'] = !empty($route) ? Url::to($route) : '';
            $notif['timeago'] = TimeElapsed::timeElapsed($notif['created_at']);
            $notifs[] = $notif;
        }

        if(!empty($seen)){
            Yii::$app->getDb()->createCommand()->update('{{%notifications}}', ['seen' => true], ['id' => $seen])->execute();
            // Raw SQL above bypasses ActiveRecord, so no AR event fires for it — called directly
            // instead. Both callers of this method (actionIndex/actionList) already scope $list to
            // the current user, so there is exactly one user to notify.
            Yii::$app->getModule('notifications')->notifyCounter(Yii::$app->getUser()->getId());
        }

        return $notifs;
    }

    public function ajaxResponse($data = [])
    {
        if(is_string($data)){
            $data = ['html' => $data];
        }

        $session = \Yii::$app->getSession();
        $flashes = $session->getAllFlashes(true);
        foreach ($flashes as $type => $message) {
            $data['notifications'][] = [
                'type' => $type,
                'message' => $message,
            ];
        }
        return $this->asJson($data);
    }

}
