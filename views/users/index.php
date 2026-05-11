<?php

declare(strict_types=1);

ob_start();

$searchQuery = (string)($searchQuery ?? '');
$users = is_array($users ?? null) ? $users : [];
$editUser = is_array($editUser ?? null) ? $editUser : null;
$distributors = is_array($distributors ?? null) ? $distributors : [];
$tableReady = (bool)($tableReady ?? false);
$message = trim((string)($message ?? ''));

$roles = [
    'internal_admin' => 'Internal Admin',
    'internal_read_only' => 'Internal Read-Only',
    'agent_viewer' => 'Agent Viewer',
];

$distributorNameById = [];
foreach ($distributors as $dist) {
    $id = (int)($dist['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $name = (string)($dist['name'] ?? ('#' . $id));
    $internalOnly = ((int)($dist['internal_only'] ?? 0)) === 1;
    $distributorNameById[$id] = $name . ($internalOnly ? ' (internal-only)' : '');
}
?>
<section class="card">
    <h2>User Administration</h2>
    <p class="muted">Manage internal and scoped agent user access.</p>

    <?php if ($message !== ''): ?>
        <p style="margin: 8px 0 12px; padding: 8px 10px; border: 1px solid #bfdbfe; border-radius: 8px; background: #eff6ff; color: #1d4ed8;">
            <?= htmlspecialchars($message) ?>
        </p>
    <?php endif; ?>

    <?php if (!$tableReady): ?>
        <p style="margin: 8px 0 12px; padding: 8px 10px; border: 1px solid #fecaca; border-radius: 8px; background: #fff1f2; color: #9f1239;">
            Users table is missing. Apply RBAC migration before managing user accounts.
        </p>
    <?php endif; ?>

    <form method="get" action="/users" class="row-actions" style="margin: 10px 0 14px;">
        <input name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search users by email, name, or role">
        <button type="submit" class="btn-muted">Search</button>
        <?php if ($searchQuery !== ''): ?>
            <a href="/users">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($tableReady): ?>
    <details style="margin: 14px 0;"<?= $editUser === null ? ' open' : '' ?>>
        <summary style="cursor: pointer; font-weight: 600;">Add User</summary>
        <form method="post" action="/users" class="grid" style="margin: 10px 0 0;">
            <?= $csrfField() ?>
            <div>
                <div class="muted" style="margin: 0 0 4px;">Email</div>
                <input type="email" name="email" required>
            </div>
            <div>
                <div class="muted" style="margin: 0 0 4px;">Display Name</div>
                <input name="display_name" placeholder="Optional">
            </div>
            <div>
                <div class="muted" style="margin: 0 0 4px;">Password</div>
                <input type="password" name="password" minlength="8" required>
            </div>
            <div>
                <div class="muted" style="margin: 0 0 4px;">Role</div>
                <select name="role" required>
                    <?php foreach ($roles as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="muted" style="margin: 0 0 4px;">Distributor Scope</div>
                <select name="distributor_id">
                    <option value="0">None (internal role)</option>
                    <?php foreach ($distributors as $dist): ?>
                        <?php $distId = (int)($dist['id'] ?? 0); ?>
                        <?php if ($distId <= 0): continue; endif; ?>
                        <option value="<?= $distId ?>">
                            <?= htmlspecialchars($distributorNameById[$distId] ?? ('#' . $distId)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="muted" style="margin: 0 0 4px;">Status</div>
                <select name="status">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit">Create User</button>
            </div>
        </form>
    </details>

    <?php if ($editUser !== null): ?>
        <details open style="margin: 10px 0 16px; padding: 12px; border: 1px solid #dbe2ea; border-radius: 10px; background: #f8fafc;">
            <summary style="cursor: pointer; font-weight: 600;">Edit User</summary>
            <form method="post" action="/users/update" class="grid" style="margin: 10px 0 0;">
                <?= $csrfField() ?>
                <input type="hidden" name="id" value="<?= (int)($editUser['id'] ?? 0) ?>">
                <div>
                    <div class="muted" style="margin: 0 0 4px;">Email</div>
                    <input type="email" name="email" value="<?= htmlspecialchars((string)($editUser['email'] ?? '')) ?>" required>
                </div>
                <div>
                    <div class="muted" style="margin: 0 0 4px;">Display Name</div>
                    <input name="display_name" value="<?= htmlspecialchars((string)($editUser['display_name'] ?? '')) ?>">
                </div>
                <div>
                    <div class="muted" style="margin: 0 0 4px;">Password (optional reset)</div>
                    <input type="password" name="password" minlength="8" placeholder="Leave blank to keep current password">
                </div>
                <div>
                    <div class="muted" style="margin: 0 0 4px;">Role</div>
                    <select name="role" required>
                        <?php foreach ($roles as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>"<?= ((string)($editUser['role'] ?? '') === $value) ? ' selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <div class="muted" style="margin: 0 0 4px;">Distributor Scope</div>
                    <?php $selectedDist = (int)($editUser['distributor_id'] ?? 0); ?>
                    <select name="distributor_id">
                        <option value="0">None (internal role)</option>
                        <?php foreach ($distributors as $dist): ?>
                            <?php $distId = (int)($dist['id'] ?? 0); ?>
                            <?php if ($distId <= 0): continue; endif; ?>
                            <option value="<?= $distId ?>"<?= $selectedDist === $distId ? ' selected' : '' ?>>
                                <?= htmlspecialchars($distributorNameById[$distId] ?? ('#' . $distId)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <div class="muted" style="margin: 0 0 4px;">Status</div>
                    <?php $selectedStatus = (int)($editUser['status'] ?? 1); ?>
                    <select name="status">
                        <option value="1"<?= $selectedStatus === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= $selectedStatus === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit">Save Changes</button>
                    <a href="/users" class="muted">Cancel</a>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Email</th>
                <th>Display Name</th>
                <th>Role</th>
                <th>Distributor Scope</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($users === []): ?>
                <tr><td colspan="7">No users found.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $row): ?>
                    <?php
                    $role = (string)($row['role'] ?? '');
                    $roleLabel = $roles[$role] ?? $role;
                    $distId = (int)($row['distributor_id'] ?? 0);
                    $status = (int)($row['status'] ?? 0);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($row['email'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['display_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)$roleLabel) ?></td>
                        <td><?= htmlspecialchars($distId > 0 ? ($distributorNameById[$distId] ?? ('#' . $distId)) : 'None') ?></td>
                        <td>
                            <?php if ($status === 1): ?>
                                <span class="badge badge-active">Active</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($row['last_login_at'] ?? '')) ?></td>
                        <td>
                            <a class="action-link-btn" href="/users?edit=<?= (int)($row['id'] ?? 0) ?><?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php
$content = (string)ob_get_clean();
require __DIR__ . '/../layouts/app.php';
