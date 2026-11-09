<?php
declare(strict_types=1);
?>
<div id="app-content" class="fadenstack-engine-page">
	<?php if (isset($_['workspace'])): ?>
		<div id="fadenstack-engine-surface" data-workspace="<?php p(json_encode($_['workspace'], JSON_THROW_ON_ERROR)); ?>"></div>
	<?php else: ?>
		<p role="alert"><?php p($_['error']); ?></p>
	<?php endif; ?>
</div>
