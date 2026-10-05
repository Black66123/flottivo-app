<?php
/**
 * Stripe-Webhook: in Stripe unter Entwickler → Webhooks diese Adresse eintragen:
 *   https://IHRE-PLATTFORM-ADRESSE/stripe-webhook.php
 * Ereignisse: checkout.session.completed, customer.subscription.created/updated/deleted,
 *             invoice.paid, invoice.payment_failed
 */
define('PLATFORM_AREA', true);
define('NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$payload = (string) file_get_contents('php://input');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_file(PLATFORM_DB)
    || !stripe_verify_signature($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), (string) psetting('stripe_webhook_secret', ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'fehler' => 'Signatur ungültig']);
    exit;
}
$ev = json_decode($payload, true);
if (!is_array($ev)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
try {
    $info = stripe_handle_event($ev);
    set_psetting('stripe_last_webhook', date('Y-m-d H:i:s') . ' · ' . ($ev['type'] ?? ''));
    echo json_encode(['ok' => true, 'info' => $info]);
} catch (Throwable $e) {
    error_log('Stripe-Webhook: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
