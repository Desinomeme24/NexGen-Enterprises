<?php
require_once __DIR__ . '/config.php';

$fpPortal = nxNormalizeLoginPortal(
    $_GET['portal']
        ?? $_POST['portal']
        ?? $_SESSION['fp_portal']
        ?? $_SESSION['login_portal']
        ?? 'client'
);
$_SESSION['fp_portal'] = $fpPortal;
$loginPath = nxLoginPathForPortal($fpPortal);

$popupMessage = "";
$popupType = "";

if (isset($_SESSION['success'])) {
    $popupMessage = $_SESSION['success'];
    $popupType = "success";
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $popupMessage = $_SESSION['error'];
    $popupType = "error";
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - NextGen</title>
    <link rel="stylesheet" href="<?php echo e(nxCodeUrl('STYLE/forgot_password.css')); ?>">
</head>
<body>

<?php if (!empty($popupMessage)): ?>
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box <?php echo $popupType; ?>" id="popupBox">
            <div class="popup-icon"><?php echo $popupType === 'success' ? '✓' : '!'; ?></div>
            <h3><?php echo $popupType === 'success' ? 'Success' : 'Error'; ?></h3>
            <p><?php echo htmlspecialchars($popupMessage); ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="forgot-page">
    <div class="forgot-card">
        <img src="<?php echo e(nxProjectUrl('IMAGES/NGlogo.png')); ?>" alt="Logo" class="forgot-logo">

        <h1>Forgot Password</h1>
        <p class="subtext">Enter your email address to look up your account.</p>

        <form action="<?php echo e(nxAppUrl('forgot_password_lookup.php')); ?>" method="POST">
            <input type="hidden" name="portal" value="<?php echo e($fpPortal); ?>">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="Enter your email address">

            <button type="submit" class="main-btn">Continue</button>
        </form>

        <div class="links">
            <a href="<?php echo e($loginPath); ?>">Back to Login</a>
        </div>
    </div>
</div>

<script src="<?php echo e(nxCodeUrl('JS/forgot_password.js')); ?>"></script>
</body>
</html>
