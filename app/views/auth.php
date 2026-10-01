<?php $old = array_map(fn ($v) => is_string($v) ? $v : '', $old ?? []); ?>
<main class="auth-wrap">
    <div class="auth-card">
        <a class="logo" href="<?= e(url('/')) ?>"><span class="logo-mark">B</span><?= e(config('app_name')) ?></a>

        <?php if ($mode === 'register'): ?>
            <h1><?= !empty($first) ? 'Set up your account' : 'Create an account' ?></h1>
            <p><?= !empty($first) ? 'This first account becomes the admin.' : 'Your news, one swipe at a time.' ?></p>
        <?php else: ?>
            <h1>Welcome back</h1>
            <p>Your news, one swipe at a time.</p>
        <?php endif; ?>

        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

        <form method="post" action="<?= e(url($mode)) ?>">
            <?= csrf_field() ?>
            <?php if ($mode === 'register'): ?>
                <div class="row-2">
                    <div class="field"><label for="first_name">First name</label><input class="input" id="first_name" name="first_name" autocomplete="given-name" required value="<?= e($old['first_name'] ?? '') ?>"></div>
                    <div class="field"><label for="last_name">Last name</label><input class="input" id="last_name" name="last_name" autocomplete="family-name" required value="<?= e($old['last_name'] ?? '') ?>"></div>
                </div>
            <?php endif; ?>
            <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" autocomplete="email" required value="<?= e($old['email'] ?? '') ?>"></div>
            <div class="field">
                <label for="password">Password</label>
                <input class="input" id="password" name="password" type="password" required minlength="<?= $mode === 'register' ? 8 : 1 ?>" autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>">
                <?php if ($mode === 'register'): ?><p class="hint">At least 8 characters.</p><?php endif; ?>
            </div>
            <button class="btn btn-primary" type="submit"><?= $mode === 'register' ? 'Create account' : 'Log in' ?></button>
        </form>

        <?php if ($mode === 'login' && !empty($canRegister)): ?>
            <p class="auth-switch">No account yet? <a href="<?= e(url('register')) ?>">Create one</a></p>
        <?php elseif ($mode === 'register'): ?>
            <p class="auth-switch">Already have an account? <a href="<?= e(url('login')) ?>">Log in</a></p>
        <?php endif; ?>
    </div>
</main>
