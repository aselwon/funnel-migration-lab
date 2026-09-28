<?php
require __DIR__.'/../../src/bootstrap.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { csrf(); $row = create_lead($_POST); redirect(checkout_url((int)$row['id'])); }
    catch (DomainException $ex) { http_response_code($ex->getCode()); $error = $ex->getMessage(); }
}
legacy_header('Sign up'); ?>
<p role="alert"><?=e($error)?></p>
<form method="post">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>">
<p><label>Name <input name="name" maxlength="100" required value="<?=e($_POST['name'] ?? '')?>"></label></p>
<p><label>Email <input name="email" type="email" maxlength="254" required value="<?=e($_POST['email'] ?? '')?>"></label></p>
<p><label>Plan <select name="plan"><option value="free">Free — $0</option><option value="paid" <?=($_POST['plan'] ?? '') === 'paid' ? 'selected' : ''?>>Pro — $29 once</option></select></label></p>
<button>Continue</button>
</form>
<?php legacy_footer(); ?>
