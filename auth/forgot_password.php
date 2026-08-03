<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . '/../config/db.php');
require_once(__DIR__ . '/../config/mail.php');
require_once(__DIR__ . '/../includes/PHPMailer/Exception.php');
require_once(__DIR__ . '/../includes/PHPMailer/PHPMailer.php');
require_once(__DIR__ . '/../includes/PHPMailer/SMTP.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$step = 1; // Step 1: Find Account | Step 2: Verify OTP | Step 3: Reset Password | Step 4: Success
$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    // STEP 1: FIND USER ACCOUNT & SEND OTP
    if ($action === 'verify_identity') {
        $input_identity = strtolower(trim($_POST['username'] ?? ''));
        $selected_role   = trim($_POST['role'] ?? '');

        if (!empty($input_identity) && !empty($selected_role)) {
            $stmt = $conn->prepare("SELECT user_id, email, username FROM users WHERE (LOWER(TRIM(username)) = ? OR LOWER(TRIM(email)) = ?) AND role = ?");
            $stmt->bind_param("sss", $input_identity, $input_identity, $selected_role);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if (!empty($user['email'])) {
                    // Generate 6-digit OTP
                    $otp = sprintf("%06d", mt_rand(100000, 999999));
                    
                    $_SESSION['reset_user_id'] = $user['user_id'];
                    $_SESSION['reset_email'] = $user['email'];
                    $_SESSION['reset_otp'] = $otp;
                    $_SESSION['reset_otp_expiry'] = time() + (15 * 60); // 15 mins expiry
                    $_SESSION['reset_otp_verified'] = false;
                    
                    // Send Email via PHPMailer
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = MAIL_HOST;
                        $mail->SMTPAuth   = true;
                        $mail->Username   = MAIL_USERNAME;
                        $mail->Password   = MAIL_PASSWORD;
                        $mail->SMTPSecure = MAIL_ENCRYPTION;
                        $mail->Port       = MAIL_PORT;
                        
                        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
                        $mail->addAddress($user['email']);
                        
                        $mail->isHTML(false);
                        $mail->Subject = 'SAAES - Password Reset OTP';
                        $mail->Body    = "Your OTP for password reset is: " . $otp . "\n\nThis OTP is valid for 15 minutes. If you did not request this, please ignore this email.";
                        
                        $mail->send();
                        $success = "An OTP has been sent to your registered email address.";
                        $step = 2;
                    } catch (Exception $e) {
                        $error = "Email could not be sent. Please check your SMTP settings or try again later.";
                        error_log("Mailer Error: {$mail->ErrorInfo}");
                    }
                    error_log("SAAES OTP for " . $user['email'] . ": " . $otp);
                } else {
                    $error = "No email address linked to this account. Contact System Admin.";
                }
            } else {
                $error = "No active $selected_role account found matching identity '$input_identity'.";
            }
            $stmt->close();
        } else {
            $error = "Please fill in all identity verification fields.";
        }
    }

    // STEP 2: VERIFY OTP
    if ($action === 'verify_otp') {
        $otp_input = trim($_POST['otp'] ?? '');
        
        if (isset($_SESSION['reset_otp']) && isset($_SESSION['reset_otp_expiry'])) {
            if (time() > $_SESSION['reset_otp_expiry']) {
                $error = "OTP has expired. Please restart the process.";
                unset($_SESSION['reset_otp'], $_SESSION['reset_otp_expiry']);
                $step = 1;
            } elseif ($otp_input === $_SESSION['reset_otp']) {
                $_SESSION['reset_otp_verified'] = true;
                $success = "OTP verified successfully. You can now reset your password.";
                $step = 3;
            } else {
                $error = "Invalid OTP entered. Please try again.";
                $step = 2;
            }
        } else {
            $error = "Session expired. Please restart the recovery process.";
            $step = 1;
        }
    }

    // STEP 3: RESET PASSWORD
    if ($action === 'reset_password') {
        $user_id          = $_SESSION['reset_user_id'] ?? 0;
        $new_password     = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');
        $is_verified      = $_SESSION['reset_otp_verified'] ?? false;

        if ($user_id > 0 && $is_verified) {
            if (!empty($new_password) && $new_password === $confirm_password) {
                if (strlen($new_password) >= 6 && preg_match('/[A-Z]/', $new_password) && preg_match('/[a-z]/', $new_password) && preg_match('/[0-9]/', $new_password) && preg_match('/[^a-zA-Z0-9]/', $new_password)) {
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

                    $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    $updateStmt->bind_param("si", $hashed_password, $user_id);

                    if ($updateStmt->execute()) {
                        unset($_SESSION['reset_user_id'], $_SESSION['reset_email'], $_SESSION['reset_otp'], $_SESSION['reset_otp_expiry'], $_SESSION['reset_otp_verified']);
                        $success = "Password successfully reset! Redirecting to login...";
                        $step = 4;
                        echo "<script>
                                setTimeout(function(){
                                    window.location.href = 'login.php';
                                }, 2000);
                              </script>";
                    } else {
                        $error = "System error updating password.";
                        $step = 3;
                    }
                    $updateStmt->close();
                } else {
                    $error = "Password must be at least 6 characters and contain an uppercase letter, a lowercase letter, a number, and a special character.";
                    $step = 3;
                }
            } else {
                $error = "New password confirmation mismatch.";
                $step = 3;
            }
        } else {
            $error = "Session expired or OTP not verified. Please restart the recovery process.";
            $step = 1;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Recovery | SAAES</title>
    
    <!-- Clean Academic Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            /* Traditional Academic Color Palette */
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --navy-primary: #0f172a;
            --blue-accent: #2563eb;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            
            --font-main: 'Inter', system-ui, -apple-system, sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--font-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
        }

        a { text-decoration: none; color: inherit; }

        /* ================= RECOVERY CARD ================= */
        .recovery-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            width: 100%;
            max-width: 480px;
            border-radius: var(--radius-lg);
            padding: 3rem 2.5rem;
            box-shadow: var(--shadow-md);
        }

        .card-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .sys-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            background: #eff6ff;
            color: var(--blue-accent);
            border-radius: 50%;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--navy-primary);
            margin-bottom: 0.5rem;
        }

        .card-subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        /* ================= FORM ELEMENTS ================= */
        .form-group { margin-bottom: 1.25rem; }
        
        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.4rem;
            display: block;
        }

        .form-control-custom, .form-select-custom {
            background-color: var(--bg-body);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 0.75rem 1rem;
            font-family: var(--font-main);
            font-size: 0.95rem;
            width: 100%;
            transition: all 0.2s ease;
            border-radius: var(--radius-md);
            -webkit-appearance: none;
        }
        
        .form-select-custom {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }
        
        .form-control-custom:focus, .form-select-custom:focus {
            border-color: var(--blue-accent);
            background-color: var(--bg-card);
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-control-custom::placeholder { color: #94a3b8; font-size: 0.9rem; }

        .question-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--blue-accent);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        /* ================= BUTTON ================= */
        .btn-primary {
            font-family: var(--font-main);
            font-weight: 600;
            font-size: 0.95rem;
            padding: 0.8rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: var(--blue-accent);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            width: 100%;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.1s ease;
            margin-top: 1.5rem;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-primary:active {
            transform: scale(0.98);
        }

        .login-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            transition: color 0.2s ease;
        }
        .login-link strong { 
            color: var(--blue-accent); 
            font-weight: 600; 
        }
        .login-link:hover strong { 
            text-decoration: underline; 
        }

        /* ================= ALERTS ================= */
        .alert { 
            font-size: 0.9rem; 
            font-weight: 500; 
            border-radius: var(--radius-md); 
            padding: 1rem 1.25rem; 
            margin-bottom: 1.5rem; 
            display: flex; 
            align-items: center; 
            gap: 0.75rem;
        }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

        @media (max-width: 600px) {
            .recovery-card { padding: 2rem 1.5rem; }
            .card-title { font-size: 1.25rem; }
        }
    </style>
</head>
<body>

    <div class="recovery-card">
        <div class="card-header">
            <div class="sys-icon"><i class="fa-solid fa-key"></i></div>
            <h3 class="card-title">Account Recovery</h3>
            <p class="card-subtitle">
                <?php 
                if ($step === 1) echo 'Verify your identity to receive an OTP on your email.';
                elseif ($step === 2) echo 'Enter the 6-digit OTP sent to your email address.';
                elseif ($step === 3) echo 'Create a new password for your account.';
                else echo 'Success!'; 
                ?>
            </p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <!-- STEP 1: IDENTITY LOOKUP -->
            <form method="POST" action="forgot_password.php">
                <input type="hidden" name="action" value="verify_identity">

                <div class="form-group">
                    <label class="form-label">Account Role <span style="color: #ef4444;">*</span></label>
                    <select name="role" class="form-select-custom" required>
                        <option value="" disabled selected>-- Select Role --</option>
                        <option value="Student">Student</option>
                        <option value="Parent">Parent / Guardian</option>
                        <option value="Faculty">Faculty</option>
                        <option value="HOD">HOD (Head of Department)</option>
                        <option value="GFM">GFM (Guardian Faculty Member)</option>
                        <option value="Admin">Administrator</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">PRN or Email Address <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="username" class="form-control-custom" placeholder="Enter PRN or Email" required autocomplete="off">
                </div>

                <button type="submit" class="btn-primary">
                    Verify Identity <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

        <?php elseif ($step === 2): ?>
            <!-- STEP 2: VERIFY OTP -->
            <form method="POST" action="forgot_password.php">
                <input type="hidden" name="action" value="verify_otp">

                <div class="question-box" style="text-align: center;">
                    <div style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.4rem;">OTP sent to</div>
                    <strong style="font-size: 1rem; color: var(--navy-primary);">
                        <?php 
                        $em = $_SESSION['reset_email'] ?? '';
                        echo htmlspecialchars(substr($em, 0, 3) . '***' . substr($em, strpos($em, '@'))); 
                        ?>
                    </strong>
                </div>

                <div class="form-group">
                    <label class="form-label">6-Digit OTP <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="otp" class="form-control-custom" placeholder="123456" required autocomplete="off" maxlength="6" style="text-align: center; font-size: 1.2rem; letter-spacing: 0.5rem; font-weight: 600;">
                </div>

                <button type="submit" class="btn-primary">
                    Verify OTP <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

        <?php elseif ($step === 3): ?>
            <!-- STEP 3: RESET PASSWORD -->
            <form method="POST" action="forgot_password.php">
                <input type="hidden" name="action" value="reset_password">

                <div class="form-group">
                    <label class="form-label">New Password <span style="color: #ef4444;">*</span></label>
                    <input type="password" name="new_password" class="form-control-custom" placeholder="Min 6 chars, uppercase, lowercase, number, symbol" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm Password <span style="color: #ef4444;">*</span></label>
                    <input type="password" name="confirm_password" class="form-control-custom" placeholder="Retype new password" required>
                </div>

                <button type="submit" class="btn-primary">
                    Reset Password <i class="fa-solid fa-check"></i>
                </button>
            </form>
        <?php endif; ?>

        <a href="login.php" class="login-link">
            Return to <strong>Login</strong>
        </a>
    </div>

</body>
</html>