<?php
declare(strict_types=1);

require_once __DIR__ . '/include/mysqli_connect.php';
require_once __DIR__ . '/include/request_functions.php';

mysqli_report(MYSQLI_REPORT_OFF);

function pageEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$recipients = [];
$users = [];
$items = [];
$error = '';
$formRequestDate = date('Y-m-d\TH:i');
$formRecipientMode = 'existing';
$formRecipientId = '';
$formUserId = '';
$formNewRecipient = [
    'contact_name' => '',
    'organization_name' => '',
    'phone_number' => '',
    'email_address' => '',
];
$formItemIds = [''];
$formQuantities = ['1'];

try {
    $recipients = getRequestRecipients($conn);
    $users = getRequestUsers($conn);
    $items = getRequestItems($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formRequestDate = is_string($_POST['request_date'] ?? null)
            ? $_POST['request_date']
            : $formRequestDate;
        $formRecipientMode = ($_POST['recipient_mode'] ?? '') === 'new' ? 'new' : 'existing';
        $formRecipientId = is_string($_POST['recipient_id'] ?? null)
            ? $_POST['recipient_id']
            : '';
        $formUserId = is_string($_POST['user_id'] ?? null)
            ? $_POST['user_id']
            : '';
        $formItemIds = is_array($_POST['item_id'] ?? null) ? $_POST['item_id'] : [''];
        $formQuantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : ['1'];
        $postedNewRecipient = is_array($_POST['new_recipient'] ?? null) ? $_POST['new_recipient'] : [];
        foreach (array_keys($formNewRecipient) as $field) {
            $formNewRecipient[$field] = is_string($postedNewRecipient[$field] ?? null)
                ? $postedNewRecipient[$field]
                : '';
        }

        $requestId = createRequestWithItems(
            $conn,
            $formRequestDate,
            $formRecipientMode === 'existing' ? (int) $formRecipientId : null,
            (int) $formUserId,
            $formItemIds,
            $formQuantities,
            $formRecipientMode === 'new' ? $formNewRecipient : null
        );

        header('Location: index.php?' . http_build_query([
            'table' => 'request',
            'message' => 'Request #' . $requestId . ' created successfully.',
        ]));
        exit;
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Request - International Book Project</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="js/request_create.js" defer></script>
</head>
<body>
    <h1>Create Request</h1>
    <p><a href="index.php?table=request">Back to Requests</a></p>

    <?php if ($error !== ''): ?>
        <p>Error: <?= pageEscape($error) ?></p>
    <?php endif; ?>

    <?php if ($recipients === [] || $users === [] || $items === []): ?>
        <p>Recipients, users, and items are required before creating a request.</p>
    <?php else: ?>
        <form method="post" action="">
            <fieldset>
                <legend>Recipient</legend>
                <p>
                    <label>
                        <input type="radio" name="recipient_mode" value="existing"
                               <?= $formRecipientMode === 'existing' ? ' checked' : '' ?>>
                        Use existing recipient
                    </label>
                    <label>
                        <input type="radio" name="recipient_mode" value="new"
                               <?= $formRecipientMode === 'new' ? ' checked' : '' ?>>
                        Create new recipient
                    </label>
                </p>

                <div id="existing-recipient-fields">
                    <label for="recipient_id">Existing recipient</label><br>
                    <select id="recipient_id" name="recipient_id"<?= $formRecipientMode === 'existing' ? ' required' : ' disabled' ?>>
                        <option value="">-- Select recipient --</option>
                        <?php foreach ($recipients as $recipient): ?>
                            <?php $recipientValue = (string) $recipient['recipient_id']; ?>
                            <option value="<?= pageEscape($recipientValue) ?>"<?= $formRecipientId === $recipientValue ? ' selected' : '' ?>>
                                <?= pageEscape($recipient['contact_name'] . ' - ' . $recipient['organization_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="new-recipient-fields">
                    <h2>New recipient details</h2>
                    <p>
                        <label for="new_contact_name">Contact name</label><br>
                        <input type="text" id="new_contact_name" name="new_recipient[contact_name]"
                               value="<?= pageEscape($formNewRecipient['contact_name']) ?>">
                    </p>
                    <p>
                        <label for="new_organization_name">Organization name</label><br>
                        <input type="text" id="new_organization_name" name="new_recipient[organization_name]"
                               value="<?= pageEscape($formNewRecipient['organization_name']) ?>">
                    </p>
                    <p>
                        <label for="new_phone_number">Phone number</label><br>
                        <input type="text" id="new_phone_number" name="new_recipient[phone_number]"
                               value="<?= pageEscape($formNewRecipient['phone_number']) ?>">
                    </p>
                    <p>
                        <label for="new_email_address">Email address</label><br>
                        <input type="email" id="new_email_address" name="new_recipient[email_address]"
                               value="<?= pageEscape($formNewRecipient['email_address']) ?>">
                    </p>
                </div>
            </fieldset>

            <p>
                <label for="user_id">Created by</label><br>
                <select id="user_id" name="user_id" required>
                    <option value="">-- Select user --</option>
                    <?php foreach ($users as $user): ?>
                        <?php $userValue = (string) $user['user_id']; ?>
                        <option value="<?= pageEscape($userValue) ?>"<?= $formUserId === $userValue ? ' selected' : '' ?>>
                            <?= pageEscape($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['user_name'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="request_date">Request date</label><br>
                <input type="datetime-local" id="request_date" name="request_date"
                       value="<?= pageEscape($formRequestDate) ?>" required>
            </p>

            <h2>Requested items</h2>
            <table class="request-items">
                <thead>
                <tr>
                    <th>Item</th>
                    <th>Quantity</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody id="request-items-body">
                <?php foreach ($formItemIds as $index => $formItemId): ?>
                    <tr>
                        <td>
                            <select name="item_id[]" required>
                                <option value="">-- Select item --</option>
                                <?php foreach ($items as $item): ?>
                                    <?php $itemValue = (string) $item['item_id']; ?>
                                    <option value="<?= pageEscape($itemValue) ?>"<?= (string) $formItemId === $itemValue ? ' selected' : '' ?>>
                                        <?= pageEscape($item['item_name'] . ' (Available: ' . $item['available_quantity'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="quantity[]" min="1" step="1"
                                   value="<?= pageEscape($formQuantities[$index] ?? '1') ?>" required>
                        </td>
                        <td><button type="button" class="remove-item">Remove</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" id="add-item">Add another item</button>
                <button type="submit">Create Request</button>
            </p>
        </form>

        <template id="request-item-template">
            <tr>
                <td>
                    <select name="item_id[]" required>
                        <option value="">-- Select item --</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?= pageEscape($item['item_id']) ?>">
                                <?= pageEscape($item['item_name'] . ' (Available: ' . $item['available_quantity'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" name="quantity[]" min="1" step="1" value="1" required></td>
                <td><button type="button" class="remove-item">Remove</button></td>
            </tr>
        </template>

    <?php endif; ?>
</body>
</html>
