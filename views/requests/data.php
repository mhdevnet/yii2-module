<?php

use yii\helpers\Html;
use kartik\grid\GridView;
use nitm\helpers\Icon;

/**
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var frontend\models\search\Requests $searchModel
 */

$this->title = 'Requests';
$this->params['breadcrumbs'][] = $this->title;
?>
<?php \yii\widgets\Pjax::begin(); ?>
<?= GridView::widget([
	'options' => [
		'id' => 'requests'
	],
	'filterModel' => $searchModel,
	'filterUrl' => 'index',
	'tableOptions' => [
		'class' => 'table'
	],
	'dataProvider' => $dataProvider,
	//'filterModel' => $searchModel,
	'columns' => [
		[
			'label' => 'ID',
			'attribute' => 'id',
			'format' => 'html',
			'value' => function ($model) {
				$ret_val = "";
				if($model->hasNewActivity)
					$ret_val .= \nitm\widgets\activityIndicator\ActivityIndicator::widget();
				$ret_val .= Html::tag('h1', $model->getId());
				return $ret_val;
			},
			'contentOptions' => function ($model) {
				return [
					'rowspan' => 2,
					'role' => 'voteIndicator'.$model->getId(),
					'style' => "background-color:rgba(255,51,0,".$model->voteModel()->rating()['ratio'].")"
				];
			}
		],
		[
			'attribute' => 'rating',
			'label' => '%',
			'format' => 'raw',
			'value' => function ($model, $index, $widget) {
				return $this->context->voteWidget([
					'model' => $model->voteModel(),
					'parentType' => $model->isWhat(), 
					'parentId' => $model->getId(),
				]);
			},
			'options' => [
				'rowspan' => 3
			]
		],
		[
			'format'  => 'html',
			'attribute' => 'type_id',
			'filter' => $primaryModel->getCategoryList($primaryModel->isWhat().'-categories'),
			'label' => 'Type',
			'value' => function ($model) {
				return $model->url('type_id', [$model->type(), 'name']);
			}
		],
		[
			'format'  => 'html',
			'attribute' => 'request_for_id',
			'filter' => $primaryModel->getCategoryList($primaryModel->isWhat().'-for'),
			'label' => 'Request For',
			'value' => function ($model) {
				return $model->url('request_for_id', [$model->requestFor(), 'name']);
			}
		],
		[
			'format' => 'html',
			'attribute' => 'status',
			'filter' => $primaryModel->getStatuses(),
			'label' => 'Urgency',
			'value' => function ($model, $index, $widget) {
				return $model->url('status', $model->getUrgency());
			}
		],
		//'closed:boolean',
		//'completed:boolean',
		// 'author',
		// 'edited',
		// 'editor',
		// 'edits',
		// 'request:ntext',
		// 'type:ntext',
		// 'request_for:ntext',
		// 'status',
		// 'completed',
		// 'completed_on',
		// 'closed',
		// 'closed_on',
		// 'rating',
		// 'rated_on',
		[
			'attribute' => 'author',
			'label' => 'Author',
			'format' => 'html',
			'filter' => $primaryModel->getFilter('author'),
			'value' => function ($model, $index, $widget) {
				return $model->author()->url(\Yii::$app->getModule('nitm')->useFullnames, \Yii::$app->request->url, [$model->formname().'[author]' => $model->author_id]);
			}
		],
		'created_at:datetime',
		'updated_at:datetime',

		[
			'class' => 'yii\grid\ActionColumn',
			'buttons' => [
				'form/update' => function ($url, $model) {
					return \nitm\widgets\modal\Modal::widget([
						'size' => 'x-large',
						'toggleButton' => [
							'tag' => 'a',
							'label' => Icon::forAction('update'), 
							'href' => \Yii::$app->urlManager->createUrl([$url, '__format' => 'modal']),
							'title' => Yii::t('yii', 'Edit '),
							'class' => 'fa-2x',
							'role' => 'dynamicAction updateAction disabledOnClose',
						],
						'contentOptions' => [
							"class" => "modal-full"
						],
						'dialogOptions' => [
							"class" => "modal-full"
						]
					]);
				},
				'close' => function ($url, $model) {
					return Html::a(Icon::forAction('close', 'closed', $model), \Yii::$app->urlManager->createUrl([$url]), [
						'title' => Yii::t('yii', ($model->closed ? 'Open' : 'Close').' '.$model->title),
						'role' => 'metaAction closeAction',
						'class' => 'fa-2x',
						'data-parent' => 'tr',
						'data-pjax' => '0',
					]);
				},
				'complete' => function ($url, $model) {
					return Html::a(Icon::forAction('complete', 'completed', $model), \Yii::$app->urlManager->createUrl([$url]), [
						'title' => Yii::t('yii', ($model->completed ? 'Incomplete' : 'Complete').' '.$model->title),
						'role' => 'metaAction resolveAction disabledOnClose',
						'class' => 'fa-2x',
						'data-parent' => 'tr',
						'data-pjax' => '0',
					]);
				}
			],
			'template' => "{form/update} {complete} {close}",
			'urlCreator' => function($action, $model, $key, $index) {
				return $this->context->id.'/'.$action.'/'.$model->getId();
			},
			'options' => [
				'rowspan' => 3
			]
		],
	],
	'rowOptions' => function ($model, $key, $index, $grid)
	{
		return [
			"class" => \nitm\helpers\Statuses::getIndicator($model->getStatus()),
			'id' => 'request'.$model->getId(),
			'role' => 'statusIndicator'.$model->getId(),
		];
	},
	"tableOptions" => [
			'class' => 'table table-bordered'
	],
	'afterRow' => function ($model, $key, $index, $grid){
		$replies = $this->context->replyCountWidget([
			"model" => $model->replyModel(),
			'fullDetails' => false,
		]);
		$revisions = $this->context->revisionsCountWidget([
			'model' => $model->revisionModel(),
			"parentId" => $model->getId(), 
			"parentType" => $model->isWhat(),
			'fullDetails' => false ,
		]);
		$issues = $this->context->issueCountWidget([
			'model' => $model->issueModel(),
			'enableComments' => true,
			"parentId" => $model->getId(), 
			"parentType" => $model->isWhat(),
			'fullDetails' => false,
		]);
		$follow = \nitm\widgets\alerts\Follow::widget([
			'model' => $model->followModel(),
			'buttonOptions' => [
				'size' => 'normal'
			]
		]);
		$title = Html::tag('div',
			Html::tag(
				'h4', 
				$model->title
			),
			['class' => 'row']
		);
		
		$activityInfo = Html::tag('div',
			Html::tag('div', $replies, ['class' => 'col-md-3 col-lg-3']).
			Html::tag('div', $revisions, ['class' => 'col-md-3 col-lg-3']).
			Html::tag('div', $issues, ['class' => 'col-md-3 col-lg-3']).
			Html::tag('div', $follow, ['class' => 'col-md-3 col-lg-3']),
			[
				'class' => 'col-md-12 col-lg-12'
			]
		);
		/*$shortLink = Html::tag('div', \lab1\widgets\ShortLink::widget([
			'url' => \Yii::$app->urlManager->createAbsoluteUrl([$model->isWhat().'/view/'.$model->getId()]),
			'header' => $model->title,
			'type' => 'modal',
			'size' => 'large'
		]));*/
		$shortLink = '';
		$metaInfo = Html::tag('div', 
			Html::tag('div', 
				$title.$shortLink."<br>".$activityInfo
			),
			[
				'class' => 'wrapper'
			]
		)."<br>";
				
		/*$statusInfo .= \lab1\widgets\MetaInfo::widget([
			'attributes' => [
				'numbers',
			],
			'items' => [
				[
					'attribute' => 'numbers',
				],
			],
		]);*/
		return Html::tag('tr', 
			Html::tag(
				'td', 
				$metaInfo, 
				[
					'colspan' => 9, 
					'rowspan' => 1,
				]
			),
			[
				"class" => \nitm\helpers\Statuses::getIndicator($model->getStatus()),
				'role' => 'statusIndicator'.$model->getId(),
			]
		);
	},
	/*'pager' => [
		'class' => \kop\y2sp\ScrollPager::className(),
		'container' => '#requests-ias-container',
		'item' => "tr"
	]*/
	'pager' => [
		'linkOptions' => [
			'data-pjax' => 1
		],
	]
]); ?>
<?php \yii\widgets\Pjax::end(); ?>