<?php
/**
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div class="guest-box organization-invite" style="text-align: start; max-width: 420px;">
<?php if ($_['invalid']): ?>
	<h2><?php p($l->t('This invitation is no longer valid')); ?></h2>
	<p><?php p($l->t('The link was already used, has expired or was replaced by a newer one. Ask the person who invited you to send a new invitation.')); ?></p>
<?php else: ?>
	<h2><?php p($l->t('Welcome, %s', [$_['displayName']])); ?></h2>
	<p><?php p($l->t('You were invited to work on:')); ?></p>
	<ul style="margin: 8px 0 16px 20px; list-style: disc;">
		<?php foreach ($_['projects'] as $project): ?>
			<li>
				<strong><?php p($project['name']); ?></strong>
				<?php p($l->t('at %1$s, invited by %2$s', [$project['organization'], $project['inviter']])); ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<p><?php p($l->t('Choose a password. You will sign in with %s.', [$_['email']])); ?></p>

	<?php if ($_['error'] !== null): ?>
		<p class="warning" role="alert" style="margin: 12px 0;"><?php p($_['error']); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php p($_['acceptUrl']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<p style="margin: 12px 0;">
			<label for="invite-password"><?php p($l->t('Password')); ?></label><br>
			<input id="invite-password" type="password" name="password" autocomplete="new-password" required style="width: 100%;">
		</p>
		<p style="margin: 12px 0;">
			<label for="invite-password-confirm"><?php p($l->t('Repeat password')); ?></label><br>
			<input id="invite-password-confirm" type="password" name="passwordConfirm" autocomplete="new-password" required style="width: 100%;">
		</p>
		<p style="margin: 12px 0;">
			<input id="invite-terms" type="checkbox" name="terms" value="1" class="checkbox" required>
			<label for="invite-terms"><?php p($l->t('I agree that my name, company and email are visible to the project team.')); ?></label>
		</p>
		<button type="submit" class="primary" style="width: 100%;"><?php p($l->t('Accept invitation')); ?></button>
	</form>
<?php endif; ?>
</div>
