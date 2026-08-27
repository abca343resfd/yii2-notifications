<?php

namespace webzop\notifications\widgets;

use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Html;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\helpers\Json;
use yii\db\Query;
use webzop\notifications\NotificationsAsset;
use webzop\notifications\model\Notifications as NotificationModel;


class Notifications extends \yii\base\Widget
{

    public $options = ['class' => 'dropdown nav-notifications'];

    /**
     * @var string|null Name of the global JavaScript object that provides the realtime transport, for
     * instance `'gycRedisWs'` for a `window.gycRedisWs`. Leave it null — the default — and the badge
     * polls exactly as it always did. See {@see \webzop\notifications\base\NotificationCounterNotifier}
     * for the server-side half of this feature.
     *
     * The object is expected to expose:
     *
     * - `subscribe(topic, callback)`, calling back with the message body, which must carry an
     *   **absolute** `count` (`{"action": "update", "count": 3}`);
     * - optionally `onUnavailable(callback)`, invoked when the transport has definitively failed —
     *   the hook this widget uses to fall back to polling.
     */
    public $realtimeClient = null;

    /**
     * @var string|null The topic/channel the transport must listen on for this user's counter. Built
     * by the host application and expected to be user-specific.
     */
    public $realtimeTopic = null;

    /**
     * @var string|null Asset bundle class providing the transport named by {@see $realtimeClient},
     * registered before this widget's own bundle so the object exists by the time the inline script
     * below runs. Optional: leave it null when the host already registers that bundle elsewhere.
     */
    public $realtimeAsset = null;

    /**
     * @var string the HTML options for the item count tag. Key 'tag' might be used here for the tag name specification.
     * For example:
     *
     * ```php
     * [
     *     'tag' => 'span',
     *     'class' => 'badge badge-warning',
     * ]
     * ```
     */
    public $countOptions = [];

    /**
     * @var array additional options to be passed to the notification library.
     * Please refer to the plugin project page for available options.
     */
    public $clientOptions = [];
    /**
     * @var integer the XHR timeout in milliseconds
     */
    public $xhrTimeout = 2000;
    /**
     * @var integer The delay between pulls in milliseconds
     */
    public $pollInterval = 60000;

    public function init()
    {
        parent::init();

        if(!isset($this->options['id'])){
            $this->options['id'] = $this->getId();
        }

        // The two realtime parameters are meaningless apart, and half a configuration would silently
        // degrade to polling — the exact symptom somebody enabling this feature is trying to fix.
        if (empty($this->realtimeClient) !== empty($this->realtimeTopic)) {
            throw new InvalidConfigException(
                'Notifications widget: `realtimeClient` and `realtimeTopic` must be set together.'
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        echo $this->renderNavbarItem();

        $this->registerAssets();
    }

    /**
     * @inheritdoc
     */
    protected function renderNavbarItem()
    {
        $html  = Html::beginTag('li', $this->options);
        $html .= Html::beginTag('a', ['href' => '#', 'class' => 'dropdown-toggle', 'data-toggle' => 'dropdown']);
        $html .= Html::tag('span', '', ['class' => 'glyphicon glyphicon-bell']);

        $count = self::getCountUnseen();
        $countOptions = array_merge([
            'tag' => 'span',
            'data-count' => $count,
        ], $this->countOptions);
        Html::addCssClass($countOptions, 'label label-warning navbar-badge notifications-count');
        if(!$count){
            $countOptions['style'] = 'display: none;';
        }
        $countTag = ArrayHelper::remove($countOptions, 'tag', 'span');
        $html .= Html::tag($countTag, $count, $countOptions);

        $html .= Html::endTag('a');
        $html .= Html::begintag('div', ['class' => 'dropdown-menu']);
        $header = Html::a(Yii::t('modules/notifications', 'Mark all as read'), '#', ['class' => 'read-all pull-right']);
        $header .= Yii::t('modules/notifications', 'Notifications');
        $html .= Html::tag('div', $header, ['class' => 'header']);

        $html .= Html::begintag('div', ['class' => 'notifications-list']);
        //$html .= Html::tag('div', '<span class="ajax-loader"></span>', ['class' => 'loading-row']);
        $html .= Html::tag('div', Html::tag('span', Yii::t('modules/notifications', 'There are no notifications to show'), ['style' => 'display: none;']), ['class' => 'empty-row']);
        $html .= Html::endTag('div');

        $footer = Html::a(Yii::t('modules/notifications', 'View all'), ['/notifications/default/index']);
        $html .= Html::tag('div', $footer, ['class' => 'footer']);
        $html .= Html::endTag('div');
        $html .= Html::endTag('li');

        return $html;
    }

    /**
     * Registers the needed assets
     */
    public function registerAssets()
    {
        // `null` when no transport is configured, which is what makes the JS module take its
        // original polling-only path. See `init()` for why the two properties always agree.
        $realtime = empty($this->realtimeClient) ? null : [
            'client' => $this->realtimeClient,
            'topic' => $this->realtimeTopic,
        ];
        $this->clientOptions = array_merge([
            'id' => $this->options['id'],
            'url' => Url::to(['/notifications/default/list']),
            'countUrl' => Url::to(['/notifications/default/count']),
            'readUrl' => Url::to(['/notifications/default/read']),
            'readAllUrl' => Url::to(['/notifications/default/read-all']),
            'xhrTimeout' => Html::encode($this->xhrTimeout),
            'pollInterval' => Html::encode($this->pollInterval),
            'realtime' => $realtime,
        ], $this->clientOptions);

        $js = 'Notifications(' . Json::encode($this->clientOptions) . ');';
        $view = $this->getView();

        if (!empty($this->realtimeAsset)) {
            /** @var \yii\web\AssetBundle $bundle */
            $bundle = $this->realtimeAsset;
            $bundle::register($view);
        }
        NotificationsAsset::register($view);

        $view->registerJs($js);
    }

    public static function getCountUnseen(){
        return NotificationModel::countUnseenFor(Yii::$app->getUser()->getId());
    }

}
