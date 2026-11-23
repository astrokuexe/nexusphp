<?php
require_once("../include/bittorrent.php");
$legacyHook = $GLOBALS['hook'];
$legacyPlugin = $GLOBALS['plugin'];
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['hook'] = $hook = $legacyHook;
$GLOBALS['plugin'] = $plugin = $legacyPlugin;
dbconn();
\Nexus\Database\RuntimeMigrations::ensureAccountRequestsTable();
\Nexus\Database\RuntimeMigrations::ensureInviteRegistrationRequestsTable();

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function renderAccountApprovalPage(string $title, string $message, ?string $token = null): never
{
    stdhead($title);
    begin_main_frame();
    print('<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>');
    print('<p class="text">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>');

    if ($token !== null) {
        $csrfToken = nexus_csrf_token('account.approval', hash('sha256', $token));
        print('<form method="post" action="account-approval.php">');
        print('<input type="hidden" name="approval_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">');
        print('<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">');
        print('<button class="btn" type="submit">Confirm and activate account</button></form>');
    }

    end_main_frame();
    stdfoot();
    exit;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$tokenValue = $isPost ? ($_POST['approval_token'] ?? '') : ($_GET['token'] ?? '');
if (!is_string($tokenValue)) {
    renderAccountApprovalPage('Invalid approval link', 'This approval link is invalid, expired, or has already been used.');
}

$approvalToken = \Nexus\Account\AccountApproval::decodeToken($tokenValue);
if (!$approvalToken) {
    renderAccountApprovalPage('Invalid approval link', 'This approval link is invalid, expired, or has already been used.');
}

$pendingRequest = \Nexus\Account\AccountApproval::getPendingRequest(
    $approvalToken['type'],
    $approvalToken['request_id']
);
if (!$pendingRequest || !hash_equals($approvalToken['email'], $pendingRequest['email'])) {
    renderAccountApprovalPage('Request unavailable', 'This account request is no longer pending or its email address has changed.');
}

if ($isPost) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $expectedCsrfToken = nexus_csrf_token('account.approval', hash('sha256', $tokenValue));
    if (!is_string($csrfToken) || !hash_equals($expectedCsrfToken, $csrfToken)) {
        renderAccountApprovalPage('Invalid request', 'Please reopen the approval link and try again.');
    }

    try {
        $approval = \Nexus\Account\AccountApproval::approve(
            $approvalToken['type'],
            $approvalToken['request_id'],
            $approvalToken['email']
        );
    } catch (\DomainException $exception) {
        renderAccountApprovalPage('Unable to approve account', $exception->getMessage());
    }
    if (!$approval) {
        renderAccountApprovalPage('Request unavailable', 'This account request is no longer pending or its email address has changed.');
    }

    \Nexus\Account\AccountApproval::complete($approval);
    renderAccountApprovalPage('Account approved', 'Your account has been approved and is ready to use.');
}

$requestDescription = $approvalToken['type'] === 'donation'
    ? 'the ' . (string) $pendingRequest['plan'] . ' VIP account request'
    : 'the account request';
renderAccountApprovalPage(
    'Confirm account approval',
    'Confirm approval for ' . $requestDescription . ' belonging to ' . $pendingRequest['username'] . '.',
    $tokenValue
);
