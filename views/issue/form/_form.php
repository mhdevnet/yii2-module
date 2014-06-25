<?php

use yii\helpers\Html;
use kartik\widgets\ActiveForm;
use kartik\widgets\Select2;
use nitm\models\Issues;

/**
 * @var yii\web\View $this
 * @var app\models\Issues $model
 * @var yii\widgets\ActiveForm $form
 */

$action = ($model->getIsNewRecord()) ? "create" : "update";
$enableComments = isset($enableComments) ? $enableComments : \Yii::$app->request->get($model->commentParam);
?>

<div class="issues-form" id='issues-form'>
	<?= \nitm\widgets\alert\Alert::widget(); ?>
	<div id="alert"></div>

	<?php $form = ActiveForm::begin([
		"type" => ActiveForm::TYPE_VERTICAL,
		'action' => \Yii::$app->urlManager->createUrl(['/issue/'.$action.($model->getIsNewRecord() ? "" : "/".$model->getId()), $model->commentParam => $enableComments]),
		'options' => [
			"role" => "updateIssue"
		],
		'fieldConfig' => [
			'inputOptions' => ['class' => 'form-control'],
			'template' => "{label}\n<div class=\"col-lg-12 col-md-12\">{input}</div>\n<div class=\"col-lg-12\">{error}</div>",
			'labelOptions' => ['class' => 'control-label'],
		],
		'enableAjaxValidation' => true
	]); ?>

    <?= $form->field($model, 'title', [
				'addon' => [
					'prepend' => [
						'content' => \nitm\widgets\priority\Priority::widget([
							'type' => 'addon',
							'inputsInline' => true,
							'addonType' => 'radiolist',
							'fieldName' => 'status',
							'model' => $model,
							'form' => $form
						]),
						'asButton' => true
					],
					'groupOptions' => [
					]
				],
				'options' => [
					'class' => 'chat-message-title',
				]
			])->textInput([
			'placeholder' => "Title for this issue",
			'tag' => 'span'
		])->label("Title", ['class' => 'sr-only']); ?>
    <?= $form->field($model, 'notes')->textarea()->label("Issue", ['class' => 'sr-only']) ?>
	<?php //$form->field($model, 'status')->radioList(Issues::getStatusLabels(), ['inline' => true])->label("Urgency"); ?>
	<?php
		switch($model->getIsNewRecord())
		{
			case true:
			echo Html::activeHiddenInput($model, 'parent_id', ['value' => $parentId]);
			echo Html::activeHiddenInput($model, 'parent_type', ['value' => $parentType]);
			break;
		}
	?>
		
	<div class="fixed-actions text-right">
		<?= Html::submitButton(ucfirst($action), ['class' => $model->isNewRecord ? 'btn btn-success' : 'btn btn-primary']) ?>
	</div>

    <?php ActiveForm::end(); ?>

</div>

<script type='text/javascript'>
$nitm.addOnLoadEvent(function () {
	$nitm.issueTracker.initCreateUpdate('#issues-form');
});
</script>
