<?php
declare(strict_types=1);
?>
<div id="app-content">
	<div class="fadenstack-oauth-result" style="max-width: 42rem; margin: 2rem; font: inherit;">
		<h2><?php p($l->t('Workflow connection')); ?></h2>
		<?php if (isset($_['success'])): ?>
			<p role="status"><?php p($l->t('OAuth access saved encrypted in your Nextcloud account. No workflow was run. Return to your original chat and reload the workflow connections.')); ?></p>
		<?php else: ?>
			<p role="alert"><?php p($_['error']); ?></p>
		<?php endif; ?>
		<p><?php p($l->t('You can close this tab. Agent executions still require their own approval.')); ?></p>
	</div>
</div>
