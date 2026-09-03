<?php
require_once __DIR__ . '/../private/includes/auth.php';
require_once __DIR__ . '/../private/includes/assets.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Login</title>
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/global.css') ?>">
</head>
<body>
    <div class="container login-container">
        <h1>Admin Access</h1>
        <?php if (isset($login_error)): ?>
            <p class="login-error"><?= $login_error ?></p>
        <?php endif; ?>
        
        <form method="POST">
            <?= csrf_field() ?>
            <input type="password" name="login_password" placeholder="Enter Password" required
                   class="login-input">
            <button type="submit" class="btn-primary">Login</button>
        </form>
        <p><a href="/">Return to Leaderboard</a></p>
    </div>
</body>
</html>