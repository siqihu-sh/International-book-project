<?php
declare(strict_types=1);

require_once __DIR__ . '/include/mysqli_connect.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bindValues(mysqli_stmt $stmt, array $values): void
{
    if ($values === []) {
        return;
    }

    $types = str_repeat('s', count($values));
    $parameters = [$types];
    foreach ($values as $index => &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    $stmt->bind_param(...$parameters);
}

function runStatement(mysqli $conn, string $sql, array $values = []): void
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare SQL: ' . $conn->error);
    }
    bindValues($stmt, $values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $stmt->close();
}

$tables = [
    'request' => [
        'title' => 'Requests', 'primary' => 'request_id',
        'fields' => ['request_date' => 'datetime-local', 'recipient_id' => 'number', 'user_id' => 'number'],
    ],
    'shipment' => [
        'title' => 'Shipments', 'primary' => 'shipment_id',
        'fields' => [
            'shipment_date' => 'datetime-local', 'status' => 'text', 'user_id' => 'number',
            'return_id' => 'number', 'track_number' => 'text', 'request_id' => 'number',
            'shipment_cost' => 'number',
        ],
    ],
    'return' => [
        'title' => 'Returns', 'primary' => 'return_id',
        'fields' => ['return_date' => 'datetime-local', 'reason' => 'text', 'return_cost' => 'number'],
    ],
    'recipient' => [
        'title' => 'Recipients', 'primary' => 'recipient_id',
        'fields' => [
            'contact_name' => 'text', 'organization_name' => 'text',
            'phone_number' => 'text', 'email_address' => 'email',
        ],
    ],
    'address' => [
        'title' => 'Addresses', 'primary' => 'address_id',
        'fields' => [
            'street' => 'text', 'city' => 'text', 'state_province' => 'text',
            'post_code' => 'text', 'country' => 'text', 'recipient_id' => 'number',
        ],
    ],
    'item' => [
        'title' => 'Inventory', 'primary' => 'item_id',
        'fields' => ['item_name' => 'text', 'available_quantity' => 'number'],
    ],
    'system_user' => [
        'title' => 'System Users', 'primary' => 'user_id',
        'fields' => [
            'first_name' => 'text', 'last_name' => 'text', 'email_address' => 'email',
            'user_name' => 'text', 'password' => 'text',
        ],
    ],
    'role' => [
        'title' => 'Roles', 'primary' => 'role_id',
        'fields' => ['role_name' => 'text', 'role_description' => 'text'],
    ],
    'permission' => [
        'title' => 'Permissions', 'primary' => 'user_id',
        'fields' => ['user_id' => 'number', 'role_id' => 'number'],
    ],
];

$type = is_string($_POST['type'] ?? null)
    ? $_POST['type']
    : (is_string($_GET['type'] ?? null) ? $_GET['type'] : 'recipient');
if (!isset($tables[$type])) {
    $type = 'recipient';
}

$config = $tables[$type];
$tableName = '`' . $type . '`';
$primaryKey = $config['primary'];
$message = '';
$error = '';
$editRow = null;

$backModules = [
    'request' => 'requests', 'request_item' => 'requests', 'shipment' => 'shipments',
    'return' => 'returns', 'recipient' => 'recipients', 'address' => 'recipients', 'item' => 'inventory',
];
$backModule = $backModules[$type] ?? 'recipients';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        if ($action === 'create') {
            if ($type === 'request') {
                throw new InvalidArgumentException('Use Create Request to create a new request.');
            }
            if ($type === 'shipment') {
                throw new InvalidArgumentException('Use Create Shipment to create a new shipment.');
            }
            if ($type === 'return') {
                throw new InvalidArgumentException('Use Process Return to create a new return.');
            }
            $fields = array_keys($config['fields']);
            $values = [];
            foreach ($fields as $field) {
                $value = $_POST['field'][$field] ?? '';
                if (is_array($value)) {
                    throw new InvalidArgumentException('Invalid field value.');
                }
                $values[] = str_replace('T', ' ', trim((string) $value));
            }
            $fieldList = implode(', ', array_map(static fn (string $field): string => '`' . $field . '`', $fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            runStatement(
                $conn,
                'INSERT INTO ' . $tableName . ' (' . $fieldList . ') VALUES (' . $placeholders . ')',
                $values
            );
            $message = 'Record created successfully.';
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            if (is_array($id) || trim((string) $id) === '') {
                throw new InvalidArgumentException('A record ID is required.');
            }
            $values = [];
            $assignments = [];
            foreach (array_keys($config['fields']) as $field) {
                $value = $_POST['field'][$field] ?? '';
                if (is_array($value)) {
                    throw new InvalidArgumentException('Invalid field value.');
                }
                $assignments[] = '`' . $field . '` = ?';
                $values[] = str_replace('T', ' ', trim((string) $value));
            }
            $values[] = (string) $id;
            runStatement(
                $conn,
                'UPDATE ' . $tableName . ' SET ' . implode(', ', $assignments)
                    . ' WHERE `' . $primaryKey . '` = ?',
                $values
            );
            $message = 'Record updated successfully.';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            if (is_array($id) || trim((string) $id) === '') {
                throw new InvalidArgumentException('A record ID is required.');
            }

            if ($type === 'request') {
                $shipmentCheck = $conn->prepare('SELECT COUNT(*) FROM shipment WHERE request_id = ?');
                if (!$shipmentCheck) {
                    throw new RuntimeException('Unable to check request dependencies: ' . $conn->error);
                }
                $shipmentCheck->bind_param('s', $id);
                if (!$shipmentCheck->execute()) {
                    throw new RuntimeException($shipmentCheck->error);
                }
                $shipmentCount = (int) $shipmentCheck->get_result()->fetch_row()[0];
                $shipmentCheck->close();

                if ($shipmentCount > 0) {
                    throw new RuntimeException('This request cannot be deleted because it has a shipment.');
                }

                $conn->begin_transaction();
                try {
                    $itemLookup = $conn->prepare(
                        'SELECT item_id, quantity FROM request_item WHERE request_id = ?'
                    );
                    if (!$itemLookup) {
                        throw new RuntimeException('Unable to load request items: ' . $conn->error);
                    }
                    $itemLookup->bind_param('s', $id);
                    if (!$itemLookup->execute()) {
                        throw new RuntimeException($itemLookup->error);
                    }
                    $itemResult = $itemLookup->get_result();

                    $inventoryRestore = $conn->prepare(
                        'UPDATE item SET available_quantity = available_quantity + ? WHERE item_id = ?'
                    );
                    if (!$inventoryRestore) {
                        $itemLookup->close();
                        throw new RuntimeException('Unable to prepare inventory restore: ' . $conn->error);
                    }
                    while ($item = $itemResult->fetch_assoc()) {
                        $quantity = (int) $item['quantity'];
                        $itemId = (int) $item['item_id'];
                        $inventoryRestore->bind_param('ii', $quantity, $itemId);
                        if (!$inventoryRestore->execute()) {
                            throw new RuntimeException('Unable to restore inventory: ' . $inventoryRestore->error);
                        }
                    }
                    $inventoryRestore->close();
                    $itemLookup->close();

                    runStatement($conn, 'DELETE FROM request_item WHERE request_id = ?', [(string) $id]);
                    runStatement($conn, 'DELETE FROM request WHERE request_id = ?', [(string) $id]);
                    $conn->commit();
                } catch (Throwable $exception) {
                    $conn->rollback();
                    throw $exception;
                }
            } else {
                runStatement($conn, 'DELETE FROM ' . $tableName . ' WHERE `' . $primaryKey . '` = ?', [(string) $id]);
            }
            $message = 'Record deleted successfully.';
        }
    }

    if (($_GET['edit'] ?? '') === '1') {
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('A record ID is required.');
        }
        $stmt = $conn->prepare('SELECT * FROM ' . $tableName . ' WHERE `' . $primaryKey . '` = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare record lookup: ' . $conn->error);
        }
        $stmt->bind_param('s', $id);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $result = $stmt->get_result();
        $editRow = $result->fetch_assoc() ?: null;
        $stmt->close();
    }

    $result = $conn->query('SELECT * FROM ' . $tableName . ' ORDER BY `' . $primaryKey . '` DESC');
    if (!$result) {
        throw new RuntimeException('Unable to load records: ' . $conn->error);
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
    $rows = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= h($config['title']) ?> - Data Management</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <h1><?= h($config['title']) ?></h1>
    <p><a href="index.php?module=<?= h($backModule) ?>">Back to application</a></p>

    <?php if ($message !== ''): ?><p><?= h($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p>Error: <?= h($error) ?></p><?php endif; ?>

    <?php if ($type === 'request'): ?>
        <p><a href="request_create.php">Use Create Request to add a request.</a></p>
    <?php elseif ($type === 'shipment'): ?>
        <p><a href="shipment_create.php">Use Create Shipment to add a shipment.</a></p>
    <?php elseif ($type === 'return'): ?>
        <p><a href="return_create.php">Use Process Return to add a return.</a></p>
    <?php else: ?>
        <h2><?= $editRow === null ? 'Add record' : 'Edit record' ?></h2>
        <form method="post" action="">
            <input type="hidden" name="type" value="<?= h($type) ?>">
            <input type="hidden" name="action" value="<?= $editRow === null ? 'create' : 'update' ?>">
            <?php if ($editRow !== null): ?>
                <input type="hidden" name="id" value="<?= h($editRow[$primaryKey]) ?>">
            <?php endif; ?>
            <?php foreach ($config['fields'] as $field => $inputType): ?>
                <?php
                $value = $editRow[$field] ?? '';
                if ($inputType === 'datetime-local') {
                    $value = str_replace(' ', 'T', (string) $value);
                }
                ?>
                <p>
                    <label for="field_<?= h($field) ?>"><?= h(ucwords(str_replace('_', ' ', $field))) ?></label><br>
                    <input type="<?= h($inputType) ?>" id="field_<?= h($field) ?>"
                           name="field[<?= h($field) ?>]" value="<?= h($value) ?>" required>
                </p>
            <?php endforeach; ?>
            <button type="submit"><?= $editRow === null ? 'Add record' : 'Save changes' ?></button>
        </form>
    <?php endif; ?>

    <h2>Existing records</h2>
    <?php if ($rows === []): ?>
        <p>No records found.</p>
    <?php else: ?>
        <table border="1" cellpadding="4" cellspacing="0">
            <thead>
            <tr>
                <?php foreach (array_keys($rows[0]) as $field): ?>
                    <th><?= h(ucwords(str_replace('_', ' ', $field))) ?></th>
                <?php endforeach; ?>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $value): ?>
                        <td><?= $value === null || $value === '' ? '<em>None</em>' : h($value) ?></td>
                    <?php endforeach; ?>
                    <td>
                        <a href="?type=<?= h($type) ?>&edit=1&id=<?= rawurlencode((string) $row[$primaryKey]) ?>">Edit</a>
                        <form method="post" action="" style="display:inline">
                            <input type="hidden" name="type" value="<?= h($type) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= h($row[$primaryKey]) ?>">
                            <button type="submit" onclick="return confirm('Delete this record?');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
