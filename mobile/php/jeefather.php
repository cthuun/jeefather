<?php
if (!isConnect()) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$eqLogics = eqLogic::byType('jeefather');
?>
<div class="eqLogicThumbnailContainer">
	<?php foreach ($eqLogics as $eqLogic): ?>
		<div class="eqLogicDisplayCard cursor" data-eqLogic_id="<?php echo $eqLogic->getId(); ?>" style="border-radius:8px;margin:5px;padding:10px;">
			<?php echo $eqLogic->getHumanName(true, true); ?>
		</div>
	<?php endforeach; ?>
</div>
<?php include_file('mobile', 'jeefather', 'js', 'jeefather'); ?>
