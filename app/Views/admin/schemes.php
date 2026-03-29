<h1>Scheme Registry</h1>
<form method="get" class="card inline-form">
    <input type="text" name="q" value="<?= htmlspecialchars((string) ($search ?? '')) ?>" placeholder="Search scheme key/name/title">
    <button class="btn" type="submit">Search</button>
</form>
<table>
<tr><th>ID</th><th>Key</th><th>Name/Public</th><th>Description</th><th>Flags</th><th>Sort</th><th>Workflow</th><th>Modules</th><th>Updated</th><th>Edit</th></tr>
<?php foreach($filteredSchemes as $s): ?>
<tr>
<td><?= htmlspecialchars((string) ($s['scheme_id'] ?? '-')) ?></td>
<td><?= htmlspecialchars((string) ($s['scheme_key'] ?? '-')) ?></td>
<td><?= htmlspecialchars((string) ($s['scheme_name'] ?? '-')) ?><br><small><?= htmlspecialchars((string) ($s['public_title'] ?? '-')) ?></small></td>
<td><?= htmlspecialchars((string) ($s['description'] ?? '-')) ?></td>
<td><span class="<?= badgeClass(!empty($s['active_flag']) ? 'active' : 'cancelled') ?>">active:<?= !empty($s['active_flag'])?'yes':'no' ?></span> <span class="badge">public:<?= !empty($s['public_visible'])?'yes':'no' ?></span> <span class="badge">signup:<?= !empty($s['signup_enabled'])?'yes':'no' ?></span></td>
<td><?= (int) ($s['public_sort_order'] ?? 99) ?></td>
<td><?= htmlspecialchars((string) ($s['workflow_definition_ref'] ?? '-')) ?></td>
<td><?= htmlspecialchars(implode(', ', (array) ($s['module_registry_keys'] ?? []))) ?></td>
<td><?= htmlspecialchars((string) ($s['updated_at'] ?? '-')) ?></td>
<td>
<form method="post" class="card compact-form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken) ?>"><input type="hidden" name="scheme_key" value="<?= htmlspecialchars((string)$s['scheme_key']) ?>">
<label>Scheme Name<input name="scheme_name" value="<?= htmlspecialchars((string)$s['scheme_name']) ?>"></label>
<label>Public Title<input name="public_title" value="<?= htmlspecialchars((string)$s['public_title']) ?>"></label>
<label>Description<textarea name="description"><?= htmlspecialchars((string)$s['description']) ?></textarea></label>
<label>Sort Order<input type="number" name="public_sort_order" value="<?= (int) ($s['public_sort_order'] ?? 99) ?>"></label>
<label>Workflow Ref<input name="workflow_definition_ref" value="<?= htmlspecialchars((string) ($s['workflow_definition_ref'] ?? '')) ?>"></label>
<label><input type="checkbox" name="active_flag" <?= !empty($s['active_flag'])?'checked':'' ?>> Active</label>
<label><input type="checkbox" name="public_visible" <?= !empty($s['public_visible'])?'checked':'' ?>> Public visible</label>
<label><input type="checkbox" name="signup_enabled" <?= !empty($s['signup_enabled'])?'checked':'' ?>> Signup enabled</label>
<button class="btn">Save</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</table>
