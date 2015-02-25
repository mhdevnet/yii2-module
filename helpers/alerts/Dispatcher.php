<?php

namespace nitm\helpers\alerts;

use Yii;
use yii\helpers\Html;
use yii\helpers\ArrayHelper;
use nitm\helpers\Cache;
use nitm\widgets\models\Alerts;

/**
 * This is the alert dispatcher class.
 */
class Dispatcher extends \yii\base\Component
{
	public $mode;
	public $useFullnames = true;
	public $reportedAction;
	public static $usersWhere = [];
	
	protected static $is = 'alerts';
	protected static $_subject;
	protected static $_body;
	
	protected $_data = [];
	protected $_originUserId;
	protected $_message;
	protected $_notifications = [];
	protected $_sendCount = 0;
	
	private $_prepared = false;
	private $_alerts;
	private $_alertStack = [];
	private $_oldLayouts = [];
	private $_supportedLayouts = ['html', 'text'];
	
	const BATCH = 'batch';
	const SINGLE = 'single';
	
	const EVENT_PREPARE = 'prepare';
	const EVENT_PROCESS = 'process';
	
	public function init()
	{
		$this->initEvents();
		$this->_data = new DispatcherData;
	}
	
	public static function supportedMethods()
	{
		return [
			'any' => 'Any Method',
			'email' => 'Email',
			'mobile' => 'Mobile/SMS'
		];
	}
	
	protected function initEvents()
	{
		$this->on(self::EVENT_PREPARE, [$this, 'prepareAlerts']);
		$this->on(self::EVENT_PROCESS, [$this, 'processAlerts']);
	}
	
	public function reset()
	{
		$this->_data = new DispatcherData;
		$this->reportedAction = '';
		$this->_prepared = false;
		$this->resetMailerLayout();
		$this->_message = null;
	}
	
	public function prepareAlerts($event, $for='any', $priority='any')
	{
		if(!\Yii::$app->getModule('nitm')->enableAlerts)
			return;
		if($event->handled)
			return;
			
		$this->_data->processEventData($event);
		$this->_alertStack[$this->_data->getKey($event->sender)] = [
			'remote_type' => $event->sender->isWhat(),
			'remote_for' => $for,
			'priority' => $priority,
			'action' => $event->sender->getScenario()
		];
	}
	
	/**
	 * Process the alerts according to $message and $parameters
	 * @param array $event = The event triggering the action
	 * @param array $options = the subject and mobile/email messages:
	 * [
	 *		'subject' => String
	 *		'message' => [
	 *			'email' => The email message
	 *			'mobile' => The mobile/text message
	 *		]
	 * ]
	 */
	public function processAlerts($event, $options=[])
	{
		if(!\Yii::$app->getModule('nitm')->enableAlerts)
			return;
		if($event->handled)
			return;
		if(!$this->_data->criteria('parent_type') || $this->_data->criteria('parent_type') == DispatcherData::UNDEFINED)
			return;
		
		$this->_data->criteria('remote_id', $event->sender->getId());
		switch(!$this->_data->criteria('action'))
		{
			case false:
			$this->prepare($event);
			switch($this->isPrepared())
			{
				case true:
				//First check to see if this specific alert exits
				if(count($options))
					$this->sendAlerts($options, ArrayHelper::getValue($options, 'owner_id', null));
				$event->handled = true;
				break;
				
				default:
				throw new \yii\base\Exception("No alert preparation was done!");
				break;
			}
			break;
			
			default:
			throw new \yii\base\Exception("Need an action to process the alert");
			break;
		}
	}
	
	public function prepare($event)
	{
		$this->_data->processEventData($event);
		$basedOn = array_merge(
			(array)ArrayHelper::remove($this->_alertStack, $this->_data->getKey($event->sender)), 
			(array)$event->data
		);
		
		if(is_array($basedOn))
		{
			$basedOn['action'] = $event->sender->isNewRecord === true ? 'create' : 'update';
			$this->reportedAction = $basedOn['action'].'d';
			$this->_data->criteria($basedOn);
			$this->_prepared = true;
		}
	}
	
	public function isPrepared()
	{
		return $this->_prepared === true;
	}
	
	/**
	 * Find alerts for the specific criteia originally provided
	 * @param int $originUserId Is the ID of the user for the object which triggered this alert sequence
	 * @return \yii\db\Query
	 */
	public function findAlerts($originUserId)
	{
		$this->_originUserId = $originUserId;
		return $this->findSpecific($this->_data->criteria())
			->union($this->findOwner($this->_originUserId, $this->_data->criteria()))
			->union($this->findListeners($this->_data->criteria()))
			->union($this->findGlobal($this->_data->criteria()))
			->indexBy('user_id')
			->with('user')->all();
	}
	
	/**
	 * Find alerts for the specific criteia originally provided
	 * @param array $criteria
	 * @return \yii\db\Query
	 */
	public static function findSpecific(array $criteria)
	{
		unset($criteria['user_id']);
		return Alerts::find()->select('*')
			->where($criteria)
			->andWhere([
				'user_id' => \Yii::$app->user->getId()
			])
			->indexBy('user_id')
			->with('user');
	}
	
	/**
	 * Find alerts for the specific criteia originally provided
	 * @param array $criteria
	 * @return \yii\db\Query
	 */
	public static function findOwner($author_id, array $criteria)
	{
		$criteria['user_id'] = $author_id;
		$criteria['action'] .= '_my';
		$criteria['remote_type'] = [$criteria['remote_type'], 'any'];
		$criteria['remote_for'] = [$criteria['remote_for'], 'any'];
		$criteria['priority'] = [$criteria['priority'], 'any'];
		$remoteWhere = [];
		if(isset($criteria['remote_id']))
		{
			$remoteWhere = ['or', '`remote_id`='.$criteria['remote_id'], ' `remote_id` IS NULL'];
			unset($criteria['remote_id']);
		}
		return Alerts::find()->select('*')
			->where($criteria)
			->andWhere($remoteWhere)
			->indexBy('user_id')
			->with('user');
	}
	
	/**
	 * This searches for users who are listening for activity 
	 * Based on the remote_type, action and priority
	 * @param array $criteria
	 * @return \yii\db\Query
	 */
	public static function findListeners(array $criteria)
	{
		unset($criteria['user_id']);
		$criteria['remote_type'] = [$criteria['remote_type'], 'any'];
		$criteria['remote_for'] = [$criteria['remote_for'], 'any'];
		$criteria['action'] = [$criteria['action'], 'any'];
		$criteria['priority'] = [$criteria['priority'], 'any'];
		$remoteWhere = [];
		if(isset($criteria['remote_id']))
		{
			$remoteWhere = ['or', '`remote_id`='.$criteria['remote_id'], ' `remote_id` IS NULL'];
			unset($criteria['remote_id']);
		}
		return Alerts::find()->select('*')
			->orWhere($criteria)
			->andWhere($remoteWhere)
			->andWhere([
				'not', ['user_id' => \Yii::$app->user->getId()]
			])
			->indexBy('user_id')
			->with('user');
	}
	
	/**
	 * Find global listeners for this criteria 
	 * @param array $criteria
	 * @return \yii\db\Query
	 */
	public static function findGlobal(array $criteria)
	{
		$criteria['global'] = 1;
		$criteria['user_id'] = null;
		$criteria['remote_type'] = [$criteria['remote_type'], 'any'];
		$criteria['action'] = [$criteria['action'], 'any'];
		$criteria['priority'] = [$criteria['priority'], 'any'];
		unset($criteria['remote_id']);
		return Alerts::find()->select('*')
			->orWhere($criteria)
			->indexBy('user_id')
			->with('user');
	}
	
	public function sendAlerts($compose, $ownerId)
	{
		$alerts = $this->findAlerts($ownerId);
		$to = [
			'global' => [],
			'individual'=> [],
			'owner' => []
		];
		//Build the addresses
		switch(is_array($alerts) && count($alerts))
		{
			case true:
			$this->setMailerLayout();
			//Organize by global and individual alerts
			foreach($alerts as $idx=>$alert)
			{
				switch(1)
				{
					case $alert->global == 1:
					/**
					 * Only send global emails based on what the user preferrs in their profile. 
					 * For specific alerts those are based ont he alert settings
					 */
					$to['global'] = array_merge_recursive($to['global'], $this->_data->getAddresses($alert->methods, $this->getUsers(), true));
					break;
					
					case $alert->user->getId() == $this->_originUserId:
					$to['owner'] = array_merge_recursive($to['owner'], $this->_data->getAddresses($alert->methods, [$alert->user]));
					break;
					
					default:
					$to['individual'] = array_merge_recursive($to['individual'], $this->_data->getAddresses($alert->methods, [$alert->user]));
					break;
				}
			}
			
			foreach($to as $scope=>$types)
			{
				if(count($types))
					$this->sendAs($scope, $types, $compose);
			}
			
			$this->sendNotifications();
		}
		
		if(\Yii::$app->getModule('nitm')->enableLogger) {
			$logger = \Yii::$app->getModule('nitm')->logger;
			$logger->log([
				'message' => "Sent ".$this->_sendCount." alerts to destinations.\n\nCriteria: ".json_encode($this->_data->criteria(), JSON_PRETTY_PRINT)."\n\nRecipients: ".json_encode(array_map(function (&$group) {
					return array_map(function (&$recipients) {
						return array_map(function(&$recipient) {
							ArrayHelper::remove($recipient, 'user');
							return $recipient;
						}, $recipients);
					}, $group);
				}, $to), JSON_PRETTY_PRINT),
				'level' => 1,
				'internal_category' => 'user-activity',
				'category' => 'Dispatch',
				'timestamp' => time(),
				'action' => 'dispatch-alerts', 
				'table' => Alerts::tableName(),
			], 'nitm-alerts-log');
			$logger->flush(true);
		}
			
		$this->reset();
		return true;
	}
	
	protected function setMailerLayout()
	{
		foreach($this->_supportedLayouts as $layout)
		{
			$property = $layout.'Layout';
			$this->_oldLayouts[$property] = \Yii::$app->mailer->$property;
			\Yii::$app->mailer->$property = '@nitm/mail/layouts/'.$layout;
		}
	}
	
	protected function resetMailerLayout()
	{
		foreach($this->_oldLayouts as $layout=>$path)
		{
			\Yii::$app->mailer->$layout = $path;
		}
	}
	
	/**
	 * Send emails using BCC
	 * @param string $scope Individual, Owner, Global...etc.
	 * @param array $types the types of emails that are being sent out
	 * @param array $compose
	 * @return boolean
	 */
	protected function sendAs($scope, $types, $compose)
	{
		$ret_val = false;
		switch(is_array($types))
		{
			case true:
			$ret_val = true;
			//Send the emails/mobile alerts
			
			//Get the subject
			static::$_subject = $this->_data->extractParam('view', $compose['subject']);
			
			foreach($types as $type=>$unmappedAddresses)
			{
				$addresses = $this->_data->getAddressNameMap($unmappedAddresses);
				switch($this->mode)
				{
					case 'single':
					foreach($addresses as $name=>$email)
					{
						$address = [$name => $email];
						$this->formatMessage($type, $scope, $compose['message'][$type], $address, current($unmappedAddresses)['user']);
						$this->send();
					}
					break;
						
					default:
					$this->formatMessage($type, $scope, $compose['message'][$type], array_slice($addresses, 0, 1));
					$this->send();
					break;
				}
				$this->addNotification($this->getMobileMessage($compose['message']['mobile']), $unmappedAddresses);
			}
			break;
		}
		return $ret_val;
	}
	
	protected function formatMessage($type, $scope, $message, $address, $user=null)
	{
		$params = [
			"subject" => self::$_subject,
			"content" => @$this->_data->extractParam('view', $message)
		];
		
		switch($scope)
		{
			case 'owner':
			$this->_data->variables('%bodyDt%', 'your');
			$this->_data->variables('%subjectDt%', $this->_data->variables('%bodyDt%'));
			break;
			
			default:
			$this->_data->variables('%bodyDt%', (($this->_data->criteria('action') == 'create') ? 'a' : 'the'));
			$this->_data->variables('%subjectDt%', $this->_data->variables('%bodyDt%'));
			break;
		}
		
		if(!is_null($user))
			$params['greeting'] = "Dear ".$user->username.", <br><br>";
			
		$params['title'] = $params['subject'];
		switch($type)
		{
			case 'email':
			$view = ['html' => '@nitm/views/alerts/message/email'];
			$params['content'] = $this->getEmailMessage($params['content'], $user, $scope);
			break;
			
			case 'mobile':
			//140 characters to be able to send a single SMS
			$params['content'] = $this->getMobileMessage($params['content']);
			$params['title'] = '';
			$view = ['text' => '@nitm/views/alerts/message/mobile'];
			break;
		}
		$params = $this->_data->replaceCommon($params);
		$this->_message = \Yii::$app->mailer->compose($view, $params)->setTo($address);
		switch($type)
		{
			case 'email':
			$this->_message->setSubject($params['subject']);
			break;
			
			case 'mobile':
			$this->_message->setTextBody($params['content']);
			break;
		}
	}
	
	protected function send()
	{
		if(!is_null($this->_message))
		{
			$this->_message->setFrom(\Yii::$app->params['components.alerts']['sender'])
				->send();
			$this->_message = null;
			$this->_sendCount++;
			return true;
		}
		else
			return false;
	}
	
	protected function addNotification($message, $addresses)
	{
		foreach((array)$addresses as $address)
		{
			$userId = $address['user']->getId();
			switch(isset($this->_notifications[$userId]))
			{
				case false:
				$this->_notifications[$userId] = [
					$message,
					$this->_data->criteria('priority'),
					$userId 
				];
				break;
			}
		}
	}
	
	protected function sendNotifications()
	{
		switch(is_array($this->_notifications) && !empty($this->_notifications))
		{
			case true:
			$keys = [
				'message',
				'priority',
				'user_id'
			];
			\nitm\widgets\models\Notification::find()->createCommand()->batchInsert(
				\nitm\widgets\models\Notification::tableName(), 
				$keys, 
				array_values($this->_notifications)
			)->execute();
			break;
		}
	}
	
	protected function getMobileMessage($original)
	{
		switch(is_array($original))
		{
			case true:
			$original = $this->_data->extractParam('view', $original);
			break;
		}
		$original = $this->_data->replaceCommon($original);
		//140 characters to be able to send a single SMS
		return strip_tags(strlen($original) <= 140 ? $original : substr($original, 0, 136).'...');
	}
	
	protected function getEmailMessage($original, $user, $scope)
	{
		//140 characters to be able to send a single SMS
		$alertAttributes = null;
		if(!is_null($user) && is_a($user, \nitm\models\User::className())) {
			$alert = ArrayHelper::getValue($this->_alerts, $user->getId(), null);
			if($alert instanceof Alert)
				$alertAttributes =  $alert->getAttributes();
		}
			
		return nl2br($original.$this->getFooter($scope, $alertAttributes));
	}
	
	private function getFooter($scope, $alert=null)
	{	
		$alert = is_array($alert) ? $alert : $this->_data->criteria();
		switch($scope)
		{
			case 'global':
			$footer = "\nYou are receiving this because of a global alert matching: ";
			break;
			
			default:
			$footer = "\nYou are receiving this because your alert settings matched: ";
			break;
		}
		if(isset($alert['priority']) && !is_null($alert['priority']))
		$footer .= "Priority: <b>".ucfirst($alert['priority'])."</b>, ";
		if(isset($alert['remote_type']) && !is_null($alert['remote_type']))
		$footer .= "Type: <b>".ucfirst($alert['remote_type'])."</b>, ";
		if(isset($alert['remote_id']) && !is_null($alert['remote_id']))
		$footer .= "Id: <b>".$alert['remote_id']."</b>, ";
		if(isset($alert['remote_for']) && !is_null($alert['remote_for']))
		$footer .= "For: <b>".ucfirst($alert['remote_for'])."</b>, ";
		if(isset($alert['action']) || !empty($this->reportedAction))
		$footer .= "and Action <b>".Alerts::properName($this->reportedAction)."</b>";
		$footer .= ". Go ".Html::a("here", \Yii::$app->urlManager->createAbsoluteUrl("/user/settings/alerts"))." to change your alerts";
		$footer .= "\n\nSite: ".Html::a(\Yii::$app->urlManager->createAbsoluteUrl('/'), \Yii::$app->homeUrl);
			
		return Html::tag('small', $footer);
	}
	
	protected function getReportedAction($event)
	{
		switch($event->sender->getScenario())
		{
			case 'resolve':
			$this->reportedAction = $event->sender->resolved == 1 ? 'resolved' : 'un-resolved';
			break;
			
			case 'complete':
			$this->reportedAction = $event->sender->completed == 1 ? 'completed' : 'in-completed';
			break;
			
			case 'close':
			$this->reportedAction = $event->sender->closed == 1 ? 'closed' : 're-opened';
			break;
			
			case 'disable':
			$this->reportedAction = $event->sender->disabled == 1 ? 'disabled' : 'enabled';
			break;
		}
	}
}
