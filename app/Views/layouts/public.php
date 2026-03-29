<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title) ?></title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header><div class="container"><strong>Yojak</strong><nav class="nav"><a href="/">Home</a><a href="/schemes">Schemes</a><a href="/pricing">Pricing</a><a href="/login">Vendor Login</a><a href="/admin/login">Admin</a></nav></div></header>
<main class="container"><?php require $contentView; ?></main>
<footer class="container"><small><?= htmlspecialchars((string) (($settings['public_footer_text'] ?? ''))) ?></small></footer>
</body></html>
