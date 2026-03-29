<h1>Module Registry</h1>
<form method="get" class="card inline-form">
    <input name="q" placeholder="Search module key/name/nav" value="<?= htmlspecialchars((string) ($search ?? '')) ?>">
    <select name="status"><option value="all" <?= ($status ?? 'all')==='all'?'selected':'' ?>>All</option><option value="enabled" <?= ($status ?? '')==='enabled'?'selected':'' ?>>Enabled</option><option value="disabled" <?= ($status ?? '')==='disabled'?'selected':'' ?>>Disabled</option></select>
    <button class="btn">Filter</button>
</form>
<table><tr><th>Key</th><th>Name</th><th>Scope</th><th>Schemes</th><th>M/Q/Y</th><th>Flags</th><th>Dependencies</th><th>Nav</th><th>Edit</th></tr>
<?php foreach($modules as $m): ?>
<tr><td><?= htmlspecialchars((string) $m['module_key']) ?></td><td><?= htmlspecialchars((string) $m['module_name']) ?></td><td><?= htmlspecialchars((string) $m['module_scope']) ?></td><td><?= htmlspecialchars(implode(', ', (array) ($m['scheme_keys'] ?? []))) ?></td><td>₹<?= (float)$m['monthly_price'] ?>/₹<?= (float)$m['quarterly_price'] ?>/₹<?= (float)$m['yearly_price'] ?></td><td><?= !empty($m['enabled_flag'])?'enabled':'disabled' ?> / <?= !empty($m['is_core_included'])?'core':'addon' ?></td><td><?= htmlspecialchars(implode(', ', (array) ($m['dependency_list'] ?? []))) ?></td><td><?= htmlspecialchars((string) ($m['nav_label'] ?? '')) ?> #<?= (int) ($m['nav_order'] ?? 99) ?></td><td>
<form method="post" class="card compact-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken) ?>"><input type="hidden" name="module_key" value="<?= htmlspecialchars((string)$m['module_key']) ?>">
<input name="module_name" value="<?= htmlspecialchars((string)$m['module_name']) ?>"><textarea name="description"><?= htmlspecialchars((string)$m['description']) ?></textarea>
<input name="scheme_keys" value="<?= htmlspecialchars(implode(',', (array) ($m['scheme_keys'] ?? []))) ?>" placeholder="scheme keys csv">
<input name="monthly_price" type="number" step="0.01" value="<?= htmlspecialchars((string)$m['monthly_price']) ?>"><input name="quarterly_price" type="number" step="0.01" value="<?= htmlspecialchars((string)$m['quarterly_price']) ?>"><input name="yearly_price" type="number" step="0.01" value="<?= htmlspecialchars((string)$m['yearly_price']) ?>">
<input name="optional_setup_fee" type="number" step="0.01" value="<?= htmlspecialchars((string)($m['optional_setup_fee'] ?? 0)) ?>"><input name="dependency_list" value="<?= htmlspecialchars(implode(',', (array) ($m['dependency_list'] ?? []))) ?>">
<input name="nav_label" value="<?= htmlspecialchars((string)($m['nav_label'] ?? '')) ?>"><input name="nav_order" type="number" value="<?= (int)($m['nav_order'] ?? 99) ?>">
<label><input type="checkbox" name="enabled_flag" <?= !empty($m['enabled_flag'])?'checked':'' ?>> enabled</label><label><input type="checkbox" name="is_core_included" <?= !empty($m['is_core_included'])?'checked':'' ?>> core included</label>
<button class="btn">Save</button></form></td></tr>
<?php endforeach; ?></table>
