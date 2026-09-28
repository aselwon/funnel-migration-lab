<?php
require __DIR__.'/../../src/bootstrap.php';
try {
    $id = (int)($_GET['id'] ?? 0); $row = lead($id);
    if (migrated()) redirect('/app/checkout/'.$id);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf(); $row = pay($id, $_POST['intent'] ?? null); redirect('/legacy/payment.php?id='.$id); }
    legacy_header('Checkout status');
    echo '<p>Thanks, '.e($row['name']).'.</p><p>Status: <strong>'.e($row['status']).'</strong></p>';
    if ($row['status'] === 'pending') {
        $intent = checkout_intent($id);
        echo '<p>Pro toolkit — $29.00 USD. This is a simulation; no money is charged.</p><form method="post"><input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'"><input type="hidden" name="intent" value="'.e($intent['intent']).'"><button>Simulate payment</button></form>';
    } else echo '<p>Your toolkit is ready. Your registration is saved.</p>';
    legacy_footer();
} catch (DomainException $ex) { http_response_code($ex->getCode()); legacy_header('Checkout error'); echo '<p>'.e($ex->getMessage()).'</p>'; legacy_footer(); }
