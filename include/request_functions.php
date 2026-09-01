<?php
declare(strict_types=1);

function getRequestRecipients(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT recipient_id, contact_name, organization_name '
        . 'FROM `recipient` ORDER BY recipient_id'
    );
    if (!$result) {
        throw new RuntimeException('Unable to load recipients: ' . $conn->error);
    }

    $recipients = [];
    while ($row = $result->fetch_assoc()) {
        $recipients[] = $row;
    }
    $result->free();
    return $recipients;
}

function getRequestUsers(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT user_id, first_name, last_name, user_name '
        . 'FROM `system_user` ORDER BY user_id'
    );
    if (!$result) {
        throw new RuntimeException('Unable to load users: ' . $conn->error);
    }

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $result->free();
    return $users;
}

function getRequestItems(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT item_id, item_name, available_quantity '
        . 'FROM `item` ORDER BY item_id'
    );
    if (!$result) {
        throw new RuntimeException('Unable to load items: ' . $conn->error);
    }

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $result->free();
    return $items;
}

function normalizeRequestDate(string $requestDate): string
{
    $normalized = str_replace('T', ' ', trim($requestDate));
    if (strlen($normalized) === 16) {
        $normalized .= ':00';
    }

    $date = DateTime::createFromFormat('Y-m-d H:i:s', $normalized);
    $errors = DateTime::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException('Please enter a valid request date.');
    }

    return $date->format('Y-m-d H:i:s');
}

function createRequestWithItems(
    mysqli $conn,
    string $requestDate,
    ?int $recipientId,
    int $userId,
    array $itemIds,
    array $quantities,
    ?array $newRecipient = null
): int {
    if (($recipientId === null || $recipientId <= 0) && $newRecipient === null) {
        throw new InvalidArgumentException('Please select an existing recipient or enter a new recipient.');
    }
    if ($newRecipient !== null) {
        foreach (['contact_name', 'organization_name', 'phone_number', 'email_address'] as $field) {
            if (!isset($newRecipient[$field]) || trim((string) $newRecipient[$field]) === '') {
                throw new InvalidArgumentException('All new recipient fields are required.');
            }
        }
        if (!filter_var($newRecipient['email_address'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Please enter a valid recipient email address.');
        }
        $recipientId = null;
    }
    if ($userId <= 0) {
        throw new InvalidArgumentException('Please select a user.');
    }
    if ($itemIds === [] || count($itemIds) !== count($quantities)) {
        throw new InvalidArgumentException('Add at least one valid request item.');
    }

    $normalizedDate = normalizeRequestDate($requestDate);
    $normalizedItems = [];
    $seenItems = [];

    foreach ($itemIds as $index => $rawItemId) {
        if (is_array($rawItemId) || !isset($quantities[$index]) || is_array($quantities[$index])) {
            throw new InvalidArgumentException('Invalid request item data.');
        }

        $itemId = (int) $rawItemId;
        $quantity = (int) $quantities[$index];
        if ($itemId <= 0 || $quantity <= 0) {
            throw new InvalidArgumentException('Each item must have a positive quantity.');
        }
        if (isset($seenItems[$itemId])) {
            throw new InvalidArgumentException('The same item can only be added once per request.');
        }

        $seenItems[$itemId] = true;
        $normalizedItems[] = [$itemId, $quantity];
    }

    $conn->begin_transaction();
    try {
        $stockStatement = $conn->prepare(
            'SELECT item_name, available_quantity FROM item WHERE item_id = ? FOR UPDATE'
        );
        if (!$stockStatement) {
            throw new RuntimeException('Unable to check inventory: ' . $conn->error);
        }

        $inventoryUpdate = $conn->prepare(
            'UPDATE item SET available_quantity = available_quantity - ? WHERE item_id = ?'
        );
        if (!$inventoryUpdate) {
            $stockStatement->close();
            throw new RuntimeException('Unable to update inventory: ' . $conn->error);
        }

        foreach ($normalizedItems as [$itemId, $quantity]) {
            $stockStatement->bind_param('i', $itemId);
            if (!$stockStatement->execute()) {
                $error = $stockStatement->error;
                $stockStatement->close();
                $inventoryUpdate->close();
                throw new RuntimeException('Unable to check inventory: ' . $error);
            }

            $stockResult = $stockStatement->get_result();
            $stockRow = $stockResult->fetch_assoc();
            if ($stockRow === null) {
                $stockStatement->close();
                $inventoryUpdate->close();
                throw new InvalidArgumentException('The selected item does not exist.');
            }

            $availableQuantity = (int) $stockRow['available_quantity'];
            if ($quantity > $availableQuantity) {
                $stockStatement->close();
                $inventoryUpdate->close();
                throw new InvalidArgumentException(
                    'Requested quantity for "' . $stockRow['item_name'] . '" exceeds available inventory. '
                    . 'Available: ' . $availableQuantity . '.'
                );
            }

            $inventoryUpdate->bind_param('ii', $quantity, $itemId);
            if (!$inventoryUpdate->execute()) {
                $error = $inventoryUpdate->error;
                $stockStatement->close();
                $inventoryUpdate->close();
                throw new RuntimeException('Unable to update inventory: ' . $error);
            }
        }
        $stockStatement->close();
        $inventoryUpdate->close();

        if ($newRecipient !== null) {
            $recipientStatement = $conn->prepare(
                'INSERT INTO `recipient` '
                . '(contact_name, organization_name, phone_number, email_address) '
                . 'VALUES (?, ?, ?, ?)'
            );
            if (!$recipientStatement) {
                throw new RuntimeException('Unable to prepare recipient insert: ' . $conn->error);
            }

            $contactName = trim((string) $newRecipient['contact_name']);
            $organizationName = trim((string) $newRecipient['organization_name']);
            $phoneNumber = trim((string) $newRecipient['phone_number']);
            $emailAddress = trim((string) $newRecipient['email_address']);
            $recipientStatement->bind_param(
                'ssss',
                $contactName,
                $organizationName,
                $phoneNumber,
                $emailAddress
            );
            if (!$recipientStatement->execute()) {
                throw new RuntimeException('Unable to create recipient: ' . $recipientStatement->error);
            }
            $recipientId = $conn->insert_id;
            $recipientStatement->close();
        }

        if ($recipientId === null || $recipientId <= 0) {
            throw new InvalidArgumentException('A valid recipient is required.');
        }

        $requestStatement = $conn->prepare(
            'INSERT INTO `request` (request_date, recipient_id, user_id) VALUES (?, ?, ?)'
        );
        if (!$requestStatement) {
            throw new RuntimeException('Unable to prepare request insert: ' . $conn->error);
        }
        $requestStatement->bind_param('sii', $normalizedDate, $recipientId, $userId);
        if (!$requestStatement->execute()) {
            throw new RuntimeException('Unable to create request: ' . $requestStatement->error);
        }
        $requestId = $conn->insert_id;
        $requestStatement->close();

        $itemStatement = $conn->prepare(
            'INSERT INTO `request_item` (quantity, request_id, item_id) VALUES (?, ?, ?)'
        );
        if (!$itemStatement) {
            throw new RuntimeException('Unable to prepare request item insert: ' . $conn->error);
        }

        foreach ($normalizedItems as [$itemId, $quantity]) {
            $itemStatement->bind_param('iii', $quantity, $requestId, $itemId);
            if (!$itemStatement->execute()) {
                throw new RuntimeException('Unable to create request item: ' . $itemStatement->error);
            }
        }
        $itemStatement->close();

        $conn->commit();
        return $requestId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
