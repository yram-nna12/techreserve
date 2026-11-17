<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?> · TechReserve</title>
    <link rel="stylesheet" href="<?= base_url('css/auth.css') ?>">
</head>
<body>
<header class="site-header"><a class="brand" href="<?= site_url('login') ?>">Tech<span>Reserve</span></a><span>University resource portal</span><?= view('notifications/_navigation') ?></header>
<main class="shell">
    <aside class="intro"><p class="eyebrow">CAMPUS CONNECTIONS</p><h1>Your campus.<br>Your next possibility.</h1><p>One student account for your university's facilities and equipment reservation system.</p><div class="note">Verified enrollment. Secure access.<br>Built for your academic journey.</div></aside>
    <section class="card" aria-labelledby="page-title">
        <h2 id="page-title"><?= esc($title) ?></h2>
        <?php if ($error = session()->getFlashdata('error')): ?><div class="alert error" role="alert"><?= esc($error) ?></div><?php endif ?>
        <?php if ($success = session()->getFlashdata('success')): ?><div class="alert success" role="status"><?= esc($success) ?></div><?php endif ?>
        <?= $this->renderSection('content') ?>
    </section>
</main>
<footer>TechReserve · Student services</footer>
</body>
</html>
